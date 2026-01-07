<?php

class TrackedChanges {
    /** @var string */
    public $statusId;

    /** @var string */
    public $conditionId;
}

/**
 * Class to track the changes in an aliquot
 */
class AliquotChange {
    /** @var string */
    public $bulkChangeId;

    /** @var string */
    public $aliquotId;

    /** @var number */
    public $patientId;

    /** @var string */
    public $patientRef;

    /** @var string */
    public $type;

    /**
     *
     * @var TrackedChanges
     */
    public $prevValues;

    /**
     *
     * @var TrackedChanges
     */
    public $newValues;

    /** @var string */
    public $taskId;

    /** @var Aliquot */
    private $originalAliquot = null;

    /**
     *
     * @param Aliquot $originalAliquot
     */
    public function __construct($originalAliquot = null) {
        $this->prevValues = new TrackedChanges();
        $this->newValues = new TrackedChanges();
        if ($originalAliquot) {
            $this->originalAliquot = $originalAliquot;
            $this->aliquotId = $originalAliquot->id;
            $this->prevValues->statusId = $originalAliquot->statusId;
            $this->prevValues->conditionId = $originalAliquot->conditionId;
        }
    }

    /**
     * Returns the aliquot information for the given aliquot ID included in the given bulk change
     *
     * @param string $bulkChangeId
     * @param string $aliquotId
     * @return AliquotChange
     */
    static public function getInstance($bulkChangeId, $aliquotId) {
        $sql = "SELECT ca.*, a.ID_PATIENT, a.PATIENT_REF, a.SAMPLE_TYPE FROM CHANGED_ALIQUOTS ca, ALIQUOTS a
                    WHERE ca.ID_BULK_CHANGE = :bulkChangeId
                    	and a.ID_ALIQUOT = :aliquotId";
        $rst = Database::getInstance()->executeBindQuery($sql, [':bulkChangeId' => $bulkChangeId, ':aliquotId' => $aliquotId]);

        if ($rst->Next()) {
            return self::fromDBRecord($rst);
        } else {
            return null;
        }
    }

    /**
     * Loads a batch of AliquotChange in an efficient way instead of loading them one by one
     *
     * @param string $bulkChangeId
     * @param int[] $arrIds
     * @param string $timezone
     * @return AliquotChange[] Array of AliquotChange objects indexed by their Aliquot ID
     */
    static public function batchLoad($bulkChangeId, $arrIds, $timezone = null) {
        /*
         * To avoid sending an extremely large query for all TaskTemplates, we will
         * group the TaskTemplateIds in groups of 100
         */
        $arrObjects = [];
        if (!$arrIds || !is_array($arrIds) || empty($arrIds)) {
            return $arrObjects; // return an empty array
        }

        $arrIds = array_unique($arrIds);
        $partialIds = [];
        $ix = 0;
        $totalIds = count($arrIds);
        foreach ($arrIds as $id) {
            $ix++;
            if ($id) {
                $partialIds[] = $id;
            }
            if (!empty($partialIds) && (count($partialIds) == 100 || ($ix >= $totalIds))) {
                $arrVariables = [':bulkChangeId' => $bulkChangeId];
                $bindString = DbHelper::bindParamArray("id", $partialIds, $arrVariables);
                $partialIds = [];
                $sql = "SELECT ca.*, a.ID_PATIENT, a.PATIENT_REF, a.SAMPLE_TYPE FROM CHANGED_ALIQUOTS ca, ALIQUOTS a
                        WHERE ca.ID_BULK_CHANGE = :bulkChangeId
                        	and a.ID_ALIQUOT IN ($bindString)";
                $rst = Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);

                while ($rst->Next()) {
                    $obj = self::fromDBRecord($rst, $timezone);
                    $arrObjects[$obj->aliquotId] = $obj;
                }
            }
        }

