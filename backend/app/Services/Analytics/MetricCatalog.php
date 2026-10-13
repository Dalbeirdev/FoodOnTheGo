<?php

namespace App\Services\Analytics;

/**
 * THE METRIC CATALOG (Module 38) — every figure the reporting API can return, defined once.
 *
 * No screen may show a number that is not here: `Metric` refuses a key the catalog does not know. A definition says
 * what is counted, from which domain, over what (numerator / denominator for a rate), what is left out, in which
 * time zone and currency, how fresh it is and who owns the definition. The owners and every threshold are DEVELOPMENT
 * DEFAULTS, PENDING BUSINESS APPROVAL.
 *
 * Words used on purpose: GMV is not revenue. Platform revenue is the commission and the platform's own fees, from the
 * financial ledger. A restaurant's gross sales, its net earnings, what it is owed and what was paid out are four
 * different figures.
 */
final class MetricCatalog
{
    private const OWNERS = [
        'platform' => 'Operations', 'customers' => 'Growth', 'funnel' => 'Growth', 'orders' => 'Operations', 'restaurants' => 'Restaurant success', 'menu' => 'Restaurant success',
        'routes' => 'Marketplace', 'pickup' => 'Operations', 'payments' => 'Payments', 'refunds' => 'Payments', 'finance' => 'Finance', 'support' => 'Support', 'reviews' => 'Trust and quality',
        'notifications' => 'Platform', 'live' => 'Operations',
    ];

    private const FRESHNESS = [
        'AGGREGATED' => 'Aggregated read model, rebuilt about every 5 minutes',
        'OPERATIONAL' => 'Read from the operational records at the moment of the request',
        'LEDGER' => 'Read from the financial ledger at the moment of the request',
    ];

