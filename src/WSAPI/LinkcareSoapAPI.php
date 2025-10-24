<?php

class LinkcareSoapAPI {
    /** @var LinkcareSoapAPI */
    private static $api;
    /** @var boolean */
    private static $isNewSession;
    /** @var SoapClient */
    public $client = null;
    /** @var APISession */
    private $session = null;
    private $lastErrorCode;
    private $lastErrorMessage;

    /** @var SoapClient */
    private static $soapClient;

    /**
     *
     * @param SoapClient $client
     * @param APISession $session
     */
    private function __construct($client, $session) {
        $this->client = $client;
        $this->session = $session;
    }

    /**
     * Prepares the connection with WS-API
     *
     * @param string $endpoint
     * @throws APIException
     */
    static public function setEndpoint($endpoint) {
        $wsdl = null; // $url . "/LINKCARE.wsdl.php";
        $client = null;

        // Obtenemos el TOKEN si ya existe o iniciamos sesión si no existe
        // Cuando de error hay que borrar el TOKEN
        $uri = parse_url($endpoint)['scheme'] . '://' . parse_url($endpoint)['host'];
        try {
            $client = new SoapClient($wsdl, ['location' => $endpoint, 'uri' => $uri, "connection_timeout" => 10]);
        } catch (SoapFault $fault) {
            $errorMsg = "ERROR: SOAP Fault: (faultcode: {$fault->faultcode}, faultstring: {$fault->faultstring})";
            throw new APIException("SOAP_ERROR", $errorMsg);
        }

        self::$soapClient = $client;
    }

    /**
     *
     * @return LinkcareSoapAPI
     */
    static public function getInstance() {
        return self::$api;
    }

    /**
     * Error code returned by the last API call
     *
     * @return string
     */
    public function errorCode() {
        return $this->lastErrorCode;
    }

    /**
     * Error message returned by the last API call
     *
     * @return string
     */
    public function errorMessage() {
        return $this->lastErrorMessage;
    }

    /*
     * **********************************
     * API FUNCTIONS
     * **********************************
     */
    /**
     *
     * @param string $user
     * @param string $password
     * @param number|string $timezone
     * @param boolean $reuseExistingSession If true and a previous (non expired) session exists, then a new session will not be created, and the token
     *        of the previous session will be used
     * @param string $language 2-letter ISO language code
     * @throws APIException
     */
    static public function session_init($user, $password, $timezone = 0, $reuseExistingSession = false, $language = null) {
        self::$api = null;
        self::$isNewSession = null;
        if (is_numeric($timezone)) {
            $timezone = $timezone <= 0 ? "-" . abs($timezone) : "+" . abs($timezone);
        }
        $client = self::$soapClient;
        if (!$client) {
            throw new APIException('ENDPOINT_MISSING', 'It is necessary to configure the endpoint before invoking WS-API');
        }

        $date = dateHelper::currentDate($timezone);
        $result = $client->session_init($user, $password, null, null, $language, '2.7.26', $reuseExistingSession ? 1 : 0, $date);
        if ($result["ErrorCode"]) {
            $message = "Error initiating session with user $user: " . $result["ErrorMsg"];
            throw new APIException($result["ErrorCode"], $message);
        } else {
            $session = APISession::parseResponse($result);
        }

        self::$api = new LinkcareSoapAPI($client, $session);
        self::$isNewSession = true;

        return self::$api;
    }

    /**
     * Join an existing Session
     *
     * @param string $token
     * @param string|number $timezone
     * @return LinkcareSoapAPI
     * @throws APIException
     */
    static public function session_join($token, $timezone = null) {
        self::$api = null;
        self::$isNewSession = null;
        $session = self::prepareAPISession(self::$soapClient, $token, $timezone);

        self::$api = new LinkcareSoapAPI(self::$soapClient, $session);
        self::$isNewSession = false;
        return self::$api;
    }

    /**
     * Closes the active session.
     *
     * @param string $token
     * @param string|number $timezone
     * @return LinkcareSoapAPI
     * @throws APIException
     */
    public function session_close($onlyNewSession = true) {
        if ($onlyNewSession && !self::$isNewSession) {
            return;
        }

        try {
            $this->invoke('session_close', []);
        } catch (Exception $e) {}

        self::$api = null;
        self::$isNewSession = null;
    }

    /**
     *
     * @return APISession
     */
    public function getSession() {
        return $this->session;
    }

    /**
     * Sets the active TEAM for the session
     *
     * @param string $teamId
     * @throws APIException
     */
    public function session_set_team($teamId) {
        $params = ["team" => $teamId];
        $resp = $this->invoke('session_set_team', $params);
        if (!$resp->getErrorCode()) {
            $this->session->setTeamId($teamId);
        }
    }

