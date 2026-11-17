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
            // 외부 화면을 거치는 수단(회사 계정 다시 로그인 등)이 확인을 마친 뒤 돌려보낼 화면 경로(7.2.0). 같은 출처 경로만
            // 수단에 넘기고 그 밖은 컨트롤러가 버린다.
            'return_path' => ['nullable', 'string', 'max:512'],
            // 인증을 시작한 화면의 범위(7.2.0). 관리자 화면이 `admin` 을 실으면 메일의 확인 링크와 사이트 링크를 사이트 주소
            // 기준으로 만든다(「사용자 화면 URL」 대신). 없으면 사용자 화면 기준.
            'scope' => ['nullable', 'string', 'in:admin,user'],
        ];

        // 모듈/플러그인이 IDV challenge 요청 검증 규칙을 동적으로 확장 가능 (예: 도메인 특화 메타데이터)
        return HookManager::applyFilters('core.identity.request_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 문구에 쓸 필드 이름을 반환합니다.
     *
     * @return array<string, string> 필드 키 => 표시 이름
     */
    public function attributes(): array
    {
        return [
            'return_path' => __('validation.attributes.return_path'),
            'scope' => __('validation.attributes.scope'),
        ];
    }
}
