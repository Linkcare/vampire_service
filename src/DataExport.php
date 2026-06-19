<?php

class DataExport {
    private $exportDir;
    private $includeTaskDate = false;

    public function __construct($exportDir, $includeTaskDate = false) {
        $this->exportDir = $exportDir;
        $this->includeTaskDate = $includeTaskDate;
        // Reset the export directory before starting a new export
        self::resetExportDir();
    }

    /**
     *
     * @param APIAdmission $admission
     * @param string[] $formList
     */
    public function exportFormsData($patientRef, $admission, $formList) {
        $formDataT0 = []; // Clinical scales collected at the baseline visit (T0)
        $formDataT1 = []; // Clinical scales collected at the 2 years visit (T1)

        foreach ($formList as $taskCode => $exportableForms) {
            $filter = new TaskFilter();
            $filter->setTaskCodes($taskCode);
            $filter->setStatusIds('CLOSED');
            foreach ($exportableForms as $exportableData) {
                $formDataT0[$exportableData['formCode']] = []; // Initialize the data array for each form
                $formDataT1[$exportableData['formCode']] = []; // Initialize the data array for each form
            }

            $ixTask = 0;
            foreach ($admission->getTaskList(2, 0, $filter) as $task) {
                foreach ($exportableForms as $exportableData) {
                    $formCode = $exportableData['formCode'];
                    $itemCodes = $exportableData['items'];
                    $data = ServiceFunctions::exportFormData($admission, $task, $formCode, $itemCodes);
                    if ($data && count($data) > 0) {
                        if ($ixTask == 0) {
                            $formDataT0[$formCode] = $data[0];
                        } else {
                            $formDataT1[$formCode] = $data[0];
                        }
                    }
                    continue;
                }
                $ixTask++;
            }
        }

        // The MDS-UPDRS scale is splitted in several forms which must be merged in a single file for export
        $this->mergeMDSUPDRSData($formDataT0);
        $this->mergeMDSUPDRSData($formDataT1);
        foreach ($formDataT0 as $formCode => $formData) {
            if (!empty($formData)) {
                $formData['PATIENT_REF'] = $patientRef;
                $formData['DATE'] = DateHelper::datePart($formData['task_date']);
                $this->writeDataToCSV($formCode, $formData, 'T0');
            }
        }
        foreach ($formDataT1 as $formCode => $formData) {
            if (!empty($formData)) {
                $formData['PATIENT_REF'] = $patientRef;
                $formData['DATE'] = DateHelper::datePart($formData['task_date']);
                $this->writeDataToCSV($formCode, $formData, 'T1');
            }
        }
    }

    /**
     * The MDS-UPDRS scale is splitted in several forms (MDS-UPDRS-1, MDS-UPDRS-2, MDS-UPDRS-3 and MDS-UPDRS-4).
     * This function merges the data of these forms in a single array, to be exported in a single file.
     *
     * @param string[] $data
     */
    private function mergeMDSUPDRSData(&$data) {
        $mergedData = [];

        $mdsForms = ['MDS-UPDRS-1' => null, 'MDS-UPDRS-2' => null, 'MDS-UPDRS-3' => null, 'MDS-UPDRS-4' => null];

        foreach (array_keys($mdsForms) as $formCode) {
            if (array_key_exists($formCode, $data)) {
                $mergedData = array_merge($mergedData, $data[$formCode]);
                unset($data[$formCode]);
            }
        }

        if (!empty($mergedData)) {
            $data['MDS-UPDRS'] = $mergedData;
        }
    }

