<?php

class APISubscription {
    private $id;
    private $version;
    private $date;
    private $active;
    private $locked;
    private $caseManager;
    /* @var APIProgram */
    private $program;
    /* @var APITeam */
    private $team;

    /**
     *
     * @param SimpleXMLElement $xmlNode
     * @return APISubscription
     */
    static public function parseXML($xmlNode) {
        if (!$xmlNode) {
            return null;
        }
        $subscription = new APISubscription();
        $subscription->id = (string) $xmlNode->ref;
        $subscription->version = (string) $xmlNode->version;
        $subscription->date = (string) $xmlNode->subscription_date;
        $subscription->active = boolToText((string) $xmlNode->active);
        $subscription->locked = boolToText((string) $xmlNode->locked);
        $subscription->caseManager = boolToText((string) $xmlNode->case_manager);
        $subscription->program = APIProgram::parseXML($xmlNode->program);
        $subscription->team = APITeam::parseXML($xmlNode->team);
        return $subscription;
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
    public function getVersion() {
        return $this->version;
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
     * @return boolean
     */
    public function isActive() {
        return $this->active;
    }

    /**
     *
     * @return boolean
     */
    public function isLocked() {
        return $this->locked;
    }

    /**
     *
     * @return APIProgram
     */
    public function getProgram() {
        return $this->program;
    }

    /**
     *
     * @return APITeam
     */
    public function getTeam() {
        return $this->team;
    }
}