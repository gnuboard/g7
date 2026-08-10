<?php

namespace Plugins\Sirsoft\VerificationKginicis\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
