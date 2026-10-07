<?php

namespace App\Services\Checkout;

use App\Auth\Principal;
use App\Enums\FinancialRuleStatus;
use App\Enums\PromotionScope;
use App\Enums\PromotionStatus;
use App\Exceptions\ApiException;
use App\Models\FeeRule;
use App\Models\Market;
use App\Models\Promotion;
use App\Models\RestaurantLocation;
use App\Models\RestaurantOrganization;
use App\Models\TaxRule;
use App\Models\TaxRuleComponent;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What administrators may do to the money rules (Module 29): promotions, tax rules and fee rules. Every change is
 * versioned (the caller states the version it edited), needs a reason, and is written to the audit log with what
 * changed — these rules decide what customers pay.
 *
 *  - PROMOTIONS are created as DRAFT. While a promotion is live (ACTIVE or SCHEDULED) its discount, scope, currency
 *    and start cannot be rewritten: it is paused first — so a rule customers are using is never changed silently.
 *    DISABLED is final. A checkout keeps the code and the discount it was given; when its promotion changes it goes
 *    stale and is priced again.
 *  - TAX and FEE RULES are written while DRAFT and frozen once ACTIVE: to change a rate the rule is ended (or
 *    disabled) and a new one created. A checkout and, later, an order can always name the exact rule they were
 *    priced with, and a historical amount is never recalculated with a newer rule.
 *  - A rule is TEST (a development configuration) unless an administrator marks it APPROVED; nothing here knows or
 *    invents an official rate of any country.
 */
final class FinancialAdminService
{
    /** What may change while customers can be using a promotion. */
    private const PROMOTION_LIVE_EDITABLE = ['name', 'description', 'ends_at', 'usage_limit', 'per_customer_limit'];

    private const PROMOTION_FIELDS = ['code', 'name', 'description', 'type', 'currency', 'amount_minor', 'percent_basis_points', 'maximum_discount_minor', 'minimum_spend_minor', 'starts_at', 'ends_at', 'usage_limit', 'per_customer_limit', 'funding_source'];

    private const TAX_FIELDS = ['name', 'applies_to', 'price_treatment', 'discount_treatment', 'approval', 'effective_from', 'effective_until'];

    private const FEE_FIELDS = ['code', 'label', 'type', 'currency', 'amount_minor', 'percent_basis_points', 'percent_of', 'minimum_minor', 'maximum_minor', 'pickup_method', 'taxable', 'approval', 'effective_from', 'effective_until'];

    public function __construct(private readonly AuditRecorder $audit) {}

    /* ------------------------------------------------------------------ promotions */

