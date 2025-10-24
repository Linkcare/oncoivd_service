<?php
require_once $_SERVER["DOCUMENT_ROOT"] . '/vendor/autoload.php';
use avadim\FastExcelReader\Excel;

class ServiceFunctions {

    /**
     * Loads the RedCAP data from a CSV file.
     * Returns an associative 2-dimensional array. The first dimension is the patient reference, and the second dimension is the field name.
     *
     * @param string $filePath
     * @return string[][]|array[]
     */
    static public function loadRedCAPData($filePath) {
        $file = fopen($filePath, 'r');
        if (!$file) {
            throw new ServiceException(ErrorCodes::UNEXPECTED_ERROR, 'Error opening file for reading: ' . $filePath);
        }

        try {
            $rawPatientsData = [];
            $header = fgetcsv($file, null, ';');
            $header[0] = removeBomUtf8($header[0]);
            if ($header[0] != 'study_ref') {
                // The first column is the study reference. Remove it from the header
                throw new ServiceException(ErrorCodes::INVALID_DATA_FORMAT, 'The first column of the RedCAP CSV file must be "study_ref". Found: ' .
                        $header[0]);
            }
            if (!$header) {
                throw new ServiceException(ErrorCodes::INVALID_DATA_FORMAT, 'Error reading header from file: ' . $filePath);
            }

            while (($row = fgetcsv($file, null, ';')) !== false) {
                if (count($row) != count($header)) {
                    throw new ServiceException(ErrorCodes::INVALID_DATA_FORMAT, 'Row length does not match header length in file: ' . $filePath);
                }
                $rowData = array_combine($header, $row);
                $patientRef = "ONCOIVD_" . sprintf("%03d", $rowData['study_ref']);
                unset($rowData['study_ref']);
                unset($rowData['redcap_event_name']); // RedCAP Form name

                $rawPatientsData[$patientRef] = array_key_exists($patientRef, $rawPatientsData) ? self::mergeRedCAPPatientData($rowData,
                        $rawPatientsData[$patientRef], $patientRef) : $rowData;
            }
        } catch (Exception $e) {
            throw $e;
        } finally {
            fclose($file);
        }

        /*
         * Now we will preprocess the raw data to normalize variables that are Check boxes and ARRAYs
         * -CHECK BOXES:
         * The values of fields that are Check boxes are stored as a list of separate variables, where each variable represents one of the available.
         * We will generate an array with the selected options of the checkbox
         * - ARRAYS
         * The values of fields that are part of an ARRAY are stored as separate variables, where the variable name is the name of the field plus a
         * suffix that indicates the row number.
         * Row 1 doesn't have a suffix, row 2 has "_2" suffix, etc.
         * We will generate a RedCAPArray object with all the rows. Each Row will be composed by an array of variables.
         */
        $processedPatientsData = $rawPatientsData;
        $checkBoxFields = [];
        $arrays = [];
        foreach (RedCAPMapping::getTaskCodes() as $taskCode) {
            foreach (RedCAPMapping::getFormCodes($taskCode) as $formCode) {
                $formItems = RedCAPMapping::getFormMappings($formCode);
                foreach ($formItems as $itemCode => $itemProperties) {
                    $redCapField = $itemProperties['redCAP'];
                    $questionType = $itemProperties['type'];
                    $valueMapping = $itemProperties['value_mapping']; // Map of RedCAP values to Linkcare values
                    if ($valueMapping) {
                        $mappedValues[$redCapField] = $valueMapping;
                    }
                    $arrayRef = $itemProperties['arrayRef'] ?? null;
                    if ($arrayRef) {
                        $arrays[$formCode . "@" . $arrayRef][$itemCode] = $itemProperties;
                    }
                    if (APIQuestionTypes::isMultiOptions($questionType)) {
                        $checkBoxFields[$redCapField] = $redCapField;
                    }
                }
            }
        }

        foreach ($processedPatientsData as $patientRef => $patientData) {
            foreach ($mappedValues as $redCapField => $valueMapping) {
                if (array_key_exists($redCapField, $patientData)) {
                    // Map the RedCAP value to the Linkcare value
                    $processedPatientsData[$patientRef][$redCapField] = $valueMapping[$patientData[$redCapField]] ?? $patientData[$redCapField];
                }
            }

            $patientCheckBoxFields = $checkBoxFields;
            /* Process the ARRAYs */
            foreach ($arrays as $arrayRef => $rowItems) {
                $arrayData = [];
                foreach ($rowItems as $itemCode => $itemProperties) {
                    $redCapField = $itemProperties['redCAP'];
                    $questionType = $itemProperties['type'];
                    $rowIx = 1;
                    $fullVarName = $redCapField;
                    while (array_key_exists($fullVarName, $patientData)) {
                        if (array_key_exists($redCapField, $patientCheckBoxFields)) {
                            $arrayData[$rowIx][$itemCode] = ServiceFunctions::collectSelectedOptions($fullVarName, $patientData);
                            unset($patientCheckBoxFields[$redCapField]);
                        } else {
                            $arrayData[$rowIx][$itemCode] = $patientData[$fullVarName];
                        }
                        unset($processedPatientsData[$patientRef][$fullVarName]);
                        $rowIx++;
                        $fullVarName = $redCapField . "_" . $rowIx;
                    }
                }

                // Remove empty rows
                foreach ($arrayData as $rowIx => $rowData) {
                    if (empty(array_filter($rowData))) {
                        unset($arrayData[$rowIx]);
                    }
                }
                $processedPatientsData[$patientRef][$arrayRef] = $arrayData;
            }

            /* Process the Checkboxes */
            foreach ($patientCheckBoxFields as $fieldName) {
                // Collect the selected options of the checkbox
                $selectedOptions = ServiceFunctions::collectSelectedOptions($fieldName, $patientData);
                $processedPatientsData[$patientRef][$fieldName] = $selectedOptions;
            }
        }

        return $processedPatientsData;
    }

