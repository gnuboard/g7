<?php

namespace Modules\Sirsoft\Inquiry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Inquiry\Models\Inquiry;

class StoreInquiryMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $inquiry = $this->route('inquiry');
        if (! $inquiry instanceof Inquiry) {
            return false;
        }
        return $this->user()?->can('postMessage', $inquiry) ?? false;
    }

    public function rules(): array
    {
        return [
            'body' => ['required_without:attachment_ids', 'nullable', 'string', 'max:10000'],
            'attachment_ids' => ['nullable', 'array', 'max:10'],
            'attachment_ids.*' => ['integer', 'exists:inquiry_attachments,id'],
        ];
    }
}