        return $arrObjects;
    }

    /**
     *
     * @param DbManagerResults $rst
     * @param string $timezone
     * @return AliquotChange
     */
    static public function fromDBRecord($rst, $timezone = null) {
        $change = new AliquotChange();

        $change->bulkChangeId = $rst->getField('ID_BULK_CHANGE');
        $change->aliquotId = $rst->getField('ID_ALIQUOT');
        $change->patientId = $rst->getField('ID_PATIENT');
        $change->patientRef = $rst->getField('PATIENT_REF');
        $change->type = $rst->getField('SAMPLE_TYPE');

        $change->prevValues->statusId = $rst->getField('ID_STATUS_PREV');
        $change->prevValues->conditionId = $rst->getField('ID_ALIQUOT_CONDITION_PREV');

        $change->newValues->statusId = $rst->getField('ID_STATUS');
        $change->newValues->conditionId = $rst->getField('ID_ALIQUOT_CONDITION');

        return $change;
    }

    /**
     *
     * @param string $timezone Convert the dates to the provided timezone
     * @return stdClass
     */
    public function toJSON($timezone = null) {
        $json = new stdClass();
        $json->bulkChangeId = $this->bulkChangeId;
        $json->aliquotId = $this->aliquotId;
        $json->patientId = $this->patientId;
        $json->patientRef = $this->patientRef;
        $json->type = $this->type;

        $json->prevValues = new stdClass();
        $json->prevValues->statusId = $this->prevValues->statusId;
        $json->prevValues->conditionId = $this->prevValues->conditionId;

        $json->newValues = new stdClass();
        $json->newValues->statusId = $this->newValues->statusId;
        $json->newValues->conditionId = $this->newValues->conditionId;

        return $json;
    }

    /**
     * Save the aliquot modification information in the database
     *
     * @param string $logAction
     */
    public function save($applyToActualAliquot = false, $changeDate = null) {
        $actualAliquot = $this->originalAliquot ?? Aliquot::getInstance($this->aliquotId);
        $this->assertCoherence($actualAliquot);

        $arrVariables = [':bulkChangeId' => $this->bulkChangeId, ':aliquotId' => $this->aliquotId];
        $arrVariables[':statusId'] = $this->newValues->statusId;
        $arrVariables[':conditionId'] = $this->newValues->conditionId;
        $arrVariables[':prevStatusId'] = $this->prevValues->statusId;
        $arrVariables[':prevConditionId'] = $this->prevValues->conditionId;
        $arrVariables[':taskId'] = $this->taskId;

        $sql = "INSERT INTO CHANGED_ALIQUOTS (ID_BULK_CHANGE, ID_ALIQUOT, ID_STATUS, ID_ALIQUOT_CONDITION, ID_STATUS_PREV, ID_ALIQUOT_CONDITION_PREV, ID_STATUS_TASK)
                VALUES (:bulkChangeId, :aliquotId, :statusId, :conditionId, :prevStatusId, :prevConditionId, :taskId)";
        Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);

        if ($applyToActualAliquot) {
            // Apply the change to the actual aliquot
            $actualAliquot->statusId = $this->newValues->statusId;
            $actualAliquot->conditionId = $this->newValues->conditionId;
            $actualAliquot->save(AliquotAuditActions::MODIFIED);

            /*
             * Special case: if the status has changed to EXOSOSMES, then it means that the original aliquot has been processed to create a new
             * aliquot of
             * type EXOSOMES.
             * We must create the new aliquot in the database indicating that it comes from the original aliquot
             */

            if ($this->newValues->statusId == AliquotStatus::TRANSFORMED) {
                $derivedAliquot = new Aliquot();
                $derivedAliquot->id = $actualAliquot->id . "_EXO";
                $derivedAliquot->patientId = $actualAliquot->patientId;
                $derivedAliquot->patientRef = $actualAliquot->patientRef;
                $derivedAliquot->type = 'EXOSOMES';
                $derivedAliquot->statusId = AliquotStatus::AVAILABLE;
                $derivedAliquot->locationId = $actualAliquot->locationId;
                $derivedAliquot->parentAliquotId = $actualAliquot->id;
                $derivedAliquot->lastUpdate = $changeDate;
                $derivedAliquot->created = $changeDate;
                $derivedAliquot->save(AliquotAuditActions::CREATED);
            }
        }
    }

    /**
     * This function throws an exception if values of the change are not coherent.
     * The verifications include:
     * <ul>
     * <li>At least one of statusId or conditionId must be changed</li>
     * <li>If the status has changed, verify that the transition is allowed
     * </ul>
     *
     * @param Aliquot $actualAliquot
     * @throws Exception
     */
    private function assertCoherence($actualAliquot) {
        if ($this->prevValues->statusId == $this->newValues->statusId && $this->prevValues->conditionId == $this->newValues->conditionId) {
            throw new ShipmentException(ShipmentErrorCodes::FORBIDDEN_OPERATION, "No changes detected for aliquot " . $this->aliquotId);
        }

        if ($this->prevValues->statusId != $this->newValues->statusId) {
            // Verify that the status transition is allowed
            if ($this->prevValues->statusId == AliquotStatus::IN_TRANSIT) {
                throw new ShipmentException(ShipmentErrorCodes::FORBIDDEN_OPERATION, "The aliquot " . $this->aliquotId .
                        " status can't be modified because it is included in a shipment that has not been received yet.");
            }
            if ($this->prevValues->statusId == AliquotStatus::TRANSFORMED) {
                /*
                 * This aliquot has already been processed to extract other blood samples (e.g. exosomes) , so no further status changes are allowed
                 */
                throw new ShipmentException(ShipmentErrorCodes::FORBIDDEN_OPERATION, "The aliquot " . $this->aliquotId .
                        " status can't be modified because it has been used to create other aliquots.");
            }
        }
    }
}