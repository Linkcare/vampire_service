<?php

function deploy_service($parameters) {
    $dbModel = ShipmentFunctions::getDataModel(Database::getInstance()->GetDatabase());
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

        ShipmentFunctions::addLocation($team->getId(), $team->getCode(), $team->getName(), $info['is_lab'], $info['is_clinical_site']);
        $logs[] = "Team $teamCode added to the locations table";
    }
    return $logs;
}
