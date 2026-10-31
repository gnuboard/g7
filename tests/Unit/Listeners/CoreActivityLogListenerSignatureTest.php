<?php

namespace Tests\Unit\Listeners;

use App\Listeners\CoreActivityLogListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * 템플릿 after_deactivate 핸들러가 Manager 의 string identifier 1개 발화를 받는다.
     *
     * @effects deactivate_handler_accepts_string_identifier
     *
     * @return void 반환값 없음
     */
    public function test_handle_template_after_deactivate_accepts_string_identifier(): void
    {
        $listener = $this->app->make(CoreActivityLogListener::class);

        $reflection = new \ReflectionMethod($listener, 'handleTemplateAfterDeactivate');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params, '템플릿 after_deactivate 핸들러는 1개 인자 (string identifier) 만 받아야 한다');
        $this->assertSame('string', (string) $params[0]->getType(), '인자 타입이 string 이어야 매니저의 string 발화를 수용');
    }

    /**
     * 모듈 after_deactivate 핸들러가 Manager 의 string identifier 1개 발화를 받는다.
     *
     * @effects deactivate_handler_accepts_string_identifier
     *
     * @return void 반환값 없음
     */
    public function test_handle_module_after_deactivate_accepts_string_identifier(): void
    {
        $listener = $this->app->make(CoreActivityLogListener::class);

        $reflection = new \ReflectionMethod($listener, 'handleModuleAfterDeactivate');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params, '모듈 after_deactivate 핸들러는 1개 인자 (string identifier) 만 받아야 한다');
        $this->assertSame('string', (string) $params[0]->getType());
    }

    /**
     * 플러그인 after_deactivate 핸들러가 Manager 의 string identifier 1개 발화를 받는다.
     *
     * @effects deactivate_handler_accepts_string_identifier
     *
     * @return void 반환값 없음
     */
    public function test_handle_plugin_after_deactivate_accepts_string_identifier(): void
    {
        $listener = $this->app->make(CoreActivityLogListener::class);

        $reflection = new \ReflectionMethod($listener, 'handlePluginAfterDeactivate');
        $params = $reflection->getParameters();

        $this->assertCount(1, $params, '플러그인 after_deactivate 핸들러는 1개 인자 (string identifier) 만 받아야 한다');
        $this->assertSame('string', (string) $params[0]->getType());
    }

    /**
     * 정규 생명주기 훅 12건 — Manager 가 발화하는 인자 계약(#138).
     *
     * 값: [훅, 리스너 메서드, Manager 가 넘기는 인자 예시]. 인자 개수·순서·타입은
     * ExtensionLifecycleHookContractTest 가 발화 쪽에서 잠그고, 여기서는 받는 쪽을 잠근다.
     *
     * @return array<string, array{0: string, 1: string, 2: array<int, mixed>}> 훅별 계약
     */
    public static function lifecycleContractProvider(): array
    {
        $info = ['identifier' => 'acme-sample', 'version' => '1.0.0', 'name' => 'Sample'];
        $updated = ['success' => true, 'from_version' => '1.0.0', 'to_version' => '1.1.0'];

        return [
            'M1 module install' => ['core.modules.after_install', 'handleModuleAfterInstall', ['acme-sample', $info]],
            'M2 module activate' => ['core.modules.after_activate', 'handleModuleAfterActivate', ['acme-sample', $info]],
            'M3 module update' => ['core.modules.after_update', 'handleModuleAfterUpdate', ['acme-sample', $updated, $info]],
            'M4 module uninstall' => ['core.modules.after_uninstall', 'handleModuleAfterUninstall', ['acme-sample', $info, true]],
            'P1 plugin install' => ['core.plugins.after_install', 'handlePluginAfterInstall', ['acme-sample', $info]],
            'P2 plugin activate' => ['core.plugins.after_activate', 'handlePluginAfterActivate', ['acme-sample', $info]],
            'P3 plugin update' => ['core.plugins.after_update', 'handlePluginAfterUpdate', ['acme-sample', $updated, $info]],
            'P4 plugin uninstall' => ['core.plugins.after_uninstall', 'handlePluginAfterUninstall', ['acme-sample', true, true]],
            'T1 template install' => ['core.templates.after_install', 'handleTemplateAfterInstall', ['acme-sample', $info]],
            'T2 template activate' => ['core.templates.after_activate', 'handleTemplateAfterActivate', [$info]],
            'T3 template version update' => ['core.templates.after_version_update', 'handleTemplateAfterVersionUpdate', ['acme-sample', $updated, $info]],
            'T4 template uninstall' => ['core.templates.after_uninstall', 'handleTemplateAfterUninstall', ['acme-sample', $info, false]],
        ];
    }

    /**
     * 리스너가 그 훅을 구독하고, Manager 발화 인자 개수 이하의 필수 인자만 요구하며,
     * 실제 인자로 TypeError 없이 호출된다. 부족하면 큐 리스너가 흔적 없이 실패한다.
     *
     * @effects canonical_hook_arguments_match_listener_signature
     *
     * @param  string  $hook  정규 훅 이름
     * @param  string  $method  리스너 핸들러 메서드
     * @param  array<int, mixed>  $args  Manager 가 넘기는 인자
     * @return void 반환값 없음
     */
    #[DataProvider('lifecycleContractProvider')]
    public function test_lifecycle_handlers_accept_manager_argument_contract(string $hook, string $method, array $args): void
    {
        $hooks = CoreActivityLogListener::getSubscribedHooks();
        $this->assertSame($method, $hooks[$hook]['method'] ?? null, "{$hook} 구독 메서드");

        $listener = $this->app->make(CoreActivityLogListener::class);
        $reflection = new \ReflectionMethod($listener, $method);
        $this->assertLessThanOrEqual(
            count($args),
            $reflection->getNumberOfRequiredParameters(),
            "{$method} 의 필수 인자가 Manager 발화 인자보다 많다"
        );

        $listener->{$method}(...$args);
    }

    /**
     * 실제 호출 가능 여부 — TypeError 미발생 검증.
     *
     * @effects deactivate_handler_accepts_string_identifier
     *
     * @return void 반환값 없음
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
