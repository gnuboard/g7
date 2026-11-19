<?php

namespace App\Http\Requests\NotificationTemplate;

use App\Models\NotificationTemplate;
use App\Services\NotificationChannelService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 알림 템플릿 활성 토글 요청.
 *
 * 운영자가 발송 수단을 켜서 만든 빈 템플릿(`is_user_created`)은 내용을 채우기 전에 켜지 못하게 막는다 — 켜지면 빈 제목·본문이나
 * 받는 사람 없음으로 발송을 시도한다. 확장이 선언한 템플릿은 받는 사람 없이 쓰는 선언이 있어(이벤트 측 규칙) 막지 않는다.
 * 끄는 쪽은 언제나 허용한다.
 */
class ToggleNotificationTemplateActiveRequest extends FormRequest
{
    /**
     * 권한 확인 (미들웨어에서 처리).
     *
     * @return bool 항상 true (권한은 미들웨어에서 검증)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다 — 입력 칸이 없고 판정은 대상 템플릿 상태로 한다.
     *
     * @return array<string, mixed> 필드별 검증 규칙
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * 운영자가 만든 빈 템플릿을 켜려는 요청을 거릅니다.
     *
     * 받는 사람 규칙이 하나도 없으면 거절한다. 본문은 코어 편집 칸을 쓰는 채널만 본다 — 본문을 확장이 자기 저장소에서
     * 받는 채널(편집기 `none`)은 코어 본문이 비어 있는 것이 정상이다.
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $template = $this->route('template');
            if (! $template instanceof NotificationTemplate || ! $template->is_user_created || $template->is_active) {
                return;
            }

            if (empty($template->recipients)) {
                $validator->errors()->add('recipients', __('notification.validation.user_template_needs_recipients'));
            }

            $editor = app(NotificationChannelService::class)->templateEditorFor((string) $template->channel);
            if ($editor !== 'none' && ! $this->hasAnyText($template->body)) {
                $validator->errors()->add('body', __('notification.validation.user_template_needs_body'));
            }
        });
    }

    /**
     * 다국어 값에 비어 있지 않은 언어가 하나라도 있는지 판정합니다.
     *
     * @param  mixed  $value  다국어 배열
     * @return bool 내용이 있으면 true
     */
    private function hasAnyText(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $text) {
            if (is_string($text) && trim(strip_tags($text)) !== '') {
                return true;
            }
        }

        return false;
    }
}
