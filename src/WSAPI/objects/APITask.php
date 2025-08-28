<?php

class APITask {
    const STATUS_NOT_DONE = 'ASSIGNED/NOT DONE';
    const STATUS_DONE = 'DONE';
    const STATUS_CANCELLED = 'CANCELLED';
    const STATUS_PAUSED = 'PAUSED';
    const STATUS_EXPIRED = 'EXPIRED';

    // Private members
    private $id;
    private $taskCode;
    private $name;
    private $description;
    private $date;
    private $hour;
    private $duration;
    private $followReport;
    private $status;
    private $recursive;
    private $locked;
    /** @var string */
    private $admissionId;
    /** @var string */
    private $caseId;

    /** @var APITaskAssignment[] $assignments */
    private $assignments = [];
    /** @var APIForm[] $forms */
    private $forms = null;
    /** @var LinkcareSoapAPI $api */
    private $api;
    private $modified = true;

    public function __construct() {
        $this->api = LinkcareSoapAPI::getInstance();
    }

    /**
     *
     * @param SimpleXMLElement $xmlNode
     * @return APITask
     */
    static public function parseXML($xmlNode) {
        if (!$xmlNode) {
            return null;
        }
        $task = new APITask();
        $task->id = NullableString($xmlNode->ref);
        if ($xmlNode->code) {
            $task->taskCode = NullableString($xmlNode->code);
        } elseif ($xmlNode->refs) {
            $task->taskCode = NullableString($xmlNode->refs->task_code);
        }
        $task->name = NullableString($xmlNode->name);
        $task->description = NullableString($xmlNode->description);

        $date = NullableString($xmlNode->date);
        $dateParts = $date ? explode(' ', $date) : [null];
        $task->date = $dateParts[0];
        $task->hour = NullableString($xmlNode->hour);
        if (!$task->hour && count($dateParts) > 1) {
            $task->hour = $dateParts[1];
        }
        $task->duration = NullableInt($xmlNode->duration);
        $task->followReport = NullableString($xmlNode->follow_report);
        $task->status = NullableString($xmlNode->status);
        $task->recursive = NullableString($xmlNode->recursive);
        $task->locked = textToBool($xmlNode->locked);

        if ($xmlNode->admission) {
            $task->admissionId = NullableString($xmlNode->admission->ref);
        }
        if ($xmlNode->case) {
            $task->caseId = NullableString($xmlNode->case->ref);
        }
        $assignments = [];
        if ($xmlNode->assignments) {
            foreach ($xmlNode->assignments->assignment as $assignNode) {
                $assignments[] = APITaskAssignment::parseXML($assignNode);
            }
            $task->assignments = array_filter($assignments);
        }

        $forms = [];
        if ($xmlNode->forms) {
            foreach ($xmlNode->forms->form as $formNode) {
                $forms[] = APIForm::parseXML($formNode);
            }
            $task->forms = array_filter($forms);
        }

        $task->modified = false;
        return $task;
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
    public function getId() {
        return $this->id;
    }

    /**
     *
     * @return string
     */
    public function getTaskCode() {
        return $this->taskCode;
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
    public function getDescription() {
        return $this->description;
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
     * @return string
     */
    public function getHour() {
        return $this->hour;
    }

    /**
     *
     * @return string
     */
    public function getDateTime() {
        return trim($this->date . ' ' . $this->hour);
    }

    /**
     *
     * @return int
     */
    public function getDuration() {
        return $this->duration;
    }

    /**
     *
     * @return string
     */
    public function getFollowReport() {
        return $this->followReport;
    }

    /**
     *
     * @return string
     */
    public function getStatus() {
        return $this->status;
    }

    /**
     *
     * @return string
     */
    public function getRecursive() {
        return $this->recursive;
    }

    /**
     *
     * @return boolean
     */
    public function getLocked() {
        return $this->locked;
    }

    /**
     *
     * @return APIForm[]
     */
    public function getForms($forceReload = true) {
        if ($this->forms === null || $forceReload) {
            $this->forms = $this->api->task_activity_list($this->id);
        }
        return $this->forms;
    }

    /**
     *
     * @return string
     */
    public function getAdmissionId() {
        return $this->admissionId;
    }

    /**
     *
     * @return string
     */
    public function getCaseId() {
        return $this->caseId;
    }

    /**
     *
     * @return APITaskAssignment[]
     */
    public function getAssignments() {
        return $this->assignments;
    }

    /*
     * **********************************
     * SETTERS
     * **********************************
     */
    /**
     *
     * @param string $date
     */
    public function setDate($date) {
        if ($date) {
            $date = explode(' ', $date)[0]; // Remove time part
        }

        if ($this->date !== $date) {
            $this->date = $date;
            $this->modified = true;
        }
    }

    /**
     *
     * @param string $date
     */
    public function setHour($time) {
        if ($this->hour !== $time) {
            $this->hour = $time;
            $this->modified = true;
        }
    }

    /**
     *
     * @param boolean $locked
     */
    public function setLocked($locked) {
        if ($this->locked !== $locked) {
            $this->locked = $locked;
            $this->modified = true;
        }
    }

    /**
     * Adds a new assignment to a TASK
     *
     * @param APITaskAssignment|APITaskAssignment[] $assignments
     */
    public function addAssignments($assignments) {
        if (!$assignments || empty($assignments)) {
            return;
        }
        if (!is_array($assignments)) {
            $assignments = [$assignments];
        }
        foreach ($assignments as $a) {
            $this->assignments[] = $a;
        }

        $this->modified = true;
    }

    /*
     * **********************************
     * METHODS
     * **********************************
     */
    /**
     * Changes the status of the TASK to "Cancelled".
     * The function will do nothing if the TASK is already cancelled
     */
    public function cancel() {
        if ($this->isCancelled()) {
            return;
        }
        $this->api->task_cancel($this->id);
    }

    /**
     *
     * @return boolean
     */
    public function isClosed() {
        return $this->status == self::STATUS_DONE;
    }

    /**
     *
     * @return boolean
     */
    public function isOpen() {
        return $this->status == self::STATUS_NOT_DONE;
    }

    /**
     *
     * @return boolean
     */
    public function isCancelled() {
        return $this->status == self::STATUS_CANCELLED;
    }

    /**
     *
     * @return boolean
     */
    public function isExpired() {
        return $this->status == self::STATUS_EXPIRED;
    }

    /**
     *
     * @return boolean
     */
    public function isPaused() {
        return $this->status == self::STATUS_PAUSED;
    }

    /**
     * Removes all the assignments of the TASK
     */
    public function clearAssignments() {
        if (empty($this->assignments)) {
            return;
        }
        $this->assignments = [];
        $this->modified = true;
    }

    /**
     * Searches the FORM with the $formId (can be a FORM CODE) indicated
     * Returns an array of FORMS that match the requested $formId
     *
     * @param int $formId
     * @return APIForm[]
     */
    public function findForm($formId) {
        $forms = [];
        if ($this->forms === null) {
            $this->forms = $this->api->task_activity_list($this->id);
        }
        foreach ($this->forms as $f) {
            if ($f->getId() == $formId || $f->getFormCode() == $formId) {
                $forms[] = $f;
            }
        }

        return $forms;
    }

    /**
     * Inserts an ACTIVITY in this TASK
     *
     * @param string $taskCode
     * @param int|string $position
     * @param boolean $insertClosed
     * @param stdClass $parameters
     * @throws APIException
     * @return APIForm[]|APIReport[]
     */
    public function activityInsert($taskCode, $position = null, $insertClosed = false, $parameters = null) {
        return $this->api->task_activity_insert($this->id, $taskCode, $position, $insertClosed, $parameters);
    }

    /**
     */
    public function save() {
        if ($this->modified) {
            $this->api->task_set($this);
        }
    }

    public function delete() {
        $this->api->task_delete($this);
    }

    /**
     *
     * @param XMLHelper $xml
     * @return SimpleXMLElement $parentNode
     */
    public function toXML($xml, $parentNode = null) {
        if ($parentNode === null) {
            $parentNode = $xml->rootNode;
        }
        $xml->createChildNode($parentNode, "ref", $this->getId());
        if ($this->getDate() !== null) {
            $xml->createChildNode($parentNode, "date", $this->getDate());
        }
        if ($this->getHour() !== null) {
            $xml->createChildNode($parentNode, "hour", $this->getHour());
        }
        if ($this->getDuration() !== null) {
            $xml->createChildNode($parentNode, "duration", $this->getDuration());
        }
        if ($this->getFollowReport() !== null) {
            $xml->createChildNode($parentNode, "follow_report", $this->getFollowReport());
        }
        if ($this->getStatus() !== null) {
            $xml->createChildNode($parentNode, "status", $this->getStatus());
        }
        if ($this->getRecursive() !== null) {
            $xml->createChildNode($parentNode, "recursive", $this->getRecursive());
        }
        if ($this->getLocked() !== null) {
            $xml->createChildNode($parentNode, "locked", boolToText($this->getLocked()));
        }

        $assignmentsNode = $xml->createChildNode($parentNode, "assignments");
        foreach ($this->assignments as $a) {
            $assignNode = $xml->createChildNode($assignmentsNode, "assignment");
            $a->toXML($xml, $assignNode);
        }
    }
}