<?php

namespace Modules\Sirsoft\Inquiry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Inquiry\Models\Inquiry;

class UpdateInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $inquiry = $this->route('inquiry');
        if (! $inquiry instanceof Inquiry) {
            return false;
        }
        return $this->user()?->can('update', $inquiry) ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'content' => ['sometimes', 'required', 'string'],
            'category' => ['nullable', 'string', 'in:' . implode(',', config('inquiry.categories', []))],
            'budget_range' => ['nullable', 'string', 'max:100'],
            'desired_due_at' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }
}
