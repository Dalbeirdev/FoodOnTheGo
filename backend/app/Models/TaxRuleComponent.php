<?php

namespace App\Models;

use App\Models\Concerns\StoresUtcTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * One component of a tax rule: a code, the label a customer reads and a rate in basis points (1 % = 100). A rule can
 * have several — a tax is never one "percent" field.
 */
class TaxRuleComponent extends Model
{
    use StoresUtcTimestamps;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['rate_basis_points' => 'integer', 'sequence' => 'integer'];
    }
}
