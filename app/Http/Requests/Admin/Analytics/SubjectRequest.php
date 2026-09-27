<?php

namespace App\Http\Requests\Admin\Analytics;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 정보주체 조회 요청
 *
 * 조건이 하나도 없으면 원장 전체가 대상이 됩니다 — 조회의 뜻이 사라지고 삭제에서는
 * 사고가 됩니다. 그래서 셋 중 하나는 반드시 받습니다.
 */
class SubjectRequest extends FormRequest
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
            'ip' => ['nullable', 'ip'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'visitor_key' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];

        // 확장이 자기 필드를 이 요청에 얹을 수 있게 규칙을 훅으로 연다.
        return HookManager::applyFilters('core.analytics.subject_validation_rules', $rules, $this);
    }

    /**
     * 조건이 하나라도 있는지 확인합니다.
     *
     * @param  Validator  $validator  검증기
     * @return void 없음
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->subject() === []) {
                $validator->errors()->add('ip', __('validation.analytics.subject_required'));
            }
        });
    }

    /**
     * 찾을 기준을 꺼냅니다.
     *
     * @return array<string, mixed> 비어 있지 않은 기준만
     */
    public function subject(): array
    {
        return array_filter([
            'ip' => $this->input('ip'),
            'user_id' => $this->input('user_id'),
            'visitor_key' => $this->input('visitor_key'),
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * 검증 항목명을 반환합니다 — 오류 문구에 필드 키 대신 화면에서 쓰는 이름이 나오게 합니다.
     *
     * @return array<string, string> 필드 => 항목명
     */
    public function attributes(): array
    {
        return [
            'ip' => __('analytics.attributes.ip'),
            'visitor_key' => __('analytics.attributes.visitor_key'),
            'from' => __('analytics.attributes.from'),
            'to' => __('analytics.attributes.to'),
        ];
    }
}
