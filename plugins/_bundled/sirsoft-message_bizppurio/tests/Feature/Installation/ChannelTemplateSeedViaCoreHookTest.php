<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Feature\Installation;

use App\Enums\ExtensionStatus;
use App\Extension\AbstractModule;
use App\Extension\AbstractPlugin;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use App\Models\Plugin as PluginRecord;
use Plugins\Sirsoft\MessageBizppurio\Listeners\SeedChannelTemplatesListener;
use Plugins\Sirsoft\MessageBizppurio\Plugin;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;
use ReflectionClass;

/**
 * 코어 공통 시딩 훅만으로 임의 확장의 회원 알림에 sms·alimtalk template 이 생기는지 검증한다.
 *
 * 종전에는 이 플러그인이 게시판·쇼핑몰의 언어팩 시딩 훅을 이름으로 구독해, 목록에 없는 새 모듈의
 * 알림에는 문자·알림톡 template 이 생기지 않았다. 이제 코어가 모든 모듈·플러그인 동기화에서
 * core.notification.filter_seed_definitions 하나를 발화하고 이 플러그인은 그 훅에만 답한다.
 * 게시판·쇼핑몰도 같은 모듈 동기화 경로를 타므로, 이름 없는 새 모듈이 통과하면 두 모듈도 같다.
 */
class ChannelTemplateSeedViaCoreHookTest extends PluginTestCase
{
    /**
     * 시딩 리스너를 등록합니다 (활성 플러그인의 리스너 등록과 같은 경로).
     *
     * @return void 반환값 없음
     */
    private function registerSeedListener(): void
    {
        HookListenerRegistrar::register(SeedChannelTemplatesListener::class, 'sirsoft-message_bizppurio');
    }

