<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Http\Requests;

use App\Models\NotificationDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Plugins\Sirsoft\MessageBizppurio\Http\Requests\Concerns\ValidatesTemplateContent;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioTemplate;

/**
 * 비즈뿌리오 알림 템플릿 생성 검증 (#597 §3.2).
 *
 * 알림 1건당 1행(unique)이며, 대상 알림 정의가 실제로 존재해야 한다. content(카카오
 * 등록 페이로드)는 선택 — SMS 단독 알림은 알림톡 content 없이 생성할 수 있고,
 * 검수 신청 시점에 content 존재를 서비스가 재검한다.
 */
class StoreBizppurioTemplateRequest extends FormRequest
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
     * 유형과 무관한 content 잔여 필드를 검증 전에 잘라냅니다.
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
            'notification_type' => [
                'required',
                'string',
                'max:100',
                Rule::exists(NotificationDefinition::class, 'type'),
                Rule::unique(BizppurioTemplate::class, 'notification_type'),
            ],
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
