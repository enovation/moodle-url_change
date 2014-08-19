<?php
/**
 * urlmove.php
 *
 * A commandline utility to generate SQL to replace all occurrences of a specified URL in a moodle database.
 *
 * @author    Paul Delany
 * @copyright 2013 Enovation Solutions Ltd.
 */

use GetOptionKit\GetOptionKit,
    GetOptionKit\ContinuousOptionParser,
    GetOptionKit\OptionCollection;

require 'vendor/autoload.php';

////////////////////////////// MAINLINE //////////////////////////////////////////
list($dbhost, $dbname, $dbuser, $dbpassword, $dbprefix,
    $version, $arguments, $subcommand, $excludeList, $includeList, $withrevert, $stripNewline) = parse_commandline($argv);

$dbtype = 'mysql'; // this is the only mysql-specific line to minimise dependence
list($conn, $pdo_conn) = open_db_connections($dbtype, $dbname, $dbuser, $dbpassword, $dbhost);

if ($arguments) {
    $encodedStrings = base64partial($arguments[0]);
    $encodedStrings[] = rawurlencode($arguments[0]);
    $encodedStrings[] = htmlentities($arguments[0]);
}

try {
    switch ($subcommand) {
        case 'search':
            $results = search($dbprefix->value,
                $excludeList,
                array(),
                array_merge(array($arguments[0]), $encodedStrings),
                $conn);

            foreach ($results as $match) {
                $data = $match['data'];
                if (isset($stripNewline) && $stripNewline) {
                    $data = trim(preg_replace('/\s+/', ' ', $data));
                }
                echo("TABLE: $match[table]\tCOLUMN: $match[column]\tID: $match[id]\tDATA: $data\n\n");
            }
            break;

        case 'replace':
        case 'revert':
            $filenumber = 0;
            $linecount = 0;

            if ($subcommand == 'replace') {
                $replacefile = "replace.$filenumber.sql";
                $revertfile = "revert.$filenumber.sql";
            } else {
                $replacefile = "revert.$filenumber.sql";
                $revertfile = '';
            }

            $fh = fopen($replacefile, 'w');
            fwrite($fh, "SET NAMES 'utf8';\n");
            fwrite($fh, "START TRANSACTION;\n");
            $fhrev = NULL;
            if ($withrevert && $revertfile) {
                $fhrev = fopen($revertfile, 'w');
                fwrite($fhrev, "SET NAMES 'utf8';\n");
                fwrite($fhrev, "START TRANSACTION;\n");
            }
            $results = search($dbprefix->value,
                array(),
                $includeList,
                array_merge(array($arguments[0]), $encodedStrings),
                $conn);

            foreach ($results as $match) {
                $sql = getSQLStatements($match['table'], $match['column'],
                    $match['id'], $match['data'],
                    $arguments[0], $arguments[1],
                    $pdo_conn);

                fwrite($fh, "$sql[$subcommand]\n");
                if ($fhrev) {
                    fwrite($fhrev, $sql['revert'] . "\n");
                }
                $linecount++;
                if ($linecount % 1000 == 0) {
                    fwrite($fh, "COMMIT;\n");
                    fclose($fh);
                    if ($fhrev) {
                        fwrite($fhrev, "COMMIT'\n");
                        fclose($fhrev);
                    }
                    $filenumber++;
                    if ($subcommand == 'replace') {
                        $replacefile = "replace.$filenumber.sql";
                        $revertfile = "revert.$filenumber.sql";
                    } else {
                        $replacefile = "revert.$filenumber.sql";
                        $revertfile = '';
                    }

                    $fh = fopen($replacefile, 'w');
                    fwrite($fh, "SET NAMES 'utf8';\n");
                    fwrite($fh, "START TRANSACTION;\n");
                    $fhrev = NULL;
                    if ($withrevert && $revertfile) {
                        $fhrev = fopen($revertfile, 'w');
                        fwrite($fhrev, "SET NAMES 'utf8';\n");
                        fwrite($fhrev, "START TRANSACTION;\n");
                    }
                }
            }
            fwrite($fh, "COMMIT;\n");
            fclose($fh);
            if ($fhrev) {
                fwrite($fhrev, "COMMIT;\n");
                fclose($fhrev);
            }
            break;
        case 'listurl':
            $urlpart = (isset($arguments[0])) ? $arguments[0] : array('');
            $results = urlslist($dbprefix->value,
                $excludeList,
                array(),
                $conn);
            break;
    }
} catch (Doctrine\DBAL\DBALException $e) {

    if (preg_match("/Unknown database type enum.*MySqlPlatform/", $e->getMessage())) {
        // MySQL enum fields will not be supported by DBAL
        // http://www.doctrine-project.org/jira/browse/DBAL-89

        $t = $e->getTrace();
        $table = $column = '';
        foreach ($t as $frame) {
            if ($frame["function"] == '_getPortableTableColumnList') {
                $table = $frame["args"][0];
            }
            if ($frame["function"] == '_getPortableTableColumnDefinition') {
                $column = $frame["args"][0]["Field"];
            }
        }

        echo("urlmove.php cannot continue to process as at least one " .
            "MySQL table column of type 'enum' is included " .
            "(See: $table.$column). \n");

    } else {
        echo("An unexpected DBAL exception has been thrown:" . $e->getMessage() . "\n");
    }

} catch (Exception $e) {
    echo "An unexpected exception has been thrown: " . $e->getMessage() . "\n";
}

