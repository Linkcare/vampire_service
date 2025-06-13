<?php

function shipment_locations($parameters) {
    $sql = "SELECT * FROM LOCATIONS WHERE IS_LAB=1";
    $rst = Database::getInstance()->executeBindQuery($sql);
    $error = Database::getInstance()->getError();
    if ($error->getErrCode()) {
        throw new ServiceException($error->getErrCode(), $error->getErrorMessage());
    }

    $locations = [];
    while ($rst->Next()) {
        $location = new stdClass();
        $location->id = $rst->GetField('ID_LOCATION');
        $location->code = $rst->GetField('CODE');
        $location->name = $rst->GetField('NAME');
        $locations[] = $location;
    }

    return new ServiceResponse($locations, null);
}

/**
 * Loads the list of all shipments that can be viewed by the user.
 *
 * @param stdClass $parameters
 * @return ServiceResponse
 */
function shipment_list($parameters) {
    $api = LinkcareSoapAPI::getInstance();

    $page = loadParam($parameters, 'page');
    $pageSize = loadParam($parameters, 'pageSize');

    $filters = loadParam($parameters, 'filters');
    $arrVariables[':currentTeamId'] = $api->getSession()->getTeamId();

    $queryColumns = "s.*, l1.NAME as SENT_FROM, l2.NAME as SENT_TO";
    $queryFromClause = "FROM SHIPMENTS s
                LEFT JOIN LOCATIONS l1 ON s.ID_SENT_FROM = l1.ID_LOCATION
                LEFT JOIN LOCATIONS l2 ON s.ID_SENT_TO = l2.ID_LOCATION
            WHERE s.ID_SENT_FROM=:currentTeamId OR s.ID_SENT_TO=:currentTeamId";
    list($rst, $totalRows) = fetchWithPagination($queryColumns, $queryFromClause, $arrVariables, $pageSize, $page);

    $shipmentList = [];
    while ($rst->Next()) {
        $shipmentList[] = Shipment::fromDBRecord($rst);
    }

    $data = new stdClass();
    $timezone = $api->getSession()->getTimezone();
    $data->rows = array_map(function ($shipment) use ($timezone) {
        /** @var Shipment $shipment */
        return $shipment->toJSON($timezone);
    }, $shipmentList);
    $data->total_count = $totalRows;

    return new ServiceResponse($data, null);
}

/**
 * Creates a new shipment
 *
 * @param stdClass $parameters
 * @return ServiceResponse
 */
function shipment_create($parameters) {
    $shipmentRef = loadParam($parameters, 'ref');
    $sentFromId = loadParam($parameters, 'sentFromId');
    $sentToId = loadParam($parameters, 'sentToId');
    $senderId = loadParam($parameters, 'senderId');
    $senderName = loadParam($parameters, 'senderName');
    if (!$sentFromId) {
        return new ServiceResponse(null, "It is mandatory to provide the location from which the shipment is sent");
    }
    if ($sentFromId == $sentToId) {
        return new ServiceResponse(null, "Shipment cannot be sent to the same location");
    }

    $arrVariables[':shipmentRef'] = $shipmentRef;
    $arrVariables[':status'] = ShipmentStatus::PREPARING;
    $arrVariables[':sentFromId'] = $sentFromId;
    $arrVariables[':sentToId'] = $sentToId;
    $arrVariables[':senderId'] = $senderId;
    $arrVariables[':senderName'] = $senderName;

    $sql = "INSERT INTO SHIPMENTS (SHIPMENT_REF, ID_STATUS, ID_SENT_FROM, ID_SENT_TO, ID_SENDER, SENDER) VALUES (:shipmentRef, :status, :sentFromId, :sentToId, :senderId, :senderName)";
    Database::getInstance()->executeBindQuery($sql, $arrVariables);
    $error = Database::getInstance()->getError();
    if ($error->getErrCode()) {
        throw new ServiceException($error->getErrCode(), "Error creating shipment: " . $error->getErrorMessage());
    }

    $data = new stdClass();
    Database::getInstance()->getLastInsertedId($data->id);
    return new ServiceResponse($data, null);
}