    /**
     * Sets the active language for the session
     *
     * @param string $language 2-letter ISO Code
     * @throws APIException
     */
    public function session_set_language($language) {
        $params = ["language" => $language];
        $resp = $this->invoke('session_set_language', $params);
        if (!$resp->getErrorCode()) {
            $this->session->setLanguage($language);
        }
    }

    /**
     * Sets the timezone for the session
     *
     * @param string $timezone Integer time shift respect UTC or region string (e.g. Europe/Madrid')
     * @throws APIException
     */
    public function session_set_timezone($timezone) {
        $params = ["timezone" => $timezone];
        $resp = $this->invoke('session_set_timezone', $params);
        if (!$resp->getErrorCode()) {
            $this->session->setTimezone($timezone);
        }
    }

    /**
     * Sets the active ROLE for the session
     *
     * @param string $roleId
     * @throws APIException
     */
    public function session_role($roleId) {
        $params = ["role" => $roleId];
        $resp = $this->invoke('session_role', $params);
        if (!$resp->getErrorCode()) {
            $this->session->setRoleId($roleId);
        }
    }

    /**
     * Get information about a PROGRAM
     *
     * @param string $programId
     * @param string $subscriptionId
     * @throws APIException
     * @return APIProgram
     */
    public function program_get($programId, $subscriptionId = null) {
        $program = null;
        $params = ["program_id" => $programId, "subscription" => $subscriptionId];
        $resp = $this->invoke('program_get', $params);
        if (!$resp->getErrorCode()) {
            if ($found = simplexml_load_string($resp->getResult())) {
                $program = APIProgram::parseXML($found);
            }
        }

        return $program;
    }

    /**
     * Creates a new USER (Professional)
     * The value returned is the ID of the new USER
     *
     * @param APIContact $contact
     * @param string $teamId
     * @throws APIException
     * @return string
     */
    function user_insert($contact, $teamId = null) {
        $xml = new XMLHelper('user');
        $contact->toXML($xml, null);

        $params = ['user' => $xml->toString()];
        $resp = $this->invoke('user_insert', $params);
        if (!$resp->getErrorCode()) {
            if ($result = simplexml_load_string($resp->getResult())) {
                $userId = NullableString($result->user);
            }
        }

        return $userId;
    }

    /**
     *
     * @param string $userId
     * @throws APIException
     * @return APIUser
     */
    public function user_get($userId) {
        $case = null;
        $params = ["user" => $userId];
        $resp = $this->invoke('user_get', $params);
        if (!$resp->getErrorCode()) {
            if ($found = simplexml_load_string($resp->getResult())) {
                $case = APIUser::parseXML($found);
            }
        }

        return $case;
    }

    /**
     *
     * @param string $userId
     * @param string $teamId
     * @throws APIException
     * @return APIContact
     */
    public function user_get_contact($userId, $teamId = null) {
        $contact = null;
        $params = ["user" => $userId, "team" => $teamId];
        $resp = $this->invoke('user_get_contact', $params);
        if (!$resp->getErrorCode()) {
            if ($found = simplexml_load_string($resp->getResult())) {
                $contact = APIContact::parseXML($found);
            }
        }

        return $contact;
    }

    /**
     *
     * @param string $searchText
     * @throws APIException
     * @return APIUser[];
     */
    public function user_search($searchText = '') {
        $caseList = [];
        $params = ['search_str' => $searchText];
        $resp = $this->invoke('user_search', $params);
        if (!$resp->getErrorCode()) {
            if ($searchResults = simplexml_load_string($resp->getResult())) {
                foreach ($searchResults->user as $userNode) {
                    $caseList[] = APIUser::parseXML($userNode);
                }
            }
        }

        return array_filter($caseList);
    }

    /**
     *
     * @param APITeam $team
     * @return string
     * @throws APIException
     */
    function team_insert($team) {
        $xml = new XMLHelper('team');
        $rootNode = $xml->rootNode;
        $dataNode = $xml->createChildNode($rootNode, 'data');
        $team->toXML($xml, $dataNode);
        $params = ['team' => $xml->toString()];
        $resp = $this->invoke('team_insert', $params);
        if (!$resp->getErrorCode()) {
            $teamId = $resp->getResult();
        }

        return $teamId;
    }

    /**
     * Get information about a TEAM
     *
     * @param string $teamId
     * @throws APIException
     * @return APITeam
     */
    public function team_get($teamId) {
        $team = null;
        $params = ['team' => $teamId];
        $resp = $this->invoke('team_get', $params);
        if (!$resp->getErrorCode()) {
            if ($found = simplexml_load_string($resp->getResult())) {
                $team = APITeam::parseXML($found->data);
            }
        }

        return $team;
    }

