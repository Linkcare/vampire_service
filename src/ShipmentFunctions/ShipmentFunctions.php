<?php
require_once $_SERVER["DOCUMENT_ROOT"] . '/vendor/autoload.php';
use avadim\FastExcelReader\Excel;

require_once 'DbDataModels.php';
require_once 'constants/ShipmentErrorCodes.php';
require_once 'constants/AliquotStatus.php';
require_once 'constants/AliquotConditions.php';
require_once 'constants/BloodSampleTypes.php';
require_once 'constants/ShipmentStatus.php';
require_once 'constants/ReceptionStatus.php';
require_once 'constants/AliquotAuditActions.php';
require_once 'types/ShipmentException.php';
require_once 'types/Shipment.php';
require_once 'types/Aliquot.php';
require_once 'types/AliquotChange.php';
require_once 'types/BulkChange.php';

class ShipmentFunctions {
    static $timezone = 'UTC';
    static $activeLocation = null;

    /**
     * Returns the DB schema definition for the shipments service
     *
     * @param string $schemaName
     * @return DbSchemaDefinition
     */
    static public function getDataModel($schemaName) {
        return DbDataModels::shipmentsModel($schemaName);
    }

    /**
     * Set the timezone to be used for date conversions
     *
     * @param string $timezone
     */
    static public function setTimezone($timezone) {
        self::$timezone = $timezone;
    }

    /**
     * Set the default location (used for listings when no specific location is provided)
     *
     * @param string $locationId
     */
    static public function setActiveLocation($locationId) {
        self::$activeLocation = $locationId;
    }

    static public function shipment_locations($parameters) {
        $onlyLabs = textToBool(loadParam($parameters, 'onlyLabs', false));

        if ($onlyLabs) {
            $condition = "WHERE IS_LAB=1";
        }
        $sql = "SELECT * FROM LOCATIONS $condition ORDER BY NAME";
        $rst = Database::getInstance()->executeBindQuery($sql);

        $locations = [];
        while ($rst->Next()) {
            $location = new stdClass();
            $location->id = $rst->GetField('ID_LOCATION');
            $location->code = $rst->GetField('CODE');
            $location->name = $rst->GetField('NAME');
            $locations[] = $location;
        }

        return $locations;
    }

