<?php

namespace App\Enums;

/**
 * Structured reason for rejecting a restaurant application. The free-text explanation shown to the restaurant
 * and the internal note are stored separately.
 */
enum RejectionCategory: string
{
    case IncompleteDocuments = 'INCOMPLETE_DOCUMENTS';
    case InvalidBusiness = 'INVALID_BUSINESS';
    case Duplicate = 'DUPLICATE';
    case Policy = 'POLICY';
    case Other = 'OTHER';
}
