<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 알림톡 연동 즉시 저장 검증 (계획서 §6-2, Phase 6).
 *
 * 코어 편집 모달 전용 칸에서 연결 템플릿·SMS 대체를 바꾸면 즉시 이 요청으로 저장된다.
 * template_code 는 nullable — 비어 있으면 연동 해제로 처리한다("연결 안 함" 선택).
 */
class StoreNotificationBindingRequest extends FormRequest
{
    /**
     * 권한은 라우트 미들웨어(messaging.manage)에서 처리한다.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'notification_type' => ['required', 'string', 'max:100'],
            'template_code' => ['nullable', 'string', 'max:50'],
            'template_name' => ['nullable', 'string', 'max:255'],
            'fallback_sms_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
