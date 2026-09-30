<?php

namespace App\Http\Requests\Auth;

use App\Models\Market;
use App\Services\Market\MarketContext;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class OtpRequestRequest extends FormRequest
{
    private ?Market $market = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:32'],
            'country' => ['sometimes', 'string', 'regex:/^[A-Z]{2}$/'],
        ];
    }

    /**
     * The market the number is interpreted in: ?country, or the default market. It must serve customers.
     */
    public function market(): Market
    {
        return $this->market ??= app(MarketContext::class)->current($this->validated('country'));
    }

    /**
     * The normalised number; a number that is not valid for the market is a field error on "phone".
     */
    public function phone(): PhoneNumber
    {
        try {
            return PhoneNumber::parse((string) $this->validated('phone'), $this->market());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['phone' => [$e->getMessage()]]);
        }
    }
}
