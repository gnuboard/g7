<?php

namespace App\Http\Requests\MailSendLog;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 메일 발송 이력 삭제 요청을 검증합니다.
 */
class MailSendLogDeleteRequest extends FormRequest
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
        return [];
    }
}
