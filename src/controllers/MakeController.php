<?php

/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2012 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

declare(strict_types=1);

namespace Elabftw\Controllers;

use Elabftw\AuditEvent\Export;
use Elabftw\Enums\EntityType;
use Elabftw\Enums\ExportFormat;
use Elabftw\Exceptions\IllegalActionException;
use Elabftw\Interfaces\MpdfProviderInterface;
use Elabftw\Interfaces\StringMakerInterface;
use Elabftw\Interfaces\ZipMakerInterface;
use Elabftw\Make\MakeCsv;
use Elabftw\Make\MakeEln;
use Elabftw\Make\MakeJson;
use Elabftw\Make\MakeMultiPdf;
use Elabftw\Make\MakePdf;
use Elabftw\Make\MakeProcurementRequestsCsv;
use Elabftw\Make\MakeQrPdf;
use Elabftw\Make\MakeQrPng;
use Elabftw\Make\MakeReport;
use Elabftw\Make\MakeSchedulerReport;
use Elabftw\Make\MakeStreamZip;
use Elabftw\Models\AuditLogs;
use Elabftw\Models\Items;
use Elabftw\Models\ProcurementRequests;
use Elabftw\Models\Scheduler;
use Elabftw\Models\Teams;
use Elabftw\Services\MpdfProvider;
use Elabftw\Services\MpdfQrProvider;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Logger;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ValueError;
use ZipStream\ZipStream;

use function array_map;
use function count;

/**
 * Create zip, csv, pdf or report
 */
class MakeController extends AbstractController
{
    private const int AUDIT_THRESHOLD = 12;

    private bool $pdfa = false;

    // array of EntitySlug
    private array $entitySlugs = array();

    public function getResponse(): Response
    {
        $this->populateSlugs();
        $format = ExportFormat::Json;
        try {
            $format = ExportFormat::from($this->Request->query->getAlpha('format'));
        } catch (ValueError) {
        }
        switch ($format) {
            case ExportFormat::Csv:
                if (str_starts_with($this->Request->getPathInfo(), '/api/v2/teams/current/procurement_requests')) {
                    $ProcurementRequests = new ProcurementRequests(new Teams($this->requester), 1);
                    return $this->getFileResponse(new MakeProcurementRequestsCsv($ProcurementRequests));
                }
                return $this->getFileResponse(new MakeCsv($this->requester, $this->entitySlugs));

            case ExportFormat::Eln:
                return $this->makeStreamZip(new MakeEln($this->getZipStreamLib(), $this->requester, $this->entitySlugs));

            case ExportFormat::Json:
                return $this->getFileResponse(new MakeJson($this->requester, $this->entitySlugs));

            case ExportFormat::PdfA:
                $this->pdfa = true;
                // no break
            case ExportFormat::Pdf:
                return $this->makePdf();

            case ExportFormat::QrPdf:
                return $this->getFileResponse(new MakeQrPdf($this->getMpdfProvider(), $this->requester, $this->entitySlugs));

            case ExportFormat::QrPng:
                return $this->getFileResponse(new MakeQrPng(new MpdfQrProvider(), $this->requester, $this->entitySlugs, $this->Request->query->getInt('size'), $this->Request->query->getBoolean('withTitle')));

            case ExportFormat::SysadminReport:
                if (!$this->requester->userData['is_sysadmin']) {
                    throw new IllegalActionException('Non sysadmin user tried to generate report.');
                }
                return $this->getFileResponse(new MakeReport(new Teams($this->requester)));

            case ExportFormat::SchedulerReport:
                return $this->makeSchedulerReport();

            case ExportFormat::ZipA:
                $this->pdfa = true;
                // no break
            case ExportFormat::Zip:
                return $this->makeZip();

            default:
                throw new IllegalActionException('Bad make format value');
        }
    }

    private function shouldIncludeChangelog(): bool
    {
        $includeChangelog =  $this->pdfa;
        if ($this->Request->query->has('changelog')) {
            $includeChangelog = $this->Request->query->getBoolean('changelog');
        }
        return $includeChangelog;
    }

