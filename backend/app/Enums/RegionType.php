<?php

namespace App\Enums;

enum RegionType: string
{
    case State = 'STATE';
    case UnionTerritory = 'UNION_TERRITORY';
    case Province = 'PROVINCE';
    case Region = 'REGION';
}
