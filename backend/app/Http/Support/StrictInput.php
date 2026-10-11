<?php

namespace App\Http\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * For endpoints whose body is a closed list of fields: anything else is refused with 422 instead of being ignored.
 * A client can then never believe it set something the server decides (an owner, a status, a "verified" flag).
 */
final class StrictInput
{
    /**
     * @param  list<string>  $allowed  top-level body fields the endpoint accepts
     *
     * @throws ValidationException
     */
    public static function only(Request $request, array $allowed): void
    {
        $body = $request->isJson() ? $request->json()->all() : $request->post();
        $unknown = array_values(array_diff(array_map('strval', array_keys($body)), $allowed));
        if ($unknown === []) {
            return;
        }
        $messages = [];
        foreach (array_slice($unknown, 0, 10) as $field) {
            $messages[mb_substr($field, 0, 60)] = ['This field is not accepted: it is decided by the server or unknown.'];
        }
        throw ValidationException::withMessages($messages);
    }
}
