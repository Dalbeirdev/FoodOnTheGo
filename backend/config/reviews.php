<?php

/*
|--------------------------------------------------------------------------
| Reviews (Module 34)
|--------------------------------------------------------------------------
| One review per COMPLETED order. Everything here is policy that the business may change without a deployment of code:
| the windows and the limits are DEVELOPMENT DEFAULTS, PENDING BUSINESS APPROVAL (they match the approved Module 16
| design: 30 days to review, 72 hours to edit).
*/

return [
    // Integer stars. A different scale would need a migration of the rating check and of the summary columns.
    'scale' => ['min' => 1, 'max' => 5],

    // How long after the order was completed a review may be written. DEVELOPMENT DEFAULT — pending business approval.
    'submission_window_days' => (int) env('REVIEW_SUBMISSION_WINDOW_DAYS', 30),

    // How long after it was submitted the customer may change a review, and how often. DEVELOPMENT DEFAULTS — pending
    // business approval. Every change keeps the previous text and ratings (review_revisions).
    'edit_window_hours' => (int) env('REVIEW_EDIT_WINDOW_HOURS', 72),
    'max_edits' => (int) env('REVIEW_MAX_EDITS', 3),

    // Lengths in Unicode code points. Text is plain text in any language; markup is refused.
    'title_max' => 120,
    'comment_max' => 500,
    'response_max' => 600,
    'explanation_max' => 500,
    'reason_max' => 300,

    // Optional category ratings (the approved design's four). `group` says which of the three reporting groups a
    // category belongs to: food, service, pickup.
    'categories' => [
        'food_quality' => ['required' => false, 'group' => 'food'],
        'order_accuracy' => ['required' => false, 'group' => 'service'],
        'preparation_time' => ['required' => false, 'group' => 'service'],
        'pickup_experience' => ['required' => false, 'group' => 'pickup'],
    ],

    // Quick tags a customer may attach: code => sentiment. Labels are client strings (rv.tag.<code>).
    'tags' => [
        'fast_pickup' => 'positive', 'great_food' => 'positive', 'accurate_order' => 'positive', 'friendly_service' => 'positive',
        'as_expected' => 'neutral', 'packaging' => 'neutral',
        'long_wait' => 'negative', 'item_missing' => 'negative', 'wrong_item' => 'negative', 'cold_food' => 'negative',
    ],
    'max_tags' => 6,

    // "Liked / disliked" per ordered item (only items of the reviewed order are accepted).
    'item_feedback' => true,

    // Review photos are not part of the approved design and are NOT implemented.
    'images' => false,

    /*
    | Automated screening: plain rules, not AI moderation (advanced moderation = PENDING, none is configured).
    | A flag holds the review for an administrator; it never rejects one, and a low rating is never a flag.
    */
    'screening' => [
        'spam_phrases' => ['whatsapp', 'telegram', 'click here', 'promo code', 'earn money', 'free gift', 'call now', 'visit my', 'follow me on', 'subscribe to'],
        'repeat_window_days' => 30,
        'repeat_min_length' => 20,
    ],

    'reports' => [
        // A customer or a staff member may report this many reviews per day; one report per review per account.
        'max_per_day' => 20,
    ],

    'lists' => ['page_size' => 10, 'max_page_size' => 50],

    /*
    | Sorting restaurants by rating: the plain average, then the number of published reviews, then the name. Locations
    | without a review come last. NO confidence adjustment is applied — a ranking formula (for example a Bayesian
    | average) is a business decision that has not been approved, so it is not enabled.
    */
    'ranking' => ['confidence_adjustment' => false],

    // Kept as long as the order they belong to; removed reviews keep their row and history (no public visibility).
    'retention' => ['policy' => 'WITH_ORDER'],
];
