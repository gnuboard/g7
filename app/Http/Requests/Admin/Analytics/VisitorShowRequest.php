<?php

namespace App\Http\Requests\Admin\Analytics;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 방문자 단건 조회 요청
 *
 * 누구를 볼지는 경로가 정합니다(`visitors/{visitorKey}`) — 목록에서 고른 그 사람입니다.
 * 그래서 본문에서 받는 것은 기간뿐입니다.
 */
class VisitorShowRequest extends FormRequest
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
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            // 정보주체가 열람을 요구하면 건네줄 파일이 있어야 한다(부록 D).
            'format' => ['nullable', 'string', 'in:json,csv'],
        ];

        // 확장이 자기 필드를 이 요청에 얹을 수 있게 규칙을 훅으로 연다.
        return HookManager::applyFilters('core.analytics.visitor_show_validation_rules', $rules, $this);
    }

    /**
     * CSV 로 내려받는 요청인지 확인합니다.
     *
     * @return bool CSV 요청이면 true
     */
    public function wantsCsv(): bool
    {
        return $this->input('format') === 'csv';
    }

    /**
     * 검증 항목명을 반환합니다 — 오류 문구에 필드 키 대신 화면에서 쓰는 이름이 나오게 합니다.
     *
     * @return array<string, string> 필드 => 항목명
     */
    public function attributes(): array
    {
        return [
            'from' => __('analytics.attributes.from'),
            'to' => __('analytics.attributes.to'),
            'format' => __('analytics.attributes.format'),
        ];
    }
}
