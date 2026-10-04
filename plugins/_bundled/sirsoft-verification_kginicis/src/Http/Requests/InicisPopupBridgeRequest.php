<?php

namespace Plugins\Sirsoft\VerificationKginicis\Http\Requests;

use App\Support\SiteUrl;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

/**
 * 이니시스 매뉴얼 STEP4 bridge 페이지 진입 쿼리를 검증합니다.
 *
 * 콜백 컨트롤러가 302 redirect 로 붙여 보내는 세 값만 받습니다. 길이 상한을 두지 않는 이유는
 * 토큰 길이가 코어 발급 정책에 종속되어 있어, 여기서 상한을 정하면 정책 변경 시 사용자가
 * 팝업 안에서 JSON 오류를 보게 되기 때문입니다.
 */
class InicisPopupBridgeRequest extends FormRequest
{
    /**
     * 요청 권한 — PG 인증 후 사용자 브라우저가 진입하는 공개 페이지이므로 true 고정.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        return [
            'verification_token' => ['nullable', 'string'],
            'challenge_id' => ['nullable', 'string'],
            'identity_error' => ['nullable', 'string'],
        ];
    }

    /**
     * 잘못된 진입 쿼리는 사이트 주소의 같은 브리지에서 본인확인 실패로 끝냅니다.
     *
     * 보안: Laravel 기본 검증 실패 처리는 `previous()`(Referer → 세션 → 요청 Host)로 302 이동시킨다 —
     * 외부 페이지에 걸린 링크면 우리 주소를 거쳐 그 페이지로 되돌아간다. 실패 코드는 부모창이 기본 실패
     * 안내로 보여 주는 값이다.
     *
     * @param  Validator  $validator  실패한 검증기
     * @return void 반환값 없음
     *
     * @throws ValidationException JSON 을 요구한 요청의 422
     * @throws HttpResponseException 사이트 주소 브리지로의 302
     */
    protected function failedValidation(Validator $validator): void
    {
        // JSON 을 요구한 요청은 기존 계약대로 422 검증 오류로 끝난다(이동이 없어 외부로 새지 않는다).
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        throw new HttpResponseException(redirect()->away(
            SiteUrl::absolute('/plugins/sirsoft-verification_kginicis/plugin/inicis/popup-bridge')
                .'?'.http_build_query(['identity_error' => 'UNKNOWN'])
        ));
    }

    /**
     * bridge 스크립트에 전달할 payload 를 반환합니다.
     *
     * @return array<string, string> 세 값 (미지정은 빈 문자열)
     */
    public function bridgePayload(): array
    {
        return [
            'verification_token' => (string) ($this->validated('verification_token') ?? ''),
            'challenge_id' => (string) ($this->validated('challenge_id') ?? ''),
            'identity_error' => (string) ($this->validated('identity_error') ?? ''),
        ];
    }
}
