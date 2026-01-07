<?php

class ServiceFunctions {

    /**
     *
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
                $aliquotId = $row[$sampleType . "_" . AliquotTrackingItems::ID]->getValue();
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

        // Remove previous Aliquots of this TASK from the database if they exist
        ShipmentFunctions::removeAliquotsByTask($processingForm->getParentId());
        ShipmentFunctions::trackAliquots($dbRows, AliquotAuditActions::CREATED);

        // Concatenate the added aliquot IDs into a string
        $aliquotsIncluded = implode(',', $aliquotIds);
        return new ServiceResponse($aliquotsIncluded, null);
    }

    /**
     *
     * @param string $patientId
     * @throws ServiceException|APIException
     * @return APIAdmission
     */
    static function findAdmission($patientId) {
        $api = LinkcareSoapAPI::getInstance();

        $patientAdmissions = $api->case_admission_list($patientId);
        $admission = null;
        foreach ($patientAdmissions as $adm) {
            if (in_array($adm->getStatus(),
                    [APIAdmission::STATUS_REJECTED, APIAdmission::STATUS_ENROLLED, APIAdmission::STATUS_DISCHARGED, APIAdmission::STATUS_INCOMPLETE])) {
                // Only Admissions that have passed the enroll stage should be considered
                continue;
            }
            if ($adm->getSubscription()->getProgram()->getCode() != $GLOBALS['PROJECT_CODE']) {
                continue;
            }
            $admission = $adm;
            break;
        }

        if (!$admission) {
            throw new ServiceException(ErrorCodes::DATA_MISSING, "No admission found for patient $patientId");
        }

        return $admission;
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
        $aliquotIds = array_map(function ($aliquot) {
            return $aliquot->id;
        }, $aliquots);
        ShipmentFunctions::markTrackedAliquots('SHIPMENT', $shipment->id, $shipmentTask->getId(), $aliquotIds);
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
        $aliquotIds = array_map(function ($aliquot) {
            return $aliquot->id;
        }, $aliquots);
        ShipmentFunctions::markTrackedAliquots('RECEPTION', $shipment->id, $shipmentTask->getId(), $aliquotIds);
    }

    static private function addLocation($locationId) {
        $api = LinkcareSoapAPI::getInstance();

        if (ShipmentFunctions::locationExists($locationId)) {
            return;
        }
        $team = $api->team_get($locationId);
        ShipmentFunctions::addLocation($locationId, $team->getCode(), $team->getName());
    }