exit(0);

////////////////////////////////// FUNCTIONS //////////////////////////////////////////

function show_help($specs)
{
    //TODO: Dopisać dokumentację!
    echo <<<EOD
Script for replacing and searching strings in database. It only generates SQL scripts file
replacing strings. Doesn't modify database. Database modification should be done by running
SQL script file.
Script use files: urlmove_exclude_XXX and urlmove_fields_XXX, where XXX it is version
set in parameter version|v or determining by values in database (Moodle only). Files contains
list of table names and fields- i.e.:

adodb_logsql.*
course.modinfo

urlmove_exclude- lists fields excluded from searching and modifying.
urlmove_fields- lists fields used to replace string (not for searching)

Search is done on all fields in database not listed in urlmove_exclude file.
\n
EOD;
    usage($specs);
    exit(0);
}

function usage($specs)
{
    echo <<<EOD
Usage:\n\nurlmove.php [OPTIONS] replace URL1 URL2
  Generate out.sql with SQL script which replaces string URL1 to URL2\n
urlmove.php [OPTIONS] revert URL1 URL2
  Generate revert.sql with SQL script which revert of replacement string URL1 to URL2.
  Backup command for 'replace' command.\n
urlmove.php [OPTIONS] search URL1
  Search string URL1 and show results.\n
urlmove.php [OPTIONS] listurl
  Search all urls (http and https) and show results.\n  
Options: \n
EOD;
    echo($specs->printOptions());
    echo("\n\n");
}

/**
 * Parses the commandline and returns array of parameters and initialised items.
 *
 * @param array $argv The standard array of commandline arguments.
 *
 * @return array        The array of init'd items.
 */
