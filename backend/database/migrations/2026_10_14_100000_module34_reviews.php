<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 34 — verified order reviews, ratings and moderation.
 *
 *  - restaurant_reviews: ONE review per completed order (unique order_id). Customer, organization, location and market
 *    are copied from the order by the server, never taken from a request. Ratings are integers; the category ratings,
 *    tags and item feedback are validated against config/reviews.php and kept as JSON so a market can configure its
 *    own set. `author_display_name` is the only thing of the customer a public answer carries.
 *  - review_revisions: what a review (or a restaurant response) said before each edit. Append-only; never public.
 *  - review_moderation_cases: one open case per review — opened by the automated screening, by a report or by an
 *    administrator — with its resolution. A case never changes a review by itself.
 *  - review_reports: one row per reporter per review (a second report by the same account is refused).
 *  - restaurant_review_responses: the restaurant's one official answer to a review.
 *  - restaurant_location_rating_summaries: the published-review aggregate of one location, recomputed from
 *    restaurant_reviews inside the transaction of every change that affects it. PostgreSQL stays the authority; the
 *    row can always be rebuilt (`reviews:recalculate`). Kept apart from restaurant_locations on purpose: saving a
 *    location flushes the restaurant catalogue cache.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_reviews', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained('restaurant_organizations')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('restaurant_locations')->restrictOnDelete();
            $table->foreignId('market_id')->constrained('markets')->restrictOnDelete();
            $table->unsignedSmallInteger('overall_rating');
            $table->jsonb('category_ratings')->default('{}');
            $table->jsonb('tags')->default('[]');
            $table->jsonb('item_feedback')->default('[]');
            $table->string('title', 160)->nullable();
            $table->text('comment')->nullable();
            $table->string('comment_hash', 64)->nullable();
            $table->string('author_display_name', 80);
            $table->string('status', 24);
            $table->jsonb('flags')->default('[]');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedSmallInteger('edit_count')->default(0);
            $table->string('client_submission_id', 64)->nullable();
            $table->timestampTz('submitted_at');
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('edited_at')->nullable();
            $table->timestampTz('moderated_at')->nullable();
            $table->timestampTz('removed_at')->nullable();
            $table->string('removed_by', 16)->nullable();
            $table->timestampsTz();

            $table->index(['customer_id', 'submitted_at']);
            $table->index(['location_id', 'status', 'published_at']);
            $table->index(['location_id', 'status', 'overall_rating']);
            $table->index(['organization_id', 'status']);
            $table->index(['market_id', 'status', 'submitted_at']);
            $table->index(['status', 'submitted_at']);
            $table->index(['customer_id', 'comment_hash']);
        });
        DB::statement('alter table restaurant_reviews add constraint restaurant_reviews_overall_rating_check check (overall_rating between 1 and 5)');
        DB::statement("alter table restaurant_reviews add constraint restaurant_reviews_status_check check (status in ('PENDING_MODERATION', 'PUBLISHED', 'HIDDEN', 'REJECTED', 'REMOVED'))");
        DB::statement("alter table restaurant_reviews add constraint restaurant_reviews_removed_by_check check (removed_by is null or removed_by in ('CUSTOMER', 'ADMIN'))");

        Schema::create('review_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_id')->constrained('restaurant_reviews')->restrictOnDelete();
            $table->string('subject', 12);
            $table->unsignedInteger('version');
            $table->unsignedSmallInteger('overall_rating')->nullable();
            $table->jsonb('category_ratings')->nullable();
            $table->jsonb('tags')->nullable();
            $table->jsonb('item_feedback')->nullable();
            $table->string('title', 160)->nullable();
            $table->text('body')->nullable();
            $table->string('status_before', 24)->nullable();
            $table->string('actor_type', 20);
            $table->uuid('actor_public_id')->nullable();
            $table->string('reason', 300)->nullable();
            $table->timestampTz('created_at');

            $table->index(['review_id', 'subject', 'version']);
        });
        DB::statement("alter table review_revisions add constraint review_revisions_subject_check check (subject in ('REVIEW', 'RESPONSE'))");

        Schema::create('review_moderation_cases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('review_id')->constrained('restaurant_reviews')->restrictOnDelete();
            $table->foreignId('market_id')->constrained('markets')->restrictOnDelete();
            $table->string('source', 20);
            $table->string('reason', 32);
            $table->string('status', 16);
            $table->string('reported_by_type', 20)->nullable();
            $table->uuid('reported_by_public_id')->nullable();
            $table->unsignedInteger('report_count')->default(0);
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('resolution', 24)->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->foreignId('resolved_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
            $table->index(['market_id', 'status']);
            $table->index('review_id');
        });
        DB::statement("alter table review_moderation_cases add constraint review_moderation_cases_status_check check (status in ('OPEN', 'ESCALATED', 'RESOLVED'))");
        DB::statement("alter table review_moderation_cases add constraint review_moderation_cases_source_check check (source in ('AUTOMATED', 'CUSTOMER_REPORT', 'RESTAURANT_REPORT', 'ADMIN'))");
        DB::statement("create unique index review_moderation_cases_one_open_per_review on review_moderation_cases (review_id) where status <> 'RESOLVED'");

        Schema::create('review_reports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('review_id')->constrained('restaurant_reviews')->restrictOnDelete();
            $table->foreignId('case_id')->constrained('review_moderation_cases')->restrictOnDelete();
            $table->string('reporter_type', 20);
            $table->unsignedBigInteger('reporter_id');
            $table->uuid('reporter_public_id');
            $table->string('reason', 32);
            $table->string('explanation', 500)->nullable();
            $table->timestampTz('created_at');

            $table->unique(['review_id', 'reporter_type', 'reporter_id']);
            $table->index('case_id');
            $table->index(['reporter_type', 'reporter_id', 'created_at']);
        });

        Schema::create('restaurant_review_responses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('review_id')->unique()->constrained('restaurant_reviews')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('restaurant_locations')->restrictOnDelete();
            $table->foreignId('restaurant_user_id')->constrained('restaurant_users')->restrictOnDelete();
            $table->string('responder_name', 120);
            $table->text('response_text');
            $table->string('status', 12);
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('published_at');
            $table->timestampTz('edited_at')->nullable();
            $table->timestampTz('hidden_at')->nullable();
            $table->timestampsTz();

            $table->index(['location_id', 'published_at']);
        });
        DB::statement("alter table restaurant_review_responses add constraint restaurant_review_responses_status_check check (status in ('PUBLISHED', 'HIDDEN'))");

        Schema::create('restaurant_location_rating_summaries', function (Blueprint $table): void {
            $table->foreignId('location_id')->primary()->constrained('restaurant_locations')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('restaurant_organizations')->restrictOnDelete();
            $table->foreignId('market_id')->constrained('markets')->restrictOnDelete();
            $table->unsignedInteger('published_count')->default(0);
            $table->unsignedBigInteger('rating_sum')->default(0);
            $table->unsignedInteger('star_1')->default(0);
            $table->unsignedInteger('star_2')->default(0);
            $table->unsignedInteger('star_3')->default(0);
            $table->unsignedInteger('star_4')->default(0);
            $table->unsignedInteger('star_5')->default(0);
            $table->jsonb('category_totals')->default('{}');
            // The average of the published overall ratings, two decimals, null while there is none (never 0, never 5).
            $table->decimal('average_rating', 3, 2)->nullable();
            $table->timestampTz('last_published_at')->nullable();
            $table->timestampTz('recalculated_at');

            $table->index(['average_rating', 'published_count']);
            $table->index('organization_id');
        });
        DB::statement('alter table restaurant_location_rating_summaries add constraint rating_summaries_average_check check (average_rating is null or (average_rating >= 1 and average_rating <= 5))');
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_location_rating_summaries');
        Schema::dropIfExists('restaurant_review_responses');
        Schema::dropIfExists('review_reports');
        Schema::dropIfExists('review_moderation_cases');
        Schema::dropIfExists('review_revisions');
        Schema::dropIfExists('restaurant_reviews');
    }
};
