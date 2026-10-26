<?php

namespace App\Http\Requests\Identity;

use App\Extension\HookManager;
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
            'purpose' => ['required', 'string', 'max:64'],
            'target' => ['nullable', 'array'],
            'target.email' => ['nullable', 'email', 'max:255'],
            'target.phone' => ['nullable', 'string', 'max:32'],
            'provider_id' => ['nullable', 'string', 'max:64'],
            // 428 응답이 알려 준 정책 키 — 정책별 문구를 고르는 데만 쓰고, 쓸 수 없는 키는 컨트롤러가 무시한다.
            'policy_key' => ['nullable', 'string', 'max:120'],
            // 외부 화면을 거치는 수단(회사 계정 다시 로그인 등)이 확인을 마친 뒤 돌려보낼 화면 경로(7.2.0). 같은 출처 경로만
            // 수단에 넘기고 그 밖은 컨트롤러가 버린다.
            'return_path' => ['nullable', 'string', 'max:512'],
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
        ];
    }
}
