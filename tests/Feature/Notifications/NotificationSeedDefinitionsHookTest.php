<?php

namespace Tests\Feature\Notifications;

use App\Enums\ExtensionStatus;
use App\Extension\AbstractModule;
use App\Extension\AbstractPlugin;
use App\Extension\Helpers\NotificationSyncHelper;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\Module;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Models\Plugin;
use Database\Seeders\NotificationDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * 알림 정의 시딩 공통 훅(core.notification.filter_seed_definitions) 계약 검증.
 *
 * 코어 시더·모듈 동기화·플러그인 동기화가 모두 저장 직전 같은 코어 훅 하나를 발화하고,
 * 소유자(extension_type·extension_identifier)를 정의마다와 컨텍스트에 함께 싣는지 확인한다.
 * 채널 제공 확장은 이 훅 하나에만 답하므로, 훅이 한 경로에서라도 빠지면 그 경로의 알림에는
 * 문자·알림톡 같은 채널 template 이 생기지 않는다.
 */
class NotificationSeedDefinitionsHookTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 테스트가 등록한 필터를 지워 다른 테스트로 새지 않게 합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter(NotificationSyncHelper::SEED_DEFINITIONS_FILTER);
        HookManager::clearFilter('seed.vendor-future_domain.notifications.translations');

        parent::tearDown();
    }

    /**
     * 회원 대상 알림 정의 한 건을 만듭니다.
     *
     * @param  string  $type  알림 정의 타입
     * @return array<string, mixed> 알림 정의
     */
    private function definition(string $type): array
    {
        return [
            'type' => $type,
            'hook_prefix' => 'vendor-future_domain',
            'name' => ['ko' => '예약 확정', 'en' => 'Booking confirmed'],
            'description' => ['ko' => '', 'en' => ''],
            'channels' => ['mail'],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '예약 확정', 'en' => 'Confirmed'],
                    'body' => ['ko' => '본문', 'en' => 'Body'],
                ],
            ],
        ];
    }

    /**
     * 알림 정의를 선언하는 가상 모듈을 만듭니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @param  array<int, array<string, mixed>>  $definitions  getNotificationDefinitions() 반환값
     * @return AbstractModule 가상 모듈
     */
    private function fakeModule(string $identifier, array $definitions): AbstractModule
    {
        return new class($identifier, $definitions) extends AbstractModule
        {
            /**
             * 식별자와 선언할 알림 정의를 받습니다.
             *
             * @param  string  $id  모듈 식별자
             * @param  array<int, array<string, mixed>>  $definitions  알림 정의 목록
             */
            public function __construct(private string $id, private array $definitions) {}

            /**
             * 식별자가 경로 basename 에서 나오도록 임시 경로를 돌려줍니다.
             *
             * @return string 모듈 경로
             */
            protected function getModulePath(): string
            {
                return sys_get_temp_dir().DIRECTORY_SEPARATOR.$this->id;
            }

            /**
             * 선언한 알림 정의를 돌려줍니다.
             *
             * @return array<int, array<string, mixed>> 알림 정의 목록
             */
            public function getNotificationDefinitions(): array
            {
                return $this->definitions;
            }
        };
    }

    /**
     * 알림 정의를 선언하는 가상 플러그인을 만듭니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  array<int, array<string, mixed>>  $definitions  getNotificationDefinitions() 반환값
     * @return AbstractPlugin 가상 플러그인
     */
    private function fakePlugin(string $identifier, array $definitions): AbstractPlugin
    {
        return new class($identifier, $definitions) extends AbstractPlugin
        {
            /**
             * 식별자와 선언할 알림 정의를 받습니다.
             *
             * @param  string  $id  플러그인 식별자
             * @param  array<int, array<string, mixed>>  $definitions  알림 정의 목록
             */
            public function __construct(private string $id, private array $definitions) {}

            /**
             * 식별자가 경로 basename 에서 나오도록 임시 경로를 돌려줍니다.
             *
             * @return string 플러그인 경로
             */
            protected function getPluginPath(): string
            {
                return sys_get_temp_dir().DIRECTORY_SEPARATOR.$this->id;
            }

            /**
             * 선언한 알림 정의를 돌려줍니다.
             *
             * @return array<int, array<string, mixed>> 알림 정의 목록
             */
            public function getNotificationDefinitions(): array
            {
                return $this->definitions;
            }
        };
    }

    /**
     * Manager 의 protected 동기화 메서드를 호출합니다.
     *
     * @param  object  $manager  ModuleManager 또는 PluginManager
     * @param  string  $method  동기화 메서드 이름
     * @param  object  $extension  대상 확장 인스턴스
     * @return void 반환값 없음
     */
    private function invokeSync(object $manager, string $method, object $extension): void
    {
        $ref = (new ReflectionClass($manager))->getMethod($method);
        $ref->setAccessible(true);
        $ref->invoke($manager, $extension);
    }

    /**
     * 공통 훅에 sms template 을 더하는 채널 제공 확장 역할의 필터를 등록하고, 받은 인자를 기록합니다.
     *
     * @param  array<int, array{definitions: array, context: array}>  $calls  호출 기록(참조)
     * @return void 반환값 없음
     */
    private function answerSeedHookWithSmsChannel(array &$calls): void
    {
        HookManager::addFilter(
            NotificationSyncHelper::SEED_DEFINITIONS_FILTER,
            function (array $definitions, array $context = []) use (&$calls) {
                $calls[] = ['definitions' => $definitions, 'context' => $context];

                foreach ($definitions as $i => $definition) {
                    $definitions[$i]['channels'][] = 'sms';
                    $definitions[$i]['templates'][] = [
                        'channel' => 'sms',
                        'recipients' => [['type' => 'trigger_user']],
                        'body' => ['ko' => '문자', 'en' => 'SMS'],
                    ];
                }

                return $definitions;
            },
            50
        );
    }

    /**
     * 가상 확장을 설치본으로 로드된 상태로 Manager 에 넣고, 설치 기록(DB)을 지정한 상태로 만듭니다.
     *
     * @param  object  $manager  ModuleManager 또는 PluginManager
     * @param  string  $property  Manager 의 로드된 인스턴스 속성 이름 (modules|plugins)
     * @param  object  $extension  가상 확장 인스턴스
     * @param  bool  $active  활성 상태로 기록할지 여부
     * @return void 반환값 없음
     */
    private function installLoaded(object $manager, string $property, object $extension, bool $active): void
    {
        $identifier = $extension->getIdentifier();
        $model = $property === 'modules' ? Module::class : Plugin::class;
        $model::query()->updateOrCreate(
            ['identifier' => $identifier],
            [
                'vendor' => 'vendor',
                'name' => ['ko' => $identifier, 'en' => $identifier],
                'version' => '1.0.0',
                'status' => $active ? ExtensionStatus::Active->value : ExtensionStatus::Inactive->value,
            ]
        );
        $property === 'modules'
            ? ModuleManager::invalidateModuleStatusCache()
            : PluginManager::invalidatePluginStatusCache();

        $ref = new \ReflectionProperty($manager, $property);
        $ref->setAccessible(true);
        $loaded = $ref->getValue($manager);
        $loaded[$identifier] = $extension;
        $ref->setValue($manager, $loaded);
    }

    /**
     * 모듈 동기화가 공통 훅을 발화하고, 훅이 더한 채널 template 이 그 모듈 소유 정의에 저장된다.
     * 게시판·쇼핑몰도 같은 동기화 경로를 타므로 이름 없는 새 모듈과 결과가 같다.
     *
     * @scenario owner=module
     *
     * @effects module_sync_fires_common_seed_hook_with_owner_context, seed_hook_contributed_templates_persist_for_owner
     *
     * @return void 반환값 없음
     */
    public function test_모듈_동기화가_공통_시딩_훅을_소유자와_함께_발화한다(): void
    {
        $calls = [];
        $this->answerSeedHookWithSmsChannel($calls);

        $module = $this->fakeModule('vendor-future_domain', [$this->definition('future_booking_confirmed')]);
        $this->invokeSync($this->app->make(ModuleManager::class), 'syncModuleNotificationDefinitions', $module);

        $this->assertCount(1, $calls, '모듈 동기화 1회에 공통 훅은 1회 발화한다.');
        $this->assertSame(
            ['extension_type' => 'module', 'extension_identifier' => 'vendor-future_domain'],
            $calls[0]['context']
        );
        $this->assertSame('future_booking_confirmed', $calls[0]['definitions'][0]['type']);
        $this->assertSame('module', $calls[0]['definitions'][0]['extension_type'], '정의마다 소유자가 실린다.');
        $this->assertSame('vendor-future_domain', $calls[0]['definitions'][0]['extension_identifier']);

        $definition = NotificationDefinition::where('type', 'future_booking_confirmed')->firstOrFail();
        $this->assertSame('vendor-future_domain', $definition->extension_identifier);
        $this->assertContains('sms', $definition->channels);
        $this->assertTrue(
            NotificationTemplate::where('definition_id', $definition->id)->where('channel', 'sms')->exists(),
            '훅이 더한 sms template 이 저장돼야 한다.'
        );
    }

    /**
     * 공통 훅은 도메인별 언어팩 훅(seed.{id}.notifications.translations) 다음에 발화한다 —
     * 채널 확장이 복사하는 본문에 언어팩 로케일이 이미 들어 있어야 종전 구독 경로와 결과가 같다.
     *
     * @scenario owner=module
     *
     * @effects common_seed_hook_runs_after_lang_pack_filter
     *
     * @return void 반환값 없음
     */
    public function test_공통_시딩_훅은_언어팩_필터_다음에_발화한다(): void
    {
        $order = [];
        HookManager::addFilter('seed.vendor-future_domain.notifications.translations', function (array $definitions) use (&$order) {
            $order[] = 'translations';
            $definitions[0]['templates'][0]['body']['ja'] = '本文';

            return $definitions;
        });
        HookManager::addFilter(NotificationSyncHelper::SEED_DEFINITIONS_FILTER, function (array $definitions) use (&$order) {
            $order[] = 'seed';
            $this->assertSame('本文', $definitions[0]['templates'][0]['body']['ja'] ?? null, '언어팩 병합 결과를 받아야 한다.');

            return $definitions;
        });

        $module = $this->fakeModule('vendor-future_domain', [$this->definition('future_booking_confirmed')]);
        $this->invokeSync($this->app->make(ModuleManager::class), 'syncModuleNotificationDefinitions', $module);

        $this->assertSame(['translations', 'seed'], $order);
    }

    /**
     * 플러그인 동기화도 같은 공통 훅을 플러그인 소유자와 함께 발화한다.
     *
     * @scenario owner=plugin
     *
     * @effects plugin_sync_fires_common_seed_hook_with_owner_context, seed_hook_contributed_templates_persist_for_owner
     *
     * @return void 반환값 없음
     */
    public function test_플러그인_동기화가_공통_시딩_훅을_소유자와_함께_발화한다(): void
    {
        $calls = [];
        $this->answerSeedHookWithSmsChannel($calls);

        $plugin = $this->fakePlugin('vendor-future_plugin', [$this->definition('future_plugin_notice')]);
        $this->invokeSync($this->app->make(PluginManager::class), 'syncPluginNotificationDefinitions', $plugin);

        $this->assertCount(1, $calls);
        $this->assertSame(
            ['extension_type' => 'plugin', 'extension_identifier' => 'vendor-future_plugin'],
            $calls[0]['context']
        );

        $definition = NotificationDefinition::where('type', 'future_plugin_notice')->firstOrFail();
        $this->assertTrue(
            NotificationTemplate::where('definition_id', $definition->id)->where('channel', 'sms')->exists()
        );
    }

    /**
     * 코어 시더도 같은 공통 훅을 발화하며, config 의 type 키 연관 배열을 type 을 담은 순차 배열로 편다.
     *
     * @scenario owner=core
     *
     * @effects core_seeder_fires_common_seed_hook_with_typed_list, seed_hook_contributed_templates_persist_for_owner
     *
     * @return void 반환값 없음
     */
    public function test_코어_시더가_공통_시딩_훅을_type_목록으로_발화한다(): void
    {
        $calls = [];
        $this->answerSeedHookWithSmsChannel($calls);

        (new NotificationDefinitionSeeder)->run();

        $this->assertCount(1, $calls);
        $this->assertSame(['extension_type' => 'core', 'extension_identifier' => 'core'], $calls[0]['context']);
        $this->assertTrue(array_is_list($calls[0]['definitions']), '코어도 순차 배열로 넘긴다.');

        $expectedTypes = array_keys(config('core.notification_definitions'));
        $this->assertSame($expectedTypes, array_column($calls[0]['definitions'], 'type'));

        $welcome = NotificationDefinition::where('type', 'welcome')->firstOrFail();
        $this->assertSame('core', $welcome->extension_type);
        $this->assertTrue(
            NotificationTemplate::where('definition_id', $welcome->id)->where('channel', 'sms')->exists(),
            '코어 정의에도 훅이 더한 template 이 저장돼야 한다.'
        );
    }

    /**
     * 리스너가 빈 목록을 돌려주면 원본 정의로 시딩한다 — 빈 목록 그대로면 stale 정리가 소유자의
     * 정의를 모두 지운다.
     *
     * @scenario owner=module
     *
     * @effects empty_seed_hook_result_falls_back_to_original_definitions
     *
     * @return void 반환값 없음
     */
    public function test_시딩_훅이_빈_목록을_돌려주면_원본으로_시딩한다(): void
    {
        HookManager::addFilter(NotificationSyncHelper::SEED_DEFINITIONS_FILTER, fn () => []);

        $module = $this->fakeModule('vendor-future_domain', [$this->definition('future_booking_confirmed')]);
        $this->invokeSync($this->app->make(ModuleManager::class), 'syncModuleNotificationDefinitions', $module);

        $this->assertTrue(NotificationDefinition::where('type', 'future_booking_confirmed')->exists());
    }

    /**
     * 활성 플러그인 알림 정의 재동기화는 설치·활성 플러그인의 알림 정의만 다시 돌려 공통 훅을 발화하고,
     * 비활성 플러그인은 건너뛰며, 운영자가 고친 템플릿 내용(user_overrides)은 보존한다.
     *
     * @scenario owner=plugin
     *
     * @effects active_plugin_notification_resync_fires_seed_hook_only_for_active, resync_preserves_user_overridden_templates
     *
     * @return void 반환값 없음
     */
    public function test_활성_플러그인_알림_정의_재동기화는_활성만_돌리고_운영자_수정을_보존한다(): void
    {
        $manager = $this->app->make(PluginManager::class);
        $active = $this->fakePlugin('vendor-future_plugin', [$this->definition('future_plugin_notice')]);
        $inactive = $this->fakePlugin('vendor-idle_plugin', [$this->definition('idle_plugin_notice')]);
        $this->installLoaded($manager, 'plugins', $active, true);
        $this->installLoaded($manager, 'plugins', $inactive, false);

        // 채널 확장이 켜지기 전 — 설치 때 저장된 상태와 운영자의 메일 본문 수정
        $this->invokeSync($manager, 'syncPluginNotificationDefinitions', $active);
        $definition = NotificationDefinition::where('type', 'future_plugin_notice')->firstOrFail();
        $mail = NotificationTemplate::where('definition_id', $definition->id)->where('channel', 'mail')->firstOrFail();
        $mail->body = ['ko' => '운영자가 고친 본문', 'en' => 'Body'];
        $mail->save();

        $calls = [];
        $this->answerSeedHookWithSmsChannel($calls);

        $result = $manager->resyncActivePluginNotificationDefinitions();

        $this->assertContains('vendor-future_plugin', $result['synced']);
        $this->assertNotContains('vendor-idle_plugin', $result['synced'], '비활성 플러그인은 재동기화하지 않는다.');
        $this->assertSame([], $result['failed']);
        $this->assertContains(
            ['extension_type' => 'plugin', 'extension_identifier' => 'vendor-future_plugin'],
            array_column($calls, 'context')
        );
        $this->assertFalse(NotificationDefinition::where('type', 'idle_plugin_notice')->exists());

        $this->assertTrue(
            NotificationTemplate::where('definition_id', $definition->id)->where('channel', 'sms')->exists(),
            '재동기화로 공통 훅이 더한 sms 행이 생겨야 한다.'
        );
        $this->assertSame('운영자가 고친 본문', $mail->fresh()->body['ko'] ?? null, '운영자가 고친 본문은 보존된다.');
    }

    /**
     * 활성 모듈 알림 정의 재동기화도 같은 방식으로 설치·활성 모듈의 알림 정의만 다시 돌린다.
     *
     * @scenario owner=module
     *
     * @effects active_module_notification_resync_fires_seed_hook_only_for_active
     *
     * @return void 반환값 없음
     */
    public function test_활성_모듈_알림_정의_재동기화는_활성_모듈만_돌린다(): void
    {
        $manager = $this->app->make(ModuleManager::class);
        $active = $this->fakeModule('vendor-future_domain', [$this->definition('future_booking_confirmed')]);
        $inactive = $this->fakeModule('vendor-idle_domain', [$this->definition('idle_booking_confirmed')]);
        $this->installLoaded($manager, 'modules', $active, true);
        $this->installLoaded($manager, 'modules', $inactive, false);

        $calls = [];
        $this->answerSeedHookWithSmsChannel($calls);

        $result = $manager->resyncActiveModuleNotificationDefinitions();

        $this->assertContains('vendor-future_domain', $result['synced']);
        $this->assertNotContains('vendor-idle_domain', $result['synced']);
        $this->assertContains(
            ['extension_type' => 'module', 'extension_identifier' => 'vendor-future_domain'],
            array_column($calls, 'context')
        );
        $definition = NotificationDefinition::where('type', 'future_booking_confirmed')->firstOrFail();
        $this->assertTrue(NotificationTemplate::where('definition_id', $definition->id)->where('channel', 'sms')->exists());
        $this->assertFalse(NotificationDefinition::where('type', 'idle_booking_confirmed')->exists());
    }
}
