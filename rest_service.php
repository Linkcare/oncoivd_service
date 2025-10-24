<?php
error_reporting(E_ERROR); // Do not report warnings to avoid undesired characters in output stream

// Deactivate CORS for debug
$GLOBALS['DISABLE_CORS'] = true;
if ($GLOBALS['DISABLE_CORS']) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: X-API-token, Content-Type');
    header('Access-Control-Allow-Methods: POST, OPTIONS');

    // If it is a preflight request, respond with 204 No Content
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit();
    }
}

// Link the config params
require_once ("src/default_conf.php");

setSystemTimeZone();
error_reporting(0);

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    return;
}

// Response is always returned as JSON
header('Content-type: application/json');

$requestHeaders = array_change_key_case(getallheaders(), CASE_LOWER);
// Check if the request contains the API token in the headers
$authToken = array_key_exists('x-api-token', $requestHeaders) ? $requestHeaders['x-api-token'] : null;

$function = $_GET['function'];
$logger = ServiceLogger::init($GLOBALS['LOG_LEVEL'], $GLOBALS['LOG_DIR']);

$systemFunctions = ['deploy_service'];
$programFunctions = ['add_aliquots'];
$shipmentManagementFunctions = [shipment_locations, 'shipment_list', 'shipment_create', 'shipment_details', 'shippable_aliquots', 'find_aliquot',
        'shipment_add_aliquot', 'shipment_remove_aliquot', 'shipment_update', 'shipment_send', 'shipment_start_reception', 'shipment_finish_reception',
        'shipment_delete', 'shipment_set_aliquot_condition'];

$publicFunctions = array_merge($systemFunctions, $programFunctions, $shipmentManagementFunctions);

if (in_array($function, $publicFunctions)) {
    $json = file_get_contents('php://input');
    try {
        $parameters = json_decode($json);
        if (trim($json) != '' && $parameters == null) {
            throw new Exception("Invalid parameters");
        }

        // The public rest function invoked from the Linkcare Platform's PROGRAM must be executed in a service session
        $surrogate = in_array($function, $programFunctions);
        initServiceSession($authToken, $parameters, $surrogate);
        Database::init($GLOBALS['SERVICE_DB_URI'], $logger);
        Database::getInstance()->beginTransaction(); // Execute all commands in transactional mode
        $serviceResponse = $function($parameters);
        Database::getInstance()->commit();
    } catch (ServiceException $e) {
        if (Database::getInstance()) {
            Database::getInstance()->rollback();
        }
        $logger->error("Service Exception: " . $e->getErrorMessage());
        $serviceResponse = new ServiceResponse(null, $e->getErrorMessage());
    } catch (Exception $e) {
        if (Database::getInstance()) {
            Database::getInstance()->rollback();
        }
        $logger->error("General exception: " . $e->getMessage());
        $serviceResponse = new ServiceResponse(null, $e->getMessage());
    } catch (Error $e) {
        if (Database::getInstance()) {
            Database::getInstance()->rollback();
        }
        $logger->error("Execution error: " . $e->getMessage());
        $serviceResponse = new ServiceResponse(null, $e->getMessage());
    } finally {
        WSAPI::apiDisconnect();
    }
} else {
    $serviceResponse = new ServiceResponse(null, "Function $function not implemented");
}

echo $serviceResponse->toString();
return;

/**
 * Initializes an API session.
 * If a valid session token is provided, then the timezone and language of that session will be used
 * for the service session
 *
 * @param string $authToken Session token provided in the request headers
 * @param stdClass $parameters Parameters provided in the request body
 * @param bool $surrogateSession If true, the user session will be surrogated by the SERVICE_USER
 */
function initServiceSession($authToken, $parameters, $surrogateSession = true) {
    if (!$authToken) {
        ServiceLogger::getInstance()->trace("No API token provided in the request headers. Use the session token provided in the parameters");
        // If an API token was not provided in the request headers, then check if it is provided in the parameters
        $authToken = loadParam($parameters, 'session');
    } else {
        ServiceLogger::getInstance()->trace("API token provided in the request headers");
    }

    /* Join the session of the user that is calling the service to retrieve the timezone and language */
    $apiSession = WSAPI::apiConnect($GLOBALS["WS_LINK"], $authToken);
    $userLanguage = $apiSession->getSession()->getLanguage();
    $userTimezone = $apiSession->getSession()->getTimezone();

    /* All the operations will be performed by a "service" user */
    if ($surrogateSession) {
        ServiceLogger::getInstance()->trace("A Service session will be created using the timezone and language of the user session");
        WSAPI::apiConnect($GLOBALS["WS_LINK"], null, $GLOBALS["SERVICE_USER"], $GLOBALS["SERVICE_PASSWORD"], null, null, false, $userLanguage,
                $userTimezone);
    }
}

/* ****************************************************************** */
/* ********************* PUBLIC REST FUNCTIONS ********************** */
/* ****************************************************************** */

/**
 * Adds a new set of aliquots of a patient.
 * This function is used after a laboratory processes the blood samples extracted from a patient.<br>
 * The function expects that the necessary FORMS to hold the new list of aliquots are already created into the same TASK, and the FORM CODES for each
 * type of blood sample are:
 * - WHOLE_BLOOD: "WHOLE_BLOOD_STATUS_FORM"
 * - PLASMA: "PLASMA_STATUS_FORM"
 * - PBMC: "PBMC_STATUS_FORM"
 * - SERUM: "SERUM_STATUS_FORM"
 *
 * @param stdClass $parameters
 */
function add_aliquots($parameters) {
    // Reference of the FORM that is initializing a shipment
    $bloodProcessingFormId = loadParam($parameters, 'processing_form');
    // Reference of the TEAM (laborarory) that has processed the blood samples and generated the aliquots
    $patientId = loadParam($parameters, 'patient');
    $patientRef = loadParam($parameters, 'patient_ref');
    $labTeamId = loadParam($parameters, 'lab_team');
    $procDate = loadParam($parameters, 'date');
    $procTime = loadParam($parameters, 'time');

    return ServiceFunctions::addAliquots($patientId, $patientRef, $bloodProcessingFormId, $labTeamId, $procDate, $procTime);
}

