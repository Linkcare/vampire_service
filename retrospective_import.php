<?php
/*
 * Script to load the data from the retrospective ProCare4Life study.
 *
 */
error_reporting(E_ERROR); // Do not report warnings to avoid undesired characters in output stream

// PROGRAM CODE of the ProCare4Life care plan
$GLOBALS['RETROSPECTIVE_PROGRAM_CODE'] = 'PROCARE4LIFE';
// Owner of the subscription
$GLOBALS['RETROSPECTIVE_TEAM_CODE'] = 'LINKCARE';
// Directory containing the Excel files with the data of the retropective data (included in this project)
$GLOBALS['RETROSPECTIVE_IMPORT_DIR'] = '/var/www/html/services/vampire_service/retrospective';

require_once $_SERVER["DOCUMENT_ROOT"] . '/vendor/autoload.php';
use avadim\FastExcelReader\Excel;

require_once ("src/default_conf.php");

error_reporting(0);

$logger = ServiceLogger::init($GLOBALS['LOG_LEVEL'], null);

try {
    // The public rest function invoked from the Linkcare Platform's PROGRAM must be executed in a service session
    $apiSession = initServiceSession();
    import_files();
} catch (Exception $e) {
    $logger->error("General exception: " . $e->getMessage());
} catch (Error $e) {
    $logger->error("Execution error: " . $e->getMessage());
} finally {
    WSAPI::apiDisconnect();
}

/**
 * Initializes an API session for the Service User configured in the system.
 *
 * @return APISession
 */
function initServiceSession() {
    /* All the operations will be performed by a "service" user */
    $api = WSAPI::apiConnect($GLOBALS["WS_LINK"], null, $GLOBALS["SERVICE_USER"], $GLOBALS["SERVICE_PASSWORD"], null, null, false,
            $GLOBALS["DEFAULT_LANGUAGE"], $GLOBALS["DEFAULT_TIMEZONE"]);
    return $api->getSession();
}

/* ****************************************************************** */
/* ****************************************************************** */
function import_files() {
    $subscriptionId = null;

    $api = LinkcareSoapAPI::getInstance();
    if (!$subscriptionId) {
        // Find the subscription ID of the ONCOIVD program
        try {
            $subscription = $api->subscription_get($GLOBALS['RETROSPECTIVE_PROGRAM_CODE'], $GLOBALS['RETROSPECTIVE_TEAM_CODE']);
        } catch (APIException $e) {
            throw new Exception("Unable to find subscription for project " . $GLOBALS['RETROSPECTIVE_PROGRAM_CODE'] . ", Team" .
                    $GLOBALS['RETROSPECTIVE_TEAM_CODE'] . ". Please check the configuration of the service. Error: " . $e->getMessage());
        }
        $subscriptionId = $subscription->getId();
    }

    $importDir = $GLOBALS['RETROSPECTIVE_IMPORT_DIR'];

    $patientsList = importPatients($importDir . "/00_patient_list.xlsx", $subscriptionId);
    importQuestionnaires($importDir . "/01_retrospective_baseline.xlsx", $subscriptionId, $patientsList, 'baseline');
    importQuestionnaires($importDir . "/02_retrospective_eop.xlsx", $subscriptionId, $patientsList, 'end-of-study');

    // Discharge patients
    foreach ($patientsList as $patientData) {
        if (!$patientData['discharge_type']) {
            continue;
        }
        $api->admission_discharge($patientData['admission']->getId(), $patientData['discharge_type'], $patientData['end-of-study']);
    }
}

