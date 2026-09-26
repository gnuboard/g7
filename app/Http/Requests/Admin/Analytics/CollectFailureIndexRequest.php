<?php

namespace App\Http\Requests\Admin\Analytics;

use App\Enums\Analytics\CollectFailureReason;
use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 적재 실패 목록 조회 요청
 */
class CollectFailureIndexRequest extends FormRequest
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
        $rules = [
            'reason' => ['nullable', Rule::in(CollectFailureReason::values())],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];

        // 확장이 자기 필드를 이 요청에 얹을 수 있게 규칙을 훅으로 연다.
        return HookManager::applyFilters('core.analytics.collect_failures_validation_rules', $rules, $this);
    }

    /**
     * 검증 항목명을 반환합니다 — 오류 문구에 필드 키 대신 화면에서 쓰는 이름이 나오게 합니다.
     *
     * @return array<string, string> 필드 => 항목명
     */
    public function attributes(): array
    {
        return [
            'reason' => __('analytics.attributes.reason'),
        ];
    }
}
