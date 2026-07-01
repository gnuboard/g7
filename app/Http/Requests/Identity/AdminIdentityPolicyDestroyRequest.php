<?php

namespace App\Http\Requests\Identity;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 운영자가 IDV 정책을 삭제할 때의 요청 검증.
 *
 * 삭제 가능 여부(source_type='admin' 만 허용)는 Controller 가 도메인 규칙으로 판정하며,
 * 인증/권한은 라우트의 permission 미들웨어 체인이 담당합니다. 이 FormRequest 는 base
 * Illuminate\Http\Request 직접 주입을 피하고 모듈/플러그인의 동적 규칙 확장 지점을
 * 제공하기 위한 전용 서브클래스입니다.
 */
class AdminIdentityPolicyDestroyRequest extends FormRequest
{
    /**
     * 인증/권한은 route middleware 가 담당 — FormRequest 는 true 고정.
     *
     * @return bool 항상 true (권한 판정은 미들웨어 책임)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙 — 삭제는 라우트 {id} 만 사용하므로 본문 규칙은 없으나,
     * 확장이 동적 규칙을 주입할 수 있도록 필터 훅을 통과시킵니다.
     *
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [];

        return HookManager::applyFilters('core.identity_policy.destroy_validation_rules', $rules, $this);
    }
}
