<?php

class APIAdmissionPerformance {

    /** @var APIPerformanceValue */
    private $compliance;
    /** @var APIPerformanceValue */
    private $adherence;
    /** @var APIPerformanceValue */
    private $output;

    public function __construct() {
        $this->compliance = new APIPerformanceValue();
        $this->adherence = new APIPerformanceValue();
        $this->output = new APIPerformanceValue();
    }

    /**
     *
     * @param SimpleXMLElement $xmlNode
     * @return APIPerformanceValue
     */
    static public function parseXML($xmlNode) {
        if (!$xmlNode) {
            return null;
        }
        $performance = new APIAdmissionPerformance();
        if ($xmlNode->compliance) {
            $performance->compliance = APIPerformanceValue::parseXML($xmlNode->compliance);
        }
        if ($xmlNode->output) {
            $performance->output = APIPerformanceValue::parseXML($xmlNode->output);
        }
        if ($xmlNode->adherence) {
            $performance->adherence = APIPerformanceValue::parseXML($xmlNode->adherence);
        }
        return $performance;
    }

    /*
     * **********************************
     * GETTERS
     * **********************************
     */

    /**
     *
     * @return APIPerformanceValue
     */
    public function getCompliance() {
        return $this->compliance;
    }

    /**
     *
     * @return APIPerformanceValue
     */
    public function getAdherence() {
        return $this->adherence;
    }

    /**
     *
     * @return APIPerformanceValue
     */
    public function getOutput() {
        return $this->output;
    }
}