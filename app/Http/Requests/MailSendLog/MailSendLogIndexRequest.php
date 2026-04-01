<?php

namespace App\Http\Requests\MailSendLog;

use App\Enums\ExtensionOwnerType;
use App\Enums\MailSendStatus;
use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 메일 발송 이력 목록 조회 요청을 검증합니다.
 */
class MailSendLogIndexRequest extends FormRequest
{
    /**
     * 요청 권한을 확인합니다.
     *
     * @return bool 항상 true (권한은 permission 미들웨어에서 처리)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'extension_type' => ['nullable', 'array'],
            'extension_type.*' => ['string', Rule::in(ExtensionOwnerType::values())],
            'extension_identifier' => ['nullable', 'array'],
            'extension_identifier.*' => ['string', 'max:100'],
            'template_type' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'array'],
            'status.*' => ['string', Rule::in(array_column(MailSendStatus::cases(), 'value'))],
            'search' => ['nullable', 'string', 'max:255'],
            'search_type' => ['nullable', 'string', Rule::in(['all', 'recipient_email', 'recipient_name', 'subject'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort_by' => ['nullable', 'string', Rule::in(['sent_at', 'recipient_email', 'subject', 'extension_type', 'status'])],
            'sort_order' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];

        return HookManager::applyFilters('core.mail_send_log.index_validation_rules', $rules, $this);
    }

    /**
     * 사용자 정의 유효성 검증 메시지를 반환합니다.
     *
     * @return array<string, string> 메시지
     */
    public function messages(): array
    {
        return [
            'status.in' => __('mail_send_log.validation.status_invalid'),
            'date_to.after_or_equal' => __('mail_send_log.validation.date_range_invalid'),
            'per_page.min' => __('mail_send_log.validation.per_page_min'),
            'per_page.max' => __('mail_send_log.validation.per_page_max'),
        ];
    }
}
