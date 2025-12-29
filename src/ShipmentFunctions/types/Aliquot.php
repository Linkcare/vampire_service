<?php

class Aliquot {
    /** @var string */
    public $id;

    /** @var number */
    public $patientId;

    /** @var string */
    public $patientRef;

    /** @var string */
    public $type;

    /** @var number */
    public $locationId;

    /** @var string */
    public $location;

    /** @var number */
    public $sentFromId;

    /** @var string */
    public $sentFrom;

    /** @var number */
    public $sentToId;

    /** @var string */
    public $sentTo;

    /** @var string */
    public $statusId;

    /** @var string */
    public $conditionId;

    /** @var string */
    public $taskId;

    /** @var number */
    public $shipmentId;

    /**
     * Reference of the aliquot that was used to generate this one (for example when a Plasma aliquot is processed to extract Exosomes).
     *
     * @var string
     */
    public $parentAliquotId;

    /** @var string */
    public $created;

    /** @var string */
    public $lastUpdate;

    /**
     * Returns the aliquot information for the given aliquot ID
     *
     * @param string $id
     * @return Aliquot
     */
    static public function getInstance($id) {
        if (!$id) {
            return null;
        }

        $sql = "SELECT * FROM ALIQUOTS WHERE ID_ALIQUOT = :id_aliquot";
        $arrVariables = [':id_aliquot' => $id];
        $rst = Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);
        if ($rst->Next()) {
            return Aliquot::fromDBRecord($rst);
        } else {
            return null;
        }
    }

    /**
     *
     * @param DbManagerResults $rst
     * @param string $timezone
     * @return Aliquot
     */
    static public function fromDBRecord($rst, $timezone = null) {
        $aliquot = new Aliquot();
        $aliquot->id = $rst->GetField('ID_ALIQUOT');
        $aliquot->patientId = $rst->GetField('ID_PATIENT');
        $aliquot->patientRef = $rst->GetField('PATIENT_REF');
        $aliquot->type = $rst->GetField('SAMPLE_TYPE');
        $aliquot->locationId = $rst->GetField('ID_LOCATION');
        $aliquot->location = $rst->GetField('LOCATION_NAME');
        $aliquot->statusId = $rst->GetField('ID_STATUS');
        $aliquot->conditionId = $rst->GetField('ID_ALIQUOT_CONDITION');
        $aliquot->taskId = $rst->GetField('ID_TASK');
        $aliquot->shipmentId = $rst->GetField('ID_SHIPMENT');
        $aliquot->parentAliquotId = $rst->GetField('ID_PARENT_ALIQUOT');
        $aliquot->created = DateHelper::UTCToLocal($rst->GetField('ALIQUOT_CREATED'), $timezone);
        $aliquot->lastUpdate = DateHelper::UTCToLocal($rst->GetField('ALIQUOT_UPDATED'), $timezone);

        $aliquot->sentFromId = $rst->GetField('ID_SENT_FROM');
        $aliquot->sentFrom = $rst->GetField('SENT_FROM');
        $aliquot->sentToId = $rst->GetField('ID_SENT_TO');
        $aliquot->sentTo = $rst->GetField('SENT_TO');

        return $aliquot;
    }

    /**
     *
     * @param string $timezone Convert the dates to the provided timezone
     * @return stdClass
     */
    public function toJSON($timezone = null) {
        $json = new stdClass();
        $json->id = $this->id;
        $json->patientId = $this->patientId;
        $json->patientRef = $this->patientRef;
        $json->type = $this->type;
        $json->locationId = $this->locationId;
        $json->location = $this->location;
        $json->sentFromId = $this->sentFromId;
        $json->sentFrom = $this->sentFrom;
        $json->sentToId = $this->sentToId;
        $json->sentTo = $this->sentTo;
        $json->statusId = $this->statusId;
        $json->conditionId = $this->conditionId;
        $json->shipmentId = $this->shipmentId;
        $json->parentAliquotId = $this->parentAliquotId;
        $json->created = DateHelper::UTCToLocal($this->created, $timezone);
        $json->lastUpdate = DateHelper::UTCToLocal($this->lastUpdate, $timezone);

        return $json;
    }

    /**
     * Find the requested aliquots given a list of aliquot IDs
     * The return value is an associative array with the aliquot ID as key and the Aliquot object as value
     *
     * @param string $patientId
     * @param string[] $aliquotIds
     * @return Aliquot[]
     */
    static public function findAliquots($aliquotIds) {
        $aliquots = [];
        $arrVariables = [];
        $condition = DbHelper::bindParamArray('alId', $aliquotIds, $arrVariables);
        $sql = "SELECT * FROM ALIQUOTS WHERE ID_ALIQUOT IN ($condition)";
        $rst = Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);
        while ($rst->Next()) {
            $a = Aliquot::fromDBRecord($rst);
            $aliquots[$a->id] = $a;
        }

        return $aliquots;
    }

    /**
     * Save the aliquot information in the database
     *
     * @param string $logAction
     */
    public function save($logAction = null) {
        $arrVariables = [];

        $now = DateHelper::currentDate();

        $arrVariables[':id_aliquot'] = $this->id;
        $arrVariables[':id_patient'] = $this->patientId;
        $arrVariables[':patient_ref'] = $this->patientRef;
        $arrVariables[':sample_type'] = $this->type;
        $arrVariables[':id_location'] = $this->locationId;
        $arrVariables[':id_status'] = $this->statusId;
        $arrVariables[':id_aliquot_condition'] = $this->conditionId;
        $arrVariables[':id_task'] = $this->taskId;
        $arrVariables[':aliquot_created'] = $this->created;
        $arrVariables[':aliquot_updated'] = $this->lastUpdate;
        $arrVariables[':id_shipment'] = $this->shipmentId;
        $arrVariables[':id_parent_aliquot'] = $this->parentAliquotId;
        $arrVariables[':record_timestamp'] = $now;

        $arrVariables[':action'] = $logAction;

        $keyColumns = ['ID_ALIQUOT' => ':id_aliquot'];
        $updateColumns = ['ID_PATIENT' => ':id_patient', 'PATIENT_REF' => ':patient_ref', 'SAMPLE_TYPE' => ':sample_type',
                'ID_LOCATION' => ':id_location', 'ID_STATUS' => ':id_status', 'ID_ALIQUOT_CONDITION' => ':id_aliquot_condition',
                'ID_TASK' => ':id_task', 'ALIQUOT_CREATED' => ':aliquot_created', 'ALIQUOT_UPDATED' => ':aliquot_updated',
                'ID_SHIPMENT' => ':id_shipment', 'ID_PARENT_ALIQUOT' => ':id_parent_aliquot', 'RECORD_TIMESTAMP' => ':record_timestamp'];

        $sql = Database::getInstance()->buildInsertOrUpdateQuery('ALIQUOTS', $keyColumns, $updateColumns);
        Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);

        /*
         * Add the tracking of the aliquot changes in the ALIQUOTS_HISTORY table
         */
        if ($logAction) {
            $sql = "INSERT INTO ALIQUOTS_HISTORY (ID_ALIQUOT, ID_TASK, ACTION, ID_LOCATION, ID_STATUS, ID_ALIQUOT_CONDITION, ALIQUOT_UPDATED, 
                                                    ID_SHIPMENT, ID_PARENT_ALIQUOT, RECORD_TIMESTAMP)
                        VALUES (:id_aliquot, :id_task, :action, :id_location, :id_status, :id_aliquot_condition, :aliquot_updated, 
                                                    :id_shipment, :id_parent_aliquot, :record_timestamp)";
        }

        Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);
    }
}