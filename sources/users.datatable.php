<?php
/**
 * @author        Nils Laumaillé <nils@teampass.net>
 *
 * @version       2.1.27
 *
 * @copyright     2009-2018 Nils Laumaillé
 * @license       GNU GPL-3.0
 *
 * @see          https://www.teampass.net
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 */
require_once 'SecureHandler.php';
session_start();
if (!isset($_SESSION['CPM']) || $_SESSION['CPM'] === false || !isset($_SESSION['key']) || empty($_SESSION['key'])) {
    die('Hacking attempt...');
}

// Load config
if (file_exists('../includes/config/tp.config.php')) {
    include_once '../includes/config/tp.config.php';
} elseif (file_exists('./includes/config/tp.config.php')) {
    include_once './includes/config/tp.config.php';
} else {
    throw new Exception("Error file '/includes/config/tp.config.php' not exists", 1);
}

// Do checks
require_once $SETTINGS['cpassman_dir'].'/includes/config/include.php';
require_once $SETTINGS['cpassman_dir'].'/sources/checks.php';
if (checkUser($_SESSION['user_id'], $_SESSION['key'], 'folders', $SETTINGS) === false) {
    // Not allowed page
    $_SESSION['error']['code'] = ERR_NOT_ALLOWED;
    include $SETTINGS['cpassman_dir'].'/error.php';
    exit();
}

