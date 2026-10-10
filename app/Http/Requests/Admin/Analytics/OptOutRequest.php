<?php

namespace App\Http\Requests\Admin\Analytics;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 수집 제외 조회·전환 요청
 *
 * 받는 값은 없습니다 — 이 요청이 어느 브라우저에서 왔는지가 곧 대상이고, 그 판정에
 * 쓰는 쿠키와 보안 연결 여부를 요청 객체에서 읽습니다.
 */
class OptOutRequest extends FormRequest
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
        return HookManager::applyFilters('core.analytics.opt_out_validation_rules', $rules, $this);
    }
}
