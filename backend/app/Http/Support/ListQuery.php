<?php

namespace App\Http\Support;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
 *
 * A filter is an exact match on the column of the same name unless the endpoint gives it a handler
 * (`$handlers`: name => fn (Builder $query, string $value)), for filters that are not a plain column —
 * a parent named by its public id, a slug of a related record, "open now".
 */
final class ListQuery
{
    /**
     * @param  list<string>  $filterable
     * @param  list<string>  $sortable
     * @param  array<string, Closure(Builder<covariant Model>, string): void>  $handlers
     */
    public function __construct(
        private readonly Request $request,
        private readonly array $filterable = [],
        private readonly array $sortable = [],
        private readonly string $defaultSort = '-created_at',
        private readonly array $handlers = [],
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
            if (isset($this->handlers[$column])) {
                ($this->handlers[$column])($query, $value);

                continue;
            }
            $query->where($column, $value);
        }

        foreach ($this->sorts() as [$column, $direction]) {
            $query->orderBy($column, $direction);
        }

        $paginator = $query->orderBy($query->getModel()->getKeyName())
            ->paginate(perPage: $this->pageSize(), pageName: 'page[number]', page: $this->pageNumber());

        // The links a client follows must speak the same convention as the request: page[number] / page[size],
        // with the filters, search and sort it sent. (The framework default would write ?page=2 and lose them.)
        $carried = $this->request->query();
        unset($carried['page']);
        $paginator->appends($carried);
        if (isset($this->page()['size'])) {
            $paginator->appends('page[size]', (string) $this->pageSize());
        }

        return $paginator;
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
            if ((! in_array($field, $this->filterable, true) && ! isset($this->handlers[$field])) || ! is_string($value)) {
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
                throw ValidationException::withMessages(['sort' => ["Sorting by [{$column}] is not supported."]]);
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
