<?php

namespace Tests\Unit\Execution;

use App\Execution\ExecutionMode;
use PHPUnit\Framework\TestCase;

/**
 * 실행 모드 열거형 단위 테스트.
 */
class ExecutionModeTest extends TestCase
{
    /**
     * 열거 값은 auto/subprocess/inprocess 셋뿐이다.
     */
    public function test_enum_cases(): void
    {
        $this->assertSame(
            ['auto', 'subprocess', 'inprocess'],
            array_map(fn (ExecutionMode $m) => $m->value, ExecutionMode::cases()),
        );
    }

    /**
     * fromStringOrAuto: null/빈 문자열/미지원 값은 전부 Auto 폴백.
     */
    public function test_from_string_or_auto_falls_back_to_auto(): void
    {
        $this->assertSame(ExecutionMode::Auto, ExecutionMode::fromStringOrAuto(null));
        $this->assertSame(ExecutionMode::Auto, ExecutionMode::fromStringOrAuto(''));
        $this->assertSame(ExecutionMode::Auto, ExecutionMode::fromStringOrAuto('nonsense'));
        $this->assertSame(ExecutionMode::Subprocess, ExecutionMode::fromStringOrAuto('subprocess'));
        $this->assertSame(ExecutionMode::Inprocess, ExecutionMode::fromStringOrAuto('inprocess'));
        $this->assertSame(ExecutionMode::Auto, ExecutionMode::fromStringOrAuto('auto'));
    }
}
