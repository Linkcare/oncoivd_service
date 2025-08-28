<?php

class TaskFilter {
    /** @var string **/
    private $fromDate;
    /** @var string **/
    private $toDate;
    /** @var string[] **/
    private $programId = [];
    /** @var string[] **/
    private $protocolId = [];
    /** @var string[] **/
    private $subscriptionId = [];
    /** @var string[] **/
    private $admissionId = [];
    /** @var string[] **/
    private $statusId = [];
    /** @var string[] **/
    private $roleId = [];
    /** @var string[] **/
    private $teamId = [];
    /** @var string[] **/
    private $caseId = [];
    /** @var string[] **/
    private $taskCode = [];
    /** @var string[] **/
    private $eventCode = [];
    /** @var boolean **/
    private $flagged;
    /** @var boolean **/
    private $locked;
    /** @var boolean **/
    private $external;
    /** @var string **/
    private $objectTypes = [];

    /*
     * **********************************
     * GETTERS
     * **********************************
     */
    /**
     *
     * @return string $value
     */
    public function getFromDate($value) {
        return $this->fromDate;
    }

    /**
     *
     * @param string $value
     */
    public function getToDate($value) {
        return $this->toDate;
    }

    /**
     *
     * @param string|string[] $value
     */
    public function getProgramIds($value) {
        if (count($this->programId) == 1) {
            return reset($this->programId);
        }
        return $this->programId;
    }

    /**
     *
     * @param string|string[] $value
     */
    public function getProtocolIds($value) {
        if (count($this->protocolId) == 1) {
            return reset($this->protocolId);
        }
        return $this->protocolId;
    }

    /**
     *
     * @param string|string[] $value
     */
    public function getSubscriptionIds($value) {
        if (count($this->subscriptionId) == 1) {
            return reset($this->subscriptionId);
        }
        return $this->subscriptionId;
    }

    /**
     *
     * @param string|string[] $value
     */
    public function getAdmissionIds($value) {
        if (count($this->admissionId) == 1) {
            return reset($this->admissionId);
        }
        return $this->admissionId;
    }

    /**
     *
     * @param string|string[] $value
     */
    public function getStatusIds($value) {
        if (count($this->statusId) == 1) {
            return reset($this->statusId);
        }
        return $this->statusId;
    }

    /**
     *
     * @param string|string[] $value
     */
    public function getRoleIds($value) {
        if (count($this->roleId) == 1) {
            return reset($this->roleId);
        }
        return $this->roleId;
    }

    /**
     *
     * @param string|string[] $value
     */
    public function getTeamIds($value) {
        if (count($this->teamId) == 1) {
            return reset($this->teamId);
        }
        return $this->teamId;
    }

    /**
     *
     * @param string|string[] $value
     */
    public function getCaseIds($value) {
        if (count($this->caseId) == 1) {
            return reset($this->caseId);
        }
        return $this->caseId;
    }

    /**
     *
     * @param boolean $value
     */
    public function getFlagged($value) {
        return $this->flagged;
    }

    /**
     *
     * @param boolean $value
     */
    public function getLocked($value) {
        return $this->locked;
    }

    /**
     *
     * @param boolean $value
     */
    public function getExternal($value) {
        return $this->external;
    }

