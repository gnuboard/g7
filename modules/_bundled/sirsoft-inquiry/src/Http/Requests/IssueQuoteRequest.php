<?php

namespace Modules\Sirsoft\Inquiry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IssueQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(config('inquiry.permissions.manage', 'inquiry.manage')) ?? false;
    }

    public function rules(): array
    {
        return [
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'valid_until' => ['nullable', 'date', 'after:today'],
            'note' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.name' => ['required', 'string', 'max:200'],
            'items.*.description' => ['nullable', 'string'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
