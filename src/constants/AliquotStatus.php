<?php

abstract class AliquotStatus extends BasicEnum {
    const ALL = -1;
    const AVAILABLE = 1;
    const IN_TRANSIT = 2;
    const REJECTED = 3;
    const USED = 4;
}