require_once $SETTINGS['cpassman_dir'].'/includes/language/'.$_SESSION['user_language'].'.php';
require_once $SETTINGS['cpassman_dir'].'/includes/config/settings.php';
header('Content-type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
require_once 'main.functions.php';

// Connect to mysql server
require_once $SETTINGS['cpassman_dir'].'/includes/libraries/Database/Meekrodb/db.class.php';
$link = mysqli_connect(DB_HOST, DB_USER, defuseReturnDecrypted(DB_PASSWD, $SETTINGS), DB_NAME, DB_PORT);
$link->set_charset(DB_ENCODING);
DB::$user = DB_USER;
DB::$password = defuseReturnDecrypted(DB_PASSWD, $SETTINGS);
DB::$dbName = DB_NAME;
DB::$host = DB_HOST;
DB::$port = DB_PORT;
DB::$encoding = DB_ENCODING;

// Class loader
require_once $SETTINGS['cpassman_dir'].'/sources/SplClassLoader.php';

//Build tree
$tree = new SplClassLoader('Tree\NestedTree', $SETTINGS['cpassman_dir'].'/includes/libraries');
$tree->register();
$tree = new Tree\NestedTree\NestedTree(prefixTable('nested_tree'), 'id', 'parent_id', 'title');

$treeDesc = $tree->getDescendants();
// Build FUNCTIONS list
$rolesList = array();
$rows = DB::query('SELECT id,title FROM '.prefixTable('roles_title').' ORDER BY title ASC');
foreach ($rows as $record) {
    $rolesList[$record['id']] = array('id' => $record['id'], 'title' => $record['title']);
}

$listAvailableUsers = $listAdmins = $html = '';
$listAlloFcts_position = false;

//Columns name
$aColumns = array('id', 'login', 'name', 'lastname', 'admin', 'read_only', 'gestionnaire', 'isAdministratedByRole', 'can_manage_all_users', 'can_create_root_folder', 'personal_folder', 'email', 'ga', 'fonction_id');
$aSortTypes = array('asc', 'desc');

//init SQL variables
$sWhere = $sOrder = $sLimit = '';

/* BUILD QUERY */
//Paging
$sLimit = '';
if (isset($_GET['length']) === true && (int) $_GET['length'] !== -1) {
    $sLimit = ' LIMIT '.filter_var($_GET['start'], FILTER_SANITIZE_NUMBER_INT).', '.filter_var($_GET['length'], FILTER_SANITIZE_NUMBER_INT).'';
}

//Ordering
if (isset($_GET['order'][0]['dir']) && in_array($_GET['order'][0]['dir'], $aSortTypes)) {
    $sOrder = 'ORDER BY  ';
    if (preg_match('#^(asc|desc)$#i', $_GET['order'][0]['column'])
    ) {
        $sOrder .= ''.$aColumns[filter_var($_GET['order'][0]['column'], FILTER_SANITIZE_NUMBER_INT)].' '
        .mysqli_escape_string($link, $_GET['order'][0]['column']).', ';
    }

    $sOrder = substr_replace($sOrder, '', -2);
    if ($sOrder == 'ORDER BY') {
        $sOrder = '';
    }
}

/*
   * Filtering
   * NOTE this does not match the built-in DataTables filtering which does it
   * word by word on any field. It's possible to do here, but concerned about efficiency
   * on very large tables, and MySQL's regex functionality is very limited
*/
if (isset($_GET['letter']) === true
    && $_GET['letter'] !== ''
    && $_GET['letter'] !== 'None'
) {
    $sWhere = ' WHERE ';
    $sWhere .= $aColumns[1]." LIKE '".filter_var($_GET['letter'], FILTER_SANITIZE_STRING)."%' OR ";
    $sWhere .= $aColumns[2]." LIKE '".filter_var($_GET['letter'], FILTER_SANITIZE_STRING)."%' OR ";
    $sWhere .= $aColumns[3]." LIKE '".filter_var($_GET['letter'], FILTER_SANITIZE_STRING)."%' ";
} elseif (isset($_GET['search']['value']) === true && $_GET['search']['value'] !== '') {
    $sWhere = ' WHERE ';
    $sWhere .= $aColumns[1]." LIKE '".filter_var($_GET['search']['value'], FILTER_SANITIZE_STRING)."%' OR ";
    $sWhere .= $aColumns[2]." LIKE '".filter_var($_GET['search']['value'], FILTER_SANITIZE_STRING)."%' OR ";
    $sWhere .= $aColumns[3]." LIKE '".filter_var($_GET['search']['value'], FILTER_SANITIZE_STRING)."%' ";
}

// enlarge the query in case of Manager

if (isset($_SESSION['is_admin']) === false
    && isset($_SESSION['user_can_manage_all_users']) === false
) {
    if (empty($sWhere) === true) {
        $sWhere = ' WHERE ';
    } else {
        $sWhere .= ' AND ';
    }
    $arrUserRoles = array_filter($_SESSION['user_roles']);
    if (count($arrUserRoles) > 0) {
        $sWhere .= 'isAdministratedByRole IN ('.implode(',', $arrUserRoles).')';
    }
}

$rows = DB::query(
    'SELECT * FROM '.prefixTable('users').
    $sWhere
);
$iTotal = DB::count();

$rows = DB::query(
    'SELECT * FROM '.prefixTable('users').
    $sWhere.
    $sLimit
);
$iFilteredTotal = DB::count();

// Output
if ($iTotal == '') {
    $iTotal = 0;
}
$iTotal = 0;
$sOutput = '{';
$sOutput .= '"aaData": ';

if (DB::count() > 0) {
    $sOutput .= '[';
} else {
    $sOutput .= '';
}

foreach ($rows as $record) {
    //Show user only if can be administrated by the adapted Roles manager
    if ((int) $_SESSION['is_admin'] === 1 ||
        ($record['isAdministratedByRole'] > 0 &&
        in_array($record['isAdministratedByRole'], $_SESSION['user_roles'])) ||
        ((int) $_SESSION['user_can_manage_all_users'] === 1 && (int) $record['admin'] !== 1 && (int) $record['id'] !== (int) $_SESSION['user_id'])
    ) {
        $showUserFolders = true;
    } else {
        $showUserFolders = false;
    }

    // Display Grid
    if ($showUserFolders === true) {
        // Build list of available users
        if ((int) $record['admin'] !== 1 && (int) $record['disabled'] !== 1) {
            $listAvailableUsers .= '<option value="'.$record['id'].'">'.$record['login'].'</option>';
        }

        // Get list of allowed functions
        $listAlloFcts = '';
        if ((int) $record['admin'] !== 1) {
            if (count($rolesList) > 0) {
                foreach ($rolesList as $fonction) {
                    if (in_array($fonction['id'], explode(';', $record['fonction_id']))) {
                        $listAlloFcts .= '<i class="fa fa-angle-right"></i>&nbsp;'.addslashes(mysqli_real_escape_string($link, filter_var($fonction['title'], FILTER_SANITIZE_STRING))).'<br />';
                    }
                }
                $listAlloFcts_position = true;
            }
            if (empty($listAlloFcts)) {
                $listAlloFcts = '<i class="fa fa-exclamation mi-red tip" title="'.@htmlspecialchars($LANG['user_alarm_no_function'], ENT_QUOTES, 'UTF-8').'"></i>';
                $listAlloFcts_position = false;
            }
        }
        // Get list of allowed groups
        $listAlloGrps = '';
        if ((int) $record['admin'] !== 1) {
            if (count($treeDesc) > 0) {
                foreach ($treeDesc as $t) {
                    if (@!in_array($t->id, $_SESSION['groupes_interdits']) && in_array($t->id, $_SESSION['groupes_visibles'])) {
                        $ident = '';
                        if (in_array($t->id, explode(';', $record['groupes_visibles']))) {
                            $listAlloGrps .= '<i class="fa fa-angle-right"></i>&nbsp;'.addslashes(mysqli_real_escape_string($link, filter_var($ident.$t->title, FILTER_SANITIZE_STRING))).'<br />';
                        }
                        $prev_level = $t->nlevel;
                    }
                }
            }
        }
        // Get list of forbidden groups
        $listForbGrps = '';
        if ((int) $record['admin'] !== 1) {
            if (count($treeDesc) > 0) {
                foreach ($treeDesc as $t) {
                    $ident = '';
                    if (in_array($t->id, explode(';', $record['groupes_interdits']))) {
                        $listForbGrps .= '<i class="fa fa-angle-right"></i>&nbsp;'.addslashes(mysqli_real_escape_string($link, filter_var($ident.$t->title, FILTER_SANITIZE_STRING))).'<br />';
                    }
                    $prev_level = $t->nlevel;
                }
            }
        }
        $sOutput .= '["<span data-id=\"'.$record['id'].'\" data-fullname=\"'.addslashes(str_replace("'", '&lsquo;', $record['name'])).' '.addslashes(str_replace("'", '&lsquo;', $record['lastname'])).'\"></span>", ';

        //col2
        $sOutput .= '"'.
            (((int) $record['disabled'] === 1) ? '<i class=\"fas fa-user-slash infotip text-danger mr-2\" title=\"'.langHdl('account_is_locked').'\"></i>'
            : '').
            '<span data-id=\"'.$record['id'].'\" data-field=\"login\" data-html=\"true\">'.addslashes(str_replace("'", '&lsquo;', $record['login'])).'</span>" , ';

        //col3
        $sOutput .= '"<span data-id=\"'.$record['id'].'\" data-field=\"name\" data-html=\"true\">'.addslashes(str_replace("'", '&lsquo;', $record['name'])).'</span>", ';

        //col4
        $sOutput .= '"<span data-id=\"'.$record['id'].'\" data-field=\"lastname\" data-html=\"true\">'.addslashes(str_replace("'", '&lsquo;', $record['lastname'])).'</span>", ';

        //col5 - MANAGED BY
        $txt = '<span id=\"managedby-'.$record['id'].'\" data-id=\"'.$record['id'].'\" data-field=\"isAdministratedByRole\" data-html=\"true\">';
        $rows2 = DB::query(
            'SELECT title
            FROM '.prefixTable('roles_title')."
            WHERE id = '".$record['isAdministratedByRole']."'
            ORDER BY title ASC"
        );
        if (DB::count() > 0) {
            foreach ($rows2 as $record2) {
                $txt .= langHdl('managers_of').' '.addslashes(str_replace("'", '&lsquo;', $record2['title'])).'<br />';
            }
        } else {
            $txt .= langHdl('god');
        }
        $sOutput .= '"'.$txt.'</span>", ';

        //col6
        $sOutput .= '"<span data-id=\"'.$record['id'].'\" data-field=\"fonction_id\" data-html=\"true\">'.addslashes($listAlloFcts).'</span>", ';

        // Get the user maximum privilege
        if ((int) $record['admin'] === 1) {
            $sOutput .= '"<i class=\"fa fa-user-cog infotip\" title=\"'.langHdl('god').'\"></i>", ';
        } elseif ((int) $record['can_manage_all_users'] === 1) {
            $sOutput .= '"<i class=\"fa fa-user-graduate infotip\" title=\"'.langHdl('human_resources').'\"></i>", ';
        } elseif ((int) $record['gestionnaire'] === 1) {
            $sOutput .= '"<i class=\"fa fa-user-tie infotip\" title=\"'.langHdl('gestionnaire').'\"></i>", ';
        } elseif ((int) $record['read_only'] === 1) {
            $sOutput .= '"<i class=\"fa fa-book-reader infotip\" title=\"'.langHdl('read_only_account').'\"></i>", ';
        } else {
            $sOutput .= '"<i class=\"fa fa-user infotip\" title=\"'.langHdl('user').'\"></i>", ';
        }
        //col12
        if ((int) $record['can_create_root_folder'] === 1) {
            $sOutput .= '"<i class=\"fa fa-toggle-on text-info\"></i>", ';
        } else {
            $sOutput .= '"<i class=\"fa fa-toggle-off\"></i>", ';
        }

        //col13
        if ((int) $record['personal_folder'] === 1) {
            $sOutput .= '"<i class=\"fa fa-toggle-on text-info\"></i>"';
        } else {
            $sOutput .= '"<i class=\"fa fa-toggle-off\"></i>"';
        }

        //Finish the line
        $sOutput .= '],';

        //++$iFilteredTotal;
        ++$iTotal;
    }
}

if (count($rows) > 0) {
    if (strrchr($sOutput, '[') != '[') {
        $sOutput = substr_replace($sOutput, '', -1);
    }
    $sOutput .= ']';
} else {
    $sOutput .= '[]';
}

echo $sOutput.', '.
    '"sEcho": '.intval($_GET['draw']).', '.
    '"iTotalRecords": '.$iFilteredTotal.', '.
    '"iTotalDisplayRecords": '.$iTotal.'}';