    /**
     * Updates the status of the blood sample aliquots after an action (shipment, reception...)
     * This functions assumes that already exists a FORM that contains the last known status of the blood samples and modifies the status according to
     * the action executed
     *
     * @param APIQuestion[] $modifiedAliquotsArray
     * @param string $sampleType
     * @param string|APIForm $statusForm Reference of the FORM containing the current status of the blood samples
     * @return ServiceResponse
     */
    static public function updateSamplesStatus($modifiedAliquotsArray, $sampleType, $statusForm) {
        // Load the list of all existing aliquots to update the status of the modified ones
        /** @var APIForm $samplesStatusForm */
        /** @var APIQuestion[][] $aliquotStatusArray */
        list($samplesStatusForm, $aliquotStatusArray) = self::loadAliquotStatus($statusForm, AliquotStatus::ALL, null);

        $updatableFieldMap = [];

        $nowUTC = DateHelper::currentDate();
        foreach ($modifiedAliquotsArray as $aliquotId => $modififedAliquot) {
            if (!array_key_exists($aliquotId, $aliquotStatusArray)) {
                throw new ServiceException(ErrorCodes::DATA_MISSING, "$sampleType aliquot $aliquotId not found in the last known status (form " .
                        $samplesStatusForm->getId() . ")");
            }
            // The modified aliquot exists in the status form. We can proceed with the update
            $currentStatus = $aliquotStatusArray[$aliquotId];

            // Update the datetime of the last modification

            if ($lastChangeDateItem = $currentStatus[AliquotStatusItems::CHANGE_DATE]) {
                $lastChangeDateItem->setAnswer(DateHelper::datePart($nowUTC));
            }
            if ($lastChangeTimeItem = $currentStatus[AliquotStatusItems::CHANGE_TIME]) {
                $lastChangeTimeItem->setAnswer(DateHelper::timePart($nowUTC));
            }

            foreach ($modififedAliquot as $key => $modifiedQuestion) {
                if (array_key_exists($key, $updatableFieldMap)) {
                    // Map the ITEM name from the modified FORM to the corresponding ITEM name in the status FORM
                    $mappedFieldName = $updatableFieldMap[$key];
                    // Ignore fields that should not be modified
                    continue;
                } elseif (AliquotStatusItems::isValidValue($key)) {
                    $mappedFieldName = $key;
                } else {
                    // Ignore fields that should not be modified
                    continue;
                }

                /** @var APIQuestion $currentStatusItem */
                $currentStatusItem = $currentStatus[$mappedFieldName];
                if (!$currentStatusItem) {
                    throw new ServiceException(ErrorCodes::DATA_MISSING, "The status record of aliquot $aliquotId does not contain the field $mappedFieldName");
                }

                if ($modifiedQuestion->getValue() === $currentStatusItem->getValue()) {
                    // No changes in this field. We can skip the update
                    continue;
                }
                switch ($mappedFieldName) {
                    case AliquotStatusItems::STATUS :
                    case AliquotStatusItems::DAMAGE :
                        $currentStatusItem->setOptionAnswer($modifiedQuestion->getOptionId(), $modifiedQuestion->getOptionValue());
                        break;
                    default :
                        $currentStatusItem->setAnswer($modifiedQuestion->getValue());
                        break;
                }
            }
        }

        $samplesStatusForm->updateAnswers();

        // Concatenate the IDs of the modified aliquots in a string
        $aliquotsModified = implode(',', array_keys($modifiedAliquotsArray));
        return new ServiceResponse($aliquotsModified, null);
    }

    /**
     * Retrieves the list of aliquots from the Form that contains the last known status of the blood samples.
     * The aliquots can be filtered by status and owner<br>
     * The return value is an associative array indexed by the aliquot ID
     *
     * @param int $aliquotStatusFilter Indicate which aliquots should be loaded. Use one of the AliquotStatus constants
     * @param string|APIForm $referenceForm
     * @param string $senderId Reference of the owner of the aliquots. If null, all aliquots will be included
     * @return [APIForm, APIQuestion[][]]
     */
    static private function loadAliquotStatus($referenceForm, $aliquotStatusFilter, $ownerFilter) {
        // Load the array of aliquots of the status FORM
        list($samplesStatusForm, $array) = self::loadStatusForm($referenceForm);

        $aliquotsArray = [];

        // Filter the aliquots according to the requested filters (status, owner)
        foreach ($array as $row) {
            /** @var APIQuestion[] $row */
            $aliquotId = $row[AliquotStatusItems::ID]->getValue();

            $curStatus = $row[AliquotStatusItems::STATUS]->getOptionValue();
            if (!$curStatus) {
                $curStatus = AliquotStatus::AVAILABLE;
            }
            $owner = $row[AliquotStatusItems::LOCATION]->getValue();
            if (($curStatus == $aliquotStatusFilter || $aliquotStatusFilter == AliquotStatus::ALL) && ($owner == $ownerFilter || !$ownerFilter)) {
                $aliquotsArray[$aliquotId] = $row;
            }
        }

        return [$samplesStatusForm, $aliquotsArray];
    }

