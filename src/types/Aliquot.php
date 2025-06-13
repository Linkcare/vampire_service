<?php

class Aliquot {
    /** @var string */
    public $id;

    /** @var number */
    public $patientId;

    /** @var string */
    public $type;

    /** @var number */
    public $locationId;

    /** @var string */
    public $locationName;

    /** @var number */
    public $sentFromId;

    /** @var string */
    public $sentFromName;

    /** @var number */
    public $sentToId;

    /** @var string */
    public $sentToName;

    /** @var string */
    public $statusId;

    /** @var string */
    public $statusName;

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
        $aliquot->locationName = $rst->GetField('LOCATION_NAME');
        $aliquot->sentFromId = $rst->GetField('ID_SENT_FROM');
        $aliquot->sentFromName = $rst->GetField('SENT_FROM');
        $aliquot->sentToId = $rst->GetField('ID_SENT_TO');
        $aliquot->sentToName = $rst->GetField('SENT_TO');
        $aliquot->statusId = $rst->GetField('ID_STATUS');
        $aliquot->statusName = AliquotStatus::getName($rst->GetField('ID_STATUS'));
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
        $json->patientId = $this->patientRef;
        $json->type = $this->type;
        $json->locationId = $this->locationId;
        $json->locationName = $this->locationName;
        $json->sentFromId = $this->sentFromId;
        $json->sentFromName = $this->sentFromName;
        $json->sentToId = $this->sentToId;
        $json->sentToName = $this->sentToName;
        $json->statusId = $this->statusId;
        $json->statusName = $this->statusName;
        $json->shipmentId = $this->shipmentId;
        $json->created = DateHelper::UTCToLocal($this->created, $timezone);
        $json->lastUpdate = DateHelper::UTCToLocal($this->lastUpdate, $timezone);

        return $json;
    }
}