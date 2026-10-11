<?php

namespace App\Services\Review;

use App\Enums\ReviewStatus;
use App\Models\RestaurantLocationRatingSummary;
use Illuminate\Support\Facades\DB;

/**
 * The rating of a restaurant LOCATION: the average of the overall ratings of its PUBLISHED reviews, their number and
 * the star distribution (optionally the category averages). Hidden, rejected, removed and waiting reviews do not count.
 *
 * PostgreSQL is the authority. The summary row is recomputed from restaurant_reviews — never incremented — inside the
 * transaction of every change that can affect it, under an advisory lock per location so two concurrent changes cannot
 * write an aggregate that misses one of them. `reviews:recalculate` rebuilds every row.
 */
final class RestaurantRatingService
{
    /**
     * Serialises rating-affecting changes of one location for the length of the current transaction.
     */
    public function lock(int $locationId): void
    {
        DB::select('select pg_advisory_xact_lock(hashtext(?))', ['rating:'.$locationId]);
    }

    public function recalculate(int $locationId): void
    {
        $row = DB::table('restaurant_reviews')
            ->where('location_id', $locationId)->where('status', ReviewStatus::Published->value)
            ->selectRaw('count(*) as n, coalesce(sum(overall_rating), 0) as s, max(published_at) as last_published,'
                .' count(*) filter (where overall_rating = 1) as s1, count(*) filter (where overall_rating = 2) as s2, count(*) filter (where overall_rating = 3) as s3,'
                .' count(*) filter (where overall_rating = 4) as s4, count(*) filter (where overall_rating = 5) as s5,'
                .' case when count(*) = 0 then null else round(sum(overall_rating)::numeric / count(*), 2) end as average')
            ->first();
        $categories = DB::table('restaurant_reviews as r')
            ->crossJoin(DB::raw("lateral jsonb_each_text(case when jsonb_typeof(r.category_ratings) = 'object' then r.category_ratings else '{}'::jsonb end) as kv"))
            ->where('r.location_id', $locationId)->where('r.status', ReviewStatus::Published->value)
            ->groupBy('kv.key')->selectRaw('kv.key as k, sum((kv.value)::int) as s, count(*) as n')->get();
        $totals = [];
        foreach ($categories as $category) {
            $totals[(string) $category->k] = ['sum' => (int) $category->s, 'count' => (int) $category->n];
        }
        ksort($totals);
        $location = DB::table('restaurant_locations')->where('id', $locationId)->first(['organization_id', 'market_id']);
        if ($location === null) {
            return;
        }
        DB::table('restaurant_location_rating_summaries')->updateOrInsert(['location_id' => $locationId], [
            'organization_id' => $location->organization_id,
            'market_id' => $location->market_id,
            'published_count' => (int) $row->n,
            'rating_sum' => (int) $row->s,
            'star_1' => (int) $row->s1, 'star_2' => (int) $row->s2, 'star_3' => (int) $row->s3, 'star_4' => (int) $row->s4, 'star_5' => (int) $row->s5,
            'category_totals' => json_encode($totals === [] ? new \stdClass : $totals),
            'average_rating' => $row->average,
            'last_published_at' => $row->last_published,
            'recalculated_at' => now(),
        ]);
    }

    /**
     * The summary as every API shows it. No review → average null and count 0 (clients say "No reviews yet").
     *
     * @return array{average: float|null, count: int, distribution: array<string, int>, categories: array<string, array{average: float|null, count: int}>}
     */
    public function present(?RestaurantLocationRatingSummary $summary): array
    {
        $count = (int) ($summary?->published_count ?? 0);
        $categories = [];
        foreach (array_keys((array) config('reviews.categories', [])) as $key) {
            $total = (array) (($summary?->category_totals ?? [])[$key] ?? []);
            $n = (int) ($total['count'] ?? 0);
            $categories[(string) $key] = ['average' => self::average((int) ($total['sum'] ?? 0), $n), 'count' => $n];
        }
        $distribution = $summary?->distribution() ?? [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];

        return [
            'average' => self::average((int) ($summary?->rating_sum ?? 0), $count),
            'count' => $count,
            'distribution' => array_combine(array_map('strval', array_keys($distribution)), array_values($distribution)),
            'categories' => $categories,
        ];
    }

    /**
     * The short form for restaurant cards.
     *
     * @return array{average: float|null, count: int}
     */
    public function brief(?RestaurantLocationRatingSummary $summary): array
    {
        $count = (int) ($summary?->published_count ?? 0);

        return ['average' => self::average((int) ($summary?->rating_sum ?? 0), $count), 'count' => $count];
    }

    /**
     * SUM / COUNT to one decimal, rounded half up with integer arithmetic; null (never a division) without reviews.
     */
    public static function average(int $sum, int $count): ?float
    {
        if ($count <= 0) {
            return null;
        }

        return intdiv($sum * 20 + $count, $count * 2) / 10;
    }
}