function parse_commandline($argv)
{
    $specs = new OptionCollection;
    $dbhost = $specs->add('h|dbhost?=s', "database hostname (default 'localhost')");
    $dbname = $specs->add('d|dbname:=s', 'database name');
    $dbuser = $specs->add('u|dbuser:=s', 'database user');
    $dbpassword = $specs->add('p|dbpassword:=s', 'database password');
    $dbprefix = $specs->add('t|dbprefix?=s', "table prefix (default 'mdl_')");
    $version = $specs->add('v|version?=s', "moodle version. If not sets, it could be tried to determine by 'config' table");
    $withrevert = $specs->add('r|with-revert', "'replace' command generates both files: revert.sql and out.sql");
    $stripnewline = $specs->add('s|strip-newlines', "strip new line characters from search results");
    $showhelp = $specs->add('help', "show help");

    $parser = new ContinuousOptionParser($specs);
    $subcommands = array('replace', 'revert', 'search', 'listurl');
    $subcommand = false;
    $arguments = array();

    try {
        $parser->parse($argv);
        if ($showhelp->value) {
            show_help($specs);
        }

        if (!$dbname->value || !$dbuser->value) { // !$dbhost->value ||  || !$version->value) {
            throw new Exception("Required options missing.");
        }

        if (!$dbhost->value) {
            $dbhost->setValue('localhost');
        }
        if (!$dbprefix->value) {
            $dbprefix->setValue('mdl_');
        }
        if (!$version->value) {
            $dbtype = 'mysql'; // this is the only mysql-specific line to minimise dependence
            list($conn, $pdo_conn) = open_db_connections('mysql', $dbname, $dbuser, $dbpassword, $dbhost);
            $detversion = determine_version($dbprefix->value, $conn);
            if (!$detversion) {
                throw new Exception("Unknown moodle version.\n");
            }
            $version->setValue("$detversion");
            echo "Moodle version has determined as: $version->value\n";
        }

        while (!$parser->isEnd()) {
            if (in_array($parser->getCurrentArgument(), $subcommands)) {
                if ($subcommand) {
                    throw new Exception('Unexpected: ' . $parser->getCurrentArgument());
                }
                $subcommand = $parser->getCurrentArgument();
                $parser->advance();
            } elseif (!$subcommand) {
                throw new Exception('Unexpected: ' . $parser->getCurrentArgument());
            } else {
                $arguments[] = $parser->advance();
            }
        }

        switch ($subcommand) {
            case 'replace':
            case 'revert':
                if (count($arguments) < 2) {
                    throw new Exception('Too few arguments.');
                }
                if (count($arguments) > 2) {
                    throw new Exception('Too many arguments.');
                }
                break;
            case 'search':
                if (count($arguments) < 1) {
                    throw new Exception('Too few arguments.');
                }
                if (count($arguments) > 1) {
                    throw new Exception('Too many arguments.');
                }
                break;
            case 'listurl':
                //if(count($arguments) < 1) { throw new Exception('Too few arguments.'); }
                if (count($arguments) > 1) {
                    throw new Exception('Too many arguments.');
                }
                break;
            default:
                throw new Exception("Unspecified command.");
                break;
        }

    } catch (Exception $e) {
        echo($e->getMessage() . "\n");
        usage($specs);
        exit(1);
    }

    $excludeFile = 'urlmove_exclude_' . $version->value;
    $a = file_exists($excludeFile);
    if (!file_exists($excludeFile)) {
        echo("Unknown Moodle version(" . $version->value . "): exclude file not available.");
        exit(1);
    } else {
        $excludeList = file($excludeFile, FILE_IGNORE_NEW_LINES);
    }

    $includeFile = 'urlmove_fields_' . $version->value;
    if (!file_exists($includeFile)) {
        echo("Unknown Moodle version(" . $version->value . "): fields configuration file not available.");
        exit(1);
    } else {
        $includeList = file($includeFile, FILE_IGNORE_NEW_LINES);
    }

    return array($dbhost, $dbname, $dbuser, $dbpassword, $dbprefix,
        $version, $arguments, $subcommand, $excludeList, $includeList, $withrevert->value, $stripnewline->value);
}

/**
 * Opens both a Doctrine\DBAL\Connection and a PDO connection to a database. Note that the
 * PDO connection is opened solely to permit the usage of PDO::quote() by the caller.
 *
 * @param string $dbtype The database type. Only 'mysql' currently tested.
 * @param OptionSpec $dbname OptionSpec of the database schema name.
 * @param OptionSpec $dbuser OptionSpec of the database user.
 * @param OptionSpec $dbpassword OptionSpec of the database password.
 * @param OptionSpec $dbhost OptionSpec of the database server hostname.
 */
