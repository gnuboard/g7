<?php

namespace App\Http\Requests\ExtensionTrustKey;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 확장 신뢰 키 목록 조회 요청 — 입력 칸은 없고, 확장이 조회 조건을 더할 자리만 둔다.
 *
 * @since 7.2.0
 */
class IndexExtensionTrustKeyRequest extends FormRequest
{
    /**
     * 권한 판정은 라우트 미들웨어가 담당합니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙.
     *
     * @return array<string, mixed> 규칙 (기본은 없음)
     */
    public function rules(): array
    {
        return HookManager::applyFilters('core.extension_trust_keys.index_validation_rules', [], $this);
    }
}