    /**
     *
     * @param APITeam $team
     * @throws APIException
     */
    function team_set($team) {
        $xml = new XMLHelper('team');
        $rootNode = $xml->rootNode;
        $dataNode = $xml->createChildNode($rootNode, 'data');
        $team->toXML($xml, $dataNode);
        $params = ['team' => $xml->toString()];
        $this->invoke('team_set', $params);
    }

    /**
     * Adds a new User (Professional) as member of a Team
     * The value returned is the ID of the User
     *
     * @param APIContact $contact
     * @param string $teamId
     * @param string $roleId
     * @throws APIException
     * @return string
     */
    function team_user_insert($contact, $teamId, $roleId = null) {
        $xml = new XMLHelper('user');
        $contact->toXML($xml, null);

        $params = ['user' => $xml->toString(), 'team' => $teamId, 'roles' => $roleId];
        $resp = $this->invoke('team_user_insert', $params);
        if (!$resp->getErrorCode()) {
            if ($result = simplexml_load_string($resp->getResult())) {
                $userId = NullableString($result->user);
            }
        }

        return $userId;
    }

    /**
     * Adds a new User (Professional) as member of a Team
     * The value returned is the ID of the User
     *
     * @param APIContact $contact
     * @param string $teamId
     * @param string $roleId
     * @throws APIException
     * @return string
     */
    function team_member_add($contact, $teamId, $memberId, $memberType = 'USER', $roleId = null) {
        $params = ['team' => $teamId, 'member' => $memberId, 'type' => $memberType, 'roles' => $roleId];
        $memberId = null;
        $resp = $this->invoke('team_member_add', $params);
        if (!$resp->getErrorCode()) {
            if ($result = simplexml_load_string($resp->getResult())) {
                $memberId = NullableString($result->ref);
            }
        }

        return $memberId;
    }

    /**
     *
     * @param string $programId
     * @param string $version
     * @param string $teamId
     * @throws APIException
     * @return APISubscription
     */
    public function subscription_insert($programId, $version = null, $teamId = null) {
        $subscription = null;
        $params = ['program' => $programId, 'version' => $version, 'team' => $teamId];
        $resp = $this->invoke('subscription_insert', $params);
        if (!$resp->getErrorCode()) {
            if ($result = simplexml_load_string($resp->getResult())) {
                $subscription = APISubscription::parseXML($result);
            }
        }

        return $subscription;
    }

    /**
     *
     * @param string $programId
     * @param string $teamId
     * @param string $subscriptionId
     * @throws APIException
     * @return APISubscription
     */
    public function subscription_get($programId = null, $teamId = null, $subscriptionId = null) {
        $subscription = null;
        $params = ['program' => $programId, 'team' => $teamId, 'subscription' => $subscriptionId];
        $resp = $this->invoke("subscription_get", $params);
        if (!$resp->getErrorCode()) {
            if ($result = simplexml_load_string($resp->getResult())) {
                $subscription = APISubscription::parseXML($result);
            }
        }

        return $subscription;
    }

    /**
     *
     * @param string[] $filter Associative array with filter options. The key of each item is the name of the filter
     * @throws APIException
     * @return APISubscription[]
     */
    public function subscription_list($filter = null) {
        $subscriptionList = [];
        $params = ["filter" => $filter ? json_encode($filter) : null];
        $resp = $this->invoke("subscription_list", $params);
        if (!$resp->getErrorCode()) {
            if ($searchResults = simplexml_load_string($resp->getResult())) {
                foreach ($searchResults->subscription as $subscriptionNode) {
                    $subscriptionList[] = APISubscription::parseXML($subscriptionNode);
                }
            }
        }
        return array_filter($subscriptionList);
    }

    /**
     *
     * @param string $programId
     * @param string $AdmissionStatus
     * @param string $scope
     * @param string $searchStr
     * @param int $maxRes
     * @param int $offset
     * @param string $sortBy
     * @param boolean $ascending
     * @param string $subscriptionFilter
     * @param string $subscriptionId
     * @throws APIException
     * @return APIAdmission[]
     */
    public function admission_list_program($programId, $admissionStatus = 'ACTIVE', $scope = null, $searchStr = null, $maxRes = 100, $offset = 0,
            $sortBy = null, $ascending = true, $subscriptionFilter = 'ALL', $subscriptionId = null) {
        $admissionList = [];

        $params = ['program' => $programId, 'status' => $admissionStatus, 'scope' => $scope, 'search_str' => $searchStr, 'max_res' => $maxRes,
                'offset' => $offset, 'sort' => $sortBy, 'direction' => $ascending ? 'asc' : 'desc', 'filter' => $subscriptionFilter,
                'subscription' => $subscriptionId];
        $resp = $this->invoke('admission_list_program', $params);
        if (!$resp->getErrorCode()) {
            if ($found = simplexml_load_string($resp->getResult())) {
                foreach ($found->admission as $taskNode) {
                    $admissionList[] = APIAdmission::parseXML($taskNode);
                }
            }
        }

        return array_filter($admissionList);
    }