    /**
     * Loads the data provided by IGTP about the blood processig of the patients from an Excel file.
     * The returned value is a multi dimensional array indexed by patient reference and aliquot type.
     * The contents of each item is an array with the IDs of the aliquots of that type for that patient.
     * Example:
     * ['PAT001' => [
     * ···'patient' => APIPatient,
     * ···'admission' => APIAdmission,
     * ···'form' => APIForm (blood processing form),
     * ···'samples' => [
     * ·····'whole_blood' => [aliquot_id1, aliquot_id2, ...],
     * ·····'plasma' => [aliquot_id3, aliquot_id4, ...],
     * ···]
     * ··]
     * ]
     *
     * @param string $processFile
     * @param string $teamCode Code of the team owner of the Subscription
     * @return array Associative array with the IDs of the aliquots indexed by patient reference / aliquot type
     */
    public function loadBloodProcessingData($processFile, $teamCode) {
        try {
            $excel = Excel::open($processFile);
        } catch (Exception $e) {
            throw new ServiceException("Error opening file: $processFile: " . $e->getMessage());
        }

        $filename = basename($processFile);
        $excel->dateFormatter('Y-m-d');
        $sheet = $excel->sheet(0);
        if (!$sheet) {
            throw new ServiceException("Sheet 'Datos' not found in file: $processFile");
        }

        // OR
        $patientSamples = [];
        $prevPatientRef = null;
        foreach ($sheet->nextRow([], Excel::KEYS_FIRST_ROW) as $rowNum => $rowData) {
            // sample_id order_id sample_type collection_date plate position plate_location plate_collection plate_delivery patient_id volume
            // haemolysis
            // plate_type key
            $patientRef = trim($rowData['ID Caso']);
            if (!$patientRef) {
                // The information about the patient is provided in several rows, and only the first one contains the reference of the patient
                $patientRef = $prevPatientRef;
            } else {
                $patientRef = sprintf("ONCOIVD_%03d", $patientRef);
                $sampleDate = trim($rowData['Fecha obtención muestra']);
                $sampleType = trim($rowData['MUESTRA']);
            }
            $prevPatientRef = $patientRef;

            if (!$patientRef) {
                throw new ServiceException("Patient reference (column 'ID Caso') not informed in file $filename, row: $rowNum");
            }
            if (!$sampleDate) {
                throw new ServiceException("Sample date (column 'Fecha obtención muestra') not informed for patient $patientRef in file $filename, row: $rowNum");
            }
            if (!$sampleType) {
                throw new ServiceException("Sample type (column 'MUESTRA') not informed for patient $patientRef in file $filename, row: $rowNum");
            }

            $aliquotId = trim($rowData['ID 2Ddatamatrix (Alícuota)']);
            if (!$aliquotId) {
                throw new ServiceException("Aliquot Id (column 'ID 2Ddatamatrix (Alícuota)') not informed for patient $patientRef, sample: $sampleType in file $filename, row: $rowNum");
            }

            $patientSamples[$patientRef]['sampleDate'] = $sampleDate;
            $patientSamples[$patientRef]['sampleStartTime'] = '00:00:00';
            $patientSamples[$patientRef]['sampleEndTime'] = '00:00:00';

            switch (strtolower(substr($sampleType, 0, 2))) {
                case 'wh' :
                    $type = 'WHOLE_BLOOD';
                    break;
                case 'pl' :
                    $type = 'PLASMA';
                    break;
                case 'pb' :
                    $type = 'PBMC';
                    break;
                case 'se' :
                    $type = 'SERUM';
                    break;
                default :
                    throw new ServiceException("Unknown sample type: " . $sampleType . "in file $filename, row: $rowNum");
            }
            if (array_key_exists($patientRef, $patientSamples) && array_key_exists($type, $patientSamples[$patientRef]['samples'])) {
                $patientSamples[$patientRef]['samples'][$type][] = $aliquotId;
            } else {
                $patientSamples[$patientRef]['samples'][$type] = [$aliquotId];
            }
        }

        // Find in the eCRF the patient and blood processing form that corresponds to each blood sample
        foreach (array_keys($patientSamples) as $patientRef) {
            try {
                list($patient, $admission, $bpTask, $bpForm) = ServiceFunctions::findFormFromPatientRef($patientRef, $teamCode);
                $patientSamples[$patientRef]['patient'] = $patient;
                $patientSamples[$patientRef]['admission'] = $admission;
                $patientSamples[$patientRef]['task'] = $bpTask;
                $patientSamples[$patientRef]['form'] = $bpForm;
                $patientSamples[$patientRef]['displayName'] = $patientRef;
            } catch (Exception $e) {
                $patientSamples[$patientRef]['displayName'] = $patientRef;
                $patientSamples[$patientRef]['error'] = $e->getMessage();
            }
        }

        return $patientSamples;
    }

