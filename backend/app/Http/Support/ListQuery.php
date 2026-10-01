<?php

namespace App\Http\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * One query-string convention for every collection endpoint:
 *
 *   ?filter[status]=ACTIVE&filter[country_code]=IN   exact-match filters (allow-listed per endpoint)
 *   ?q=text                                           free-text search (when the endpoint supports it)
 *   ?sort=name,-created_at                            comma list, "-" prefix = descending (allow-listed)
 *   ?page[number]=2&page[size]=25                     page pagination (size capped by config)
 *
 * Unknown filters or sort fields are rejected with 422 instead of being silently ignored.
 * Cursor pagination (`page[cursor]`) is reserved for feeds that must not skip rows (orders, events).
 */
final class ListQuery
{
    /**
     * @param  list<string>  $filterable
     * @param  list<string>  $sortable
     */
    public function __construct(
        private readonly Request $request,
        private readonly array $filterable = [],
        private readonly array $sortable = [],
        private readonly string $defaultSort = '-created_at',
    ) {}

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(Builder $query): LengthAwarePaginator
    {
        foreach ($this->filters() as $column => $value) {
            $query->where($column, $value);
        }

        foreach ($this->sorts() as [$column, $direction]) {
            $query->orderBy($column, $direction);
        }

        return $query->orderBy($query->getModel()->getKeyName())
            ->paginate(perPage: $this->pageSize(), page: $this->pageNumber())
            ->withQueryString();
    }

    /**
     * @return array<string, string>
     */
    public function filters(): array
    {
        $filters = $this->request->query('filter', []);

        if (! is_array($filters)) {
            throw ValidationException::withMessages(['filter' => ['Filters must be sent as filter[field]=value.']]);
        }

        foreach ($filters as $field => $value) {
            if (! in_array($field, $this->filterable, true) || ! is_string($value)) {
                throw ValidationException::withMessages(["filter.{$field}" => ['This filter is not supported.']]);
            }
        }

        return $filters;
    }

    /**
     * @return list<array{0: string, 1: 'asc'|'desc'}>
     */
    public function sorts(): array
    {
        $sort = $this->request->query('sort', $this->defaultSort);

        if (! is_string($sort)) {
            throw ValidationException::withMessages(['sort' => ['Sort must be a comma separated list of fields.']]);
        }

        $sorts = [];
        foreach (array_filter(explode(',', $sort)) as $field) {
            $column = ltrim($field, '-');
            if (! in_array($column, $this->sortable, true)) {
                throw ValidationException::withMessages(['sort' => [__('Sorting by [:column] is not supported.', ['column' => $column])]]);
            }
            $sorts[] = [$column, str_starts_with($field, '-') ? 'desc' : 'asc'];
        }

        return $sorts;
    }

    public function pageSize(): int
    {
        $size = (int) ($this->page()['size'] ?? 0);
        $default = (int) config('api.pagination.default_size');

        return $size < 1 ? $default : min($size, (int) config('api.pagination.max_size'));
    }

    public function pageNumber(): int
    {
        return max(1, (int) ($this->page()['number'] ?? 1));
    }

    /**
     * @return array<string, mixed>
     */
    private function page(): array
    {
        $page = $this->request->query('page', []);

        return is_array($page) ? $page : [];
    }
}
