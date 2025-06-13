<?php

// error_reporting(E_ERROR); // Do not report warnings to avoid undesired characters in output stream

// if ($_SERVER['REQUEST_METHOD'] != 'GET') {
// return;
// }

// require_once ("src/default_conf.php");
// setSystemTimeZone();
// header('Content-type: text/html');

// $logger = ServiceLogger::init($GLOBALS['LOG_LEVEL'], $GLOBALS['LOG_DIR']);
// $logger->asHTML(true);
// $logger->toSTDOUT(true);

// $dbModel = DbDataModels::shipmentsModel('PREVAMPIRE_SERVICE');

// try {
// // Create DB schema
// Database::init($GLOBALS['SERVICE_DB_URI'], $logger);
// $error = Database::getInstance()->createSchema($dbModel, false);
// if ($error->getErrCode()) {
// throw new ServiceException($error->getErrCode(), "Error creating database schema: " . $error->getErrorMessage());
// }
// $logger->info("Database schema created successfully.");

// populateLocations();
// } catch (Exception $e) {
// $logger->error($e->getMessage());
// exit(0);
// }
// function populateLocations() {
// foreach ($GLOBALS['LAB_TEAMS'] as $teamCode => $info) {}
// }
function deploy_service($parameters) {
    $dbModel = DbDataModels::shipmentsModel('PREVAMPIRE_SERVICE');
    $logs = [];

    // Create DB schema
    $error = Database::getInstance()->createSchema($dbModel, false);
    if ($error->getErrCode()) {
        throw new ServiceException($error->getErrCode(), "Error creating database schema: " . $error->getErrorMessage());
    }
    $logs[] = "Database schema created successfully.";

    $logs = array_merge($logs, populateLocations());

    return new ServiceResponse($logs, null);
}

function populateLocations() {
    $api = LinkcareSoapAPI::getInstance();
    $logs = [];

    foreach ($GLOBALS['LAB_TEAMS'] as $teamCode => $info) {
        try {
            $team = $api->team_get($teamCode);
        } catch (Exception $e) {
            $logs[] = "Team $teamCode could not be added to the locations table: " . $e->getMessage();
            continue;
        }

        $keyColumns = ['ID_LOCATION' => ':id'];
        $updateColumns = ['NAME' => ':name', 'CODE' => ':code', 'IS_LAB' => ':is_lab', 'IS_CLINICAL_SITE' => ':is_clinical_site'];
        $arrVariables = [':id' => $team->getId(), ':name' => $team->getName(), ':code' => $team->getCode(), ':is_lab' => $info['is_lab'] ? 1 : 0,
                ':is_clinical_site' => $info['is_clinical_site'] ? 1 : 0];
        $sql = Database::getInstance()->buildInsertOrUpdateQuery('LOCATIONS', $keyColumns, $updateColumns);
        Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);
        $error = Database::getInstance()->getError();
        if ($error->getErrCode()) {
            throw new ServiceException(ErrorCodes::DB_ERROR, "Error adding location '" . $team->getName() . "': " . $error->getErrorMessage());
        }
        $logs[] = "Team $teamCode added to the locations table";
    }
    return $logs;
}
