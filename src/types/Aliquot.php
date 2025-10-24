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
    public $conditionId;

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
        $aliquot->status = $rst->GetField('ID_STATUS');
        $aliquot->conditionId = $rst->GetField('ID_ALIQUOT_CONDITION');
        $aliquot->shipmentId = $rst->GetField('ID_SHIPMENT');
        $aliquot->created = DateHelper::UTCToLocal($rst->GetField('ALIQUOT_CREATED'), $timezone);
        $aliquot->lastUpdate = DateHelper::UTCToLocal($rst->GetField('ALIQUOT_UPDATED'), $timezone);

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
        $json->conditionId = $this->conditionId;
        $json->shipmentId = $this->shipmentId;
        $json->created = DateHelper::UTCToLocal($this->created, $timezone);
        $json->lastUpdate = DateHelper::UTCToLocal($this->lastUpdate, $timezone);

        return $json;
    }

    /**
     * Find the requested aliquots given a list of aliquot IDs
     *
     * @param string $patientId
     * @param string[] $aliquotIds
     * @return Aliquot[]
     */
    public function findAliquots($aliquotIds) {
        $aliquots = [];
        $arrVariables = [];
        $condition = DbHelper::bindParamArray('alId', $aliquotIds, $arrVariables);
        $sql = "SELECT * FROM ALIQUOTS WHERE ID_ALIQUOT IN ($condition)";
        $rst = Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);
        while ($rst->Next()) {
            $aliquots[] = Aliquot::fromDBRecord($rst);
        }

        return $aliquots;
    }
}