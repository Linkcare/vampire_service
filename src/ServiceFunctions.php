<?php

class ServiceFunctions {

    /**
     *
     * @param string $bloodProcessingFormId Reference of the FORM with the aliquots to be added
     * @param string $labTeamId Reference of the TEAM that has processed the blood samples
     * @param string $procDate Blood processing date
     * @param string $procTime Blood processing time
     * @return ServiceResponse
     */
    static public function addAliquots($bloodProcessingFormId, $labTeamId, $procDate, $procTime) {
        $api = LinkcareSoapAPI::getInstance();

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
            $existingAliquotsArray = $destStatusForm->getArrayQuestions(AliquotStatusItems::ARRAY);

            $questionsArray = [];
            $questionsArray[] = self::updateTextQuestionValue($destStatusForm, AliquotStatusItems::SAMPLE_TYPE, $sampleType);

            foreach ($existingAliquotsArray as $row) {
                foreach ($row as $question) {
                    $questionsArray[] = $question;
                }
            }

            // Add the new aliquots to the status form
            $ix = count($existingAliquotsArray) + 1;
            foreach ($aliquotsArray as $row) {
                /** @var APIQuestion[] $row */
                $aliquotId = $row[$sampleType . "_" . AliquotTrackingItems::ALIQUOT_ID]->getValue();
                $aliquotIds[] = $aliquotId;
                $questionsArray[] = self::updateArrayTextQuestionValue($destStatusForm, $destArrayHeader->getId(), $ix, AliquotStatusItems::ID,
                        $aliquotId);
                $questionsArray[] = self::updateArrayTextQuestionValue($destStatusForm, $destArrayHeader->getId(), $ix,
                        AliquotStatusItems::CREATION_DATE, DateHelper::datePart($procDateUTC));
                $questionsArray[] = self::updateArrayTextQuestionValue($destStatusForm, $destArrayHeader->getId(), $ix,
                        AliquotStatusItems::CREATION_TIME, DateHelper::timePart($procDateUTC));
                $questionsArray[] = self::updateArrayOptionQuestionValue($destStatusForm, $destArrayHeader->getId(), $ix, AliquotStatusItems::LOCATION,
                        null, $labTeamId);
                $ix++;
            }

            // Remove null entries
            $questionsArray = array_filter($questionsArray);

            if (!empty($questionsArray)) {
                $api->form_set_all_answers($destStatusForm->getId(), $questionsArray, false);
            }
        }
        // Concatenate the added aliquot IDs into a string
        $aliquotsIncluded = implode(',', $aliquotIds);
        return new ServiceResponse($aliquotsIncluded, null);
    }

    /**
     * Prepares a TASK for a blood sample shipment.
     * The preparation consists in:
     * - Retrieving the most recent information about the blood samples
     * - Selecting the blood samples that correspond to the sender Team
     * - Storing a table with the selected samples in the in the Shipment form
     *
     * @param string $preparationFormId Reference of the Form that contains the basic shipping information
     * @param string $sampleType Type of blood sample to be shipped
     * @param string $senderId Reference of the Team that will send the blood samples. Only the blood samples that belong to this Team and are
     * @param string $shipmentFormId Reference of the FORM where the aliquots available to send will be stored
     *        available can be included in the shipment
     * @return ServiceResponse
     */
    static public function prepareShipment($preparationFormId, $shipmentFormId, $sampleType, $senderId) {
        $api = LinkcareSoapAPI::getInstance();

        // Find the reference of the Form that contains the last known status of the blood samples
        $formula = 'ADMISSION.FORM{' . $sampleType . '_STATUS_FORM}:LAST.REF';
        $sampleStatusFormId = $api->formula_exec($preparationFormId, null, $formula);
        if (!$sampleStatusFormId) {
            throw new ServiceException(ErrorCodes::DATA_MISSING, "Last status form of the $sampleType blood samples not found");
        }

        /** @var APIForm $samplesStatusForm */
        /** @var APIQuestion[][] $availableAliquotsArray */
        list($samplesStatusForm, $availableAliquotsArray) = self::loadAliquotStatus($sampleStatusFormId, AliquotStatus::AVAILABLE, $senderId,
                $sampleType);
        $shipmentForm = $api->form_get_summary($shipmentFormId);

        // Fill the array of available aliquots in the shipment form
        $arrQuestions = [];

        // Procedure information stored as a table (1 row)
        $ix = 1;
        if (!empty($availableAliquotsArray) && ($arrayHeader = $shipmentForm->findQuestion(AliquotTrackingItems::ALIQUOTS_ARRAY)) &&
                $arrayHeader->getType() == APIQuestion::TYPE_ARRAY) {
            foreach ($availableAliquotsArray as $row) {
                $aliquotIds[] = $row[AliquotStatusItems::ID]->getValue();
                $arrQuestions[] = self::updateArrayTextQuestionValue($shipmentForm, $arrayHeader->getId(), $ix, AliquotTrackingItems::ALIQUOT_ID,
                        $row[AliquotStatusItems::ID]->getValue());

                $ix++;
            }
        }

        // Remove null entries
        $arrQuestions = array_filter($arrQuestions);

        if (!empty($arrQuestions)) {
            $api->form_set_all_answers($shipmentForm->getId(), $arrQuestions, false);
        }

        // Concatenate the aliquot IDs into a string
        $aliquotsIncluded = implode(',', $aliquotIds);
        return new ServiceResponse($aliquotsIncluded, null);
    }

    /**
     * Prepares a TASK for the recpetion of a blood sample shipment.
     * The preparation consists in:
     * - Retrieving the most recent information about the blood samples
     * - Selecting the blood samples that correspond to the sender Team
     * - Storing a table with the selected samples in the in the Shipment form
     *
     * @param string $shipmentFormId Reference of the Form that contains the list of aliquots that have been shipped
     * @param string $receptionFormId Reference of the FORM where the received aliquots will be stored
     * @param string $sampleType Type of blood sample received
     * @return ServiceResponse
     */
    static public function prepareReception($shipmentFormId, $receptionFormId, $sampleType) {
        $api = LinkcareSoapAPI::getInstance();

        // Load the list of aliquots included in the shipment
        $shippedAliquotsArray = self::loadAffectedAliquots(AliquotActions::SHIPMENT, $shipmentFormId, $sampleType);
        if (empty($shippedAliquotsArray)) {
            return new ServiceResponse("No aliquot informed in the shipment FORM $shipmentFormId", null);
        }

        $receptionForm = $api->form_get_summary($receptionFormId);

        // Fill the array of available aliquots in the shipment form
        $arrQuestions = [];

        // Procedure information stored as a table (1 row)
        $ix = 1;
        if (!empty($shippedAliquotsArray) && ($arrayHeader = $receptionForm->findQuestion(AliquotTrackingItems::ALIQUOTS_ARRAY)) &&
                $arrayHeader->getType() == APIQuestion::TYPE_ARRAY) {
            foreach ($shippedAliquotsArray as $row) {
                $aliquotIds[] = $row[AliquotTrackingItems::ALIQUOT_ID]->getValue();
                $arrQuestions[] = self::updateArrayTextQuestionValue($receptionForm, $arrayHeader->getId(), $ix, AliquotTrackingItems::ALIQUOT_ID,
                        $row[AliquotTrackingItems::ALIQUOT_ID]->getValue());

                $ix++;
            }
        }

        // Remove null entries
        $arrQuestions = array_filter($arrQuestions);

        if (!empty($arrQuestions)) {
            $api->form_set_all_answers($receptionForm->getId(), $arrQuestions, false);
        }

        // Concatenate the aliquot IDs into a string
        $aliquotsIncluded = implode(',', $aliquotIds);
        return new ServiceResponse($aliquotsIncluded, null);
    }

    /**
     * Prepares a TASK to select the aliquots that have been used to extract exosomes.
     * The preparation consists in:
     * - Retrieving the most recent information about the blood samples
     * - Selecting the blood samples that belong to the laboratory
     * - Storing a table with the selected samples in the in the Selection form
     *
     * @param string $preparationFormId Reference of the Form that has invoked the service to prepare the selection of processed samples
     * @param string $selectionFormId Reference of the FORM where the aliquots available to send will be stored
     * @param string $sampleType Type of blood sample to be shipped
     * @param string $labId Reference of the Team (laboratory) that has processed the blood samples to extract the aliquots
     * @return ServiceResponse
     */
    static public function prepareForExosomes($preparationFormId, $selectionFormId, $sampleType, $labId) {
        $api = LinkcareSoapAPI::getInstance();

        // Find the reference of the Form that contains the last known status of the blood samples
        $formula = 'ADMISSION.FORM{' . $sampleType . '_STATUS_FORM}:LAST.REF';
        $sampleStatusFormId = $api->formula_exec($preparationFormId, null, $formula);
        if (!$sampleStatusFormId) {
            throw new ServiceException(ErrorCodes::DATA_MISSING, "Last status form of the $sampleType blood samples not found");
        }

        /** @var APIForm $samplesStatusForm */
        /** @var APIQuestion[][] $availableAliquotsArray */
        list($samplesStatusForm, $availableAliquotsArray) = self::loadAliquotStatus($sampleStatusFormId, AliquotStatus::AVAILABLE, $labId, $sampleType);
        $selectionForm = $api->form_get_summary($selectionFormId);

        // Fill the array of available aliquots in the shipment form
        $arrQuestions = [];

        // Procedure information stored as a table (1 row)
        $ix = 1;

        self::updateTextQuestionValue($selectionForm, 'NUM_AVAILABLE_ALIQUOTS', count($availableAliquotsArray));

        if (!empty($availableAliquotsArray)) {
            if (($arrayHeader = $selectionForm->findQuestion(AliquotTrackingItems::ALIQUOTS_ARRAY)) &&
                    $arrayHeader->getType() == APIQuestion::TYPE_ARRAY) {
                foreach ($availableAliquotsArray as $row) {
                    $aliquotIds[] = $row[AliquotStatusItems::ID]->getValue();
                    $arrQuestions[] = self::updateArrayTextQuestionValue($selectionForm, $arrayHeader->getId(), $ix, AliquotTrackingItems::ALIQUOT_ID,
                            $row[AliquotStatusItems::ID]->getValue());

                    $ix++;
                }
            } else {
                throw new ServiceException(ErrorCodes::DATA_MISSING, "The array of aliquots (item " . AliquotTrackingItems::ALIQUOTS_ARRAY .
                        ") does not exist in the Exosomes selection form");
            }
        }

        // Remove null entries
        $arrQuestions = array_filter($arrQuestions);

        if (!empty($arrQuestions)) {
            $api->form_set_all_answers($selectionForm->getId(), $arrQuestions, false);
        }

        // Concatenate the aliquot IDs into a string
        $aliquotsIncluded = implode(',', $aliquotIds);
        return new ServiceResponse($aliquotsIncluded, null);
    }

    /**
     * Loads the list of aliquots that have been marked as "processed" to extract exosomes and:
     * <ul>
     * <li>Updates the status of the aliquots used for exosomes indicating that the new status is "used" and also indicating the ID of the exosomes
     * aliquot created</li>
     * <li>Creates a new aliquot of type "EXOSOMES" for each aliquot processed and stores the information in Exosomes status FORM</li>
     * </ul>
     *
     * @param string $bloodProcessingFormId Reference of the FORM with the aliquots to be added
     * @param string $labTeamId Reference of the TEAM that has processed the blood samples
     * @param string $procDate Blood processing date
     * @param string $procTime Blood processing time
     * @return ServiceResponse
     */
    static public function addExosomeAliquots($bloodProcessingFormId, $labTeamId, $procDate, $procTime) {
        $api = LinkcareSoapAPI::getInstance();
        $exosomesStatusFormCode = 'EXOSOMES_STATUS_FORM';
        $srcAliquotStatusFormCode = 'PLASMA_STATUS_FORM';

        // The time is stored in the local timezone. We need to convert it to UTC
        $localTime = DateHelper::compose($procDate, $procTime);
        $procDateUTC = DateHelper::localToUTC($localTime, $api->getSession()->getTimezone());

        // Load the FORM that contains the processed aliquots that have been used for exosomes
        $processingForm = $api->form_get_summary($bloodProcessingFormId);
        // Check if there are aliquots of this sample type
        $processedAliquotsArray = $processingForm->getArrayQuestions(AliquotTrackingItems::ALIQUOTS_ARRAY);
        if (count($processedAliquotsArray) == 0) {
            throw new ServiceException(ErrorCodes::DATA_MISSING, "The array of aliquots in the selection form does not exist");
        }

        $containerTaskActivities = $api->task_activity_list($processingForm->getParentId());
        foreach ($containerTaskActivities as $taskActivity) {
            if (!$taskActivity instanceof APIForm) {
                continue;
            }
            $forms[$taskActivity->getFormCode()] = $taskActivity;
        }

        // Verify that the STATUS FORM to store the new exosome aliquots exists
        if (!array_key_exists($exosomesStatusFormCode, $forms)) {
            throw new ServiceException(ErrorCodes::FORM_MISSING, "The FORM $exosomesStatusFormCode does not exist in the blood processing task. It is not possible to store the processed aliquots");
        }
        $destStatusForm = $forms[$exosomesStatusFormCode];

        $destArrayHeader = $destStatusForm->findQuestion(AliquotStatusItems::ARRAY);
        if (!$destArrayHeader) {
            throw new ServiceException(ErrorCodes::DATA_MISSING, "The array of aliquots does not exist in the exosomes status form");
        }
        $existingExosomesArray = $destStatusForm->getArrayQuestions(AliquotStatusItems::ARRAY);

        /*
         * Verify that the STATUS FORM of the aliquots processed exists. The aliquots in this FORM will be updated to indicate that they have been
         * used for exosomes
         */
        if (!array_key_exists($srcAliquotStatusFormCode, $forms)) {
            throw new ServiceException(ErrorCodes::FORM_MISSING, "The FORM $srcAliquotStatusFormCode does not exist in the blood processing task. It is not possible to update the status of the aliquots used for exosomes");
        }
        $srcStatusForm = $forms[$srcAliquotStatusFormCode];

        $srcArrayHeader = $srcStatusForm->findQuestion(AliquotStatusItems::ARRAY);
        if (!$srcArrayHeader) {
            throw new ServiceException(ErrorCodes::DATA_MISSING, "The array of aliquots does not exist in the plasma status form");
        }
        $srcAliquotStatusArray = $srcStatusForm->getArrayQuestions(AliquotStatusItems::ARRAY);

        $strAliquotsProcessed = [];

        // Load the existing aliquots of the status FORM. Additional rows will be appended for the new aliquots
        $existingExosomesArray = $destStatusForm->getArrayQuestions(AliquotStatusItems::ARRAY);

        $exosomesQuestionsArray = [];
        $exosomesQuestionsArray[] = self::updateTextQuestionValue($destStatusForm, AliquotStatusItems::SAMPLE_TYPE, 'EXOSOMES');

        foreach ($existingExosomesArray as $row) {
            foreach ($row as $question) {
                $exosomesQuestionsArray[] = $question;
            }
        }

        // Add the new aliquots to the status form
        $ix = count($existingExosomesArray) + 1;

        $srcAliquotIds = []; // IDs of the aliquots processed
        $failedAliquotIds = []; // IDs of the aliquots that have been processed but the extraction of exosomes has failed
        $exoPrefix = '_exo'; // Suffix to be added to the IDs of the aliquots used for exosomes

        foreach ($processedAliquotsArray as $row) {
            if (!array_key_exists(AliquotTrackingItems::ALIQUOT_USED_FOR_EXOSOMES, $row)) {
                throw new ServiceException(ErrorCodes::DATA_MISSING, "The item (" . AliquotTrackingItems::ALIQUOT_USED_FOR_EXOSOMES .
                        ") indicating whether an aliquot has been used for exosomes is missing");
            }
            if ($row[AliquotTrackingItems::ALIQUOT_USED_FOR_EXOSOMES]->getValue() != 1) {
                // Skip the aliquots that have not been used for exosomes
                continue;
            }

            $aliquotId = $row[AliquotTrackingItems::ALIQUOT_ID]->getValue();
            $srcAliquotIds[] = $aliquotId;

            if (trim($row[AliquotTrackingItems::EXOSOMES_SUCCESS]->getValue()) !== "0") {
                //
                /** @var APIQuestion[] $row */
                // Aliquot IDs are suffixed with "_exo" to tack the original aliquot from which the exosome aliquot was extracted
                $aliquotId .= $exoPrefix;
                $exosomesQuestionsArray[] = self::updateArrayTextQuestionValue($destStatusForm, $destArrayHeader->getId(), $ix, AliquotStatusItems::ID,
                        $aliquotId);
                $exosomesQuestionsArray[] = self::updateArrayTextQuestionValue($destStatusForm, $destArrayHeader->getId(), $ix,
                        AliquotStatusItems::CREATION_DATE, DateHelper::datePart($procDateUTC));
                $exosomesQuestionsArray[] = self::updateArrayTextQuestionValue($destStatusForm, $destArrayHeader->getId(), $ix,
                        AliquotStatusItems::CREATION_TIME, DateHelper::timePart($procDateUTC));
                $exosomesQuestionsArray[] = self::updateArrayOptionQuestionValue($destStatusForm, $destArrayHeader->getId(), $ix,
                        AliquotStatusItems::LOCATION, null, $labTeamId);
                $ix++;
            } else {
                // Something went wrong during the extraction of the exosomes, so we will not create the exosomes aliquot
                $failedAliquotIds[] = $aliquotId;
            }
        }

        // Remove null entries
        $exosomesQuestionsArray = array_filter($exosomesQuestionsArray);

        /*
         * Update the status of aliquots processed to indicate:
         * - The new status is now "Used"
         * - The ID of the exosome aliquot have been created from each processed aliquot
         */
        $srcQuestionsArray = [];
        $found = 0;
        foreach ($srcAliquotStatusArray as $ixRow => $row) {
            if (!in_array($row[AliquotStatusItems::ID]->getValue(), $srcAliquotIds)) {
                // Skip the aliquots that have not been used for exosomes
                continue;
            }
            $found++;
            $id = $row[AliquotStatusItems::ID]->getValue();
            $statusQuestion = self::updateArrayOptionQuestionValue($srcStatusForm, $srcArrayHeader->getId(), $ixRow, AliquotStatusItems::STATUS,
                    AliquotStatus::USED, null, false);
            $srcQuestionsArray[] = $statusQuestion;
            if (in_array($id, $failedAliquotIds)) {
                // The extraction of exosomes has failed => Indicate the damage status
                $damageQuestion = self::updateArrayOptionQuestionValue($srcStatusForm, $srcArrayHeader->getId(), $ixRow, AliquotStatusItems::DAMAGE,
                        null, AliquotDamage::EXOSOMES_FAILURE, false);
                $srcQuestionsArray[] = $damageQuestion;
            } else {
                // Extraction of exosomes has been successful => store the ID of the exosomes aliquot
                $newIdQuestion = $row[AliquotStatusItems::EXOSOSOMES_ID];
                $newIdQuestion->setAnswer($id . $exoPrefix);
                $srcQuestionsArray[] = $newIdQuestion;
            }
        }

        if (count($srcAliquotIds) != $found) {
            // ERROR: Not all the aliquots marked as processed were found in the status form
            throw new ServiceException(ErrorCodes::UNEXPECTED_ERROR, "One or more aliquots marked as used for exosomes were not found in the status form and can't be updated");
        }

        if (!empty($exosomesQuestionsArray)) {
            $api->form_set_all_answers($destStatusForm->getId(), $exosomesQuestionsArray, false);
        }

        if (!empty($srcQuestionsArray)) {
            foreach ($srcQuestionsArray as $question) {
                $question->save();
            }
        }

        // Concatenate the processed aliquot IDs into a string
        $strAliquotsProcessed = implode(',', $srcAliquotIds);
        return new ServiceResponse($strAliquotsProcessed, null);
    }

    /**
     * Updates the status of the blood sample aliquots after an action (shipment, reception...)
     * This functions assumes that already exists a FORM that contains the last known status of the blood samples and modifies the status according to
     * the action executed
     *
     * @param int $action Type of action performed on the aliquots. Use one of the AliquotAction constants
     * @param string $modifAliquotsFormId
     * @param string $sampleType
     * @param string $statusFormId Reference of the FORM containing the current status of the blood samples
     * @return ServiceResponse
     */
    static public function updateSamplesStatus($action, $modifAliquotsFormId, $sampleType, $statusFormId) {
        $api = LinkcareSoapAPI::getInstance();

        // Load the list of aliquots modified
        $modifiedAliquotsArray = self::loadAffectedAliquots($action, $modifAliquotsFormId, $sampleType);
        if (empty($modifiedAliquotsArray)) {
            return new ServiceResponse('No aliquot modified', null);
        }

        // Load the list of all existing aliquots to update the status of the modified ones
        /** @var APIForm $samplesStatusForm */
        /** @var APIQuestion[][] $aliquotStatusArray */
        list($samplesStatusForm, $aliquotStatusArray) = self::loadAliquotStatus($statusFormId, AliquotStatus::ALL, null, $sampleType);

        $updatableFieldMap = [AliquotTrackingItems::FINAL_ALIQUOT_LOCATION => AliquotStatusItems::LOCATION,
                AliquotTrackingItems::FINAL_ALIQUOT_STATUS => AliquotStatusItems::STATUS,
                AliquotTrackingItems::FINAL_ALIQUOT_DAMAGE => AliquotStatusItems::DAMAGE,
                AliquotTrackingItems::FINAL_ALIQUOT_SHIPMENT_REF => AliquotStatusItems::SHIPMENT_REF];

        $nowUTC = DateHelper::currentDate();
        foreach ($modifiedAliquotsArray as $aliquotId => $modififedAliquot) {
            if (!array_key_exists($aliquotId, $aliquotStatusArray)) {
                throw new ServiceException(ErrorCodes::DATA_MISSING, "$sampleType aliquot $aliquotId not found in the last known status (form $statusFormId)");
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
                if (!array_key_exists($key, $updatableFieldMap)) {
                    // Ignore fields that should not be modified
                    continue;
                }

                // Map the ITEM name from the modified FORM to the corresponding ITEM name in the status FORM
                $mappedFieldName = $updatableFieldMap[$key];
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
                        $currentStatusItem->setOptionAnswer($modifiedQuestion->getValue());
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
     * @param string $referenceFormId
     * @param string $senderId Reference of the owner of the aliquots. If null, all aliquots will be included
     * @param string $sampleType
     * @return [APIForm, APIQuestion[][]]
     */
    static private function loadAliquotStatus($referenceFormId, $aliquotStatusFilter, $ownerFilter, $sampleType) {
        $api = LinkcareSoapAPI::getInstance();

        // Load the array of aliquots of the status FORM
        list($samplesStatusForm, $array) = self::loadStatusForm($referenceFormId);

        $aliquotsArray = [];

        // Filter the aliquots according to the requested filters (status, owner)
        foreach ($array as $row) {
            /** @var APIQuestion[] $row */
            $aliquotId = $row[AliquotStatusItems::ID]->getValue();

            $curStatus = $row[AliquotStatusItems::STATUS]->getValue();
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
     * Retrieves the list of aliquots of a preparation FORM (for example a shipment or reception FORM)
     * The return value is an associative array indexed by the aliquot ID
     *
     * @param int $action Type of action performed on the aliquots. Use one of the AliquotAction constants
     * @param string $modifiedFormId Reference of the FORM with the modified aliquots. No all the aliquots may have necessarily been modified.
     * @param string $sampleType Type of blood sample
     * @return string[]
     */
    static private function loadAffectedAliquots($action, $modifiedFormId, $sampleType) {
        $api = LinkcareSoapAPI::getInstance();
        $samplesStatusForm = $api->form_get_summary($modifiedFormId);

        // Fetch all the rows of the array. The list of fields loaded will depend on the type of action
        if ($action == AliquotActions::SHIPMENT) {
            $requiredItems = [AliquotTrackingItems::ALIQUOT_ID, AliquotTrackingItems::FINAL_ALIQUOT_LOCATION,
                    AliquotTrackingItems::FINAL_ALIQUOT_STATUS, AliquotTrackingItems::INCLUDE_IN_SHIPMENT,
                    AliquotTrackingItems::FINAL_ALIQUOT_SHIPMENT_REF];
        } elseif ($action == AliquotActions::RECEPTION) {
            $requiredItems = [AliquotTrackingItems::ALIQUOT_ID, AliquotTrackingItems::FINAL_ALIQUOT_STATUS,
                    AliquotTrackingItems::FINAL_ALIQUOT_DAMAGE];
        } elseif ($action == AliquotActions::REGISTER_EXOSOMES) {
            $requiredItems = [AliquotTrackingItems::ALIQUOT_ID, AliquotTrackingItems::ALIQUOT_USED_FOR_EXOSOMES];
        } else {
            throw new ServiceException(ErrorCodes::UNSUPPORTED_ACTION, "Requestes action: " . AliquotActions::getName($action));
        }
        $array = $samplesStatusForm->getArrayQuestions(AliquotTrackingItems::ALIQUOTS_ARRAY);

        // Verify that all the required items are present in the array and create an associative array indexed by the aliquot ID
        $aliquotsArray = [];
        foreach ($array as $ix => $row) {
            $itemCodes = array_keys($row);
            foreach ($requiredItems as $key) {
                if (!in_array($key, $itemCodes)) {
                    throw new ServiceException(ErrorCodes::DATA_MISSING, "Error loading the column '$key' of the blood sample $ix from the status form $modifiedFormId");
                }
            }

            $aliquotId = $row[AliquotTrackingItems::ALIQUOT_ID]->getValue();
            $aliquotsArray[$aliquotId] = $row;
        }

        // Preserve only the samples that have been modified
        $modifiedAliquots = [];

        foreach ($aliquotsArray as $key => $row) {
            /** @var APIQuestion[] $row */
            if ($action == AliquotActions::SHIPMENT) {
                // Use only the aliquots marked as included in the shipment
                $affected = $row[AliquotTrackingItems::INCLUDE_IN_SHIPMENT]->getValue();
            } elseif ($action == AliquotActions::RECEPTION) {
                // The status of all the aliquots received must be updated
                $affected = true;
            } else {
                $affected = false;
            }
            if ($affected == 1) {
                $modifiedAliquots[$key] = $row;
            }
        }

        return $modifiedAliquots;
    }

    /**
     * Load the array of blood samples from the Form that contains the last known status of the blood samples
     *
     * @param string $sampleStatusFormId
     * @return [APIForm, APIQuestion[][]]
     */
    static private function loadStatusForm($sampleStatusFormId) {
        $api = LinkcareSoapAPI::getInstance();
        $samplesStatusForm = $api->form_get_summary($sampleStatusFormId);

        // Fetch all the rows of the array

        $requiredItems = [AliquotStatusItems::ID, AliquotStatusItems::LOCATION, AliquotStatusItems::CREATION_DATE, AliquotStatusItems::STATUS,
                AliquotStatusItems::DAMAGE, AliquotStatusItems::CHANGE_DATE, AliquotStatusItems::SHIPMENT_REF];
        $array = $samplesStatusForm->getArrayQuestions(AliquotStatusItems::ARRAY);

        foreach ($array as $ix => $row) {
            $itemCodes = array_keys($row);
            foreach ($requiredItems as $key) {
                if (!in_array($key, $itemCodes)) {
                    throw new ServiceException(ErrorCodes::DATA_MISSING, "Error loading the column '$key' of the blood sample $ix from the status form $sampleStatusFormId");
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
     * @return APIQuestion
     */
    static private function updateTextQuestionValue($form, $itemCode, $value) {
        if ($q = $form->findQuestion($itemCode)) {
            $q->setAnswer($value);
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
     * @return APIQuestion
     */
    private function updateOptionQuestionValue($form, $itemCode, $optionId, $optionValues = null) {
        $ids = is_array($optionId) ? implode('|', $optionId) : $optionId;
        $values = is_array($optionValues) ? implode('|', $optionValues) : $optionValues;

        if ($q = $form->findQuestion($itemCode)) {
            $q->setOptionAnswer($ids, $values);
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
    private function updateArrayOptionQuestionValue($form, $arrayRef, $row, $itemCode, $optionId, $optionValues = null, $create = true) {
        $ids = is_array($optionId) ? implode('|', $optionId) : $optionId;
        $values = is_array($optionValues) ? implode('|', $optionValues) : $optionValues;

        if ($q = $form->findArrayQuestion($arrayRef, $row, $itemCode, $create)) {
            $q->setOptionAnswer($ids, $values);
        } else {
            throw new ServiceException(ErrorCodes::DATA_MISSING, "Error updating form " . $form->getFormCode() . ". Item '$itemCode' not found");
        }
        return $q;
    }
}