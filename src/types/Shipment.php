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
    private $modifiedProperties = [];

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
     * Updates a Shipment from a JSON tracking the modified properties.
     * If the JSON object does not have a property defined, it is ignored.
     *
     * @param stdClass $json
     * @param string $timezone
     * @return Shipment
     */
    public function trackedCopy($json, $timezone = null) {
        $this->modifiedProperties = []; // Reset the list of modified properties

        $this->trackedPropertyCopy($json, 'ref');
        $this->trackedPropertyCopy($json, 'statusId');
        $this->trackedPropertyCopy($json, 'sentFromId');
        $this->trackedPropertyCopy($json, 'sentToId');
        $this->trackedPropertyCopy($json, 'sendDate', $timezone);
        $this->trackedPropertyCopy($json, 'senderId');
        $this->trackedPropertyCopy($json, 'receptionDate', $timezone);
        $this->trackedPropertyCopy($json, 'receiverId');
        $this->trackedPropertyCopy($json, 'receiver');
        $this->trackedPropertyCopy($json, 'receptionStatusId');
        $this->trackedPropertyCopy($json, 'receptionComments');
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
        return array_key_exists($propertyName, $this->modifiedProperties);
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
            $sql = "SELECT a.*, sa.ID_ALIQUOT_CONDITION AS COND_RECEPTION  FROM SHIPPED_ALIQUOTS sa JOIN ALIQUOTS a ON a.ID_ALIQUOT = sa.ID_ALIQUOT WHERE sa.ID_SHIPMENT = :id";
            $rst = Database::getInstance()->executeBindQuery($sql, $this->id);

            $this->aliquots = [];
            while ($rst->Next()) {
                $aliquot = Aliquot::fromDBRecord($rst, $timezone);
                // Use the Rejection reason from the shipped aliquots table (not the current rejection reason assigned to the aliquot)
                $aliquot->conditionId = $rst->getField('COND_RECEPTION');
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

    /**
     *
     * @param Shipment $copyFrom
     */
    public function updateModified() {
        $arrVariables = [':shipmentId' => $this->id];
        $updateFields = [];

        if ($this->modified('ref')) {
            $arrVariables[':shipmentRef'] = $this->ref;
            $updateFields[] = "SHIPMENT_REF = :shipmentRef";
        }
        if ($this->modified('sentFromId')) {
            $arrVariables[':sentFromId'] = $this->sentFromId;
            $updateFields[] = "ID_SENT_FROM = :sentFromId";
        }
        if ($this->modified('sentToId')) {
            $arrVariables[':sentToId'] = $this->sentToId;
            $updateFields[] = "ID_SENT_TO = :sentToId";
        }
        if ($this->modified('senderId')) {
            $arrVariables[':senderId'] = $this->senderId;
            $updateFields[] = "ID_SENDER = :senderId";
        }
        if ($this->modified('sender')) {
            $arrVariables[':sender'] = $this->sender;
            $updateFields[] = "SENDER = :sender";
        }
        if ($this->modified('statusId')) {
            $arrVariables[':statusId'] = $this->statusId;
            $updateFields[] = "ID_STATUS = :statusId";
        }
        if ($this->modified('sendDate')) {
            $arrVariables[':sendDate'] = $this->sendDate;
            $updateFields[] = "SHIPMENT_DATE = :sendDate";
        }

        if ($this->modified('receiverId')) {
            $arrVariables[':receiverId'] = $this->receiverId;
            $updateFields[] = "ID_RECEIVER = :receiverId";
        }

        if ($this->modified('receiver')) {
            $arrVariables[':receiverName'] = $this->receiver;
            $updateFields[] = "RECEIVER = :receiverName";
        }

        if ($this->modified('receptionDate')) {
            $arrVariables[':receptionDate'] = $this->receptionDate;
            $updateFields[] = "RECEPTION_DATE = :receptionDate";
        }

        if ($this->modified('receptionStatusId')) {
            $arrVariables[':receptionStatusId'] = $this->receptionStatusId;
            $updateFields[] = "ID_RECEPTION_STATUS = :receptionStatusId";
        }

        if ($this->modified('receptionComments')) {
            $arrVariables[':receptionComments'] = $this->receptionComments;
            $updateFields[] = "RECEPTION_COMMENTS = :receptionComments";
        }

        if (empty($updateFields)) {
            throw new ServiceException(ErrorCodes::DATA_MISSING, "No data provided to update the shipment");
        }

        $updateFields = implode(', ', $updateFields);
        $sql = "UPDATE SHIPMENTS SET $updateFields WHERE ID_SHIPMENT = :shipmentId";
        Database::getInstance()->executeBindQuery($sql, $arrVariables);

        if ($this->modified('receptionStatusId')) {
            $conditionId = ($this->receptionStatusId == ReceptionStatus::ALL_BAD ? AliquotDamage::WHOLE_DAMAGE : null);
            $arrVariables = [':shipmentId' => $this->id, ':conditionId' => $conditionId];
            $sql = "UPDATE SHIPPED_ALIQUOTS SET ID_ALIQUOT_CONDITION = :conditionId WHERE ID_SHIPMENT = :shipmentId";
            Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);
        }
    }
}