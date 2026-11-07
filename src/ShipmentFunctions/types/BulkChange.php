<?php

class BulkChange {
    /** @var number */
    public $id;

    /** @var string */
    public $locationId;

    /** @var string */
    public $location;

    /** @var string */
    public $statusId;

    /** @var string Only date part is stored */
    public $changeDate;

    /** @var string */
    public $changedById;

    /** @var string */
    public $changedBy;

    /** @var string */
    public $comments;

    /** @var string */
    public $created;

    /** @var string */
    public $updated;

    /** @var int Number of aliquots stored in DB */
    private $aliquotCount;

    /** @var AliquotChange[] */
    private $changes = null;

    public function __construct($locationId = null, $userId = null, $userName = null) {
        $this->locationId = $locationId;
        $this->changedById = $userId;
        $this->changedBy = $userName;
        $this->statusId = BulkChangeStatus::PREPARING;
        $this->changeDate = DateHelper::datePart(DateHelper::currentDate());
    }

    /**
     * Loads a BulkChange from the database.
     *
     * @param number $id
     * @return BulkChange|null
     */
    static public function getInstance($id) {
        $sql = "SELECT s.*, l.NAME AS LOCATION, COUNT(ca.ID_ALIQUOT) AS ALIQUOT_COUNT
            FROM BULK_CHANGES s
                LEFT JOIN LOCATIONS l ON s.ID_LOCATION = l.ID_LOCATION
                LEFT JOIN CHANGED_ALIQUOTS ca ON s.ID_BULK_CHANGE = ca.ID_BULK_CHANGE
            WHERE s.ID_BULK_CHANGE = :id
            GROUP BY s.ID_BULK_CHANGE, l.NAME";
        // $sql = "SELECT b.*, l.NAME AS LOCATION FROM BULK_CHANGES b
        // LEFT JOIN LOCATIONS l ON b.ID_LOCATION = l.ID_LOCATION
        // WHERE b.ID_BULK_CHANGE = :id";
        $arrVariables = [':id' => $id];
        $rst = Database::getInstance()->executeBindQuery($sql, $arrVariables);
        if ($rst->Next()) {
            return self::fromDBRecord($rst);
        }
        return null;
    }

    /**
     * Loads a batch of BulkChanges in an efficient way instead of loading them one by one
     *
     * @param int[] $arrIds
     * @param string $timezone
     * @return BulkChange[] array of BulkChange objects
     */
    static public function batchLoad($arrIds, $timezone = null) {
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
                $arrVariables = [];
                $bindString = DbHelper::bindParamArray("id", $partialIds, $arrVariables);
                $partialIds = [];
                $sql = "SELECT b.*, l.NAME AS LOCATION, COUNT(ca.ID_ALIQUOT) AS ALIQUOT_COUNT 
                        FROM BULK_CHANGES b
                            LEFT JOIN LOCATIONS l ON b.ID_LOCATION = l.ID_LOCATION 
                            LEFT JOIN CHANGED_ALIQUOTS ca ON b.ID_BULK_CHANGE = ca.ID_BULK_CHANGE
                        WHERE b.ID_BULK_CHANGE IN ($bindString)
                        GROUP BY b.ID_BULK_CHANGE, l.NAME";
                $rst = Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);

