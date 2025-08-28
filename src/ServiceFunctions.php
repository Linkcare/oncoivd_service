<?php

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
}