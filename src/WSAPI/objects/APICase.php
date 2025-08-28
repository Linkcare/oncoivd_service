<?php

class APICase {
    private $id;
    private $userName;
    private $fullName;
    private $name;
    private $surname;
    private $nickname;
    private $bdate;
    private $gender;
    private $timezone;
    /* @var APIIdentifier[] $identifiers */
    private $identifiers = [];
    /** @var LinkcareSoapAPI $api */
    private $api;

    /** @var APICasePreferences */
    private $preferences;

    public function __construct() {
        $this->api = LinkcareSoapAPI::getInstance();
        $this->preferences = new APICasePreferences();
    }

    /**
     *
     * @param SimpleXMLElement $xmlNode
     * @return APICase
     */
    static public function parseXML($xmlNode) {
        if (!$xmlNode) {
            return null;
        }
        $case = new APICase();
        $case->id = trim($xmlNode->ref);
        $case->userName = trim($xmlNode->username);
        if ($xmlNode->data) {
            $case->fullName = trim($xmlNode->data->full_name);
            $case->name = trim($xmlNode->data->name);
            $case->surname = trim($xmlNode->data->surname);
            $case->nickname = trim($xmlNode->data->nickname);
            $case->bdate = trim($xmlNode->data->bdate);
            $case->gender = trim($xmlNode->data->gender);
            $case->timezone = trim($xmlNode->data->timezone);
            if (isset($xmlNode->data->preferences)) {
                $case->preferences = APICasePreferences::parseXML($xmlNode->data->preferences);
            }
        }
        $identifiers = [];
        if ($xmlNode->identifiers) {
            foreach ($xmlNode->identifiers->identifier as $idNode) {
                $identifiers[] = APIIdentifier::parseXML($idNode);
            }
            $case->identifiers = array_filter($identifiers);
        }

        return $case;
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
    public function getUsername() {
        return $this->userName;
    }

    /**
     *
     * @return string
     */
    public function getFullName() {
        return $this->fullName;
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
    public function getSurname() {
        return $this->surname;
    }

    /**
     *
     * @return string
     */
    public function getNickname() {
        return $this->nickname;
    }

    /**
     *
     * @return string
     */
    public function getBirthdate() {
        return $this->bdate;
    }

    /**
     *
     * @return string
     */
    public function getGender() {
        return $this->gender;
    }

    /**
     *
     * @return string
     */
    public function getTimezone() {
        return $this->timezone;
    }

    /**
     *
     * @return APIIdentifier[]
     */
    public function getIdentifiers() {
        return $this->identifiers;
    }

    /**
     *
     * @return APICasePreferences[]
     */
    public function getPreferences() {
        return $this->preferences;
    }

    /*
     * **********************************
     * METHODS
     * **********************************
     */
    /**
     * Saves the information of the patient
     *
     * @throws APIException
     */
    public function save() {
        $this->api->case_set($this);
    }

    /**
     *
     * @param int $maxRes
     * @param int $offset
     * @param TaskFilter $filter
     * @param boolean $ascending
     * @throws APIException
     * @return APITask[]
     */
    public function getTaskList($maxRes = null, $offset = null, $filter = null, $ascending = true) {
        if (!$filter) {
            $filter = new TaskFilter();
        }
        $filter->setObjectType('TASKS');
        return $this->api->case_get_task_list($this->id, $maxRes, $offset, $filter, $ascending);
    }

    /**
     *
     * @param XMLHelper $xml
     * @param SimpleXMLElement $parentNode
     * @return SimpleXMLElement
     */
    public function toXML($xml, $parentNode = null) {
        if ($parentNode === null) {
            $parentNode = $xml->rootNode;
        }

        $xml->createChildNode($xml->rootNode, 'ref', $this->id);
        $dataNode = $xml->createChildNode($xml->rootNode, 'data');
        $preferencesNode = $xml->createChildNode($dataNode, 'preferences');

        if ($this->preferences) {
            $xml->createChildNode($preferencesNode, 'preferences');
            $this->preferences->toXML($xml, $preferencesNode);
        }

        return $parentNode;
    }
}