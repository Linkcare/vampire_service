<?php

abstract class AliquotConditions extends BasicEnum {
    const NORMAL = "NO_DAMAGE";
    const WHOLE_DAMAGE = "WHOLE_DAMAGE";
    const BROKEN = "BROKEN";
    const MISSING = "MISSING";
    const DEFROST = "DEFROST";
    const EXOSOMES_FAILURE = "EXOSOMES_FAILURE";
    const OTHER = "OTHER";

    static public function readableName($condition) {
        switch ($condition) {
            case self::NORMAL :
                return "Normal";
            case self::WHOLE_DAMAGE :
                return "Broken";
            case self::BROKEN :
                return "Broken";
            case self::MISSING :
                return "Missing";
            case self::DEFROST :
                return "Defrosted";
            case self::EXOSOMES_FAILURE :
                return "Exosomes Failure";
            case self::OTHER :
                return "Other";
        }
    }
}