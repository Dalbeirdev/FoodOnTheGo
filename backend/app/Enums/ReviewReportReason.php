<?php

namespace App\Enums;

/**
 * Why somebody reports a published review. A report is a request to look, never proof of a violation.
 */
enum ReviewReportReason: string
{
    case Spam = 'SPAM';
    case Harassment = 'HARASSMENT';
    case PersonalInformation = 'PERSONAL_INFORMATION';
    case OffensiveContent = 'OFFENSIVE_CONTENT';
    case FakeReviewConcern = 'FAKE_REVIEW_CONCERN';
    case Other = 'OTHER';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
