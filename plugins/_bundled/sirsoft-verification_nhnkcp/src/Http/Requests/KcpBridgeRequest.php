<?php

// audit:allow api-doc-coverage reason: docblock 서술 정정만 수행 (web GET 브리지의 검증 실패 모드 서술) — 검증 규칙/요청·응답 계약 무변경. 이 브리지는 web 전용 HTML 내비게이션이라 API 문서 대상도 아니다

namespace Plugins\Sirsoft\VerificationNhnkcp\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * KCP 인증 결과 브리지 페이지 진입 쿼리를 검증합니다.
 *
 * 콜백 컨트롤러가 302 redirect 로 붙여 보내는 값만 받습니다. 배열 주입
 * (`?verification_token[]=x`)은 string 규칙이 차단합니다 — 이 라우트는 web GET
 * HTML 내비게이션이라 검증 실패는 422 JSON 이 아니라 redirect-back 으로 끝나며,
 * 어느 쪽이든 배열이 브리지 payload 에 유입되지 않는 것이 목적입니다. 종전에는
 * `(string)` 캐스팅이 배열을 "Array" 문자열로 바꿔 브리지 payload 에 유입됐습니다.
 * 길이 상한을 두지 않는 이유는 토큰 길이가 코어 발급 정책에 종속되어 있기 때문입니다
 * (kginicis InicisPopupBridgeRequest 선례).
 *
 * 스키마 비대칭(의도): kginicis 브리지는 `identity_error_message` 를 발신하지 않으므로
 * (실측) 그쪽 계약에 이 필드를 신설하지 않는다 — 死계약 신설 금지. 본 플러그인의
 * 콜백 Resolver 는 이 필드를 발신하므로 여기에는 존재한다.
 */
class KcpBridgeRequest extends FormRequest
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
            'identity_error_message' => ['nullable', 'string'],
        ];
    }

    /**
     * bridge 스크립트에 전달할 payload 를 반환합니다.
     *
     * @return array<string, string> 네 값 (미지정은 빈 문자열)
     */
    public function bridgePayload(): array
    {
        return [
            'verification_token' => (string) ($this->validated('verification_token') ?? ''),
            'challenge_id' => (string) ($this->validated('challenge_id') ?? ''),
            'identity_error' => (string) ($this->validated('identity_error') ?? ''),
            'identity_error_message' => (string) ($this->validated('identity_error_message') ?? ''),
        ];
    }
}