/**
 * Updates the information of an existing shipment (that has not been shipped yet)
 * Only the properties relevant for the shipment are updated
 *
 * @param array $parameters
 * @return ServiceResponse
 */
function shipment_update($parameters, $status = null) {
    $id = loadParam($parameters, 'id');
    $shipment = Shipment::exists($id);
    if (!$shipment) {
        throw new ServiceException(ErrorCodes::NOT_FOUND, "Shipment with ID " . $id . " not found");
    }
    $shipment->ref = loadParam($parameters, 'ref');
    $shipment->statusId = loadParam($parameters, 'status');
    $shipment->sentFromId = loadParam($parameters, 'sentFromId');
    $shipment->sentToId = loadParam($parameters, 'sentToId');
    $shipment->senderId = loadParam($parameters, 'senderId');

    updateShipment($shipment);

    return new ServiceResponse($shipment->d, null);
}

/**
 *
 * @param array $parameters
 */
function shipment_send($parameters) {
    $api = LinkcareSoapAPI::getInstance();

    $shipmentId = loadParam($parameters, 'id');

    $sql = "SELECT * FROM SHIPMENTS s WHERE s.ID_SHIPMENT = :id";
    $rst = Database::getInstance()->ExecuteBindQuery($sql, $shipmentId);
    $error = Database::getInstance()->getError();
    if ($error->getErrCode()) {
        throw new ServiceException($error->getErrCode(), "Error loading shipment data: " . $error->getErrorMessage());
    }
    if (!$rst->Next()) {
        throw new ServiceException(ErrorCodes::NOT_FOUND, "Shipment with ID: $shipmentId was not found");
    }
    $shipment = Shipment::fromDBRecord($rst);

    $sql = "SELECT COUNT(*) AS TOTAL_ALIQUOTS FROM ALIQUOTS WHERE ID_SHIPMENT=:id";
    $rst = Database::getInstance()->ExecuteBindQuery($sql, $shipmentId);
    if ($rst->Next()) {
        $numAliquots = $rst->GetField('TOTAL_ALIQUOTS');
    }
    if (!$numAliquots) {
        throw new ServiceException(ErrorCodes::DATA_MISSING, "A shipment can't be sent if it doesn't contain aliquots");
    }

    // Mark the shipment as "Shipped" and indicate the datetime
    $shipment->statusId = ShipmentStatus::SHIPPED;
    $shipment->sendDate = DateHelper::currentDate();
    $shipment->senderId = loadParam($parameters, 'senderId');
    $shipment->sentToId = loadParam($parameters, 'sentToId');

    if (!$shipment->ref) {
        throw new ServiceException(ErrorCodes::DATA_MISSING, "Shipment reference was not informed but is mandatory for sending a shipment");
    }
    if (!$shipment->senderId) {
        throw new ServiceException(ErrorCodes::DATA_MISSING, "Sender Id was not informed but is mandatory for sending a shipment");
    }
    if (!$shipment->sentToId) {
        throw new ServiceException(ErrorCodes::DATA_MISSING, "Destination was not informed but is mandatory for sending a shipment");
    }

    try {
        $user = $api->user_get($shipment->senderId);
        $shipment->senderName = $user->getFullName();
    } catch (Exception $e) {}

    // Update the last modification date of the aliquots and generate a tracking record
    $arrVariables = [':shipmentId' => $shipmentId, ':updated' => $shipment->sendDate];
    $sql = "SELECT * FROM ALIQUOTS WHERE ID_SHIPMENT=:shipmentId";
    $rst = Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);
    $error = Database::getInstance()->getError();
    if ($error->getErrCode()) {
        throw new ServiceException($error->getErrCode(), "Error sending shipment: " . $error->getErrorMessage());
    }

    $aliquotList = [];
    while ($rst->Next()) {
        $colNames = $rst->getColumnNames();
        $aliquot = [];
        foreach ($colNames as $colName) {
            $aliquot[$colName] = $rst->GetField($colName);
        }
        $aliquot['UPDATED'] = $shipment->sendDate;
        $aliquotList[] = $aliquot;
    }

    updateShipment($shipment);
    ServiceFunctions::trackAliquots($aliquotList, null, $shipmentId);

    return new ServiceResponse($shipment->d, null);
}

