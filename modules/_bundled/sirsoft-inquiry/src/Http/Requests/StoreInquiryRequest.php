<?php

namespace Modules\Sirsoft\Inquiry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null; // 회원만 (v1 spec)
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'content' => ['required', 'string'],
            'category' => ['nullable', 'string', 'in:' . implode(',', config('inquiry.categories', []))],
            'budget_range' => ['nullable', 'string', 'max:100'],
            'desired_due_at' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }
}
