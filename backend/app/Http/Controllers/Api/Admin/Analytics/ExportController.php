<?php

namespace App\Http\Controllers\Api\Admin\Analytics;

use App\Enums\Permission;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Admin\Analytics\Concerns\AnalyticsScoping;
use App\Http\Controllers\Controller;
use App\Http\Support\StrictInput;
use App\Models\Market;
use App\Models\ReportExport;
use App\Services\Analytics\AnalyticsMetrics;
use App\Services\Analytics\AnalyticsPeriod;
use App\Services\Analytics\Reports\ReportExportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Report exports for administrators (Module 38): ask for one of the curated reports, watch it being prepared,
 * download it while it exists.
 *
 * `admin.analytics.export` for the markets of the report — and, for a payments, refunds, support or finance report,
 * that domain's own permission as well (a financial report needs `admin.finance.export`). An export belongs to the
 * administrator who asked: nobody else can see that it exists. The download is checked again at the moment it is
 * made — losing the permission ends the access to a file that was already built.
 */
class ExportController extends Controller
{
    use AnalyticsScoping;

    private const BASE = '/api/v1/admin/analytics/exports';

    public function __construct(private readonly ReportExportService $exports, private readonly AnalyticsMetrics $metrics) {}

    public function index(Request $request): JsonResponse
    {
        $this->marketsWith($request, Permission::AdminAnalyticsExport);
        $exports = ReportExport::query()->where('requested_by_admin_id', $this->admin($request)->getKey())->orderByDesc('id')->limit(50)->get();

        return response()->json([
            'data' => $exports->map(fn (ReportExport $e): array => ReportExportService::present($e, self::BASE))->all(),
            'reports' => array_values(array_map(fn (string $key, array $r): array => ['key' => $key, 'label' => (string) $r['label'], 'requires' => [Permission::AdminAnalyticsExport->value, ...$r['admin_permissions']]], array_keys($this->reports()), array_values($this->reports()))),
            'expiry_hours' => (int) config('analytics.exports.expiry_hours'), 'max_rows' => (int) config('analytics.exports.max_rows'), 'format' => 'CSV',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        StrictInput::only($request, ['report', 'market', 'restaurant', 'preset', 'from', 'to']);
        $input = $request->validate(['report' => ['required', 'string', Rule::in(array_keys($this->reports()))]]);
        $report = $this->reports()[$input['report']];
        [$scope, $markets] = $this->scope($request, in_array($input['report'], ['orders', 'restaurant_performance'], true), Permission::AdminAnalyticsExport);
        foreach ((array) $report['admin_permissions'] as $code) {
            foreach ($markets as $market) {
                $this->authorizeFor(Permission::from((string) $code), $market);
            }
            if ($scope->includesUnassigned) {
                $this->authorizeFor(Permission::from((string) $code), null);
            }
        }
        $period = AnalyticsPeriod::fromInput($request->validate(['preset' => ['sometimes', 'string', 'max:20'], 'from' => ['sometimes', 'string', 'max:10'], 'to' => ['sometimes', 'string', 'max:10']]), $scope->timezone, (int) config('analytics.exports.max_range_days'));
        $export = $this->exports->ask(ReportExport::ADMIN, $this->admin($request), (string) $input['report'], $period, $scope, count($markets) === 1 ? (int) $markets[0]->getKey() : null, null);

        return response()->json(['export' => ReportExportService::present($export, self::BASE)], 202);
    }

    public function show(Request $request, string $export): JsonResponse
    {
        return response()->json(['export' => ReportExportService::present($this->own($request, $export), self::BASE)]);
    }

    public function download(Request $request, string $export): StreamedResponse
    {
        $record = $this->own($request, $export);
        // Checked again now: the permission for every market of the export, and the report's own permissions.
        $filters = (array) $record->filters;
        $markets = Market::query()->whereIn('id', array_map('intval', (array) ($filters['market_ids'] ?? [])) ?: [0])->get();
        $needed = [Permission::AdminAnalyticsExport, ...array_map(fn ($code): Permission => Permission::from((string) $code), (array) (config('analytics.exports.reports.'.$record->report_type.'.admin_permissions') ?? []))];
        foreach ($needed as $permission) {
            $everywhere = ($filters['includes_unassigned'] ?? false) && ! $this->allowed($request, $permission, null);
            if ($everywhere || $markets->contains(fn (Market $market): bool => ! $this->allowed($request, $permission, $market))) {
                $this->metrics->record('export_refused');
                throw new AuthorizationException;
            }
        }

        return $this->exports->download($record, $this->admin($request));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function reports(): array
    {
        return array_filter((array) config('analytics.exports.reports'), fn (array $r): bool => in_array(ReportExport::ADMIN, (array) $r['audiences'], true));
    }

    private function own(Request $request, string $publicId): ReportExport
    {
        $export = ReportExport::query()->where('public_id', Str::isUuid($publicId) ? $publicId : null)->first();
        // Another administrator's export does not exist for this caller.
        if ($export === null || ! ReportExportService::belongsTo($export, $this->admin($request))) {
            $this->metrics->record('export_refused');
            throw ApiException::notFound('export_not_found', 'This export does not exist.');
        }

        return $export;
    }
}
