<?php

namespace App\Enums;

enum MenuOptionGroupStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
    case Archived = 'ARCHIVED';
}
