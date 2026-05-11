<?php

namespace Tests\Unit\Rules;

use App\Models\User;
use App\Rules\ExcludeCurrentUser;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * ExcludeCurrentUser Rule 은 Auth::user()?->uuid 를 기준으로 검증한다.
 * 이전 Auth::id() 정수 비교 방식에서 UUID 비교로 변경됨 (리팩토링 반영).
 */
class ExcludeCurrentUserTest extends TestCase
{
    private ExcludeCurrentUser $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new ExcludeCurrentUser;
    }

    /**
     * 로그인한 사용자의 uuid 가 ID 목록에 포함된 경우 검증 실패
     */
    public function test_fails_when_current_user_is_in_ids(): void
    {
        $currentUuid = 'user-uuid-current';
        $user = (new User)->forceFill(['uuid' => $currentUuid]);
        Auth::shouldReceive('user')->andReturn($user);

        $failCalled = false;
        $failMessage = '';

        $this->rule->validate('ids', [$currentUuid, 'other-1', 'other-2'], function ($message) use (&$failCalled, &$failMessage) {
            $failCalled = true;
            $failMessage = $message;
        });

        $this->assertTrue($failCalled);
        $this->assertEquals(__('validation.exclude_current_user'), $failMessage);
    }

    /**
     * 로그인한 사용자의 uuid 가 ID 목록에 포함되지 않은 경우 검증 성공
     */
    public function test_passes_when_current_user_is_not_in_ids(): void
    {
        $user = (new User)->forceFill(['uuid' => 'current-uuid']);
        Auth::shouldReceive('user')->andReturn($user);

        $failCalled = false;

        $this->rule->validate('ids', ['uuid-1', 'uuid-2', 'uuid-3'], function () use (&$failCalled) {
            $failCalled = true;
        });

        $this->assertFalse($failCalled);
    }

    /**
     * 인증되지 않은 사용자의 경우 검증 통과
     */
    public function test_passes_when_user_is_not_authenticated(): void
    {
        Auth::shouldReceive('user')->andReturn(null);

        $failCalled = false;

        $this->rule->validate('ids', ['uuid-1', 'uuid-2'], function () use (&$failCalled) {
            $failCalled = true;
        });

        $this->assertFalse($failCalled);
    }

    /**
     * 배열이 아닌 값의 경우 검증 통과
     */
    public function test_passes_when_value_is_not_array(): void
    {
        $user = (new User)->forceFill(['uuid' => 'current-uuid']);
        Auth::shouldReceive('user')->andReturn($user);

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
        $user = (new User)->forceFill(['uuid' => 'current-uuid']);
        Auth::shouldReceive('user')->andReturn($user);

        $failCalled = false;

        $this->rule->validate('ids', [], function () use (&$failCalled) {
            $failCalled = true;
        });

        $this->assertFalse($failCalled);
    }

    /**
     * UUID 는 strict 비교이므로 정확히 일치해야 실패.
     * (이전 테스트는 loose 비교 (1 == '1') 였으나 UUID 는 문자열 정합성 필수)
     */
    public function test_fails_with_exact_uuid_match(): void
    {
        $uuid = 'exact-uuid-match';
        $user = (new User)->forceFill(['uuid' => $uuid]);
        Auth::shouldReceive('user')->andReturn($user);

        $failCalled = false;

        $this->rule->validate('ids', [$uuid, 'other'], function () use (&$failCalled) {
            $failCalled = true;
        });

        $this->assertTrue($failCalled);
    }
}
