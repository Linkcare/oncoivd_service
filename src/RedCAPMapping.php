<?php

class RedCAPMapping {

    static public function taskDataIsEmpty($taskCode, $data) {
        // Verify if any of the Forms of the TASK has data informed
        foreach (self::getFormCodes($taskCode) as $formCode) {
            $completeFlag = self::formCompleteFlag($formCode, $data);
            if (trim($completeFlag) !== '') {
                // The "complete" flag is empty when no data is informed
                return false;
            }
        }
        return true;
    }

    /**
     * Returns the value of the "Complete" flag for the given form code.
     * Possible values:
     * <ul>
     * <li>"": No data informed</li>
     * <li>0: Form data not complete</li>
     * <li>1: Form data not verified</li>
     * <li>2: Form completed</li>
     * </ul>
     *
     * @param string $formCode
     * @return string
     */
    static public function formCompleteFlag($formCode, $data) {
        $completeFlag = [];
        $completeFlag['PROFILE_PATHOLOGIES_TREATMENTS'] = 'other_patologies_and_treatments_complete';
        $completeFlag['PROFILE_HABITS_STATUS'] = 'habitsgeneral_status_complete';
        $completeFlag['PROFILE_DIETARY_HABITS'] = 'dietary_habits_complete';
        $completeFlag['CANCER_TEST'] = 'cancer_test_complete';
        $completeFlag['COLONOSCOPY_RESULTS'] = 'colonoscopy_results_complete';
        $completeFlag['LESION_DESC'] = 'lesion_description_complete';
        $completeFlag['ADENO_CHARACT'] = 'adenocarcinoma_characteristics_complete';
        $completeFlag['BIOCHEMICAL'] = 'biochemical_parameters_complete';

        if (!array_key_exists($formCode, $completeFlag)) {
            throw new ServiceException(ErrorCodes::DATA_MISSING, "Complete flag not found for form $formCode");
        }
        return $data[$completeFlag[$formCode]];
    }

    /**
     *
     * @return string[]
     */
    static public function getTaskCodes() {
        $taskCodes = [];
        $taskCodes[] = 'PATIENT_PROFILE_REPORT';
        $taskCodes[] = 'COLONOSCOPY_REPORT';
        $taskCodes[] = 'ANATOMOPATHOLOGICAL_REPORT';
        $taskCodes[] = 'BIOCHEMICAL_REPORT';

        return $taskCodes;
    }

    /**
     * Returns the FORM_CODES of the FORMS that can exist in a TASK
     *
     * @param string $taskCode
     * @return string[]
     */
    static public function getFormCodes($taskCode) {
        $formCodes = [];
        if ($taskCode == 'PATIENT_PROFILE_REPORT') {
            $formCodes[] = 'PROFILE_PATHOLOGIES_TREATMENTS';
            $formCodes[] = 'PROFILE_HABITS_STATUS';
            $formCodes[] = 'PROFILE_DIETARY_HABITS';
            $formCodes[] = 'CANCER_TEST';
        } elseif ($taskCode == 'COLONOSCOPY_REPORT') {
            $formCodes[] = 'COLONOSCOPY_RESULTS';
        } elseif ($taskCode == 'ANATOMOPATHOLOGICAL_REPORT') {
            $formCodes[] = 'LESION_DESC';
            $formCodes[] = 'ADENO_CHARACT';
        } elseif ($taskCode == 'BIOCHEMICAL_REPORT') {
            $formCodes[] = 'BIOCHEMICAL';
        }
        return $formCodes;
    }

    public function getQuestionType($formCode, $itemCode) {
        $redCAPField = self::getRedCAPField($formCode, $itemCode);
        if (array_key_exists('type', $redCAPField)) {
            return $redCAPField['type'];
        }
        throw new ServiceException(ErrorCodes::DATA_MISSING, "RedCAP field type not found for form $formCode and item $itemCode");
    }

