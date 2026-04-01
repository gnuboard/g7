<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Rules;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';
// _bundled 디렉토리의 CooldownRule을 직접 로드 (재설치 전까지 활성 디렉토리에 없음)
require_once __DIR__.'/../../../src/Rules/CooldownRule.php';

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Modules\Sirsoft\Board\Rules\CooldownRule;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * CooldownRule 단위 테스트
 *
 * 게시글/댓글 연속 작성 방지 쿨다운 규칙의 동작을 테스트합니다.
 */
class CooldownRuleTest extends ModuleTestCase
{
    /**
     * 쿨다운이 0초일 때 검증을 통과하는지 테스트 (비활성화)
     */
    public function test_passes_when_cooldown_is_zero(): void
    {
        $rule = new CooldownRule('post', 0, 'test-board');
        $failed = false;

        $rule->validate('title', '테스트 제목', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    /**
     * 쿨다운이 음수일 때 검증을 통과하는지 테스트
     */
    public function test_passes_when_cooldown_is_negative(): void
    {
        $rule = new CooldownRule('post', -1, 'test-board');
        $failed = false;

        $rule->validate('title', '테스트 제목', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    /**
     * 캐시가 없을 때 검증을 통과하는지 테스트
     */
    public function test_passes_when_no_cache_exists(): void
    {
        Cache::flush();
        $rule = new CooldownRule('post', 30, 'test-board');
        $failed = false;

        $rule->validate('title', '테스트 제목', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    /**
     * 캐시가 있을 때 검증에 실패하는지 테스트 (회원)
     */
    public function test_fails_when_cache_exists_for_authenticated_user(): void
    {
        $user = \App\Models\User::factory()->create();
        Auth::shouldReceive('id')->andReturn($user->id);

        $cacheKey = "post_cooldown_test-board_{$user->id}";
        Cache::put($cacheKey, true, 30);

        $rule = new CooldownRule('post', 30, 'test-board');
        $failed = false;

        $rule->validate('title', '테스트 제목', function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed);

        Cache::forget($cacheKey);
    }

    /**
     * 캐시가 있을 때 검증에 실패하는지 테스트 (비회원, IP 기반)
     */
    public function test_fails_when_cache_exists_for_guest_ip(): void
    {
        Auth::shouldReceive('id')->andReturn(null);

        // request()->ip()를 모킹
        $this->app['request']->server->set('REMOTE_ADDR', '192.168.1.100');

        $cacheKey = 'comment_cooldown_test-board_192.168.1.100';
        Cache::put($cacheKey, true, 30);

        $rule = new CooldownRule('comment', 30, 'test-board');
        $failed = false;

        $rule->validate('content', '테스트 댓글', function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed);

        Cache::forget($cacheKey);
    }

    /**
     * 게시글과 댓글 쿨다운이 독립적으로 동작하는지 테스트
     */
    public function test_post_and_comment_cooldowns_are_independent(): void
    {
        $user = \App\Models\User::factory()->create();
        Auth::shouldReceive('id')->andReturn($user->id);

        // 게시글 쿨다운만 설정
        $postCacheKey = "post_cooldown_test-board_{$user->id}";
        Cache::put($postCacheKey, true, 30);

        // 댓글 쿨다운은 없음
        $commentRule = new CooldownRule('comment', 30, 'test-board');
        $commentFailed = false;

        $commentRule->validate('content', '테스트 댓글', function () use (&$commentFailed) {
            $commentFailed = true;
        });

        // 게시글 쿨다운은 있음
        $postRule = new CooldownRule('post', 30, 'test-board');
        $postFailed = false;

        $postRule->validate('title', '테스트 제목', function () use (&$postFailed) {
            $postFailed = true;
        });

        $this->assertFalse($commentFailed, '댓글 쿨다운은 게시글 쿨다운과 독립적');
        $this->assertTrue($postFailed, '게시글 쿨다운은 적용 중');

        Cache::forget($postCacheKey);
    }

    /**
     * 다른 게시판의 쿨다운은 영향을 주지 않는지 테스트
     */
    public function test_cooldown_is_per_board(): void
    {
        $user = \App\Models\User::factory()->create();
        Auth::shouldReceive('id')->andReturn($user->id);

        // board-a에만 쿨다운 설정
        $cacheKey = "post_cooldown_board-a_{$user->id}";
        Cache::put($cacheKey, true, 30);

        // board-b에는 쿨다운 없음
        $rule = new CooldownRule('post', 30, 'board-b');
        $failed = false;

        $rule->validate('title', '테스트 제목', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed, '다른 게시판의 쿨다운은 영향을 주지 않음');

        Cache::forget($cacheKey);
    }

    /**
     * 쿨다운 시간이 초 단위로 표시되는지 테스트
     */
    public function test_format_duration_seconds(): void
    {
        $rule = new CooldownRule('post', 45, 'test-board');
        $method = new \ReflectionMethod($rule, 'formatDuration');
        $method->setAccessible(true);

        $result = $method->invoke($rule, 45);
        $this->assertStringContainsString('45', $result);
    }

    /**
     * 쿨다운 시간이 분 단위로 표시되는지 테스트
     */
    public function test_format_duration_minutes(): void
    {
        $rule = new CooldownRule('post', 120, 'test-board');
        $method = new \ReflectionMethod($rule, 'formatDuration');
        $method->setAccessible(true);

        $result = $method->invoke($rule, 120);
        $this->assertStringContainsString('2', $result);
    }

    /**
     * 쿨다운 시간이 분+초 단위로 표시되는지 테스트
     */
    public function test_format_duration_minutes_and_seconds(): void
    {
        $rule = new CooldownRule('post', 90, 'test-board');
        $method = new \ReflectionMethod($rule, 'formatDuration');
        $method->setAccessible(true);

        $result = $method->invoke($rule, 90);
        $this->assertStringContainsString('1', $result);
        $this->assertStringContainsString('30', $result);
    }

    /**
     * 쿨다운 시간이 시간 단위로 표시되는지 테스트
     */
    public function test_format_duration_hours(): void
    {
        $rule = new CooldownRule('post', 3600, 'test-board');
        $method = new \ReflectionMethod($rule, 'formatDuration');
        $method->setAccessible(true);

        $result = $method->invoke($rule, 3600);
        $this->assertStringContainsString('1', $result);
    }

    /**
     * 쿨다운 시간이 시간+분 단위로 표시되는지 테스트
     */
    public function test_format_duration_hours_and_minutes(): void
    {
        $rule = new CooldownRule('post', 5400, 'test-board');
        $method = new \ReflectionMethod($rule, 'formatDuration');
        $method->setAccessible(true);

        $result = $method->invoke($rule, 5400);
        $this->assertStringContainsString('1', $result);
        $this->assertStringContainsString('30', $result);
    }

    /**
     * 캐시 만료 후 검증을 통과하는지 테스트
     */
    public function test_passes_after_cache_expires(): void
    {
        $user = \App\Models\User::factory()->create();
        Auth::shouldReceive('id')->andReturn($user->id);

        $cacheKey = "post_cooldown_test-board_{$user->id}";

        // 캐시를 1초로 설정 후 만료 시뮬레이션
        Cache::put($cacheKey, true, 1);
        Cache::forget($cacheKey); // 만료 시뮬레이션

        $rule = new CooldownRule('post', 30, 'test-board');
        $failed = false;

        $rule->validate('title', '테스트 제목', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }
}