    /**
     * @param  array<string, mixed>  $input  validated; scope targets as models under `market`, `organization`, `location`
     */
    public function createPromotion(array $input, Principal $actor): Promotion
    {
        return DB::transaction(function () use ($input, $actor): Promotion {
            $promotion = new Promotion;
            $promotion->forceFill(['status' => PromotionStatus::Draft->value, 'created_by_admin_id' => $actor instanceof Model ? $actor->getKey() : null, ...$this->promotionValues($input), ...$this->promotionScope($input)]);
            $this->assertPromotionCoherent($promotion);
            try {
                $promotion->save();
            } catch (UniqueConstraintViolationException) {
                throw ApiException::conflict('promotion_code_taken', 'Another promotion already uses this code.');
            }
            $this->audit->record('promotion.created', $promotion, $actor, ['promotion_code' => ['from' => null, 'to' => $promotion->code], 'status' => ['from' => null, 'to' => 'DRAFT']], $input['reason'] ?? null, $promotion->market_id);

            return $promotion->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updatePromotion(Promotion $promotion, array $input, Principal $actor): Promotion
    {
        return DB::transaction(function () use ($promotion, $input, $actor): Promotion {
            $locked = Promotion::query()->whereKey($promotion->getKey())->lockForUpdate()->firstOrFail();
            $this->assertVersion($locked, (int) $input['version']);
            if ($locked->status === PromotionStatus::Disabled) {
                throw ApiException::conflict('promotion_disabled', 'A disabled promotion cannot be changed. Create a new one.');
            }

            $values = [...$this->promotionValues($input), ...(array_intersect_key($input, array_flip(['scope', 'market', 'organization', 'location'])) === [] ? [] : $this->promotionScope([...['scope' => $locked->scope->value], ...$input]))];
            $live = in_array($locked->effectiveStatus(), [PromotionStatus::Active, PromotionStatus::Scheduled], true);
            $changes = [];
            foreach ($values as $field => $value) {
                $before = self::comparable($field, $locked->getAttribute($field));
                $after = self::comparable($field, $value);
                if ($before === $after) {
                    continue;
                }
                if ($live && ! in_array($field, self::PROMOTION_LIVE_EDITABLE, true)) {
                    throw ApiException::conflict('promotion_live_edit_refused', 'This promotion is in use. Pause it before changing its discount, scope, currency or start.', ['field' => $field]);
                }
                $changes[$field] = ['from' => $before, 'to' => $after];
                $locked->setAttribute($field, $value);
            }
            if ($changes === []) {
                return $locked;
            }
            $this->assertPromotionCoherent($locked);
            $locked->setAttribute('version', (int) $locked->version + 1);
            try {
                $locked->save();
            } catch (UniqueConstraintViolationException) {
                throw ApiException::conflict('promotion_code_taken', 'Another promotion already uses this code.');
            }
            $this->audit->record('promotion.updated', $locked, $actor, self::auditable($changes, 'promotion_code'), (string) $input['reason'], $locked->market_id);

            return $locked->refresh();
        });
    }

    /**
     * @param  array{status: string, version: int, reason: string}  $input
     */
    public function setPromotionStatus(Promotion $promotion, array $input, Principal $actor): Promotion
    {
        return DB::transaction(function () use ($promotion, $input, $actor): Promotion {
            $locked = Promotion::query()->whereKey($promotion->getKey())->lockForUpdate()->firstOrFail();
            $this->assertVersion($locked, (int) $input['version']);
            $target = PromotionStatus::from($input['status']);
            if ($locked->status === $target) {
                return $locked;
            }
            if (! in_array($target, PromotionStatus::STORED, true) || ! $locked->status->mayBecome($target)) {
                throw ApiException::conflict('invalid_status_transition', "A promotion cannot go from {$locked->status->value} to {$target->value}.", ['from' => $locked->status->value]);
            }
            $from = $locked->status->value;
            $locked->forceFill(['status' => $target->value, 'version' => (int) $locked->version + 1])->save();
            $this->audit->record('promotion.status_changed', $locked, $actor, ['status' => ['from' => $from, 'to' => $target->value]], (string) $input['reason'], $locked->market_id);

            return $locked->refresh();
        });
    }

    /* ------------------------------------------------------------------ tax rules */

    /**
     * @param  array<string, mixed>  $input  validated; `location` as a model or absent; `components` as a list
     */
    public function createTaxRule(Market $market, array $input, Principal $actor): TaxRule
    {
        return DB::transaction(function () use ($market, $input, $actor): TaxRule {
            $rule = new TaxRule;
            $rule->forceFill([
                'market_id' => $market->getKey(),
                'location_id' => ($input['location'] ?? null)?->getKey(),
                'status' => FinancialRuleStatus::Draft->value,
                'created_by_admin_id' => $actor instanceof Model ? $actor->getKey() : null,
                ...array_intersect_key($input, array_flip(self::TAX_FIELDS)),
            ]);
            $this->assertRuleCoherent($rule);
            $rule->save();
            $this->writeComponents($rule, (array) $input['components']);
            $this->audit->record('tax_rule.created', $rule, $actor, ['name' => ['from' => null, 'to' => $rule->name], 'components' => ['from' => null, 'to' => $this->componentSummary($rule)]], (string) $input['reason'], $market->getKey());

            return $rule->refresh()->load('components');
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateTaxRule(TaxRule $rule, array $input, Principal $actor): TaxRule
    {
        return DB::transaction(function () use ($rule, $input, $actor): TaxRule {
            $locked = TaxRule::query()->whereKey($rule->getKey())->lockForUpdate()->firstOrFail();
            $this->assertVersion($locked, (int) $input['version']);
            $changes = $this->applyRuleChanges($locked, $input, self::TAX_FIELDS);
            if (array_key_exists('components', $input)) {
                $this->assertDraft($locked, 'components');
                $before = $this->componentSummary($locked);
                $this->writeComponents($locked, (array) $input['components']);
                $after = $this->componentSummary($locked->unsetRelation('components'));
                if ($before !== $after) {
                    $changes['components'] = ['from' => $before, 'to' => $after];
                }
            }
            if ($changes === []) {
                return $locked->load('components');
            }
            $locked->forceFill(['version' => (int) $locked->version + 1])->save();
            $this->audit->record('tax_rule.updated', $locked, $actor, $changes, (string) $input['reason'], $locked->market_id);

            return $locked->refresh()->load('components');
        });
    }

    /* ------------------------------------------------------------------ fee rules */

    /**
     * @param  array<string, mixed>  $input
     */
    public function createFeeRule(Market $market, array $input, Principal $actor): FeeRule
    {
        return DB::transaction(function () use ($market, $input, $actor): FeeRule {
            $rule = new FeeRule;
            $rule->forceFill([
                'market_id' => $market->getKey(),
                'location_id' => ($input['location'] ?? null)?->getKey(),
                'status' => FinancialRuleStatus::Draft->value,
                'created_by_admin_id' => $actor instanceof Model ? $actor->getKey() : null,
                ...array_intersect_key($input, array_flip(self::FEE_FIELDS)),
            ]);
            $this->assertRuleCoherent($rule);
            $rule->save();
            $this->audit->record('fee_rule.created', $rule, $actor, ['fee_code' => ['from' => null, 'to' => $rule->code], 'type' => ['from' => null, 'to' => $rule->type]], (string) $input['reason'], $market->getKey());

            return $rule->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateFeeRule(FeeRule $rule, array $input, Principal $actor): FeeRule
    {
        return DB::transaction(function () use ($rule, $input, $actor): FeeRule {
            $locked = FeeRule::query()->whereKey($rule->getKey())->lockForUpdate()->firstOrFail();
            $this->assertVersion($locked, (int) $input['version']);
            $changes = $this->applyRuleChanges($locked, $input, self::FEE_FIELDS);
            if ($changes === []) {
                return $locked;
            }
            $locked->forceFill(['version' => (int) $locked->version + 1])->save();
            $this->audit->record('fee_rule.updated', $locked, $actor, self::auditable($changes, 'fee_code'), (string) $input['reason'], $locked->market_id);

            return $locked->refresh();
        });
    }

    /**
     * DRAFT → ACTIVE, DRAFT / ACTIVE → DISABLED — for a tax rule or a fee rule.
     *
     * @template T of TaxRule|FeeRule
     *
     * @param  T  $rule
     * @param  array{status: string, version: int, reason: string}  $input
     * @return T
     */
    public function setRuleStatus(TaxRule|FeeRule $rule, array $input, Principal $actor): TaxRule|FeeRule
    {
        return DB::transaction(function () use ($rule, $input, $actor): TaxRule|FeeRule {
            $locked = $rule->newQuery()->whereKey($rule->getKey())->lockForUpdate()->firstOrFail();
            $this->assertVersion($locked, (int) $input['version']);
            $target = FinancialRuleStatus::from($input['status']);
            if ($locked->status === $target) {
                return $locked;
            }
            if (! $locked->status->mayBecome($target)) {
                throw ApiException::conflict('invalid_status_transition', "A rule cannot go from {$locked->status->value} to {$target->value}.", ['from' => $locked->status->value]);
            }
            if ($target === FinancialRuleStatus::Active && $locked instanceof TaxRule && ! $locked->components()->exists()) {
                throw ApiException::conflict('rule_incomplete', 'A tax rule needs at least one component before it can be activated.');
            }
            $from = $locked->status->value;
            $locked->forceFill(['status' => $target->value, 'version' => (int) $locked->version + 1])->save();
            $this->audit->record(($locked instanceof TaxRule ? 'tax_rule' : 'fee_rule').'.status_changed', $locked, $actor, ['status' => ['from' => $from, 'to' => $target->value]], (string) $input['reason'], $locked->market_id);

            return $locked->refresh();
        });
    }

    /* ------------------------------------------------------------------ internals */

    /**
     * A frozen rule accepts only its end date; a DRAFT accepts everything.
     *
     * @param  list<string>  $fields
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function applyRuleChanges(TaxRule|FeeRule $rule, array $input, array $fields): array
    {
        $changes = [];
        foreach (array_intersect_key($input, array_flip([...$fields, 'location'])) as $field => $value) {
            if ($field === 'location') {
                $field = 'location_id';
                $value = $value?->getKey();
            }
            $before = self::comparable($field, $rule->getAttribute($field));
            $after = self::comparable($field, $value);
            if ($before === $after) {
                continue;
            }
            if ($field !== 'effective_until') {
                $this->assertDraft($rule, $field);
            } elseif ($rule->status === FinancialRuleStatus::Disabled) {
                throw ApiException::conflict('rule_frozen', 'A disabled rule cannot be changed.', ['field' => $field]);
            }
            $changes[$field] = ['from' => $before, 'to' => $after];
            $rule->setAttribute($field, $value);
        }
        if ($changes !== []) {
            $this->assertRuleCoherent($rule);
            $rule->save();
        }

        return $changes;
    }

    /**
     * The same rules the database enforces, said in words before it has to — for a new rule and for every change.
     */
    private function assertRuleCoherent(TaxRule|FeeRule $rule): void
    {
        $errors = [];
        if ($rule->effective_until !== null && ! CarbonImmutable::instance($rule->effective_until)->greaterThan($rule->effective_from)) {
            $errors['effective_until'] = ['The end must be after the start.'];
        }
        if ($rule instanceof FeeRule) {
            $code = (string) $rule->code;
            if (str_contains($code, 'DELIVERY')) {
                $errors['code'] = ['FoodOnTheGo is a pickup product: there is no delivery fee.'];
            } elseif (str_contains($code, 'COMMISSION')) {
                $errors['code'] = ['A restaurant commission is a settlement matter, never a customer fee.'];
            }
            if ($rule->type === FeeRule::TYPE_FIXED) {
                if ((int) $rule->amount_minor <= 0 || $rule->currency === null || $rule->percent_basis_points !== null) {
                    $errors['amount_minor'] = ['A fixed fee needs an amount and its currency, and no percentage.'];
                }
            } elseif ((int) $rule->percent_basis_points < 1 || (int) $rule->percent_basis_points > 10000 || $rule->amount_minor !== null) {
                $errors['percent_basis_points'] = ['A percentage fee needs its rate in basis points (1 to 10000), and no fixed amount.'];
            }
            if (($rule->minimum_minor !== null || $rule->maximum_minor !== null) && $rule->currency === null) {
                $errors['currency'] = ['A minimum or maximum needs its currency.'];
            }
            if ($rule->minimum_minor !== null && $rule->maximum_minor !== null && (int) $rule->maximum_minor < (int) $rule->minimum_minor) {
                $errors['maximum_minor'] = ['The maximum cannot be below the minimum.'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertDraft(TaxRule|FeeRule $rule, string $field): void
    {
        if ($rule->status !== FinancialRuleStatus::Draft) {
            throw ApiException::conflict('rule_frozen', 'An active rule cannot be rewritten. End or disable it and create a new rule.', ['field' => $field, 'status' => $rule->status->value]);
        }
    }

    /**
     * @param  list<array{code: string, label: string, rate_basis_points: int}>  $components
     */
    private function writeComponents(TaxRule $rule, array $components): void
    {
        TaxRuleComponent::query()->where('tax_rule_id', $rule->getKey())->delete();
        foreach (array_values($components) as $i => $component) {
            (new TaxRuleComponent)->forceFill(['tax_rule_id' => $rule->getKey(), 'code' => strtoupper((string) $component['code']), 'label' => (string) $component['label'], 'rate_basis_points' => (int) $component['rate_basis_points'], 'sequence' => $i])->save();
        }
    }

    /**
     * @return list<string>
     */
    private function componentSummary(TaxRule $rule): array
    {
        return $rule->components()->get()->map(fn (TaxRuleComponent $c): string => $c->code.':'.$c->rate_basis_points)->all();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function promotionValues(array $input): array
    {
        $values = array_intersect_key($input, array_flip(self::PROMOTION_FIELDS));
        if (isset($values['code'])) {
            $values['code'] = Promotion::normalizeCode((string) $values['code']);
        }
        if (isset($values['currency'])) {
            $values['currency'] = strtoupper((string) $values['currency']);
        }

        return $values;
    }

    /**
     * The scope and exactly the targets it needs. A restaurant scope keeps its market too, so that market-scoped
     * administrators see and manage it.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function promotionScope(array $input): array
    {
        $scope = PromotionScope::from((string) $input['scope']);
        /** @var Market|null $market */
        $market = $input['market'] ?? null;
        /** @var RestaurantOrganization|null $organization */
        $organization = $input['organization'] ?? null;
        /** @var RestaurantLocation|null $location */
        $location = $input['location'] ?? null;

        return match ($scope) {
            PromotionScope::Platform => ['scope' => $scope->value, 'market_id' => null, 'organization_id' => null, 'location_id' => null],
            PromotionScope::Market => ['scope' => $scope->value, 'market_id' => ($market ?? throw $this->missing('market_id'))->getKey(), 'organization_id' => null, 'location_id' => null],
            PromotionScope::RestaurantOrganization => ['scope' => $scope->value, 'market_id' => ($organization ?? throw $this->missing('organization_id'))->primary_market_id, 'organization_id' => $organization->getKey(), 'location_id' => null],
            PromotionScope::RestaurantLocation => ['scope' => $scope->value, 'market_id' => ($location ?? throw $this->missing('location_id'))->market_id, 'organization_id' => $location->organization_id, 'location_id' => $location->getKey()],
        };
    }

    /**
     * The same rules the database enforces, said in words before it has to.
     */
    private function assertPromotionCoherent(Promotion $promotion): void
    {
        $type = $promotion->type instanceof \BackedEnum ? $promotion->type->value : (string) $promotion->type;
        $errors = [];
        if ($type === 'FIXED_AMOUNT') {
            if ((int) $promotion->amount_minor <= 0) {
                $errors['amount_minor'] = ['A fixed-amount promotion needs an amount.'];
            }
            if ($promotion->percent_basis_points !== null || $promotion->maximum_discount_minor !== null) {
                $errors['percent_basis_points'] = ['A fixed-amount promotion has no percentage and no maximum discount.'];
            }
        } else {
            if ((int) $promotion->percent_basis_points < 1 || (int) $promotion->percent_basis_points > 10000) {
                $errors['percent_basis_points'] = ['A percentage is between 1 and 10000 basis points (0.01 % and 100 %).'];
            }
            if ($promotion->amount_minor !== null) {
                $errors['amount_minor'] = ['A percentage promotion has no fixed amount.'];
            }
        }
        if (($promotion->amount_minor !== null || $promotion->maximum_discount_minor !== null || $promotion->minimum_spend_minor !== null) && $promotion->currency === null) {
            $errors['currency'] = ['A promotion with amounts needs their currency.'];
        }
        if ($promotion->starts_at !== null && $promotion->ends_at !== null && ! CarbonImmutable::instance($promotion->ends_at)->greaterThan($promotion->starts_at)) {
            $errors['ends_at'] = ['The end must be after the start.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function missing(string $field): ValidationException
    {
        return ValidationException::withMessages([$field => ['This scope needs it.']]);
    }

    /**
     * A value in the form two states of a record are compared in: an enum by its value, an instant as UTC — so the
     * same moment written with another offset or precision is not taken for a change.
     */
    private static function comparable(string $field, mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc()->toIso8601String();
        }
        if (is_string($value) && in_array($field, ['starts_at', 'ends_at', 'effective_from', 'effective_until'], true)) {
            return CarbonImmutable::parse($value)->utc()->toIso8601String();
        }

        return $value;
    }

    /**
     * The audit log redacts every value stored under the key "code" (one-time codes). A promotion code and a fee
     * code are not secrets and must stay readable in the trail, so they are recorded under a name of their own.
     *
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private static function auditable(array $changes, string $codeKey): array
    {
        if (array_key_exists('code', $changes)) {
            $changes = [$codeKey => $changes['code'], ...array_diff_key($changes, ['code' => true])];
        }

        return $changes;
    }

    private function assertVersion(Model $record, int $expected): void
    {
        if ((int) $record->getAttribute('version') !== $expected) {
            throw ApiException::conflict('stale_update', 'This record was changed by someone else. Reload it and try again.', ['current_version' => (int) $record->getAttribute('version')]);
        }
    }
}