    /**
     * Deletes the CSV files in the provided directory, to reset the data export directory before a new export is performed.
     *
     * @param string $dir
     */
    private function resetExportDir() {
        $files = glob($this->exportDir . '/*.csv');
        foreach ($files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Normalize the data retrieved from an eCRF form to ensure that has all the required fields so that the exported data has always the same
     * structure
     *
     * @param string $formCode
     * @param string[] $formData
     * @return string[]
     */
    private function normalizeFormData($formCode, $formData) {
        $normalizedStructure = self::exportableFields($formCode);
        $normalizedData = [];
        foreach ($normalizedStructure as $itemCode) {
            if (array_key_exists($itemCode, $formData)) {
                $normalizedData[$itemCode] = $formData[$itemCode];
            } else {
                $normalizedData[$itemCode] = ''; // If the item is not informed, it will be included in the normalized data with an empty value
            }
        }

        return $normalizedData;
    }

    /**
     * Writes the provided data to a CSV file with the provided form code as name in the export directory.
     * If the file does not exist, it will be created and a header with the keys of the data array will be included as first row. If the file already
     * exists, the data will be appended to the existing file.
     *
     * @param string $formCode
     * @param string $data
     */
    public function writeDataToCSV($formCode, $data, $period = null) {
        if (empty($data)) {
            return;
        }

        $data = $this->normalizeFormData($formCode, $data);

        $period = $period ? $period . '_' : '';
        $filename = $this->exportDir . "/$period$formCode.csv";
        if (!file_exists($filename)) {
            $header = array_keys($data);
            $fp = fopen($filename, 'w');
            fputcsv($fp, $header, ';', '"', '\\');
        } else {
            $fp = fopen($filename, 'a');
        }

        fputcsv($fp, $data, ';', '"', '\\');
        fclose($fp);
    }

    /**
     * Returns the list of items that must be included in the export for the provided form code.
     * If the form code is not recognized, an empty array is returned.
     *
     * @param string $formCode
     * @return string[]
     */
    public function exportableFields($formCode) {
        $baseFields = $this->includeTaskDate ? ['PATIENT_REF', 'DATE'] : ['PATIENT_REF'];

        $formDetails['PATIENTS'] = ['BIRTHDATE', 'GENDER', 'ENROL_DATE', 'COHORT', 'SITE'];

        $formDetails['CLINICAL_HISTORY_FORM'] = ['DIAGNOSIS_YEAR', 'EDUCATION', 'PROFESSIONAL_ACTIVITY', 'LATERALITY', 'CARDIOVASCULAR_DISEASES',
                'CORONARY_ONSET', 'HEART_ATTACK_ONSET', 'HEART_FAILURE_ONSET', 'ARRHYTHMIA_ONSET', 'HYPERTENSION_ONSET', 'OTHER_SPECIFY',
                'COMORBIDITIES', 'DIABETES_ONSET', 'ANEMIA_ONSET', 'CANCER_ONSET', 'ENCEPHALOPATHY_ONSET', 'DEPRESSION_ONSET', 'CONSTIPATION_ONSET',
                'OTHER_COMORBIDITIES', 'FAMILY_PARKINSON', 'RELATIVES_PD', 'FAMILY_NEURODEGENERATIVE', 'FAMILY_NEURODEGENERATIVE_DETAILS',
                'FAMILY_NEURODEGENERATIVE_OTHER', 'GENETIC_INSIGHTS', 'SOCIAL_SUPPORT', 'DIET', 'SMOKING', 'ALCOHOL', 'SLEEP_DISORDERS',
                'BRAIN_INJURY', 'RETROSPECTIVE_DATA', 'RETROSPECTIVE_ID', 'REHAB_TREATMENT', 'REHAB_OTHER', 'NON_PD_UNDER_TREATMENT', 'DBS_THERAPY',
                'DRUG_TREATMENT', 'OTHER_DRUGS'];

        // Common (Non-PD and PD) clinical assessment forms
        $formDetails['BERG_BALANCE_SCALE'] = ['SITTING_TO_STANDING', 'STANDING_UNSUPPORTED', 'SITTING_BACK_UNSUPPORTED', 'STANDING_TO_SITTING',
                'TRANSFERS', 'STANDING_UNSUPPORTED_EYES_CLOSED', 'STANDING_UNSUPPORTED_FEET_TOGETHER', 'REACHING_FORWARD', 'PICK_UP_OBJECT',
                'LOOK_BEHIND', 'TURN_360', 'ALTERNATE_FOOT', 'STANDING_ONE_FOOT', 'STANDING_ONE_LEG', 'SCORE'];

        $formDetails['CIRS-G'] = ['HEART', 'VASCULAR', 'HEMATOPOIETIC', 'RESPIRATORY', 'EYES_EARS_NOSE_THROAT', 'UPPER_GI', 'LOWER_GI', 'LIVER',
                'RENAL', 'GENITOURINARY', 'MUSCULOSKELETAL', 'NEUROLOGICAL', 'ENDOCRINE', 'PSYCHIATRIC', 'SCORE', 'NUM_ENDORSED', 'SEVERITY_INDEX',
                'SEVERITY_3', 'SEVERITY_4'];
        $formDetails['MMSE_PARKINSON_FORM'] = ['TEMPORAL_ORIENTATION', 'SPATIAL_ORIENTATION', 'SCORE'];

        $formDetails['GDS_SHORT_FORM'] = ['GDS1', 'GDS2', 'GDS3', 'GDS4', 'GDS5', 'GDS6', 'GDS7', 'GDS8', 'GDS9', 'GDS10', 'GDS11', 'GDS12', 'GDS13',
                'GDS14', 'GDS15', 'GDS_SCORE', 'ASSESSMENT'];

        // PD clinical assessment forms
        // PD specific clinical assessment forms
        $formDetails['PD-CRS'] = ['INMEDIATE_MEMORY', 'CONFRONTATION_NAMING', 'SUSTAINED_ATTENTION', 'WORKING_MEMORY', 'UMPROMPTED_CLOCK',
                'COPY_CLOCK', 'DELAYED_MEMORY', 'ALTERNATING_FLUENCY', 'ACTION_FLUENCY', 'FRONTO_SCORE', 'POSTERIOR_SCORE', 'TOTAL_SCORE'];

        $formDetails['PDQ-8'] = ['DIFFICULTY_GETTING_AROUND', 'DIFFICULTY_DRESSING', 'FELT_DEPRESSED', 'PROBLEMS_RELATIONSHIPS',
                'PROBLEMS_CONCENTRATION', 'UNABLE_COMMUNICATE', 'PAINFUL_MUSCLE', 'FELT_EMBARRASSED', 'SCORE'];

        $formDetails['PD-CFRS'] = ['ANSWERED_BY', 'TROUBLE_MONEY', 'TROUBLE_ECONOMY', 'TROUBLE_HOLIDAYS', 'TROUBLE_APPOINTMENTS', 'TROUBLE_MEDICINES',
                'TROUBLE_DAILY_ACTIVITIES', 'DIFFICULTY_APPLIANCES', 'TROUBLE_PUBLIC_TRANSPORT', 'PROBLEM_SOLVING', 'TROUBLE_EXPLAIN',
                'TROUBLE_READING', 'TROUBLE_CELL_PHONE', 'SUM_SCORE_LE_2', 'NUM_SCORE_LE_2', 'SCORE'];

        $formDetails['MDS-UPDRS-1'] = ['Q1_A', 'Q1_1', 'Q1_2', 'Q1_3', 'Q1_4', 'Q1_5', 'Q1_6', 'Q1_6A', 'Q1_7', 'Q1_8', 'Q1_9', 'Q1_10', 'Q1_11',
                'Q1_12', 'Q1_13', 'TOTAL_1'];

        $formDetails['MDS-UPDRS-2'] = ['Q2_1', 'Q2_2', 'Q2_3', 'Q2_4', 'Q2_5', 'Q2_6', 'Q2_7', 'Q2_8', 'Q2_9', 'Q2_10', 'Q2_11', 'Q2_12', 'Q2_13',
                'TOTAL_2'];

        $formDetails['MDS-UPDRS-3'] = ['Q3A', 'Q3B', 'Q3_C', 'Q3_C1', 'Q3_1', 'Q3_2', 'Q3_3A', 'Q3_3B', 'Q3_3C', 'Q3_3D', 'Q3_3E', 'Q3_4A', 'Q3_4B',
                'Q3_5A', 'Q3_5B', 'Q3_6A', 'Q3_6B', 'Q3_7A', 'Q3_7B', 'Q3_8A', 'Q3_8B', 'Q3_9', 'Q3_10', 'Q3_11', 'Q3_12', 'Q3_13', 'Q3_14', 'Q3_15A',
                'Q3_15B', 'Q3_16A', 'Q3_16B', 'Q3_17A', 'Q3_17B', 'Q3_17C', 'Q3_17D', 'Q3_17E', 'Q3_18', 'DYSC_PRESENT', 'DYSC_INTERFERE',
                'HOEHN_YAHR', 'TOTAL_3'];

        $formDetails['MDS-UPDRS-4'] = ['Q4_1', 'Q4_2', 'Q4_3', 'Q4_4', 'Q4_5', 'Q4_6', 'TOTAL_4'];

        $formDetails['MDS-UPDRS'] = array_merge($formDetails['MDS-UPDRS-1'], $formDetails['MDS-UPDRS-2'], $formDetails['MDS-UPDRS-3'],
                $formDetails['MDS-UPDRS-4']);

        $formDetails['DEMOGRAPHIC_DATA_FORM'] = ['BIRTHDATE', 'GENDER', 'EDUCATION_LEVEL', 'LABORAL_SITUATION', 'PROFESSIONAL_ACTIVITY',
                'YEAR_DIAGNOSIS'];
        $formDetails['FES_ASSESSMENT_FORM'] = ['CLEANING', 'DRESSED', 'MEALS', 'BATH', 'SHOPPING', 'CHAIR', 'STAIRS', 'NEIGHBOURHOOD', 'REACHING',
                'TELEPHONE', 'SLIPPERY', 'FRIEND', 'CROWDS', 'UNEVEN', 'SLOPE', 'SOCIAL', 'FES_SCORE'];
        $formDetails['BARTHEL'] = ['FEEDING', 'GROOMING', 'TOILET_USE', 'BATHING', 'BOWELS', 'BLADDER', 'DRESSING', 'TRANSFER', 'MOBILITY', 'STAIRS',
                'SCORE'];
        $formDetails['EQ_5D_5DL'] = ['MOBILITY', 'SELFCARE', 'ADL', 'PAIN', 'DEPRESS', 'SELF_HEALTH_COND', 'SCORE'];
        $formDetails['PCI_PSQIS_FORM'] = ['BEDTIME', 'FALL_ASLEEP_TIME', 'GETUP_TIME', 'SLEEP_TIME_NIGHT', 'INABILITY_SLEEP', 'WAKING_UP',
                'GO_BATHROOM', 'BREATHING', 'COUGH_SNORE', 'COLD', 'HOT', 'BAD_DREAMS', 'PAIN', 'OTHER_REASONS', 'OTHER_REASONS_SPECIFY',
                'SUBJ_SLEEP_QUALITY', 'MEDICINES_SLEEP', 'TROUBLE_STAYING_AWAKE', 'THINGS_DONE', 'BED_PARTNER', 'ROOMMATE_SNORING', 'ROOMMATE_PAUSES',
                'ROOMMATE_TWITCH', 'ROOMMATE_DESORIENTATION', 'ROOMMATE_OTHER', 'ROOMMATE_OTHER_SPECIFY', 'C1_SCORE', 'C2_SCORE', 'C3_SCORE',
                'C4_SCORE', 'C5_SCORE', 'C6_SCORE', 'C7_SCORE', 'PSQI_SCORE'];
        $formDetails['STAI_FORM'] = ['NOW_CALM', 'NOW_SECURE', 'NOW_TENSE', 'NOW_STRAINED', 'NOW_EASE', 'NOW_UPSET', 'NOW_MISFORTUNES',
                'NOW_SATISFIED', 'NOW_FRIGHTENED', 'NOW_UNCOMFORTABLE', 'NOW_SELFCONFIDENT', 'NOW_NERVOUS', 'NOW_JITTERY', 'NOW_INDECISIVE',
                'NOW_RELAXED', 'NOW_CONTENT', 'NOW_WORRIED', 'NOW_CONFUSED', 'NOW_STEADY', 'NOW_PLEASANT', 'GEN_PLEASANT', 'GEN_NERVOUS',
                'GEN_SATISFIED', 'GEN_WISH_HAPPY', 'GEN_FAILURE', 'GEN_RESTED', 'GEN_CALM', 'GEN_DIFICULTIES', 'GEN_WORRIED', 'GEN_HAPPY',
                'GEN_DISTURBING_THOUGHTS', 'GEN_LACK_CONFIDENCE', 'GEN_SECURE', 'GEN_DECIDED', 'GEN_INADEQUATE', 'GEN_CONTENT',
                'GEN_UNIMPORTANT_THOUGHTS', 'GEN_DISAPPOINTMENT', 'GEN_STEADY', 'GEN_TENSE', 'NOW_TOTAL', 'GEN_TOTAL'];

        foreach ($formDetails as $code => $items) {
            $formDetails[$code] = array_merge($baseFields, $items);
        }

        return array_key_exists($formCode, $formDetails) ? $formDetails[$formCode] : [];
    }
}

