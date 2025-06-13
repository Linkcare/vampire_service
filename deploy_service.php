<?php
error_reporting(E_ERROR); // Do not report warnings to avoid undesired characters in output stream

if ($_SERVER['REQUEST_METHOD'] != 'GET') {
    return;
}

require_once ("src/default_conf.php");
setSystemTimeZone();
header('Content-type: text/html');

$logger = ServiceLogger::init($GLOBALS['LOG_LEVEL'], $GLOBALS['LOG_DIR']);
$logger->asHTML(true);
$logger->toSTDOUT(true);

$dbModel = DbDataModels::shipmentsModel('PREVAMPIRE_SERVICE');

try {
    Database::init($GLOBALS['SERVICE_DB_URI'], $logger);
    $error = Database::getInstance()->createSchema($dbModel, false);
    if (!$error->getErrCode()) {
        echo "Database schema created successfully.<br>";
    } else {
        $logger->error("Error creating database schema: " . $error->getErrorMessage());
    }
} catch (Exception $e) {
    $logger->error($e->getMessage());
    exit(0);
}
