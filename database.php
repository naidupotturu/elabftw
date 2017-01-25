<?php
/**
 * database.php
 *
 * @author Nicolas CARPi <nicolas.carpi@curie.fr>
 * @copyright 2012 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */

namespace Elabftw\Elabftw;

use \Exception;

/**
 * Entry point for database things
 *
 */
require_once 'app/init.inc.php';
$page_title = _('Database');
$selected_menu = 'Database';
require_once 'app/head.inc.php';

// add the chemdoodle stuff if we want it
echo addChemdoodle();

try {
    $EntityView = new DatabaseView(new Database(new Users($_SESSION['userid'])));

    if (!isset($_GET['mode']) || empty($_GET['mode']) || $_GET['mode'] === 'show') {
        $EntityView->display = $_SESSION['prefs']['display'];

        // CATEGORY FILTER
        if (isset($_GET['filter']) && !empty($_GET['filter']) && Tools::checkId($_GET['filter'])) {
            $EntityView->Entity->categoryFilter = "AND items_types.id = " . $_GET['filter'];
            $EntityView->searchType = 'filter';
        }
        // TAG FILTER
        if (isset($_GET['tag']) && $_GET['tag'] != '') {
            $tag = filter_var($_GET['tag'], FILTER_SANITIZE_STRING);
            $EntityView->tag = $tag;
            $EntityView->Entity->tagFilter = "AND items_tags.tag LIKE '" . $tag . "'";
            $EntityView->searchType = 'tag';
        }
        // QUERY FILTER
        if (isset($_GET['q']) && !empty($_GET['q'])) {
            $query = filter_var($_GET['q'], FILTER_SANITIZE_STRING);
            $EntityView->query = $query;
            $EntityView->Entity->queryFilter = "AND (title LIKE '%$query%' OR date LIKE '%$query%' OR body LIKE '%$query%')";
            $EntityView->searchType = 'query';
        }
        // ORDER
        if (isset($_GET['order'])) {
            if ($_GET['order'] === 'cat') {
                $EntityView->Entity->order = 'items_types.name';
            } elseif ($_GET['order'] === 'date' || $_GET['order'] === 'rating' || $_GET['order'] === 'title') {
                $EntityView->Entity->order = 'items.' . $_GET['order'];
            }
        }
        // SORT
        if (isset($_GET['sort'])) {
            if ($_GET['sort'] === 'asc' || $_GET['sort'] === 'desc') {
                $EntityView->Entity->sort = $_GET['sort'];
            }
        }

        echo $EntityView->buildShowMenu('database');

        // limit the number of items to show if there is no search parameters
        // because with a big database this can be expensive
        if (!isset($_GET['q']) && !isset($_GET['tag']) && !isset($_GET['filter'])) {
            $EntityView->Entity->setLimit(50);
        }
        echo $EntityView->buildShow();

    // VIEW
    } elseif ($_GET['mode'] === 'view') {

        $EntityView->Entity->setId($_GET['id']);
        echo $EntityView->view();

    // EDIT
    } elseif ($_GET['mode'] === 'edit') {

        $EntityView->Entity->setId($_GET['id']);
        echo $EntityView->edit();
    }
} catch (Exception $e) {
    echo Tools::displayMessage($e->getMessage(), 'ko');
} finally {
    require_once 'app/footer.inc.php';
}
