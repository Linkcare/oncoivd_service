<?php
error_reporting(E_ERROR); // Do not report warnings to avoid undesired characters in output stream

require_once $_SERVER["DOCUMENT_ROOT"] . '/vendor/autoload.php';
use avadim\FastExcelReader\Excel;

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

$publicFunctions = ['import_redcap', 'import_aliquots', 'track_pending_shipments', 'track_pending_receptions'];

if (in_array($function, $publicFunctions)) {
    $json = file_get_contents('php://input');
    try {
        $parameters = json_decode($json);
        if (trim($json) != '' && $parameters == null) {
            throw new Exception("Invalid parameters");
        }

        // The public rest function invoked from the Linkcare Platform's PROGRAM must be executed in a service session
        initServiceSession();
        Database::init($GLOBALS['SERVICE_DB_URI'], $logger);
        Database::getInstance()->beginTransaction(); // Execute all commands in transactional mode
        $serviceResponse = $function($parameters);
        Database::getInstance()->commit();
    } catch (ServiceException $e) {
        if (Database::getInstance()) {
            Database::getInstance()->rollback();
        }
        $logger->error("Service Exception: " . $e->getErrorMessage());
        $serviceResponse = new BackgroundServiceResponse(BackgroundServiceResponse::ERROR, $e->getErrorMessage());
    } catch (Exception $e) {
        if (Database::getInstance()) {
            Database::getInstance()->rollback();
        }
        $logger->error("General exception: " . $e->getMessage());
        $serviceResponse = new BackgroundServiceResponse(BackgroundServiceResponse::ERROR, $e->getMessage());
    } catch (Error $e) {
        if (Database::getInstance()) {
            Database::getInstance()->rollback();
        }
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
 * Imports the data exported from the RedCAP platform updates the patients information in the eCRF.
 * If the patient doesn't exist, then a new ADMISSION is created
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

/**
 * Adds a new set of aliquots of a patient.
 * The information about the aliquots is imported from an Excel file provided by IGTP<br>
 *
 * @param stdClass $parameters
 * @return BackgroundServiceResponse
 */
function import_aliquots($parameters) {
    $serviceResponse = new BackgroundServiceResponse(BackgroundServiceResponse::IDLE, "");
    $numErrors = 0;
    $numSuccessful = 0;
    $numSkipped = 0;
    $executionResult = BackgroundServiceResponse::SUCCESS;

    $filesToImport = glob(aliquots_data . '/*.xlsx');
    if (empty($filesToImport)) {
        $msg = "No Aliquots data files (*.xlsx) pending to import.";
        ServiceLogger::getInstance()->info($msg);
        return new BackgroundServiceResponse(BackgroundServiceResponse::IDLE, $msg);
    }

    $filePath = $filesToImport[0]; // Process only one file
    $serviceResponse->addDetails("Importing aliquots from file " . basename($filePath));
    $processFile = $filePath . '.processing';
    $logFile = $filePath . '.log';
    ServiceLogger::getInstance()->setCustomLogFile($logFile);

    if (file_exists($processFile) && !unlink($processFile)) {
        $msg = "Error deleting previous file: $processFile. Verify the the directory is writable.";
        ServiceLogger::getInstance()->error($msg);
        return new BackgroundServiceResponse(BackgroundServiceResponse::ERROR, $msg);
    }

    if (!rename($filePath, $processFile)) {
        $msg = "Error renaming $filePath to $processFile. Verify the the directory is writable.";
        ServiceLogger::getInstance()->error($msg);
        return new BackgroundServiceResponse(BackgroundServiceResponse::ERROR, $msg);
    }

    $importedData = ServiceFunctions::loadBloodProcessingData($processFile, $GLOBALS['TEAM_CODE']);
    foreach ($importedData as $patientSamples) {
        try {
            /** @var APICase $patient */
            $patient = $patientSamples['patient'];
            /** @var APIAdmission $admission */
            $admission = $patientSamples['admission'];
            /** @var APITask $bpTask */
            $bpTask = $patientSamples['task'];
            /** @var APIForm $bpForm */
            $bpForm = $patientSamples['form'];
            $sampleDate = $patientSamples['sampleDate'];
            $sampleStartTime = $patientSamples['sampleStartTime'];
            $sampleEndTime = $patientSamples['sampleEndTime'];
            $displayName = $patientSamples['displayName'];
            if ($loadError = $patientSamples['error']) {
                throw new Exception($loadError);
            }

            $admission->setEnrolDate($sampleDate);
            $admission->setAdmissionDate($sampleDate);
            $admission->save();
            if (!$bpForm) {
                $msg = "Sample $displayName skipped. Aliquots already loaded (BLOOD PROCESSING task exists and is closed)";
                $serviceResponse->addDetails($msg);
                ServiceLogger::getInstance()->info($msg);
                $numSkipped++;
                continue;
            }

            $aliquotIds = [];
            foreach (array_values($patientSamples['samples']) as $ids) {
                $aliquotIds = array_merge($aliquotIds, $ids);
            }

            ServiceFunctions::updateBloodProcessingData($bpTask, $bpForm, $patientSamples['samples'], $sampleDate, $sampleStartTime, $sampleEndTime);

            $msg = "Sample $displayName: Imported successfully.";
            $serviceResponse->addDetails($msg);
            ServiceLogger::getInstance()->info($msg);
            $numSuccessful++;
        } catch (Exception $e) {
            $executionResult = BackgroundServiceResponse::ERROR;
            $msg = "Sample $displayName: ERROR " . $e->getMessage();
            $serviceResponse->addDetails($msg);
            ServiceLogger::getInstance()->error($msg);
            $numErrors++;
        }

        echo " "; // Send space to the output buffer to avoid timeouts, because the processing of all patients can take a long time
        flush();
    }

    if ($executionResult == BackgroundServiceResponse::ERROR) {
        $msg = "Aliquots import process finished. Errors: $numErrors, Successful: $numSuccessful, Skipped: $numSkipped, Total samples processed: " .
                count($importedData);
        $serviceResponse->setMessage($msg);
    } else {
        $msg = "Aliquots import process finished successfully. Errors: $numErrors, Successful: $numSuccessful, Skipped: $numSkipped, Total samples processed: " .
                count($importedData);
        $serviceResponse->setMessage($msg);
    }

    $serviceResponse->setCode($executionResult);

    if ($executionResult == BackgroundServiceResponse::SUCCESS) {
        unlink($processFile); // Remove the processing file after processing
    } else {
        rename($processFile, str_replace('.processing', '.error', $processFile)); // Rename the processing file to .error to avoid reprocessing);
    }

    return $serviceResponse;
}

/**
 * Verifies if there are new blood sample shipments created from the Shipment Control application that need to be tracked in the eCRF.
 * If true, a new "SHIPMENT TRACKING" TASK will be created in each affected ADMISSION of the eCRF
 *
 * @param stdClass $parameters
 * @return BackgroundServiceResponse
 */
function track_pending_shipments($parameters) {
    $response = new BackgroundServiceResponse(BackgroundServiceResponse::IDLE, "");

    // Find the shipped aliquots that have not been tracked yet in the eCRF
    $untrackedShipments = untrackedShipments();

    if (empty($untrackedShipments)) {
        return new BackgroundServiceResponse(BackgroundServiceResponse::IDLE, 'No shipments pending to be tracked.');
    }

    $shipment = null;
    $numSuccess = 0;
    $numErrors = 0;
    foreach ($untrackedShipments as $shipmentData) {
        /** @var Shipment $shipment */
        $shipment = $shipmentData['shipment'];
        $patientIdsInShipment = $shipmentData['patients'];
        $shipmentId = $shipment->id;

        $patientsSuccess = 0;
        $patientsError = 0;
        foreach ($patientIdsInShipment as $data) {
            $patientId = $data['patientId'];
            $patientRef = $data['patientRef'];
            try {
                ServiceFunctions::createShipmentTrackingTask($shipment, $patientId);
                $msg = "Patient $patientRef: Shipment with ID $shipmentId tracked succsessfully in eCRF";
                $response->addDetails($msg);
                $patientsSuccess++;
            } catch (ServiceException $e) {
                $msg = "ERROR Patient $patientRef: Shipment with ID $shipmentId failed to be tracked in eCRF" . $e->getErrorMessage();
                $response->addDetails($msg);
                $patientsError++;
            }
        }
        $msg = "SHIPMENT $shipmentId updated: patients success: $patientsSuccess, errors: $patientsError";
        $response->addDetails($msg);
        if ($patientsError > 0) {
            $numErrors++;
        } else {
            $numSuccess++;
        }
    }

    if ($numErrors > 0) {
        $retCode = BackgroundServiceResponse::ERROR;
    } elseif ($numSuccess > 0) {
        $retCode = BackgroundServiceResponse::SUCCESS;
    } else {
        $retCode = BackgroundServiceResponse::IDLE;
    }
    $response->setMessage("Shipments updated successfully: $numSuccess, Errors: $numErrors");
    $response->setCode($retCode);

    return $response;
}

/**
 * Verifies if there are blood sample shipments marked as received (from the Shipment Control application) that need to be tracked in the eCRF.
 * If true, a new "RECEPTION TRACKING" TASK will be created in each affected ADMISSION of the eCRF
 *
 * @param stdClass $parameters
 * @return BackgroundServiceResponse
 */
function track_pending_receptions($parameters) {
    $response = new BackgroundServiceResponse(BackgroundServiceResponse::IDLE, "");

    // Find the shipped aliquots that have not been tracked yet in the eCRF
    $untrackedReceptions = untrackedReceptions();
    if (empty($untrackedReceptions)) {
        return new BackgroundServiceResponse(BackgroundServiceResponse::IDLE, 'No shipment receptions pending be tracked.');
    }

    $shipment = null;
    $numSuccess = 0;
    $numErrors = 0;
    foreach ($untrackedReceptions as $shipmentData) {
        /** @var Shipment $shipment */
        $shipment = $shipmentData['shipment'];
        $patientIdsInShipment = $shipmentData['patients'];
        $shipmentId = $shipment->id;

        $patientsSuccess = 0;
        $patientsError = 0;
        foreach ($patientIdsInShipment as $data) {
            $patientId = $data['patientId'];
            $patientRef = $data['patientRef'];
            $trackingTaskId = $data['trackingTaskId'];

            try {
                ServiceFunctions::createReceptionTrackingTask($shipment, $patientId, $trackingTaskId);
                $msg = "Patient $patientRef: Shipment with ID $shipmentId tracked succsessfully in eCRF";
                $response->addDetails($msg);
                $patientsSuccess++;
            } catch (ServiceException $e) {
                $msg = "ERROR Patient $patientRef: Shipment with ID $shipmentId failed to be tracked in eCRF" . $e->getErrorMessage();
                $response->addDetails($msg);
                $patientsError++;
            }
        }
        $msg = "SHIPMENT $shipmentId updated: patients success: $patientsSuccess, errors: $patientsError";
        $response->addDetails($msg);
        if ($patientsError > 0) {
            $numErrors++;
        } else {
            $numSuccess++;
        }
    }

    $retCode = $numErrors > 0 ? BackgroundServiceResponse::ERROR : BackgroundServiceResponse::SUCCESS;

    $response->setMessage("Shipment receptions updated successfully: $numSuccess, Errors: $numErrors");
    $response->setCode($retCode);

    return $response;
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