    /**
     *
     * @param int $case
     * @param int $subscription
     * @param string $date
     * @param int $team
     * @param boolean $allow_incomplete
     * @param StdClass $setupValues JSON object with a list of fields to store in the ADMISSION SETUP stage
     * @throws APIException
     * @return APIAdmission
     */
    function admission_create($caseId, $subscriptionId, $date, $team = null, $allowIncomplete = false, $setupValues = null) {
        $admission = null;
        $strValues = is_object($setupValues) ? json_encode($setupValues) : null;
        $params = ["case" => $caseId, "subscription" => $subscriptionId, "date" => $date, "team" => $team,
                "allow_incomplete" => $allowIncomplete ? "1" : "", "setup_values" => $strValues];
        $resp = $this->invoke('admission_create', $params);
        if (!$resp->getErrorCode()) {
            if ($result = simplexml_load_string($resp->getResult())) {
                $admission = APIAdmission::parseXML($result);
            }
        }

        return $admission;
    }

    /**
     *
     * @param string $admissionId
     * @throws APIException
     * @return APIAdmission
     */
    function admission_get($admissionId) {
        $admission = null;
        $params = ["admission" => $admissionId];
        $resp = $this->invoke('admission_get', $params);
        if (!$resp->getErrorCode()) {
            if ($result = simplexml_load_string($resp->getResult())) {
                $admission = APIAdmission::parseXML($result);
            }
        }

        return $admission;
    }

    /**
     *
     * @param APIAdmission $admission
     * @throws APIException
     */
    function admission_set($admission) {
        $xml = new XMLHelper('admission');
        $admission->toXML($xml, null);
        $params = ["admission" => $xml->toString()];
        $this->invoke('admission_set', $params);
    }

    /**
     *
     * @param int $admissionId
     * @throws APIException
     */
    function admission_delete($admissionId) {
        $params = ["admission" => $admissionId];
        $this->invoke('admission_delete', $params);
    }

    /**
     *
     * @param string $admissionId
     * @param int $maxRes
     * @param int $offset
     * @param TaskFilter $filter
     * @param boolean $ascending
     * @throws APIException
     * @return APITask[]
     */
    public function admission_get_task_list($admissionId, $maxRes = null, $offset = null, $filter = null, $ascending = true) {
        $taskList = [];
        $params = ["admission" => $admissionId, "max_res" => $maxRes, "offset" => $offset, "filter" => $filter ? $filter->toString() : null,
                "ascending" => $ascending ? "1" : 0];
        $resp = $this->invoke('admission_get_task_list', $params);
        if (!$resp->getErrorCode()) {
            if ($found = simplexml_load_string($resp->getResult())) {
                foreach ($found->task as $taskNode) {
                    $taskList[] = APITask::parseXML($taskNode);
                }
            }
        }

        return array_filter($taskList);
    }

    /**
     *
     * @param int $admissionId
     * @param string $type of discharge
     * @param string $date (optional) date of the discharge. By default is the current datetime
     * @param APIAdmission $admission (optional) if provided, the data will be stored in this APIAdmission object
     * @throws APIException
     */
    function admission_discharge($admissionId, $type = null, $date = null, $admission = null) {
        $params = ["admission" => $admissionId, 'type' => $type, 'date' => $date];
        $resp = $this->invoke('admission_discharge', $params);
        if (!$resp->getErrorCode()) {
            if ($res = simplexml_load_string($resp->getResult())) {
                $admission = APIAdmission::parseXML($res, $admission);
            }
        }

        return $admission;
    }

    /**
     *
     * @param int $admissionId
     * @param string $type of reject
     * @param string $date (optional) date of the reject. By default is the current datetime
     * @param APIAdmission $admission (optional) if provided, the data will be stored in this APIAdmission object
     * @throws APIException
     */
    function admission_reject($admissionId, $type = null, $date = null, $admission = null) {
        $params = ["admission" => $admissionId, 'type' => $type, 'date' => $date];
        $resp = $this->invoke('admission_reject', $params);
        if (!$resp->getErrorCode()) {
            if ($res = simplexml_load_string($resp->getResult())) {
                $admission = APIAdmission::parseXML($res, $admission);
            }
        }

        return $admission;
    }

    /**
     *
     * @param int $admissionId
     * @param string $date (optional) date of the resume. By default is the current datetime
     * @param APIAdmission $admission (optional) if provided, the data will be stored in this APIAdmission object
     * @throws APIException
     */
    function admission_resume($admissionId, $date = null, $admission = null) {
        $params = ["admission" => $admissionId, 'date' => $date];
        $resp = $this->invoke('admission_resume', $params);
        if (!$resp->getErrorCode()) {
            if ($res = simplexml_load_string($resp->getResult())) {
                $admission = APIAdmission::parseXML($res, $admission);
            }
        }

        return $admission;
    }

