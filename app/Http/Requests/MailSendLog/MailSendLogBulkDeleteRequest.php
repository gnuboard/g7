<?php

namespace App\Http\Requests\MailSendLog;

use App\Models\MailSendLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 메일 발송 이력 일괄 삭제 요청을 검증합니다.
 */
class MailSendLogBulkDeleteRequest extends FormRequest
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
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', Rule::exists(MailSendLog::class, 'id')],
        ];
    }

    /**
     * 사용자 정의 유효성 검증 메시지를 반환합니다.
     *
     * @return array<string, string> 메시지
     */
    public function messages(): array
    {
        return [
            'ids.required' => __('mail_send_log.validation.ids_required'),
            'ids.min' => __('mail_send_log.validation.ids_min'),
            'ids.*.exists' => __('mail_send_log.validation.id_not_found'),
        ];
    }
}
