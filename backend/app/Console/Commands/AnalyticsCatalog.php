<?php

namespace App\Console\Commands;

use App\Services\Analytics\EventRegistry;
use App\Services\Analytics\MetricCatalog;
use Illuminate\Console\Command;

/**
 * Prints the metric catalog or the event registry as Markdown (Module 38) — the text of docs/analytics/
 * metric-catalog.md and event-registry.md. The documents are written FROM the code, so a definition cannot say one
 * thing on a screen and another in the documentation; a test compares the files with this output.
 */
class AnalyticsCatalog extends Command
{
    protected $signature = 'analytics:catalog
        {--events : print the behavioural event registry instead of the metric catalog}
        {--write : write both documents to docs/analytics/ instead of printing}';

    protected $description = 'Print the analytics metric catalog (or the event registry) as Markdown.';

    public function handle(EventRegistry $events): int
    {
        if ($this->option('write')) {
            // Written by PHP itself: a console redirect on Windows drops blank lines and re-encodes the text.
            $directory = base_path('../docs/analytics');
            file_put_contents($directory.'/metric-catalog.md', self::metrics());
            file_put_contents($directory.'/event-registry.md', self::events($events));
            $this->line((string) json_encode(['written' => ['docs/analytics/metric-catalog.md', 'docs/analytics/event-registry.md']]));

            return self::SUCCESS;
        }
        $this->output->write($this->option('events') ? self::events($events) : self::metrics());

        return self::SUCCESS;
    }

    public static function metrics(): string
    {
        $groups = [];
        foreach (MetricCatalog::all() as $metric) {
            $groups[$metric['group']][] = $metric;
        }
        $titles = ['platform' => 'Platform', 'orders' => 'Orders', 'customers' => 'Customers', 'funnel' => 'Customer funnel', 'restaurants' => 'Restaurants', 'menu' => 'Menu', 'routes' => 'Journeys, routes and corridors',
            'pickup' => 'Pickup', 'payments' => 'Payments', 'refunds' => 'Refunds', 'finance' => 'Finance (from the financial ledger)', 'support' => 'Support', 'reviews' => 'Reviews', 'notifications' => 'Notifications',
            'live' => 'Figures of this moment (not of a period)'];
        $out = "# Metric catalog\n\n"
            ."> GENERATED from `backend/app/Services/Analytics/MetricCatalog.php` by `php artisan analytics:catalog --write`. Do not edit by hand: change the catalog and run the command (a test compares this file with the command's output).\n\n"
            .'Every figure the analytics API can return is defined here — '.count(MetricCatalog::keys())." metrics. A screen cannot show a figure that is not in this catalog: the API refuses to build one.\n\n"
            ."Words used on purpose: **GMV is not revenue.** Platform revenue is commission plus the platform's own fees, from the financial ledger. A restaurant's gross sales, its net earnings, what it is owed and what was paid out are four different figures. "
            ."Money is kept in minor units and reported per currency — currencies are never added together. Every definition, threshold and owner is a DEVELOPMENT DEFAULT, **PENDING BUSINESS APPROVAL**.\n\n"
            ."Freshness: `AGGREGATED` = read model rebuilt about every 5 minutes · `OPERATIONAL` = read from the operational records at the moment of the request · `LEDGER` = read from the financial ledger at the moment of the request. Nothing aggregated is called \"live\".\n";
        foreach ($titles as $group => $title) {
            if (! isset($groups[$group])) {
                continue;
            }
            $out .= "\n## {$title}\n";
            foreach ($groups[$group] as $m) {
                $out .= "\n### {$m['name']}\n\n"
                    ."| | |\n|---|---|\n"
                    ."| Key | `{$m['key']}` |\n| Unit | {$m['unit']} |\n| Definition | {$m['definition']} |\n| Source | {$m['source']} |\n"
                    .($m['numerator'] === null ? '' : "| Numerator | {$m['numerator']} |\n| Denominator | {$m['denominator']} |\n")
                    ."| Calculation | {$m['aggregation']} |\n| Filters | {$m['filters']} |\n| Time zone | {$m['timezone']} |\n| Currency | {$m['currency']} |\n| Exclusions | {$m['exclusions']} |\n"
                    ."| Freshness | {$m['freshness']} — {$m['freshness_meaning']} |\n| Owner | {$m['owner']} ({$m['approval']}) |\n| Known limitations | ".($m['limitations'] ?? 'None recorded')." |\n";
            }
        }

        return $out;
    }

    public static function events(EventRegistry $events): string
    {
        $out = "# Behavioural event registry\n\n"
            ."> GENERATED from `backend/config/analytics.php` (`events.registry`) by `php artisan analytics:catalog --write`. Do not edit by hand (a test compares this file with the command's output).\n\n"
            .'An event that is not in this registry does not exist: the ingestion endpoint refuses the name, and a registered event with a property that is not listed is refused too. '
            .'Schema version: '.(int) config('analytics.events.schema_version').". Names are stable lower_snake_case and mean the same on the web and on Android.\n\n"
            ."There is deliberately **no** `payment_verified`, `order_created`, `order_completed` or `refund_completed`: those are facts of the payment, order and refund domains and are read from them — no client can report them.\n\n"
            .'Session: a random identifier the application makes up (not a device identifier), replaced after '.(int) config('analytics.session.idle_minutes').' minutes idle or '.(int) config('analytics.session.max_hours').' hours. '
            .'Batch: at most '.(int) config('analytics.events.max_batch').' events; events older than '.(int) config('analytics.events.max_age_hours')." hours are refused.\n\n"
            ."Privacy classes: `BEHAVIOURAL` = what a session did in the application · `OPERATIONAL` = what the service answered. Neither carries a phone number, an e-mail address, a name, an address, a coordinate, a route, a search text or an amount.\n";
        foreach (['CLIENT' => 'Sent by the applications (web and Android)', 'SERVER' => 'Recorded by the backend itself'] as $source => $title) {
            $out .= "\n## {$title}\n";
            foreach ($events->all() as $name => $e) {
                if ($e['source'] !== $source) {
                    continue;
                }
                $refs = array_map(fn (string $r): string => in_array($r, (array) $e['required'], true) ? "**{$r}** (required)" : $r, (array) $e['refs']);
                $properties = array_map(fn (string $key, string $rule): string => "`{$key}` ({$rule})", array_keys((array) $e['properties']), array_values((array) $e['properties']));
                $out .= "\n### `{$name}`\n\n| | |\n|---|---|\n| Source | {$source} |\n| Trigger | {$e['trigger']} |\n| Funnel stage | ".($e['stage'] === null ? '—' : (string) $e['stage'])." |\n"
                    .'| References | '.($refs === [] ? 'none' : implode(', ', $refs))." |\n| Properties | ".($properties === [] ? 'none' : implode(', ', $properties))." |\n"
                    ."| Privacy class | {$e['privacy']} |\n| Deduplication | {$e['dedupe']} |\n| Schema version | ".(int) config('analytics.events.schema_version')." |\n";
            }
        }

        return $out;
    }
}