function importPatients($processFile, $subscriptionId) {
    $api = LinkcareSoapAPI::getInstance();

    try {
        $excel = Excel::open($processFile);
    } catch (Exception $e) {
        throw new Exception("Error opening file: $processFile: " . $e->getMessage());
    }

    $filename = basename($processFile);
    $excel->dateFormatter('Y-m-d');

    $sheetName = 'Patient';
    $sheet = $excel->sheet($sheetName);
    if (!$sheet) {
        throw new Exception("Sheet '$sheetName' not found in file: $filename");
    }

    $patientList = [];
    foreach ($sheet->nextRow([], Excel::KEYS_FIRST_ROW) as $rowNum => $rowData) {
        $patientRef = $rowData['ref'];

        $patient = findPatient($patientRef);
        if (!$patient) {
            // The CASE doesn't exist in the eCRF. Create it
            $patient = createPatient($patientRef, $rowData, $subscriptionId);
        }

        $admission = findAdmission($patient->getId());
        if (!$admission) {
            // The ADMISSION doesn't exist in the eCRF. Create it
            $admission = $api->admission_create($patient->getId(), $subscriptionId, $rowData['inclusion']);
        }

        // Step 3: Find the DEMOGRAPHIC task of the admission
        $filter = new TaskFilter();
        $taskCode = 'DEMOGRAPHIC';
        $filter->setTaskCodes($taskCode);
        $filter->setAdmissionIds($admission->getId());
        $tasks = $patient->getTaskList(1, 0, $filter);
        if (empty($tasks)) {
            // The DEMOGRAPHIC task doesn't exist. Create it automatically
            $taskId = $api->task_insert_by_task_code($admission->getId(), $taskCode);
            $tasks = [$api->task_get($taskId)];
        }

        $demographicForm = null;
        foreach ($tasks as $task) {
            foreach ($task->getForms() as $form) {
                if ($form->getFormCode() != 'DEMOGRAPHIC_DATA_FORM') {
                    continue;
                }
                $demographicForm = $form;
                break;
            }
        }
        if (!$demographicForm) {
            throw new Exception("The 'DEMOGRAPHIC_DATA_FORM' FORM was not found for patient $patientRef");
        }

        $questionsArray = [];

        $values = [];
        $values['EDUCATION_LEVEL'] = normalizeEducation($rowData['education']);
        $values['LABORAL_SITUATION'] = $rowData['laboral_situation'];
        $values['PROFESSIONAL_ACTIVITY'] = $rowData['position'];
        $values['YEAR_DIAGNOSIS'] = $rowData['year_diagnosis'];

        foreach ($values as $itemCode => $value) {
            if ($q = $form->findQuestion($itemCode)) {
                $q->setAnswer($value);
            } else {
                throw new Exception("Question with code $itemCode not found in form DEMOGRAPHIC_DATA_FORM for patient $patientRef. Please check the configuration of the form.");
            }
            $questionsArray[] = $q;
        }

        // Add the aliquots to the tracking form
        if (!empty($questionsArray)) {
            $api->form_set_all_answers($demographicForm->getId(), $questionsArray, true);
        }

        $task->setDate($rowData['inclusion']);
        $task->save(true);

        $patientList[$patientRef] = ['patient' => $patient, 'admission' => $admission, 'baseline' => $rowData['inclusion'],
                'end-of-study' => $rowData['end'], 'discharge_type' => $rowData['discharge_type']];
    }

    return $patientList;
}

