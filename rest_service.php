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

$function = $_GET['function'];
$logger = ServiceLogger::init($GLOBALS['LOG_LEVEL'], $GLOBALS['LOG_DIR']);

$publicFunctions = ['import_redcap'];

if (in_array($function, $publicFunctions)) {
    $json = file_get_contents('php://input');
    try {
        $parameters = json_decode($json);
        if (trim($json) != '' && $parameters == null) {
            throw new Exception("Invalid parameters");
        }

        // The public rest function invoked from the Linkcare Platform's PROGRAM must be executed in a service session
        initServiceSession();
        $serviceResponse = $function($parameters);
    } catch (ServiceException $e) {
        $logger->error("Service Exception: " . $e->getErrorMessage());
        $serviceResponse = new BackgroundServiceResponse(BackgroundServiceResponse::ERROR, $e->getErrorMessage());
    } catch (Exception $e) {
        $logger->error("General exception: " . $e->getMessage());
        $serviceResponse = new BackgroundServiceResponse(BackgroundServiceResponse::ERROR, $e->getMessage());
    } catch (Error $e) {
        $logger->error("Execution error: " . $e->getMessage());
        $serviceResponse = new BackgroundServiceResponse(BackgroundServiceResponse::ERROR, $e->getMessage());
    } finally {
        WSAPI::apiDisconnect();
    }
} else {
    $serviceResponse = new BackgroundServiceResponse(BackgroundServiceResponse::ERROR, "Function $function not implemented");
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
function initServiceSession() {
    /* All the operations will be performed by a "service" user */
    WSAPI::apiConnect($GLOBALS["WS_LINK"], null, $GLOBALS["SERVICE_USER"], $GLOBALS["SERVICE_PASSWORD"], null, null, false,
            $GLOBALS["DEFAULT_LANGUAGE"], $GLOBALS["DEFAULT_TIMEZONE"]);
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
 * @return BackgroundServiceResponse
 */
function import_redcap($parameters) {
    $serviceResponse = new BackgroundServiceResponse(BackgroundServiceResponse::IDLE, "");

    $redcapFiles = glob(__DIR__ . '/redcap_data/*.csv');
    if (empty($redcapFiles)) {
        return new BackgroundServiceResponse(BackgroundServiceResponse::IDLE, "No RedCAP data files (*.csv) pending to import.");
    }

    $filePath = $redcapFiles[0]; // Get the first file found;
    $processFile = $filePath . '.processing';
    if (file_exists($processFile) && !unlink($processFile)) {
        return new BackgroundServiceResponse(BackgroundServiceResponse::IDLE, "Error deleting previous file: $processFile. Verify the the directory is writable.");
    }

    if (!rename($filePath, $processFile)) {
        return new BackgroundServiceResponse(BackgroundServiceResponse::IDLE, "Error renaming $filePath to $processFile. Verify the the directory is writable.");
    }

    $redCapData = ServiceFunctions::loadRedCAPData($processFile);
    foreach ($redCapData as $patientRef => $patientData) {
        try {
            ServiceFunctions::updatePatientData($patientRef, $patientData);
        } catch (Exception $e) {
            $serviceResponse->setCode(BackgroundServiceResponse::ERROR);
            $errorMsg = "Error importing RedCAP data for patient $patientRef: " . $e->getMessage();
            $serviceResponse->setMessage($errorMsg);
            $serviceResponse->addDetails("Patient $patientRef: ERROR");
            ServiceLogger::getInstance()->error($errorMsg);
            return $serviceResponse;
        }

        $msg = "Patient $patientRef: RedCAP data imported successfully.";
        ServiceLogger::getInstance()->info($msg, 1);
        echo " "; // Send space to the output buffer to avoid timeouts, because the processing of all patients can take a long time
        flush();
    }

    $msg = "RedCAP data imported successfully. Total patients processed: " . count($redCapData);
    $serviceResponse->setCode(BackgroundServiceResponse::SUCCESS);
    $serviceResponse->addDetails($msg);

    unlink($processFile); // Remove the processing file after processing

    return $serviceResponse;
}

function summary_report() {
    $sql = "SELECT patient_list.PATIENT, diagnose.NUM_POLYPS,diagnose.NUM_POLYPS-diagnose.NEOPLASIC AS NON_NEOPLASIC, diagnose.NEOPLASIC, carcinoma.SERRATED, risk.LOW_DISPLASIA, risk.HIGH_DISPLASIA, carcinoma.CARCINOMAS
        FROM
        	(
        		SELECT i.VALUE AS PATIENT
        		FROM
        			PATIENTS p, IDENTIFIERS i, ADMISSIONS a 
        		WHERE
        			a.DELETED IS NULL
        			AND p.ID_PATIENT = a.ID_PATIENT	
        			AND p.ID_CONTACT = i.ID_CONTACT
        			AND i.CODE = 'PARTICIPANT_REF'
        	) patient_list
        	LEFT JOIN		
        		-- Pacientes con NEOPLASIA (ADENOMA)
        		(
        			SELECT i.VALUE AS PATIENT,ii.ITEM_CODE,
        				COUNT(*) AS NUM_POLYPS,
        				SUM(CASE WHEN ii.ITEM_VALUE=2 THEN 1 ELSE 0 END) AS NEOPLASIC
        			FROM
        				PATIENTS p, IDENTIFIERS i, ADMISSIONS a, TASK_INSTANCES ti, FORM_INSTANCES fi, ITEM_INSTANCES ii 
        			WHERE
        				a.DELETED IS NULL
        				AND a.ID_ADMISSION = ti.ID_ADMISSION
        				AND p.ID_PATIENT = a.ID_PATIENT	
        				AND p.ID_CONTACT = i.ID_CONTACT
        				AND i.CODE = 'PARTICIPANT_REF'
        				AND ti.ID_TASK = fi.ID_TASK
        				AND fi.ID_FORM = ii.ID_FORM
        				AND ti.TASK_CODE = 'ANATHOMOPATOLOGICAL_REPORT'
        				AND ii.ITEM_CODE ='POLYOP_DIAGNOSE'
        				AND ii.EMPTY_ROW =0
        			GROUP BY i.VALUE
        		) diagnose ON patient_list.PATIENT = diagnose.PATIENT
        	LEFT JOIN		
        		-- Riesgo del ADENOMA
        		(
        			SELECT i.VALUE AS PATIENT,ii.ITEM_CODE,
        				SUM(CASE WHEN ii.ITEM_VALUE=1 THEN 1 ELSE 0 END) AS LOW_DISPLASIA,
        				SUM(CASE WHEN ii.ITEM_VALUE=2 THEN 1 ELSE 0 END) AS HIGH_DISPLASIA
        			FROM
        				PATIENTS p, IDENTIFIERS i, ADMISSIONS a, TASK_INSTANCES ti, FORM_INSTANCES fi, ITEM_INSTANCES ii 
        			WHERE
        				a.DELETED IS NULL
        				AND a.ID_ADMISSION = ti.ID_ADMISSION
        				AND p.ID_PATIENT = a.ID_PATIENT	
        				AND p.ID_CONTACT = i.ID_CONTACT
        				AND i.CODE = 'PARTICIPANT_REF'
        				AND ti.ID_TASK = fi.ID_TASK
        				AND fi.ID_FORM = ii.ID_FORM
        				AND ti.TASK_CODE = 'ANATHOMOPATOLOGICAL_REPORT'
        				AND ii.ITEM_CODE ='NEOPLASTIC_DYSPLASIA'
        				AND ii.EMPTY_ROW =0
        			GROUP BY i.VALUE
        		) risk ON patient_list.PATIENT = risk.PATIENT
        	LEFT JOIN			
        		-- CARCINOMA
        		(
        			SELECT i.VALUE AS PATIENT,ii.ITEM_CODE,
        				SUM(CASE WHEN ii.ITEM_VALUE=3 THEN 1 ELSE 0 END) AS CARCINOMAS,
        				SUM(CASE WHEN ii.ITEM_VALUE=2 THEN 1 ELSE 0 END) AS SERRATED
        			FROM
        				PATIENTS p, IDENTIFIERS i, ADMISSIONS a, TASK_INSTANCES ti, FORM_INSTANCES fi, ITEM_INSTANCES ii 
        			WHERE
        				a.DELETED IS NULL
        				AND a.ID_ADMISSION = ti.ID_ADMISSION
        				AND p.ID_PATIENT = a.ID_PATIENT	
        				AND p.ID_CONTACT = i.ID_CONTACT
        				AND i.CODE = 'PARTICIPANT_REF'
        				AND ti.ID_TASK = fi.ID_TASK
        				AND fi.ID_FORM = ii.ID_FORM
        				AND ti.TASK_CODE = 'ANATHOMOPATOLOGICAL_REPORT'
        				AND ii.ITEM_CODE ='NEOPLASTIC_TYPE'
        				AND ii.EMPTY_ROW =0
        			GROUP BY i.VALUE
        		) carcinoma ON patient_list.PATIENT = carcinoma.PATIENT
        ORDER BY patient_list.PATIENT";
}
