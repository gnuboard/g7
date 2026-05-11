<?php

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 외부 IDV provider 의 redirect 콜백 수신 요청.
 *
 * `POST /api/identity/callback/{providerId}` — 외부 본인인증 SDK / OAuth-style
 * provider 가 사용자 브라우저를 우리 서버로 다시 보내는 콜백 진입점.
 *
 * 요청 형식 예시:
 * - PortOne 스타일: body 에 `{ challenge_id, identity_verification_id, ... }`
 * - OAuth 스타일: query 에 `?challenge_id=...&code=...&state=...`
 *
 * 본 FormRequest 는 challenge_id 추출 + 자유로운 provider 페이로드 통과만 검증합니다.
 * 실제 provider 별 페이로드 해석은 IdentityVerificationManager 가 provider 의
 * `verify($challengeId, $input, $context)` 에 위임합니다.
 *
 * @since engine-v1.46.0
 */
class IdentityCallbackRequest extends FormRequest
{
    /**
     * 요청 권한 — 외부 redirect 콜백은 라우트 미들웨어 (throttle/optional.sanctum) 가 담당.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙.
     *
     * challenge_id 는 body 또는 query 둘 중 하나에 반드시 포함되어야 합니다.
     * 그 외 provider 별 필드는 자유 통과 (provider->verify 내부에서 추가 검증).
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'challenge_id' => ['required', 'string', 'max:64'],
            // 일반적으로 사용되는 필드 — 명시적으로 nullable 로 허용해 검증 통과
            'code' => ['nullable', 'string', 'max:512'],
            'token' => ['nullable', 'string', 'max:1024'],
            'state' => ['nullable', 'string', 'max:512'],
            'redirect_url' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /**
     * body / query 양쪽에서 challenge_id 를 합쳐 검증 대상에 포함시킵니다.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('challenge_id') && $this->query('challenge_id')) {
            $this->merge(['challenge_id' => (string) $this->query('challenge_id')]);
        }
    }
}