    /**
     *
     * @param string $patientId eCRF internal reference of the patient whose blood samples are being processed
     * @param string $patientId Reference of the patient whose blood samples are being processed
     * @param string $bloodProcessingFormId Reference of the FORM with the aliquots to be added
     * @param string $labTeamId Reference of the TEAM that has processed the blood samples
     * @param string $procDate Blood processing date
     * @param string $procTime Blood processing time
     * @return ServiceResponse
     */
    static public function addAliquots($patientId, $patientRef, $bloodProcessingFormId, $labTeamId, $procDate, $procTime, $overwriteExisting = false) {
        $api = LinkcareSoapAPI::getInstance();

        self::addLocation($labTeamId);

        // Load the FORM that contains the new processed aliquots that must be added
        $processingForm = $api->form_get_summary($bloodProcessingFormId);
        $containerTaskActivities = $api->task_activity_list($processingForm->getParentId());
        foreach ($containerTaskActivities as $taskActivity) {
            if (!$taskActivity instanceof APIForm) {
                continue;
            }
            $forms[$taskActivity->getFormCode()] = $taskActivity;
        }

        $sampleTypesList = ['WHOLE_BLOOD', 'PLASMA', 'PBMC', 'SERUM'];

        if (!$procDate) {
            throw new ServiceException(ErrorCodes::DATA_MISSING, "The processing date of the blood samples is missing");
        }
        if (!$procTime) {
            throw new ServiceException(ErrorCodes::DATA_MISSING, "The processing time of the blood samples is missing");
        }

        // The time is stored in the local timezone. We need to convert it to UTC
        $localTime = DateHelper::compose($procDate, $procTime);
        $procDateUTC = DateHelper::localToUTC($localTime, $api->getSession()->getTimezone());

        $aliquotsIncluded = [];

        $dbRows = [];
        foreach ($sampleTypesList as $sampleType) {
            $statusFormCode = $sampleType . '_STATUS_FORM';
            // Check if there are aliquots of this sample type
            $aliquotsArray = $processingForm->getArrayQuestions($sampleType . '_ARRAY');
            if (count($aliquotsArray) == 0) {
                continue;
            }
            // Verify that the STATUS FORM to store the aliquots exists
            if (!array_key_exists($statusFormCode, $forms)) {
                throw new ServiceException(ErrorCodes::FORM_MISSING, "The FORM $statusFormCode does not exist in the blood processing task. It is not possible to store the processed aliquots");
            }

            $destStatusForm = $forms[$statusFormCode];

            $destArrayHeader = $destStatusForm->findQuestion(AliquotStatusItems::ARRAY);
            if (!$destArrayHeader) {
                throw new ServiceException(ErrorCodes::DATA_MISSING, "The array of aliquots $sampleType does not exist in the status form");
            }

            // Load the existing aliquots of the status FORM. Additional rows will be appended for the new aliquots
            $existingAliquotsArray = $overwriteExisting ? [] : $destStatusForm->getArrayQuestions(AliquotStatusItems::ARRAY);

            $questionsArray = [];
            $questionsArray[] = self::updateTextQuestionValue($destStatusForm, AliquotStatusItems::SAMPLE_TYPE, $sampleType);

            foreach ($existingAliquotsArray as $row) {
                foreach ($row as $question) {
                    $questionsArray[] = $question;
                }
            }

            // Add the new aliquots to the status form
            $ix = count($existingAliquotsArray) + 1;
            if ($overwriteExisting) {
                error_log("Overwriting samples status in FORM " . $destStatusForm->getId() . " for sample type $sampleType. Starting at row $ix");
            }

            foreach ($aliquotsArray as $row) {
                $dbColumns = [];
                /** @var APIQuestion[] $row */
                $aliquotId = $row[$sampleType . "_" . AliquotStatusItems::ID]->getValue();
                $aliquotIds[] = $aliquotId;
                $dbColumns['ID_ALIQUOT'] = $aliquotId;
                $dbColumns['ID_PATIENT'] = $patientId;
                $dbColumns['PATIENT_REF'] = $patientRef;
                $dbColumns['SAMPLE_TYPE'] = $sampleType;
                $dbColumns['ID_LOCATION'] = $labTeamId;
                $dbColumns['ID_STATUS'] = AliquotStatus::AVAILABLE;
                $dbColumns['ID_TASK'] = $processingForm->getParentId();
                $dbColumns['ALIQUOT_CREATED'] = $procDateUTC;
                $dbColumns['ALIQUOT_UPDATED'] = $procDateUTC;

                $questionsArray[] = self::updateArrayTextQuestionValue($destStatusForm, $destArrayHeader->getId(), $ix, AliquotStatusItems::ID,
                        $aliquotId);
                $questionsArray[] = self::updateArrayTextQuestionValue($destStatusForm, $destArrayHeader->getId(), $ix,
                        AliquotStatusItems::CREATION_DATE, DateHelper::datePart($procDateUTC));
                $questionsArray[] = self::updateArrayTextQuestionValue($destStatusForm, $destArrayHeader->getId(), $ix,
                        AliquotStatusItems::CREATION_TIME, DateHelper::timePart($procDateUTC));
                $questionsArray[] = self::updateArrayOptionQuestionValue($destStatusForm, $destArrayHeader->getId(), $ix, AliquotStatusItems::LOCATION,
                        null, $labTeamId);
                $ix++;
                $dbRows[] = $dbColumns;
            }

            // Remove null entries
            $questionsArray = array_filter($questionsArray);

            if (!empty($questionsArray)) {
                $api->form_set_all_answers($destStatusForm->getId(), $questionsArray, false);
            }
        }

        self::trackAliquots($dbRows);

        // Concatenate the added aliquot IDs into a string
        $aliquotsIncluded = implode(',', $aliquotIds);
        return new ServiceResponse($aliquotsIncluded, null);
    }

    /**
     * The RedCAP csv file contains several lines for the same patient.
     * This function merges the new data with the previous data for the same patient.
     *
     * @param string[] $newData
     * @param string[] $prevData
     * @return string[]
     */
    static private function mergeRedCAPPatientData($newData, $prevData, $patientRef) {
        $mergedData = $prevData;
        foreach ($newData as $key => $value) {
            if (!array_key_exists($key, $prevData)) {
                // If the key does not exist in the previous data, add it
                $mergedData[$key] = $value;
                continue;
            }
            if ($value === '' || $value === null) {
                // If the new value is empty, keep the previous value
                continue;
            }
            $prevValue = $prevData[$key];
            if ($prevValue !== '' && $prevValue !== null && $prevValue !== $value) {
                /*
                 * If both the previous value and the new values are not empty and are different, it is an error.
                 * Each line should have non-conflicting values
                 */
                throw new ServiceException(ErrorCodes::DATA_MISSING, "Patient: $patientRef. Conflicting data in different lines for key '$key': previous value '$prevValue', new value '$value'");
            }
            $mergedData[$key] = $value;
        }

        return $mergedData;
    }

    /**
     */
    static public function updatePatientData($patientRef, $RedCAPData) {
        static $subscriptionId = null;

        $api = LinkcareSoapAPI::getInstance();
        if (!$subscriptionId) {
            // Find the subscription ID of the ONCOIVD program
            try {
                $subscription = $api->subscription_get($GLOBALS['PROJECT_CODE'], $GLOBALS['TEAM_CODE']);
            } catch (APIException $e) {
                throw new ServiceException(ErrorCodes::DATA_MISSING, "Unable to find subscription for project " . $GLOBALS['PROJECT_CODE'] . ", Team" .
                        $GLOBALS['TEAM_CODE'] . ". Please check the configuration of the service. Error: " . $e->getMessage());
            }
            $subscriptionId = $subscription->getId();
        }

        $patient = self::findPatient($patientRef);
        if (!$patient) {
            // The CASE doesn't exist in the eCRF. Create it
            $patient = self::createPatient($patientRef, $RedCAPData);
        } else {
            self::updatePatientContact($patient, $RedCAPData);
        }

        $admission = self::findAdmission($patient->getId());
        if (!$admission) {
            // The ADMISSION doesn't exist in the eCRF. Create it
            $admission = $api->admission_create($patient->getId(), $subscriptionId, DateHelper::currentDate($GLOBALS['DEFAULT_TIMEZONE']));
        }

        // $enrollDate = $RedCAPData['extraction_date'];
        // if ($enrollDate && $ $enrollDate != explode(' ', $admission->getEnrolDate())[0]) {
        // // Update the enrollment date of the admission if it is different
        // $admission->setEnrolDate($enrollDate);
        // $admission->save();
        // }

        foreach (RedCAPMapping::getTaskCodes() as $taskCode) {
            if (RedCAPMapping::taskDataIsEmpty($taskCode, $RedCAPData)) {
                continue; // No data informed for this task
            }

            $taskFilter = new TaskFilter();
            $taskFilter->setTaskCodes($taskCode);
            $foundTasks = $admission->getTaskList(1, 0, $taskFilter);

            $task = null;
            if (!empty($foundTasks)) {
                $task = $foundTasks[0];
            }

            if (!$task) {
                $taskId = $api->task_insert_by_task_code($admission->getId(), $taskCode);
                $task = $api->task_get($taskId);
            }
            foreach (RedCAPMapping::getFormCodes($taskCode) as $formCode) {
                $formList = $task->findForm($formCode);
                if (empty($formList)) {
                    $formId = $api->form_insert($task->getId(), $formCode, null);
                    $form = $api->form_get_summary($formId);
                } else {
                    $form = $formList[0];
                }

                $formItems = RedCAPMapping::getFormMappings($formCode);
                $questionsArray = [];
                foreach ($formItems as $itemCode => $itemProperties) {
                    $redCapField = $itemProperties['redCAP'];
                    $questionType = $itemProperties['type'];
                    if ($itemProperties['arrayRef']) {
                        // Questions that are part of an ARRAY have been preprocessed and are included in the ARRAY ITEM
                        continue;
                    }
                    if ($questionType == APIQuestionTypes::ARRAY) {
                        /*
                         * Is a field that is part of an array. Create as many rows as necessary
                         */
                        $arrayRef = $formCode . '@' . $itemCode;
                        $row = 1;
                        foreach ($RedCAPData[$arrayRef] as $rowData) {
                            foreach ($rowData as $rowItemCode => $fieldValue) {
                                $rowQuestionType = RedCAPMapping::getQuestionType($formCode, $rowItemCode);
                                $questionsArray[] = self::assignRedCAPValueToForm($form, $rowItemCode, $rowQuestionType, $fieldValue, $itemCode, $row);
                            }
                            $row++;
                        }
                    } else {
                        $questionsArray[] = self::assignRedCAPValueToForm($form, $itemCode, $questionType, $RedCAPData[$redCapField]);
                    }
                }

                if (!empty($questionsArray)) {
                    $completeFlag = RedCAPMapping::formCompleteFlag($formCode, $RedCAPData);
                    $api->form_set_all_answers($form->getId(), $questionsArray, ($completeFlag == 1 || $completeFlag == 2));
                }
            }
        }
    }

