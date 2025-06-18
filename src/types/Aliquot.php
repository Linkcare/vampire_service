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
    public $status;

    /** @var string */
    public $rejectionReason;

    /** @var number */
    public $shipmentId;

    /** @var string */
    public $created;

    /** @var string */
    public $lastUpdate;

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
        $aliquot->sentFromId = $rst->GetField('ID_SENT_FROM');
        $aliquot->sentFrom = $rst->GetField('SENT_FROM');
        $aliquot->sentToId = $rst->GetField('ID_SENT_TO');
        $aliquot->sentTo = $rst->GetField('SENT_TO');
        $aliquot->statusId = $rst->GetField('ID_STATUS');
        $aliquot->status = AliquotStatus::getName($rst->GetField('ID_STATUS'));
        $aliquot->rejectionReason = AliquotStatus::getName($rst->GetField('REJECTION_REASON'));
        $aliquot->shipmentId = AliquotStatus::getName($rst->GetField('ID_SHIPMENT'));
        $aliquot->created = DateHelper::UTCToLocal($rst->GetField('CREATED'), $timezone);
        $aliquot->lastUpdate = DateHelper::UTCToLocal($rst->GetField('UPDATED'), $timezone);

        return $aliquot;
    }

    /**
     *
     * @return string
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
        $json->status = $this->status;
        $json->rejectionReason = $this->rejectionReason;
        $json->shipmentId = $this->shipmentId;
        $json->created = DateHelper::UTCToLocal($this->created, $timezone);
        $json->lastUpdate = DateHelper::UTCToLocal($this->lastUpdate, $timezone);

        return $json;
    }
}