    /**
     *
     * @param string $value
     */
    public function getObjectType($value) {
        return $this->objectTypes;
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
    public function setFromDate($value) {
        $this->fromDate = $value;
    }

    /**
     *
     * @param string $value
     */
    public function setToDate($value) {
        $this->toDate = $value;
    }

    /**
     *
     * @param string|string[] $value
     */
    public function setProgramIds($value) {
        if ((is_array($value) && empty($value)) || (!is_array($value) && isNullOrEmpty($value))) {
            $this->programId = [];
        } else {
            $this->programId = is_array($value) ? $value : [$value];
        }
    }

    /**
     *
     * @param string|string[] $value
     */
    public function setProtocolIds($value) {
        if ((is_array($value) && empty($value)) || (!is_array($value) && isNullOrEmpty($value))) {
            $this->protocolId = [];
        } else {
            $this->protocolId = is_array($value) ? $value : [$value];
        }
    }

    /**
     *
     * @param string|string[] $value
     */
    public function setSubscriptionIds($value) {
        if ((is_array($value) && empty($value)) || (!is_array($value) && isNullOrEmpty($value))) {
            $this->subscriptionId = [];
        } else {
            $this->subscriptionId = is_array($value) ? $value : [$value];
        }
    }

    /**
     *
     * @param string|string[] $value
     */
    public function setAdmissionIds($value) {
        if ((is_array($value) && empty($value)) || (!is_array($value) && isNullOrEmpty($value))) {
            $this->admissionId = [];
        } else {
            $this->admissionId = is_array($value) ? $value : [$value];
        }
    }

    /**
     *
     * @param string|string[] $value
     */
    public function setStatusIds($value) {
        if ((is_array($value) && empty($value)) || (!is_array($value) && isNullOrEmpty($value))) {
            $this->statusId = [];
        } else {
            $this->statusId = is_array($value) ? $value : [$value];
        }
    }

    /**
     *
     * @param string|string[] $value
     */
    public function setRoleIds($value) {
        if ((is_array($value) && empty($value)) || (!is_array($value) && isNullOrEmpty($value))) {
            $this->roleId = [];
        } else {
            $this->roleId = is_array($value) ? $value : [$value];
        }
    }

    /**
     *
     * @param string|string[] $value
     */
    public function setTeamIds($value) {
        if ((is_array($value) && empty($value)) || (!is_array($value) && isNullOrEmpty($value))) {
            $this->teamId = [];
        } else {
            $this->teamId = is_array($value) ? $value : [$value];
        }
    }

    /**
     *
     * @param string|string[] $value
     */
    public function setCaseIds($value) {
        if ((is_array($value) && empty($value)) || (!is_array($value) && isNullOrEmpty($value))) {
            $this->caseId = [];
        } else {
            $this->caseId = is_array($value) ? $value : [$value];
        }
    }

    /**
     *
     * @param string|string[] $value
     */
    public function setTaskCodes($value) {
        if ((is_array($value) && empty($value)) || (!is_array($value) && isNullOrEmpty($value))) {
            $this->taskCode = [];
        } else {
            $this->taskCode = is_array($value) ? $value : [$value];
        }
    }

    /**
     *
     * @param string|string[] $value
     */
    public function setEventCodes($value) {
        if ((is_array($value) && empty($value)) || (!is_array($value) && isNullOrEmpty($value))) {
            $this->eventCode = [];
        } else {
            $this->eventCode = is_array($value) ? $value : [$value];
        }
    }

    /**
     *
     * @param boolean $value
     */
    public function setFlagged($value) {
        $this->flagged = $value ? true : false;
    }

    /**
     *
     * @param boolean $value
     */
    public function setLocked($value) {
        $this->locked = $value ? true : false;
    }

    /**
     *
     * @param boolean $value
     */
    public function setExternal($value) {
        $this->external = $value ? true : false;
    }

    /**
     *
     * @param string $value
     */
    public function setObjectType($value) {
        if ((is_array($value) && empty($value)) || (!is_array($value) && isNullOrEmpty($value))) {
            $this->objectTypes = [];
        } else {
            $this->objectTypes = is_array($value) ? $value : [$value];
        }
    }

    /*
     * **********************************
     * METHODS
     * **********************************
     */

    /**
     * Returns a JSON representation of the filter that can be passed to *_get_task_filter() functions
     */
    public function toString() {
        $obj = new StdClass();

        if ($this->fromDate !== null && $this->fromDate !== '') {
            $obj->from_date = $this->fromDate;
        }
        if (!isNullOrEmpty($this->toDate)) {
            $obj->to_date = $this->toDate;
        }
        if (!empty($this->programId)) {
            $obj->program = implode(',', $this->programId);
        }
        if (!empty($this->protocolId)) {
            $obj->protocol = implode(',', $this->protocolId);
        }
        if (!empty($this->subscriptionId)) {
            $obj->subscription = implode(',', $this->subscriptionId);
        }
        if (!empty($this->admissionId)) {
            $obj->admission = implode(',', $this->admissionId);
        }
        if (!empty($this->statusId)) {
            $obj->status = implode(',', $this->statusId);
        }
        if (!empty($this->taskCode)) {
            $obj->code = implode(',', $this->taskCode);
        }
        if (!empty($this->roleId)) {
            $obj->role = implode(',', $this->roleId);
        }
        if (!empty($this->teamId)) {
            $obj->team = implode(',', $this->teamId);
        }
        if (!empty($this->caseId)) {
            $obj->patient = implode(',', $this->caseId);
        }
        if (!isNullOrEmpty($this->flagged)) {
            $obj->flagged = $this->flagged;
        }
        if (!isNullOrEmpty($this->locked)) {
            $obj->locked = $this->locked;
        }
        if (!isNullOrEmpty($this->external)) {
            $obj->external = $this->external;
        }
        if (!empty($this->objectTypes)) {
            $obj->object_types = implode(',', $this->objectTypes);
        }

        return json_encode($obj);
    }
}