    /**
     * Returns an associative array with 2 items:
     * 'redCAP' => the name of the field in RedCAP
     * 'type' => the type of the field (one of the API Question types)
     *
     * @param string $formCode
     * @param string $itemCode
     * @return string[]
     */
    static public function getRedCAPField($formCode, $itemCode) {
        $redCAPMapping = self::getFieldMappings();

        if (array_key_exists($formCode, $redCAPMapping)) {
            if (array_key_exists($itemCode, $redCAPMapping[$formCode])) {
                return $redCAPMapping[$formCode][$itemCode];
            }
        }
        throw new ServiceException(ErrorCodes::DATA_MISSING, "RedCAP field mapping not found for form $formCode and item $itemCode");
    }

    /**
     *
     * @param string $formCode
     * @return string[]
     */
    static public function getFormMappings($formCode) {
        $fieldMappings = self::getFieldMappings();

        return $fieldMappings[$formCode] ?? [];
    }

    /**
     *
     * @param string $formCode
     * @param string $itemCode
     * @return boolean
     */
    static public function isArrayColumn($formCode, $itemCode) {
        $fieldMappings = self::getFieldMappings();

        if (array_key_exists($formCode, $fieldMappings)) {
            if (array_key_exists($itemCode, $fieldMappings[$formCode])) {
                $fieldProperties = $fieldMappings[$formCode][$itemCode];
                return true;
            }
        }
        return false;
    }

