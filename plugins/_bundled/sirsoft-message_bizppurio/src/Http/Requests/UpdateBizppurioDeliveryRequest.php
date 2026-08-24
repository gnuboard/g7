<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Http\Requests;

use App\Models\NotificationDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\MessageBizppurio\Http\Requests\Concerns\ValidatesTemplateContent;

/**
 * 비즈뿌리오 알림 발송 설정 upsert 검증 (#597 §4.1 행 하단 토글).
 *
 * 알림 설정 탭 행의 대체 SMS·SMS 단독·알림톡 사용 토글이 즉시 저장하는 요청이다.
 * 대상 행이 없으면 draft 로 생성(upsert)하므로 notification_type(라우트 파라미터)의
 * 알림 정의 존재만 검증한다. 알림톡 content 는 이 경로로 변경할 수 없다(작성 모달 전용).
 */
class UpdateBizppurioDeliveryRequest extends FormRequest
{
    // sms_body 규칙·발송 설정 라벨을 Store/Update 와 공유한다. 이 경로만 따로 조립하면
    // 세 경로의 검증 강도가 갈리고(약한 쪽이 우회로), 라벨도 빠진다.
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
     * 라우트 파라미터(notificationType)를 검증 대상에 병합합니다.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'notification_type' => (string) $this->route('notificationType'),
        ]);
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'notification_type' => [
                'required',
                'string',
                'max:100',
                Rule::exists(NotificationDefinition::class, 'type'),
            ],
            'alimtalk_enabled' => ['sometimes', 'boolean'],
            'fallback_sms_enabled' => ['sometimes', 'boolean'],
            'sms_only' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ] + $this->smsBodyRules();
    }

    /**
     * 저장할 발송 설정 필드만 반환합니다 (notification_type 제외).
     *
     * @return array<string, mixed>
     */
    public function deliveryData(): array
    {
        return collect($this->validated())
            ->only(['alimtalk_enabled', 'fallback_sms_enabled', 'sms_body', 'sms_only', 'is_active'])
            ->all();
    }

    /**
     * 검증 오류 문구에 쓰일 필드 라벨을 반환합니다.
     *
     * @return array<string, string> 필드 경로 → 라벨
     */
    public function attributes(): array
    {
        // 공용 deliveryAttributes() 를 그대로 쓴다 — 인라인으로 다시 나열하면 이 클래스만
        // notification_type 라벨을 빠뜨린 채 그 필드를 검증하게 된다(실제로 그랬다).
        return $this->deliveryAttributes();
    }
}
