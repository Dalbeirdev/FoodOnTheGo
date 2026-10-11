<?php

/*
| Notification templates — locale en-IN (the market default and the fallback). Variables are the allow-listed
| placeholders only (:restaurant, :order, :pickup_time, :reason); every value is treated as untrusted text and escaped per
| output (plain text for push / in-app / SMS, HTML-escaped in the e-mail). `version` is kept on the notification so support
| knows which wording a customer saw. Nothing here ever carries a pickup secret, a code, a phone number or a payment detail.
*/

return [
    'order.created' => [
        'version' => 1,
        'push' => ['title' => 'Order placed', 'body' => ':restaurant is reviewing your order :order.'],
        'in_app' => ['title' => 'Order placed', 'body' => 'Your order :order has reached :restaurant. We will tell you as soon as they confirm it.'],
        'email' => ['subject' => 'Your FoodOnTheGo order :order', 'body' => 'Your order :order has reached :restaurant and is waiting for their confirmation. Open the app to follow it.'],
    ],
    'order.accepted' => [
        'version' => 1,
        'push' => ['title' => 'Order confirmed', 'body' => ':restaurant confirmed your order :order.'],
        'in_app' => ['title' => 'Order confirmed', 'body' => ':restaurant confirmed your order :order. Pickup :pickup_time.'],
    ],
    'order.rejected' => [
        'version' => 1,
        'push' => ['title' => 'Order not accepted', 'body' => ':restaurant could not take your order :order. Open the app for the refund status.'],
        'in_app' => ['title' => 'Order not accepted', 'body' => ':restaurant could not take your order :order. :refund_line'],
        'email' => ['subject' => 'Your FoodOnTheGo order :order was not accepted', 'body' => ':restaurant could not take your order :order. :refund_line'],
    ],
    'order.preparing' => [
        'version' => 1,
        'in_app' => ['title' => 'Being prepared', 'body' => ':restaurant is preparing your order :order.'],
        'push' => ['title' => 'Being prepared', 'body' => ':restaurant is preparing your order :order.'],
    ],
    'order.ready' => [
        'version' => 1,
        'push' => ['title' => 'Your order is ready', 'body' => 'Order :order is ready for pickup at :restaurant. Open the app to show your pickup code.'],
        'in_app' => ['title' => 'Ready for pickup', 'body' => 'Order :order is ready at :restaurant. Show the QR or the pickup code from your order to collect it.'],
        'sms' => ['body' => 'FoodOnTheGo: order :order is ready for pickup at :restaurant. Open the app to show your pickup code.'],
    ],
    'order.picked_up' => [
        'version' => 1,
        'in_app' => ['title' => 'Picked up', 'body' => 'Order :order was handed over at :restaurant. Enjoy your meal.'],
    ],
    'order.completed' => [
        // v2 (Module 34): the completion notice carries the review invitation — one notice, no separate reminder.
        'version' => 2,
        'in_app' => ['title' => 'Order completed', 'body' => 'Order :order at :restaurant is complete. Your receipt is in the order, and you can rate your experience there.'],
        'email' => ['subject' => 'Your FoodOnTheGo order :order is complete', 'body' => 'Order :order at :restaurant is complete. The receipt is available in the app.'],
    ],
    'order.cancelled' => [
        'version' => 1,
        'push' => ['title' => 'Order cancelled', 'body' => 'Your order :order was cancelled. Open the app for the refund status.'],
        'in_app' => ['title' => 'Order cancelled', 'body' => 'Your order :order at :restaurant was cancelled. :refund_line'],
        'email' => ['subject' => 'Your FoodOnTheGo order :order was cancelled', 'body' => 'Your order :order at :restaurant was cancelled. :refund_line'],
    ],
    'refund.pending' => [
        'version' => 1,
        'push' => ['title' => 'Refund on its way', 'body' => 'A refund for order :order is being processed.'],
        'in_app' => ['title' => 'Refund being processed', 'body' => 'A refund for order :order is being processed. We will update you here when it is done.'],
        'email' => ['subject' => 'Refund for your FoodOnTheGo order :order', 'body' => 'A refund for order :order is being processed. You will see the update in the app.'],
    ],
    'payment.recovery_required' => [
        'version' => 1,
        'push' => ['title' => 'Payment received', 'body' => 'We received your payment and are finalising order :order. No need to pay again.'],
        'in_app' => ['title' => 'Payment received, finalising your order', 'body' => 'We received your payment for order :order and are finalising it. You do not need to pay again; we will update you here.'],
        'email' => ['subject' => 'Payment received for your FoodOnTheGo order :order', 'body' => 'We received your payment for order :order and are finalising it. You do not need to pay again.'],
    ],
    'review.response' => [
        'version' => 1,
        'push' => ['title' => ':restaurant replied to your review', 'body' => 'Open the app to read the reply to your review of order :order.'],
        'in_app' => ['title' => ':restaurant replied to your review', 'body' => 'The restaurant has replied to your review of order :order.'],
    ],
    'review.published' => [
        'version' => 1,
        'in_app' => ['title' => 'Your review is published', 'body' => 'Your review of :restaurant for order :order is now public. Thank you for sharing it.'],
    ],
    'review.moderated' => [
        'version' => 1,
        'in_app' => ['title' => 'Your review is not shown', 'body' => 'Your review of :restaurant for order :order is not shown publicly because it does not meet the review guidelines. Contact support if you think this is a mistake.'],
    ],
    'account.phone_changed' => [
        'version' => 1,
        'push' => ['title' => 'Phone number changed', 'body' => 'The phone number on your FoodOnTheGo account was changed. Not you? Open the app and contact support.'],
        'in_app' => ['title' => 'Phone number changed', 'body' => 'The phone number on your account was changed. If this was not you, contact support right away.'],
        'email' => ['subject' => 'Your FoodOnTheGo phone number was changed', 'body' => 'The phone number on your account was changed. If this was not you, contact support right away.'],
    ],

    // Shared fragments
    'fragments' => [
        'refund_line.pending' => 'Your payment will be refunded; the refund is being processed.',
        'refund_line.none' => 'Nothing was charged.',
        'refund_line.not_required' => 'No payment was due.',
        'pickup_time.unknown' => 'as planned',
    ],
];
