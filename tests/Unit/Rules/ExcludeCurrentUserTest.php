<?php

namespace Tests\Unit\Rules;

use App\Rules\ExcludeCurrentUser;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ExcludeCurrentUserTest extends TestCase
{
    private ExcludeCurrentUser $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new ExcludeCurrentUser;
    }

    /**
     * 로그인한 사용자가 ID 목록에 포함된 경우 검증 실패
     */
    public function test_fails_when_current_user_is_in_ids(): void
    {
        // DB 없이 테스트 - Auth::id()만 모킹
        $userId = 123;
        Auth::shouldReceive('id')->andReturn($userId);

        $failCalled = false;
        $failMessage = '';

        $this->rule->validate('ids', [$userId, 2, 3], function ($message) use (&$failCalled, &$failMessage) {
            $failCalled = true;
            $failMessage = $message;
        });

        $this->assertTrue($failCalled);
        $this->assertEquals(__('validation.exclude_current_user'), $failMessage);
    }

    /**
     * 로그인한 사용자가 ID 목록에 포함되지 않은 경우 검증 성공
     */
    public function test_passes_when_current_user_is_not_in_ids(): void
    {
        Auth::shouldReceive('id')->andReturn(999);

        $failCalled = false;

        $this->rule->validate('ids', [1, 2, 3], function () use (&$failCalled) {
            $failCalled = true;
        });

        $this->assertFalse($failCalled);
    }

    /**
     * 인증되지 않은 사용자의 경우 검증 통과
     */
    public function test_passes_when_user_is_not_authenticated(): void
    {
        Auth::shouldReceive('id')->andReturn(null);

        $failCalled = false;

        $this->rule->validate('ids', [1, 2, 3], function () use (&$failCalled) {
            $failCalled = true;
        });

        $this->assertFalse($failCalled);
    }

    /**
     * 배열이 아닌 값의 경우 검증 통과
     */
    public function test_passes_when_value_is_not_array(): void
    {
        Auth::shouldReceive('id')->andReturn(1);

        $failCalled = false;

        $this->rule->validate('ids', 'not an array', function () use (&$failCalled) {
            $failCalled = true;
        });

        $this->assertFalse($failCalled);
    }

    /**
     * 빈 배열의 경우 검증 통과
     */
    public function test_passes_when_array_is_empty(): void
    {
        Auth::shouldReceive('id')->andReturn(1);

        $failCalled = false;

        $this->rule->validate('ids', [], function () use (&$failCalled) {
            $failCalled = true;
        });

        $this->assertFalse($failCalled);
    }

    /**
     * 문자열 ID와 정수 ID의 느슨한 비교 테스트
     */
    public function test_fails_with_loose_comparison(): void
    {
        Auth::shouldReceive('id')->andReturn(1);

        $failCalled = false;

        // 문자열 '1'도 정수 1과 동일하게 처리
        $this->rule->validate('ids', ['1', 2, 3], function () use (&$failCalled) {
            $failCalled = true;
        });

        $this->assertTrue($failCalled);
    }
}
