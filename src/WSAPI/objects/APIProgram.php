<?php

class APIProgram {
    private $id;
    private $code;
    private $version;
    private $name;
    private $trial;

    /**
     *
     * @param SimpleXMLElement $xmlNode
     * @return APIProgram
     */
    static public function parseXML($xmlNode) {
        if (!$xmlNode) {
            return null;
        }
        $program = new APIProgram();
        if ($xmlNode->ref) {
            $program->id = (string) $xmlNode->ref;
        } else {
            $program->id = (string) $xmlNode->id;
        }
        $program->code = (string) $xmlNode->code;
        $program->version = (string) $xmlNode->version;
        $program->name = (string) $xmlNode->name;
        $program->trial = booltotext((string) $xmlNode->ref);
        return $program;
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
    public function getVersion() {
        return $this->version;
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
     * @return boolean
     */
    public function isTrial() {
        return $this->trial;
    }
}