<?php

namespace Modules\Sirsoft\Inquiry\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Inquiry\Models\Inquiry;

class UploadInquiryAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $inquiry = $this->route('inquiry');
        if (! $inquiry instanceof Inquiry) {
            return false;
        }
        return $this->user()?->can('uploadAttachment', $inquiry) ?? false;
    }

    public function rules(): array
    {
        $context = $this->route('inquiryMessage') ? 'message' : 'inquiry';
        $maxBytes = (int) config(
            $context === 'message'
                ? 'inquiry.attachment.max_size_message'
                : 'inquiry.attachment.max_size_inquiry'
        );

        return [
            'file' => [
                'required',
                'file',
                'max:' . (int) ($maxBytes / 1024), // Laravel expects KB
            ],
        ];
    }
}
