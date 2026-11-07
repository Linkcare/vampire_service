<?php
require_once $_SERVER["DOCUMENT_ROOT"] . '/vendor/autoload.php';
use avadim\FastExcelReader\Excel;

class BulkChangeFunctions {

    /**
     * Loads the list of all BulkChanges that have been commited.
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
    static public function bulk_change_list($parameters) {
        $locationId = loadParam($parameters, 'location');
        $page = loadParam($parameters, 'page');
        $pageSize = loadParam($parameters, 'pageSize');

        $multipleLocations = explode(',', $locationId);
        $locationCondition = "";
        if (count($multipleLocations) > 1) {
            $arrVariables = [];
            $condition = DbHelper::bindParamArray('locationId', $multipleLocations, $arrVariables);
            $locationCondition = "s.ID_LOCATION IN ($condition)";
        } elseif (!isNullOrEmpty($locationId)) {
            $locationCondition = "s.ID_LOCATION=:locationId";
            $arrVariables = [':locationId' => $locationId];
        } else {
            $locationCondition = "1=1"; // No location filter
        }

        $queryColumns = "s.ID_BULK_CHANGE";
        $queryFromClause = "FROM BULK_CHANGES s WHERE $locationCondition";
        list($rst, $totalRows) = self::fetchWithPagination($queryColumns, $queryFromClause, $arrVariables, $pageSize, $page);

        $bulkChangeIds = [];
        while ($rst->Next()) {
            $bulkChangeIds[] = $rst->GetField('ID_BULK_CHANGE');
        }
        $bulkChangeList = BulkChange::batchLoad($bulkChangeIds);

        $data = new stdClass();
        $timezone = ShipmentFunctions::$timezone;
        $data->rows = array_map(
                function ($bulkChange) use ($timezone) {
                    /** @var BulkChange $bulkChange */
                    return $bulkChange->toJSON(false, $timezone);
                }, $bulkChangeList);
        $data->total_count = $totalRows;

        return $data;
    }

    /**
     * Creates a new BulkChange
     *
     * @param stdClass $parameters
     * @return object
     */
    static public function bulk_change_create($parameters) {
        $locationId = loadParam($parameters, 'locationId');
        $changedById = loadParam($parameters, 'changedById');
        $changedBy = loadParam($parameters, 'changedBy');
        if (!$locationId) {
            throw new ShipmentException(ShipmentErrorCodes::DATA_MISSING, "It is mandatory to provide the location to which the bulk change is applied");
        }

        $bulkChange = new BulkChange($locationId, $changedById, $changedBy);
        $bulkChange->save();

        return $bulkChange->toJSON();
    }

    /**
     * Updates the information of an existing BulkChange (that has not been commited yet)
     * Only the properties relevant for the BulkChange are updated
     *
     * @param array $parameters
     * @return object
     */
    static public function bulk_change_update($parameters) {
        $id = loadParam($parameters, 'id');
        $bulkChange = BulkChange::getInstance($id);
        if (!$bulkChange) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "BulkChange with ID " . $id . " not found");
        }

        if ($bulkChange->statusId == BulkChangeStatus::PREPARING) {
            preserveProperties($parameters, ['id', 'name', 'locationId', 'changedById', 'changeDate', 'comments']);
        } else {
            throw new ShipmentException(ShipmentErrorCodes::INVALID_STATUS, "BulkChange with ID $id cannot be updated because it is not in a status that allows updates");
        }
        // Copy the parameters received tracking the modified ones
        $bulkChange->trackedCopy($parameters, ShipmentFunctions::$timezone);
        $bulkChange->updateModified();

        return $bulkChange->id;
    }

    /**
     *
     * @param array $parameters
     */
    static public function bulk_change_delete($parameters) {
        $bulkChangeId = loadParam($parameters, 'id');

        $bulkChange = BulkChange::getInstance($bulkChangeId);
        if (!$bulkChange) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "Bulk change with ID: $bulkChangeId was not found");
        }

        if ($bulkChange->statusId != BulkChangeStatus::PREPARING) {
            throw new ShipmentException(ShipmentErrorCodes::INVALID_STATUS, "Bulk change with ID: $bulkChangeId can't be deleted because it is not in 'Preparing' status");
        }

        $bulkChange->delete();
        return $bulkChangeId;
    }

    /**
     * Loads the details of a specific BulkChange, including the list of affected aliquots.
     *
     * @param stdClass $parameters
     * @return object
     */
    static public function bulk_change_details($parameters) {
        $bulkChangeId = loadParam($parameters, 'id');

        $bulkChange = BulkChange::getInstance($bulkChangeId);
        if (!$bulkChange) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "BulkChange not found");
        }

        return $bulkChange->toJSON(ShipmentFunctions::$timezone);
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
    static public function bulk_change_commit($parameters) {
        $bulkChangeId = loadParam($parameters, 'id');

        $bulkChange = BulkChange::getInstance($bulkChangeId);
        if (!$bulkChange) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "BulkChange not found");
        }
        if ($bulkChange->getAliquotCount() == 0) {
            throw new ShipmentException(ShipmentErrorCodes::DATA_MISSING, "There no aliquots to change");
        }
        $bulkChange->statusId = BulkChangeStatus::COMMITED;
        $bulkChange->save(true); // Save changes and apply to actual aliquots

        return $bulkChange->id;
    }

    /**
     * Adds an aliquot to a BulkChange.
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
    static public function bulk_change_add_aliquot($params) {
        $bulkChangeId = loadParam($params, 'bulkChangeId');
        $aliquotId = loadParam($params, 'aliquotId');
        $statusId = loadParam($params, 'statusId');
        $conditionId = loadParam($params, 'conditionId');

        $bulkChange = BulkChange::getInstance($bulkChangeId);
        if (!$bulkChange) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "Bulk change with ID $bulkChangeId not found");
        }

        if ($bulkChange->statusId != BulkChangeStatus::PREPARING) {
            throw new ShipmentException(ShipmentErrorCodes::FORBIDDEN_OPERATION, "Bulk change with ID is not available for adding aliquots");
        }

        $locationId = $bulkChange->locationId;
        $aliquot = Aliquot::getInstance($aliquotId);
        if (!$aliquot) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "Aliquot with ID $aliquotId not found");
        }

        assertAvailabilityForBulkChange($aliquot, $locationId, $statusId, $conditionId);

        // Create an aliquot object with the new properties to be changed
        /** @var Aliquot $aliquot */
        $aliquotChange = new AliquotChange($aliquot);
        $aliquotChange->newValues->statusId = $statusId;
        $aliquotChange->newValues->conditionId = $conditionId;
        $bulkChange->addChange($aliquotChange);

        $aliquotChange->save();
    }

    /**
     * Adds multiple aliquots to a shipment from an uploaded Excel file.
     *
     * @param stdClass $params Unused parameter, data is taken from $_POST and $_FILES
     * @return stdClass
     */
    static public function bulk_change_add_from_file($params) {
        if (!isset($_FILES["file"])) {
            throw new ShipmentException(ShipmentErrorCodes::DATA_MISSING, "Missing Excel file");
        }

        $params = json_decode($_POST["metadata"] ?? '');
        if (!$params) {
            throw new ShipmentException(ShipmentErrorCodes::INVALID_JSON, "Missing parameters");
        }

        $bulkChangeId = loadParam($params, 'bulkChangeId');
        $statusId = loadParam($params, 'statusId');
        $conditionId = loadParam($params, 'conditionId');

        $bulkChange = BulkChange::getInstance($bulkChangeId);
        if (!$bulkChange) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "Bulk change with ID $bulkChangeId not found");
        }
        if ($bulkChange->statusId != BulkChangeStatus::PREPARING) {
            throw new ShipmentException(ShipmentErrorCodes::FORBIDDEN_OPERATION, "Bulk change with ID is not available for adding aliquots");
        }

        if ($statusId == AliquotStatus::REJECTED) {
            if (!$conditionId) {
                throw new ShipmentException(ShipmentErrorCodes::DATA_MISSING, "New status for aliquots is REJECTED but no aliquot condition was provided");
            }
        } else {
            $conditionId = null;
        }

        $warnings = [];
        $errors = [];

        $aliquotIds = ShipmentFunctions::loadAliquotsFromAttachment();

        // Check for duplicate aliquot IDs in the uploaded file
        $countDuplicates = array_count_values($aliquotIds);
        $duplicates = array_keys(array_filter($countDuplicates, fn ($numOccurrences) => $numOccurrences > 1));

        foreach ($duplicates as $duplicate) {
            $warnings[] = ['id' => $duplicate, 'msg' => 'Duplicates ignored'];
        }

        $aliquotIds = array_unique($aliquotIds);

        $aliquots = Aliquot::batchLoad($aliquotIds, $bulkChange->locationId);
        $missing = [];
        $alreadyAdded = [];
        $validAliquotIds = [];

        $aliquotsInBulkChange = array_map(function ($ch) {
            /** @var AliquotChange $ch */
            return $ch->aliquotId;
        }, $bulkChange->getChanged());

        foreach ($aliquotIds as $aliquotId) {
            $aliquot = $aliquots[$aliquotId] ?? null;
            if (!$aliquot) {
                /* Inform about the missing aliquots */
                $missing[] = $aliquotId;
                $errors[] = ['id' => $aliquotId, 'msg' => 'Not found in ' . $bulkChange->location];
            } elseif (in_array($aliquot->id, $aliquotsInBulkChange)) {
                /* Inform about the aliquots already added to the BulkChange */
                $alreadyAdded[] = $aliquotId;
                $warnings[] = ['id' => $aliquotId, 'msg' => 'Ignored because it was already added'];
            } else {
                try {
                    self::assertAvailabilityForBulkChange($aliquot, $bulkChange->locationId, $statusId, $conditionId);
                    $change = new AliquotChange($aliquot);
                    $change->newValues->statusId = $statusId;
                    $change->newValues->conditionId = $conditionId;
                    $bulkChange->addChange($change);
                } catch (ShipmentException $e) {
                    $errors[] = ['id' => $aliquotId, 'msg' => $e->getErrorMessage()];
                    $missing[] = $aliquotId;
                }
            }
        }

        $bulkChange->save();

        $data = new stdClass();
        $data->added = $validAliquotIds;
        $data->not_found = $missing;
        $data->ignored = $alreadyAdded;
        $data->duplicates = $duplicates;

        $data->errors = $errors;
        $data->warnings = $warnings;

        return $data;
    }

    /**
     * Removes an aliquot from a BulkChange.
     *
     * @param stdClass $params
     */
    static public function bulk_change_update_aliquot($params) {
        $bulkChangeId = loadParam($params, 'bulkChangeId');
        $aliquotId = loadParam($params, 'aliquotId');
        $statusId = loadParam($params, 'statusId');
        $conditionId = loadParam($params, 'conditionId');

        $bulkChange = BulkChange::getInstance($bulkChangeId);
        if (!$bulkChange) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "Bulk change with ID $bulkChangeId not found");
        }
        if ($bulkChange->statusId != BulkChangeStatus::PREPARING) {
            throw new ShipmentException(ShipmentErrorCodes::FORBIDDEN_OPERATION, "Bulk change with ID is not available for removing aliquots");
        }

        $affected = null;
        foreach ($bulkChange->getChanged() as $change) {
            if ($change->aliquotId == $aliquotId) {
                $affected = $change;
                break;
            }
        }

        if (!$affected) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "Aliquot with ID $aliquotId not found in bulk change with ID $bulkChangeId");
        }

        if ($statusId == AliquotStatus::REJECTED) {
            if (!$conditionId) {
                throw new ShipmentException(ShipmentErrorCodes::DATA_MISSING, "Aliquot $aliquotId was marked as REJECTED but no condition was provided");
            }
        } else {
            $conditionId = null;
        }

        $affected->newValues->statusId = $statusId;
        $affected->newValues->conditionId = $conditionId;
        $affected->save();
    }

    /**
     * Removes an aliquot from a BulkChange.
     *
     * @param stdClass $params
     */
    static public function bulk_change_remove_aliquot($params) {
        $bulkChangeId = loadParam($params, 'bulkChangeId');
        $aliquotId = loadParam($params, 'aliquotId');

        $bulkChange = BulkChange::getInstance($bulkChangeId);
        if (!$bulkChange) {
            throw new ShipmentException(ShipmentErrorCodes::NOT_FOUND, "Bulk change with ID $bulkChangeId not found");
        }
        if ($bulkChange->statusId != BulkChangeStatus::PREPARING) {
            throw new ShipmentException(ShipmentErrorCodes::FORBIDDEN_OPERATION, "Bulk change with ID is not available for removing aliquots");
        }

        foreach ($bulkChange->getChanged() as $change) {
            if ($change->aliquotId == $aliquotId) {
                $change->delete();
                break;
            }
        }
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

    /**
     *
     * @param Aliquot $aliquot
     * @param string $locationId
     * @param string $statusId
     * @param string $conditionId
     */
    static private function assertAvailabilityForBulkChange($aliquot, $locationId, $statusId, $conditionId) {
        $locationName = ShipmentFunctions::getLocationName($locationId);
        $aliquotId = $aliquot->id;

        if ($aliquot->locationId != $locationId) {
            throw new ShipmentException(ShipmentErrorCodes::INVALID_LOCATION, "Aliquot with ID $aliquotId does't belong to $locationName");
        }
        if ($aliquot->statusId != AliquotStatus::AVAILABLE) {
            throw new ShipmentException(ShipmentErrorCodes::INVALID_STATUS, "Aliquot with ID $aliquotId is in status " . $aliquot->statusId .
                    ", which doesn't allow a status change");
        }

        if ($statusId == AliquotStatus::REJECTED) {
            if (!$conditionId) {
                throw new ShipmentException(ShipmentErrorCodes::DATA_MISSING, "Aliquot $aliquotId was marked as REJECTED but no aliquot condition was provided");
            }
        } else {
            $conditionId = null;
        }
    }
}

