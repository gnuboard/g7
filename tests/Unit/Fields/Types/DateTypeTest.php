<?php

namespace Tests\Unit\Fields\Types;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * 날짜 유형(날짜, 날짜+시간, 기간)의 규칙과 저장 왕복.
 */
class DateTypeTest extends FieldTypeTestCase
{
    /**
     * 시각을 고정합니다 — 상대 기준(오늘)이 테스트 실행 시각에 흔들리지 않게 한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.default_user_timezone' => 'Asia/Seoul']);
        Carbon::setTestNow(Carbon::parse('2026-11-11 03:00:00', 'UTC')); // 서울 2026-11-11 12:00
    }

    /**
     * 고정한 시각을 풉니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * 날짜는 형식과 가장 이른 날·가장 늦은 날을 검사한다.
     *
     * @scenario field_type=date
     *
     * @effects field_type_rules_reject_invalid_input, field_type_value_roundtrip
     *
     * @return void 반환값 없음
     */
    public function test_date_format_and_fixed_bounds(): void
    {
        $definition = $this->definition('date', ['min' => '2026-01-01', 'max' => '2026-12-31']);

        $this->assertTrue($this->passes($definition, '2026-06-15'));
        $this->assertFalse($this->passes($definition, '2025-12-31'), '가장 이른 날보다 앞선 날짜가 통과했다');
        $this->assertFalse($this->passes($definition, '2027-01-01'), '가장 늦은 날보다 뒤의 날짜가 통과했다');
        $this->assertFalse($this->passes($definition, '2026/06/15'), '형식이 다른 날짜가 통과했다');
        $this->assertFalse($this->passes($definition, '2026-02-30'), '없는 날짜가 통과했다');
        $this->assertSame('2026-06-15', $this->roundTrip($definition, '2026-06-15'));
    }

    /**
     * 상대 기준 「오늘」은 사이트 시간대의 오늘이고, 「오늘 이후」는 오늘을 뺀다.
     *
     * @scenario field_type=date
     *
     * @effects field_type_relative_date_bound_uses_site_timezone
     *
     * @return void 반환값 없음
     */
    public function test_date_relative_bounds_follow_site_timezone(): void
    {
        $untilToday = $this->definition('date', ['max' => 'today']);
        $this->assertTrue($this->passes($untilToday, '2026-11-11'), '오늘이 「오늘까지」에서 거부됐다');
        $this->assertFalse($this->passes($untilToday, '2026-11-12'));

        $afterToday = $this->definition('date', ['min' => 'after_today']);
        $this->assertFalse($this->passes($afterToday, '2026-11-11'), '오늘이 「오늘 이후」에서 통과했다');
        $this->assertTrue($this->passes($afterToday, '2026-11-12'));

        // UTC 로는 아직 11일이지만 서울은 12일인 시각 — 사이트 시간대의 오늘이 기준이어야 한다.
        Carbon::setTestNow(Carbon::parse('2026-11-11 16:00:00', 'UTC'));
        $this->assertTrue($this->passes($untilToday, '2026-11-12'), '사이트 시간대가 아닌 UTC 의 오늘로 판정했다');
    }

    /**
     * 경계 값은 날짜 또는 상대 기준만 받는다.
     *
     * @scenario field_type=date
     *
     * @effects field_type_constraint_rules_guard_definition
     *
     * @return void 반환값 없음
     */
    public function test_date_bound_constraint_validation(): void
    {
        $rules = $this->type('date')->constraintRules();

        $this->assertTrue(Validator::make(['constraints' => ['min' => 'today', 'max' => '2030-01-01']], $rules)->passes());
        $this->assertTrue(Validator::make(['constraints' => ['min' => 'tomorrow']], $rules)->fails(), '모르는 상대 기준이 통과했다');
    }

    /**
     * 날짜+시간은 브라우저 datetime-local 값을 받는다.
     *
     * @scenario field_type=datetime
     *
     * @effects field_type_rules_reject_invalid_input, field_type_value_roundtrip
     *
     * @return void 반환값 없음
     */
    public function test_datetime_format(): void
    {
        $definition = $this->definition('datetime', ['min' => 'after_today']);

        $this->assertTrue($this->passes($definition, '2026-11-12T09:30'));
        $this->assertFalse($this->passes($definition, '2026-11-12 09:30'), '형식이 다른 값이 통과했다');
        $this->assertFalse($this->passes($definition, '2026-11-10T09:30'), '오늘 이전 시각이 통과했다');
        $this->assertSame('2026-11-12T09:30', $this->roundTrip($definition, '2026-11-12T09:30'));
        // 보기용 값은 입력칸 형식의 구분 글자(T) 없이 읽기 좋게 보인다
        $this->assertSame('2026-11-12 09:30', $this->type('datetime')->display($definition, '2026-11-12T09:30'));
        $this->assertNull($this->type('datetime')->display($definition, null));
    }

    /**
     * 기간은 시작과 끝이 함께 있어야 하고 끝이 시작보다 빠를 수 없다.
     *
     * @scenario field_type=date_range
     *
     * @effects field_type_rules_reject_invalid_input, field_type_value_roundtrip
     *
     * @return void 반환값 없음
     */
    public function test_date_range_order_and_pairing(): void
    {
        $definition = $this->definition('date_range');
        $type = $this->type('date_range');

        $this->assertTrue($this->passes($definition, ['start' => '2026-01-01', 'end' => '2026-01-01']));
        $this->assertFalse($this->passes($definition, ['start' => '2026-01-02', 'end' => '2026-01-01']), '시작이 끝보다 늦은 기간이 통과했다');
        $this->assertFalse($this->passes($definition, ['start' => '2026-01-02']), '끝이 없는 기간이 통과했다');
        $this->assertFalse($this->passes($definition, ['start' => '2026-01-01', 'end' => '2026-01-02', 'extra' => 'x']), '모르는 하위 키가 통과했다');
        $this->assertSame(['start' => '2026-01-01', 'end' => '2026-01-31'], $this->roundTrip($definition, ['start' => '2026-01-01', 'end' => '2026-01-31']));
        $this->assertNull($type->normalize($definition, ['start' => '2026-01-01', 'end' => '']), '한쪽만 있는 기간이 빈 값이 되지 않았다');
        $this->assertSame(['field.start', 'field.end'], $type->requiredAttributes($definition, 'field'));
        $this->assertSame('2026-01-01 ~ 2026-01-31', $type->display($definition, ['start' => '2026-01-01', 'end' => '2026-01-31']));
    }
}