    /**
     *
     * @param int $taskId
     * @throws APIException
     */
    public function task_cancel($taskId) {
        $params = ['task' => $taskId];
        $this->invoke('task_cancel', $params);
    }

    /**
     *
     * @param int $taskId
     * @throws APIException
     * @return APITask
     */
    public function task_get($taskId) {
        $task = null;
        $params = ["task" => $taskId, "context" => ""];
        $resp = $this->invoke('task_get', $params);
        if (!$resp->getErrorCode()) {
            if ($found = simplexml_load_string($resp->getResult())) {
                $task = APITask::parseXML($found);
            }
        }

        return $task;
    }

    /**
     *
     * @param APITask $task
     * @throws APIException
     */
    function task_set($task) {
        $xml = new XMLHelper('task');
        $task->toXML($xml, null);
        $params = ["task" => $xml->toString()];
        $this->invoke('task_set', $params);
    }

    /**
     *
     * @param APITask $task
     * @throws APIException
     */
    function task_delete($task) {
        $xml = new XMLHelper('task');
        $task->toXML($xml, null);
        $params = ["token" => $this->session->getToken(), "task" => $task->getId()];
        $this->invoke('task_delete', $params);
    }

    /**
     * Returns the list of ACTIVITIES of a FORM
     *
     * @param int $taskId
     * @throws APIException
     * @return APIForm[]|APIReport[]
     */
    public function task_activity_list($taskId) {
        $activities = [];
        $params = ["task_id" => $taskId];
        $resp = $this->invoke('task_activity_list', $params);
        if (!$resp->getErrorCode()) {
            if ($results = simplexml_load_string($resp->getResult())) {
                foreach ($results->activity as $activityNode) {
                    if ($activityNode->type == 'form') {
                        $activities[] = APIForm::parseXML($activityNode);
                    } elseif ($activityNode->type == 'report') {
                        $activities[] = APIReport::parseXML($activityNode);
                    }
                }
            }
        }

        return array_filter($activities);
    }

    /**
     * Inserts an ACTIVITY in an existing TASK
     *
     * @param int $taskId
     * @param string $taskCode
     * @param int|string $position
     * @param boolean $insertClosed
     * @param stdClass $parameters
     * @throws APIException
     * @return APIForm[]|APIReport[]
     */
    public function task_activity_insert($taskId, $taskCode, $position = null, $insertClosed = false, $parameters = null) {
        $paramsStr = $parameters ? json_encode($parameters) : null;
        $params = ['task' => $taskId, 'task_code' => $taskCode, 'position' => $position, 'insert_closed' => textToBool($insertClosed) ? 1 : 0,
                'parameters' => $paramsStr];
        $resp = $this->invoke('task_activity_insert', $params);
        if (!$resp->getErrorCode()) {
            if ($results = simplexml_load_string($resp->getResult())) {
                foreach ($results->activity as $activityNode) {
                    if ($activityNode->type == 'form') {
                        $activities[] = APIForm::parseXML($activityNode);
                    } elseif ($activityNode->type == 'report') {
                        $activities[] = APIReport::parseXML($activityNode);
                    }
                }
            }
        }
        return $activities;
    }

    /**
     * Inserts a new TASK in an ADMISSION.
     * The return value is the ID of the new TASK
     *
     * @param string $admissionId
     * @param string $taskCode
     * @param string $date
     * @param array $parameters
     * @throws APIException
     * @return int
     */
    function task_insert_by_task_code($admissionId, $taskCode, $date = null, $parameters = null) {
        $taskId = null;
        $params = ["admission" => $admissionId, "task_code" => $taskCode, "date" => $date,
                "parameters" => $parameters ? json_encode($parameters) : null];
        $resp = $this->invoke('task_insert_by_task_code', $params);
        if (!$resp->getErrorCode()) {
            $taskId = $resp->getResult();
        }

        return $taskId;
    }

    /**
     * Creates a new CASE
     * The value returned is the ID of the new CASE
     *
     * @param APIContact $contact
     * @param int $subscriptionId
     * @param boolean $allowIncomplete
     * @throws APIException
     * @return APICase
     */
    function case_insert($contact, $subscriptionId = null, $allowIncomplete = false) {
        $xml = new XMLHelper("case");
        $contact->toXML($xml, null);

        $params = ['case' => $xml->toString(), 'subscription' => $subscriptionId, 'allow_incomplete' => boolToText($allowIncomplete)];
        $resp = $this->invoke('case_insert', $params);
        if (!$resp->getErrorCode()) {
            if ($result = simplexml_load_string($resp->getResult())) {
                $caseId = NullableString($result->case);
            }
        }

        $case = $caseId ? $this->case_get($caseId) : null;
        return $case;
    }

