<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SignatureRequest extends FormRequest
{
    /**
     * authorize
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

	/**

	 * rules

 *

 * @return array

	 */

    public function rules(): array
    {
        return [
            'oid' => ['required', 'string', 'max:40'],
            'price' => ['required', 'integer', 'min:100'],
            'timestamp' => ['required', 'string', 'max:20'],
            'buyer_email' => ['nullable', 'string', 'max:255'],
            'buyer_phone' => ['nullable', 'string', 'max:30'],
        ];
    }
}