/* ******* Internal funcions ************************************************** */
function importQuestionnaires($processFile, $subscriptionId, $patientsList, $stage) {
    $api = LinkcareSoapAPI::getInstance();
    $logger = ServiceLogger::getInstance();

    try {
        $excel = Excel::open($processFile);
    } catch (Exception $e) {
        throw new Exception("Error opening file: $processFile: " . $e->getMessage());
    }

    $sheetNames = ['CIRS' => 'CIRS-G', 'FALLS EFFICACY SCALE (FES)' => 'FES_ASSESSMENT_FORM', 'BARTHEL INDEX OF ADL (BI)' => 'BARTHEL',
            'Berg balance test' => 'BERG_BALANCE_SCALE', 'MDS-UPDRS-1' => 'MDS-UPDRS-1', 'MDS-UPDRS-2' => 'MDS-UPDRS-2',
            'MDS-UPDRS-3' => 'MDS-UPDRS-3', 'MDS-UPDRS-4' => 'MDS-UPDRS-4', 'EURO QoL 5D' => 'EQ_5D_5DL',
            'Pittsburg Sleep Quality Index' => 'PCI_PSQIS_FORM', 'State Trai Anxiety Inventory' => 'STAI_FORM'];

    $filename = basename($processFile);
    $excel->dateFormatter('Y-m-d');

    $updatedForms = []; // List of Forms updated for each patient, it will be used later to remove the FORMS that have not been updated
    foreach ($patientsList as $patientRef => $patientData) {
        $updatedForms[$patientRef] = [];
    }

    foreach ($sheetNames as $sheetName => $formCode) {
        $sheet = $excel->sheet($sheetName);
        if (!$sheet) {
            throw new Exception("Sheet '$sheetName' not found in file: $filename");
        }

        $headers = [];
        foreach ($sheet->nextRow() as $rowNum => $rowData) {
            if ($rowNum == 1) {
                continue;
            } elseif ($rowNum == 2) {
                $headers = array_values($rowData);
                continue;
            }

            $rowData = array_combine($headers, array_slice(array_values($rowData), 0, count($headers)));
            $patientRef = $rowData['patient'];

            if (!$patientRef) {
                throw new Exception("Patient reference is missing in sheet $sheetName, row $rowNum");
            }
            unset($rowData['patient']);

            if (!array_key_exists($patientRef, $patientsList)) {
                $logger->warning("Patient not present in patients list (Sheet: $sheetName, Row: $rowNum, Ref: $patientRef)=> IGNORED");
                continue;
            }

            $admission = $patientsList[$patientRef]['admission'];

            /** @var APIForm $form */
            /** @var APITask $task */
            $taskDate = $patientsList[$patientRef][$stage];
            if (!$taskDate) {
                $logger->warning(
                        "Task date is missing for patient $patientRef, stage $stage. Skipping questionnaire import for this patient and stage.");
                continue;
            }

            try {
                $task = findProcare4LifeTask($patientRef, $admission, $taskDate, true, $stage);
                $form = findProcare4LifeForm($task, $formCode);
            } catch (Exception $e) {
                $msg = "ERROR: Patient $patientRef, Stage $stage => " . $e->getMessage();
                throw new Exception($msg);
            }

            $arrQuestions = [];
            $nonEmpty = 0;
            foreach ($rowData as $itemCode => $answer) {
                if (startsWith('#', $itemCode)) {
                    // Calculated field. Skip it
                    continue;
                }
                $q = $form->findQuestion($itemCode);
                if (!$q) {
                    // May be a conditioned question
                    $logger->info(
                            "Question with code $itemCode not found in form $formCode for patient $patientRef, stage $stage. May be a conditioned question => ADDED MANUALLY");
                    $q = new APIQuestion($itemCode, $answer, '?', APIQuestionTypes::VERTICAL_RADIO);
                }
                if (APIQuestionTypes::isSingleOption($q->getType()) || APIQuestionTypes::isMultiOptions($q->getType())) {
                    $q->setOptionAnswer('?', $answer);
                } else {
                    $q->setAnswer($answer);
                }
                $arrQuestions[] = $q;
                $nonEmpty += (isNullOrEmpty($answer) ? 0 : 1);
            }
            if ($nonEmpty) {
                $api->form_set_all_answers($form->getId(), $arrQuestions, true);
                $updatedForms[$patientRef][] = $formCode;
                $task->setDate($taskDate);
                $task->save(true);
            }
        }
    }

    // Delete the FORMS that are not informed
    foreach ($patientsList as $patientRef => $patientData) {
        $admission = $patientData['admission'];
        $taskDate = $patientData[$stage];
        $formsToKeep = $updatedForms[$patientRef];

        try {
            $task = findProcare4LifeTask($patientRef, $admission, $taskDate);
        } catch (Exception $e) {}

        if ($task) {
            $deleted = 0;
            foreach ($task->getForms() as $form) {
                if (!in_array($form->getFormCode(), $sheetNames) || !$form->isOpen() || in_array($form->getFormCode(), $formsToKeep)) {
                    continue;
                }
                $logger->warning(
                        "Form " . $form->getFormCode() .
                        " will be deleted for patient $patientRef, stage $stage, because it is not present in the import file");
                $api->form_delete($form->getId());
                $deleted++;
            }
            if ($deleted) {
                /*
                 * Just in case the TASK was open and finished closed after deleting some FORMS, because in that
                 * case the date would have been adjusted to the current date
                 *
                 */
                $task->setDate($taskDate);
                $task->save(true);
            }
        }
    }
}

/**
 *
 * @return APICase
 */
