<?php

namespace Tests\Feature\Extension;

use App\Extension\AbstractModule;
use App\Extension\ModuleManager;
use App\Models\NotificationDefinition;
use App\Services\NotificationDefinitionService;
use Database\Seeders\NotificationDefinitionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * (호환층) 모듈의 `getIdentityMessages()` 선언이 본인인증 알림 정의로 합류하는지 검증 (#699)
 *
 * 7.1.0 부터 본인인증 메시지는 알림 정의(category=identity)다. 7.0.x 계약으로 선언한 서드파티
 * 모듈도 계속 동작하도록 ModuleManager 가 알림 정의 동기화 때 변환·합류시킨다
 * (@deprecated 7.1.0 — 8.0 제거). 옛 helper 직접 호출 테스트를 Manager 실경로로 바꿨다.
 */
class ModuleManagerIdentityMessageSyncTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 코어 알림 정의를 시딩합니다 (type 충돌 판정의 상대).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NotificationDefinitionSeeder::class);
        app(NotificationDefinitionService::class)->invalidateAllCache();
    }

    /**
     * 옛 선언(purpose 범위)이 identity.purpose.{목적} 알림 정의로 변환·등록된다.
     *
     * @scenario surface=extension_compat
     *
     * @effects legacy_declaration_converted_to_identity_definition
     *
     * @return void 반환값 없음
     */
    public function test_legacy_declaration_is_registered_as_identity_definition(): void
    {
        $this->sync($this->makeFakeModule([$this->message('purpose', 'fake_purpose')]));

        $definition = NotificationDefinition::where('type', 'identity.purpose.fake_purpose')->first();
        $this->assertNotNull($definition);
        $this->assertSame('identity', $definition->category);
        $this->assertSame('module', $definition->extension_type);
        $this->assertSame('fake-module', $definition->extension_identifier);
        $this->assertSame('fake-module', $definition->hook_prefix);
        $this->assertSame([], $definition->hooks);
        $this->assertSame('본문 {code}', $definition->templates()->where('channel', 'mail')->first()->body['ko']);
    }

    /**
     * 메일 외 provider 선언은 경고 후 메일 채널로 흡수되고, 다른 소유자와 type 이 겹치면 건너뛴다.
     *
     * @scenario surface=extension_compat
     *
     * @effects legacy_provider_absorbed_and_conflict_skipped
     *
     * @return void 반환값 없음
     */
    public function test_non_mail_provider_is_absorbed_and_conflict_is_skipped(): void
    {
        Log::spy();

        $foreign = $this->message('purpose', 'kcp_only');
        $foreign['provider_id'] = 'nhnkcp';
        $conflict = $this->message('purpose', 'signup'); // 코어가 이미 가진 identity.purpose.signup

        $this->sync($this->makeFakeModule([$foreign, $conflict]));

        $this->assertDatabaseHas('notification_definitions', ['type' => 'identity.purpose.kcp_only', 'extension_identifier' => 'fake-module']);
        $this->assertDatabaseHas('notification_definitions', ['type' => 'identity.purpose.signup', 'extension_identifier' => 'core']);

        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, '메일 외 프로바이더'))->once();
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, '다른 소유자'))->once();
    }

    /**
     * 같은 type 을 getNotificationDefinitions() 가 선언했으면 그 선언이 우선한다.
     *
     * @scenario surface=extension_compat
     *
     * @effects explicit_notification_declaration_wins
     *
     * @return void 반환값 없음
     */
    public function test_explicit_notification_declaration_wins(): void
    {
        $module = $this->makeFakeModule([$this->message('purpose', 'dup')], [
            [
                'type' => 'identity.purpose.dup',
                'category' => 'identity',
                'hook_prefix' => 'fake-module',
                'hooks' => [],
                'channels' => ['mail'],
                'name' => ['ko' => '새 선언', 'en' => 'New declaration'],
                'templates' => [['channel' => 'mail', 'subject' => ['ko' => '새', 'en' => 'New'], 'body' => ['ko' => '새 {code}', 'en' => 'New {code}']]],
            ],
        ]);

        $this->sync($module);

        $this->assertSame('새 선언', NotificationDefinition::where('type', 'identity.purpose.dup')->value('name')['ko']);
    }

    /**
     * 운영자가 고친 이름은 재동기화(모듈 업데이트)에서 보존된다.
     *
     * @scenario surface=extension_compat
     *
     * @effects legacy_declaration_user_overrides_preserved
     *
     * @return void 반환값 없음
     */
    public function test_user_overrides_are_preserved_across_resync(): void
    {
        $module = $this->makeFakeModule([$this->message('purpose', 'override_test')]);
        $this->sync($module);

        $definition = NotificationDefinition::where('type', 'identity.purpose.override_test')->firstOrFail();
        $definition->update(['name' => ['ko' => '운영자 수정', 'en' => 'Edited']]);

        $this->sync($module);

        $this->assertSame('운영자 수정', NotificationDefinition::whereKey($definition->id)->value('name')['ko']);
    }

    /**
     * ModuleManager 의 알림 정의 동기화를 실행합니다.
     *
     * @param  AbstractModule  $module  대상 모듈
     * @return void 반환값 없음
     */
    private function sync(AbstractModule $module): void
    {
        $manager = app(ModuleManager::class);
        $method = new \ReflectionMethod($manager, 'syncModuleNotificationDefinitions');
        $method->invoke($manager, $module);
        app(NotificationDefinitionService::class)->invalidateAllCache();
    }

    /**
     * 옛 형식 메시지 선언 1건.
     *
     * @param  string  $scopeType  범위
     * @param  string  $scopeValue  범위 값
     * @return array<string, mixed> 선언
     */
    private function message(string $scopeType, string $scopeValue): array
    {
        return [
            'provider_id' => 'g7:core.mail',
            'scope_type' => $scopeType,
            'scope_value' => $scopeValue,
            'name' => ['ko' => '가짜 목적', 'en' => 'Fake Purpose'],
            'channels' => ['mail'],
            'templates' => [
                ['channel' => 'mail', 'subject' => ['ko' => '제목', 'en' => 'Subject'], 'body' => ['ko' => '본문 {code}', 'en' => 'Body {code}']],
            ],
        ];
    }

    /**
     * 테스트용 가짜 모듈 인스턴스를 만듭니다.
     *
     * @param  array<int, array<string, mixed>>  $messages  getIdentityMessages() 결과
     * @param  array<int, array<string, mixed>>  $notifications  getNotificationDefinitions() 결과
     * @return AbstractModule 가짜 모듈
     */
    private function makeFakeModule(array $messages, array $notifications = []): AbstractModule
    {
        $module = new class($messages, $notifications) extends AbstractModule
        {
            /**
             * 옛 메시지 선언과 알림 정의 선언을 보관합니다.
             *
             * @param  array  $messageData  옛 메시지 선언
             * @param  array  $notificationData  알림 정의 선언
             */
            public function __construct(private array $messageData, private array $notificationData) {}

            /**
             * 모듈 이름.
             *
             * @return string|array 이름
             */
            public function getName(): string|array
            {
                return 'Fake Module';
            }

            /**
             * 모듈 설명.
             *
             * @return string|array 설명
             */
            public function getDescription(): string|array
            {
                return 'Test module for IDV message compat';
            }

            /**
             * 모듈 버전.
             *
             * @return string 버전
             */
            public function getVersion(): string
            {
                return '1.0.0';
            }

            /**
             * 옛 본인인증 메시지 선언.
             *
             * @return array 선언
             */
            public function getIdentityMessages(): array
            {
                return $this->messageData;
            }

            /**
             * 알림 정의 선언.
             *
             * @return array 선언
             */
            public function getNotificationDefinitions(): array
            {
                return $this->notificationData;
            }
        };

        $reflection = new \ReflectionClass($module);
        while ($reflection && ! $reflection->hasProperty('identifier')) {
            $reflection = $reflection->getParentClass();
        }
        $reflection->getProperty('identifier')->setValue($module, 'fake-module');

        return $module;
    }
}
