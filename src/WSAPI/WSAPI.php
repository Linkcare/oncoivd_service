<?php
$GLOBALS['WSAPI_VERSION'] = "1.1";

require_once ("DateHelper.php");
require_once ("APIException.php");
require_once ("LinkcareSoapAPI.php");
require_once ("APIResponse.php");
require_once ("XMLHelper.php");
require_once ("ConstantsBase.php");

$requires = glob(__DIR__ . '/objects/*.php');
$requires = array_merge($requires, glob(__DIR__ . '/constants/*.php'));
$requires = array_merge($requires, glob(__DIR__ . '/SupportClasses/*.php'));

foreach ($requires as $filename) {
    require_once ($filename);
}

abstract class WSAPI {

    /**
     * Connects to the WS-API using the session $token passed as parameter
     *
     * @param string $endpoint
     * @param string $token
     * @param string $user
     * @param string $password
     * @param int $role
     * @param string $team
     * @param bool $reuseExistingSession
     * @param string $language
     * @param string $timezone
     *
     * @throws APIException
     * @throws Exception
     * @return LinkcareSoapAPI
     */
    static public function apiConnect($endpoint, $token, $user = null, $password = null, $role = null, $team = null, $reuseExistingSession = false,
            $language = null, $timezone = null) {
        $session = null;

        try {
            LinkcareSoapAPI::setEndpoint($endpoint);
            if ($token) {
                LinkcareSoapAPI::session_join($token, $timezone);
            } else {
                LinkcareSoapAPI::session_init($user, $password, $timezone, $reuseExistingSession, $language);
            }

            $session = LinkcareSoapAPI::getInstance()->getSession();
        } catch (APIException $e) {
            throw $e;
        } catch (Exception $e) {
            throw $e;
        }

        try {
            // Ensure to set the correct active ROLE and TEAM
            if ($team && $team != $session->getTeamCode() && $team != $session->getTeamId()) {
                LinkcareSoapAPI::getInstance()->session_set_team($team);
            }
            if ($role && $session->getRoleId() != $role) {
                LinkcareSoapAPI::getInstance()->session_role($role);
            }
            if ($language && $session->getLanguage() != $language) {
                LinkcareSoapAPI::getInstance()->session_set_language($language);
            }
            if ($timezone && $session->getTimezone() != $timezone) {
                LinkcareSoapAPI::getInstance()->session_set_timezone($timezone);
            }
        } catch (APIException $e) {
            throw $e;
        } catch (Exception $e) {
            throw $e;
        }
        return LinkcareSoapAPI::getInstance();
    }

    static public function apiDisconnect() {
        $api = LinkcareSoapAPI::getInstance();
        if (!$api) {
            return;
        }
        $api->session_close();
    }
}