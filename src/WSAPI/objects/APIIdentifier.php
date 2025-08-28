<?php

class APIIdentifier {
    private $id;
    private $description;
    private $programId;
    private $teamId;
    private $value;

    public function __construct($id = null, $value = null) {
        $this->setId($id);
        $this->setValue($value);
    }

    /**
     *
     * @param SimpleXMLElement $xmlNode
     * @return APIIdentifier
     */
    static public function parseXML($xmlNode) {
        if (!$xmlNode) {
            return null;
        }
        $identifier = new APIIdentifier();
        $identifier->id = NullableString($xmlNode->label);
        $identifier->description = NullableString($xmlNode->description);
        $identifier->value = NullableString($xmlNode->value);
        if ($xmlNode->program) {
            $identifier->programId = NullableString($xmlNode->program->ref);
        }
        if ($xmlNode->team) {
            $identifier->teamId = NullableString($xmlNode->team->ref);
        }
        return $identifier;
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
    public function getDescription() {
        return $this->description;
    }

    /**
     *
     * @return string
     */
    public function getValue() {
        return $this->value;
    }

    /**
     *
     * @return string
     */
    public function getProgramId() {
        return $this->programId;
    }

    /**
     *
     * @return string
     */
    public function getTeamId() {
        return $this->teamId;
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
    public function setId($value) {
        $this->id = $value;
    }

    /**
     *
     * @param string $value
     */
    public function setValue($value) {
        $this->value = $value;
    }

    /**
     *
     * @param string $value
     */
    public function setProgramId($value) {
        $this->programId = $value;
    }

    /**
     *
     * @param string $value
     */
    public function setTeamId($value) {
        $this->teamId = $value;
    }

    /*
     * **********************************
     * METHODS
     * **********************************
     */

    /**
     *
     * @param XMLHelper $xml
     * @param SimpleXMLElement $parentNode
     * @return SimpleXMLElement
     */
    public function toXML($xml, $parentNode) {
        if ($parentNode === null) {
            $parentNode = $xml->rootNode;
        }

        $xml->createChildNode($parentNode, 'label', $this->getId());
        $xml->createChildNode($parentNode, 'value', $this->getValue());
        if ($this->getProgramId()) {
            $programNode = $xml->createChildNode($parentNode, 'program');
            $xml->createChildNode($programNode, 'ref', $this->getProgramId());
        }
        if ($this->getTeamId()) {
            $teamNode = $xml->createChildNode($parentNode, 'team');
            $xml->createChildNode($teamNode, 'ref', $this->getTeamId());
        }

        return $parentNode;
    }
}