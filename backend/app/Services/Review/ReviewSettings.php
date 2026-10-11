<?php

namespace App\Services\Review;

/**
 * The review policy as the code reads it (config/reviews.php) — one place, so no service carries a number of its own.
 */
final class ReviewSettings
{
    public function min(): int
    {
        return (int) config('reviews.scale.min', 1);
    }

    public function max(): int
    {
        return (int) config('reviews.scale.max', 5);
    }

    public function windowDays(): int
    {
        return max(1, (int) config('reviews.submission_window_days', 30));
    }

    public function editWindowHours(): int
    {
        return max(0, (int) config('reviews.edit_window_hours', 72));
    }

    public function maxEdits(): int
    {
        return max(0, (int) config('reviews.max_edits', 3));
    }

    public function titleMax(): int
    {
        return (int) config('reviews.title_max', 120);
    }

    public function commentMax(): int
    {
        return (int) config('reviews.comment_max', 500);
    }

    public function responseMax(): int
    {
        return (int) config('reviews.response_max', 600);
    }

    /**
     * @return array<string, array{required: bool, group: string}>
     */
    public function categories(): array
    {
        $out = [];
        foreach ((array) config('reviews.categories', []) as $key => $row) {
            $out[(string) $key] = ['required' => (bool) ($row['required'] ?? false), 'group' => (string) ($row['group'] ?? 'service')];
        }

        return $out;
    }

    /**
     * @return array<string, string> code => sentiment
     */
    public function tags(): array
    {
        return array_map('strval', (array) config('reviews.tags', []));
    }

    public function maxTags(): int
    {
        return (int) config('reviews.max_tags', 6);
    }

    public function itemFeedback(): bool
    {
        return (bool) config('reviews.item_feedback', true);
    }

    /**
     * What a client needs to draw the form. Policy only — nothing about a customer or an order.
     *
     * @return array<string, mixed>
     */
    public function forClients(): array
    {
        return [
            'scale' => ['min' => $this->min(), 'max' => $this->max()],
            'title_max_length' => $this->titleMax(),
            'comment_max_length' => $this->commentMax(),
            'categories' => array_map(fn (string $key, array $row): array => ['key' => $key, 'required' => $row['required'], 'group' => $row['group']], array_keys($this->categories()), array_values($this->categories())),
            'tags' => array_map(fn (string $key, string $sentiment): array => ['key' => $key, 'sentiment' => $sentiment], array_keys($this->tags()), array_values($this->tags())),
            'max_tags' => $this->maxTags(),
            'item_feedback' => $this->itemFeedback(),
            'images' => false,
            'submission_window_days' => $this->windowDays(),
            'edit_window_hours' => $this->editWindowHours(),
            'max_edits' => $this->maxEdits(),
            'policy_status' => 'DEVELOPMENT_DEFAULT_PENDING_BUSINESS_APPROVAL',
        ];
    }
}
