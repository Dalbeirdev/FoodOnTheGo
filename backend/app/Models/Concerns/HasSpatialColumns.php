<?php

namespace App\Models\Concerns;

use App\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persistence for models with PostGIS columns. Spatial values are written through SQL expressions whose
 * every user-supplied part is a bound parameter — coordinates and GeoJSON are never concatenated into SQL.
 *
 * Reading: the raw column (WKB) is hidden; models expose derived values (latitude / longitude, GeoJSON)
 * through select expressions added by their scopes.
 */
trait HasSpatialColumns
{
    /**
     * Default columns for a model whose spatial column is never selected raw: the given plain columns plus
     * derived expressions. Columns a query added itself (a distance, the GeoJSON) are kept — the column
     * list is set directly because select() would discard them together with their bindings.
     *
     * @param  Builder<covariant Model>  $query
     * @param  list<string>  $columns
     */
    protected static function selectSummary(Builder $query, array $columns, string $derived): void
    {
        $base = $query->getQuery();
        $base->columns = [...$columns, new Expression($derived), ...($base->columns ?? [])];
    }

    /**
     * Inserts the model together with its spatial columns in one statement (they are NOT NULL).
     *
     * @param  array<string, array{0: string, 1: list<mixed>}>  $spatial  column => [SQL expression with ?, bindings]
     */
    public function insertWithSpatial(array $spatial): static
    {
        $this->public_id ??= (string) Str::uuid();
        $this->created_at = $this->updated_at = $this->freshTimestamp();

        $attributes = $this->getAttributes();
        $columns = [...array_keys($attributes), ...array_keys($spatial)];
        $placeholders = [...array_fill(0, count($attributes), '?'), ...array_map(fn (array $s): string => $s[0], array_values($spatial))];
        $bindings = [...array_values($attributes), ...array_merge(...array_map(fn (array $s): array => $s[1], array_values($spatial)))];

        DB::insert(sprintf('insert into %s (%s) values (%s)', $this->getTable(), implode(', ', $columns), implode(', ', $placeholders)), $bindings);

        return static::query()->where('public_id', $this->public_id)->firstOrFail();
    }

    /**
     * @param  array{0: string, 1: list<mixed>}  $expression
     */
    public function updateSpatial(string $column, array $expression): void
    {
        DB::update(sprintf('update %s set %s = %s where id = ?', $this->getTable(), $column, $expression[0]), [...$expression[1], $this->getKey()]);
    }

    /**
     * Optimistic concurrency: the caller states the version it edited. A different current version means
     * somebody else saved in between — nothing is written and the caller must reload.
     */
    public function assertVersion(int $expected): void
    {
        if ((int) $this->version !== $expected) {
            throw ApiException::conflict('stale_update', 'This record was changed by someone else. Reload it and try again.', ['current_version' => (int) $this->version]);
        }
    }
}