    /**
     *
     * @param APITask $bpTask
     * @param APIForm $bpForm
     * @param array $aliquotsData
     * @param string $sampleDate
     * @param string $sampleStartTime
     * @param string $sampleEndTime
     */
    static public function updateBloodProcessingData($bpTask, $bpForm, $aliquotsData, $sampleDate = null, $sampleStartTime = null,
            $sampleEndTime = null) {
        $api = LinkcareSoapAPI::getInstance();

        // Fill the aliquot arrays of the TASK with the IDs of the aliquots provided
        $sampleTypesList = ['WHOLE_BLOOD', 'PLASMA', 'PBMC', 'SERUM'];
        $updatedQuestions = [];
        foreach ($sampleTypesList as $sampleType) {
            $numAliquotsQuestion = $bpForm->findQuestion('NUM_' . $sampleType . '_ALIQUOTS');

            $arrayRef = $sampleType . '_ARRAY';

            $updatedQuestions[] = self::updateTextQuestionValue($bpForm, $numAliquotsQuestion->getItemCode(), count($aliquotsData[$sampleType]));
            $ixRow = 1;
            foreach ($aliquotsData[$sampleType] as $aliquotId) {
                $itemCode = $sampleType . '_ALIQUOT_ID';
                $updatedQuestions[] = self::updateArrayTextQuestionValue($bpForm, $arrayRef, $ixRow++, $itemCode, $aliquotId);
            }
        }

        if ($sampleDate) {
            $updatedQuestions[] = self::updateTextQuestionValue($bpForm, 'PROC_DATE', $sampleDate);
        }
        if ($sampleStartTime) {
            $updatedQuestions[] = self::updateTextQuestionValue($bpForm, 'PROC_START', $sampleStartTime);
        }
        if ($sampleEndTime) {
            $updatedQuestions[] = self::updateTextQuestionValue($bpForm, 'PROC_END', $sampleEndTime);
        }
        $updatedQuestions[] = self::updateOptionQuestionValue($bpForm, 'FORM_COMPLETE', 1, null, true, APIQuestionTypes::VERTICAL_RADIO);

        $api->form_set_all_answers($bpForm->getId(), $updatedQuestions, true);
        $bpForm->refresh();
        if ($bpForm->getStatus() != APIForm::STATUS_CLOSED) {
            throw new ServiceException(ErrorCodes::UNEXPECTED_ERROR, "Form updated successfully but its status is not CLOSED");
        }

        if ($bpTask && $sampleDate) {
            $bpTask->setDate($sampleDate);
            $bpTask->setHour('00:00:00');
            $bpTask->save();
        }
    }

    /**
     *
     * @param string $patientRef
     * @return APICase;
     */
    static private function findPatient($patientRef) {
        $api = LinkcareSoapAPI::getInstance();

        $patients = $api->case_search($patientRef);
        if (empty($patients)) {
            return null;
        }
        if (count($patients) > 1) {
            throw new ServiceException(ErrorCodes::AMBIGUOUS, "Multiple patients found with reference $patientRef. Please specify a unique patient.");
        }

        return $patients[0];
    }

    /**
     *
     * @param string $patientId
     * @throws APIException::
     * @return APIAdmission
     */
    static private function findAdmission($patientId) {
        $api = LinkcareSoapAPI::getInstance();

        $patientAdmissions = $api->case_admission_list($patientId);
        $admission = null;
        foreach ($patientAdmissions as $adm) {
            if ($adm->getSubscription()->getProgram()->getCode() != $GLOBALS['PROJECT_CODE']) {
                continue;
            }
            $admission = $adm;
            break;
        }

        if (!$admission) {
            return null;
        }

        return $admission;
    }

    static private function createPatient($patientRef, $RedCAPData) {
        $api = LinkcareSoapAPI::getInstance();

        // Create a new patient in the eCRF
        $contactData = new APIContact();
        $contactData->setBirthdate($RedCAPData['birthdate']);
        $contactData->setGender($RedCAPData['gender'] == 1 ? "M" : "F");
        $identifier = new APIIdentifier();
        $identifier->setId('PARTICIPANT_REF');
        $identifier->setTeamId($GLOBALS['TEAM_CODE']);
        $identifier->setValue($patientRef);
        $contactData->addIdentifier($identifier);
        $patient = $api->case_insert($contactData, $RedCAPData);
        if (!$patient) {
            throw new ServiceException(ErrorCodes::DATA_MISSING, "Unable to create patient with reference $patientRef");
        }

        return $patient;
    }

    /**
     * Check if there is any change in the personal data of the patient and update it if necessary
     *
     * @param APICase $patient
     * @param array $RedCAPData
     */
    static private function updatePatientContact($patient, $RedCAPData) {
        $api = LinkcareSoapAPI::getInstance();

        $birthdateChanged = ($patient->getBirthdate() != $RedCAPData['birthdate']);
        switch ($RedCAPData['gender']) {
            case 1 :
                $gender = "M";
                break;
            case 2 :
                $gender = "F";
                break;
            default :
                $gender = "";
        }
        $genderChanged = ($patient->getGender() != $gender);
        if (!$birthdateChanged && !$genderChanged) {
            return;
        }

        // Create a new patient in the eCRF
        $contactData = new APIContact();
        $contactData->setBirthdate($RedCAPData['birthdate']);
        $contactData->setGender($gender);

        $api->case_set_contact($patient->getId(), $contactData);
    }

