<?php

class APICasePreferences {
    const CASE_MANAGER = 24;
    const PATIENT = 39;
    const SERVICE = 47;
    const REFERRAL = 48;

    // Private members
    /**@var boolean */
    private $passwordExpired = null;
    /**@var boolean */
    private $editableByProfessional = null;
    /**@var boolean */
    private $editableByCase = null;
    /**@var string */
    private $gpsMapService = null;
    /**@var string[] */
    private $notificationChannels = null;
    /**@var string */
    private $notificationEventPriority = null;
    /**@var string */
    private $notificationStartTime = null;

    /**
     *
     * @param SimpleXMLElement $xmlNode
     * @return APICasePreferences
     */
    static public function parseXML($xmlNode) {
        if (!$xmlNode) {
            return null;
        }
        $preferences = new APICasePreferences();
        if (isset($xmlNode->password_expire)) {
            $preferences->passwordExpired = textToBool(trim($xmlNode->password_expire));
        }
        if (isset($xmlNode->editable_by_user)) {
            $preferences->editableByProfessional = textToBool(trim($xmlNode->editable_by_user));
        }
        if (isset($xmlNode->editable_by_case)) {
            $preferences->editableByCase = textToBool(trim($xmlNode->editable_by_case));
        }

        if (isset($xmlNode->map_service) && $xmlNode->map_service->code != '') {
            $preferences->gpsMapService = NullableString($xmlNode->map_service->code);
        }

        if (isset($xmlNode->notifications)) {
            if (isset($xmlNode->notifications->channels)) {
                $channels = explode(',', trim($xmlNode->notifications->channels));
                $preferences->notificationChannels = $channels;
            }
            if (isset($xmlNode->notifications->event_priority)) {
                $preferences->notificationEventPriority = NullableString($xmlNode->notifications->event_priority);
            }
            if (isset($xmlNode->notifications->from_time)) {
                $preferences->notificationStartTime = NullableString($xmlNode->notifications->from_time);
            }
        }

        return $preferences;
    }

    /*
     * **********************************
     * GETTERS
     * **********************************
     */

    /**
     * Indicates whether the password of the patient is marked as expired and requires to be renewed
     *
     * @return string
     */
    public function getPasswordExpired() {
        return $this->passwordExpired;
    }

    /**
     * Indicates whether a professional can modify the patient profile data
     *
     * @return boolean
     */
    public function getEditableByProfessional() {
        return $this->editableByProfessional;
    }

    /**
     * Indicates whether a case (patient) can modify his own profile data
     *
     * @return boolean
     */
    public function getEditableByCase() {
        return $this->editableByCase;
    }

    /**
     *
     * @return string
     */
    public function getGpsMapService() {
        return $this->gpsMapService;
    }

    /**
     * List of communication channels that will be used to send notifications to the patient
     * <ul>
     * <li>email</li>
     * <li>phone</li>
     * <li>push</li>
     * <li>webalert</li>
     * <li>whatsapp</li>
     * </ul>
     *
     * @return string[]
     */
    public function getNotificactionChannels() {
        return $this->notificationChannels;
    }

    /**
     * Indicates which Events should be notified to the patient.
     * Possible values are:
     * <ul>
     * <li>all: All events will be notified no matter its priority</li>
     * <li>flagged: Only Events with a non null priority will be notified</li>
     * </ul>
     *
     * @return string
     */
    public function getNotificationEventPriority() {
        return $this->notificationEventPriority;
    }

    /**
     * Time to start sending non-critical notifications.
     * If set, the notifications happened before that time will not be sent until the specified time arrives
     *
     * @return string
     */
    public function getNotificationStartTime() {
        return $this->notificationStartTime;
    }

    /*
     * **********************************
     * SETTERS
     * **********************************
     */

    /**
     * Indicates whether the password of the patient is marked as expired and requires to be renewed
     *
     * @param boolean $value
     */
    public function setPasswordExpired($value) {
        $this->passwordExpired = $value;
    }

    /**
     * Indicates whether a professional can modify the patient profile data
     *
     * @param boolean $value
     */
    public function setEditableByProfessional($value) {
        $this->editableByProfessional = $value;
    }

    /**
     * Indicates whether a case (patient) can modify his own profile data
     *
     * @param boolean $value
     */
    public function setEditableByCase($value) {
        $this->editableByCase = $value;
    }

    /**
     *
     * @param string $value
     */
    public function setGpsMapService($value) {
        $this->gpsMapService = $value;
    }

    /**
     * List of communication channels that will be used to send notifications to the patient.
     * <ul>
     * <li>email</li>
     * <li>phone</li>
     * <li>push</li>
     * <li>webalert</li>
     * <li>whatsapp</li>
     * </ul>
     *
     * @param string[] $value
     */
    public function setNotificationChannels($value) {
        $this->notificationChannels = $value;
    }

    /**
     * Indicates which Events should be notified to the patient.
     * Possible values are:
     * <ul>
     * <li>all: All events will be notified no matter its priority</li>
     * <li>flagged: Only Events with a non null priority will be notified</li>
     * </ul>
     *
     * @param string $value
     */
    public function setNotificationEventPriority($value) {
        $this->notificationEventPriority = $value;
    }

    /**
     * Time to start sending non-critical notifications.
     * If set, the notifications happened before that time will not be sent until the specified time arrives
     *
     * @param string $value
     */
    public function setNotificationStartTime($value) {
        $this->notificationStartTime = $value;
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

        if ($this->editableByProfessional !== null) {
            $xml->createChildNode($parentNode, 'editable_by_user', boolToText($this->editableByProfessional));
        }
        if ($this->editableByCase !== null) {
            $xml->createChildNode($parentNode, 'editable_by_case', boolToText($this->editableByCase));
        }

        if ($this->gpsMapService !== null) {
            $mapNode = $xml->createChildNode($parentNode, 'map_service');
            $xml->createChildNode($mapNode, 'code', $this->gpsMapService);
        }

        $notificationsNode = $xml->createChildNode($parentNode, 'notifications');
        if (is_array($this->notificationChannels)) {
            $channels = implode(',', $this->notificationChannels);
            $xml->createChildNode($notificationsNode, 'channels', $channels);
        }
        if ($this->notificationEventPriority !== null) {
            $xml->createChildNode($notificationsNode, 'event_priority', $this->notificationEventPriority);
        }
        if ($this->notificationStartTime !== null) {
            $xml->createChildNode($notificationsNode, 'from_time', $this->notificationStartTime);
        }

        return $parentNode;
    }
}