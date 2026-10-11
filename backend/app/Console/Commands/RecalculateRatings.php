<?php

namespace App\Console\Commands;

use App\Services\Review\RestaurantRatingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the rating summary of every location that has (or had) a review from restaurant_reviews. The summaries are
 * derived data kept consistent inside the transactions that change reviews; this is the recovery path — after a
 * restore of a backup, a manual data fix, or whenever a number is doubted.
 */
class RecalculateRatings extends Command
{
    protected $signature = 'reviews:recalculate {--location= : Only this location (its numeric id)}';

    protected $description = 'Recompute the restaurant rating summaries from the published reviews';

    public function handle(RestaurantRatingService $ratings): int
    {
        $only = $this->option('location');
        $ids = DB::table('restaurant_reviews')->select('location_id')
            ->union(DB::table('restaurant_location_rating_summaries')->select('location_id'))
            ->when($only !== null, fn ($q) => $q->where('location_id', (int) $only))
            ->pluck('location_id')->unique()->sort()->values();
        foreach ($ids as $id) {
            DB::transaction(function () use ($ratings, $id): void {
                $ratings->lock((int) $id);
                $ratings->recalculate((int) $id);
            });
        }
        $this->info('Rating summaries recalculated: '.$ids->count());

        return self::SUCCESS;
    }
}