    /**
     *
     * @param APIForm $form
     * @param string $itemCode
     * @param string $questionType
     * @param string $redCAPField
     * @param string[] $RedCAPData
     * @param string $arrayRef
     * @param int $row
     * @return APIQuestion
     */
    static private function assignRedCAPValueToForm($form, $itemCode, $questionType, $value, $arrayRef = null, $row = null) {
        if ($arrayRef) {
            // It is a question in a row of an ARRAY
            if (APIQuestionTypes::isMultiOptions($questionType)) {
                $question = self::updateArrayOptionQuestionValue($form, $arrayRef, $row, $itemCode, $value, null, true, $questionType);
            } else {
                if (APIQuestionTypes::isSingleOption($questionType)) {
                    $question = self::updateArrayOptionQuestionValue($form, $arrayRef, $row, $itemCode, null, $value, true, $questionType);
                } elseif (APIQuestionTypes::isScalar($questionType)) {
                    $question = self::updateArrayTextQuestionValue($form, $arrayRef, $row, $itemCode, $value, true, $questionType);
                }
            }
        } else {
            if (APIQuestionTypes::isMultiOptions($questionType)) {
                $question = self::updateOptionQuestionValue($form, $itemCode, $value, null, true, $questionType);
            } else {
                if (APIQuestionTypes::isSingleOption($questionType)) {
                    $question = self::updateOptionQuestionValue($form, $itemCode, null, $value, true, $questionType);
                } elseif (APIQuestionTypes::isScalar($questionType)) {
                    $question = self::updateTextQuestionValue($form, $itemCode, $value, true, $questionType);
                }
            }
        }

        return $question;
    }

    /**
     * Returns the list of selected options in a RedCAP check box field.
     * Check boxes in RedCAP are stored as a list of separate variables, where each variable represents one of the available
     * options, and the value is 0/1 to indicate whether the option is selected or not.
     *
     * @param string $redCapField
     * @param string[] $RedCAPData
     */
    static public function collectSelectedOptions($redCapField, $RedCAPData) {
        $selectedOptionIds = [];
        $optionId = 1;
        while (array_key_exists($redCapField . "___$optionId", $RedCAPData)) {
            if ($RedCAPData[$redCapField . "___$optionId"] == 1) {
                $selectedOptionIds[] = $optionId;
            }
            $optionId++;
        }

        return $selectedOptionIds;
    }

    /**
     * Sets the value of a Question in a FORM.
     * Returns the question of the Form that has been modified, or null if it was not found
     *
     * @param APIForm $form Form containing the question to be modified
     * @param string $itemCode ITEM CODE of the question that must be modified
     * @param string $value Value to assign to the question
     * @param bool $create If true, the question will be created if it does not exist (maybe it is a conditioned question that currently doesn't
     *        appear in the summary of the form)
     * @param string $questionType Type of the question to be modified (optional. Only necessary if the question is going to be created)
     * @return APIQuestion
     */
    static private function updateTextQuestionValue($form, $itemCode, $value, $create = false, $questionType = null) {
        if ($q = $form->findQuestion($itemCode)) {
            $q->setAnswer($value);
        } elseif ($create) {
            $q = new APIQuestion($itemCode, $value, null, $questionType);
        } else {
            throw new ServiceException(ErrorCodes::DATA_MISSING, "Error updating form " . $form->getFormCode() . ". Item '$itemCode' not found");
        }
        return $q;
    }

    /**
     * Sets the value of a Question that belongs to an ARRAY item in a FORM.
     * Returns the question of the Form that has been modified, or null if it was not found
     *
     * @param APIForm $form Form containing the question to be modified
     * @param string $arrayRef Reference of the array containing the question to be modified
     * @param int $row Row of the array containing the question to be modified
     * @param string $itemCode ITEM CODE of the question that must be modified
     * @param string $value Value to assign to the question
     * @param bool $create If true, the question will be created if it does not exist (maybe it is a conditioned question that currently doesn't
     *        appear in the summary of the form)
     * @param string $questionType Type of the question to be modified (optional. Only necessary if the question is going to be created)
     * @return APIQuestion
     */
    static private function updateArrayTextQuestionValue($form, $arrayRef, $row, $itemCode, $value, $create = true, $questionType = null) {
        if ($q = $form->findArrayQuestion($arrayRef, $row, $itemCode, $create, $questionType)) {
            $q->setAnswer($value);
        } else {
            throw new ServiceException(ErrorCodes::DATA_MISSING, "Error updating form " . $form->getFormCode() . ". Item '$itemCode' not found");
        }
        return $q;
    }

    /**
     * Sets the value of a multi-options Question (checkbox, radios) in a FORM.
     * Returns the question of the Form that has been modified, or null if it was not found
     *
     * @param APIForm $form Form containing the question to be modified
     * @param string $itemCode ITEM CODE of the question that must be modified
     * @param string|string[] $optionId Id/s of the options assigned as the answer to the question
     * @param string|string[] $optionValues Value/s of the options assigned as the answer to the question
     * @param bool $create If true, the question will be created if it does not exist (maybe it is a conditioned question that currently doesn't
     *        appear in the summary of the form)
     * @param string $questionType Type of the question to be modified (optional. Only necessary if the question is going to be created)
     * @return APIQuestion
     */
    static private function updateOptionQuestionValue($form, $itemCode, $optionId, $optionValues = null, $create = false, $questionType = null) {
        $ids = is_array($optionId) ? implode('|', $optionId) : $optionId;
        $values = is_array($optionValues) ? implode('|', $optionValues) : $optionValues;

        if ($q = $form->findQuestion($itemCode)) {
            $q->setOptionAnswer($ids, $values);
        } elseif ($create) {
            $q = new APIQuestion($itemCode, $values, $ids, $questionType);
        } else {
            throw new ServiceException(ErrorCodes::DATA_MISSING, "Error updating form " . $form->getFormCode() . ". Item '$itemCode' not found");
        }
        return $q;
    }

