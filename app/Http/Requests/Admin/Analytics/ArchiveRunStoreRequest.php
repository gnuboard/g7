<?php

namespace App\Http\Requests\Admin\Analytics;

use App\Enums\Analytics\ArchiveRunType;
use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 수동 집계 실행 요청
 *
 * 화면의 「지금 집계」·「다시 집계」 버튼이 보내는 요청입니다.
 */
class ArchiveRunStoreRequest extends FormRequest
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
            'run_type' => ['required', 'string', 'in:'.implode(',', [
                ArchiveRunType::Hour->value,
                ArchiveRunType::Day->value,
                ArchiveRunType::Reaggregate->value,
            ])],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_if:run_type,'.ArchiveRunType::Reaggregate->value],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from', 'required_if:run_type,'.ArchiveRunType::Reaggregate->value],
            'force' => ['nullable', 'boolean'],
        ];

        // 확장이 자기 필드를 이 요청에 얹을 수 있게 규칙을 훅으로 연다.
        return HookManager::applyFilters('core.analytics.archive_run_validation_rules', $rules, $this);
    }

    /**
     * 검증 항목명을 반환합니다 — 오류 문구에 필드 키 대신 화면에서 쓰는 이름이 나오게 합니다.
     *
     * @return array<string, string> 필드 => 항목명
     */
    public function attributes(): array
    {
        return [
            'run_type' => __('analytics.attributes.run_type'),
            'from' => __('analytics.attributes.from'),
            'to' => __('analytics.attributes.to'),
            'force' => __('analytics.attributes.force'),
        ];
    }
}
