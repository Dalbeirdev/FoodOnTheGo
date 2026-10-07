<?php

/*
|--------------------------------------------------------------------------
| Legal documents (Module 29)
|--------------------------------------------------------------------------
|
| Which version of each document is current. Checkout records the version a customer accepted — not just "accepted".
| The TEXT of these documents is a draft: whether a document has final business / legal approval is a property of the
| market (market_configurations.legal.documents) and stays DRAFT_PENDING_APPROVAL until the owner supplies approved
| text. That is a production blocker, not something this file can settle.
|
*/

return [

    'documents' => [
        'terms' => ['version' => env('LEGAL_TERMS_VERSION', 'draft-2026-09'), 'effective_date' => env('LEGAL_TERMS_EFFECTIVE_DATE'), 'slug' => 'terms'],
        'privacy' => ['version' => env('LEGAL_PRIVACY_VERSION', 'draft-2026-09'), 'effective_date' => env('LEGAL_PRIVACY_EFFECTIVE_DATE'), 'slug' => 'privacy'],
        'refund' => ['version' => env('LEGAL_REFUND_VERSION', 'draft-2026-09'), 'effective_date' => env('LEGAL_REFUND_EFFECTIVE_DATE'), 'slug' => 'refund-policy'],
    ],

    // What a customer must accept before a checkout becomes READY.
    'checkout_required' => ['terms', 'privacy', 'refund'],

    // The state reported for a document whose market does not say otherwise.
    'default_status' => 'DRAFT_PENDING_APPROVAL',

    'approved_status' => 'APPROVED',

];
