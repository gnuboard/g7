<?php

namespace Tests\Unit\Rules;

use App\Rules\NotSelfParent;
use Tests\TestCase;

class NotSelfParentTest extends TestCase
{
    /**
     * 자기 자신을 부모로 설정하려는 경우 검증 실패
     */
    public function test_fails_when_parent_id_equals_current_id(): void
    {
        $rule = new NotSelfParent(5);

        $failCalled = false;
        $failMessage = '';

        $rule->validate('parent_id', 5, function ($message) use (&$failCalled, &$failMessage) {
            $failCalled = true;
            $failMessage = $message;
        });

        $this->assertTrue($failCalled);
        $this->assertEquals(__('validation.not_self_parent'), $failMessage);
    }

    /**
     * 다른 ID를 부모로 설정하는 경우 검증 성공
     */
    public function test_passes_when_parent_id_is_different(): void
    {
        $rule = new NotSelfParent(5);

        $failCalled = false;

        $rule->validate('parent_id', 10, function () use (&$failCalled) {
            $failCalled = true;
        });

        $this->assertFalse($failCalled);
    }

    /**
     * parent_id가 null인 경우 검증 성공
     */
    public function test_passes_when_parent_id_is_null(): void
    {
        $rule = new NotSelfParent(5);

        $failCalled = false;

        $rule->validate('parent_id', null, function () use (&$failCalled) {
            $failCalled = true;
        });

        $this->assertFalse($failCalled);
    }

    /**
     * currentId가 null인 경우 (새 생성) 검증 성공
     */
    public function test_passes_when_current_id_is_null(): void
    {
        $rule = new NotSelfParent(null);

        $failCalled = false;

        $rule->validate('parent_id', 5, function () use (&$failCalled) {
            $failCalled = true;
        });

        $this->assertFalse($failCalled);
    }

    /**
     * 문자열과 정수 비교 테스트
     */
    public function test_fails_with_string_integer_comparison(): void
    {
        $rule = new NotSelfParent(5);

        $failCalled = false;

        // 문자열 '5'도 정수 5와 동일하게 처리
        $rule->validate('parent_id', '5', function () use (&$failCalled) {
            $failCalled = true;
        });

        $this->assertTrue($failCalled);
    }
}