function open_db_connections($dbtype, $dbname, $dbuser, $dbpassword, $dbhost)
{
    $dbdriver = "pdo_$dbtype";
    $config = new \Doctrine\DBAL\Configuration();
    $connectionParams = array('dbname' => $dbname->value,
        'user' => $dbuser->value,
        'password' => $dbpassword->value,
        'host' => $dbhost->value,
        'driver' => $dbdriver,
        'charset' => 'utf8'
    );

    $dsn = "$dbtype:dbname=" . $dbname->value . ";host=" . $dbhost->value;


    try {
        $conn = \Doctrine\DBAL\DriverManager::getConnection($connectionParams,
            $config);

        // MySQL enum fields will not be supported by DBAL. This should
        // allow the execution to continue.
        // http://www.doctrine-project.org/jira/browse/DBAL-89
        if ($dbtype == 'mysql') {
            $pf = $conn->getDatabasePlatform();
            $pf->registerDoctrineTypeMapping('enum', 'string');
        }

        // PDO connection purely to utilize PDO::quote() -
        // not ideal, but can't see other db-independent way to do this
        $pdo_conn = new PDO($dsn, $dbuser->value, $dbpassword->value);
    } catch (Exception $e) {
        echo ($e->getMessage()) . "\n";
        exit(1);
    }

    return array($conn, $pdo_conn);
}

/**
 * Applies $prefix to each string element of $arr, except if the element begins with '*'.
 *
 * @param array $arr The array to be modified by having each element (assumed strings) prefixed by $prefix.
 * @param string $prefix The prefix to apply to each element.
 */
function apply_prefix(&$arr, $prefix)
{
    for ($i = 0; $i < count($arr); $i++) {
        if (preg_match("/^\*/", $arr[$i])) {
            continue;
        }
        $arr[$i] = $prefix . $arr[$i];
    }
}

/**
 * Given two lists of database tables/fields, both potentially containing wildcards, which
 * represent the subset of the schema to be included / excluded respectively, returns the
 * definitive list of qualified fields defined by the inclusion / exclusion lists.
 *
 * @param Connection $conn The Doctrine\DBAL\Connection to the database, which must be open.
 * @param string $table_prefix If not empty, then only tables prefixed by this string will be
 *                               considered. If this is not empty, then the tablenames specified in
 *                               $excluded and $included should not already be prefixed.
 * @param array $excluded An array of strings of the form 'tablename.columnname' to
 *                               indicate columns to be skipped. Either tablename or columnname
 *                               may be '*' to indicate match all.
 * @param array $included An array of strings of the form 'tablename.columnname' to
 *                               indicate columns to search. If this is empty, all tables with
 *                               an 'id' field will be searched, excluding tables / fields
 *                               specfied by $excluded.
 */
function filter_fields($conn, $table_prefix, $excluded, $included)
{

    $sm = $conn->getSchemaManager();
    $tables = $sm->listTables();

    apply_prefix($excluded, $table_prefix);
    $excludedString = ',' . join(',', $excluded) . ',';

    apply_prefix($included, $table_prefix);
    $includedString = count($included) ?
        ',' . join(',', $included) . ',' :
        '';

    $columns = array();
    foreach ($tables as $table) {

        $t_name = $table->getName();

        if (!$table->hasColumn('id')) {
            continue;
        }
        if ($table_prefix && !preg_match("/^$table_prefix/", $t_name)) {
            continue;
        }
        if (preg_match("/,$t_name\.\*/", $excludedString)) {
            continue;
        }
        if ($includedString && !preg_match("/,$t_name\./", $includedString)) {
            continue;
        }

        foreach ($table->getColumns() as $column) {
            $c_name = $column->getName();

            if (preg_match("/,$t_name\.$c_name,/", $excludedString)
                || preg_match("/,\*\.$c_name,/", $excludedString)
            ) {
                continue;
            }
            if ($includedString && !preg_match(",$t_name\.$c_name,", $includedString)) {
                continue;
            }
            if ($column->getType() == "BigInt" || $column->getType() == "SmallInt"
                || $column->getType() == "Time" || $column->getType() == "Float"
                || $column->getType() == "DateTime" || $column->getType() == "Integer"
                || $column->getType() == "Boolean" || $column->getType() == "Decimal"
            ) {
                continue;
            }

            $columns[] = array($t_name, $c_name);
        }
    }

    return $columns;
}