    /**
     * Retrieves the list of aliquots of a shipment tracking FORM
     * The return value is an associative array indexed by the aliquot ID
     *
     * @param APIForm $samplesStatusForm
     * @return APIQuestion[]
     */
    static public function loadTrackedAliquots($samplesStatusForm) {
        // Fetch all the rows of the array. The list of fields loaded will depend on the type of action
        $requiredItems = [AliquotTrackingItems::ID, AliquotTrackingItems::TYPE];
        $array = $samplesStatusForm->getArrayQuestions(AliquotTrackingItems::ARRAY);

        // Verify that all the required items are present in the array and create an associative array indexed by the aliquot ID
        $aliquotsArray = [];
        foreach ($array as $ix => $row) {
            $itemCodes = array_keys($row);
            foreach ($requiredItems as $key) {
                if (!in_array($key, $itemCodes)) {
                    throw new ServiceException(ErrorCodes::DATA_MISSING, "Error loading the column '$key' of the blood sample $ix from the status form " .
                            $samplesStatusForm->getId());
                }
            }

            $aliquotId = $row[AliquotTrackingItems::ID]->getValue();
            $aliquotsArray[$aliquotId] = $row;
        }

        return $aliquotsArray;
    }

    /**
     * Find the "BLOOD PROCESSING" TASK of a patient given his reference
     * Returns NULL if the FORM is up to date (the aliquots have already been registered)
     *
     * @throws ServiceException If the FORM of the patient cannot be found or if there is any error
     * @param string $patientRef
     * @param string $teamCode Code of the supscription owner team
     * @return [APICase, APIForm]
     */
    static public function findFormFromPatientRef($patientRef, $teamCode) {
        $api = LinkcareSoapAPI::getInstance();

        // Step 1: Find patients of the program that have the specified blood sample ID
        $filter = ['identifier' => ['code' => 'STUDY_REF', 'value' => $patientRef, 'program' => $GLOBALS['PROJECT_CODE'], 'team' => $teamCode]];
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
        $filter->setTaskCodes('PROC_BLOOD_SAMPLE');
        $filter->setAdmissionIds($admission->getId());
        $tasks = $patient->getTaskList(2, 0, $filter);
        foreach ($tasks as $task) {
            $bpForm = null;
            foreach ($task->getForms() as $form) {
                if ($form->isClosed() || $form->getFormCode() != 'BLOOD_PROCESSING') {
                    continue;
                }
                $bpForm = $form;
                break;
            }
        }
        if (!$bpForm) {
            throw new ServiceException(ErrorCodes::NOT_FOUND, "No BLOOD PROCESSING task found for patient $patientRef");
        }

        return [$patient, $bpForm];
    }

    /**
     * Find the "BLOOD PROCESSING" TASK where the blood sample ID is the one specified
     * Returns NULL if the FORM is up to date (the aliquots have already been registered)
     *
     * @throws ServiceException If the FORM with the specified blood sample ID cannot be found or if there is any error
     * @param string $bloodSampleId
     * @return [APICase, APIForm]
     */
    static public function findFormFromBloodSampleId($bloodSampleId) {
        $api = LinkcareSoapAPI::getInstance();

        // Step 1: Find patients of the program that have the specified blood sample ID
        $filter = ['program' => $GLOBALS['PROJECT_CODE'], 'datacode' => ['name' => 'SAMPLE_ID', 'value' => $bloodSampleId]];
        $patients = $api->case_search(json_encode($filter));

        if (empty($patients)) {
            throw new ServiceException(ErrorCodes::NOT_FOUND, "No patient found with blood sample ID $bloodSampleId in the care plan " .
                    $GLOBALS['PROJECT_CODE']);
        } else if (count($patients) > 1) {
            $ids = array_map(function ($p) {
                /** @var APICase $p */
                return $p->getId();
            }, $patients);
            throw new ServiceException(ErrorCodes::UNEXPECTED_ERROR, "More than one CQS patient found with blood sample ID $bloodSampleId: Patient IDs: " .
                    implode(',', $ids));
        }

        /** @var APICase $patient */
        $patient = $patients[0];
        // Step 2: Find the ADMISSION of the patient
        $admission = self::findAdmission($patient->getId());
        if (!$admission) {
            throw new ServiceException(ErrorCodes::NOT_FOUND, "No CQS patient found with blood sample ID $bloodSampleId");
        }

        // Step 3: Find the BLOOD PROCESSING task of the admission
        $filter = new TaskFilter();
        $filter->setTaskCodes('PROC_BLOOD_SAMPLE');
        $filter->setAdmissionIds($admission->getId());
        $tasks = $patient->getTaskList(2, 0, $filter);
        foreach ($tasks as $task) {
            $bpForm = null;
            foreach ($task->getForms() as $form) {
                if ($form->getFormCode() != 'BLOOD_PROCESSING') {
                    continue;
                }
                $bloodSampleQuestion = $form->findQuestion('SAMPLE_ID');
                if (!$bloodSampleQuestion || $bloodSampleQuestion->getValue() != $bloodSampleId) {
                    continue;
                }
                $bpForm = $form;
                break;
            }
        }
        if (!$bpForm) {
            throw new ServiceException(ErrorCodes::NOT_FOUND, "No BLOOD PROCESSING task found with blood sample ID $bloodSampleId");
        }

        return [$patient, $bpForm];
    }

