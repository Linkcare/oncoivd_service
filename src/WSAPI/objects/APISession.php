<?php

class APISession {
    private $token;
    private $userId;
    private $language;
    private $roleId;
    private $teamId;
    private $teamCode;
    private $name;
    private $professionalId;
    private $caseId;
    private $associateId;
    private $timezone;

    /**
     *
     * @param string[] $sessionInfo
     * @return APICase
     */
    static public function parseResponse($sessionInfo) {
        if (!$sessionInfo) {
            return null;
        }
        $session = new APISession();
        if (array_key_exists("result", $sessionInfo)) {
            // session_get response
            if ($xml = simplexml_load_string($sessionInfo["result"])) {
                $session->token = trim($xml->token);
                $session->userId = trim($xml->user);
                $session->language = trim($xml->language);
                $session->timezone = trim($xml->timezone);
                $session->roleId = intval(trim($xml->role));
                $session->teamId = trim($xml->team);
                $session->teamCode = trim($xml->team_code);
                $session->name = trim($xml->name);
                $session->professionalId = trim($xml->professional);
                $session->caseId = trim($xml->case);
                $session->associateId = trim($xml->associate);
            }
        } else {
            // session_init response
            $session->token = $sessionInfo["token"];
            $session->userId = $sessionInfo["user"];
            $session->language = $sessionInfo["language"];
            $session->timezone = $sessionInfo["timezone"];
            $session->roleId = $sessionInfo["role"];
            $session->teamId = $sessionInfo["team"];
            $session->teamCode = $sessionInfo["team_code"];
            $session->name = $sessionInfo["name"];
            $session->professionalId = $sessionInfo["professional"];
            $session->caseId = $sessionInfo["case"];
            $session->associateId = $sessionInfo["associate"];
        }
        return $session;
    }

    /*
     * **********************************
     * GETTERS
     * **********************************
     */
    public function getToken() {
        return $this->token;
    }

    public function getUserId() {
        return $this->userId;
    }

    public function getLanguage() {
        return $this->language;
    }

    public function getTimezone() {
        return $this->timezone;
    }

    public function getRoleId() {
        return $this->roleId;
    }

    public function getTeamId() {
        return $this->teamId;
    }

    public function getTeamCode() {
        return $this->teamCode;
    }

    public function getName() {
        return $this->name;
    }

    public function getProfessionalId() {
        return $this->professionalId;
    }

    public function getCaseId() {
        return $this->caseId;
    }

    /**
     * Changes the active TEAM.
     * This function should never be used by a client.
     * This is a public function only for LinkcareSoapAPI functions after invoking session_set_team()
     *
     * @param string $teamId
     */
    public function setTeamId($teamId) {
        $this->teamId = $teamId;
    }

    /**
     * Changes the active ROLE.
     * This function should never be used by a client.
     * This is a public function only for LinkcareSoapAPI functions after invoking session_role()
     *
     * @param string $roleId
     */
    public function setRoleId($roleId) {
        $this->roleId = $roleId;
    }

    /**
     * Changes the active language.
     *
     * @param string $language
     */
    public function setLanguage($language) {
        $this->language = $language;
    }

    /**
     * Changes the active timezone.
     *
     * @param string $language
     */
    public function setTimezone($timezone) {
        $this->timezone = $timezone;
    }
}