/**
 * Performs a search of the database for any matches to the set of $strings.
 *
 * Note that only tables which have an 'id' field will be considered.
 *
 * @param string $table_prefix Only tables named with this prefix will be considered
 * @param array $excluded An array of strings of the form 'tablename.columnname' to
 *                             indicate columns to be skipped. Either tablename or columnname
 *                             may be '*' to indicate match all.
 * @param array $included An array of strings of the form 'tablename.columnname' to
 *                             indicate columns to search. If this is empty, all tables with
 *                             an 'id' field will be searched, excluding tables / fields
 *                             specfied by $excluded.
 * @param array $strings An array of strings, all of which will be searched for.
 * @param Connection $conn The Doctrine\DBAL\Connection to the database, which must be connected.
 *
 * @return array $arr          An array of (tablename, row_id, columnname, data) tuples for each match.
 */
function search($table_prefix, $excluded, $included, $strings, $conn)
{

    $columns = filter_fields($conn, $table_prefix, $excluded, $included);
    $results = array();
    foreach ($columns as $column) {

        list($t_name, $c_name) = $column;
        $urlMatchClause = '';
        $or = '';

        foreach ($strings as $string) {
            $urlMatchClause .= $or . "`$c_name`" . " LIKE ? ";
            $or = ' OR ';
        }

        $searchQuery = "SELECT `id`, `$c_name`
                    FROM $t_name
                    WHERE $urlMatchClause
                    ORDER BY id ASC";
        $stmt = $conn->prepare($searchQuery);

        $i = 0;
        foreach ($strings as $string) {
            $i++;
            $stmt->bindvalue($i, "%$string%", 'string');
        }
        $stmt->execute();

        $matches = array();
        while ($row = $stmt->fetch()) {
            foreach ($strings as $string) {

                if (!substr_count($row[$c_name], $string)) {
                    continue;
                }
                if (array_key_exists("$t_name-" . $row['id'] . "-$c_name", $matches)) {
                    continue;
                }

                $results[] = array('table' => $t_name,
                    'id' => $row['id'],
                    'column' => $c_name,
                    'data' => $row["$c_name"]);

                $matches["$t_name-" . $row['id'] . "-$c_name"] = true;
            }
        }
    }

    return $results;
} // end search()

/**
 * Generates the two SQL statments to a) update the specified record field with the new url and
 * b) revert the same to the original data.
 * Note that this function does not access the DB in any way, and merely takes parameters at 'face value'
 * to generate the statements. Also, if the base64 encoding of $oldUrl is found, it will be replaced by the
 * base64 encoding of $newUrl.
 *
 * @param string $tableName The name of the table containing the record.
 * @param string $colName The name of the column specifying the field of the record.
 * @param string $id The value of the primary key 'id' field of the record.
 * @param string $data The original data of the record field.
 * @param array $oldUrl The url to replace if it, or its base64 encoding, is found in the $data.
 * @param string $newUrl The new url to replace the $oldUrl with in the $data.
 * @param PDO $pdo_conn A PDO connection to the database, which must be open.
 *
 * @return array $arr          An array of ('replace' => $replaceStmt, 'revert' => $revertStmt) pairs.
 */
function getSQLStatements($tableName, $colName, $id, $data, $oldUrl, $newUrl, $pdo_conn)
{

    $urlregexp = '#\bhttps?://[^\s()<>;]+(?:\([\w\d]+\)|([^[:punct:]\s]|/))#';

    $oldUrls[] = $oldUrl;
    $oldUrls[] = rawurlencode($oldUrl);
    $oldUrls[] = htmlentities($oldUrl);

    $newUrls[] = $newUrl;
    $newUrls[] = rawurlencode($newUrl);
    $newUrls[] = htmlentities($newUrl);

    $oldUrls = array_unique($oldUrls);
    $newUrls = array_unique($newUrls);

    if (preg_match($urlregexp, $data)) {
        $newValue = str_replace($oldUrls, $newUrls, $data);
    }
     else {
         $decoded_data = base64_decode($data);
         $newValue = str_replace($oldUrls, $newUrls, $decoded_data);
         $newValue = base64_encode($newValue);
    }

    $newValue = $pdo_conn->quote($newValue);
    $updateStmt = "UPDATE `$tableName` SET `$colName` = $newValue WHERE id = $id;";

    $data = $pdo_conn->quote($data);
    $revertStmt = "UPDATE `$tableName` SET `$colName` = $data WHERE id = $id;";

    return array('replace' => $updateStmt, 'revert' => $revertStmt);
}

