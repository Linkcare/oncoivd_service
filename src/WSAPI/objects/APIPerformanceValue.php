<?php

class APIPerformanceValue {
    private $value;
    private $validity;

    /**
     *
     * @param SimpleXMLElement $xmlNode
     * @return APIPerformanceValue
     */
    static public function parseXML($xmlNode) {
        if (!$xmlNode) {
            return null;
        }
        $pf = new APIPerformanceValue();
        $pf->value = NullableString($xmlNode->value);
        $pf->validity = NullableString($xmlNode->validity);
        return $pf;
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
    public function getValue() {
        return $this->value;
    }

    /**
     *
     * @return string
     */
    public function getValidity() {
        return $this->validity;
    }
}