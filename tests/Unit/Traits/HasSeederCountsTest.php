<?php

namespace Tests\Unit\Traits;

use App\Traits\HasSeederCounts;
use PHPUnit\Framework\TestCase;

class HasSeederCountsTest extends TestCase
{
    /**
     * Trait을 사용하는 테스트 더블 생성
     */
    private function createTestClass(): object
    {
        return new class
        {
            use HasSeederCounts;
        };
    }

    /**
     * 초기 상태에서 빈 배열을 반환하는지 확인
     */
    public function test_초기_상태에서_빈_배열_반환(): void
    {
        $instance = $this->createTestClass();

        $this->assertSame([], $instance->getSeederCounts());
    }

    /**
     * setSeederCounts로 설정한 값이 getSeederCounts로 반환되는지 확인
     */
    public function test_카운트_설정_및_조회(): void
    {
        $instance = $this->createTestClass();
        $counts = ['products' => 1000, 'orders' => 500];

        $instance->setSeederCounts($counts);

        $this->assertSame($counts, $instance->getSeederCounts());
    }

    /**
     * 존재하는 키의 카운트 값을 반환하는지 확인
     */
    public function test_존재하는_키의_카운트_반환(): void
    {
        $instance = $this->createTestClass();
        $instance->setSeederCounts(['products' => 500]);

        // getSeederCount는 protected이므로 리플렉션 사용
        $result = $this->invokeGetSeederCount($instance, 'products', 100);

        $this->assertSame(500, $result);
    }

    /**
     * 존재하지 않는 키에 대해 기본값을 반환하는지 확인
     */
    public function test_존재하지_않는_키에_기본값_반환(): void
    {
        $instance = $this->createTestClass();
        $instance->setSeederCounts(['products' => 500]);

        $result = $this->invokeGetSeederCount($instance, 'users', 100);

        $this->assertSame(100, $result);
    }

    /**
     * 카운트 값이 0 이하일 때 최소 1을 반환하는지 확인
     */
    public function test_최소값_1_보장(): void
    {
        $instance = $this->createTestClass();
        $instance->setSeederCounts(['products' => 0]);

        $result = $this->invokeGetSeederCount($instance, 'products', 100);

        $this->assertSame(1, $result);
    }

    /**
     * 음수 카운트 값에 대해 최소 1을 반환하는지 확인
     */
    public function test_음수_값에_최소값_1_보장(): void
    {
        $instance = $this->createTestClass();
        $instance->setSeederCounts(['products' => -10]);

        $result = $this->invokeGetSeederCount($instance, 'products', 100);

        $this->assertSame(1, $result);
    }

    /**
     * 빈 counts 배열에서 기본값 반환 확인
     */
    public function test_빈_배열에서_기본값_반환(): void
    {
        $instance = $this->createTestClass();
        $instance->setSeederCounts([]);

        $result = $this->invokeGetSeederCount($instance, 'products', 50);

        $this->assertSame(50, $result);
    }

    /**
     * 여러 키를 설정하고 각각 조회하는지 확인
     */
    public function test_여러_키_설정_및_개별_조회(): void
    {
        $instance = $this->createTestClass();
        $instance->setSeederCounts([
            'products' => 1000,
            'orders' => 500,
            'users' => 200,
        ]);

        $this->assertSame(1000, $this->invokeGetSeederCount($instance, 'products', 100));
        $this->assertSame(500, $this->invokeGetSeederCount($instance, 'orders', 50));
        $this->assertSame(200, $this->invokeGetSeederCount($instance, 'users', 100));
    }

    /**
     * protected getSeederCount 메서드를 호출하는 헬퍼
     *
     * @param  object  $instance  Trait 사용 인스턴스
     * @param  string  $key  카운트 키
     * @param  int  $default  기본값
     * @return int
     */
    private function invokeGetSeederCount(object $instance, string $key, int $default): int
    {
        $reflection = new \ReflectionMethod($instance, 'getSeederCount');
        $reflection->setAccessible(true);

        return $reflection->invoke($instance, $key, $default);
    }
}