    /**
     *
     * @param string $caseId
     * @throws APIException
     * @return APICase
     */
    public function case_get($caseId, $admissionId = null) {
        $case = null;
        $params = ["case" => $caseId, 'admission' => $admissionId];
        $resp = $this->invoke('case_get', $params);
        if (!$resp->getErrorCode()) {
            if ($found = simplexml_load_string($resp->getResult())) {
                $case = APICase::parseXML($found);
            }
        }

        return $case;
    }

    /**
     *
     * @param string $caseId
     * @param string $subscriptionId
     * @param string $admissionId
     * @throws APIException
     * @return APIContact
     */
    public function case_get_contact($caseId, $subscriptionId = null, $admissionId = null) {
        $contact = null;
        $params = ["case" => $caseId, "subscription" => $subscriptionId, "admission" => $admissionId];
        $resp = $this->invoke('case_get_contact', $params);
        if (!$resp->getErrorCode()) {
            if ($found = simplexml_load_string($resp->getResult())) {
                $contact = APIContact::parseXML($found);
            }
        }

        return $contact;
    }

    /**
     *
     * @param string $caseId
     * @param APIContact $contact
     * @param string $admissionId
     * @param string $programId
     * @param string $teamId
     * @throws APIException
     */
    public function case_set_contact($caseId, $contact, $admissionId = null, $programId = null, $teamId = null) {
        $xml = new XMLHelper('contact');

        $contact->setId($caseId);
        $contact->toXML($xml, $xml->rootNode);
        $params = ['case' => $xml->toString(), 'admission' => $admissionId, 'program' => $programId, 'team' => $teamId];
        $this->invoke('case_set_contact', $params);
    }

    /**
     * Set Case preferences
     *
     * @param APICase $case
     * @param string $admissionId
     * @throws APIException
     */
    public function case_set($case, $admissionId = null) {
        $xml = new XMLHelper('case');

        $case->toXML($xml, $xml->rootNode);
        $params = ['case' => $xml->toString(), 'admission' => $admissionId];
        $this->invoke('case_set', $params);
    }

    /**
     *
     * @param int $caseId
     * @throws APIException
     */
    function case_delete($caseId) {
        $params = ['case' => $caseId, 'type' => 'DELETE'];
        $this->invoke('case_delete', $params);
    }

    /**
     *
     * @param string $searchText
     * @throws APIException
     * @return APICase[];
     */
    public function case_search($searchText = "") {
        $caseList = [];
        $params = ["search_str" => $searchText];
        $resp = $this->invoke('case_search', $params);
        if (!$resp->getErrorCode()) {
            if ($searchResults = simplexml_load_string($resp->getResult())) {
                foreach ($searchResults->case as $caseNode) {
                    $caseList[] = APICase::parseXML($caseNode);
                }
            }
        }

        return array_filter($caseList);
    }

    /**
     *
     * @param int $caseId
     * @param boolean $get
     * @param int $subscriptionId
     * @param string $search
     * @throws APIException
     * @return APIAdmission[]
     */
    public function case_admission_list($caseId, $get = false, $subscriptionId = null, $search = null) {
        $admissionList = [];
        $params = ["case" => $caseId, "get" => $get ? "1" : "", "subscription" => $subscriptionId, "search_str" => $search];
        $resp = $this->invoke('case_admission_list', $params);
        if (!$resp->getErrorCode()) {
            if ($found = simplexml_load_string($resp->getResult())) {
                foreach ($found->admission as $admissionNode) {
                    $admissionList[] = APIAdmission::parseXML($admissionNode);
                }
            }
        }

        return array_filter($admissionList);
    }

    /**
     *
     * @param string $caseId
     * @param int $maxRes
     * @param int $offset
     * @param TaskFilter $filter
     * @param boolean $ascending
     * @throws APIException
     * @return APITask[]
     */
    public function case_get_task_list($caseId, $maxRes = null, $offset = null, $filter = null, $ascending = true) {
        $taskList = [];
        $params = ["case" => $caseId, "max_res" => $maxRes, "offset" => $offset, "filter" => $filter ? $filter->toString() : null,
                "ascending" => $ascending ? "1" : 0];
        $resp = $this->invoke('case_get_task_list', $params);
        if (!$resp->getErrorCode()) {
            if ($found = simplexml_load_string($resp->getResult())) {
                foreach ($found->task as $taskNode) {
                    $taskList[] = APITask::parseXML($taskNode);
                }
            }
        }

        return array_filter($taskList);
    }

