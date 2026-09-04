<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Plugins\Sirsoft\MessageBizppurio\Http\Requests\Concerns\ValidatesTemplateContent;

/**
 * 비즈뿌리오 알림 템플릿 수정 검증 (#597 §3.2 — Store 와 대칭 매트릭스).
 *
 * notification_type 은 행 정체성이라 수정 대상이 아니다(전달돼도 무시 — validated 에
 * 포함되지 않는다). content 변경의 상태 가드(draft/rejected 만)는 서비스가 판정한다.
 */
class UpdateBizppurioTemplateRequest extends FormRequest
{
    use ValidatesTemplateContent;

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
     * 유형과 무관한 content 잔여 필드를 검증 전에 잘라냅니다 (Store 와 동일 정돈).
     */
    protected function prepareForValidation(): void
    {
        $this->pruneContentPayload();
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'alimtalk_enabled' => ['sometimes', 'boolean'],
            'fallback_sms_enabled' => ['sometimes', 'boolean'],
            'sms_only' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ], $this->smsBodyRules(), $this->contentRules());
    }

    /**
     * 항목별 조건부 필수(linkType 별 링크 필드 등)를 after 훅으로 검증합니다.
     *
     * @param  Validator  $validator  검증기
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->validateContentConditionals($v));
    }

    /**
     * 검증 오류 문구에 쓰일 필드 라벨을 반환합니다.
     *
     * @return array<string, string> 필드 경로 → 라벨
     */
    public function attributes(): array
    {
        return array_merge($this->deliveryAttributes(), $this->contentAttributes());
    }
}