    /**
     *
     * @param APIForm $bpForm
     * @param array $aliquotsData
     */
    static public function updateBloodProcessingData($bpForm, $aliquotsData) {
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

        $updatedQuestions[] = self::updateOptionQuestionValue($bpForm, 'FORM_COMPLETE', 1, null, true, APIQuestionTypes::VERTICAL_RADIO);

        $api->form_set_all_answers($bpForm->getId(), $updatedQuestions, true);
        $bpForm->refresh();
        if ($bpForm->getStatus() != APIForm::STATUS_CLOSED) {
            throw new ServiceException(ErrorCodes::UNEXPECTED_ERROR, "Form updated successfully but its status is not CLOSED");
        }
    }

    /**
     * Load the array of blood samples from the Form that contains the last known status of the blood samples
     *
     * @param string|APIForm $formReference
     * @return [APIForm, APIQuestion[][]]
     */
    static private function loadStatusForm($formReference) {
        $api = LinkcareSoapAPI::getInstance();

        if ($formReference instanceof APIForm) {
            $samplesStatusForm = $formReference;
        } else {
            $samplesStatusForm = $api->form_get_summary($formReference);
        }

        // Fetch all the rows of the array

        $requiredItems = [AliquotStatusItems::ID, AliquotStatusItems::LOCATION, AliquotStatusItems::CREATION_DATE, AliquotStatusItems::STATUS,
                AliquotStatusItems::DAMAGE, AliquotStatusItems::CHANGE_DATE, AliquotStatusItems::SHIPMENT_REF];
        $array = $samplesStatusForm->getArrayQuestions(AliquotStatusItems::ARRAY);

        foreach ($array as $ix => $row) {
            $itemCodes = array_keys($row);
            foreach ($requiredItems as $key) {
                if (!in_array($key, $itemCodes)) {
                    throw new ServiceException(ErrorCodes::DATA_MISSING, "Error loading the column '$key' of the blood sample $ix from the status form $formReference");
                }
            }
        }

        return [$samplesStatusForm, $array];
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
     * @param bool $create If true, the question will be created if it does not exist
     * @return APIQuestion
     */
    static private function updateArrayTextQuestionValue($form, $arrayRef, $row, $itemCode, $value, $create = true) {
        if ($q = $form->findArrayQuestion($arrayRef, $row, $itemCode, $create)) {
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
     * @param bool $create If true, the question will be created if it does not exist
     * @return APIQuestion
     */
    static private function updateArrayOptionQuestionValue($form, $arrayRef, $row, $itemCode, $optionId, $optionValues = null, $create = true) {
        $ids = is_array($optionId) ? implode('|', $optionId) : $optionId;
        $values = is_array($optionValues) ? implode('|', $optionValues) : $optionValues;

        if ($q = $form->findArrayQuestion($arrayRef, $row, $itemCode, $create)) {
            $q->setOptionAnswer($ids, $values);
        } else {
            throw new ServiceException(ErrorCodes::DATA_MISSING, "Error updating form " . $form->getFormCode() . ". Item '$itemCode' not found");
        }
        return $q;
    }

    static private function encodeTaskInitialValues($arrValues) {
        $initialValues = [];
        foreach ($arrValues as $code => $value) {
            $param = new stdClass();
            $param->code = $code;
            $param->value = $value;
            $initialValues[] = $param;
        }
        return $initialValues;
    }
}