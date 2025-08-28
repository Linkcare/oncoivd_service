<?php

class APIEvent {
    // Private members
    private $id;
    private $type;
    private $description;
    private $status;
    private $date;
    private $closeDate;
    private $readMark;
    private $priority;
    private $eventCode;
    /** @var APITaskAssignment */
    private $assignedTo;
    /** @var APIAdmission */
    private $admission;
    /** @var APICase */
    private $case;

    /** @var LinkcareSoapAPI $api */
    private $api;

    public function __construct() {
        $this->api = LinkcareSoapAPI::getInstance();
    }

    /**
     *
     * @param SimpleXMLElement $xmlNode
     * @return APIEvent
     */
    static public function parseXML($xmlNode) {
        if (!$xmlNode) {
            return null;
        }
        $event = new APIEvent();
        $event->id = NullableString($xmlNode->ref);
        if ($xmlNode->type) {
            $event->type = NullableString($xmlNode->type->id);
            $event->description = NullableString($xmlNode->type->desc);
        }
        $event->date = NullableString($xmlNode->date);
        $event->status = NullableString($xmlNode->status);
        $event->closeDate = NullableString($xmlNode->close_date);
        $event->readMark = textToBool($xmlNode->readMark);
        $event->priority = NullableInt($xmlNode->priority);
        if ($xmlNode->code) {
            $event->eventCode = NullableString($xmlNode->code);
        } elseif ($xmlNode->event_code) {
            $event->eventCode = NullableString($xmlNode->event_code);
        }
        if ($xmlNode->assigned_to) {
            $event->assignedTo = APITaskAssignment::parseXML($xmlNode->assigned_to);
        }

        if ($xmlNode->admission) {
            $event->admission = APIAdmission::parseXML($xmlNode->admission);
        }
        if ($xmlNode->case) {
            $event->case = APICase::parseXML($xmlNode->case);
        }

        return $event;
    }

    /*
     * **********************************
     * GETTERS
     * **********************************
     */
    /**
     *
     * @return string
     */
    public function getId() {
        return $this->id;
    }

    /**
     *
     * @return string
     */
    public function getEventCode() {
        return $this->eventCode;
    }

    /**
     *
     * @return string
     */
    public function getType() {
        return $this->type;
    }

    /**
     *
     * @return string
     */
    public function getDescription() {
        return $this->description;
    }

    /**
     *
     * @return string
     */
    public function getDate() {
        return $this->date;
    }

    /**
     *
     * @return string
     */
    public function getCloseDate() {
        return $this->closeDate;
    }

    /**
     *
     * @return string
     */
    public function getReadMark() {
        return $this->readMark;
    }

    /**
     *
     * @return string
     */
    public function getPriority() {
        return $this->priority;
    }

    /**
     *
     * @return APITaskAssignment
     */
    public function getAssignedTo() {
        return $this->assignedTo;
    }

    /**
     *
     * @return string
     */
    public function getStatus() {
        return $this->status;
    }

    /**
     *
     * @return string
     */
    public function getAdmission() {
        return $this->admission;
    }

    /**
     *
     * @return string
     */
    public function getCase() {
        return $this->case;
    }

    /*
     * **********************************
     * SETTERS
     * **********************************
     */
    /**
     *
     * @param string $date
     */
    public function setDate($date) {
        if ($date) {
            $date = explode(' ', $date)[0]; // Remove time part
        }
        $this->date = $date;
    }

    /**
     *
     * @param boolean $read
     */
    public function setReadMark($read) {
        $this->readMark = $read;
    }

    /**
     *
     * @param APITaskAssignment $assignTo
     */
    public function setAssignedTo($assignTo) {
        $this->assignedTo = $assignTo;
    }

    /*
     * **********************************
     * METHODS
     * **********************************
     */

    /**
     */
    public function save() {
        $this->api->event_set($this);
    }

    /**
     *
     * @param XMLHelper $xml
     * @return SimpleXMLElement $parentNode
     */
    public function toXML($xml, $parentNode = null) {
        if ($parentNode === null) {
            $parentNode = $xml->rootNode;
        }

        $xml->createChildNode($parentNode, "ref", $this->getId());
        if ($this->getDate() !== null) {
            $xml->createChildNode($parentNode, "date", $this->getDate());
        }
        if ($this->getType() !== null) {
            $typeNode = $xml->createChildNode($parentNode, "type");
            $xml->createChildNode($typeNode, "id", $this->getType());
        }
        if ($this->getDate() !== null) {
            $xml->createChildNode($parentNode, "date", $this->getDate());
        }
        if ($this->getStatus() !== null) {
            $xml->createChildNode($parentNode, "status", $this->getStatus());
        }
        if ($this->getPriority() !== null) {
            $xml->createChildNode($parentNode, "priority", $this->getPriority());
        }

        if ($this->assignedTo) {
            $assignNode = $xml->createChildNode($parentNode, "assigned_to");
            $this->assignedTo->toXML($xml, $assignNode);
        }
    }
}