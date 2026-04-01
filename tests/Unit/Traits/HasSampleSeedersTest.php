<?php

namespace Tests\Unit\Traits;

use App\Traits\HasSampleSeeders;
use PHPUnit\Framework\TestCase;

class HasSampleSeedersTest extends TestCase
{
    /**
     * Trait을 사용하는 테스트 더블 생성
     */
    private function createTestClass(): object
    {
        return new class
        {
            use HasSampleSeeders;
        };
    }

    /**
     * 초기 상태에서 false를 반환하는지 확인
     */
    public function test_초기_상태에서_false_반환(): void
    {
        $instance = $this->createTestClass();

        $this->assertFalse($instance->shouldIncludeSample());
    }

    /**
     * setIncludeSample(true) 설정 후 shouldIncludeSample이 true 반환
     */
    public function test_true_설정_후_true_반환(): void
    {
        $instance = $this->createTestClass();

        $result = $instance->setIncludeSample(true);

        $this->assertTrue($instance->shouldIncludeSample());
        $this->assertSame($instance, $result); // fluent interface 확인
    }

    /**
     * setIncludeSample(false) 설정 후 shouldIncludeSample이 false 반환
     */
    public function test_false_설정_후_false_반환(): void
    {
        $instance = $this->createTestClass();
        $instance->setIncludeSample(true);
        $instance->setIncludeSample(false);

        $this->assertFalse($instance->shouldIncludeSample());
    }
}
