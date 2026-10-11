<?php

namespace App\Enums;

enum ReviewResponseStatus: string
{
    case Published = 'PUBLISHED';
    case Hidden = 'HIDDEN';
}
