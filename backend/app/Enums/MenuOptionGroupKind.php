<?php

namespace App\Enums;

/**
 * Where a client shows a group; the selection rules do not depend on it. VARIANT = one way of having the item
 * (Size, Portion, Crust), MODIFIER = additions and preferences (Add-ons, Spice level, Remove ingredients).
 */
enum MenuOptionGroupKind: string
{
    case Variant = 'VARIANT';
    case Modifier = 'MODIFIER';
}