function createPatient($patientRef, $rowData, $subscriptionId) {
    $api = LinkcareSoapAPI::getInstance();

    // Create a new patient in the eCRF
    $contactData = new APIContact();
    $contactData->setBirthdate($rowData['birthdate']);
    $gender = strtoupper($rowData['gender']);
    if (!in_array($gender, ['M', 'F'])) {
        throw new Exception("Invalid gender for patient $patientRef");
    }
    $contactData->setGender($gender);
    $identifier = new APIIdentifier();
    $identifier->setId('STUDY_REF');
    $identifier->setProgramId($GLOBALS['RETROSPECTIVE_PROGRAM_CODE']);
    $identifier->setTeamId($GLOBALS['RETROSPECTIVE_TEAM_CODE']);
    $identifier->setValue($patientRef);
    $contactData->addIdentifier($identifier);
    $patient = $api->case_insert($contactData, $subscriptionId);
    if (!$patient) {
        throw new ServiceException(ErrorCodes::DATA_MISSING, "Unable to create patient with reference $patientRef");
    }

    return $patient;
}

/**
 *
 * @param string $patientRef
 * @return APICase;
 */
function findPatient($patientRef) {
    $api = LinkcareSoapAPI::getInstance();

    $searchFilter = new stdClass();
    $searchFilter->identifier = new stdClass();
    $searchFilter->identifier->program = $GLOBALS['RETROSPECTIVE_PROGRAM_CODE'];
    $searchFilter->identifier->team = $GLOBALS['RETROSPECTIVE_TEAM_CODE'];
    $searchFilter->identifier->code = 'STUDY_REF';
    $searchFilter->identifier->value = $patientRef;

    $patients = $api->case_search(json_encode($searchFilter));
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
function findAdmission($patientId) {
    $api = LinkcareSoapAPI::getInstance();

    $patientAdmissions = $api->case_admission_list($patientId);
    $admission = null;
    foreach ($patientAdmissions as $adm) {
        if ($adm->getSubscription()->getProgram()->getCode() != $GLOBALS['RETROSPECTIVE_PROGRAM_CODE']) {
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

function normalizeEducation($education) {
    $normalized = strtolower(trim($education));
    switch ($normalized) {
        case 'primary education' :
            return 'Primary';
        case 'secondary education' :
        case 'upper secondary education' :
        case 'high school diploma' :
            return 'Secondary';
        case 'bachelor' :
            return 'Bachelor';
        case 'master' :
        case 'bachelor/master' :
            return 'Master';
        default :
            return $education;
    }
}

/**
 * Find the the TASK with with TASK_CODE "PROCARE4LIFE_ASSESSMENT" and the specified date
 *
 * @param string $patientRef
 * @param APIAdmission $admission
 * @param string $date
 * @param boolean $createIfNotExist If true, the TASK will be created if it doesn't exist. Otherwise, the function will return NULL if the TASK
 *        doesn't exist
 * @return APITask
 */
function findProcare4LifeTask($patientRef, $admission, $date, $createIfNotExist = false, $stage = 'baseline') {
    $api = LinkcareSoapAPI::getInstance();

    $filter = new TaskFilter();
    $filter->setTaskCodes('PROCARE4LIFE_ASSESSMENT');
    $tasks = $admission->getTaskList(2, 0, $filter);

    $task = null;
    foreach ($tasks as $t) {
        if ($t->getDate() != $date) {
            continue;
        }
        $task = $t;
        break;
    }

    if (!$task && $createIfNotExist) {
        // The requested task doesn't exist. Create it automatically
        $parameters = new stdClass();
        $parameters->code = 'ASSESSMENT_TYPE';
        $parameters->value = $stage;
        $taskId = $api->task_insert_by_task_code($admission->getId(), 'PROCARE4LIFE_ASSESSMENT', $date, [$parameters]);
        $task = $api->task_get($taskId);
    }

    if (!$task) {
        throw new Exception("Task PROCARE4LIFE_ASSESSMENT not found");
    }

    return $task;
}

/**
 * Find the the FORM with the specified FORM CODE in the "PROCARE4LIFE_ASSESSMENT" TASK
 * Returns NULL if the FORM is up to date (the aliquots have already been registered)
 *
 * @throws Exception If the FORM with the specified FORM CODE cannot be found or if there is any error
 * @param APITask $task
 * @param string $formCode
 * @return APIForm
 */
function findProcare4LifeForm($task, $formCode) {
    $form = null;

    foreach ($task->getForms() as $f) {
        if ($f->getFormCode() != $formCode) {
            continue;
        }
        $form = $f;
        break;
    }

    if (!$form) {
        throw new Exception("Form $formCode not found in task " . $task->getId());
    }

    return $form;
}

