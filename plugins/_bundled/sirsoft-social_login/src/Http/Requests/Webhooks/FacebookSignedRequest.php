<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Requests\Webhooks;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Plugins\Sirsoft\SocialLogin\Services\WebhookReceiver;

/**
 * 페이스북 앱 권한 해제·데이터 삭제 콜백 요청 — `signed_request` 형식만 거르고(빠져도 422 대신 드라이버가 서명 오류 400 으로 답한다), 서명은 페이스북 드라이버가 검증한다.
 *
 * @since 1.0.0
 */
class FacebookSignedRequest extends FormRequest
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
            'signed_request' => ['nullable', 'string', 'max:8192'],
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
            'signed_request' => __('sirsoft-social_login::validation.attributes.webhook.signed_request'),
        ];
    }

    /**
     * 형식 검증 실패를 서명 오류와 같은 400 `{error}` 로 끝냅니다(페이스북 콜백 규약 — 사이트 API 봉투를 쓰지 않는다). 형식 오류는 수신 이력에 `ignored` 로 남긴다.
     *
     * 제공자 서버가 부르는 주소라 422·302 로 답하지 않는다.
     *
     * @param  Validator  $validator  검증기
     * @return never 반환하지 않음(규약 응답 예외)
     *
     * @throws HttpResponseException 400 응답
     */
    protected function failedValidation(Validator $validator): never
    {
        app(WebhookReceiver::class)->rejectMalformed('facebook', $this);

        // audit:allow response-helper-bypass reason: 페이스북 콜백 규약 — 최상위 error 키를 읽는다
        throw new HttpResponseException(response()->json(['error' => 'webhook_payload_invalid'], 400));
    }
}
