<?php

class APITeam {
    private $id;
    private $code;
    private $name;
    private $type;
    private $timezone;
    /** @var LinkcareSoapAPI $api */
    private $api;
    private $modified = true;

    public function __construct() {
        $this->api = LinkcareSoapAPI::getInstance();
    }

    /**
     *
     * @param SimpleXMLElement $xmlNode
     * @param APITeam $team (optional) if provided, the data will be stored in this APITeam object
     * @return APITeam
     */
    static public function parseXML($xmlNode, $team = null) {
        if (!$xmlNode) {
            return null;
        }
        if (!$team) {
            $team = new APITeam();
        }

        $team->id = NullableString($xmlNode->ref);
        $team->code = NullableString($xmlNode->code);
        if (!$team->code) {
            $team->code = NullableString($xmlNode->team_code);
        }
        $team->timezone = NullableString($xmlNode->timezone);
        $team->name = NullableString($xmlNode->name);
        $team->type = NullableString($xmlNode->unit);
        return $team;
    }

    /*
     * **********************************
     * GETTERS
     * **********************************
     */

    /**
     *
     * @return int
     */
    public function getId() {
        return $this->id;
    }

    /**
     *
     * @return string
     */
    public function getCode() {
        return $this->code;
    }

    /**
     *
     * @return string
     */
    public function getName() {
        return $this->name;
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
    public function getTimezone() {
        return $this->timezone;
    }

    /*
     * **********************************
     * SETTERS
     * **********************************
     */
    /**
     *
     * @param string $value
     */
    public function setCode($value) {
        $this->code = $value;
    }

    /**
     *
     * @param string $value
     */
    public function setName($value) {
        $this->name = $value;
    }

    /**
     *
     * @param string $value
     */
    public function setType($value) {
        $this->type = $value;
    }

    /**
     *
     * @param string $value
     */
    public function setTimezone($value) {
        $this->timezone = $value;
    }

    /*
     * **********************************
     * METHODS
     * **********************************
     */

    /**
     *
     * @return APITeam
     */
    public function save() {
        if (!$this->id) {
            $this->id = $this->api->team_insert($this);
        } else {
            $this->api->team_set($this);
        }

        return $this;
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
        $xml->createChildNode($parentNode, "code", $this->getCode());
        $xml->createChildNode($parentNode, "team_code", $this->getCode());
        $xml->createChildNode($parentNode, "name", $this->getName());
        $xml->createChildNode($parentNode, "unit", $this->getType());
        if ($this->getTimezone()) {
            $xml->createChildNode($parentNode, "timezone", $this->getTimezone());
        }
    }
}