    /**
     * Sets the value of a multi-options Question (checkbox, radios) that belongs to an ARRAY item in a FORM.
     * Returns the question of the Form that has been modified, or null if it was not found
     *
     * @param APIForm $form Form containing the question to be modified
     * @param string $arrayRef Reference of the array containing the question to be modified
     * @param int $row Row of the array containing the question to be modified
     * @param string $itemCode ITEM CODE of the question that must be modified
     * @param string|string[] $optionId Id/s of the options assigned as the answer to the question
     * @param string|string[] $optionValues Value/s of the options assigned as the answer to the question
     * @param bool $create If true, the question will be created if it does not exist (maybe it is a conditioned question that currently doesn't
     *        appear in the summary of the form)
     * @param string $questionType Type of the question to be modified (optional. Only necessary if the question is going to be created)
     * @return APIQuestion
     */
    static private function updateArrayOptionQuestionValue($form, $arrayRef, $row, $itemCode, $optionId, $optionValues = null, $create = true,
            $questionType = null) {
        $ids = is_array($optionId) ? implode('|', $optionId) : $optionId;
        $values = is_array($optionValues) ? implode('|', $optionValues) : $optionValues;

        if ($q = $form->findArrayQuestion($arrayRef, $row, $itemCode, $create, $questionType)) {
            $q->setOptionAnswer($ids, $values);
        } else {
            throw new ServiceException(ErrorCodes::DATA_MISSING, "Error updating form " . $form->getFormCode() . ". Item '$itemCode' not found");
        }
        return $q;
    }

    /**
     * Find the "BLOOD PROCESSING" TASK of a patient given his reference.
     * If it doesn't exist, then it will be created
     * Returns NULL if the FORM is up to date (the aliquots have already been registered)
     *
     * @throws ServiceException If the FORM of the patient cannot be found or if there is any error
     * @param string $patientRef
     * @param string $teamCode Code of the supscription owner team
     * @return [APICase, APIAdmission, APITask, APIForm]
     */
    static public function findFormFromPatientRef($patientRef, $teamCode) {
        $api = LinkcareSoapAPI::getInstance();

        // Step 1: Find patients of the program that have the specified blood sample ID
        $filter = ['identifier' => ['code' => 'PARTICIPANT_REF', 'value' => $patientRef, 'program' => $GLOBALS['PROJECT_CODE'], 'team' => $teamCode]];
        $patients = $api->case_search(json_encode($filter));

        if (empty($patients)) {
            throw new ServiceException(ErrorCodes::NOT_FOUND, "No patient found with reference $patientRef with an admission in the care plan " .
                    $GLOBALS['PROJECT_CODE']);
        } else if (count($patients) > 1) {
            $ids = array_map(function ($p) {
                /** @var APICase $p */
                return $p->getId();
            }, $patients);
            throw new ServiceException(ErrorCodes::UNEXPECTED_ERROR, "More than one CQS patient found with reference $patientRef: Patient IDs: " .
                    implode(',', $ids));
        }

        /** @var APICase $patient */
        $patient = $patients[0];
        // Step 2: Find the ADMISSION of the patient
        $admission = self::findAdmission($patient->getId());
        if (!$admission) {
            throw new ServiceException(ErrorCodes::NOT_FOUND, "No admission found for patient $patientRef");
        }

        // Step 3: Find the BLOOD PROCESSING task of the admission
        $filter = new TaskFilter();
        $taskCode = 'PROC_BLOOD_SAMPLE';
        $filter->setTaskCodes($taskCode);
        $filter->setAdmissionIds($admission->getId());
        $tasks = $patient->getTaskList(2, 0, $filter);
        if (empty($tasks)) {
            // The BLOOD PROCESSING task doesn't exist. Create it automatically
            $taskId = $api->task_insert_by_task_code($admission->getId(), $taskCode);
            $tasks = [$api->task_get($taskId)];
        }

        $bpForm = null;
        $bpTask = null;
        $numForms = 0;
        foreach ($tasks as $task) {
            foreach ($task->getForms() as $form) {
                if ($form->getFormCode() != 'BLOOD_PROCESSING') {
                    continue;
                }
                $numForms++;
                if ($form->isClosed()) {
                    /*
                     * The BLOOD PROCESSION FORM exists, but it is closed.
                     * This is not considered an error, but simply indicates that the aliquots are already registered there is no need to do it again.
                     */
                    continue;
                }
                $bpForm = $form;
                $bpTask = $task;
                break;
            }
        }
        if (!$bpForm && $numForms == 0) {
            throw new ServiceException(ErrorCodes::NOT_FOUND, "The 'BLOOD PROCESSING' FORM was not found for patient $patientRef");
        }

        return [$patient, $admission, $bpTask, $bpForm];
    }