/**
 *
 * @param array $parameters
 */
function shipment_delete($parameters) {
    $shipmentId = loadParam($parameters, 'id');

    $sql = "SELECT * FROM SHIPMENTS s WHERE s.ID_SHIPMENT = :id";
    $rst = Database::getInstance()->ExecuteBindQuery($sql, $shipmentId);
    $error = Database::getInstance()->getError();
    if ($error->getErrCode()) {
        throw new ServiceException($error->getErrCode(), "Error loading shipment data: " . $error->getErrorMessage());
    }
    if (!$rst->Next()) {
        throw new ServiceException(ErrorCodes::NOT_FOUND, "Shipment with ID: $shipmentId was not found");
    }
    $shipment = Shipment::fromDBRecord($rst);

    if ($shipment->statusId != ShipmentStatus::PREPARING) {
        throw new ServiceException(ErrorCodes::INVALID_STATE, "Shipment with ID: $shipmentId can't be deleted because it is not in 'Preparing' status");
    }

    $arrVariables = [':id' => $shipmentId, ':aliquotStatus' => AliquotStatus::AVAILABLE];
    $sqls = [];
    $sqls[] = "DELETE FROM SHIPPED_ALIQUOTS WHERE ID_SHIPMENT=:id";
    $sqls[] = "UPDATE ALIQUOTS SET ID_SHIPMENT = NULL, ID_STATUS = :aliquotStatus WHERE ID_SHIPMENT = :id";
    $sqls[] = "DELETE FROM SHIPMENTS WHERE ID_SHIPMENT=:id";
    foreach ($sqls as $sql) {
        Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);
        $error = Database::getInstance()->getError();
        if ($error->getErrCode()) {
            throw new ServiceException($error->getErrCode(), "Error deleting shipment: " . $error->getErrorMessage());
        }
    }

    return new ServiceResponse($shipmentId, null);
}

/**
 * Loads the details of a specific shipment, including its aliquots.
 *
 * @param stdClass $parameters
 * @return ServiceResponse
 */
function shipment_details($parameters) {
    $api = LinkcareSoapAPI::getInstance();
    $shipmentId = loadParam($parameters, 'id');

    $arrVariables = [':shipmentId' => $shipmentId];
    $sql = "SELECT s.*, l1.NAME as SENT_FROM, l2.NAME as SENT_TO
            FROM SHIPMENTS s
                LEFT JOIN LOCATIONS l1 ON s.ID_SENT_FROM = l1.ID_LOCATION
                LEFT JOIN LOCATIONS l2 ON s.ID_SENT_TO = l2.ID_LOCATION
            WHERE s.ID_SHIPMENT = :shipmentId";
    $rst = Database::getInstance()->executeBindQuery($sql, $arrVariables);
    $error = Database::getInstance()->getError();
    if ($error->getErrCode()) {
        throw new ServiceException($error->getErrCode(), $error->getErrorMessage());
    }

    if (!$rst->Next()) {
        throw new ServiceException(ErrorCodes::NOT_FOUND, "Shipment not found");
    }

    $shipment = Shipment::fromDBRecord($rst);
    $shipment->getAliquots(null, $api->getSession()->getTimezone()); // Force loading the aliquots of the shipment

    return new ServiceResponse($shipment->toJSON($api->getSession()->getTimezone()), null);
}