    static private function getFieldMappings() {
        $redCAPMapping = [];

        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['HYPERTENSION'] = ['redCAP' => 'hypertension', 'type' => 'BOOLEAN'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['HYPERTENSION_DRUG_Q'] = ['redCAP' => 'hypertension_drug_q', 'type' => 'VERTICAL_RADIO',
                "value_mapping" => ["1" => "1", "0" => "2", "99" => "3"]];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['HYPERTENSION_DRUG'] = ['redCAP' => 'hypertension_drug', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['HYPER_ACEINH'] = ['redCAP' => 'hyper_aceinh', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['HYPER_CALCHAN'] = ['redCAP' => 'hyper_calchan', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['HYPER_BETACLOCK'] = ['redCAP' => 'hyper_betaclock', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['HYPER_DIURE'] = ['redCAP' => 'hyper_diure', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['HYPER_ANGIO'] = ['redCAP' => 'hyper_angio', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['HYPER_ALPHABLOCK'] = ['redCAP' => 'hyper_alphablock', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['HYPER_ALPHARECEP'] = ['redCAP' => 'hyper_alpharecep', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['HYPER_ALPHABETA'] = ['redCAP' => 'hyper_alphabeta', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['HYPER_VASODIL'] = ['redCAP' => 'hyper_vasodil', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CARDIAC_DISEASE'] = ['redCAP' => 'cardiac_disease', 'type' => 'BOOLEAN'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CARDIAC_DISEASE_DRUG_Q'] = ['redCAP' => 'cardiac_disease_drug_q',
                'type' => 'VERTICAL_RADIO', "value_mapping" => ["1" => "1", "0" => "2", "99" => "3"]];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CARDIAC_DISEASE_DRUG'] = ['redCAP' => 'cardiac_disease_drug', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CARDIAC_ACEINH'] = ['redCAP' => 'cardiac_aceinh', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CARDIAC_CALCHAN'] = ['redCAP' => 'cardiac_calchan', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CARDIAC_BETACLOCK'] = ['redCAP' => 'cardiac_betaclock', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CARDIAC_DIURE'] = ['redCAP' => 'cardiac_diure', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CARDIAC_ANGIO'] = ['redCAP' => 'cardiac_angio', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CARDIAC_ANTICO'] = ['redCAP' => 'cardiac_antico', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CARDIAC_ANTIPLAT'] = ['redCAP' => 'cardiac_antiplat', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CARDIAC_ANGIOTENSIN'] = ['redCAP' => 'cardiac_angiotensin', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CARDIAC_VASODIL'] = ['redCAP' => 'cardiac_vasodil', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CARDIAC_CHOLEST'] = ['redCAP' => 'cardiac_cholest', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CARDIAC_DIGITALIS'] = ['redCAP' => 'cardiac_digitalis', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CHOLESTEROL'] = ['redCAP' => 'cholesterol', 'type' => 'BOOLEAN'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CHOLESTEROL_DRUG_Q'] = ['redCAP' => 'cholesterol_drug_q', 'type' => 'VERTICAL_RADIO',
                "value_mapping" => ["1" => "1", "0" => "2", "99" => "3"]];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CHOLESTEROL_DRUG'] = ['redCAP' => 'cholesterol_drug', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CHOLESTEROL_STATINS'] = ['redCAP' => 'cholesterol_statins', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CHOLESTEROL_ABSORINH'] = ['redCAP' => 'cholesterol_absorinh', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CHOLESTEROL_BILEACID'] = ['redCAP' => 'cholesterol_bileacid', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CHOLESTEROL_PCSK9'] = ['redCAP' => 'cholesterol_pcsk9', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CHOLESTEROL_ADENO'] = ['redCAP' => 'cholesterol_adeno', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CHOLESTEROL_FIBRATES'] = ['redCAP' => 'cholesterol_fibrates', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CHOLESTEROL_NIACIN'] = ['redCAP' => 'cholesterol_niacin', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['CHOLESTEROL_OMEGA3'] = ['redCAP' => 'cholesterol_omega3', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['ASTHMA'] = ['redCAP' => 'asthma', 'type' => 'BOOLEAN'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['ASTHMA_DRUG_Q'] = ['redCAP' => 'asthma_drug_q', 'type' => 'VERTICAL_RADIO',
                "value_mapping" => ["1" => "1", "0" => "2", "99" => "3"]];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['ASTHMA_DRUG'] = ['redCAP' => 'asthma_drug', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['ASTHMA_BRONCHO'] = ['redCAP' => 'asthma_broncho', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['ASTHMA_INHALED'] = ['redCAP' => 'asthma_inhaled', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['ASTHMA_LEUKO'] = ['redCAP' => 'asthma_leuko', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['ASTHMA_ANTICHOL'] = ['redCAP' => 'asthma_antichol', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['ASTHMA_MASTCELL'] = ['redCAP' => 'asthma_mastcell', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['ASTHMA_ANTI'] = ['redCAP' => 'asthma_anti', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['ASTHMA_METYL'] = ['redCAP' => 'asthma_metyl', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['AUTOIMMUNE_DISEASE'] = ['redCAP' => 'autoinmune_disease', 'type' => 'BOOLEAN'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['AUTOIMMUNE_DISEASE_DRUG_Q'] = ['redCAP' => 'autoinmune_disease_drug_q',
                'type' => 'VERTICAL_RADIO', "value_mapping" => ["1" => "1", "0" => "2", "99" => "3"]];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['AUTOIMMUNE_DISEASE_DRUG'] = ['redCAP' => 'autoinmune_disease_drug', 'type' => 'TEXT'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['INFLAMMATORY_INTESTINAL'] = ['redCAP' => 'inflammatory_intestinal', 'type' => 'BOOLEAN'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['INFLAMMATORY_INTESTINAL_DRUG_Q'] = ['redCAP' => 'inflammatory_intestinal_drug_q',
                'type' => 'VERTICAL_RADIO', "value_mapping" => ["1" => "1", "0" => "2", "99" => "3"]];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['INFLAMMATORY_INTESTINAL_DRUG'] = ['redCAP' => 'inflammatory_intestinal_drug',
                'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['INFLAMMATORY_5AMINO'] = ['redCAP' => 'inflammatory_5amino', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['INFLAMMATORY_CORTICO'] = ['redCAP' => 'inflammatory_cortico', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['INFLAMMATORY_IMMUNO'] = ['redCAP' => 'inflammatory_immuno', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['INFLAMMATORY_BIOLOG'] = ['redCAP' => 'inflammatory_biolog', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['INFLAMMATORY_SMALLMOL'] = ['redCAP' => 'inflammatory_smallmol', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['INFLAMMATORY_ANTI'] = ['redCAP' => 'inflammatory_anti', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['OSTEOPOROSIS'] = ['redCAP' => 'osteoporosis', 'type' => 'BOOLEAN'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['OSTEOPOROSIS_DRUG_Q'] = ['redCAP' => 'osteoporosis_drug_q', 'type' => 'VERTICAL_RADIO',
                "value_mapping" => ["1" => "1", "0" => "2", "99" => "3"]];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['OSTEOPOROSIS_DRUG'] = ['redCAP' => 'osteoporosis_drug', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['OSTEOPOROSIS_ANTIRES'] = ['redCAP' => 'osteoporosis_antires', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['OSTEOPOROSIS_ANAB'] = ['redCAP' => 'osteoporosis_anab', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['TRANSPLANT'] = ['redCAP' => 'transplant', 'type' => 'BOOLEAN'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['TRANSPLANT_DRUG_Q'] = ['redCAP' => 'transplant_drug_q', 'type' => 'VERTICAL_RADIO',
                "value_mapping" => ["1" => "1", "0" => "2", "99" => "3"]];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['TRANSPLANT_DRUG'] = ['redCAP' => 'transplant_drug', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['HYPER_HYPOTHYROIDISM'] = ['redCAP' => 'hyper_hypothyroidism', 'type' => 'BOOLEAN'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['HYPER_HYPOTHYROIDISM_DRUG_Q'] = ['redCAP' => 'hyper_hypothyroidism_drug_q',
                'type' => 'VERTICAL_RADIO', "value_mapping" => ["1" => "1", "0" => "2", "99" => "3"]];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['HYPER_HYPOTHYROIDISM_DRUG'] = ['redCAP' => 'hyper_hypothyroidism_drug',
                'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['HYPER_HYPOTHYROIDISM_TYROID_HORMREPL'] = [
                'redCAP' => 'hyper_hypothyroidism_tyroid_hormrepl', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['HYPRT_HYPOTHYROIDISM_ANTIMED'] = ['redCAP' => 'hyprt_hypothyroidism_antimed',
                'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['INFECTIONS'] = ['redCAP' => 'infections', 'type' => 'VERTICAL_RADIO',
                "value_mapping" => ["1" => "1", "0" => "2", "2" => "3"]];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['SPECIF_INF'] = ['redCAP' => 'specif_inf', 'type' => 'VERTICAL_CHECK'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['INFECTIONS_OTHER'] = ['redCAP' => 'sepcif_othinf', 'type' => 'TEXT'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['DIABETES'] = ['redCAP' => 'diabetes', 'type' => 'BOOLEAN'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['DIABETES_TIME'] = ['redCAP' => 'diabetes_time', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['DIABETES_ANTECEDENTS'] = ['redCAP' => 'diabetes_antecedents', 'type' => 'BOOLEAN'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['DIABETES_INJECTED_INSULIN'] = ['redCAP' => 'diabetes_injected_insulin', 'type' => 'BOOLEAN'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['DIABETES_OTHER_TREATM'] = ['redCAP' => 'diabetes_other_treatm', 'type' => 'BOOLEAN'];
        $redCAPMapping['PROFILE_PATHOLOGIES_TREATMENTS']['DIABETES_OTHER_TREATM_SPECIFY'] = ['redCAP' => 'diabetes_other_treatm_specify',
                'type' => 'VERTICAL_RADIO'];

        $redCAPMapping['PROFILE_HABITS_STATUS']['JOB'] = ['redCAP' => 'job', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_HABITS_STATUS']['JOB_SHIFT'] = ['redCAP' => 'job_shift', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_HABITS_STATUS']['JOB_HOURS'] = ['redCAP' => 'job_hours', 'type' => 'NUMERICAL'];
        $redCAPMapping['PROFILE_HABITS_STATUS']['ALCOHOL'] = ['redCAP' => 'alcohol', 'type' => 'BOOLEAN'];
        $redCAPMapping['PROFILE_HABITS_STATUS']['ALCOHOL_TYPE'] = ['redCAP' => 'alcohol_type', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_HABITS_STATUS']['ALCOHOL_FREQUENCY'] = ['redCAP' => 'alcohol_frequency', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_HABITS_STATUS']['STRESS'] = ['redCAP' => 'stress', 'type' => 'BOOLEAN'];
        $redCAPMapping['PROFILE_HABITS_STATUS']['STRESS_REASON'] = ['redCAP' => 'stress_reason', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_HABITS_STATUS']['STRESS_TRAUMSTRE'] = ['redCAP' => 'stress_traumstre', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_HABITS_STATUS']['STRESS_MITIGATION'] = ['redCAP' => 'stress_migration', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_HABITS_STATUS']['SAD'] = ['redCAP' => 'sad', 'type' => 'BOOLEAN'];
        $redCAPMapping['PROFILE_HABITS_STATUS']['EXERCISE'] = ['redCAP' => 'exercise', 'type' => 'BOOLEAN'];
        $redCAPMapping['PROFILE_HABITS_STATUS']['EXERCISE_SPECIFY'] = ['redCAP' => 'exercise_specify', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_HABITS_STATUS']['EXERCISE_SPECIFY_OTHER'] = ['redCAP' => 'exercise_specify_other', 'type' => 'TEXT'];
        $redCAPMapping['PROFILE_HABITS_STATUS']['EXERCISE_FREQUENCY'] = ['redCAP' => 'exercise_frequency', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_HABITS_STATUS']['EXERCISE_DURATION'] = ['redCAP' => 'exercise_duration', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_HABITS_STATUS']['SMOKING'] = ['redCAP' => 'smoking', 'type' => 'VERTICAL_RADIO',
                "value_mapping" => ["1" => "1", "0" => "2", "2" => "3"]];
        $redCAPMapping['PROFILE_HABITS_STATUS']['SMOKING_EX_TIME'] = ['redCAP' => 'smoking_ex_time', 'type' => 'VERTICAL_RADIO'];

        $redCAPMapping['PROFILE_DIETARY_HABITS']['DIET'] = ['redCAP' => 'diet', 'type' => 'BOOLEAN'];
        $redCAPMapping['PROFILE_DIETARY_HABITS']['DIET_SPECIFY'] = ['redCAP' => 'diet_specify', 'type' => 'TEXT'];
        $redCAPMapping['PROFILE_DIETARY_HABITS']['WEIGHT_CHANGE6M'] = ['redCAP' => 'weight_change6m', 'type' => 'BOOLEAN'];
        $redCAPMapping['PROFILE_DIETARY_HABITS']['WEIGHT_CHANGE6M_HOW'] = ['redCAP' => 'weight_change6m_how', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_DIETARY_HABITS']['WEIGHT_CHANGE6M_KG'] = ['redCAP' => 'weight_change6m_kg', 'type' => 'NUMERICAL'];
        $redCAPMapping['PROFILE_DIETARY_HABITS']['MAX_WEIGHT'] = ['redCAP' => 'max_weight', 'type' => 'NUMERICAL'];
        $redCAPMapping['PROFILE_DIETARY_HABITS']['FEEDING_PROBLEMS'] = ['redCAP' => 'feeding_problems', 'type' => 'BOOLEAN'];
        $redCAPMapping['PROFILE_DIETARY_HABITS']['FEEDING_PROBLEMS_SPECIFY'] = ['redCAP' => 'feeding_problems_specify', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_DIETARY_HABITS']['FEEDING_PROBLEMS_OTHER'] = ['redCAP' => 'feeding_problems_others', 'type' => 'TEXT'];
        $redCAPMapping['PROFILE_DIETARY_HABITS']['DIET_MILK'] = ['redCAP' => 'diet_milk', 'type' => 'VERTICAL_RADIO',
                "value_mapping" => ["1" => "1", "2" => "2", "0" => "3"]];
        $redCAPMapping['PROFILE_DIETARY_HABITS']['DIET_FISH'] = ['redCAP' => 'diet_fish', 'type' => 'VERTICAL_RADIO',
                "value_mapping" => ["1" => "1", "2" => "2", "0" => "3"]];
        $redCAPMapping['PROFILE_DIETARY_HABITS']['DIET_VEGETABLES'] = ['redCAP' => 'diet_vegetables', 'type' => 'VERTICAL_RADIO',
                "value_mapping" => ["1" => "1", "2" => "2", "0" => "3"]];
        $redCAPMapping['PROFILE_DIETARY_HABITS']['DIET_FRUIT'] = ['redCAP' => 'diet_fruit', 'type' => 'VERTICAL_RADIO',
                "value_mapping" => ["1" => "1", "2" => "2", "0" => "3"]];
        $redCAPMapping['PROFILE_DIETARY_HABITS']['DIET_FASTFOOD'] = ['redCAP' => 'diet_fastfood', 'type' => 'VERTICAL_RADIO',
                "value_mapping" => ["1" => "1", "2" => "2", "3" => "3", "4" => "4", "0" => "5"]];
        $redCAPMapping['PROFILE_DIETARY_HABITS']['DIET_MEAT_PERCENTAGE'] = ['redCAP' => 'diet_meat_percentage', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['PROFILE_DIETARY_HABITS']['DIET_SWEETS'] = ['redCAP' => 'diet_sweets', 'type' => 'VERTICAL_RADIO',
                "value_mapping" => ["1" => "1", "2" => "2", "0" => "3"]];
        $redCAPMapping['PROFILE_DIETARY_HABITS']['DIET_PREPARATION'] = ['redCAP' => 'diet_preparation', 'type' => 'VERTICAL_RADIO'];

        $redCAPMapping['CANCER_TEST']['NEOADJUVANT'] = ['redCAP' => 'neoadjuvant', 'type' => 'BOOLEAN'];
        $redCAPMapping['CANCER_TEST']['NEOADJ_TTM'] = ['redCAP' => 'neoadj_ttm', 'type' => 'VERTICAL_RADIO',
                "value_mapping" => ["1" => "1", "99" => "2"]];
        $redCAPMapping['CANCER_TEST']['NEOADJQT_SCHEMA'] = ['redCAP' => 'neoadjqt_schema', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['CANCER_TEST']['NEOQT_FIRST_CYCLE_DATE'] = ['redCAP' => 'neoqt_first_cycle_date', 'type' => 'DATE'];
        $redCAPMapping['CANCER_TEST']['NEOQT_LAST_CYCLE_DATE'] = ['redCAP' => 'neoqt_last_cycle_date', 'type' => 'DATE'];
        $redCAPMapping['CANCER_TEST']['ADJUVANT'] = ['redCAP' => 'adjuvant', 'type' => 'BOOLEAN'];
        $redCAPMapping['CANCER_TEST']['ADJ_TTM'] = ['redCAP' => 'adj_ttm', 'type' => 'VERTICAL_RADIO', "value_mapping" => ["1" => "1", "99" => "2"]];
        $redCAPMapping['CANCER_TEST']['ADJQT_SCHEMA'] = ['redCAP' => 'adjqt_schema', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['CANCER_TEST']['QT_FIRST_CYCLE_DATE'] = ['redCAP' => 'qt_first_cycle_date', 'type' => 'DATE'];
        $redCAPMapping['CANCER_TEST']['QT_LAST_CYCLE_DATE'] = ['redCAP' => 'qt_last_cycle_date', 'type' => 'DATE'];
        $redCAPMapping['CANCER_TEST']['RECURRENCE'] = ['redCAP' => 'recurrence', 'type' => 'BOOLEAN'];
        $redCAPMapping['CANCER_TEST']['RECURRENCE_DATE'] = ['redCAP' => 'recurrence_date', 'type' => 'DATE'];
        $redCAPMapping['CANCER_TEST']['RECURRENCE_TYPE'] = ['redCAP' => 'recurrence_type', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['CANCER_TEST']['RECURRENCE_TREATMENT'] = ['redCAP' => 'recurrence_treatment', 'type' => 'VERTICAL_RADIO'];

        $redCAPMapping['COLONOSCOPY_RESULTS']['EXTRACTION_DATE'] = ['redCAP' => 'extraction_date', 'type' => 'DATE'];
        $redCAPMapping['COLONOSCOPY_RESULTS']['NUMBER_REMOVED_POLYPS'] = ['redCAP' => 'number_removed_polyps', 'type' => 'NUMERICAL'];
        $redCAPMapping['COLONOSCOPY_RESULTS']['POLYPS_TABLE'] = ['redCAP' => '', 'type' => 'ARRAY'];
        $redCAPMapping['COLONOSCOPY_RESULTS']['POLYP_LOCATION'] = ['redCAP' => 'extraction_location', 'type' => 'VERTICAL_RADIO',
                'arrayRef' => 'POLYPS_TABLE'];
        $redCAPMapping['COLONOSCOPY_RESULTS']['MUCOSAL_DESC'] = ['redCAP' => 'mucosal_desc', 'type' => 'VERTICAL_CHECK'];
        $redCAPMapping['COLONOSCOPY_RESULTS']['INFLAMMATORY_BOWEL'] = ['redCAP' => 'inflammatory_bowel', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['COLONOSCOPY_RESULTS']['COLONOSCOPY_OTHER_DATA'] = ['redCAP' => 'colonoscopy_other_data', 'type' => 'TEXT_AREA'];

        $redCAPMapping['LESION_DESC']['POLYPS_NUMBER'] = ['redCAP' => 'polyps_extracted', 'type' => 'NUMERICAL'];
        $redCAPMapping['LESION_DESC']['POLYPS_TABLE'] = ['redCAP' => '', 'type' => 'ARRAY'];
        $redCAPMapping['LESION_DESC']['POLYOP_SIZE'] = ['redCAP' => 'polyop_size', 'type' => 'VERTICAL_RADIO', 'arrayRef' => 'POLYPS_TABLE'];
        $redCAPMapping['LESION_DESC']['POLYOP_DIAGNOSE'] = ['redCAP' => 'polyop_diagnose', 'type' => 'VERTICAL_RADIO', 'arrayRef' => 'POLYPS_TABLE'];
        $redCAPMapping['LESION_DESC']['NON_NEOPLASTIC_TYPE'] = ['redCAP' => 'non_neoplastic_type', 'type' => 'VERTICAL_RADIO',
                'arrayRef' => 'POLYPS_TABLE'];
        $redCAPMapping['LESION_DESC']['NEOPLASTIC_TYPE'] = ['redCAP' => 'neoplastic_type', 'type' => 'VERTICAL_RADIO', 'arrayRef' => 'POLYPS_TABLE'];
        $redCAPMapping['LESION_DESC']['NEOPLASTIC_HISTOLOGY'] = ['redCAP' => 'neoplastic_histology', 'type' => 'VERTICAL_RADIO',
                'arrayRef' => 'POLYPS_TABLE'];
        $redCAPMapping['LESION_DESC']['ADENOMA_MALIGNANCY'] = ['redCAP' => 'adenoma_malignancy', 'type' => 'VERTICAL_RADIO',
                'arrayRef' => 'POLYPS_TABLE'];
        $redCAPMapping['LESION_DESC']['NEOPLASTIC_DYSPLASIA'] = ['redCAP' => 'neoplastic_dysplasia', 'type' => 'VERTICAL_RADIO',
                'arrayRef' => 'POLYPS_TABLE'];
        $redCAPMapping['LESION_DESC']['SERRATED_TYPE'] = ['redCAP' => 'serrated_type', 'type' => 'VERTICAL_RADIO', 'arrayRef' => 'POLYPS_TABLE'];
        $redCAPMapping['LESION_DESC']['POLYOP_OTHER'] = ['redCAP' => 'polyop_other', 'type' => 'TEXT', 'arrayRef' => 'POLYPS_TABLE'];

        $redCAPMapping['ADENO_CHARACT']['PT'] = ['redCAP' => 'pt', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['ADENO_CHARACT']['PN'] = ['redCAP' => 'pn', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['ADENO_CHARACT']['PM'] = ['redCAP' => 'pm', 'type' => 'VERTICAL_RADIO'];
        $redCAPMapping['ADENO_CHARACT']['METASTASIS_LOCATION'] = ['redCAP' => 'metastasis_loc', 'type' => 'VERTICAL_CHECK'];
        $redCAPMapping['ADENO_CHARACT']['SPECIFOTHER_METLOC'] = ['redCAP' => 'specifother_metloc', 'type' => 'TEXT_AREA'];

        $redCAPMapping['BIOCHEMICAL']['BLOOD_TEST'] = ['redCAP' => 'blood_test', 'type' => 'BOOLEAN'];
        $redCAPMapping['BIOCHEMICAL']['DATE_BLOOD'] = ['redCAP' => 'date_blood', 'type' => 'DATE'];
        $redCAPMapping['BIOCHEMICAL']['LEUKOCYTES'] = ['redCAP' => 'leukocityes', 'type' => 'NUMERICAL'];
        $redCAPMapping['BIOCHEMICAL']['PLATELETS'] = ['redCAP' => 'platelets', 'type' => 'NUMERICAL'];
        $redCAPMapping['BIOCHEMICAL']['HEMOGLOBIN'] = ['redCAP' => 'hemoglobin', 'type' => 'NUMERICAL'];
        $redCAPMapping['BIOCHEMICAL']['GLUCOSE'] = ['redCAP' => 'glucose', 'type' => 'NUMERICAL'];
        $redCAPMapping['BIOCHEMICAL']['UREA'] = ['redCAP' => 'urea', 'type' => 'NUMERICAL'];
        $redCAPMapping['BIOCHEMICAL']['CREATININA'] = ['redCAP' => 'creatinina', 'type' => 'NUMERICAL'];
        $redCAPMapping['BIOCHEMICAL']['SODIUM'] = ['redCAP' => 'sodium', 'type' => 'NUMERICAL'];
        $redCAPMapping['BIOCHEMICAL']['POTASSIUM'] = ['redCAP' => 'potassium', 'type' => 'NUMERICAL'];
        $redCAPMapping['BIOCHEMICAL']['ALPS'] = ['redCAP' => 'alps', 'type' => 'NUMERICAL'];
        $redCAPMapping['BIOCHEMICAL']['BILIRUBIN'] = ['redCAP' => 'bilirubin', 'type' => 'NUMERICAL'];
        $redCAPMapping['BIOCHEMICAL']['ALT'] = ['redCAP' => 'alt', 'type' => 'NUMERICAL'];
        $redCAPMapping['BIOCHEMICAL']['AST'] = ['redCAP' => 'ast', 'type' => 'NUMERICAL'];
        $redCAPMapping['BIOCHEMICAL']['CHOLESTEROL_TOTAL'] = ['redCAP' => 'cholesterol_total', 'type' => 'NUMERICAL'];
        $redCAPMapping['BIOCHEMICAL']['HDL'] = ['redCAP' => 'hdl', 'type' => 'NUMERICAL'];
        $redCAPMapping['BIOCHEMICAL']['LDL'] = ['redCAP' => 'ldl', 'type' => 'NUMERICAL'];
        $redCAPMapping['BIOCHEMICAL']['TRIGLYCERIDES'] = ['redCAP' => 'triglycerides', 'type' => 'NUMERICAL'];
        $redCAPMapping['BIOCHEMICAL']['CEA'] = ['redCAP' => 'cea', 'type' => 'NUMERICAL'];
        $redCAPMapping['BIOCHEMICAL']['CEA199'] = ['redCAP' => 'cea199', 'type' => 'NUMERICAL'];

        return $redCAPMapping;
    }
}
