<?php

class APIContactChannel {
    private $id;
    private $category;
    private $value;
    private $description;
    private $comment;
    private $preferred;
    private $verified;

    /**
     *
     * @param SimpleXMLElement $xmlNode
     * @return APIContactChannel
     */
    static public function parseXML($xmlNode) {
        if (!$xmlNode) {
            return null;
        }

        $channel = new APIContactChannel();
        $channel->id = (string) $xmlNode->ref;
        $channel->category = (string) $xmlNode->type;
        $channel->value = (string) $xmlNode->value;
        $channel->description = (string) $xmlNode->description;
        $channel->preferred = textToBool((string) $xmlNode->preferred);
        $channel->verified = textToBool((string) $xmlNode->verified);
        return $channel;
    }

    /*
     * address**********************
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
    public function getCategory() {
        return $this->category;
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
    public function getDescription() {
        return $this->description;
    }

    /**
     *
     * @return boolean
     */
    public function isPreferred() {
        return $this->preferred;
    }

    /**
     *
     * @return boolean
     */
    public function isVerified() {
        return $this->verified;
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
    public function setCategory($value) {
        return $this->category;
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
     * @param boolean $value
     */
    public function setPreferred($value) {
        return $this->preferred = textToBool($value);
    }

    /**
     *
     * @param boolean $value
     */
    public function setVerified() {
        $this->verified = textToBool($value);
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

        if ($this->getId() !== null) {
            $xml->createChildNode($parentNode, "ref", $this->getId());
        }
        if ($this->getCategory() !== null) {
            $xml->createChildNode($parentNode, "type", $this->getCategory());
        }
        $xml->createChildNode($parentNode, "value", $this->getValue());
        $xml->createChildNode($parentNode, "preferred", boolToText($this->isPreferred()));

        return $parentNode;
    }
}