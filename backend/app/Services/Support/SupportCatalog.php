<?php

namespace App\Services\Support;

use App\Enums\SupportCasePriority;
use App\Enums\SupportRequesterType;
use App\Enums\SupportTeam;
use App\Exceptions\ApiException;
use App\Models\SupportCase;

/**
 * The taxonomy of support cases (Module 37), read from config/support.php — the one place categories, their
 * subcategories, their queue, their starting priority and who may use them are defined. Clients read it through the
 * catalog endpoints and carry no list of their own.
 */
final class SupportCatalog
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function categories(): array
    {
        return (array) config('support.categories');
    }

    public function has(string $category): bool
    {
        return array_key_exists($category, $this->categories());
    }

    /**
     * The category as the requester may use it — or a validation error naming the field.
     *
     * @return array{category: string, subcategory: string|null, team: SupportTeam, priority: SupportCasePriority, classification: string, restricted_class: string|null, restaurant_permission: string|null}
     */
    public function resolve(SupportRequesterType $requester, string $category, ?string $subcategory): array
    {
        $category = strtoupper(trim($category));
        $definition = $this->categories()[$category] ?? null;
        if ($definition === null || ! in_array($requester->value, (array) $definition['audiences'], true)) {
            throw new ApiException(422, 'validation_failed', 'This category cannot be used here.', ['fields' => ['category' => ['Choose one of the categories offered.']]]);
        }
        $sub = null;
        $priority = (string) $definition['priority'];
        $class = $definition['restricted'] ?? null;
        if ($subcategory !== null && trim($subcategory) !== '') {
            $sub = strtoupper(trim($subcategory));
            $subDefinition = ((array) $definition['subcategories'])[$sub] ?? null;
            if ($subDefinition === null || ! in_array($requester->value, (array) ($subDefinition['audiences'] ?? $definition['audiences']), true)) {
                throw new ApiException(422, 'validation_failed', 'This kind of issue cannot be used here.', ['fields' => ['subcategory' => ['Choose one of the issues offered for this category.']]]);
            }
            $priority = (string) ($subDefinition['priority'] ?? $priority);
            $class = $subDefinition['restricted'] ?? $class;
        }
        $team = $definition['team'] ?? ((array) config('support.routing.by_requester'))[$requester->value] ?? SupportTeam::CustomerSupport->value;

        return [
            'category' => $category,
            'subcategory' => $sub,
            'team' => SupportTeam::from((string) $team),
            'priority' => SupportCasePriority::from($priority),
            'classification' => $class === null ? SupportCase::STANDARD : SupportCase::RESTRICTED,
            'restricted_class' => $class,
            'restaurant_permission' => $definition['restaurant_permission'] ?? null,
        ];
    }

    /**
     * What an audience is offered: codes only (labels are client strings, "support.category.<code>").
     *
     * @return list<array{code: string, subcategories: list<string>, restaurant_permission: string|null}>
     */
    public function forAudience(SupportRequesterType $requester): array
    {
        $out = [];
        foreach ($this->categories() as $code => $definition) {
            if (! in_array($requester->value, (array) $definition['audiences'], true)) {
                continue;
            }
            $subs = [];
            foreach ((array) $definition['subcategories'] as $sub => $subDefinition) {
                if (in_array($requester->value, (array) ($subDefinition['audiences'] ?? $definition['audiences']), true)) {
                    $subs[] = (string) $sub;
                }
            }
            $out[] = ['code' => (string) $code, 'subcategories' => $subs, 'restaurant_permission' => $definition['restaurant_permission'] ?? null];
        }

        return $out;
    }

    /** The extra permission a restaurant member needs for a category (finance categories), if any. */
    public function restaurantPermission(string $category): ?string
    {
        return ($this->categories()[$category] ?? [])['restaurant_permission'] ?? null;
    }

    /**
     * @return list<string>
     */
    public function restaurantRestrictedCategories(): array
    {
        return array_keys(array_filter($this->categories(), fn (array $d): bool => ($d['restaurant_permission'] ?? null) !== null));
    }
}