/**
 * Loads the details of a specific shipment, including its aliquots.
 *
 * @param stdClass $parameters
 * @return ServiceResponse
 */
function shippable_aliquots($parameters) {
    $api = LinkcareSoapAPI::getInstance();
    $locationId = loadParam($parameters, 'locationId');
    $page = loadParam($parameters, 'page');
    $pageSize = loadParam($parameters, 'pageSize');

    $arrVariables = [':locationId' => $locationId, ':statusId' => AliquotStatus::AVAILABLE];

    $conditions = [];
    if ($excludeIds = loadParam($parameters, 'excludeIds')) {
        $excludeIds = explode(',', $excludeIds);
        if (count($excludeIds) > 0) {
            $exclude = DbHelper::bindParamArray('exId', $parameters, $arrVariables);
            $conditions[] = "a.ID_ALIQUOT NOT IN ($exclude)";
        }
    }

    $filter = "";
    if (!empty($conditions)) {
        $filter = 'AND ' . implode(' AND ', $conditions);
    }

    $queryColumns = "a.* , l.NAME AS LOCATION_NAME";
    $queryFromClause = "FROM ALIQUOTS a LEFT JOIN LOCATIONS l ON a.ID_LOCATION = l.ID_LOCATION WHERE a.ID_LOCATION = :locationId AND a.ID_STATUS = :statusId $filter";
    list($rst, $totalRows) = fetchWithPagination($queryColumns, $queryFromClause, $arrVariables, $pageSize, $page);

    $available = [];
    while ($rst->Next()) {
        $available[] = Aliquot::fromDBRecord($rst);
    }

    $data = new stdClass();
    $timezone = $api->getSession()->getTimezone();
    $data->rows = array_map(function ($aliquot) use ($timezone) {
        /** @var Aliquot $aliquot */
        return $aliquot->toJSON($timezone);
    }, $available);
    $data->total_count = $totalRows;

    return new ServiceResponse($data, null);
}

/**
 *
 * @param stdClass $parameters
 * @return ServiceResponse
 */
function find_aliquot($parameters) {
    $aliquotId = loadParam($parameters, 'aliquotId');
    $arrVariables = [':aliquotId' => $aliquotId];
    $conditions = [];
    if ($locationId = loadParam($parameters, 'locationId')) {
        $conditions[] = "a.ID_LOCATION = :locationId";
        $arrVariables[':locationId'] = $locationId;
    }
    if ($statusId = loadParam($parameters, 'statusId')) {
        $conditions[] = "a.ID_STATUS = :statusId";
        $arrVariables[':statusId'] = $statusId;
    }
    if ($excludeIds = loadParam($parameters, 'excludeIds')) {
        $excludeIds = explode(',', $excludeIds);
        if (count($excludeIds) > 0) {
            $exclude = DbHelper::bindParamArray('exId', $parameters, $arrVariables);
            $conditions[] = "a.ID_ALIQUOT NOT IN ($exclude)";
        }
    }

    $filter = "";
    if (!empty($conditions)) {
        $filter = 'AND ' . implode(' AND ', $conditions);
    }

    $sql = "SELECT a.* , l.NAME AS LOCATION_NAME FROM ALIQUOTS a LEFT JOIN LOCATIONS l ON a.ID_LOCATION = l.ID_LOCATION WHERE a.ID_ALIQUOT = :aliquotId $filter";
    $rst = Database::getInstance()->executeBindQuery($sql, $arrVariables);
    $error = Database::getInstance()->getError();
    if ($error->getErrCode()) {
        return new ServiceResponse(null, $error->getErrorMessage());
    }
    if (!$rst->Next()) {
        return new ServiceResponse(null, "Aliquot not found");
    }

    $aliquot = new stdClass();
    $aliquot->id = $rst->GetField('ID_ALIQUOT');
    $aliquot->patientId = $rst->GetField('ID_PATIENT');
    $aliquot->type = $rst->GetField('SAMPLE_TYPE');
    $aliquot->locationId = $rst->GetField('ID_LOCATION');
    $aliquot->locationName = $rst->GetField('LOCATION_NAME');
    $aliquot->statusId = $rst->GetField('ID_STATUS');
    $aliquot->statusName = AliquotStatus::getName($rst->GetField('ID_STATUS'));
    $aliquot->created = $rst->GetField('CREATED');
    $aliquot->lastUpdate = $rst->GetField('UPDATED');

    return new ServiceResponse($aliquot, null);
}

