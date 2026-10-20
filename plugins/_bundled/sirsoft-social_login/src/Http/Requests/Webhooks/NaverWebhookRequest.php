<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Requests\Webhooks;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Plugins\Sirsoft\SocialLogin\Services\WebhookReceiver;

/**
 * 네이버 연동 해제 알림 요청 — 형식만 1차로 거르고, 서명·복호화는 네이버 드라이버가 한다.
 *
 * @since 1.0.0
 */
class NaverWebhookRequest extends FormRequest
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
            'clientId' => ['nullable', 'string', 'max:255'],
            'encryptUniqueId' => ['nullable', 'string', 'max:1024'],
            'timestamp' => ['nullable', 'string', 'max:20'],
            'signature' => ['nullable', 'string', 'max:255'],
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
            'clientId' => __('sirsoft-social_login::validation.attributes.webhook.client_id'),
            'encryptUniqueId' => __('sirsoft-social_login::validation.attributes.webhook.user_id'),
            'timestamp' => __('sirsoft-social_login::validation.attributes.webhook.timestamp'),
            'signature' => __('sirsoft-social_login::validation.attributes.webhook.signature'),
        ];
    }

    /**
     * 형식 검증 실패를 네이버 규약대로 204 로 끝냅니다. 형식 오류는 수신 이력에 `ignored`(`webhook_payload_invalid`)로 남긴다.
     *
     * 제공자 서버가 부르는 주소라 422·302 로 답하지 않는다.
     *
     * @param  Validator  $validator  검증기
     * @return never 반환하지 않음(규약 응답 예외)
     *
     * @throws HttpResponseException 204 응답
     */
    protected function failedValidation(Validator $validator): never
    {
        app(WebhookReceiver::class)->rejectMalformed('naver', $this);

        throw new HttpResponseException(response('', 204));
    }
}