    /**
     *
     * @param int $formId
     * @throws APIException
     */
    public function form_open($formId) {
        $params = ['form' => $formId];
        $this->invoke('form_open', $params);
    }

    /**
     *
     * @param int $formId
     * @throws APIException
     */
    public function form_close($formId) {
        $params = ['form' => $formId];
        $this->invoke('form_close', $params);
    }

    /**
     * Inserts a new FORM in an TASK.
     * The return value is the ID of the new FORM
     *
     * @param string $taskId
     * @param string $formCode
     * @throws APIException
     * @return int
     */
    function form_insert($taskId, $formCode, $parameters, $newFormCode = null) {
        $taskId = null;
        $params = ["task" => $taskId, "form_code" => $formCode, 'new_form_code' => $newFormCode,
                "parameters" => $parameters ? json_encode($parameters) : null];
        $resp = $this->invoke('form_insert', $params);
        if (!$resp->getErrorCode()) {
            $formId = $resp->getResult();
        }

        return $formId;
    }

    /**
     *
     * @param int $formId
     * @param boolean $withQuestions
     * @param boolean $asClosed
     * @param APIForm $form (optional) if provided, the data will be stored in this APIForm object instead of creating a new one
     * @throws APIException
     * @return APIForm
     */
    public function form_get_summary($formId, $withQuestions = false, $asClosed = false, $form = null) {
        $params = ["form" => $formId, "with_questions" => $withQuestions ? "1" : "", "as_closed" => $asClosed ? "1" : ""];
        $resp = $this->invoke('form_get_summary', $params);
        if ($xml = simplexml_load_string($resp->getResult())) {
            $form = APIForm::parseXML($xml, $form);
        }

        return $form;
    }

    /**
     *
     * @param string $formId
     * @param string $questionId
     * @param string $value
     * @param string $optionId
     * @param string $eventId
     * @throws APIException
     * @param boolean $closeForm
     */
    function form_set_answer($formId, $questionId, $value, $optionId = null, $eventId = null, $closeForm = false) {
        $params = ["form_id" => $formId, "question_id" => $questionId, "value" => $value, "option_id" => $optionId, "event_id" => $eventId,
                "close_form" => $closeForm ? "1" : ""];
        $this->invoke('form_set_answer', $params);
    }

    /**
     * Sets the value of multiple the answers of a form in a single call.
     * This function is more efficient than calling form_set_answer() for each question separately.<br>
     * Note that if there are questions belonging to an array, the API will completely overwrite the array with the questions (the previous data will
     * be lost).<br>
     * Alternatively, you can use form_update_all_answers() to simply update the values of existing questions but without replacing the existing
     * contents
     * of arrays<br>
     *
     * @param string $formId
     * @param APIQuestion[] $questions
     * @param boolean $closeForm
     * @throws APIException
     */
    function form_set_all_answers($formId, $questions, $closeForm = false) {
        $xml = new XMLHelper('questions');

        $simpleQuestions = [];
        $arrayQuestions = [];
        foreach ($questions as $q) {
            if ($q->getArrayRef()) {
                $arrayQuestions[$q->getArrayRef()][$q->getRow()][] = $q;
            } else {
                $simpleQuestions[] = $q;
            }
        }

        foreach ($simpleQuestions as $q) {
            $qNode = $xml->createChildNode(null, "question");
            $q->toXML($xml, $qNode);
        }

        foreach ($arrayQuestions as $arrayRef => $rows) {
            $arrayNode = $xml->createChildNode(null, "array");
            $xml->createChildNode($arrayNode, 'ref', $arrayRef);
            foreach ($rows as $rowQuestions) {
                $rowNode = $xml->createChildNode($arrayNode, "row");
                foreach ($rowQuestions as $q) {
                    $qNode = $xml->createChildNode($rowNode, "question");
                    $q->toXML($xml, $qNode);
                }
            }
        }

        $params = ["form" => $formId, "xml_answers" => $xml->toString(), "close_form" => $closeForm ? "1" : ""];
        $this->invoke('form_set_all_answers', $params);
    }

    /**
     * Sets the value of multiple the answers of a form in a single call.
     * This function is more efficient than calling form_set_answer() for each question separately.<br>
     * This function assumes that all questions already exist in the FORM. Opposite to form_set_all_answers() is not able to add or remove rows from
     * arrays, because it would involve creating new questions or removing existing ones.
     *
     * @param string $formId
     * @param APIQuestion[] $questions
     * @param boolean $closeForm
     * @throws APIException
     */
    function form_update_all_answers($formId, $questions, $closeForm = false) {
        $xml = new XMLHelper('questions');

        foreach ($questions as $q) {
            $qNode = $xml->createChildNode(null, "question");
            $q->toXML($xml, $qNode, true);
        }

        $params = ["form" => $formId, "xml_answers" => $xml->toString(), "close_form" => $closeForm ? "1" : ""];
        $this->invoke('form_set_all_answers', $params);
    }

