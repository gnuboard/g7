<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Feature;

use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\NicknameRequirement;
use App\Enums\UserStatus;
use App\Extension\Helpers\NotificationSyncHelper;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Listeners\NotificationHookListener;
use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\Gdpr\Listeners\GdprAnnualNoticeListener;
use Plugins\Sirsoft\Gdpr\Plugin;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprPrivacyNoticeRepositoryInterface;
use Plugins\Sirsoft\Gdpr\Repositories\GdprPrivacyNoticeRepository;
use Plugins\Sirsoft\Gdpr\Services\GdprAnnualNoticeService;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;
use Tests\Helpers\MocksChannelReadiness;

/**
 * 연간 개인정보 이용내역 안내의 받는 사람 이름 (#490)
 *
 * 안내는 회원 본인에게 간다. 알림 데이터의 받는 사람 이름(`recipient_name`)은 자리표시(`{recipient_name}`)이고, 코어가 받는
 * 사람과 채널마다 표시 이름으로 푼다. 설정을 바꾸지 않은 사이트는 수정 전과 같이 회원 이름이 나간다.
 */
class AnnualNoticeRecipientNameTest extends PluginTestCase
{
    use MocksChannelReadiness;

    /** @var array<string, mixed>|null 발송 직전 추출 데이터 */
    private ?array $captured = null;

    /**
     * 전 번들 migrate:fresh 대신 기존 테스트 스키마의 트랜잭션만 사용합니다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    /**
     * 실제 통지 훅 체인과 메일 채널을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync', 'mail.default' => 'array']);
        $this->app->bind(GdprPrivacyNoticeRepositoryInterface::class, GdprPrivacyNoticeRepository::class);
        app(PluginSettingsService::class)->save('sirsoft-gdpr', ['annual_notice' => ['enabled' => false, 'batch_size' => 500, 'channels' => ['mail'], 'introduction' => []]]);

        HookManager::clearAction('sirsoft-gdpr.privacy.annual_notice_due');
        HookManager::clearFilter('sirsoft-gdpr.notification.extract_data');
        HookListenerRegistrar::clear();
        HookListenerRegistrar::register(GdprAnnualNoticeListener::class);
        HookManager::addFilter('sirsoft-gdpr.notification.extract_data', function (array $extracted) {
            $this->captured = $extracted;

            return $extracted;
        }, 999);

        $data = (new Plugin)->getNotificationDefinitions()[0];
        $helper = app(NotificationSyncHelper::class);
        $definition = $helper->syncDefinition($data + ['extension_type' => 'plugin', 'extension_identifier' => 'sirsoft-gdpr']);
        foreach ($data['templates'] as $template) {
            $helper->syncTemplate($definition->id, $template);
        }
        app(NotificationHookListener::class)->registerDynamicHooks();

        // 시험 환경은 발신 주소가 없어 메일 채널이 준비 안 됨으로 건너뛴다. 준비 상태를 고정하고 array 메일러로 보낸다.
        $this->mockAllChannelsReady();
    }

    /**
     * 추출 데이터의 받는 사람 이름은 자리표시다.
     *
     * @scenario case=extract_recipient_name_placeholder
     *
     * @effects annual_notice_recipient_name_is_placeholder
     *
     * @return void 반환값 없음
     */
    public function test_extracted_recipient_name_is_placeholder(): void
    {
        $this->send();

        $this->assertSame('{recipient_name}', $this->captured['data']['recipient_name'] ?? null);
    }

    /**
     * 알림 영역을 닉네임으로 둔 사이트에서 실제로 나간 메일 본문의 받는 사람 이름은 닉네임이다.
     *
     * @scenario case=delivered_nickname_notification_area
     *
     * @effects delivered_mail_uses_notification_area_name
     *
     * @return void 반환값 없음
     */
    public function test_delivered_mail_uses_notification_area_name(): void
    {
        $this->areas(['core:notification' => ['mode' => 'fixed', 'kind' => 'nickname']]);

        $body = $this->send();

        $this->assertStringContainsString('길동이', $body);
        $this->assertStringNotContainsString('홍길동', $body);
        $this->assertStringNotContainsString('{recipient_name}', $body);
    }

    /**
     * 설정을 바꾸지 않은 사이트에서 실제로 나간 메일 본문의 받는 사람 이름은 수정 전과 같은 이름이다.
     *
     * @scenario case=delivered_default_site
     *
     * @effects delivered_mail_name_unchanged_on_default_site
     *
     * @return void 반환값 없음
     */
    public function test_delivered_mail_keeps_name_on_default_site(): void
    {
        $body = $this->send();

        $this->assertStringContainsString('홍길동', $body);
        $this->assertStringNotContainsString('{recipient_name}', $body);
    }

    /**
     * 이름과 닉네임이 있는 회원에게 시험 발송하고 발송 이력에 남은 메일 본문을 돌려줍니다.
     *
     * @return string 메일 본문
     */
    private function send(): string
    {
        $user = User::factory()->create(['name' => '홍길동', 'nickname' => '길동이', 'status' => UserStatus::Active]);

        $this->assertNotNull(app(GdprAnnualNoticeService::class)->sendTest($user));

        return (string) DB::table('notification_logs')->where('recipient_user_id', $user->id)->where('channel', 'mail')->latest('id')->value('body');
    }

    /**
     * 영역 설정을 바꿉니다 (사이트 기본과 관리자 화면은 지정하지 않으면 이름 고정).
     *
     * @param  array<string, array<string, mixed>>  $areas  영역 키 => 설정
     * @return void 반환값 없음
     */
    private function areas(array $areas): void
    {
        config(['g7_settings.core.member.display_name_areas' => $areas + [
            'core:site' => ['mode' => 'fixed', 'kind' => 'name'],
            'core:admin' => ['mode' => 'fixed', 'kind' => 'name'],
        ]]);
        app(DisplayNameAreaRegistry::class)->flush();
        app(NicknameRequirement::class)->flush();
    }
}
