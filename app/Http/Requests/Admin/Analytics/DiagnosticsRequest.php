<?php

namespace App\Http\Requests\Admin\Analytics;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 수집 진단 조회 요청
 *
 * 받는 값은 없지만, 진단은 지금 요청의 프록시 헤더를 읽어 「프록시 뒤에서 주소가
 * 하나로 뭉치고 있는가」를 판정합니다 — 그래서 요청 객체가 필요합니다.
 */
class DiagnosticsRequest extends FormRequest
{
    /**
     * 인가는 라우트 미들웨어가 판정합니다.
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
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [];

        // 확장이 자기 필드를 이 요청에 얹을 수 있게 규칙을 훅으로 연다.
        return HookManager::applyFilters('core.analytics.diagnostics_validation_rules', $rules, $this);
    }
}