/**
 * Adds an aliquot to a shipment.
 *
 * @param stdClass $params
 */
function shipment_add_aliquot($params) {
    $shipmentId = loadParam($params, 'shipmentId');
    $aliquotId = loadParam($params, 'aliquotId');

    if (!Shipment::exists($shipmentId)) {
        throw new ServiceException(ErrorCodes::NOT_FOUND, "Shipment with ID $shipmentId not found");
    }

    $arrVariables = [':shipmentId' => $shipmentId, ':aliquotId' => $aliquotId, ':statusId' => AliquotStatus::IN_TRANSIT];
    $sql = "INSERT INTO SHIPPED_ALIQUOTS (ID_SHIPMENT, ID_ALIQUOT, ID_STATUS) VALUES (:shipmentId, :aliquotId, :statusId)";
    Database::getInstance()->executeBindQuery($sql, $arrVariables);
    $error = Database::getInstance()->getError();
    if (!$error->errCode) {
        // Update also the current status of the aliquot
        $arrVariables = [':shipmentId' => $shipmentId, ':aliquotId' => $aliquotId, ':statusId' => AliquotStatus::IN_TRANSIT];
        $sql = "UPDATE ALIQUOTS SET ID_STATUS = :statusId, ID_SHIPMENT=:shipmentId WHERE ID_ALIQUOT = :aliquotId";
        Database::getInstance()->executeBindQuery($sql, $arrVariables);
        $error = Database::getInstance()->getError();
    }
    if ($error->getErrCode()) {
        throw new ServiceException($error->getErrCode(), $error->getErrorMessage());
    }

    return new ServiceResponse(1, null);
}

/**
 * Removes an aliquot from a shipment.
 *
 * @param stdClass $params
 */
function shipment_remove_aliquot($params) {
    $shipmentId = loadParam($params, 'shipmentId');
    $aliquotId = loadParam($params, 'aliquotId');

    if (!Shipment::exists($shipmentId)) {
        throw new ServiceException(ErrorCodes::NOT_FOUND, "Shipment with ID $shipmentId not found");
    }

    $arrVariables = [':shipmentId' => $shipmentId, ':aliquotId' => $aliquotId];
    $sql = "DELETE FROM SHIPPED_ALIQUOTS WHERE ID_SHIPMENT=:shipmentId AND ID_ALIQUOT=:aliquotId";
    Database::getInstance()->executeBindQuery($sql, $arrVariables);
    $error = Database::getInstance()->getError();
    if (!$error->errCode) {
        // Update also the current status of the aliquot
        $arrVariables = [':shipmentId' => null, ':aliquotId' => $aliquotId, ':statusId' => AliquotStatus::AVAILABLE];
        $sql = "UPDATE ALIQUOTS SET ID_STATUS = :statusId, ID_SHIPMENT=:shipmentId WHERE ID_ALIQUOT = :aliquotId";
        Database::getInstance()->executeBindQuery($sql, $arrVariables);
        $error = Database::getInstance()->getError();
    }
    if ($error->getErrCode()) {
        throw new ServiceException($error->getErrCode(), $error->getErrorMessage());
    }

    return new ServiceResponse(1, null);
}

/* ************************************************************************************* */
/* ************************************************************************************* */
/* ************************************************************************************* */

/**
 *
 * @param string $queryColumns
 * @param string $queryFromClause
 * @param array $arrVariables
 * @param number $pageSize
 * @param number $page
 * @return [DbManagerResults, int]
 */
