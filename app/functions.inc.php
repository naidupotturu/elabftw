<?php
/**
 * inc/functions.php
 *
 * @author Nicolas CARPi <nicolas.carpi@curie.fr>
 * @copyright 2012 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 */
namespace Elabftw\Elabftw;

use \Exception;
use Defuse\Crypto\Crypto as Crypto;
use Defuse\Crypto\Key as Key;

/**
 * This file holds global functions available everywhere.
 *
 * @deprecated
 */

/**
 * Used in sysconfig.php to update config values
 *
 * @param array $post (conf_name => conf_value)
 * @return bool the return value of execute queries
 */
function update_config($post)
{
    global $pdo;
    $result = array();
    $Teams = new Teams($_SESSION['team_id']);

    // do some data validation for some values
    if (isset($post['stampcert'])) {
        $cert_chain = filter_var($post['stampcert'], FILTER_SANITIZE_STRING);
        if (!is_readable(realpath(ELAB_ROOT . $cert_chain))) {
            throw new Exception('Cannot read provided certificate file.');
        }
    }

    if (isset($post['stamppass']) && !empty($post['stamppass'])) {
        $post['stamppass'] = Crypto::encrypt($post['stamppass'], Key::loadFromAsciiSafeString(SECRET_KEY));
    } elseif (isset($post['stamppass'])) {
        $post['stamppass'] = $Teams->read('stamppass');
    }

    if (isset($post['login_tries']) && Tools::checkId($post['login_tries']) === false) {
        throw new Exception('Bad value for number of login attempts!');
    }
    if (isset($post['ban_time']) && Tools::checkId($post['ban_time']) === false) {
        throw new Exception('Bad value for number of login attempts!');
    }

    // encrypt password
    if (isset($post['smtp_password']) && !empty($post['smtp_password'])) {
        $post['smtp_password'] = Crypto::encrypt($post['smtp_password'], Key::loadFromAsciiSafeString(SECRET_KEY));
    // we might receive a set but empty smtp_password, so ignore it
    } elseif (empty($post['smtp_password'])) {
        unset($post['smtp_password']);
    }


    // loop the array and update config
    foreach ($post as $name => $value) {
        $sql = "UPDATE config SET conf_value = :value WHERE conf_name = :name";
        $req = $pdo->prepare($sql);
        $req->bindParam(':value', $value);
        $req->bindParam(':name', $name);
        $result[] = $req->execute();
    }

    return !in_array(0, $result);
}

/*
 * Functions to keep current order/filter selection in dropdown
 *
 * @param string value to check
 * @return string|null echo 'selected'
 */

function checkSelectOrder($val)
{
    if (isset($_GET['order']) && $_GET['order'] === $val) {
        return " selected";
    }
}

function checkSelectSort($val)
{
    if (isset($_GET['sort']) && $_GET['sort'] === $val) {
        return " selected";
    }
}

function checkSelectFilter($val)
{
    if (isset($_GET['filter']) && $_GET['filter'] === $val) {
        return " selected";
    }
}

/*
 * Import the SQL structure
 *
 */
function import_sql_structure()
{
    global $pdo;

    $sqlfile = 'elabftw.sql';

    // temporary variable, used to store current query
    $queryline = '';
    // read in entire file
    $lines = file($sqlfile);
    // loop through each line
    foreach ($lines as $line) {
        // Skip it if it's a comment
        if (substr($line, 0, 2) == '--' || $line == '') {
                continue;
        }

        // Add this line to the current segment
        $queryline .= $line;
        // If it has a semicolon at the end, it's the end of the query
        if (substr(trim($line), -1, 1) == ';') {
            // Perform the query
            $pdo->query($queryline);
            // Reset temp variable to empty
            $queryline = '';
        }
    }
}

/**
 * Get the time difference between start of page and now.
 *
 * @return array with time and unit
 */
function get_total_time()
{
    $time = microtime();
    $time = explode(' ', $time);
    $time = $time[1] + $time[0];
    $total_time = round(($time - $_SERVER["REQUEST_TIME_FLOAT"]), 4);
    $unit = _('seconds');
    if ($total_time < 0.01) {
        $total_time = $total_time * 1000;
        $unit = _('milliseconds');
    }
    return array(
        'time' => $total_time,
        'unit' => $unit);
}

/**
 * Inject the script/css for chemdoodle
 *
 * @return string
 */
function addChemdoodle()
{
    $html = '';

    if (isset($_SESSION['prefs']['chem_editor']) && $_SESSION['prefs']['chem_editor']) {
        $html .= "<link rel='stylesheet' href='app/css/chemdoodle.css' type='text/css'>";
        $html .= "<script src='app/js/chemdoodle/chemdoodle.min.js'></script>";
        $html .= "<script>ChemDoodle.iChemLabs.useHTTPS();</script>";
    }

    return $html;
}

/**
 * Generate a JS list of DB items to use for links or # autocomplete
 *
 * @param $format string ask if you want the default list for links, or the one for the mentions
 * @since 1.1.7 it adds the XP of user
 * @return string
 */
function getDbList($format = 'default')
{
    $link_list = "";
    $tinymce_list = "";

    $Users = new Users($_SESSION['userid']);
    $Database = new Database($Users);
    $itemsArr = $Database->read();

    foreach ($itemsArr as $item) {

        // html_entity_decode is needed to convert the quotes
        // str_replace to remove ' because it messes everything up
        $link_name = str_replace(array("'", "\""), "", html_entity_decode(substr($item['title'], 0, 60), ENT_QUOTES));
        // remove also the % (see issue #62)
        $link_name = str_replace("%", "", $link_name);

        // now build the list in both formats
        $link_list .= "'" . $item['itemid'] . " - " . $item['name'] . " - " . $link_name . "',";
        $tinymce_list .= "{ name : \"<a href='database.php?mode=view&id=" . $item['itemid'] . "'>" . $link_name . "</a>\"},";
    }

    if ($format === 'default') {
        return $link_list;
    }

    // complete the list with experiments (only for tinymce)
    // fix #191
    $Experiments = new Experiments($Users);
    $expArr = $Experiments->read();

    foreach ($expArr as $exp) {

        $link_name = str_replace(array("'", "\""), "", html_entity_decode(substr($exp['title'], 0, 60), ENT_QUOTES));
        // remove also the % (see issue #62)
        $link_name = str_replace("%", "", $link_name);
        $tinymce_list .= "{ name : \"<a href='experiments.php?mode=view&id=" . $exp['id'] . "'>" . $link_name . "</a>\"},";
    }

    return $tinymce_list;
}