    /** key => [name, group, unit, definition, source, numerator, denominator, exclusions, freshness, notes] */
    private const METRICS = [
        // ---------------------------------------------------------------------------------------------- orders
        'orders_placed' => ['Orders placed', 'orders', 'count', 'Orders created in the period (by the time they were placed).', 'Order domain (orders.placed_at)', null, null, 'None: every order counts, whatever became of it.', 'AGGREGATED', null],
        'orders_completed' => ['Orders completed', 'orders', 'count', 'Orders that reached COMPLETED in the period (by the time they were completed).', 'Order domain (orders.completed_at, status COMPLETED)', null, null, 'Orders that are active, rejected or cancelled. A picked-up order counts once it is completed.', 'AGGREGATED', 'A "successful order" on any screen means this.'],
        'orders_rejected' => ['Orders rejected', 'orders', 'count', 'Orders placed in the period that the restaurant rejected.', 'Order domain (status REJECTED)', null, null, null, 'AGGREGATED', 'Counted on the day the order was placed.'],
        'orders_cancelled' => ['Orders cancelled', 'orders', 'count', 'Orders placed in the period that were cancelled.', 'Order domain (status CANCELLED)', null, null, null, 'AGGREGATED', 'Counted on the day the order was placed.'],
        'orders_in_progress' => ['Orders still in progress', 'orders', 'count', 'Orders placed in the period that have not reached a final state yet.', 'Order domain (status WAITING_FOR_RESTAURANT, ACCEPTED, PREPARING, READY_FOR_PICKUP, PICKED_UP)', null, null, null, 'AGGREGATED', 'These are never counted as completed.'],
        'orders_refunded' => ['Orders with a refund', 'orders', 'count', 'Orders placed in the period for which at least one refund completed.', 'Refund domain (refunds with status REFUNDED)', null, null, 'Refunds that are requested, processing, failed or cancelled.', 'AGGREGATED', null],
        'orders_refund_required' => ['Orders waiting for a refund', 'live', 'count', 'Orders whose refund is required and not issued yet — now.', 'Order domain (refund_status REQUIRED)', null, null, null, 'AGGREGATED', 'A current figure, not a figure of the period.'],
        'order_completion_rate' => ['Order completion rate', 'orders', 'percent', 'Of the orders placed in the period that reached a final state, the share that completed.', 'Order domain', 'Orders placed in the period with status COMPLETED', 'Orders placed in the period with status COMPLETED, REJECTED or CANCELLED', 'Orders still in progress are in neither number, so a recent period is not understated.', 'AGGREGATED', null],
        'order_rejection_rate' => ['Order rejection rate', 'orders', 'percent', 'Of the orders placed in the period, the share the restaurant rejected.', 'Order domain', 'Orders placed in the period with status REJECTED', 'Orders placed in the period', null, 'AGGREGATED', null],
        'average_order_value' => ['Average order value', 'orders', 'money', 'What a completed order came to, on average.', 'Order domain (orders.total_minor)', 'Sum of the totals of orders completed in the period', 'Orders completed in the period', 'Refunds are not subtracted (they are reported on their own).', 'AGGREGATED', 'The total a customer paid: items after discounts, plus tax and fees.'],
        'median_order_value' => ['Median order value', 'orders', 'money', 'The middle total of the orders completed in the period.', 'Order domain (orders.total_minor)', null, null, 'Refunds are not subtracted.', 'AGGREGATED', null],
        'acceptance_time' => ['Time to accept', 'orders', 'seconds', 'From an order being placed to the restaurant accepting it.', 'Order domain (placed_at → accepted_at)', null, null, 'Orders that were never accepted.', 'AGGREGATED', 'Average, median and 90th percentile. An operational figure — not a judgement of the food.'],
        'preparation_time' => ['Preparation time', 'orders', 'seconds', 'From the restaurant accepting an order to marking it ready.', 'Order domain (accepted_at → ready_at)', null, null, 'Orders that were never marked ready.', 'AGGREGATED', 'Compared with the estimate the customer was shown; operational only.'],
        'pickup_wait_time' => ['Ready-to-pickup time', 'orders', 'seconds', 'From an order being ready to it being handed over.', 'Order domain (ready_at → picked_up_at)', null, null, 'Orders that were never picked up.', 'AGGREGATED', null],
        'completion_time' => ['Time to completion', 'orders', 'seconds', 'From an order being placed to it being completed.', 'Order domain (placed_at → completed_at)', null, null, 'Orders that did not complete.', 'AGGREGATED', 'Includes the time a scheduled order waited for its pickup time.'],
        // ---------------------------------------------------------------------------------------------- money of orders
        'gmv' => ['Gross merchandise value (GMV)', 'platform', 'money', 'The sum of what customers paid for the orders COMPLETED in the period: items after discounts, plus tax and fees.', 'Order domain (orders.total_minor of completed orders)', null, null, 'Orders that are active, rejected or cancelled. Refunds are NOT subtracted.', 'AGGREGATED', 'GMV is NOT FoodOnTheGo revenue: almost all of it is the restaurants\' money.'],
        'completed_order_total' => ['Sales of completed orders', 'restaurants', 'money', 'What customers paid for the orders completed in the period: items after discounts, plus tax and fees.', 'Order domain (orders.total_minor of completed orders)', null, null, 'Orders that are active, rejected or cancelled. Refunds are NOT subtracted.', 'AGGREGATED', 'Not what the restaurant earned: commission is not taken off here. Earnings are on the Earnings page.'],
        // ---------------------------------------------------------------------------------------------- customers
        'new_accounts' => ['New accounts', 'customers', 'count', 'Customer accounts created in the period.', 'Customer accounts (customers.created_at)', null, null, null, 'AGGREGATED', 'An account, not an order: see first-time ordering customers.'],
        'first_time_ordering_customers' => ['First-time ordering customers', 'customers', 'count', 'Customers whose FIRST completed order was completed in the period.', 'Order domain (first completed order per customer)', null, null, null, 'AGGREGATED', null],
        'ordering_customers' => ['Ordering customers', 'customers', 'count', 'Different customers with at least one order completed in the period.', 'Order domain (completed orders)', null, null, null, 'AGGREGATED', 'A customer is counted once however many orders they completed.'],
        'repeat_customers' => ['Repeat customers', 'customers', 'count', 'Ordering customers of the period who, by its end, had completed at least two orders in total.', 'Order domain (completed orders)', null, null, null, 'AGGREGATED', null],
        'repeat_order_rate' => ['Repeat customer share', 'customers', 'percent', 'Of the customers who ordered in the period, the share who are repeat customers.', 'Order domain', 'Repeat customers', 'Ordering customers', null, 'AGGREGATED', null],
        'active_customers' => ['Active customers', 'customers', 'count', 'Different signed-in customers who, in the period, placed an order or did something the applications record while signed in.', 'Order domain + behavioural events of signed-in sessions', null, null, 'Guests. A customer who only received a notification.', 'AGGREGATED', 'Behavioural events exist from Module 38 on; earlier periods count ordering activity only.'],
        'orders_per_customer' => ['Orders per ordering customer', 'customers', 'number', 'Completed orders of the period divided by the customers who completed them.', 'Order domain', 'Orders completed in the period', 'Ordering customers', null, 'AGGREGATED', null],
        // ---------------------------------------------------------------------------------------------- restaurants
        'active_restaurants' => ['Restaurants with completed orders', 'restaurants', 'count', 'Restaurant locations with at least one order completed in the period.', 'Order domain', null, null, null, 'AGGREGATED', 'Not the same as "orderable now".'],
        'orderable_restaurants' => ['Restaurants accepting orders now', 'live', 'count', 'Approved restaurant locations that are accepting orders at this moment.', 'Restaurant domain (status APPROVED, accepting_orders)', null, null, null, 'OPERATIONAL', 'A current figure, not a figure of the period.'],
        'restaurant_gross_sales' => ['Restaurant gross sales', 'restaurants', 'money', 'The merchandise restaurants sold through the platform in orders RECOGNIZED in the period.', 'Financial allocations (Module 36: merchandise_gross_minor, recognized_at)', null, null, 'Tax and platform fees. Orders that are not recognized.', 'LEDGER', 'The same figure the finance report calls gross marketplace sales.'],
        'restaurant_net_earnings' => ['Restaurant net earnings', 'restaurants', 'money', 'What restaurants earned on the orders recognized in the period, after commission and their own discounts.', 'Financial allocations (Module 36: restaurant_net_minor)', null, null, 'Later refund or dispute adjustments (reported in the finance module).', 'LEDGER', 'Identical to the finance module — it is read from it.'],
        'restaurant_acceptance_rate' => ['Acceptance rate', 'restaurants', 'percent', 'Of the orders placed in the period that the restaurant answered, the share it accepted.', 'Order domain', 'Orders placed in the period that were accepted', 'Orders placed in the period that were accepted or rejected', 'Orders still waiting for the restaurant, and orders cancelled before an answer.', 'AGGREGATED', null],
        'restaurant_refund_rate' => ['Refund rate (orders)', 'restaurants', 'percent', 'Of the orders placed in the period, the share with a completed refund.', 'Order + refund domains', 'Orders placed in the period with a completed refund', 'Orders placed in the period', null, 'AGGREGATED', null],
        // ---------------------------------------------------------------------------------------------- menu
        'menu_views' => ['Menu views', 'menu', 'count', 'Times a restaurant\'s menu was displayed.', 'Behavioural event menu_viewed', null, null, null, 'AGGREGATED', 'Recorded from Module 38 on.'],
        'item_views' => ['Item views', 'menu', 'count', 'Times the detail of a menu item was opened.', 'Behavioural event item_viewed', null, null, null, 'AGGREGATED', 'Recorded from Module 38 on.'],
        'cart_adds' => ['Add-to-cart', 'menu', 'count', 'Times an item was accepted into a cart.', 'Behavioural event item_added_to_cart', null, null, null, 'AGGREGATED', 'What was put in a cart — not what was sold.'],
        'units_sold' => ['Units sold', 'menu', 'count', 'Units of menu items in orders completed in the period.', 'Order items of completed orders (snapshots)', null, null, 'Items of orders that did not complete.', 'AGGREGATED', null],
        'item_sales' => ['Item sales', 'menu', 'money', 'Line subtotals of the items in orders completed in the period.', 'Order items of completed orders (snapshots)', null, null, 'Order-level discounts, tax and fees.', 'AGGREGATED', null],
        'add_to_cart_rate' => ['Add-to-cart rate', 'menu', 'percent', 'Of the sessions that saw the menu, the share that added an item to a cart.', 'Behavioural events', 'Sessions with item_added_to_cart at the restaurant', 'Sessions with menu_viewed at the restaurant', null, 'AGGREGATED', 'Sessions against sessions — never mixed with customers.'],
        // ---------------------------------------------------------------------------------------------- funnel
        'funnel_sessions' => ['Sessions that searched a journey', 'funnel', 'count', 'Sessions that started a journey search in the period — the top of the funnel.', 'Behavioural event journey_search_started', null, null, 'Sessions that never searched a journey.', 'AGGREGATED', null],
        'funnel_overall_conversion' => ['Journey search to completed order', 'funnel', 'percent', 'Of the sessions that searched a journey in the period, the share whose order was completed.', 'Behavioural events + payment and order domains', 'Sessions of the period that reached "order completed"', 'Sessions of the period that reached "journey search"', null, 'AGGREGATED', 'Sessions against sessions. A session is bounded: an old search never converts against a later order.'],
        'funnel_stage_conversion' => ['Conversion from the previous stage', 'funnel', 'percent', 'Of the sessions that reached a stage, the share that reached the next one.', 'Behavioural events + payment and order domains', 'Sessions that reached the next stage', 'Sessions that reached the stage', null, 'AGGREGATED', null],
        // ---------------------------------------------------------------------------------------------- routes
        'journey_searches' => ['Journey searches', 'routes', 'count', 'Journeys the backend planned in the period (a route was found).', 'Server-recorded event journey_search_succeeded', null, null, 'Searches that failed before a route was found.', 'AGGREGATED', 'Recorded from Module 38 on. No address, coordinate or route is kept.'],
        'no_result_searches' => ['Searches without a restaurant', 'routes', 'count', 'Planned journeys for which discovery found no restaurant — and not because of the traveller\'s own filters.', 'Server-recorded event journey_search_no_result', null, null, 'An empty answer caused by filters.', 'AGGREGATED', null],
        'no_result_rate' => ['Share of searches without a restaurant', 'routes', 'percent', 'Of the journeys planned in the period, the share that found no restaurant.', 'Server-recorded events', 'Searches without a restaurant', 'Journey searches', null, 'AGGREGATED', null],
        'restaurant_impressions' => ['Restaurant impressions', 'routes', 'count', 'Times a restaurant was RETURNED in a discovery answer for a journey (once per journey and restaurant).', 'Server-recorded event restaurant_impression', null, null, null, 'AGGREGATED', 'Returned by the backend — not proven to have been seen on a screen.'],
        'journey_restaurant_views' => ['Restaurant views from a journey', 'routes', 'count', 'Times a restaurant page was opened from a journey.', 'Behavioural event restaurant_viewed with a journey', null, null, null, 'AGGREGATED', null],
        'journey_orders' => ['Orders from a journey', 'routes', 'count', 'Orders placed in the period whose cart was linked to a journey.', 'Order domain (the journey recorded on the order)', null, null, null, 'AGGREGATED', null],
        'journey_order_conversion' => ['Journey to order', 'routes', 'percent', 'Orders from a journey per journey planned in the period.', 'Order domain + server-recorded events', 'Orders from a journey placed in the period', 'Journey searches of the period', null, 'AGGREGATED', 'Orders against journeys of the same period; not a session funnel.'],
        'average_detour' => ['Average detour of ordering travellers', 'routes', 'seconds', 'The detour to the restaurant, for the orders from a journey placed in the period.', 'Order domain (the detour recorded on the order)', null, null, 'Orders without a journey.', 'AGGREGATED', null],
        'average_restaurants_returned' => ['Restaurants returned per journey', 'routes', 'number', 'Restaurant impressions divided by the journeys that found at least one restaurant.', 'Server-recorded events', 'Restaurant impressions', 'Journey searches minus searches without a restaurant', null, 'AGGREGATED', null],
        // ---------------------------------------------------------------------------------------------- pickup
        'pickup_asap_orders' => ['ASAP pickups', 'pickup', 'count', 'Orders placed in the period for pickup as soon as possible.', 'Order domain (pickup_mode ASAP)', null, null, null, 'AGGREGATED', null],
        'pickup_scheduled_orders' => ['Scheduled pickups', 'pickup', 'count', 'Orders placed in the period for a chosen pickup time.', 'Order domain (pickup_mode SCHEDULED)', null, null, null, 'AGGREGATED', null],
        'pickup_lead_time' => ['Pickup lead time', 'pickup', 'seconds', 'From an order being placed to its pickup time, on average.', 'Order domain (placed_at → pickup_at)', null, null, null, 'AGGREGATED', null],
        'pickup_refusals' => ['Pickup times refused', 'pickup', 'count', 'Times the backend refused a pickup time when it was chosen.', 'Server-recorded event pickup_slot_refused', null, null, null, 'AGGREGATED', 'Recorded from Module 38 on.'],
        'pickup_slot_full' => ['Pickup times refused: slot full', 'pickup', 'count', 'Refusals because the slot had no capacity left.', 'Server-recorded event pickup_slot_refused (reason SLOT_FULL)', null, null, null, 'AGGREGATED', null],
        'pickup_capacity_utilization' => ['Capacity used in slots with orders', 'pickup', 'percent', 'Units reserved by orders, of the capacity of the slots that had at least one order.', 'Pickup reservations of orders + the restaurant\'s configured capacity per slot', 'Units reserved by orders', 'Capacity per slot × slots with at least one order', 'Restaurants with unlimited capacity: nothing is made up for them.', 'AGGREGATED', 'Only where a capacity per slot is configured.'],
        'recommended_slot_acceptance' => ['Recommended time chosen', 'pickup', 'percent', 'Of the pickup selections where the application said whether the time was the recommended one, the share that was.', 'Behavioural event pickup_selected', 'Selections of the recommended time', 'Selections that say whether the time was recommended', null, 'AGGREGATED', null],
        // ---------------------------------------------------------------------------------------------- payments
        'payment_attempts' => ['Payment attempts', 'payments', 'count', 'Payment attempts created in the period.', 'Payment domain (payment_attempts.created_at)', null, null, null, 'AGGREGATED', null],
        'payments_verified' => ['Payments verified', 'payments', 'count', 'Attempts created in the period that the backend verified.', 'Payment domain (status VERIFIED)', null, null, null, 'AGGREGATED', 'Verified by the backend against the provider — never reported by a client.'],
        'payments_failed' => ['Payments failed', 'payments', 'count', 'Attempts created in the period that failed.', 'Payment domain (status FAILED)', null, null, null, 'AGGREGATED', null],
        'payments_cancelled' => ['Payments cancelled', 'payments', 'count', 'Attempts created in the period that the customer cancelled.', 'Payment domain (status CANCELLED)', null, null, null, 'AGGREGATED', null],
        'payments_expired' => ['Payments expired', 'payments', 'count', 'Attempts created in the period that ran out of time.', 'Payment domain (status EXPIRED)', null, null, null, 'AGGREGATED', 'Not completed — not called a failure.'],
        'payments_pending' => ['Payments pending', 'payments', 'count', 'Attempts created in the period that are not finished yet.', 'Payment domain (status CREATED, PROVIDER_READY, PROCESSING, PENDING, AUTHORIZED)', null, null, null, 'AGGREGATED', 'Never counted as verified.'],
        'payments_unknown' => ['Payments unknown or in review', 'payments', 'count', 'Attempts created in the period whose outcome is not known or needs a person.', 'Payment domain (status UNKNOWN, REVIEW_REQUIRED)', null, null, null, 'AGGREGATED', null],
        'payment_success_rate' => ['Payment success rate', 'payments', 'percent', 'Of the attempts created in the period that are finished, the share that was verified.', 'Payment domain', 'Attempts with status VERIFIED', 'Attempts with status VERIFIED, FAILED, CANCELLED or EXPIRED', 'Pending and unknown attempts are in neither number.', 'AGGREGATED', null],
        'payments_verified_amount' => ['Verified payment amount', 'payments', 'money', 'The amounts of the attempts created in the period that were verified.', 'Payment domain (payment_attempts.amount_minor, status VERIFIED)', null, null, null, 'AGGREGATED', 'Customer money received — not revenue.'],
        'payment_verification_time' => ['Time to verification', 'payments', 'seconds', 'From a payment attempt being created to it being verified, on average.', 'Payment domain (created_at → verified_at)', null, null, 'Attempts that were not verified.', 'AGGREGATED', null],
        // ---------------------------------------------------------------------------------------------- refunds
        'refunds_completed' => ['Refunds completed', 'refunds', 'count', 'Refunds that completed in the period.', 'Refund domain (status REFUNDED, completed_at)', null, null, 'Refunds that are requested, processing, failed or cancelled.', 'AGGREGATED', null],
        'refund_amount' => ['Refund amount', 'refunds', 'money', 'The money that went back to customers in refunds completed in the period.', 'Refund domain (approved amount of REFUNDED refunds)', null, null, null, 'AGGREGATED', 'From the refunds themselves — never estimated from an order\'s status.'],
        'refunds_full' => ['Full refunds', 'refunds', 'count', 'Refunds completed in the period for the whole payment.', 'Refund domain (type FULL)', null, null, null, 'AGGREGATED', null],
        'refunds_partial' => ['Partial refunds', 'refunds', 'count', 'Refunds completed in the period for a part of the payment.', 'Refund domain (type PARTIAL)', null, null, null, 'AGGREGATED', null],
        'refunds_in_progress' => ['Refunds in progress', 'refunds', 'count', 'Refunds asked for in the period that are not finished.', 'Refund domain (status REQUIRED, REQUESTED, APPROVED, SUBMITTING, SUBMISSION_UNKNOWN, PROCESSING, REVIEW_REQUIRED)', null, null, null, 'AGGREGATED', null],
        'refunds_failed' => ['Refunds failed', 'refunds', 'count', 'Refunds asked for in the period that failed.', 'Refund domain (status FAILED)', null, null, null, 'AGGREGATED', null],
        'refund_processing_time' => ['Refund processing time', 'refunds', 'seconds', 'From a refund being asked for to it completing, on average.', 'Refund domain (requested_at → completed_at)', null, null, 'Refunds that did not complete.', 'AGGREGATED', null],
        'refund_rate_orders' => ['Refund rate (orders)', 'refunds', 'percent', 'Of the orders placed in the period, the share with a completed refund.', 'Order + refund domains', 'Orders placed in the period with a completed refund', 'Orders placed in the period', null, 'AGGREGATED', 'A different figure from the refund rate by amount.'],
        'refund_rate_amount' => ['Refund rate (amount)', 'refunds', 'percent', 'Refund amount of the period as a share of the verified payment amount of the period.', 'Refund + payment domains', 'Refund amount (refunds completed in the period)', 'Verified payment amount (attempts created in the period)', null, 'AGGREGATED', 'A different figure from the refund rate by orders.'],
        // ---------------------------------------------------------------------------------------------- finance
        'gross_marketplace_sales' => ['Gross marketplace sales', 'finance', 'money', 'The merchandise restaurants sold through the platform in orders recognized in the period.', 'Financial allocations (Module 36)', null, null, 'Tax and platform fees.', 'LEDGER', 'NOT FoodOnTheGo revenue.'],
        'platform_commission' => ['Platform commission', 'finance', 'money', 'Commission the platform earned in the period.', 'Financial ledger (account PLATFORM_COMMISSION_REVENUE)', null, null, null, 'LEDGER', 'Every commission plan on this machine is a TEST FIXTURE.'],
        'platform_fee_revenue' => ['Platform fees', 'finance', 'money', 'Customer fees that are the platform\'s own, in the period.', 'Financial ledger (account PLATFORM_FEE_REVENUE)', null, null, null, 'LEDGER', null],
        'platform_revenue' => ['Platform revenue', 'finance', 'money', 'Commission plus the platform\'s own fees.', 'Financial ledger', null, null, 'GMV and gross marketplace sales are never part of it.', 'LEDGER', 'The only figure called revenue.'],
        'customer_payments' => ['Customer payments', 'finance', 'money', 'Verified customer payments posted to the ledger in the period.', 'Financial ledger (transactions PAYMENT_VERIFIED)', null, null, null, 'LEDGER', 'Customer money — not revenue.'],
        'ledger_refunds' => ['Refunds (ledger)', 'finance', 'money', 'Completed refunds posted to the ledger in the period.', 'Financial ledger (transactions REFUND_COMPLETED)', null, null, null, 'LEDGER', null],
        'provider_fees' => ['Payment provider fees', 'finance', 'money', 'What the payment provider itself reported as its fees, posted in the period.', 'Financial ledger (account PROVIDER_FEE_EXPENSE) from imported provider settlement records', null, null, null, 'LEDGER', 'Never estimated: without an import the figure is zero.'],
        'promotion_expense' => ['Platform-funded discounts', 'finance', 'money', 'Discounts the platform paid for, in the period.', 'Financial ledger (account PROMOTION_EXPENSE)', null, null, null, 'LEDGER', null],
        'restaurant_payable' => ['Owed to restaurants now', 'finance', 'money', 'What the platform owes restaurants at this moment.', 'Financial ledger (balance of RESTAURANT_PAYABLE)', null, null, null, 'LEDGER', 'A balance, not a figure of the period.'],
        'restaurant_paid_out' => ['Paid out to restaurants', 'finance', 'money', 'Payouts to restaurants posted to the ledger in the period.', 'Financial ledger (account PAYOUT_CLEARING)', null, null, null, 'LEDGER', 'LIVE PAYOUTS ARE DISABLED: on this machine payouts come from the fake payout provider only.'],
        'settlements_generated' => ['Settlements generated', 'finance', 'count', 'Restaurant settlements generated in the period.', 'Settlement domain (restaurant_settlements.generated_at)', null, null, null, 'LEDGER', null],
        'settlement_time_to_payout' => ['Settlement to payout', 'finance', 'seconds', 'From a settlement being generated to it being paid, on average.', 'Settlement domain (generated_at → paid_at)', null, null, 'Settlements that are not paid.', 'LEDGER', null],
        'reconciliation_differences_open' => ['Open reconciliation differences', 'finance', 'count', 'Differences between the records that nobody has resolved — now.', 'Reconciliation (status OPEN, INVESTIGATING)', null, null, null, 'LEDGER', 'A current figure.'],
        // ---------------------------------------------------------------------------------------------- support
        'support_cases_created' => ['Cases opened', 'support', 'count', 'Support cases opened in the period.', 'Support domain (support_cases.created_at)', null, null, null, 'AGGREGATED', null],
        'support_cases_resolved' => ['Cases resolved', 'support', 'count', 'Cases resolved in the period.', 'Support domain (resolved_at)', null, null, null, 'AGGREGATED', null],
        'support_cases_closed' => ['Cases closed', 'support', 'count', 'Cases closed in the period.', 'Support domain (closed_at)', null, null, null, 'AGGREGATED', null],
        'support_cases_reopened' => ['Cases reopened', 'support', 'count', 'Cases whose last reopening was in the period.', 'Support domain (reopened_at)', null, null, null, 'AGGREGATED', null],
        'support_cases_escalated' => ['Cases escalated', 'support', 'count', 'Cases escalated in the period.', 'Support domain (escalated_at)', null, null, null, 'AGGREGATED', null],
        'support_sla_breached' => ['Targets missed', 'support', 'count', 'Cases that missed an internal target in the period.', 'Support domain (sla_breached_at)', null, null, null, 'AGGREGATED', 'The targets are TEST FIXTURES and internal — not a promise to anyone.'],
        'support_reopen_rate' => ['Reopen rate', 'support', 'percent', 'Cases reopened in the period per case resolved in the period.', 'Support domain', 'Cases reopened in the period', 'Cases resolved in the period', null, 'AGGREGATED', 'A quality indicator of the service — not of a person.'],
        'support_first_response_time' => ['First response time', 'support', 'seconds', 'From a case opened in the period to the first answer of support, on average.', 'Support domain (created_at → first_response_at)', null, null, 'Cases without a first response.', 'AGGREGATED', 'Wall-clock time.'],
        'support_resolution_time' => ['Resolution time', 'support', 'seconds', 'From a case being opened to it being resolved, for the cases resolved in the period, on average.', 'Support domain (created_at → resolved_at)', null, null, null, 'AGGREGATED', 'Wall-clock time: the time support waited for the requester is NOT taken out.'],
        'support_open_cases' => ['Open cases', 'live', 'count', 'Cases that are not resolved, closed or cancelled — now.', 'Support domain (status)', null, null, null, 'OPERATIONAL', 'A current figure.'],
        'support_unassigned_cases' => ['Unassigned cases', 'live', 'count', 'Open cases nobody is assigned to — now.', 'Support domain', null, null, null, 'OPERATIONAL', 'A current figure.'],
        'support_urgent_cases' => ['Urgent open cases', 'live', 'count', 'Open cases with priority URGENT — now.', 'Support domain', null, null, null, 'OPERATIONAL', 'A current figure.'],
        'support_sla_at_risk' => ['Targets at risk', 'live', 'count', 'Open cases close to missing an internal target — now.', 'Support domain (sla_status AT_RISK)', null, null, null, 'OPERATIONAL', 'Internal targets on TEST FIXTURE policies.'],
        'support_sla_breached_open' => ['Open cases past a target', 'live', 'count', 'Open cases that missed an internal target — now.', 'Support domain (sla_status BREACHED)', null, null, null, 'OPERATIONAL', 'Internal targets on TEST FIXTURE policies.'],
        // ---------------------------------------------------------------------------------------------- reviews
        'reviews_published' => ['Reviews published', 'reviews', 'count', 'Reviews published in the period that are still published.', 'Review domain (status PUBLISHED, published_at)', null, null, 'Reviews that are hidden, rejected, removed or waiting for moderation.', 'AGGREGATED', null],
        'average_rating' => ['Average rating', 'reviews', 'rating', 'The average of the overall ratings of the reviews published in the period.', 'Review domain (overall_rating of published reviews)', 'Sum of the ratings', 'Reviews published in the period', 'Hidden, rejected and removed reviews.', 'AGGREGATED', 'The rating shown to customers is the all-time one; this is the period\'s.'],
        'review_response_rate' => ['Restaurant response rate', 'reviews', 'percent', 'Of the reviews published in the period, the share the restaurant answered.', 'Review domain', 'Published reviews of the period with a published response', 'Reviews published in the period', null, 'AGGREGATED', null],
        'reviews_reported' => ['Reports of reviews', 'reviews', 'count', 'Reports made about reviews in the period.', 'Review domain (review_reports.created_at)', null, null, null, 'AGGREGATED', null],
        // ---------------------------------------------------------------------------------------------- notifications
        'notifications_created' => ['Notifications created', 'notifications', 'count', 'Notifications created for customers in the period.', 'Notification domain (notifications.created_at)', null, null, null, 'AGGREGATED', null],
        'push_queued' => ['Push waiting', 'notifications', 'count', 'Push deliveries of the period\'s notifications that are waiting or being sent.', 'Notification domain (PUSH: QUEUED, SENDING, RETRY_SCHEDULED)', null, null, null, 'AGGREGATED', null],
        'push_sent' => ['Push sent', 'notifications', 'count', 'Push deliveries of the period\'s notifications that the provider accepted.', 'Notification domain (PUSH: SENT, DELIVERED)', null, null, null, 'AGGREGATED', '"Sent" means the provider accepted it. It does NOT mean anybody read it.'],
        'push_failed' => ['Push failed', 'notifications', 'count', 'Push deliveries of the period\'s notifications that failed.', 'Notification domain (PUSH: FAILED)', null, null, null, 'AGGREGATED', null],
        'push_invalid_tokens' => ['Invalid device tokens', 'notifications', 'count', 'Push deliveries refused because the device token is no longer valid.', 'Notification domain (PUSH: INVALID_DESTINATION)', null, null, null, 'AGGREGATED', null],
        'push_preference_skips' => ['Push not sent by preference', 'notifications', 'count', 'Push deliveries not attempted because of the customer\'s settings or because there is no device.', 'Notification domain (PUSH: SKIPPED, with the policy result)', null, null, null, 'AGGREGATED', null],
        'in_app_read' => ['In-app notifications read', 'notifications', 'count', 'Notifications of the period that were marked read in the application.', 'Notification domain (notifications.read_at)', null, null, null, 'AGGREGATED', 'The in-app read mark only.'],
        'in_app_read_rate' => ['In-app read rate', 'notifications', 'percent', 'Of the notifications created in the period, the share marked read in the application.', 'Notification domain', 'Notifications of the period with a read mark', 'Notifications created in the period', null, 'AGGREGATED', 'Not a push open rate: opening a push is not tracked.'],
        'push_provider_latency' => ['Push provider answer time', 'notifications', 'milliseconds', 'How long the push provider took to answer a delivery attempt, on average.', 'Notification domain (delivery attempts)', null, null, null, 'AGGREGATED', 'On this machine the push provider is the development provider.'],
        // ---------------------------------------------------------------------------------------------- live
        'live_active_orders' => ['Active orders', 'live', 'count', 'Orders that are waiting, accepted, being prepared or ready — now.', 'Order domain', null, null, null, 'OPERATIONAL', 'A current figure.'],
        'live_waiting_for_restaurant' => ['Waiting for the restaurant', 'live', 'count', 'Orders the restaurant has not answered — now.', 'Order domain', null, null, null, 'OPERATIONAL', null],
        'live_preparing' => ['Being prepared', 'live', 'count', 'Orders accepted or in preparation — now.', 'Order domain', null, null, null, 'OPERATIONAL', null],
        'live_ready' => ['Ready for pickup', 'live', 'count', 'Orders ready and not picked up — now.', 'Order domain', null, null, null, 'OPERATIONAL', null],
        'live_payment_recovery' => ['Payments needing recovery', 'live', 'count', 'Verified payments that have no order — now.', 'Payment domain (fulfillment RECOVERY_REQUIRED)', null, null, null, 'OPERATIONAL', null],
        'live_refund_review' => ['Refunds needing a person', 'live', 'count', 'Refunds waiting for review — now.', 'Refund domain (status REVIEW_REQUIRED)', null, null, null, 'OPERATIONAL', null],
    ];

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $key): ?array
    {
        $row = self::METRICS[$key] ?? null;
        if ($row === null) {
            return null;
        }
        [$name, $group, $unit, $definition, $source, $numerator, $denominator, $exclusions, $freshness, $notes] = $row;
        $money = $unit === 'money';

        return [
            'key' => $key, 'name' => $name, 'group' => $group, 'unit' => $unit, 'definition' => $definition, 'source' => $source,
            'numerator' => $numerator, 'denominator' => $denominator,
            'filters' => $group === 'live' ? 'Market (the caller\'s markets)' : 'Period, market; restaurant location where the report is a restaurant\'s',
            'timezone' => $group === 'live' ? 'Not applicable (a figure of this moment)' : 'The market\'s reporting time zone (markets.default_timezone); a restaurant\'s own report uses the restaurant\'s time zone',
            'currency' => $money ? 'One figure per currency (INR in India). Currencies are never added together.' : 'Not a money figure',
            'exclusions' => $exclusions ?? 'None',
            'aggregation' => match ($unit) {
                'percent' => 'Numerator ÷ denominator over the whole period (not an average of daily rates); "insufficient data" under the minimum denominator',
                'seconds', 'milliseconds' => 'Average over the period; median and 90th percentile where the report shows them',
                'rating' => 'Sum of ratings ÷ number of reviews over the period',
                'number' => 'Numerator ÷ denominator over the whole period',
                default => 'Sum over the period',
            },
            'freshness' => $freshness, 'freshness_meaning' => self::FRESHNESS[$freshness],
            'owner' => self::OWNERS[$group], 'approval' => 'PENDING BUSINESS APPROVAL',
            'notes' => $notes, 'limitations' => $notes,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return array_map(fn (string $key): array => (array) self::find($key), array_keys(self::METRICS));
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::METRICS);
    }

    public static function has(string $key): bool
    {
        return isset(self::METRICS[$key]);
    }
}
