<?php

namespace Tests\Feature\LanguagePack;

use App\Enums\LanguagePackStatus;
use App\Extension\AbstractModule;
use App\Extension\AbstractPlugin;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\LanguagePack;
use App\Providers\LanguagePackServiceProvider;
use App\Services\LanguagePack\LanguagePackRegistry;
use App\Services\LanguagePack\LanguagePackSeedInjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * ModuleManager / PluginManager 의 sync 메서드가 lang pack hook 필터를 발화시키는지 검증.
 *
 * 회귀 차단:
 * - syncModuleNotificationDefinitions / syncPluginNotificationDefinitions
 * - (호환층) getIdentityMessages() 선언 — 7.1.0 부터 알림 정의로 변환돼 같은 notifications 필터를 탄다(#699)
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
     * @return AbstractModule 가상 모듈
     */
    private function fakeModule(string $identifier, array $payload, string $getter): AbstractModule
    {
        // AbstractModule::getIdentifier() 는 final + getModulePath() 의 basename 으로 식별.
        // getModulePath() 가 protected 이므로 override 하여 식별자를 결정한다.
        return new class($identifier, $payload, $getter) extends AbstractModule
        {
            /**
             * 식별자·페이로드·대상 getter 이름을 보관합니다.
             *
             * @param  string  $id  확장 식별자 (경로 basename)
             * @param  array  $payload  getter 가 반환할 페이로드
             * @param  string  $getter  페이로드를 돌려줄 getter 이름
             */
            public function __construct(
                private string $id,
                private array $payload,
                private string $getter,
            ) {}

            /**
             * 식별자를 basename 으로 하는 임시 경로를 돌려줍니다 (식별자 결정용).
             *
             * @return string 모듈 경로
             */
            protected function getModulePath(): string
            {
                return sys_get_temp_dir().DIRECTORY_SEPARATOR.$this->id;
            }

            /**
             * 대상 getter 가 알림 정의면 페이로드를, 아니면 빈 배열을 돌려줍니다.
             *
             * @return array 알림 정의 선언
             */
            public function getNotificationDefinitions(): array
            {
                return $this->getter === 'getNotificationDefinitions' ? $this->payload : [];
            }

            /**
             * 대상 getter 가 옛 본인인증 메시지면 페이로드를, 아니면 빈 배열을 돌려줍니다.
             *
             * @return array 옛 본인인증 메시지 선언
             */
            public function getIdentityMessages(): array
            {
                return $this->getter === 'getIdentityMessages' ? $this->payload : [];
            }
        };
    }

    /**
     * 가상 플러그인 인스턴스.
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  array  $payload  getter 가 반환할 페이로드
     * @param  string  $getter  override 할 메서드 이름
     * @return AbstractPlugin 가상 플러그인
     */
    private function fakePlugin(string $identifier, array $payload, string $getter): AbstractPlugin
    {
        return new class($identifier, $payload, $getter) extends AbstractPlugin
        {
            /**
             * 식별자·페이로드·대상 getter 이름을 보관합니다.
             *
             * @param  string  $id  확장 식별자 (경로 basename)
             * @param  array  $payload  getter 가 반환할 페이로드
             * @param  string  $getter  페이로드를 돌려줄 getter 이름
             */
            public function __construct(
                private string $id,
                private array $payload,
                private string $getter,
            ) {}

            /**
             * 식별자를 basename 으로 하는 임시 경로를 돌려줍니다 (식별자 결정용).
             *
             * @return string 플러그인 경로
             */
            protected function getPluginPath(): string
            {
                return sys_get_temp_dir().DIRECTORY_SEPARATOR.$this->id;
            }

            /**
             * 대상 getter 가 알림 정의면 페이로드를, 아니면 빈 배열을 돌려줍니다.
             *
             * @return array 알림 정의 선언
             */
            public function getNotificationDefinitions(): array
            {
                return $this->getter === 'getNotificationDefinitions' ? $this->payload : [];
            }

            /**
             * 대상 getter 가 옛 본인인증 메시지면 페이로드를, 아니면 빈 배열을 돌려줍니다.
             *
             * @return array 옛 본인인증 메시지 선언
             */
            public function getIdentityMessages(): array
            {
                return $this->getter === 'getIdentityMessages' ? $this->payload : [];
            }
        };
    }

    /**
     * Manager 의 protected sync 메서드를 reflection 으로 호출.
     *
     * @param  object  $manager  ModuleManager 또는 PluginManager
     * @param  string  $method  호출할 sync 메서드 이름
     * @param  object  $arg  동기화 대상 확장
     * @return void 반환값 없음
     */
    private function invokeSync(object $manager, string $method, object $arg): void
    {
        $ref = new ReflectionClass($manager);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        $m->invoke($manager, $arg);
    }

    /**
     * 모듈 알림 정의 동기화가 seed.{id}.notifications.translations 필터에 선언 목록을 넘긴다.
     *
     * @scenario owner=module
     *
     * @effects domain_lang_pack_filter_fires_on_module_notification_sync, module_notification_definitions_fires_lang_pack_filter
     *
     * @return void 반환값 없음
     */
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

    /**
     * (호환층) 모듈의 옛 본인인증 메시지 선언은 identity.purpose.{목적} 알림 정의로 변환된 뒤 같은 notifications 번역 필터를 거친다.
     *
     * @scenario owner=module
     *
     * @effects legacy_identity_declaration_converted_before_domain_lang_pack_filter, module_identity_messages_fires_lang_pack_filter
     *
     * @return void 반환값 없음
     */
    public function test_module_identity_messages_fires_lang_pack_filter(): void
    {
        $captured = null;
        HookManager::addFilter('seed.test-module.notifications.translations', function ($defs) use (&$captured) {
            $captured = $defs;

            return $defs;
        });

        $module = $this->fakeModule('test-module', [
            [
                'provider_id' => 'g7:core.mail',
                'scope_type' => 'purpose',
                'scope_value' => 'fake_signup',
                'name' => ['ko' => '회원가입'],
                'templates' => [],
            ],
        ], 'getIdentityMessages');

        $this->invokeSync($this->app->make(ModuleManager::class), 'syncModuleNotificationDefinitions', $module);

        // 옛 선언은 알림 정의(type 키)로 변환된 뒤 notifications 번역 필터를 거친다 — 팩이 type 키로 번역을 붙인다.
        $this->assertNotNull($captured, '변환된 본인인증 선언이 seed.{id}.notifications.translations 필터를 거치지 않음');
        $this->assertSame('identity.purpose.fake_signup', $captured[0]['type'] ?? null);
    }

    /**
     * 플러그인 알림 정의 동기화가 seed.{id}.notifications.translations 필터에 선언 목록을 넘긴다.
     *
     * @scenario owner=plugin
     *
     * @effects domain_lang_pack_filter_fires_on_plugin_notification_sync, plugin_notification_definitions_fires_lang_pack_filter
     *
     * @return void 반환값 없음
     */
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

    /**
     * (호환층) 플러그인의 옛 본인인증 메시지 선언(메일 외 provider 포함)도 알림 정의로 변환된 뒤 notifications 번역 필터를 거친다.
     *
     * @scenario owner=plugin
     *
     * @effects legacy_identity_declaration_converted_before_domain_lang_pack_filter, plugin_identity_messages_fires_lang_pack_filter
     *
     * @return void 반환값 없음
     */
    public function test_plugin_identity_messages_fires_lang_pack_filter(): void
    {
        $captured = null;
        HookManager::addFilter('seed.test-plugin.notifications.translations', function ($defs) use (&$captured) {
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

        $this->invokeSync($this->app->make(PluginManager::class), 'syncPluginNotificationDefinitions', $plugin);

        $this->assertNotNull($captured, '변환된 본인인증 선언이 플러그인 notifications 번역 필터를 거치지 않음');
        $this->assertSame('identity.purpose.sensitive_action', $captured[0]['type'] ?? null);
    }

    /**
     * 활성 언어팩(plugin/module/template scope)에 seed/manifest.json 이 있으면
     * ServiceProvider 가 `{scope}.{id}.manifest.translations` 필터를 자동 등록하고,
     * 그 필터가 manifest name/description 에 ja 를 주입함을 검증.
     *
     * Manager DB 저장 직전이 호출하는 `applyFilters("{scope}.{id}.manifest.translations", ...)`
     * 와 동일 경로 — Manager 결선이 끊기면 라벨이 영영 ko 폴백된다.
     *
     * @return iterable<string, array{string, string}> 케이스명 => [scope, 대상 확장 식별자]
     */
    public static function manifestScopeProvider(): iterable
    {
        yield 'plugin' => ['plugin', 'sirsoft-gdpr'];
        yield 'module' => ['module', 'sirsoft-ecommerce'];
        yield 'template' => ['template', 'sirsoft-basic'];
    }

    /**
     * 활성 ja 팩의 seed/manifest.json 이 있으면 ServiceProvider 가 scope 별 manifest 번역 필터를
     * 자동 등록하고, 그 필터가 name·description 에 ja 를 넣으면서 ko·en 은 그대로 둔다.
     *
     * @effects manifest_filter_auto_registered_for_module_scope, manifest_filter_auto_registered_for_plugin_scope,
     *   manifest_filter_auto_registered_for_template_scope, ja_injected_into_name_and_description_when_pack_present,
     *   ko_en_preserved_after_ja_injection, manifest_translations_filter_auto_registered_and_injects_ja
     *
     * @param  string  $scope  언어팩 scope (plugin|module|template)
     * @param  string  $target  대상 확장 식별자
     * @return void 반환값 없음
     */
    #[DataProvider('manifestScopeProvider')]
    public function test_manifest_translations_filter_auto_registered_and_injects_ja(string $scope, string $target): void
    {
        $packRoot = base_path("lang-packs/g7-{$scope}-{$target}-ja");
        File::ensureDirectoryExists($packRoot.'/seed');
        File::put($packRoot.'/seed/manifest.json', json_encode([
            'name' => 'ジャパニーズ名',
            'description' => 'ジャパニーズ説明',
        ]));

        try {
            LanguagePack::query()->create([
                'identifier' => "g7-{$scope}-{$target}-ja",
                'vendor' => 'g7',
                'scope' => $scope,
                'target_identifier' => $target,
                'locale' => 'ja',
                'locale_name' => 'Japanese',
                'locale_native_name' => '日本語',
                'text_direction' => 'ltr',
                'version' => '1.0.0',
                'status' => LanguagePackStatus::Active->value,
                'is_protected' => false,
                'manifest' => [],
                'source_type' => 'bundled',
            ]);
            $this->app->make(LanguagePackRegistry::class)->invalidate();

            // ServiceProvider 의 동적 등록 재실행 (활성 팩 변경 반영)
            $provider = new LanguagePackServiceProvider($this->app);
            $provider->registerExtensionSeedFilters($this->app->make(LanguagePackSeedInjector::class));

            // Manager DB 저장 직전과 동일한 필터 호출
            $result = HookManager::applyFilters("{$scope}.{$target}.manifest.translations", [
                'name' => ['ko' => '한국어명', 'en' => 'English'],
                'description' => ['ko' => '한국어설명', 'en' => 'English desc'],
            ]);

            $this->assertSame('ジャパニーズ名', $result['name']['ja'], "{$scope} manifest 필터가 ja name 미주입");
            $this->assertSame('ジャパニーズ説明', $result['description']['ja'], "{$scope} manifest 필터가 ja description 미주입");
            $this->assertSame('한국어명', $result['name']['ko'], 'ko 회귀');
            $this->assertSame('English', $result['name']['en'], 'en 회귀');
        } finally {
            File::deleteDirectory($packRoot);
        }
    }
}
