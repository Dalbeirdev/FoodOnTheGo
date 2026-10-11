<?php

namespace App\Enums;

/**
 * What the lightweight automated screening noticed. These are plain rules (a link, something shaped like a phone
 * number or an e-mail address, a spam phrase, the same text again) — not AI moderation. A flag holds the review for a
 * person; it never rejects one.
 */
enum ReviewFlag: string
{
    case UnsafeLink = 'UNSAFE_LINK';
    case PersonalInformation = 'PERSONAL_INFORMATION';
    case SpamPattern = 'SPAM_PATTERN';
    case RepeatedContent = 'REPEATED_CONTENT';
}
