<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Requests\Webhooks;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Plugins\Sirsoft\SocialLogin\Services\WebhookReceiver;

/**
 * 카카오 연결 끊기 알림 요청.
 *
 * 형식만 1차로 거른다(문자열·길이). 어드민 키·발신 IP·앱 ID 검증은 카카오 드라이버가 한다 — 그 실패는 422 가 아니라 200 + 수신
 * 이력 `ignored` 여야 하므로 여기서 다루지 않는다.
 *
 * @since 1.0.0
 */
class KakaoWebhookRequest extends FormRequest
{
    /**
     * 제공자 서버 요청 — 인증은 드라이버 검증이 맡는다.
     *
     * @return bool 허용 여부
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, string>> 규칙
     */
    public function rules(): array
    {
        return [
            'app_id' => ['nullable', 'string', 'max:50'],
            'user_id' => ['nullable', 'string', 'max:191'],
            'referrer_type' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * 검증 항목 라벨을 반환합니다.
     *
     * @return array<string, string> 필드 => 라벨
     */
    public function attributes(): array
    {
        return [
            'app_id' => __('sirsoft-social_login::validation.attributes.webhook.app_id'),
            'user_id' => __('sirsoft-social_login::validation.attributes.webhook.user_id'),
            'referrer_type' => __('sirsoft-social_login::validation.attributes.webhook.referrer_type'),
        ];
    }

    /**
     * 형식 검증 실패를 카카오 규약대로 빈 본문 200 으로 끝냅니다 — 실패 응답이 쌓이면 카카오가 알림을 멈춘다. 형식 오류는 수신 이력에 `ignored`(`webhook_payload_invalid`)로 남긴다.
     *
     * 제공자 서버가 부르는 주소라 422·302 로 답하지 않는다.
     *
     * @param  Validator  $validator  검증기
     * @return never 반환하지 않음(규약 응답 예외)
     *
     * @throws HttpResponseException 200 응답
     */
    protected function failedValidation(Validator $validator): never
    {
        app(WebhookReceiver::class)->rejectMalformed('kakao', $this);

        throw new HttpResponseException(response('', 200));
    }
}
