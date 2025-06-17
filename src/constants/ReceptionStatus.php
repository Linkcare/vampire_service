<?php

abstract class ReceptionStatus extends BasicEnum {
    const ALL_GOOD = 1;
    const PARTIALLY_BAD = 2;
    const ALL_BAD = 3;
}