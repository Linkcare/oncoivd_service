<?php

class APITaskAssignment {
    const CASE_MANAGER = 24;
    const PATIENT = 39;
    const SERVICE = 47;
    const REFERRAL = 48;

    // Private members
    private $teamId;
    private $roleId;
    private $userId;

    public function __construct($roleId = null, $teamId = null, $userId = null) {
        $this->roleId = $roleId;
        $this->teamId = $teamId;
        $this->userId = $userId;
    }

    /**
     *
     * @param SimpleXMLElement $xmlNode
     * @return APITaskAssignment
     */
    static public function parseXML($xmlNode) {
        if (!$xmlNode) {
            return null;
        }
        $assignment = new APITaskAssignment();
        if ($xmlNode->team) {
            if ($xmlNode->team->ref) {
                $assignment->teamId = NullableString($xmlNode->team->ref);
            } else {
                $assignment->teamId = NullableString($xmlNode->team->id);
            }
        }
        if ($xmlNode->role) {
            if ($xmlNode->role->ref) {
                $assignment->roleId = NullableString($xmlNode->role->ref);
            } else {
                $assignment->roleId = NullableString($xmlNode->role->id);
            }
        }
        if ($xmlNode->user) {
            if ($xmlNode->user->ref) {
                $assignment->userId = NullableString($xmlNode->user->ref);
            } else {
                $assignment->userId = NullableString($xmlNode->user->id);
            }
        }
        return $assignment;
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
    public function getTeamId() {
        return $this->teamId;
    }

    /**
     *
     * @return string
     */
    public function getRoleId() {
        return $this->roleId;
    }

    /**
     *
     * @return string
     */
    public function getUserId() {
        return $this->userId;
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
    public function setTeamId($value) {
        $this->teamId = $value;
    }

    /**
     *
     * @param string $value
     */
    public function setRoleId($value) {
        $this->roleId = $value;
    }

    /**
     *
     * @param string $value
     */
    public function setUserId($value) {
        $this->userId = $value;
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
    public function toXML($xml, $parentNode = null) {
        if ($parentNode === null) {
            $parentNode = $xml->rootNode;
        }

        $node = $xml->createChildNode($parentNode, "team");
        $xml->createChildNode($node, "id", $this->getTeamId());
        $xml->createChildNode($node, "ref", $this->getTeamId());

        $node = $xml->createChildNode($parentNode, "role");
        $xml->createChildNode($node, "id", $this->getRoleId());
        $xml->createChildNode($node, "ref", $this->getRoleId());

        $node = $xml->createChildNode($parentNode, "user");
        $xml->createChildNode($node, "id", $this->getUserId());
        $xml->createChildNode($node, "ref", $this->getUserId());

        return $parentNode;
    }
}