                while ($rst->Next()) {
                    $obj = self::fromDBRecord($rst, $timezone);
                    $arrObjects[] = $obj;
                }
            }
        }

        return $arrObjects;
    }

    /**
     *
     * @param DbManagerResults $rst
     * @return Shipment
     */
    static public function fromDBRecord($rst) {
        $bulkChange = new BulkChange();
        $bulkChange->id = $rst->GetField('ID_BULK_CHANGE');
        $bulkChange->locationId = $rst->GetField('ID_LOCATION');
        $bulkChange->location = $rst->GetField('LOCATION');
        $bulkChange->statusId = $rst->GetField('ID_STATUS');
        $bulkChange->changeDate = DateHelper::datePart($rst->GetField('CHANGE_DATE'));
        $bulkChange->changedById = $rst->GetField('ID_CHANGED_BY');
        $bulkChange->changedBy = $rst->GetField('CHANGED_BY');
        $bulkChange->comments = $rst->GetField('CHANGE_COMMENTS');
        $bulkChange->created = $rst->GetField('CREATED');
        $bulkChange->updated = $rst->GetField('UPDATED');
        $bulkChange->aliquotCount = $rst->GetField('ALIQUOT_COUNT');

        return $bulkChange;
    }

    /**
     * Returns the number of aliquots affected by this bulk change.
     *
     * @return number
     */
    public function getAliquotCount() {
        if ($this->changes !== null) {
            // If the changes array is already loaded, return its count instead of the stored aliquotCount retrieved from the database
            return count($this->changes);
        }

        return $this->aliquotCount;
    }

    /**
     * Adds an aliquot to the bulk change.
     *
     * @param AliquotChange $change
     * @param Aliquot $originalAliquot
     */
    public function addChange(AliquotChange $change) {
        if (!$change) {
            return;
        }

        $this->getChanged(); // Ensure the changes array is initialized
        $change->bulkChangeId = $this->id;
        $this->changes[$change->aliquotId] = $change;
    }

    /**
     * Returns a JSON representation of the bulk change.
     *
     * @param boolean $includeAliquots
     * @param string $timezone
     * @return stdClass
     */
    public function toJSON($includeAliquots = true, $timezone = null) {
        $json = new stdClass();
        $json->id = $this->id;
        $json->locationId = $this->locationId;
        $json->location = $this->location;
        $json->statusId = $this->statusId;
        $json->changeDate = $this->changeDate;
        $json->changedById = $this->changedById;
        $json->changedBy = $this->changedBy;
        $json->comments = $this->comments;
        $json->numAliquots = $this->getAliquotCount();

        if ($includeAliquots) {
            $affectedAliquots = $this->getChanged();
            if (!empty($affectedAliquots)) {
                $json->aliquots = [];
                foreach ($affectedAliquots as $aliquot) {
                    $json->aliquots[] = $aliquot->toJSON();
                }
            }
        }
        return $json;
    }

    /**
     * Returns the list of aliquots of this bulk change.
     *
     * @return AliquotChange[]
     */
    public function getChanged() {
        if ($this->changes === null) {
            $sql = "SELECT ca.ID_ALIQUOT FROM CHANGED_ALIQUOTS ca
                    WHERE ca.ID_BULK_CHANGE = :id";
            $rst = Database::getInstance()->executeBindQuery($sql, $this->id);

            $this->changes = [];
            while ($rst->Next()) {
                $aliquotIds[] = $rst->getField('ID_ALIQUOT');
            }
            $this->changes = AliquotChange::batchLoad($this->id, $aliquotIds);
        }

        return $this->changes;
    }

    /**
     * Saves the bulk change to the database.
     *
     * @param $applyToActualAliquots (default=false) If true, the changes registered are applied to the affected aliquots
     */
    public function save($applyToActualAliquots = false) {
        $arrVariables = [];
        if ($this->id === null) {
            $this->created = DateHelper::currentDate();
            $this->updated = $this->created;
            $arrVariables[':created'] = $this->created;
            $sql = "INSERT INTO BULK_CHANGES (ID_LOCATION, ID_STATUS, CHANGE_DATE, ID_CHANGED_BY, CHANGED_BY, CHANGE_COMMENTS, CREATED, UPDATED) 
                        VALUES (:locationId, :statusId, :date, :changedById, :changedBy, :comments, :created, :updated)";
        } else {
            $this->updated = DateHelper::currentDate();
            $arrVariables = [':id' => $this->id];
            $sql = "UPDATE BULK_CHANGES SET ID_LOCATION=:locationId, ID_STATUS=:statusId, CHANGE_DATE=:date, ID_CHANGED_BY=:changedById, CHANGED_BY=:changedBy, CHANGE_COMMENTS=:comments, UPDATED=:updated WHERE ID_BULK_CHANGE=:id";
        }

        $arrVariables[':date'] = DateHelper::datePart($this->changeDate);
        $arrVariables[':locationId'] = $this->locationId;
        $arrVariables[':statusId'] = $this->statusId;
        $arrVariables[':changedById'] = $this->changedById;
        $arrVariables[':changedBy'] = $this->changedBy;
        $arrVariables[':comments'] = $this->comments;
        $arrVariables[':updated'] = $this->updated;
        Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);

        if (!$this->id) {
            Database::getInstance()->getLastInsertedId($this->id);
        }

        foreach ($this->getChanged() as $changedAliquot) {
            $changedAliquot->bulkChangeId = $this->id;
            $changedAliquot->save($applyToActualAliquots, $this->changeDate);
        }
    }

    public function delete() {
        if (!$this->id) {
            throw new Exception("Cannot delete a bulk change without an ID.");
        }

        // Delete the changed aliquots first
        $sql = "DELETE FROM CHANGED_ALIQUOTS WHERE ID_BULK_CHANGE = :id";
        Database::getInstance()->executeBindQuery($sql, [':id' => $this->id]);

        // Then delete the bulk change itself
        $sql = "DELETE FROM BULK_CHANGES WHERE ID_BULK_CHANGE = :id";
        Database::getInstance()->executeBindQuery($sql, [':id' => $this->id]);
    }

    /**
     * Updates a BulkChange from a JSON tracking the modified properties.
     * If the JSON object does not have a property defined, it is ignored.
     *
     * @param stdClass $json
     * @param string $timezone
     */
    public function trackedCopy($json, $timezone = null) {
        $this->modifiedProperties = []; // Reset the list of modified properties

        $this->trackedPropertyCopy($json, 'locationId');
        $this->trackedPropertyCopy($json, 'statusId');
        $this->trackedPropertyCopy($json, 'sentToId');
        $this->trackedPropertyCopy($json, 'changeDate');
        $this->trackedPropertyCopy($json, 'changedById');
        $this->trackedPropertyCopy($json, 'changedBy');
        $this->trackedPropertyCopy($json, 'comments');
    }

    private function trackedPropertyCopy($json, $propertyName, $timezone = null) {
        if (!is_object($json)) {
            return;
        }
        if (property_exists($json, $propertyName) && $this->$propertyName != $json->$propertyName) {
            $this->modifiedProperties[$propertyName] = $this->$propertyName; // Store the previous value
            if ($timezone) {
                // Is a date that must be converted to UTC
                $this->$propertyName = DateHelper::localToUTC($json->$propertyName, $timezone);
            } else {
                $this->$propertyName = $json->$propertyName;
            }
        }
    }

    /**
     * Returns true if a property of the shipment has a property was modified.
     *
     * @param string $propertyName
     * @return boolean
     */
    public function modified($propertyName) {
        return array_key_exists($propertyName, $this->modifiedProperties ?? []);
    }

    /**
     *
     * @param Shipment $copyFrom
     */
    public function updateModified() {
        $arrVariables = [':id' => $this->id];
        $updateFields = [];

        if ($this->modified('locationId')) {
            $arrVariables[':locationId'] = $this->locationId;
            $updateFields[] = "ID_LOCATION = :locationId";
        }
        if ($this->modified('changedById')) {
            $arrVariables[':changedById'] = $this->changedById;
            $updateFields[] = "ID_CHANGED_BY = :changedById";
        }
        if ($this->modified('changedBy')) {
            $arrVariables[':changedBy'] = $this->changedBy;
            $updateFields[] = "CHANGED_BY = :changedBy";
        }
        if ($this->modified('statusId')) {
            $arrVariables[':statusId'] = $this->statusId;
            $updateFields[] = "ID_STATUS = :statusId";
        }
        if ($this->modified('changeDate')) {
            $arrVariables[':changeDate'] = $this->changeDate;
            $updateFields[] = "CHANGE_DATE = :changeDate";
        }

        if ($this->modified('comments')) {
            $arrVariables[':comments'] = $this->comments;
            $updateFields[] = "CHANGE_COMMENTS = :comments";
        }

        if (empty($updateFields)) {
            return; // Nothing to update
        }

        $updateFields = implode(', ', $updateFields);
        $sql = "UPDATE BULK_CHANGES SET $updateFields WHERE ID_BULK_CHANGE = :id";
        Database::getInstance()->executeBindQuery($sql, $arrVariables);
    }
}