    private function populateSlugs(): void
    {
        try {
            $entityType = EntityType::from($this->Request->query->getString('type'));
        } catch (ValueError) {
            return;
        }
        $idArr = array();
        // generate the id array
        if ($this->Request->query->has('category')) {
            $entity = $entityType->toInstance($this->requester);
            $idArr = $entity->getIdFromCategory($this->Request->query->getInt('category'));
        } elseif ($this->Request->query->has('owner')) {
            // only admin can export a user, or it is ourself
            if (!$this->requester->isAdminOf($this->Request->query->getInt('owner'))) {
                throw new IllegalActionException('User tried to export another user but is not admin.');
            }
            // being admin is good, but we also need to be in the same team as the requested user
            $Teams = new Teams($this->requester);
            $targetUserid = $this->Request->query->getInt('owner');
            if (!$Teams->hasCommonTeamWithCurrent($targetUserid, $this->requester->userData['team'])) {
                throw new IllegalActionException('User tried to export another user but is not in same team.');
            }
            $entity = $entityType->toInstance($this->requester);
            $idArr = $entity->getIdFromUser($targetUserid);
        } elseif ($this->Request->query->has('id')) {
            $idArr = array_map(
                fn(string $id): int => (int) $id,
                explode(' ', $this->Request->query->getString('id')),
            );
        }
        $slugs = array_map(function ($id) use ($entityType) {
            return sprintf('%s:%d', $entityType->value, $id);
        }, $idArr);
        $this->entitySlugs = array_map('\Elabftw\Elabftw\EntitySlug::fromString', $slugs);
        // generate audit log event if exporting more than $threshold entries
        $count = count($this->entitySlugs);
        if ($count > self::AUDIT_THRESHOLD) {
            AuditLogs::create(new Export($this->requester->userid ?? 0, count($this->entitySlugs)));
        }
    }

    private function getZipStreamLib(): ZipStream
    {
        return new ZipStream(sendHttpHeaders:false);
    }

    private function makePdf(): Response
    {
        $log = (new Logger('elabftw'))->pushHandler(new ErrorLogHandler());
        if (count($this->entitySlugs) === 1) {
            return $this->getFileResponse(new MakePdf($log, $this->getMpdfProvider(), $this->requester, $this->entitySlugs, $this->shouldIncludeChangelog()));
        }
        return $this->getFileResponse(new MakeMultiPdf($log, $this->getMpdfProvider(), $this->requester, $this->entitySlugs, $this->shouldIncludeChangelog()));
    }

    private function makeSchedulerReport(): Response
    {
        $defaultStart = '2018-12-23T00:00:00+01:00';
        $defaultEnd = '2119-12-23T00:00:00+01:00';
        return $this->getFileResponse(new MakeSchedulerReport(
            new Scheduler(
                new Items($this->requester),
                null,
                $this->Request->query->getString('start', $defaultStart),
                $this->Request->query->getString('end', $defaultEnd),
            ),
        ));
    }

    private function makeZip(): Response
    {
        return $this->makeStreamZip(new MakeStreamZip(
            $this->getZipStreamLib(),
            $this->requester,
            $this->entitySlugs,
            $this->pdfa,
            $this->shouldIncludeChangelog(),
            $this->Request->query->getBoolean('json'),
        ));
    }

    private function makeStreamZip(ZipMakerInterface $Maker): Response
    {
        $Response = new StreamedResponse();
        $Response->headers->set('X-Accel-Buffering', 'no');
        $Response->headers->set('Content-Type', $Maker->getContentType());
        $Response->headers->set('Cache-Control', 'no-store');
        $contentDisposition = $Response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $Maker->getFileName(), 'elabftw-export.zip');
        $Response->headers->set('Content-Disposition', $contentDisposition);
        $Response->setCallback(function () use ($Maker) {
            $Maker->getStreamZip();
        });
        return $Response;
    }

    private function getMpdfProvider(): MpdfProviderInterface
    {
        $userData = $this->requester->userData;
        return new MpdfProvider(
            $userData['fullname'],
            $userData['pdf_format'],
            $this->pdfa,
        );
    }

    private function getFileResponse(StringMakerInterface $Maker): Response
    {
        return new Response(
            $Maker->getFileContent(),
            200,
            array(
                'Content-Type' => $Maker->getContentType(),
                'Content-Size' => $Maker->getContentSize(),
                'Content-disposition' => 'inline; filename="' . $Maker->getFileName() . '"',
                'Cache-Control' => 'no-store',
                'Last-Modified' => gmdate('D, d M Y H:i:s') . ' GMT',
            )
        );
    }
}