// This copied verbatim from original lib_compare.php
/**
 * A function which, given a string, will produce three variant base64 encodings of
 * same, and return them in an array.
 *
 * @param string $str The plaintext string.
 *
 * @return array $arr   An array of three string elements, which are the base64 encodings.
 */
function base64partial($str)
{
    $n = strlen($str);
    $ret = array();

    //how many bytes should be stripped from the beginning and the end?
    $alignment[0] = array();
    $alignment[0]['begin'] = 0;
    //shift % 3 => end bytes truncate
    $alignment[0]['end'] = array(
        0 => 0, -1 => 3, +1 => 2);

    $alignment[1] = array();
    $alignment[1]['begin'] = 2;
    $alignment[1]['end'] = array(
        0 => 3, -1 => 2, +1 => 0);

    $alignment[2] = array();
    $alignment[2]['begin'] = 3;
    $alignment[2]['end'] = array(
        0 => 2, -1 => 0, +1 => 3);

    for ($x = 0; $x < 3; $x++) {
        $encoded = base64_encode($str);
        //strip left
        $stripped = substr($encoded, $alignment[$x]['begin']);

        //strip right
        $rstrip = null;
        foreach ($alignment[$x]['end'] as $k => $v) {
            if (($n + $k) % 3 == 0) {
                $rstrip = $v;
                break;
            }
        }
        if ($rstrip === null)
            die('very bad. report to tm@enovation.ie');

        if ($rstrip > 0) {
            $stripped = substr($stripped, 0, -$rstrip);
        }
        $str = "\xFF" . $str;
        $ret[] = $stripped;
    }

    return $ret;
}

/**
 * Function determines moodle version.
 * @param string $table_prefix table prefix
 * @param object $conn database connection object
 * @return var  Moodle version as string, or False if failure.
 */
function determine_version($table_prefix, $conn)
{
    $versions = array('2007101509' => 1.9,
        '2010112400' => 2.0,
        '2011070100' => 2.1,
        '2011120500' => 2.2,
        '2012062500' => 2.3,
        '2012120300' => 2.4,
        '2013051400' => 2.5,
        '2013111800' => 2.6
    );
    $sql = "SELECT value FROM $table_prefix" . "config where name = 'version'";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $row = $stmt->fetch();
    $dbvers = $row['value'];
    if (asort($versions)) {
        $retversion = false;
        foreach ($versions as $time => $version) {
            if (intval($time) <= intval($dbvers)) {
                $retversion = $version;
            } else {
                return $retversion;
            }
        }
        return $version;
    }
    return false;
}

/**
 * Show tables names and urls found in tables fields
 * @param string $table_prefix table prefix
 * @param array $excluded array of excluded fields
 * @param array $included array of fields for searching
 * @param object $conn data base connection object
 * @return void
 */
function urlslist($table_prefix, $excluded, $included, $conn)
{
    $base64Strings1 = base64partial('http://');
    $base64Strings2 = base64partial('https://');
    $strings = array_merge(array('http://', 'https://'), $base64Strings1, $base64Strings2);

    $results = search($table_prefix, $excluded, $included, $strings, $conn);
    $urlregexp = '#\bhttps?://[^\s()<>;]+(?:\([\w\d]+\)|([^[:punct:]\s]|/))#';
    foreach ($results as $match) {
        $data = $match['data'];
        $urls = array();
        $is_url = false;

        if (preg_match_all($urlregexp, $data, $urlmatch)) {
            $urls = array_merge($urls, $urlmatch[0]);
            $is_url = true;
        } else {
            $decdata = base64_decode($data);
            if (preg_match_all($urlregexp, $decdata, $urlmatch)) {
                $urls = array_merge($urls, $urlmatch[0]);
                $is_url = true;
            } else {
                //
            }
        }

        if ($is_url) {
            $urls = implode("\n", array_unique($urls));
            echo("TABLE: $match[table]\tCOLUMN: $match[column]\tID: $match[id]\t\n$urls\n\n");
        }
    }


    $columns = filter_fields($conn, $table_prefix, $excluded, $included);
    $results = array();

} // end urlslist()

?>