    /**
     * 회원 대상 알림 1건과 관리자 전용 알림 1건을 담은 정의 목록을 만듭니다.
     *
     * @param  string  $prefix  타입·훅 접두사
     * @return array<int, array<string, mixed>> 알림 정의 목록
     */
    private function definitions(string $prefix): array
    {
        return [
            [
                'type' => "{$prefix}_member_notice",
                'hook_prefix' => $prefix,
                'name' => ['ko' => '회원 안내', 'en' => 'Member notice'],
                'channels' => ['mail', 'database'],
                'templates' => [
                    ['channel' => 'mail', 'recipients' => [['type' => 'trigger_user']], 'subject' => ['ko' => '안내'], 'body' => ['ko' => '<p>{name}님 안내</p>']],
                    ['channel' => 'database', 'recipients' => [['type' => 'trigger_user']], 'subject' => ['ko' => '안내'], 'body' => ['ko' => '{name}님 안내']],
                ],
            ],
            [
                'type' => "{$prefix}_admin_digest",
                'hook_prefix' => $prefix,
                'name' => ['ko' => '관리자 요약', 'en' => 'Admin digest'],
                'channels' => ['mail'],
                'templates' => [
                    ['channel' => 'mail', 'recipients' => [['type' => 'role', 'value' => 'admin']], 'subject' => ['ko' => '요약'], 'body' => ['ko' => '요약']],
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
     * Manager 의 protected 동기화 메서드를 호출합니다 (설치·업데이트의 알림 정의 동기화 단계).
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
     * 정의 타입의 저장된 template 채널 목록을 돌려줍니다.
     *
     * @param  string  $type  알림 정의 타입
     * @return array<int, string> 채널 목록
     */
    private function storedChannels(string $type): array
    {
        $definition = NotificationDefinition::where('type', $type)->firstOrFail();

        return NotificationTemplate::where('definition_id', $definition->id)->pluck('channel')->all();
    }

    /**
     * 이름을 모르는 새 모듈의 알림 정의가 동기화되면 회원 알림에만 sms·alimtalk template 이 생기고,
     * 다시 동기화(업데이트)해도 지워지지 않는다.
     *
     * @effects arbitrary_extension_definitions_augmented_via_core_seed_hook, seeded_channel_templates_survive_resync
     *
     * @return void 반환값 없음
     */
    public function test_새_모듈_동기화만으로_회원_알림에_sms_alimtalk_template이_생긴다(): void
    {
        $this->registerSeedListener();
        $manager = app(ModuleManager::class);
        $module = $this->fakeModule('vendor-future_domain', $this->definitions('future_domain'));

        $this->invokeSync($manager, 'syncModuleNotificationDefinitions', $module);

        $member = $this->storedChannels('future_domain_member_notice');
        $this->assertContains('sms', $member);
        $this->assertContains('alimtalk', $member);
        $this->assertNotContains('sms', $this->storedChannels('future_domain_admin_digest'), '관리자 전용 알림은 증강하지 않는다.');

        $definition = NotificationDefinition::where('type', 'future_domain_member_notice')->firstOrFail();
        $this->assertSame('vendor-future_domain', $definition->extension_identifier, '정의 소유자는 모듈 그대로다.');
        $this->assertContains('alimtalk', $definition->channels);

        $sms = NotificationTemplate::where('definition_id', $definition->id)->where('channel', 'sms')->firstOrFail();
        $this->assertSame('{name}님 안내', $sms->body['ko'] ?? null, 'database 평문 본문을 재활용한다.');

        // 업데이트 재동기화 — 증강 template 은 정의 선언처럼 다뤄져 stale 정리에 지워지지 않는다.
        $this->invokeSync($manager, 'syncModuleNotificationDefinitions', $module);
        $this->assertContains('alimtalk', $this->storedChannels('future_domain_member_notice'));
    }

    /**
     * 플러그인이 선언한 회원 알림도 같은 코어 훅으로 증강된다.
     *
     * @effects arbitrary_extension_definitions_augmented_via_core_seed_hook
     *
     * @return void 반환값 없음
     */
    public function test_다른_플러그인의_회원_알림도_코어_훅으로_증강된다(): void
    {
        $this->registerSeedListener();
        $plugin = $this->fakePlugin('vendor-future_plugin', $this->definitions('future_plugin'));

        $this->invokeSync(app(PluginManager::class), 'syncPluginNotificationDefinitions', $plugin);

        $this->assertContains('alimtalk', $this->storedChannels('future_plugin_member_notice'));
        $this->assertNotContains('alimtalk', $this->storedChannels('future_plugin_admin_digest'));
    }

    /**
     * 도메인별 언어팩 시딩 훅에는 답하지 않는다 — 그 훅만 발화하는 경로(코어 공통 훅 없음)에서는
     * 증강이 일어나지 않아야 코어 훅 하나로 수렴했음이 드러난다.
     *
     * @effects seed_answered_via_single_core_hook_without_domain_names
     *
     * @return void 반환값 없음
     */
    public function test_도메인_언어팩_시딩_훅에는_답하지_않는다(): void
    {
        $this->registerSeedListener();

        $definitions = $this->definitions('future_domain');
        $filtered = HookManager::applyFilters('seed.vendor-future_domain.notifications.translations', $definitions);

        $this->assertSame($definitions, $filtered, '도메인 언어팩 훅 결과는 이 플러그인이 바꾸지 않는다.');
    }

    /**
     * 가상 플러그인을 설치·활성 상태로 만들고 PluginManager 에 설치본으로 로드된 인스턴스로 넣습니다.
     *
     * @param  AbstractPlugin  $plugin  가상 플러그인
     * @return void 반환값 없음
     */
    private function installActivePlugin(AbstractPlugin $plugin): void
    {
        PluginRecord::query()->updateOrCreate(
            ['identifier' => $plugin->getIdentifier()],
            [
                'vendor' => 'vendor',
                'name' => ['ko' => $plugin->getIdentifier(), 'en' => $plugin->getIdentifier()],
                'version' => '1.0.0',
                'status' => ExtensionStatus::Active->value,
            ]
        );
        PluginManager::invalidatePluginStatusCache();

        $manager = app(PluginManager::class);
        $property = new \ReflectionProperty($manager, 'plugins');
        $property->setAccessible(true);
        $loaded = $property->getValue($manager);
        $loaded[$plugin->getIdentifier()] = $plugin;
        $property->setValue($manager, $loaded);
    }

    /**
     * 이 플러그인을 켜는 순간, 이미 설치·활성인 다른 플러그인의 회원 알림에도 sms·alimtalk 행이 생기고
     * 운영자가 고친 템플릿 내용은 보존된다. 다시 켜도(재활성화) 운영자가 고친 문자 본문이 남는다.
     *
     * @effects activation_resyncs_installed_plugin_definitions, activation_resync_preserves_user_overrides
     *
     * @return void 반환값 없음
     */
    public function test_활성화하면_이미_설치된_플러그인의_회원_알림에_행이_생기고_운영자_수정은_보존된다(): void
    {
        // [준비] 이 플러그인을 켜기 전 — 다른 플러그인이 설치돼 알림 정의가 mail·database 로만 저장됨
        $other = $this->fakePlugin('vendor-future_plugin', $this->definitions('future_plugin'));
        $this->installActivePlugin($other);
        $this->invokeSync(app(PluginManager::class), 'syncPluginNotificationDefinitions', $other);
        $this->assertNotContains('sms', $this->storedChannels('future_plugin_member_notice'), '사전조건: 켜기 전에는 문자 행이 없다.');

        $definition = NotificationDefinition::where('type', 'future_plugin_member_notice')->firstOrFail();
        $mail = NotificationTemplate::where('definition_id', $definition->id)->where('channel', 'mail')->firstOrFail();
        $mail->body = ['ko' => '운영자가 고친 메일 본문'];
        $mail->save();

        // [실행] 정식 활성화 진입점
        (new Plugin)->activate();

        // [검증] 다른 플러그인의 회원 알림에 행 생성, 관리자 전용 제외, 운영자 수정 보존
        $member = $this->storedChannels('future_plugin_member_notice');
        $this->assertContains('sms', $member);
        $this->assertContains('alimtalk', $member);
        $this->assertNotContains('sms', $this->storedChannels('future_plugin_admin_digest'));
        $this->assertSame('운영자가 고친 메일 본문', $mail->fresh()->body['ko'] ?? null);

        // 재활성화 — 운영자가 고친 문자 본문은 재동기화에도 남는다
        $sms = NotificationTemplate::where('definition_id', $definition->id)->where('channel', 'sms')->firstOrFail();
        $sms->body = ['ko' => '운영자가 고친 문자 본문'];
        $sms->save();

        (new Plugin)->activate();

        $this->assertSame('운영자가 고친 문자 본문', $sms->fresh()->body['ko'] ?? null);
    }
}
