<?php

namespace App\Http\Requests\Identity;

use App\Extension\HookManager;
use App\Rules\IdentityProviderForPurposeRule;
use App\Rules\RegisteredIdentityPurposeRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Challenge 요청 검증.
 *
 * 권한은 라우트의 permission:user,core.identity.request 미들웨어가 담당합니다.
 * 비로그인 가입 플로우(Mode B) 도 동일 엔드포인트를 사용하므로 optional.sanctum 경로도 허용.
 */
class RequestChallengeRequest extends FormRequest
{
    /**
     * 요청 권한 — 라우트 permission 미들웨어가 담당하므로 true 고정.
     *
     * @return bool
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
        $rules = [
            // 등록된 목적만 — 공개 경로는 로그인 2단계 목적을 발급하지 않는다(7.1.6, KVE 동형 B1)
            'purpose' => ['required', 'string', 'max:64', new RegisteredIdentityPurposeRule(false, 'identity.errors.purpose_unknown')],
            'target' => ['nullable', 'array'],
            'target.email' => ['nullable', 'email', 'max:255'],
            'target.phone' => ['nullable', 'string', 'max:32'],
            // 등록돼 있고 그 목적을 지원하는 수단만 — 모르는 수단을 메일로 바꿔 발급하지 않는다(B9)
            'provider_id' => ['nullable', 'string', 'max:64', new IdentityProviderForPurposeRule(fn (): ?string => is_string($this->input('purpose')) ? $this->input('purpose') : null)],
            // 428 응답이 알려 준 정책 키 — 정책별 문구를 고르는 데만 쓰고, 쓸 수 없는 키는 컨트롤러가 무시한다.
            'policy_key' => ['nullable', 'string', 'max:120'],
        ];

        // 모듈/플러그인이 IDV challenge 요청 검증 규칙을 동적으로 확장 가능 (예: 도메인 특화 메타데이터)
        return HookManager::applyFilters('core.identity.request_validation_rules', $rules, $this);
    }
}
