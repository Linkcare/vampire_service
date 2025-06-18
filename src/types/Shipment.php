<?php

class Shipment {
    /** @var number */
    public $id;

    /** @var string */
    public $ref;

    /** @var string */
    public $statusId;

    /** @var string */
    public $status;

    /** @var number */
    public $sentFromId;

    /** @var string */
    public $sentFrom;

    /** @var number|null */
    public $sentToId;

    /** @var string */
    public $sentTo;

    /** @var string|null */
    public $sendDate;

    /** @var number|null */
    public $senderId;

    /** @var string|null */
    public $sender;

    /** @var string|null */
    public $receptionDate;

    /** @var number|null */
    public $receiverId;

    /** @var string|null */
    public $receiver;

    /** @var number|null */
    public $receptionStatusId;

    /** @var number */
    public $receptionStatus;

    /** @var string */
    public $receptionComments;

    /** @var Aliquot[] */
    private $aliquots = null;

    /**
     *
     * @param DbManagerResults $rst
     * @param string $timezone
     * @return Shipment
     */
    static public function fromDBRecord($rst) {
        $shipment = new Shipment();
        $shipment->id = $rst->GetField('ID_SHIPMENT');
        $shipment->ref = $rst->GetField('SHIPMENT_REF');
        $shipment->statusId = $rst->GetField('ID_STATUS');
        $shipment->status = ShipmentStatus::getName($shipment->statusId);
        $shipment->sentFromId = $rst->GetField('ID_SENT_FROM');
        $shipment->sentFrom = $rst->GetField('SENT_FROM');
        $shipment->sentToId = $rst->GetField('ID_SENT_TO');
        $shipment->sentTo = $rst->GetField('SENT_TO');
        $shipment->sendDate = $rst->GetField('SHIPMENT_DATE');
        $shipment->senderId = $rst->GetField('ID_SENDER');
        $shipment->sender = $rst->GetField('SENDER');
        $shipment->receptionDate = $rst->GetField('RECEPTION_DATE');
        $shipment->receiverId = $rst->GetField('ID_RECEIVER');
        $shipment->receiver = $rst->GetField('RECEIVER');
        $shipment->receptionStatusId = $rst->GetField('ID_RECEPTION_STATUS');
        $shipment->receptionStatus = ReceptionStatus::getName($shipment->receptionStatusId);
        $shipment->receptionComments = $rst->GetField('RECEPTION_COMMENTS');

        return $shipment;
    }

    public function toJSON($timezone = null) {
        $json = new stdClass();
        $json->id = $this->id;
        $json->ref = $this->ref;
        $json->statusId = $this->statusId;
        $json->status = ShipmentStatus::getName($this->statusId);
        $json->sentFromId = $this->sentFromId;
        $json->sentFrom = $this->sentFrom;
        $json->sentToId = $this->sentToId;
        $json->sentTo = $this->sentTo;
        $json->sendDate = DateHelper::UTCToLocal($this->sendDate, $timezone);
        $json->senderId = $this->senderId;
        $json->sender = $this->sender;
        $json->receptionDate = DateHelper::UTCToLocal($this->receptionDate, $timezone);
        $json->receiverId = $this->receiverId;
        $json->receiver = $this->receiver;
        $json->receptionStatusId = $this->receptionStatusId;
        $json->receptionStatus = ReceptionStatus::getName($this->receptionStatusId);
        $json->receptionComments = $this->receptionComments;

        if (!empty($this->aliquots)) {
            $json->aliquots = [];
            foreach ($this->aliquots as $aliquot) {
                $json->aliquots[] = $aliquot->toJSON($timezone);
            }
        }

        return $json;
    }

    /**
     * Checks if a shipment exists in the database.
     *
     * @param number $id
     * @return Shipment|null
     */
    static public function exists($id) {
        $arrVariables = [':shipmentId' => $id];

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
        if ($rst->Next()) {
            return self::fromDBRecord($rst);
        } else {
            return null;
        }
    }

    /**
     * Returns the list of aliquots of this shipment.
     * If a patientId is provided, the list is filtered to return only the aliquots of that patient.
     *
     * @param number $patientId
     * @return Aliquot[]
     */
    public function getAliquots($patientId = null, $timezone = null) {
        if ($this->aliquots === null) {
            $sql = "SELECT a.*, sa.REJECTION_REASON AS REJ FROM SHIPPED_ALIQUOTS sa JOIN ALIQUOTS a ON a.ID_ALIQUOT = sa.ID_ALIQUOT WHERE sa.ID_SHIPMENT = :id";
            $rst = Database::getInstance()->executeBindQuery($sql, $this->id);
            $error = Database::getInstance()->getError();
            if ($error->getErrCode()) {
                throw new ServiceException($error->getErrCode(), $error->getErrorMessage());
            }

            $this->aliquots = [];
            while ($rst->Next()) {
                $aliquot = Aliquot::fromDBRecord($rst, $timezone);
                // Use the Rejection reason from the shipped aliquots table (not the current rejection reason assigned to the aliquot)
                $aliquot->rejectionReason = $rst->getField('REJ');
                $this->aliquots[] = $aliquot;
            }
        }

        $aliquots = array_filter($this->aliquots,
                function ($aliquot) use ($patientId) {
                    /** @var Aliquot $aliquot */
                    return $aliquot->patientId == $patientId || $patientId === null;
                });

        return $aliquots;
    }
}