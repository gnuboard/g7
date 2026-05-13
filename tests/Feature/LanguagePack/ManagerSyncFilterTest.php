<?php

namespace Tests\Feature\LanguagePack;

use App\Extension\AbstractModule;
use App\Extension\AbstractPlugin;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * ModuleManager / PluginManager 의 sync 메서드가 lang pack hook 필터를 발화시키는지 검증.
 *
 * 회귀 차단:
 * - syncModuleNotificationDefinitions / syncPluginNotificationDefinitions
 * - syncModuleIdentityMessages / syncPluginIdentityMessages
 *
 * 모듈/플러그인 IDV 정책 (`syncModuleIdentityPolicies` 등) 은 IdentityPolicy 모델에 다국어 필드
 * (name/description) 가 부재하므로 lang pack seed 적용 대상 외 — 본 테스트 범위 외.
 */
class ManagerSyncFilterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 가상 모듈 인스턴스 (AbstractModule 의 default 구현 활용).
     *
     * @param  string  $identifier  모듈 식별자
     * @param  array  $payload  getter 가 반환할 페이로드
     * @param  string  $getter  override 할 메서드 이름
     * @return AbstractModule
     */
    private function fakeModule(string $identifier, array $payload, string $getter): AbstractModule
    {
        // AbstractModule::getIdentifier() 는 final + getModulePath() 의 basename 으로 식별.
        // getModulePath() 가 protected 이므로 override 하여 식별자를 결정한다.
        return new class($identifier, $payload, $getter) extends AbstractModule
        {
            public function __construct(
                private string $id,
                private array $payload,
                private string $getter,
            ) {}

            protected function getModulePath(): string
            {
                return sys_get_temp_dir().DIRECTORY_SEPARATOR.$this->id;
            }

            public function getNotificationDefinitions(): array
            {
                return $this->getter === 'getNotificationDefinitions' ? $this->payload : [];
            }

            public function getIdentityMessages(): array
            {
                return $this->getter === 'getIdentityMessages' ? $this->payload : [];
            }
        };
    }

    /**
     * 가상 플러그인 인스턴스.
     */
    private function fakePlugin(string $identifier, array $payload, string $getter): AbstractPlugin
    {
        return new class($identifier, $payload, $getter) extends AbstractPlugin
        {
            public function __construct(
                private string $id,
                private array $payload,
                private string $getter,
            ) {}

            protected function getPluginPath(): string
            {
                return sys_get_temp_dir().DIRECTORY_SEPARATOR.$this->id;
            }

            public function getNotificationDefinitions(): array
            {
                return $this->getter === 'getNotificationDefinitions' ? $this->payload : [];
            }

            public function getIdentityMessages(): array
            {
                return $this->getter === 'getIdentityMessages' ? $this->payload : [];
            }
        };
    }

    /**
     * Manager 의 protected sync 메서드를 reflection 으로 호출.
     */
    private function invokeSync(object $manager, string $method, object $arg): void
    {
        $ref = new ReflectionClass($manager);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        $m->invoke($manager, $arg);
    }

    public function test_module_notification_definitions_fires_lang_pack_filter(): void
    {
        $captured = null;
        HookManager::addFilter('seed.test-module.notifications.translations', function ($defs) use (&$captured) {
            $captured = $defs;

            return $defs;
        });

        $module = $this->fakeModule('test-module', [
            [
                'type' => 'test_welcome',
                'hook_prefix' => 'test',
                'name' => ['ko' => '환영', 'en' => 'Welcome'],
                'description' => ['ko' => '', 'en' => ''],
                'channels' => ['mail'],
                'templates' => [
                    [
                        'channel' => 'mail',
                        'subject' => ['ko' => '환영', 'en' => 'Welcome'],
                        'body' => ['ko' => '본문', 'en' => 'Body'],
                    ],
                ],
            ],
        ], 'getNotificationDefinitions');

        $this->invokeSync($this->app->make(ModuleManager::class), 'syncModuleNotificationDefinitions', $module);

        $this->assertNotNull($captured, 'seed.{id}.notifications.translations 필터가 발화되지 않음');
        $this->assertSame('test_welcome', $captured[0]['type'] ?? null);
    }

    public function test_module_identity_messages_fires_lang_pack_filter(): void
    {
        $captured = null;
        HookManager::addFilter('seed.test-module.identity_messages.translations', function ($defs) use (&$captured) {
            $captured = $defs;

            return $defs;
        });

        $module = $this->fakeModule('test-module', [
            [
                'provider_id' => 'g7:core.mail',
                'scope_type' => 'purpose',
                'scope_value' => 'signup',
                'name' => ['ko' => '회원가입'],
                'templates' => [],
            ],
        ], 'getIdentityMessages');

        $this->invokeSync($this->app->make(ModuleManager::class), 'syncModuleIdentityMessages', $module);

        $this->assertNotNull($captured, 'seed.{id}.identity_messages.translations 필터가 발화되지 않음');
        $this->assertSame('signup', $captured[0]['scope_value'] ?? null);
    }

    public function test_plugin_notification_definitions_fires_lang_pack_filter(): void
    {
        $captured = null;
        HookManager::addFilter('seed.test-plugin.notifications.translations', function ($defs) use (&$captured) {
            $captured = $defs;

            return $defs;
        });

        $plugin = $this->fakePlugin('test-plugin', [
            [
                'type' => 'test_order_paid',
                'hook_prefix' => 'test',
                'name' => ['ko' => '결제완료', 'en' => 'Order Paid'],
                'description' => ['ko' => '', 'en' => ''],
                'channels' => ['mail'],
                'templates' => [
                    [
                        'channel' => 'mail',
                        'subject' => ['ko' => '결제완료', 'en' => 'Paid'],
                        'body' => ['ko' => '본문', 'en' => 'Body'],
                    ],
                ],
            ],
        ], 'getNotificationDefinitions');

        $this->invokeSync($this->app->make(PluginManager::class), 'syncPluginNotificationDefinitions', $plugin);

        $this->assertNotNull($captured, 'seed.{id}.notifications.translations 필터가 플러그인에서 발화되지 않음');
        $this->assertSame('test_order_paid', $captured[0]['type'] ?? null);
    }

    public function test_plugin_identity_messages_fires_lang_pack_filter(): void
    {
        $captured = null;
        HookManager::addFilter('seed.test-plugin.identity_messages.translations', function ($defs) use (&$captured) {
            $captured = $defs;

            return $defs;
        });

        $plugin = $this->fakePlugin('test-plugin', [
            [
                'provider_id' => 'kcp:identity',
                'scope_type' => 'purpose',
                'scope_value' => 'sensitive_action',
                'name' => ['ko' => '민감 작업'],
                'templates' => [],
            ],
        ], 'getIdentityMessages');

        $this->invokeSync($this->app->make(PluginManager::class), 'syncPluginIdentityMessages', $plugin);

        $this->assertNotNull($captured, 'seed.{id}.identity_messages.translations 필터가 플러그인에서 발화되지 않음');
        $this->assertSame('sensitive_action', $captured[0]['scope_value'] ?? null);
    }
}
