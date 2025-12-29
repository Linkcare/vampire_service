<?php

class BulkChange {
    /** @var number */
    public $id;

    /** @var string */
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

    /** @var AliquotChange[] */
    private $changes = null;

    static private function getInstance($id) {
        $sql = "SELECT * FROM BULK_CHANGES WHERE ID_BULK_CHANGE = :id";
        $arrVariables = [':id' => $id];
        $rst = Database::getInstance()->executeBindQuery($sql, $arrVariables);
        if ($rst->Next()) {
            return self::fromDBRecord($rst);
        }
        return null;
    }

    /**
     *
     * @param DbManagerResults $rst
     * @param string $timezone
     * @return Shipment
     */
    static public function fromDBRecord($rst) {
        $bulkChange = new Shipment();
        $bulkChange->id = $rst->GetField('ID_BULK_CHANGE');
        $bulkChange->changeDate = $rst->GetField('CHANGE_DATE');
        $bulkChange->changedById = $rst->GetField('ID_CHANGED_BY');
        $bulkChange->changedBy = $rst->GetField('CHANGED_BY');
        $bulkChange->comments = $rst->GetField('COMMENTS');
        $bulkChange->created = $rst->GetField('CREATED');
        $bulkChange->updated = $rst->GetField('UPDATED');

        return $bulkChange;
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

        if ($this->changes === null) {
            $this->changes = [];
        }

        $this->changes[$change->aliquotId] = $change;
    }

    public function toJSON($timezone = null) {
        $json = new stdClass();
        $json->id = $this->id;
        $json->changeDate = $this->changeDate;
        $json->changedById = $this->changedById;
        $json->changedBy = $this->changedBy;
        $json->coments = $this->comments;

        if (!empty($this->changes)) {
            $json->aliquots = [];
            foreach ($this->changes as $aliquot) {
                $json->aliquots[] = $aliquot->toJSON($timezone);
            }
        }

        return $json;
    }

    /**
     * Returns the list of aliquots of this bulk change.
     * If a patientId is provided, the list is filtered to return only the aliquots of that patient.
     *
     * @param number $patientId
     * @param string $timezone To convert the dates to the provided timezone
     * @return AliquotChange[]
     */
    public function getChanges($patientId = null, $timezone = null) {
        if ($this->changes === null) {
            $sql = "SELECT ca.ID_ALIQUOT FROM CHANGED_ALIQUOTS ca
                    WHERE ca.ID_BULK_CHANGE = :id";
            $rst = Database::getInstance()->executeBindQuery($sql, $this->id);

            $this->changes = [];
            while ($rst->Next()) {
                $aliquotIds[] = $rst->getField('ID_ALIQUOT');
            }
            $this->changes = AliquotChange::batchLoad($this->id, $aliquotIds, $timezone);
        }

        $changes = array_filter($this->changes,
                function ($change) use ($patientId) {
                    /** @var Aliquot $aliquot */
                    return $change->patientId == $patientId || $patientId === null;
                });

        return $changes;
    }

    /**
     * Saves the bulk change to the database.
     */
    public function save($applyToActualAliquots = false) {
        $arrVariables = [];
        if ($this->id === null) {
            $this->created = DateHelper::currentDate();
            $this->updated = $this->created;
            $arrVariables[':created'] = $this->created;
            $sql = "INSERT INTO BULK_CHANGES (CHANGE_DATE, ID_CHANGED_BY, CHANGED_BY, CHANGE_COMMENTS, CREATED, UPDATED) VALUES (:date, :changedById, :changedBy, :comments, :created, :updated)";
        } else {
            $this->updated = DateHelper::currentDate();
            $arrVariables = [':id' => $this->id];
            $sql = "UPDATE BULK_CHANGES SET CHANGE_DATE=:date, ID_CHANGED_BY=:changedById, CHANGED_BY=:changedBy, CHANGE_COMMENTS=:comments, UPDATED=:updated WHERE ID_BULK_CHANGE=:id";
        }

        $arrVariables[':date'] = $this->changeDate;
        $arrVariables[':changedById'] = $this->changedById;
        $arrVariables[':changedBy'] = $this->changedBy;
        $arrVariables[':comments'] = $this->comments;
        $arrVariables[':updated'] = $this->updated;
        Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);

        if (!$this->id) {
            Database::getInstance()->getLastInsertedId($this->id);
        }

        foreach ($this->changes as $changedAliquot) {
            $changedAliquot->bulkChangeId = $this->id;
            $changedAliquot->save($applyToActualAliquots, $this->changeDate);
        }
    }
}