    /**
     * After shipping blood samples, a TASK must be created for each patient to track the shipment in the eCRF.
     *
     * @param Shipment $shipment
     * @param number $patientId
     */
    static public function createShipmentTrackingTask($shipment, $patientId) {
        $api = LinkcareSoapAPI::getInstance();

        $senderTeam = $api->team_get($shipment->sentFromId);
        $admission = self::findAdmission($patientId);

        $initialValues = self::encodeTaskInitialValues(
                [TrackingItems::SHIPMENT_ID => $shipment->id, TrackingItems::SHIPMENT_REF => $shipment->ref,
                        TrackingItems::SHIPMENT_DATE => DateHelper::datePart($shipment->sendDate),
                        TrackingItems::SHIPMENT_TIME => DateHelper::timePart($shipment->sendDate),
                        TrackingItems::FROM_TEAM_ID => $shipment->sentFromId, TrackingItems::TO_TEAM_ID => $shipment->sentToId,
                        TrackingItems::SENDER_ID => $shipment->senderId]);

        $taskId = $api->task_insert_by_task_code($admission->getId(), $GLOBALS['SHIPMENT_TASK_CODE'], $shipment->sendDate, $initialValues);

        $executionException = null;
        try {
            $shipmentTask = $api->task_get($taskId);

            $trackingForm = null;
            foreach ($shipmentTask->getForms() as $form) {
                if ($form->getFormCode() == $GLOBALS['SHIPMENT_TRACKING_FORM']) {
                    $trackingForm = $form;
                    break;
                }
            }

            if (!$trackingForm) {
                throw new ServiceException(ErrorCodes::FORM_MISSING, "The shipment tracking form (" . $GLOBALS['SHIPMENT_TRACKING_FORM'] .
                        ") does not exist in the shipment task " . $shipmentTask->getId());
            }

            $destArrayHeader = $trackingForm->findQuestion(AliquotStatusItems::ARRAY);
            if (!$destArrayHeader) {
                throw new ServiceException(ErrorCodes::DATA_MISSING, "The array of aliquots does not exist in the tracking form");
            }

            $aliquots = $shipment->getAliquots($patientId);
            $aliquotsPerType = [];
            $questionsArray = [];
            $ix = 1;
            foreach ($aliquots as $a) {
                $aliquotsPerType[$a->type][] = $a;

                $questionsArray[] = self::updateArrayTextQuestionValue($trackingForm, $destArrayHeader->getId(), $ix, AliquotTrackingItems::ID, $a->id);
                $questionsArray[] = self::updateArrayTextQuestionValue($trackingForm, $destArrayHeader->getId(), $ix, AliquotTrackingItems::TYPE,
                        $a->type);
                $questionsArray[] = self::updateArrayTextQuestionValue($trackingForm, $destArrayHeader->getId(), $ix,
                        AliquotTrackingItems::CREATION_DATE, DateHelper::datePart($a->created));
                $questionsArray[] = self::updateArrayTextQuestionValue($trackingForm, $destArrayHeader->getId(), $ix,
                        AliquotTrackingItems::CREATION_TIME, DateHelper::timePart($a->created));
                $ix++;
            }

            $questionsArray[] = self::updateOptionQuestionValue($trackingForm, TrackingItems::CONFIRM, 1);

            // Add the aliquots to the tracking form
            if (!empty($questionsArray)) {
                $api->form_set_all_answers($trackingForm->getId(), $questionsArray, true);
            }

            // The Datetime of the TASK must be expressed in the local timezone of the sender Team
            $localDate = DateHelper::UTCToLocal($shipment->sendDate, $senderTeam->getTimezone());
            $shipmentTask->setDate(DateHelper::datePart($localDate));
            $shipmentTask->setHour(DateHelper::timePart($localDate));
            $shipmentTask->save();

            /*
             * Update the STATUS FORM for each sample type that has been shipped
             * The FORMS are created automatically by an action the "SHIPMENT TRACKING" TASK cloning the last known status of the aliquots, so it is
             * only
             * necessary to update them
             */
            foreach ($aliquotsPerType as $sampleType => $aliquotSublist) {
                /* Check if there already exists a Form for this sample type or otherwise create it */
                $questionsArray = [];
                $statusForm = null;
                $formCode = $sampleType . $GLOBALS['STATUS_FORM_CODE_SUFFIX'];
                foreach ($shipmentTask->getForms() as $form) {
                    if ($form->getFormCode() == $formCode) {
                        $statusForm = $form;
                        break;
                    }
                }
                if (!$statusForm) {
                    throw new ServiceException(ErrorCodes::FORM_MISSING, "The status form for the sample type $sampleType does not exist in the shipment task " .
                            $shipmentTask->getId());
                }

                $modifiedAliquotsArray = [];
                foreach ($aliquotSublist as $aliquot) {
                    $modifiedAliquotsArray[$aliquot->id] = [AliquotStatusItems::SHIPMENT_REF => new APIQuestion(null, $shipment->ref),
                            AliquotStatusItems::STATUS => new APIQuestion(null, AliquotStatus::IN_TRANSIT),
                            AliquotStatusItems::CHANGE_DATE => new APIQuestion(null, DateHelper::datePart($shipment->sendDate)),
                            AliquotStatusItems::CHANGE_TIME => new APIQuestion(null, DateHelper::timePart($shipment->sendDate))];
                }
                self::updateSamplesStatus($modifiedAliquotsArray, $sampleType, $statusForm);
            }
        } catch (Exception $e) {
            $executionException = $e;
        }

        if ($executionException) {
            if ($shipmentTask) {
                // If the TASK was created but an error occurred, delete it
                try {
                    $shipmentTask->delete();
                } catch (Exception $e) {
                    // Ignore the error
                }
            }

            throw $executionException;
        }

        // Finally update the aliquots to indicate that they have already been tracked in a TASK of the eCRF
        $arrVariables = [':shipmentId' => $shipment->id, ':taskId' => $shipmentTask->getId()];
        $aliquotIds = array_map(function ($aliquot) {
            return $aliquot->id;
        }, $aliquots);
        $inCondition = DbHelper::bindParamArray('aliquotId', $aliquotIds, $arrVariables);
        $sqls = [];
        $sqls[] = "UPDATE SHIPPED_ALIQUOTS SET ID_SHIPMENT_TASK = :taskId WHERE ID_SHIPMENT=:shipmentId AND ID_ALIQUOT IN ($inCondition)";
        $sqls[] = "UPDATE ALIQUOTS SET ID_TASK = :taskId WHERE ID_SHIPMENT=:shipmentId AND ID_ALIQUOT IN ($inCondition)";
        $sqls[] = "UPDATE ALIQUOTS_HISTORY SET ID_TASK = :taskId WHERE ID_SHIPMENT=:shipmentId AND ID_ALIQUOT IN ($inCondition)";
        foreach ($sqls as $sql) {
            Database::getInstance()->executeBindQuery($sql, $arrVariables);
        }
    }