    /**
     *
     * @param string $date
     * @param string $caseId
     * @param string $eventType
     * @param string $eventCode
     * @param string $teamId
     * @param string $admissionId
     * @param string $message
     * @param stdClass $options
     * @throws APIException
     * @return string
     */
    function event_insert($date = null, $caseId = null, $eventType = null, $eventCode = null, $teamId = null, $admissionId = null, $message = null,
            $options = null) {
        if (!$date) {
            $date = DateHelper::currentDate($this->session->getTimezone());
        }
        $params = ["date" => $date, "case" => $caseId, "event_type" => $eventType, "event_code" => $eventCode, "team" => $teamId,
                "admission" => $admissionId, "message" => $message];
        if ($options && is_object($options)) {
            $params['options'] = json_encode($options);
        }
        $resp = $this->invoke('event_insert', $params);
        return $resp->getResult();
    }

    /**
     *
     * @param int $eventId
     * @throws APIException
     * @return APIEvent
     */
    public function event_get($eventId) {
        $event = null;
        $params = ["event_id" => $eventId];
        $resp = $this->invoke('event_get', $params);
        if (!$resp->getErrorCode()) {
            if ($found = simplexml_load_string($resp->getResult())) {
                $event = APIEvent::parseXML($found);
            }
        }

        return $event;
    }

    /**
     *
     * @param APIEvent $event
     * @throws APIException
     */
    function event_set($event) {
        $xml = new XMLHelper('event');
        $event->toXML($xml, null);
        $params = ["event" => $xml->toString()];
        $this->invoke('event_set', $params);
    }

    /**
     * Execute a FORMULA
     *
     * @param string $formId
     * @param string $questionId
     * @param string $formula
     * @param boolean $simulation
     * @throws APIException
     * @return string
     */
    public function formula_exec($formId, $questionId = null, $formula = null, $simulation = false) {
        $value = null;
        $params = ['form' => $formId, 'question' => $questionId, 'formula' => $formula, 'simulation' => $simulation ? 'true' : 'false'];
        $resp = $this->invoke('formula_exec', $params);
        if (!$resp->getErrorCode()) {
            if ($formulaResult = simplexml_load_string($resp->getResult())) {
                $value = NullableString($formulaResult->value);
            }
        }

        return $value;
    }

    /*
     * **********************************
     * PRIVATE METHODS
     * **********************************
     */

    /**
     * Initializes a session in WS-API
     *
     * @param SoapClient $client
     * @param string $token
     * @param int|string $timezone
     * @throws APIException
     * @return APISession
     */
    static private function prepareAPISession($client, $token = null, $timezone = 0) {
        $errorMsg = "";
        $session = null;

        try {
            $result = $client->session_get($token);
            if ($result["ErrorCode"]) {
                throw new APIException($result["ErrorCode"], $result["ErrorMsg"]);
            } else {
                $session = APISession::parseResponse($result);
            }
        } catch (SoapFault $fault) {
            $errorMsg = "ERROR: SOAP Fault: (faultcode: {$fault->faultcode}, faultstring: {$fault->faultstring})";
            throw new APIException("SOAP_ERROR", $errorMsg);
        }
        return $session;
    }

    /**
     * Starts a SOAP call to the function indicated in $functionName
     *
     * @param string $functionName Name of the function to invoke
     * @param string[] $params Associative array. The key of each item is the name of the parameter
     * @throws APIException
     * @return APIResponse;
     */
    private function invoke($functionName, $params, $returnRaw = false) {
        $this->lastErrorCode = null;
        $this->lastErrorMessage = null;

        if (!$this->client) {
            throw new APIException('ENDPOINT_MISSING', 'It is necessary to configure the endpoint before invoking WS-API');
        }

        try {
            $args = [new SoapParam($this->session->getToken(), "session")];
            foreach ($params as $paramName => $paramValue) {
                $args[] = new SoapParam($paramValue, $paramName);
            }
            $result = $this->client->__soapCall($functionName, $args);
        } catch (SoapFault $fault) {
            $errorMsg = "ERROR: SOAP Fault invoking function $functionName: (faultcode: {$fault->faultcode}, faultstring: {$fault->faultstring})";
            throw new APIException('SOAP_FAULT', $errorMsg);
        }

        if ($returnRaw) {
            // Used for old API functions that do not return a standardized response
            return new APIResponse($result, null, null);
        } else {
            if ($result['ErrorCode']) {
                throw new APIException($result['ErrorCode'], $functionName . ':' . $result['ErrorMsg'], $result['result']);
            }

            return new APIResponse($result['result'], $result['ErrorCode'], $result['ErrorMsg']);
        }
    }
}