    /**
     * Add or update a location to the database
     *
     * @param string $locationId
     * @param string $code
     * @param string $name
     * @param boolean $isLab
     * @param boolean $isClinicalSite
     */
    static public function addLocation($locationId, $code, $name, $isLab = false, $isClinicalSite = true) {
        $keyColumns = ['ID_LOCATION' => ':id'];
        $updateColumns = ['NAME' => ':name', 'CODE' => ':code', 'IS_LAB' => ':is_lab', 'IS_CLINICAL_SITE' => ':is_clinical_site'];
        $arrVariables = [':id' => $locationId, ':name' => $name, ':code' => $code, ':is_lab' => $isLab ? 1 : 0,
                ':is_clinical_site' => $isClinicalSite ? 1 : 0];
        $sql = Database::getInstance()->buildInsertOrUpdateQuery('LOCATIONS', $keyColumns, $updateColumns);

        Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);
    }

    /**
     * Checks if a location exists in the database.
     *
     * @param string $locationId
     * @return boolean
     */
    static public function locationExists($locationId) {
        $sql = "SELECT ID_LOCATION,NAME FROM LOCATIONS WHERE ID_LOCATION=:id";
        $rst = Database::getInstance()->ExecuteBindQuery($sql, [':id' => $locationId]);
        if ($rst->Next()) {
            return true;
        }

        return false;
    }

    /**
     * Loads the list of all shipments that have been sent from or to the TEAM of the active user's session.
     *
     * Parameters expected ($parameters):<br/>
     * {<br/>
     * ··"activeLocation": 2, // Location that is related to the shipment (either as sender or receiver)<br/>
     * ··"page": 1, // Page number (used for pagination)<br/>
     * ··"pageSize": 25, // Number of records per page (used for pagination)<br/>
     * ··"filters": ""<br/>
     * }<br/>
     *
     * @param stdClass $parameters
     * @return object
     */
    static public function shipment_list($parameters) {
        $locationId = loadParam($parameters, 'activeLocation');
        $page = loadParam($parameters, 'page');
        $pageSize = loadParam($parameters, 'pageSize');
        $filters = loadParam($parameters, 'filters');
        cleanFilters($filters);

        $multipleLocations = explode(',', $locationId);
        $locationCondition = "";
        if (count($multipleLocations) > 1) {
            $arrVariables = [];
            $condition = DbHelper::bindParamArray('locationId', $multipleLocations, $arrVariables);
            $locationCondition = "(s.ID_SENT_FROM IN ($condition) OR (s.ID_SENT_TO IN ($condition) AND s.ID_STATUS <> :statusPreparing))";
        } else {
            $locationCondition = "(s.ID_SENT_FROM=:locationId OR (s.ID_SENT_TO=:locationId AND s.ID_STATUS <> :statusPreparing))";
            $arrVariables = [':locationId' => $locationId];
        }

        $arrVariables[':activeTeamId'] = $locationId ? $locationId : self::$activeLocation;
        $arrVariables[':statusPreparing'] = ShipmentStatus::PREPARING;

        $filterConditions = [];
        if ($filters && property_exists($filters, 'ref') && $filters->ref) {
            $likeExpr = Database::getInstance()->fnConcat("'%'", ':shipmentRef', "'%'");
            $filterConditions[] = "SHIPMENT_REF LIKE $likeExpr";
            $arrVariables[':shipmentRef'] = $filters->ref;
        }
        if ($filters && property_exists($filters, 'sentFrom') && $filters->sentFrom) {
            $filterConditions[] = "ID_SENT_FROM=:sentFromId";
            $arrVariables[':sentFromId'] = $filters->sentFrom;
        }
        if ($filters && property_exists($filters, 'sentTo') && $filters->sentTo) {
            $filterConditions[] = "ID_SENT_TO=:sentToId";
            $arrVariables[':sentToId'] = $filters->sentTo;
        }

        $filterSql = empty($filterConditions) ? "" : " AND " . implode(' AND ', $filterConditions);

        $queryColumns = "s.*, l1.NAME as SENT_FROM, l2.NAME as SENT_TO";
        $queryFromClause = "FROM SHIPMENTS s
                LEFT JOIN LOCATIONS l1 ON s.ID_SENT_FROM = l1.ID_LOCATION
                LEFT JOIN LOCATIONS l2 ON s.ID_SENT_TO = l2.ID_LOCATION
            WHERE $locationCondition $filterSql";
        list($rst, $totalRows) = self::fetchWithPagination($queryColumns, $queryFromClause, $arrVariables, $pageSize, $page);

        $shipmentList = [];
        while ($rst->Next()) {
            $shipmentList[] = Shipment::fromDBRecord($rst);
        }

        $data = new stdClass();
        $timezone = self::$timezone;
        $data->rows = array_map(function ($shipment) use ($timezone) {
            /** @var Shipment $shipment */
            return $shipment->toJSON($timezone);
        }, $shipmentList);
        $data->total_count = $totalRows;

        return $data;
    }

    /**
     * Creates a new shipment
     *
     * @param stdClass $parameters
     * @return object
     */
    static public function shipment_create($parameters) {
        $shipmentRef = loadParam($parameters, 'ref');
        $sentFromId = loadParam($parameters, 'sentFromId');
        $sentToId = loadParam($parameters, 'sentToId');
        $senderId = loadParam($parameters, 'senderId');
        $senderName = loadParam($parameters, 'sender');
        if (!$sentFromId) {
            throw new ShipmentException(ShipmentErrorCodes::DATA_MISSING, "It is mandatory to provide the location from which the shipment is sent");
        }
        if ($sentFromId == $sentToId) {
            throw new ShipmentException(ShipmentErrorCodes::INVALID_DATA, "Shipment cannot be sent to the same location");
        }

        $arrVariables[':shipmentRef'] = $shipmentRef;
        $arrVariables[':status'] = ShipmentStatus::PREPARING;
        $arrVariables[':sentFromId'] = $sentFromId;
        $arrVariables[':sentToId'] = $sentToId;
        $arrVariables[':senderId'] = $senderId;
        $arrVariables[':senderName'] = $senderName;

        $sql = "INSERT INTO SHIPMENTS (SHIPMENT_REF, ID_STATUS, ID_SENT_FROM, ID_SENT_TO, ID_SENDER, SENDER) VALUES (:shipmentRef, :status, :sentFromId, :sentToId, :senderId, :senderName)";
        Database::getInstance()->executeBindQuery($sql, $arrVariables);

        $data = new stdClass();
        Database::getInstance()->getLastInsertedId($data->id);
        return $data;
    }

    /**
     * Updates the information of an existing shipment (that has not been shipped yet)
     * Only the properties relevant for the shipment are updated
     *
     * @param array $parameters
     * @return object
     */
    static public function shipment_update($parameters) {
        $id = loadParam($parameters, 'id');
        $shipment = Shipment::exists($id);
        if (!$shipment) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "Shipment with ID " . $id . " not found");
        }

        if ($shipment->statusId == ShipmentStatus::PREPARING) {
            preserveProperties($parameters, ['id', 'ref', 'sentFromId', 'sentToId', 'sendDate']);
        } elseif ($shipment->statusId == ShipmentStatus::RECEIVING) {
            preserveProperties($parameters, ['id', 'receiverId', 'receptionDate', 'receptionStatusId', 'receptionComments']);
        } else {
            throw new ShipmentException(ShipmentErrorCodes::INVALID_STATUS, "Shipment with ID $id cannot be updated because it is not in a status that allows updates");
        }
        // Copy the parameters received tracking the modified ones
        $shipment->trackedCopy($parameters, self::$timezone);
        $shipment->updateModified();

        return $shipment->id;
    }

    /**
     * Mark a shipment as "Sent"
     *
     * @param stdClass $parameters
     */
    static public function shipment_send($parameters) {
        $shipmentId = loadParam($parameters, 'id');
        $shipmentDate = loadParam($parameters, 'sendDate', DateHelper::currentDate());
        $shipment = Shipment::exists($shipmentId);
        if (!$shipment) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "Shipment $shipmentId not found");
        }

        $sql = "SELECT COUNT(*) AS TOTAL_ALIQUOTS FROM ALIQUOTS WHERE ID_SHIPMENT=:id";
        $rst = Database::getInstance()->ExecuteBindQuery($sql, $shipmentId);
        if ($rst->Next()) {
            $numAliquots = $rst->GetField('TOTAL_ALIQUOTS');
        }
        if (!$numAliquots) {
            throw new ShipmentException(ShipmentErrorCodes::DATA_MISSING, "A shipment can't be sent if it doesn't contain aliquots");
        }

        // Mark the shipment as "Shipped" and indicate the datetime
        $parameters->statusId = ShipmentStatus::SHIPPED;
        if (!DateHelper::isValidDate($shipmentDate)) {
            throw new ShipmentException(ShipmentErrorCodes::INVALID_DATA_FORMAT, "Invalid shipment date: " . $parameters->sendDate);
        }

        $shipment->trackedCopy($parameters);
        if (!$shipment->ref) {
            throw new ShipmentException(ShipmentErrorCodes::DATA_MISSING, "Shipment reference was not informed but is mandatory for sending a shipment");
        }
        if (!$shipment->senderId) {
            throw new ShipmentException(ShipmentErrorCodes::DATA_MISSING, "Sender Id was not informed but is mandatory for sending a shipment");
        }
        if (!$shipment->sentToId) {
            throw new ShipmentException(ShipmentErrorCodes::DATA_MISSING, "Destination was not informed but is mandatory for sending a shipment");
        }

        // Update the last modification date of the aliquots and generate a tracking record
        $aliquots = $shipment->getAliquots();
        foreach ($aliquots as $aliquot) {
            $aliquot->statusId = AliquotStatus::IN_TRANSIT;
            $aliquot->lastUpdate = $shipment->sendDate;
        }

        $arrVariables = [':shipmentId' => $shipmentId];
        $sql = "SELECT * FROM ALIQUOTS WHERE ID_SHIPMENT=:shipmentId";
        $rst = Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);

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

        $shipment->updateModified();
        self::trackAliquots($aliquotList, AliquotAuditActions::SHIPPED);

        return $shipment->id;
    }

    /**
     *
     * @param stdClass $parameters
     * @return object
     */
    static public function shipment_start_reception($parameters) {
        $shipmentId = loadParam($parameters, 'id');
        $shipment = Shipment::exists($shipmentId);
        if (!$shipment) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "Shipment $shipmentId not found");
        }

        // Mark the shipment as "Receiving"
        $modify = new stdClass();
        $modify->statusId = ShipmentStatus::RECEIVING;

        $shipment->trackedCopy($modify);

        $shipment->updateModified();

        return $shipment->id;
    }

    /**
     * Mark a shipment as "Received"
     *
     * @param array $parameters
     */
    static public function shipment_finish_reception($parameters) {
        preserveProperties($parameters, ['id', 'receptionDate', 'receiverId', 'receptionStatusId', 'receptionComments']);
        $parameters->statusId = ShipmentStatus::RECEIVED;

        $shipmentId = loadParam($parameters, 'id');

        $shipment = Shipment::exists($shipmentId);
        if (!$shipment) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "Shipment $shipmentId not found");
        }

        // Mark the shipment as "Received" and indicate the datetime
        $shipment->trackedCopy($parameters, self::$timezone);

        if (!$shipment->receptionDate) {
            throw new ShipmentException(ShipmentErrorCodes::DATA_MISSING, "Reception datetime was not informed but is mandatory for receiving a shipment");
        }
        if (!$shipment->receiverId) {
            throw new ShipmentException(ShipmentErrorCodes::DATA_MISSING, "Receiver Id was not informed but is mandatory for receiving a shipment");
        }
        if (!$shipment->receptionStatusId) {
            throw new ShipmentException(ShipmentErrorCodes::DATA_MISSING, "Reception status was not informed but is mandatory for receiving a shipment");
        }

        $shipment->updateModified();

        // Update the new location, last modification date and the rejection reason (if any) of the aliquots
        $sqls = [];
        $arrVariables = [':shipmentId' => $shipmentId, ':updated' => $shipment->receptionDate, ':rejectedStatus' => AliquotStatus::REJECTED,
                ':okStatus' => AliquotStatus::AVAILABLE, ':locationId' => $shipment->sentToId];
        $sqls[] = "UPDATE ALIQUOTS a, SHIPPED_ALIQUOTS sa
            SET a.ALIQUOT_UPDATED=:updated, a.ID_STATUS=:rejectedStatus, a.ID_ALIQUOT_CONDITION=sa.ID_ALIQUOT_CONDITION,
                a.ID_LOCATION=:locationId, a.ID_SHIPMENT=NULL
            WHERE
                sa.ID_SHIPMENT=:shipmentId
            	AND a.ID_ALIQUOT = sa.ID_ALIQUOT
            	AND sa.ID_ALIQUOT_CONDITION IS NOT NULL AND sa.ID_ALIQUOT_CONDITION <> ''";
        $sqls[] = "UPDATE ALIQUOTS a, SHIPPED_ALIQUOTS sa
            SET a.ALIQUOT_UPDATED=:updated, a.ID_STATUS=:okStatus, a.ID_ALIQUOT_CONDITION=NULL,
                a.ID_LOCATION=:locationId, a.ID_SHIPMENT=NULL
            WHERE
                sa.ID_SHIPMENT=:shipmentId
            	AND a.ID_ALIQUOT = sa.ID_ALIQUOT
            	AND (sa.ID_ALIQUOT_CONDITION IS NULL OR sa.ID_ALIQUOT_CONDITION = '')";
        foreach ($sqls as $sql) {
            Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);
        }

        // Generate a tracking record for each aliquot received
        $arrVariables = [':shipmentId' => $shipmentId];
        $sql = "SELECT * FROM ALIQUOTS WHERE ID_ALIQUOT IN (SELECT ID_ALIQUOT FROM SHIPPED_ALIQUOTS WHERE ID_SHIPMENT = :shipmentId)";
        $rst = Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);

        $aliquotList = [];
        $currentDate = DateHelper::currentDate();
        while ($rst->Next()) {
            $colNames = $rst->getColumnNames();
            $aliquot = [];
            foreach ($colNames as $colName) {
                $aliquot[$colName] = $rst->GetField($colName);
            }
            $aliquot['UPDATED'] = $currentDate;
            $aliquotList[] = $aliquot;
        }

        self::trackAliquots($aliquotList, AliquotAuditActions::RECEIVED);

        return $shipment->id;
    }

    /**
     *
     * @param array $parameters
     */
    static public function shipment_delete($parameters) {
        $shipmentId = loadParam($parameters, 'id');

        $sql = "SELECT * FROM SHIPMENTS s WHERE s.ID_SHIPMENT = :id";
        $rst = Database::getInstance()->ExecuteBindQuery($sql, $shipmentId);
        if (!$rst->Next()) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "Shipment with ID: $shipmentId was not found");
        }
        $shipment = Shipment::fromDBRecord($rst);

        if ($shipment->statusId != ShipmentStatus::PREPARING) {
            throw new ShipmentException(ShipmentErrorCodes::INVALID_STATUS, "Shipment with ID: $shipmentId can't be deleted because it is not in 'Preparing' status");
        }

        $arrVariables = [':id' => $shipmentId, ':aliquotStatus' => AliquotStatus::AVAILABLE];
        $sqls = [];
        $sqls[] = "DELETE FROM SHIPPED_ALIQUOTS WHERE ID_SHIPMENT=:id";
        $sqls[] = "UPDATE ALIQUOTS SET ID_SHIPMENT = NULL, ID_STATUS = :aliquotStatus WHERE ID_SHIPMENT = :id";
        $sqls[] = "DELETE FROM SHIPMENTS WHERE ID_SHIPMENT=:id";
        foreach ($sqls as $sql) {
            Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);
        }

        return $shipmentId;
    }

    /**
     * Loads the details of a specific shipment, including its aliquots.
     *
     * @param stdClass $parameters
     * @return object
     */
    static public function shipment_details($parameters) {
        $shipmentId = loadParam($parameters, 'id');

        $arrVariables = [':shipmentId' => $shipmentId];
        $sql = "SELECT s.*, l1.NAME as SENT_FROM, l2.NAME as SENT_TO
            FROM SHIPMENTS s
                LEFT JOIN LOCATIONS l1 ON s.ID_SENT_FROM = l1.ID_LOCATION
                LEFT JOIN LOCATIONS l2 ON s.ID_SENT_TO = l2.ID_LOCATION
            WHERE s.ID_SHIPMENT = :shipmentId";
        $rst = Database::getInstance()->executeBindQuery($sql, $arrVariables);

        if (!$rst->Next()) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "Shipment not found");
        }

        $shipment = Shipment::fromDBRecord($rst);
        $shipment->getAliquots(null, self::$timezone); // Force loading the aliquots of the shipment

        return $shipment->toJSON(self::$timezone);
    }

    /**
     * Loads the details of a specific shipment, including its aliquots.
     *
     * @param stdClass $parameters
     * @return object
     */
    static public function shippable_aliquots($parameters) {
        $locationId = loadParam($parameters, 'locationId');
        $page = loadParam($parameters, 'page');
        $pageSize = loadParam($parameters, 'pageSize');
        $filters = loadParam($parameters, 'filters');
        cleanFilters($filters);

        $arrVariables = [':locationId' => $locationId, ':statusId' => AliquotStatus::AVAILABLE];

        $filterConditions = [];
        if ($filters && property_exists($filters, 'id') && $filters->id) {
            $likeExpr = Database::getInstance()->fnConcat("'%'", ':aliquotId', "'%'");
            $filterConditions[] = "a.ID_ALIQUOT LIKE $likeExpr";
            $arrVariables[':aliquotId'] = $filters->id;
        }
        if ($filters && property_exists($filters, 'patientRef') && $filters->patientRef) {
            $likeExpr = Database::getInstance()->fnConcat("'%'", ':patientRef', "'%'");
            $filterConditions[] = "a.PATIENT_REF LIKE $likeExpr";
            $arrVariables[':patientRef'] = $filters->patientRef;
        }
        if ($filters && property_exists($filters, 'type') && $filters->type) {
            $likeExpr = Database::getInstance()->fnConcat("'%'", ':sampleType', "'%'");
            $filterConditions[] = "a.SAMPLE_TYPE LIKE $likeExpr";
            $arrVariables[':sampleType'] = $filters->type;
        }

        if ($excludeIds = loadParam($parameters, 'excludeIds')) {
            $excludeIds = explode(',', $excludeIds);
            if (count($excludeIds) > 0) {
                $exclude = DbHelper::bindParamArray('exId', $parameters, $arrVariables);
                $filterConditions[] = "a.ID_ALIQUOT NOT IN ($exclude)";
            }
        }

        $filterSql = empty($filterConditions) ? "" : " AND " . implode(' AND ', $filterConditions);

        $queryColumns = "a.* , l.NAME AS LOCATION_NAME";
        $queryFromClause = "FROM ALIQUOTS a LEFT JOIN LOCATIONS l ON a.ID_LOCATION = l.ID_LOCATION
                        WHERE a.ID_LOCATION = :locationId AND a.ID_STATUS = :statusId $filterSql";
        list($rst, $totalRows) = self::fetchWithPagination($queryColumns, $queryFromClause, $arrVariables, $pageSize, $page);

        $available = [];
        while ($rst->Next()) {
            $available[] = Aliquot::fromDBRecord($rst);
        }

        $data = new stdClass();
        $timezone = self::$timezone;
        $data->rows = array_map(function ($aliquot) use ($timezone) {
            /** @var Aliquot $aliquot */
            return $aliquot->toJSON($timezone);
        }, $available);
        $data->total_count = $totalRows;

        return $data;
    }

    /**
     * Loads a list of aliquots available in a specific location.
     *
     * @param stdClass $parameters
     * @return object
     */
    static public function aliquot_list($parameters) {
        $locationId = loadParam($parameters, 'locationId');
        $page = loadParam($parameters, 'page');
        $pageSize = loadParam($parameters, 'pageSize');
        $filters = loadParam($parameters, 'filters');
        cleanFilters($filters);

        $multipleLocations = explode(',', $locationId);
        $locationCondition = "";
        if (count($multipleLocations) > 1) {
            $arrVariables = [];
            $condition = DbHelper::bindParamArray('locationId', $multipleLocations, $arrVariables);
            $locationCondition = "a.ID_LOCATION IN ($condition)";
        } else {
            $locationCondition = "a.ID_LOCATION = :locationId";
            $arrVariables = [':locationId' => $locationId];
        }

        $filterConditions = [];
        if ($filters && property_exists($filters, 'aliquotId') && $filters->aliquotId) {
            $likeExpr = Database::getInstance()->fnConcat("'%'", ':aliquotId', "'%'");
            $filterConditions[] = "a.ID_ALIQUOT LIKE $likeExpr";
            $arrVariables[':aliquotId'] = $filters->aliquotId;
        }
        if ($filters && property_exists($filters, 'patientRef') && $filters->patientRef) {
            $likeExpr = Database::getInstance()->fnConcat("'%'", ':patientRef', "'%'");
            $filterConditions[] = "a.PATIENT_REF LIKE $likeExpr";
            $arrVariables[':patientRef'] = $filters->patientRef;
        }
        if ($filters && property_exists($filters, 'type') && $filters->type) {
            $likeExpr = Database::getInstance()->fnConcat("'%'", ':sampleType', "'%'");
            $filterConditions[] = "a.SAMPLE_TYPE LIKE $likeExpr";
            $arrVariables[':sampleType'] = $filters->type;
        }
        if ($filters && property_exists($filters, 'statusId') && $filters->statusId) {
            $filterConditions[] = "a.ID_STATUS = :statusId";
            $arrVariables[':statusId'] = $filters->statusId;
        }
        if ($filters && property_exists($filters, 'conditionId') && $filters->conditionId) {
            if ($filters->conditionId === 'NO_DAMAGE') {
                $filterConditions[] = "(a.ID_ALIQUOT_CONDITION IS NULL OR a.ID_ALIQUOT_CONDITION = :conditionId)";
            } else {
                $filterConditions[] = "a.ID_ALIQUOT_CONDITION = :conditionId";
            }
            $arrVariables[':conditionId'] = $filters->conditionId;
        }

        $filterSql = empty($filterConditions) ? "" : " AND " . implode(' AND ', $filterConditions);

        $queryColumns = "a.* , l.NAME AS LOCATION_NAME";
        $queryFromClause = "FROM ALIQUOTS a LEFT JOIN LOCATIONS l ON a.ID_LOCATION = l.ID_LOCATION
                        WHERE $locationCondition $filterSql";
        list($rst, $totalRows) = self::fetchWithPagination($queryColumns, $queryFromClause, $arrVariables, $pageSize, $page);

        $available = [];
        while ($rst->Next()) {
            $available[] = Aliquot::fromDBRecord($rst);
        }

        $data = new stdClass();
        $timezone = self::$timezone;
        $data->rows = array_map(function ($aliquot) use ($timezone) {
            /** @var Aliquot $aliquot */
            return $aliquot->toJSON($timezone);
        }, $available);
        $data->total_count = $totalRows;

        return $data;
    }

    /**
     * Changes the properties of multiple aliquots at once.
     *
     * Parameters expected in $parameters:<br/>
     * {
     * ··"changeDate": "yyyy-mm-dd hh:nn:ss", // Expected to be in the local timezone of the active user<br/>
     * ··"changedById": "xxxxxxx",<br/>
     * ··"changedBy": "Full Name",<br/>
     * ··"comments": "",<br/>
     * ··"aliquots": [<br/>
     * ····{<br/>
     * ····"id" : "xxxxxxx",<br/>
     * ····"statusId": "REJECTED",<br/>
     * ····"conditionId": "DEFROST"<br/>
     * ····}<br/>
     * ··]<br/>
     * }<br/>
     *
     * @param stdClass $parameters
     * @return string Bulk change identifier
     */
    static public function aliquot_bulk_change($parameters) {
        if ($changeDate = loadParam($parameters, 'changeDate')) {
            if (!DateHelper::isValidDate($changeDate)) {
                throw new ShipmentException(ShipmentErrorCodes::INVALID_DATA_FORMAT, "Invalid change date: " . $parameters->changeDate);
            }
            $changeDate = DateHelper::localToUTC($changeDate, self::$timezone);
        } else {
            DateHelper::currentDate();
        }

        $aliquotsArr = loadParam($parameters, 'aliquots', []);
        if (empty($aliquotsArr)) {
            throw new ShipmentException(ShipmentErrorCodes::DATA_MISSING, "No aliquots were provided for bulk update");
        }

        $aliquotIds = array_map(function ($aliquotJson) {
            return $aliquotJson->id;
        }, $aliquotsArr);

        $aliquotIds = array_unique(array_filter($aliquotIds));
        if (count($aliquotIds) != count($aliquotsArr)) {
            throw new ShipmentException(ShipmentErrorCodes::DATA_MISSING, "Some aliquots have a null or duplicated Aliquot ID");
        }

        $changes = [];
        foreach ($aliquotsArr as $aliquotJson) {
            $statusId = $aliquotJson->statusId;
            if ($statusId == AliquotStatus::REJECTED) {
                $conditionId = $aliquotJson->conditionId;
                if (!$conditionId) {
                    throw new ShipmentException(ShipmentErrorCodes::DATA_MISSING, "Aliquot " . $aliquotJson->id .
                            " was marked as REJECTED but no condition was provided");
                }
            } else {
                $conditionId = null;
            }
            $changes[$aliquotJson->id] = ['statusId' => $statusId, 'conditionId' => $conditionId];
        }

        $aliquots = Aliquot::findAliquots($aliquotIds);
        if (count($aliquots) != count($aliquotIds)) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "Some aliquots were not found in the system");
        }

        // Check if any of tha aliquots is in an state that does not allow changes
        foreach ($aliquots as $aliquot) {
            /** @var Aliquot $aliquot */
            if (!in_array($aliquot->statusId, [AliquotStatus::AVAILABLE])) {
                throw new ShipmentException(ShipmentErrorCodes::INVALID_STATUS, "Aliquot " . $aliquot->id . " is in status " . $aliquot->statusId .
                        " that does not allow bulk changes");
            }
        }

        $bulkChange = new BulkChange();
        $bulkChange->changeDate = $changeDate;
        $bulkChange->comments = loadParam($parameters, 'comments');
        $bulkChange->changedById = loadParam($parameters, 'changedById');
        $bulkChange->changedBy = loadParam($parameters, 'changedBy');

        foreach ($changes as $aliquotId => $change) {
            // Create an aliquot object with the new properties to be changed
            /** @var Aliquot $aliquot */
            $aliquotChange = new AliquotChange($aliquots[$aliquotId]);
            $aliquotChange->newValues->statusId = $change['statusId'];
            $aliquotChange->newValues->conditionId = $change['conditionId'];

            $bulkChange->addChange($aliquotChange);
        }

        $bulkChange->save(true); // Save changes and apply to actual aliquots

        return $bulkChange->id;
    }

    /**
     *
     * @param stdClass $parameters
     * @return object
     */
    static public function find_aliquot($parameters) {
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
        if (!$rst->Next()) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "Aliquot not found");
        }

        $aliquot = Aliquot::fromDBRecord($rst, self::$timezone);

        return $aliquot->toJSON();
    }

    /**
     * Adds an aliquot to a shipment.
     *
     * The function fails if the aliquot doesn't exist in the specified location, if it is not in AVAILABLE status,
     * or if the shipment is not in PREPARING status.<br/>
     * <br/>
     * Parameters expected in $parameters:<br/>
     * {
     * ··"shipmentId": "sssssss",<br/>
     * ··"aliquotId": "xxxxxxx",<br/>
     * ··"locationId": 2 // Identifier of the current location of the aliquot<br/>
     * }<br/>
     *
     * @param stdClass $params
     */
    static public function shipment_add_aliquot($params) {
        $shipmentId = loadParam($params, 'shipmentId');
        $aliquotId = loadParam($params, 'aliquotId');
        $locationId = loadParam($params, 'locationId');

        $shipment = Shipment::exists($shipmentId);
        if (!$shipment) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "Shipment with ID $shipmentId not found");
        }
        if ($shipment->statusId != ShipmentStatus::PREPARING) {
            throw new ShipmentException(ShipmentErrorCodes::FORBIDDEN_OPERATION, "Shipment with ID is not available for adding aliquots");
        }

        $aliquot = Aliquot::getInstance($aliquotId);
        if (!$aliquot) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "Aliquot with ID $aliquotId not found");
        }
        if ($aliquot->locationId != $locationId) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "Aliquot with ID $aliquotId not found in location with ID $locationId");
        }
        if ($aliquot->statusId != AliquotStatus::AVAILABLE) {
            throw new ShipmentException(ShipmentErrorCodes::INVALID_STATUS, "Aliquot with ID $aliquotId is in status " . $aliquot->statusId .
                    " that does not allow adding it to a shipment");
        }

        $arrVariables = [':shipmentId' => $shipmentId, ':aliquotId' => $aliquotId];
        $sql = "INSERT INTO SHIPPED_ALIQUOTS (ID_SHIPMENT, ID_ALIQUOT) VALUES (:shipmentId, :aliquotId)";
        Database::getInstance()->executeBindQuery($sql, $arrVariables);

        // Update also the current status of the aliquot
        $arrVariables = [':shipmentId' => $shipmentId, ':aliquotId' => $aliquotId, ':statusId' => AliquotStatus::IN_TRANSIT];
        $sql = "UPDATE ALIQUOTS SET ID_STATUS = :statusId, ID_SHIPMENT=:shipmentId WHERE ID_ALIQUOT = :aliquotId";
        Database::getInstance()->executeBindQuery($sql, $arrVariables);

        return $aliquotId;
    }

    /**
     * Adds multiple aliquots to a shipment from an uploaded Excel file.
     *
     * @param stdClass $params Unused parameter, data is taken from $_POST and $_FILES
     * @return stdClass
     */
    static public function shipment_add_aliquots_from_file($params) {
        if (!isset($_FILES["file"])) {
            throw new ShipmentException(ShipmentErrorCodes::DATA_MISSING, "Missing Excel file");
        }

        $params = json_decode($_POST["metadata"] ?? null);
        if (!$params) {
            throw new ShipmentException(ShipmentErrorCodes::INVALID_JSON, "Missing parameters");
        }

        $shipmentId = loadParam($params, 'shipmentId');
        $locationId = loadParam($params, 'locationId');

        $shipment = Shipment::exists($shipmentId);
        if (!$shipment) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "Shipment with ID $shipmentId not found");
        }
        if ($shipment->statusId != ShipmentStatus::PREPARING) {
            throw new ShipmentException(ShipmentErrorCodes::FORBIDDEN_OPERATION, "Shipment with ID is not available for adding aliquots");
        }

        $file = $_FILES["file"];

        // File information
        $filename = $file["name"];
        $tmpPath = $file["tmp_name"];
        $size = $file["size"];
        $error = $file["error"];

        if ($error !== UPLOAD_ERR_OK) {
            throw new ShipmentException(ShipmentErrorCodes::UNEXPECTED_ERROR, "Error uploading file (code $error)");
        }

        try {
            $excel = Excel::open($tmpPath);
        } catch (Exception $e) {
            throw new ShipmentException(ShipmentErrorCodes::INVALID_DATA_FORMAT, "Error opening Excel file: $filename: " . $e->getMessage());
        }

        $sheet = $excel->sheet(0);
        if (!$sheet) {
            throw new ServiceException("Sheet 'Datos' not found in file: $filename");
        }

        $aliquotIdColumnKey = null;
        $aliquotIds = [];
        foreach ($sheet->nextRow([], Excel::KEYS_FIRST_ROW) as $rowNum => $rowData) {
            if (!$aliquotIdColumnKey) {
                foreach (array_keys($rowData) as $key) {
                    if (trim(strtolower($key)) == 'id_aliquot') {
                        $aliquotIdColumnKey = $key;
                        break;
                    }
                }
                if (!$aliquotIdColumnKey) {
                    throw new ShipmentException(ShipmentErrorCodes::INVALID_DATA_FORMAT, "ALIQUOT_ID column not found in file $filename");
                }
            }
            $aliquotIds[] = $rowData[$aliquotIdColumnKey];
        }

        $aliquotIds = array_filter($aliquotIds);

        // Check for duplicate aliquot IDs in the uploaded file
        $countDuplicates = array_count_values($aliquotIds);
        $duplicates = array_keys(array_filter($countDuplicates, fn ($numOccurrences) => $numOccurrences > 1));

        $aliquotIds = array_unique($aliquotIds);

        $aliquots = Aliquot::batchLoad($aliquotIds, $locationId);
        $missing = [];
        $alreadyAdded = [];
        $validAliquotIds = [];
        foreach ($aliquotIds as $aliquotId) {
            $aliquot = $aliquots[$aliquotId] ?? null;
            if (!$aliquot) {
                /* Inform about the missing aliquots */
                $missing[] = $aliquotId;
            } elseif ($aliquot->statusId != AliquotStatus::AVAILABLE) {
                if ($aliquot->statusId == AliquotStatus::IN_TRANSIT && $aliquot->shipmentId == $shipmentId) {
                    /* Inform about the aliquots already added to the shipment */
                    $alreadyAdded[] = $aliquotId;
                } else {
                    /* Inform that the Aliquot is not available for including in the selected shipment */
                    $missing[] = $aliquotId;
                }
            } else {
                $validAliquotIds[] = $aliquotId;
                $arrVariables = [':shipmentId' => $shipmentId, ':aliquotId' => $aliquotId];
                $sql = "INSERT INTO SHIPPED_ALIQUOTS (ID_SHIPMENT, ID_ALIQUOT) VALUES (:shipmentId, :aliquotId)";
                Database::getInstance()->executeBindQuery($sql, $arrVariables);

                // Update also the current status of the aliquot
                $arrVariables = [':shipmentId' => $shipmentId, ':aliquotId' => $aliquotId, ':statusId' => AliquotStatus::IN_TRANSIT];
                $sql = "UPDATE ALIQUOTS SET ID_STATUS = :statusId, ID_SHIPMENT=:shipmentId WHERE ID_ALIQUOT = :aliquotId";
                Database::getInstance()->executeBindQuery($sql, $arrVariables);
            }
        }

        $data = new stdClass();
        $data->added = $validAliquotIds;
        $data->not_found = $missing;
        $data->ignored = $alreadyAdded;
        $data->duplicates = $duplicates;

        return $data;
    }

    /**
     * Marks an individual aliquot of a shipment as received.
     *
     * @param stdClass $params
     */
    static public function shipment_set_aliquot_condition($params) {
        $shipmentId = loadParam($params, 'shipmentId');
        $aliquotId = loadParam($params, 'aliquotId');
        $conditionId = loadParam($params, 'conditionId');
        $conditionId = $conditionId ? $conditionId : null;

        if (!Shipment::exists($shipmentId)) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "Shipment with ID $shipmentId not found");
        }

        $arrVariables = [':shipmentId' => $shipmentId, ':aliquotId' => $aliquotId, ':conditionId' => $conditionId];
        $sql = "UPDATE SHIPPED_ALIQUOTS SET ID_ALIQUOT_CONDITION=:conditionId WHERE ID_SHIPMENT=:shipmentId AND ID_ALIQUOT = :aliquotId";
        Database::getInstance()->executeBindQuery($sql, $arrVariables);

        return $aliquotId;
    }

    /**
     * Removes an aliquot from a shipment.
     *
     * @param stdClass $params
     */
    static public function shipment_remove_aliquot($params) {
        $shipmentId = loadParam($params, 'shipmentId');
        $aliquotId = loadParam($params, 'aliquotId');

        $shipment = Shipment::exists($shipmentId);
        if (!$shipment) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "Shipment with ID $shipmentId not found");
        }
        if ($shipment->statusId != ShipmentStatus::PREPARING) {
            throw new ShipmentException(ShipmentErrorCodes::FORBIDDEN_OPERATION, "Shipment with ID is not available for removing aliquots");
        }

        $arrVariables = [':shipmentId' => $shipmentId, ':aliquotId' => $aliquotId];
        $sql = "DELETE FROM SHIPPED_ALIQUOTS WHERE ID_SHIPMENT=:shipmentId AND ID_ALIQUOT=:aliquotId";
        Database::getInstance()->executeBindQuery($sql, $arrVariables);

        // Update also the current status of the aliquot
        $arrVariables = [':shipmentId' => null, ':aliquotId' => $aliquotId, ':statusId' => AliquotStatus::AVAILABLE];
        $sql = "UPDATE ALIQUOTS SET ID_STATUS = :statusId, ID_SHIPMENT=:shipmentId WHERE ID_ALIQUOT = :aliquotId";
        Database::getInstance()->executeBindQuery($sql, $arrVariables);

        return $aliquotId;
    }

    /**
     * Creates or updates a tracking of aliquots in the database.
     * <ul>
     * <li>If the aliquot does not exist in the ALIQUTOS table, it is created with the provided values.</li>
     * <li>A record is created in the ALIQUOTS_HISTORY table to maintain an audit log of the changes</li>
     * </ul>
     *
     * @param array $dbRows
     */
    static public function trackAliquots($dbRows, $action = AliquotAuditActions::CREATED) {
        $arrVariables = [];

        $dbColumnNames = ['ID_ALIQUOT', 'ID_PATIENT', 'PATIENT_REF', 'SAMPLE_TYPE', 'ID_LOCATION', 'ID_STATUS', 'ID_ALIQUOT_CONDITION', 'ID_TASK',
                'ALIQUOT_CREATED', 'ALIQUOT_UPDATED', 'ID_SHIPMENT', 'RECORD_TIMESTAMP'];

        $now = DateHelper::currentDate();
        foreach ($dbRows as $row) {
            $arrVariables[':action'] = $action;
            $row['RECORD_TIMESTAMP'] = $now; // Add the current timestamp to track the real time when the DB record was created/modified

            // Read the last known values of the aliquot to be updated
            $sqlPrev = "SELECT * FROM ALIQUOTS WHERE ID_ALIQUOT=:id";
            $rst = Database::getInstance()->ExecuteBindQuery($sqlPrev, $row['ID_ALIQUOT']);
            $prevValues = [];
            while ($rst->Next()) {
                foreach ($rst->getColumnNames() as $colName) {
                    $prevValues[$colName] = $rst->GetField($colName);
                }
            }

            $keyColumns = ['ID_ALIQUOT' => ':id_aliquot'];

            $updateColumns = [];
            foreach ($dbColumnNames as $colName) {
                $parameterName = ':' . strtolower($colName);
                if (array_key_exists($colName, $row)) {
                    // New value provided for the column
                    $arrVariables[$parameterName] = $row[$colName];
                } elseif (array_key_exists($colName, $prevValues)) {
                    // If the column is not present in the row, we must keep the previous value
                    $arrVariables[$parameterName] = $prevValues[$colName];
                } else {
                    $arrVariables[$parameterName] = null;
                }
                if (!array_key_exists($colName, $keyColumns)) {
                    $updateColumns[$colName] = $parameterName;
                }
            }

            $sql = Database::getInstance()->buildInsertOrUpdateQuery('ALIQUOTS', $keyColumns, $updateColumns);
            Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);

            /*
             * Add the tracking of the aliquots in the ALIQUOTS_HISTORY table
             */
            $sql = "INSERT INTO ALIQUOTS_HISTORY (ID_ALIQUOT, ID_TASK, ACTION, ID_LOCATION, ID_STATUS, ID_ALIQUOT_CONDITION, ALIQUOT_UPDATED, ID_SHIPMENT, RECORD_TIMESTAMP)
                        VALUES (:id_aliquot, :id_task, :action, :id_location, :id_status, :id_aliquot_condition, :aliquot_updated, :id_shipment, :record_timestamp)";
            Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);
        }
    }

    /**
     * Removes all the aliquots associated to a specific eCRF Task.
     *
     * @param string $taskId
     */
    static function removeAliquotsByTask($taskId) {
        $sql = "DELETE FROM ALIQUOTS WHERE ID_TASK = :taskId";
        Database::getInstance()->ExecuteBindQuery($sql, [':taskId' => $taskId]);
    }

    /**
     * Returns the list of shipments that have shipped aliquots that have not been tracked yet in the eCRF.
     * The conditions to consider that a Shipment is pending to be tracked are:
     * <ul>
     * <li>The shipment must be in "Shipped" or "Received" status</li>
     * <li>At least one of the aliquots in the shipment has not been tracked yet in the eCRF, what means that they do not have an associated eCRF
     * Task to track the shipment (which contains the information about the shipment)</li>
     * </ul>
     * The returned value is an array where each item is an associative array with the following structure:
     * <ul>
     * <li>shipment: Shipment</li>
     * <li>patients: array of ['patientId' => ..., 'patientRef' => ...]: The list of patients in the shipment with untracked aliquots</li>
     * </ul>
     *
     * @return array
     */
    static public function untrackedShipments() {
        // Find the shipped aliquots that have not been tracked yet in the eCRF
        $sql = "SELECT DISTINCT sa.ID_SHIPMENT, a.ID_PATIENT, a.PATIENT_REF
            FROM SHIPPED_ALIQUOTS sa, SHIPMENTS s, ALIQUOTS a
            WHERE s.ID_SHIPMENT=sa.ID_SHIPMENT AND s.ID_STATUS IN (:statusShipped, :statusReceived)
                AND (sa.ID_SHIPMENT_TASK IS NULL OR sa.ID_SHIPMENT_TASK=0) AND sa.ID_ALIQUOT = a.ID_ALIQUOT
            ORDER BY s.SHIPMENT_DATE, a.ID_PATIENT";
        $rst = Database::getInstance()->executeBindQuery($sql,
                [':statusShipped' => ShipmentStatus::SHIPPED, ':statusReceived' => ShipmentStatus::RECEIVED]);
        $error = Database::getInstance()->getError();
        if ($error->getErrCode()) {
            throw new ShipmentException($error->getErrCode(), $error->getErrorMessage());
        }

        $pendingShipmentIds = [];
        while ($rst->Next()) {
            $pendingShipmentIds[$rst->GetField('ID_SHIPMENT')][] = ['patientId' => $rst->GetField('ID_PATIENT'),
                    'patientRef' => $rst->GetField('PATIENT_REF')];
        }

        $untrackedShipments = [];
        foreach ($pendingShipmentIds as $shipmentId => $patientIdsInShipment) {
            if ($shipment = Shipment::exists($shipmentId)) {
                $untrackedShipments[] = ['shipment' => $shipment, 'patients' => $patientIdsInShipment];
            }
        }

        return $untrackedShipments;
    }

    /**
     * Returns the list of shipments that have aliquots received at the destination of a shipment that have not been tracked yet in the eCRF.
     * The conditions to consider that the reception of a Shipment is pending to be tracked are:
     * <ul>
     * <li>The shipment must be in "Received" status</li>
     * <li>At least one of the aliquots in the shipment has not been tracked yet in the eCRF, what means that they do not have an associated eCRF
     * Task to track the reception (which contains the information about the reception)</li>
     * </ul>
     * The returned value is an array where each item is an associative array with the following structure:
     * <ul>
     * <li>shipment: Shipment</li>
     * <li>patients: array of ['patientId' => ..., 'patientRef' => ..., 'trackingTaskId' => ...]: The list of patients in the shipment with untracked
     * aliquots</li>
     * </ul>
     *
     * @return array
     */
    static public function untrackedReceptions() {
        // Find the shipped aliquots that have not been tracked yet in the eCRF
        $sql = "SELECT DISTINCT sa.ID_SHIPMENT, a.ID_PATIENT, a.PATIENT_REF, sa.ID_SHIPMENT_TASK FROM SHIPPED_ALIQUOTS sa, SHIPMENTS s, ALIQUOTS a
            WHERE s.ID_SHIPMENT=sa.ID_SHIPMENT AND s.ID_STATUS=:statusReceived
                AND sa.ID_SHIPMENT_TASK > 0
                AND (sa.ID_RECEPTION_TASK IS NULL OR sa.ID_RECEPTION_TASK=0)
                AND sa.ID_ALIQUOT = a.ID_ALIQUOT
            ORDER BY s.SHIPMENT_DATE, a.ID_PATIENT";

        $rst = Database::getInstance()->executeBindQuery($sql, [':statusReceived' => ShipmentStatus::RECEIVED]);
        $error = Database::getInstance()->getError();
        if ($error->getErrCode()) {
            throw new ShipmentException($error->getErrCode(), $error->getErrorMessage());
        }

        $pendingShipmentIds = [];
        while ($rst->Next()) {
            $pendingShipmentIds[$rst->GetField('ID_SHIPMENT')][] = ['patientId' => $rst->GetField('ID_PATIENT'),
                    'patientRef' => $rst->GetField('PATIENT_REF'), 'trackingTaskId' => $rst->GetField('ID_SHIPMENT_TASK')];
        }

        $untrackedReceptions = [];
        foreach ($pendingShipmentIds as $shipmentId => $patientIdsInShipment) {
            if ($shipment = Shipment::exists($shipmentId)) {
                $untrackedReceptions[] = ['shipment' => $shipment, 'patients' => $patientIdsInShipment];
            }
        }

        return $untrackedReceptions;
    }

    /**
     * Returns the list of bulk changes where some aliquots have been modified manually and that have not been tracked yet in the eCRF.
     * The conditions to consider that the reception of a Shipment is pending to be tracked are:
     * <ul>
     * <li>The modified aliquots can't be in a previous shipment that whose reception has not been tracked in the eCRF yet. This ensures that the
     * tracking is always done in the same order that the actions performed in the Shipment Control application</li>
     * <li>At least one of the aliquots in the bulk change has not been tracked yet in the eCRF, what means that they do not have an associated eCRF
     * Task to track the modification</li>
     * </ul>
     * The returned value is an array where each item is an associative array with the following structure:
     * <ul>
     * <li>bulkChange: BulkChange</li>
     * <li>patients: array of ['patientId' => ..., 'patientRef' => ...]: The list of patients in the shipment with untracked aliquots</li>
     * aliquots</li>
     * </ul>
     *
     * @return array
     */
    static public function untrackedStatusChanges() {
        // Find the shipped aliquots that have not been tracked yet in the eCRF
        $sql = "SELECT DISTINCT ca.ID_BULK_CHANGE, a.ID_PATIENT, a.PATIENT_REF
                FROM CHANGED_ALIQUOTS ca, BULK_CHANGES bs, ALIQUOTS a
                WHERE bs.ID_BULK_CHANGE=ca.ID_BULK_CHANGE
                    AND (ca.ID_STATUS_TASK IS NULL OR ca.ID_STATUS_TASK=0) AND ca.ID_ALIQUOT = a.ID_ALIQUOT
                ORDER BY bs.CHANGE_DATE, a.ID_PATIENT";
        $rst = Database::getInstance()->executeBindQuery($sql);
        $error = Database::getInstance()->getError();
        if ($error->getErrCode()) {
            throw new ShipmentException($error->getErrCode(), $error->getErrorMessage());
        }

        $pendingBulkChangeIds = [];
        while ($rst->Next()) {
            $bulkChangeId = $rst->GetField('ID_BULK_CHANGE');
            $patientId = $rst->GetField('ID_PATIENT');
            $sql = 'SELECT bc.ID_ALIQUOT
                    FROM CHANGED_ALIQUOTS ca 
                    LEFT JOIN BULK_CHANGES bc ON bc.ID_BULK_CHANGE = ca.ID_BULK_CHANGE 
                    	LEFT JOIN SHIPPED_ALIQUOTS sa ON ca.ID_ALIQUOT = sa.ID_ALIQUOT 
                    	LEFT JOIN SHIPMENTS s ON sa.ID_SHIPMENT = s.ID_SHIPMENT 
                    	LEFT JOIN ALIQUOTS a ON a.ID_ALIQUOT =ca.ID_ALIQUOT 
                    WHERE
                    	-- Shipment occurred before the status changed
                    	s.SHIPMENT_DATE < bc.CHANGE_DATE 
                    	-- The shipment has not been tracked in the eCRF
                    	AND ((sa.ID_SHIPMENT_TASK IS NULL || sa.ID_SHIPMENT_TASK = 0) 
                    		OR (sa.ID_RECEPTION_TASK IS NULL || sa.ID_RECEPTION_TASK = 0)
                    	)
                    	AND a.ID_PATIENT=:patientId	
                    	AND ca.ID_BULK_CHANGE=:bulkChangeId';
            $rstCheck = Database::getInstance()->executeBindQuery($sql, [':patientId' => $patientId, ':bulkChangeId' => $bulkChangeId]);

            $conflictingAliquotIds = [];
            while ($rstCheck->Next()) {
                // There is at least one previous untracked shipment for this patient, so we skip this bulk change for now
                $conflictingAliquotIds[] = $rstCheck->GetField('ID_ALIQUOT');
            }

            $changeData = ['patientId' => $patientId, 'patientRef' => $rst->GetField('PATIENT_REF')];
            if (count($conflictingAliquotIds) > 0) {
                // Notify that this patient can't be tracked yet due to previous untracked shipments
                $str = implode(', ', $conflictingAliquotIds);
                $changeData['error'] = "There is at least one previous untracked shipment for this patient, so it's not possible to track the status change by now. Conflicting aliquot IDs: $str";
            }
            $pendingBulkChangeIds[$bulkChangeId][] = $changeData;
        }

        $untrackedChanges = [];
        foreach ($pendingBulkChangeIds as $bulkChangeId => $patientIdsInBulkChange) {
            if ($bulkChange = BulkChange::getInstance($bulkChangeId)) {
                $untrackedChanges[] = ['bulkChange' => $bulkChange, 'patients' => $patientIdsInBulkChange];
            }
        }

        return $untrackedChanges;
    }

    /**
     * Updates the aliquots that have been successfully tracked in the eCRF to indicate the associated tracking task.
     *
     * @param string $trackedAction 'SHIPMENT' or 'RECEPTION'
     * @param number $shipmentId
     * @param string $taskId
     * @param string[] $aliquotIds
     */
    static public function markTrackedAliquots($trackedAction, $shipmentId, $taskId, $aliquotIds) {
        $shipment = Shipment::exists($shipmentId);
        if (!$shipment) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "Shipment with ID $shipmentId not found while marking tracked receptions");
        }

        $aliquots = array_filter($shipment->getAliquots(),
                function ($aliquot) use ($aliquotIds) {
                    /** @var Aliquot $aliquot */
                    return in_array($aliquot->id, $aliquotIds);
                });

        if (count($aliquots) != count($aliquotIds)) {
            $foundIds = array_map(function ($aliquot) {
                return $aliquot->id;
            }, $aliquots);
            $missingIds = array_diff($aliquotIds, $foundIds);
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "Some aliquots of the shipment with ID $shipmentId were not found while marking tracked receptions: " .
                    implode(', ', $missingIds));
        }

        $taskColumn = null;
        $action = null;
        switch ($trackedAction) {
            case 'SHIPMENT' :
                $taskColumn = 'ID_SHIPMENT_TASK';
                $action = AliquotAuditActions::SHIPMENT_TRACKED;
                break;
            case 'RECEPTION' :
                $taskColumn = 'ID_RECEPTION_TASK';
                $action = AliquotAuditActions::RECEPTION_TRACKED;
                break;
            default :
                throw new ShipmentException(ShipmentErrorCodes::INVALID_DATA_FORMAT, "Invalid tracked action: $trackedAction");
        }
        $arrVariables = [':shipmentId' => $shipmentId, ':taskId' => $taskId];
        $inCondition = DbHelper::bindParamArray('aliquotId', $aliquotIds, $arrVariables);
        $sql = "UPDATE SHIPPED_ALIQUOTS SET $taskColumn = :taskId WHERE ID_SHIPMENT=:shipmentId AND ID_ALIQUOT IN ($inCondition)";
        Database::getInstance()->executeBindQuery($sql, $arrVariables);

        foreach ($aliquots as $aliquot) {
            $aliquot->taskId = $taskId;
            $aliquot->save($action);
        }
    }

    /**
     * Generates a report of all aliquots associated to a specific patient.
     * The return value is an array where each item is an object with the following structure:
     * <ul>
     * <li>sampleType: The type of sample (WHOLE_BLOOD, PLASMA, PBMC, SERUM, EXOSOMES)</li>
     * <li>list: Array of aliquots of this sample type, where each item has the following properties:</li>
     * <ul>
     * <li>id: Aliquot ID</li>
     * <li>locationId: Current location ID of the aliquot</li>
     * <li>locationName: Current location name of the aliquot</li>
     * <li>statusId: Current status ID of the aliquot</li>
     * <li>status: Human readable status name</li>
     * <li>conditionId: Current condition ID of the aliquot (if any)</li>
     * <li>condition: Human readable condition name</li>
     * <li>created: Creation date of the aliquot (in local timezone)</li>
     * </ul>
     * </ul>
     * Example:<br/>
     * [<br/>
     * ··{<br/>
     * ····"sampleType": "PLASMA",<br/>
     * ····"list": [<br/>
     * ······{<br/>
     * ········"id": "LV2005465409",<br/>
     * ········"locationId": "4",<br/>
     * ········"locationName": "UNIVERSIDAD DE OVIEDO",<br/>
     * ········"statusId": "IN_PLACE",<br/>
     * ········"status": "Available",<br/>
     * ········"conditionId": null,<br/>
     * ········"condition": null,<br/>
     * ········"created": "2024-07-26 00:00:00"<br/>
     * ······}<br/>
     * ····]<br/>
     * ··}<br/>
     * ]<br/>
     *
     * @param stdClass $params
     * @return stdClass[]
     */
    static public function aliquots_report_by_patient($params) {
        $patientId = loadParam($params, 'patientId');

        $sql = 'SELECT a.ID_ALIQUOT, a.ID_PATIENT, a.PATIENT_REF, a.SAMPLE_TYPE, 
                	a.ID_LOCATION, l.NAME AS LOCATION_NAME,
                	a.ID_STATUS, 
                	a.ID_ALIQUOT_CONDITION,
                	a.ALIQUOT_CREATED
                FROM ALIQUOTS a
                	LEFT JOIN LOCATIONS l ON a.ID_LOCATION = l.ID_LOCATION
                WHERE a.ID_PATIENT = :patientId
                ORDER BY a.PATIENT_REF, a.SAMPLE_TYPE, a.ID_ALIQUOT';

        $rst = Database::getInstance()->executeBindQuery($sql, [':patientId' => $patientId]);
        $report = [];
        $lastType = null;
        $sampleTypeData = new stdClass();
        while ($rst->Next()) {
            $sampleType = $rst->GetField('SAMPLE_TYPE');
            if ($lastType != $sampleType) {
                $sampleTypeData = new stdClass();
                $sampleTypeData->sampleType = $sampleType;
                $sampleTypeData->list = [];
                $lastType = $sampleType;
                $report[] = $sampleTypeData;
            }

            $aliquotData = new stdClass();
            $aliquotData->id = $rst->GetField('ID_ALIQUOT');
            $aliquotData->locationId = $rst->GetField('ID_LOCATION');
            $aliquotData->locationName = $rst->GetField('LOCATION_NAME');
            $aliquotData->statusId = $rst->GetField('ID_STATUS');
            $aliquotData->status = AliquotStatus::readableName($aliquotData->statusId);
            $aliquotData->conditionId = $rst->GetField('ID_ALIQUOT_CONDITION');
            $aliquotData->condition = AliquotConditions::readableName($aliquotData->conditionId);
            $aliquotData->created = DateHelper::UTCToLocal($rst->GetField('ALIQUOT_CREATED'), self::$timezone);
            $sampleTypeData->list[] = $aliquotData;
        }

        return $report;
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
    static private function fetchWithPagination($queryColumns, $queryFromClause, $arrVariables, $pageSize = null, $page = null) {
        if ($pageSize > 0) {
            $offset = ($page > 0) ? 1 + ($page - 1) * $pageSize : null;
        } else {
            $pageSize = null;
        }

        $sqlFecth = "SELECT $queryColumns " . $queryFromClause;
        $sqlCount = "SELECT COUNT(*) AS TOTAL_ROWS " . $queryFromClause;

        $rstCount = Database::getInstance()->executeBindQuery($sqlCount, $arrVariables);
        $rstCount->Next();

        $totalRows = $rstCount->GetField('TOTAL_ROWS');
        $rst = Database::getInstance()->executeBindQuery($sqlFecth, $arrVariables, $pageSize, $offset);

        return [$rst, $totalRows];
    }
}