    /**
     * When a shipment of blood samples is received at its destination, a TASK must be created for each patient to track the shipment in the eCRF.
     *
     * @param Shipment $shipment
     * @param number $patientId
     */
    static public function createReceptionTrackingTask($shipment, $patientId, $trackingTaskId) {
        $api = LinkcareSoapAPI::getInstance();

        $shipmentTask = $api->task_get($trackingTaskId);

        $trackingForm = null;
        foreach ($shipmentTask->getForms() as $form) {
            if ($form->getFormCode() == $GLOBALS['SHIPMENT_TRACKING_FORM']) {
                $trackingForm = $form;
                break;
            }
        }

        if (!$trackingForm) {
            throw new ServiceException(ErrorCodes::FORM_MISSING, "The shipment tracking form (" . $GLOBALS['SHIPMENT_TRACKING_FORM'] .
                    ") does not exist in the shipment task " . $shipmentTask->getId());
        }

        $questionsArray = [];
        $questionsArray[] = self::updateTextQuestionValue($form, TrackingItems::RECEPTION_DATE, DateHelper::datePart($shipment->receptionDate));
        $questionsArray[] = self::updateTextQuestionValue($form, TrackingItems::RECEPTION_TIME, DateHelper::timePart($shipment->receptionDate));
        $questionsArray[] = self::updateTextQuestionValue($form, TrackingItems::RECEIVER_ID, $shipment->receiverId);
        /*
         * Load the aliquots that currently exist in the array of aliquots of the tracking FORM
         * We will update them with the reception information
         */
        $arrayHeader = $trackingForm->findQuestion(AliquotTrackingItems::ARRAY);
        $trackedAliquots = self::loadTrackedAliquots($trackingForm);

        $aliquots = $shipment->getAliquots($patientId);
        $aliquotsPerType = [];
        $ix = 1;
        foreach ($aliquots as $a) {
            $aliquotsPerType[$a->type][] = $a;

            if (!array_key_exists($a->id, $trackedAliquots)) {
                throw new ServiceException(ErrorCodes::DATA_MISSING, "Aliquot " . $a->id . " is present in shipment " . $shipment->id .
                        ", but it is not present in the Shipment Tracking Task with ID: " . $trackingTaskId);
            }

            $questionsArray[] = self::updateArrayOptionQuestionValue($trackingForm, $arrayHeader->getId(), $ix, AliquotTrackingItems::STATUS, null,
                    $a->statusId, false);
            $questionsArray[] = self::updateArrayOptionQuestionValue($trackingForm, $arrayHeader->getId(), $ix, AliquotTrackingItems::DAMAGE, null,
                    $a->conditionId, false);
            $ix++;
        }

        // Add the aliquots to the tracking form
        if (!empty($questionsArray)) {
            $trackingForm->updateAnswers();
        }

        /*
         * Update the STATUS FORM for each sample type that has been shipped
         * The FORMS are created automatically by an action the "SHIPMENT TRACKING" TASK cloning the last known status of the aliquots, so it is
         * only
         * necessary to update them
         */
        foreach ($aliquotsPerType as $sampleType => $aliquotSublist) {
            /* Check if there already exists a Form for this sample type or otherwise create it */
            $questionsArray = [];
            $statusForm = null;
            $formCode = $sampleType . $GLOBALS['STATUS_FORM_CODE_SUFFIX'];
            foreach ($shipmentTask->getForms() as $form) {
                if ($form->getFormCode() == $formCode) {
                    $statusForm = $form;
                    break;
                }
            }
            if (!$statusForm) {
                throw new ServiceException(ErrorCodes::FORM_MISSING, "The status form for the sample type $sampleType does not exist in the shipment task " .
                        $shipmentTask->getId());
            }

            $modifiedAliquotsArray = [];
            foreach ($aliquotSublist as $aliquot) {
                $modifiedAliquotsArray[$aliquot->id] = [AliquotStatusItems::LOCATION => new APIQuestion(null, $aliquot->locationId),
                        AliquotStatusItems::STATUS => new APIQuestion(null, $aliquot->statusId),
                        AliquotStatusItems::DAMAGE => new APIQuestion(null, $aliquot->conditionId)];
            }
            self::updateSamplesStatus($modifiedAliquotsArray, $sampleType, $statusForm);
        }

        // Finally update the aliquots to indicate that they have already been tracked in a TASK of the eCRF
        $arrVariables = [':shipmentId' => $shipment->id, ':taskId' => $shipmentTask->getId()];
        $aliquotIds = array_map(function ($aliquot) {
            return $aliquot->id;
        }, $aliquots);
        $inCondition = DbHelper::bindParamArray('aliquotId', $aliquotIds, $arrVariables);
        $sqls = [];
        $sqls[] = "UPDATE SHIPPED_ALIQUOTS SET ID_RECEPTION_TASK = :taskId WHERE ID_SHIPMENT=:shipmentId";
        $sqls[] = "UPDATE ALIQUOTS SET ID_TASK = :taskId WHERE ID_SHIPMENT=:shipmentId AND ID_ALIQUOT IN ($inCondition)";
        $sqls[] = "UPDATE ALIQUOTS_HISTORY SET ID_TASK = :taskId WHERE ID_SHIPMENT=:shipmentId AND ID_ALIQUOT IN ($inCondition)";
        foreach ($sqls as $sql) {
            Database::getInstance()->executeBindQuery($sql, $arrVariables);
        }
    }

    /**
     * Creates or updates a tracking of aliquots in the database.
     *
     * @param array $dbRows
     */
    static public function trackAliquots($dbRows) {
        $arrVariables = [];

        $dbColumnNames = ['ID_ALIQUOT', 'ID_PATIENT', 'PATIENT_REF', 'SAMPLE_TYPE', 'ID_LOCATION', 'ID_STATUS', 'ID_ALIQUOT_CONDITION', 'ID_TASK',
                'ALIQUOT_CREATED', 'ALIQUOT_UPDATED', 'ID_SHIPMENT', 'RECORD_TIMESTAMP'];

        $now = DateHelper::currentDate();
        foreach ($dbRows as $row) {
            $arrVariables = [];
            $row['RECORD_TIMESTAMP'] = $now; // Add the current timestamp to track the real time when the DB record was created/modified

            // Read the last known values of the aliquot to be updated
            $sqlPrev = "SELECT * FROM ALIQUOTS WHERE ID_ALIQUOT=:id";
            $rst = Database::getInstance()->ExecuteBindQuery($sqlPrev, $row['ID_ALIQUOT']);
            $prevValues = [];
            while ($rst->Next()) {
                foreach ($rst->getColumnNames() as $colName) {
                    $prevValues[$colName] = $rst->GetField($colName);
                }
            }

            $keyColumns = ['ID_ALIQUOT' => ':id_aliquot'];

            $updateColumns = [];
            foreach ($dbColumnNames as $colName) {
                $parameterName = ':' . strtolower($colName);
                if (array_key_exists($colName, $row)) {
                    // New value provided for the column
                    $arrVariables[$parameterName] = $row[$colName];
                } elseif (array_key_exists($colName, $prevValues)) {
                    // If the column is not present in the row, we must keep the previous value
                    $arrVariables[$parameterName] = $prevValues[$colName];
                } else {
                    $arrVariables[$parameterName] = null;
                }
                if (!array_key_exists($colName, $keyColumns)) {
                    $updateColumns[$colName] = $parameterName;
                }
            }

            $sql = Database::getInstance()->buildInsertOrUpdateQuery('ALIQUOTS', $keyColumns, $updateColumns);
            Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);

            /*
             * Add the tracking of the aliquots in the ALIQUOTS_HISTORY table
             */
            $sql = "INSERT INTO ALIQUOTS_HISTORY (ID_ALIQUOT, ID_TASK, ID_LOCATION, ID_STATUS, ID_ALIQUOT_CONDITION, ALIQUOT_UPDATED, ID_SHIPMENT, RECORD_TIMESTAMP)
                        VALUES (:id_aliquot, :id_task, :id_location, :id_status, :id_aliquot_condition, :aliquot_updated, :id_shipment, :record_timestamp)";
            Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);
        }
    }

    static private function addLocation($locationId) {
        $api = LinkcareSoapAPI::getInstance();

        $sql = "SELECT ID_LOCATION,NAME FROM LOCATIONS WHERE ID_LOCATION=:id";
        $rst = Database::getInstance()->ExecuteBindQuery($sql, [':id' => $locationId]);
        if ($rst->Next()) {
            return;
        }

        $team = $api->team_get($locationId);

        $keyColumns = ['ID_LOCATION' => ':id'];
        $updateColumns = ['NAME' => ':name', 'CODE' => ':code', 'IS_LAB' => ':is_lab', 'IS_CLINICAL_SITE' => ':is_clinical_site'];
        $arrVariables = [':id' => $team->getId(), ':name' => $team->getName(), ':code' => $team->getCode(), ':is_lab' => 0, ':is_clinical_site' => 1];
        $sql = Database::getInstance()->buildInsertOrUpdateQuery('LOCATIONS', $keyColumns, $updateColumns);

        Database::getInstance()->ExecuteBindQuery($sql, $arrVariables);
    }
}