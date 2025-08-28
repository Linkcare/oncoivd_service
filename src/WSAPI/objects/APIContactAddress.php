<?php

class APIContactAddress {
    private $kind;
    private $address;
    private $city;
    private $postcode;
    private $state;
    private $country;
    private $countryId;
    private $fullAddress;

    /**
     *
     * @param SimpleXMLElement $xmlNode
     * @return APIContactAddress
     */
    static public function parseXML($xmlNode) {
        if (!$xmlNode) {
            return null;
        }
        $address = new APIContactAddress();
        $address->kind = (string) $xmlNode->kind;
        $address->address = (string) $xmlNode->address;
        $address->city = (string) $xmlNode->city;
        $address->country = (string) $xmlNode->country;
        $address->countryId = (string) $xmlNode->country_ref;
        $address->fullAddress = (string) $xmlNode->full_address;
        return $address;
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
    public function getKind() {
        return $this->kind;
    }

    /**
     *
     * @return string
     */
    public function getAddress() {
        return $this->address;
    }

    /**
     *
     * @return string
     */
    public function getCity() {
        return $this->city;
    }

    /**
     *
     * @return string
     */
    public function getPostcode() {
        return $this->postcode;
    }

    /**
     *
     * @return string
     */
    public function getCountry() {
        return $this->country;
    }

    /**
     *
     * @return string
     */
    public function getCountryId() {
        return $this->countryId;
    }

    /**
     *
     * @return string
     */
    public function getFullAddress() {
        return $this->fullAddress;
    }

    /*
     * **********************************
     * SETTERS
     * **********************************
     */

    /**
     *
     * @return string
     */
    public function setKind($value) {
        $this->kind = $value;
    }

    /**
     *
     * @return string
     */
    public function setAddress($value) {
        $this->address = $value;
    }

    /**
     *
     * @return string
     */
    public function setCity($value) {
        $this->city = $value;
    }

    /**
     *
     * @return string
     */
    public function setPostcode($value) {
        $this->postcode = $value;
    }

    /**
     *
     * @return string
     */
    public function setCountry($value) {
        $this->country = $value;
    }

    /**
     *
     * @return string
     */
    public function setCountryId($value) {
        $this->countryId = $value;
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

        if ($this->getKind() !== null) {
            $xml->createChildNode($parentNode, "kind", $this->getKind());
        }
        if ($this->getAddress() !== null) {
            $xml->createChildNode($parentNode, "address", $this->getAddress());
        }
        if ($this->getCity() !== null) {
            $xml->createChildNode($parentNode, "city", $this->getAddress());
        }
        if ($this->getCountry() !== null) {
            $xml->createChildNode($parentNode, "country", $this->getCountry());
        }
        if ($this->getCountryId() !== null) {
            $xml->createChildNode($parentNode, "country_ref", $this->getCountryId());
        }

        return $parentNode;
    }
}