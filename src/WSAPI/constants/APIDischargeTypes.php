<?php

abstract class APIDischargeTypes extends ConstantsBase {
    // 1. NORMAL TERMINATION (end of study)
    const END = 'END';

    // 2. EARLY TERMINATION (drop-out)

    // 2.1 By participant’s decision:
    // 2.1.a) Withdrawal of consent.
    const CONSENT_WITHDRAWAL = 'CONSENT_WITHDRAWAL';
    // 2.1.b) Voluntary withdrawal
    const VOLUNTARY = 'VOLUNTARY';

    // 2.2 investigator’s / site’s decision:
    // 2.2.a) Lack of adherence to the protocol
    const LACK_ADHERENCE = 'LACK_ADHERENCE';
    // 2.2.b) Risk to the patient (medical judgment)
    const RISK_PATIENT = 'RISK_PATIENT';

    // 2.3 Administrative withdrawal (e.g., site closure)
    const ADMINISTRATIVE = 'ADMINISTRATIVE';

    // 2.4 For treatment-related reasons:
    // 2.4.a) Adverse effects
    const ADVERSE_EFFECTS = 'ADVERSE_EFFECTS';
    // 2.4.b) Lack of efficacy
    const LACK_EFFICACY = 'LACK_EFFICACY';
    // 2.4.c) Drug suspended by regulatory decision or sponsor
    const DRUG_SUSPENDED = 'DRUG_SUSPENDED';

    // 2.5 EXITUS
    // 2.5.a) Treatment-related
    const TREATMENT_RELATED_DEATH = 'TREATMENT_RELATED_DEATH';
    // 2.5.b) Not treatment-related
    const NON_TREATMENT_RELATED_DEATH = 'NON_TREATMENT_RELATED_DEATH';
    // 2.5.c) Unknown cause
    const EXITUS = 'EXITUS';

    // 2.6) Lost to follow-up
    const LOST_FOLLOW_UP = 'LOST_FOLLOW_UP';

    // 2.7 Protocol violation / Eligibility violation
    const PROTOCOL_VIOLATION = 'PROTOCOL_VIOLATION';

    // 2.8 Transplant or major surgery incompatible with the study
    const STUDY_INCOMPATIBILITY = 'STUDY_INCOMPATIBILITY';

    // 2.9) Admission transfer to another hospital
    const TRANSFERRED = 'TRANSFERRED';
}