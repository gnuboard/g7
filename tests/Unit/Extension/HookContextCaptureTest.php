<?php

namespace Tests\Unit\Extension;

use App\Extension\HookContextCapture;
use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * HookContextCapture 테스트
 *
 * 큐 워커에서 Auth/Request/Locale 컨텍스트를 캡처하고 복원하는 동작을 검증합니다.
 */
class HookContextCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        HookManager::resetAll();
    }

    protected function tearDown(): void
    {
        HookManager::resetAll();
        parent::tearDown();
    }

    /**
     * capture()가 현재 Auth/Request/Locale을 스냅샷하는지 검증합니다.
     */
    public function test_capture_returns_current_request_context(): void
    {
        $user = User::factory()->create();
        Auth::login($user);
        App::setLocale('ko');

        $request = Request::create('/api/admin/users', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'TestAgent/1.0',
        ]);
        app()->instance('request', $request);

        $context = HookContextCapture::capture();

        $this->assertEquals($user->id, $context['user_id']);
        $this->assertEquals('203.0.113.10', $context['ip_address']);
        $this->assertEquals('TestAgent/1.0', $context['user_agent']);
        $this->assertEquals('ko', $context['locale']);
        $this->assertEquals('api/admin/users', $context['path']);
    }

    /**
     * 미인증 상태에서도 capture()가 안전하게 동작하는지 검증합니다.
     */
    public function test_capture_handles_unauthenticated_state(): void
    {
        Auth::logout();

        $context = HookContextCapture::capture();

        $this->assertNull($context['user_id']);
        $this->assertArrayHasKey('locale', $context);
    }

    /**
     * restore()가 Auth, Locale, Request를 복원하는지 검증합니다.
     */
    public function test_restore_recovers_auth_locale_and_request(): void
    {
        $user = User::factory()->create();

        // 큐 워커처럼 빈 컨텍스트에서 시작
        Auth::logout();
        App::setLocale('en');

        HookContextCapture::restore([
            'user_id' => $user->id,
            'ip_address' => '198.51.100.7',
            'user_agent' => 'WorkerAgent/2.0',
            'locale' => 'ko',
            'path' => 'api/admin/settings',
        ]);

        $this->assertEquals($user->id, Auth::id());
        $this->assertEquals('ko', App::getLocale());
        $this->assertEquals('198.51.100.7', request()->ip());
        $this->assertEquals('WorkerAgent/2.0', request()->userAgent());
        $this->assertEquals('api/admin/settings', request()->path());
        $this->assertTrue(request()->is('api/admin/*'));
    }

    /**
     * 빈 컨텍스트(user_id null 등)에서도 restore()가 예외 없이 동작합니다.
     */
    public function test_restore_handles_empty_context(): void
    {
        Auth::logout();

        HookContextCapture::restore([
            'user_id' => null,
            'ip_address' => null,
            'user_agent' => null,
            'locale' => null,
            'path' => null,
        ]);

        $this->assertNull(Auth::id());
    }

    /**
     * capture() 시 hook.context.capture 필터로 플러그인이 키를 추가할 수 있는지 검증합니다.
     */
    public function test_capture_filter_allows_extension_to_add_keys(): void
    {
        HookManager::addFilter('hook.context.capture', function (array $context) {
            $context['tenant_id'] = 'tenant-42';

            return $context;
        });

        $context = HookContextCapture::capture();

        $this->assertEquals('tenant-42', $context['tenant_id']);
    }

    /**
     * restore() 시 hook.context.restore 액션으로 플러그인 복원 로직이 실행되는지 검증합니다.
     */
    public function test_restore_action_fires_for_extension_recovery(): void
    {
        $received = null;
        HookManager::addAction('hook.context.restore', function (array $context) use (&$received) {
            $received = $context['tenant_id'] ?? null;
        });

        HookContextCapture::restore([
            'user_id' => null,
            'tenant_id' => 'tenant-99',
        ]);

        $this->assertEquals('tenant-99', $received);
    }
}
