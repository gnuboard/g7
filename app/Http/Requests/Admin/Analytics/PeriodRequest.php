<?php

namespace App\Http\Requests\Admin\Analytics;

use App\Analytics\AnalyticsClock;
use App\Enums\Analytics\VisitorType;
use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * 통계 조회 기간 요청
 *
 * 통계 화면은 전부 같은 기간 규약을 씁니다 — 프리셋 이름이나 시작·종료일, 그리고
 * 합산에서 뺄 방문자 유형입니다. 그래서 검증도 한 곳에 둡니다.
 *
 * 기간 상한을 두는 이유는, 몇 년치를 한 번에 물으면 집계 표를 훑는 질의가 화면
 * 하나 때문에 데이터베이스를 오래 붙잡기 때문입니다.
 */
class PeriodRequest extends FormRequest
{
    /**
     * 한 번에 조회할 수 있는 최대 기간 (일)
     */
    public const MAX_RANGE_DAYS = 366;

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
            'preset' => ['nullable', 'string', 'in:today,yesterday,7d,30d,90d,this_month,last_month,this_year'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'unit' => ['nullable', 'string', 'in:auto,hour,day,week,month,year'],
            'exclude' => ['nullable', 'array'],
            'exclude.*' => ['string', 'in:admin,member'],
            // 내려받기는 기간 규약을 쓰는 화면 어디서나 된다 — 추이·방문자 상세도
            // 목록과 같은 방식으로 받는다(부록 D).
            'format' => ['nullable', 'string', 'in:json,csv'],
        ];

        // 확장이 자기 필드를 이 요청에 얹을 수 있게 규칙을 훅으로 연다.
        return HookManager::applyFilters('core.analytics.period_validation_rules', $rules, $this);
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
     * 기간 상한을 추가로 검사합니다.
     *
     * @param  Validator  $validator  검증기
     * @return void 없음
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            [$from, $to] = $this->resolvedRange();

            if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1 > self::MAX_RANGE_DAYS) {
                $validator->errors()->add('from', __('validation.analytics.range_too_long', [
                    'days' => self::MAX_RANGE_DAYS,
                ]));
            }
        });
    }

    /**
     * 프리셋이나 입력값에서 실제 조회 기간을 정합니다.
     *
     * 시작·종료일을 직접 준 경우가 프리셋보다 우선입니다 — 주소를 공유했을 때 보이던
     * 화면이 그대로 열려야 합니다.
     *
     * @return array{0: string, 1: string} 시작일과 종료일 (Y-m-d)
     */
    public function resolvedRange(): array
    {
        $from = $this->input('from');
        $to = $this->input('to');

        if (is_string($from) && $from !== '') {
            return [$from, is_string($to) && $to !== '' ? $to : $from];
        }

        $today = Carbon::parse(AnalyticsClock::today());

        return match ((string) $this->input('preset', '7d')) {
            'today' => [$today->toDateString(), $today->toDateString()],
            'yesterday' => [
                $today->copy()->subDay()->toDateString(),
                $today->copy()->subDay()->toDateString(),
            ],
            '30d' => [$today->copy()->subDays(29)->toDateString(), $today->toDateString()],
            '90d' => [$today->copy()->subDays(89)->toDateString(), $today->toDateString()],
            'this_month' => [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()],
            'last_month' => [
                $today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ],
            'this_year' => [$today->copy()->startOfYear()->toDateString(), $today->toDateString()],
            default => [$today->copy()->subDays(6)->toDateString(), $today->toDateString()],
        };
    }

    /**
     * 합산에 넣을 방문자 유형을 정합니다.
     *
     * 「관리자 제외」·「회원 제외」 토글은 유형별 집계 행에서 그 유형을 빼는 것입니다.
     *
     * @return array<int, string> 방문자 유형 목록
     */
    public function visitorTypes(): array
    {
        $excluded = (array) $this->input('exclude', []);

        return array_values(array_diff(
            [VisitorType::Guest->value, VisitorType::Member->value, VisitorType::Admin->value],
            $excluded,
        ));
    }

    /**
     * 검증 항목명을 반환합니다 — 오류 문구에 필드 키 대신 화면에서 쓰는 이름이 나오게 합니다.
     *
     * @return array<string, string> 필드 => 항목명
     */
    public function attributes(): array
    {
        return [
            'preset' => __('analytics.attributes.preset'),
            'from' => __('analytics.attributes.from'),
            'to' => __('analytics.attributes.to'),
            'unit' => __('analytics.attributes.unit'),
            'exclude' => __('analytics.attributes.exclude'),
            'exclude.*' => __('analytics.attributes.exclude'),
            'format' => __('analytics.attributes.format'),
        ];
    }
}
