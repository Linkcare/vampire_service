<?php

abstract class AliquotStatus extends BasicEnum {
    const ALL = 'ALL';
    const AVAILABLE = 'IN_PLACE';
    const IN_TRANSIT = 'IN_TRANSIT';
    const REJECTED = 'REJECTED';
    const USED = 'USED';
    const TRANSFORMED = 'TRANSFORMED';

    static public function readableName($status) {
        switch ($status) {
            case AliquotStatus::AVAILABLE :
                return "Available";
            case AliquotStatus::IN_TRANSIT :
                return "Shipped";
            case AliquotStatus::REJECTED :
                return "Rejected";
            case AliquotStatus::USED :
                return "Used";
            case AliquotStatus::TRANSFORMED :
                return "Exosomes extracted";
            default :
                return "Unknown";
        }
    }
}