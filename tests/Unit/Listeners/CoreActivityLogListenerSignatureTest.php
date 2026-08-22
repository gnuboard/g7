<?php

namespace Tests\Unit\Listeners;

use App\Listeners\CoreActivityLogListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 회귀: 매니저(Module/Plugin/TemplateManager)의 after_deactivate 훅 발화 시그니처와
 * Listener 핸들러 메서드 시그니처가 일치해야 한다.
 *
 * 이슈 #302 자동 비활성화 경로(부팅 시 validateAndDeactivateIncompatible*) 가
 * 트리거될 때 다음 TypeError 가 발생하던 회귀 차단:
 *
 *   handleTemplateAfterDeactivate(): Argument #1 ($templateInfo) must be of type
 *   array, string given
 *
 * 매니저 측 호출:
 *   HookManager::doAction('core.{type}s.after_deactivate', $identifier);  // string 1 인자
 *
 * Listener 핸들러는 이 호출을 받아들여야 한다 (string 1 인자).
 */
class CoreActivityLogListenerSignatureTest extends TestCase
{
    // 아래 호출 가능 검증은 핸들러를 실제로 실행하므로 활동 로그가 기록된다.
    // 격리가 없으면 그 행이 프로세스 내내 남아, 테이블 전체 건수를 단언하는 다른 테스트를
    // 실행 순서에 따라 실패시킨다 (단독 실행에서는 통과해 원인을 찾기 어렵다).
    use RefreshDatabase;

    public function test_handle_template_after_deactivate_accepts_string_identifier(): void
    {
        $listener = $this->app->make(CoreActivityLogListener::class);

        $reflection = new \ReflectionMethod($listener, 'handleTemplateAfterDeactivate');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params, '템플릿 after_deactivate 핸들러는 1개 인자 (string identifier) 만 받아야 한다');
        $this->assertSame('string', (string) $params[0]->getType(), '인자 타입이 string 이어야 매니저의 string 발화를 수용');
    }

    public function test_handle_module_after_deactivate_accepts_string_identifier(): void
    {
        $listener = $this->app->make(CoreActivityLogListener::class);

        $reflection = new \ReflectionMethod($listener, 'handleModuleAfterDeactivate');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params, '모듈 after_deactivate 핸들러는 1개 인자 (string identifier) 만 받아야 한다');
        $this->assertSame('string', (string) $params[0]->getType());
    }

    public function test_handle_plugin_after_deactivate_accepts_string_identifier(): void
    {
        $listener = $this->app->make(CoreActivityLogListener::class);

        $reflection = new \ReflectionMethod($listener, 'handlePluginAfterDeactivate');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params, '플러그인 after_deactivate 핸들러는 1개 인자 (string identifier) 만 받아야 한다');
        $this->assertSame('string', (string) $params[0]->getType());
    }

    /**
     * 실제 호출 가능 여부 — TypeError 미발생 검증.
     */
    public function test_handlers_are_callable_with_string_only(): void
    {
        $listener = $this->app->make(CoreActivityLogListener::class);

        $listener->handleTemplateAfterDeactivate('test-template');
        $listener->handleModuleAfterDeactivate('test-module');
        $listener->handlePluginAfterDeactivate('test-plugin');

        $this->assertTrue(true, 'string 단일 인자로 모두 호출 가능');
    }
}