function fetchWithPagination($queryColumns, $queryFromClause, $arrVariables, $pageSize = null, $page = null) {
    if ($pageSize > 0) {
        $offset = ($page > 0) ? 1 + ($page - 1) * $pageSize : null;
    } else {
        $pageSize = null;
    }

    $sqlFecth = "SELECT $queryColumns " . $queryFromClause;
    $sqlCount = "SELECT COUNT(*) AS TOTAL_ROWS " . $queryFromClause;
    $rstCount = Database::getInstance()->executeBindQuery($sqlCount, $arrVariables);
    $error = Database::getInstance()->getError();

    if (!$error->getErrCode()) {
        $rstCount->Next();
        $totalRows = $rstCount->GetField('TOTAL_ROWS');
        $rst = Database::getInstance()->executeBindQuery($sqlFecth, $arrVariables, $pageSize, $offset);
        $error = Database::getInstance()->getError();
    }

    if ($error->getErrCode()) {
        throw new ServiceException($error->getErrCode(), $error->getErrorMessage());
    }

    return [$rst, $totalRows];
}

/**
 * Checks if a shipment exists in the database.
 *
 * @param number $shipmentId
 * @return Shipment|null
 */
// function shipmentExists($shipmentId) {
// $arrVariables = [':shipmentId' => $shipmentId];
// $sql = "SELECT ID_SHIPMENT FROM SHIPMENTS WHERE ID_SHIPMENT = :shipmentId";
// $rst = Database::getInstance()->executeBindQuery($sql, $arrVariables);
// $error = Database::getInstance()->getError();
// if ($error->getErrCode()) {
// throw new ServiceException($error->getErrCode(), $error->getErrorMessage());
// }
// if ($rst->Next()) {
// return self::fromDBRecord($rst);
// } else {
// return null;
// }
// }

/**
 *
 * @param Shipment $shipment
 */
function updateShipment($shipment) {
    $arrVariables = [':shipmentId' => $shipment->id];
    $updateFields = [];
    if ($shipment->ref) {
        $arrVariables[':shipmentRef'] = $shipment->ref;
        $updateFields[] = "SHIPMENT_REF = :shipmentRef";
    }
    if ($shipment->sentFromId) {
        $arrVariables[':sentFromId'] = $shipment->sentFromId;
        $updateFields[] = "ID_SENT_FROM = :sentFromId";
    }
    if ($shipment->sentToId) {
        $arrVariables[':sentToId'] = $shipment->sentToId;
        $updateFields[] = "ID_SENT_TO = :sentToId";
    }
    if ($shipment->senderId) {
        $arrVariables[':senderId'] = $shipment->senderId;
        $updateFields[] = "ID_SENDER = :senderId";
    }
    if ($shipment->senderName) {
        $arrVariables[':senderName'] = $shipment->senderName;
        $updateFields[] = "SENDER = :senderName";
    }
    if ($shipment->statusId) {
        $arrVariables[':statusId'] = $shipment->statusId;
        $updateFields[] = "ID_STATUS = :statusId";
    }
    if ($shipment->sendDate) {
        $arrVariables[':sendDate'] = $shipment->sendDate;
        $updateFields[] = "SHIPMENT_DATE = :sendDate";
    }

    if (empty($updateFields)) {
        throw new ServiceException(ErrorCodes::DATA_MISSING, "No data provided to update the shipment");
    }

    $updateFields = implode(', ', $updateFields);
    $sql = "UPDATE SHIPMENTS SET $updateFields WHERE ID_SHIPMENT = :shipmentId";
    Database::getInstance()->executeBindQuery($sql, $arrVariables);
    $error = Database::getInstance()->getError();
    if ($error->getErrCode()) {
        throw new ServiceException($error->getErrCode(), "Error updating shipment: " . $error->getErrorMessage());
    }
}