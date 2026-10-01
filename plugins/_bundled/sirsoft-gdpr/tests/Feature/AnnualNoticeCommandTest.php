<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Feature;

use App\Contracts\Notifications\ChannelReadinessCheckerInterface;
use App\Enums\PermissionType;
use App\Enums\UserStatus;
use App\Extension\Helpers\NotificationSyncHelper;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Listeners\NotificationHookListener;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\PrivacyManualRun;
use App\Models\Role;
use App\Models\User;
use App\Notifications\GenericNotification;
use App\Services\NotificationTemplateService;
use App\Services\PluginSettingsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Gdpr\Console\Commands\SendAnnualNotices;
use Plugins\Sirsoft\Gdpr\Enums\PrivacyNoticeStatus;
use Plugins\Sirsoft\Gdpr\Listeners\GdprAnnualNoticeListener;
use Plugins\Sirsoft\Gdpr\Models\GdprPrivacyNoticeLog;
use Plugins\Sirsoft\Gdpr\Plugin;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprPrivacyNoticeRepositoryInterface;
use Plugins\Sirsoft\Gdpr\Repositories\GdprPrivacyNoticeRepository;
use Plugins\Sirsoft\Gdpr\Services\GdprAnnualNoticeService;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 정기 통지의 대상·상한·불변 이력과 실제 알림 훅 체인을 검증합니다.
 *
 * @scenario flow=scheduled
 *
 * @effects notice_queued, channel_result_appended, duplicate_prevented, annual_notice_targets_match_the_dispatch_decision
 */
class AnnualNoticeCommandTest extends PluginTestCase
{
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
     * 트랜잭션과 실제 통지 훅 체인을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'sync']);
        $this->app->bind(GdprPrivacyNoticeRepositoryInterface::class, GdprPrivacyNoticeRepository::class);
        $this->app->make(Kernel::class)->registerCommand(new SendAnnualNotices);
        // 기준 DB의 오래된 회원이 배치 대상에 섞이지 않도록 테스트 트랜잭션 안에서 격리합니다.
        User::query()->update(['created_at' => now()]);
        $this->configure(false);
        HookManager::clearAction('sirsoft-gdpr.privacy.annual_notice_due');
        HookManager::clearFilter('sirsoft-gdpr.notification.extract_data');
        HookListenerRegistrar::clear();
        HookListenerRegistrar::register(GdprAnnualNoticeListener::class);
        $data = (new Plugin)->getNotificationDefinitions()[0];
        $data['extension_type'] = 'plugin';
        $data['extension_identifier'] = 'sirsoft-gdpr';
        $helper = app(NotificationSyncHelper::class);
        $definition = $helper->syncDefinition($data);
        foreach ($data['templates'] as $template) {
            $helper->syncTemplate($definition->id, $template);
        }
        app(NotificationHookListener::class)->registerDynamicHooks();
    }

    /**
     * 테스트에서 사용할 통지 설정을 저장합니다.
     *
     * @param  bool  $enabled  자동 통지 사용 여부
     * @param  int  $limit  실행당 상한
     * @param  array  $channels  채널 목록
     * @return void 반환값 없음
     */
    private function configure(bool $enabled, int $limit = 500, array $channels = ['database']): void
    {
        app(PluginSettingsService::class)->save('sirsoft-gdpr', ['annual_notice' => ['enabled' => $enabled, 'batch_size' => $limit, 'channels' => $channels, 'introduction' => []]]);
    }

    /**
     * 지정한 관리자 권한만 가진 전용 역할의 관리자를 만듭니다.
     *
     * 공유 `admin` 역할을 쓰지 않는다 — 기준 DB 의 그 역할은 회원 읽기를 이미 가질 수 있어
     * 「통지 조회만 있는 관리자」를 만들 수 없다.
     *
     * @param  array<int, string>  $permissions  권한 식별자 목록
     * @param  array<string, string>  $scopes  권한 식별자 => 적용 범위(`self`·`role`) — 없으면 전체
     * @return User 관리자
     */
    private function adminWith(array $permissions, array $scopes = []): User
    {
        $role = Role::create([
            'identifier' => 'gdpr_target_test_'.uniqid(),
            'name' => ['ko' => '시험 관리자', 'en' => 'Test admin'],
            'description' => ['ko' => '시험 관리자', 'en' => 'Test admin'],
            'is_active' => true,
        ]);
        $role->permissions()->sync(collect($permissions)->map(fn (string $identifier) => Permission::firstOrCreate(
            ['identifier' => $identifier],
            ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => PermissionType::Admin],
        )->id)->all());

        foreach ($scopes as $identifier => $scope) {
            $role->permissions()->updateExistingPivot(Permission::where('identifier', $identifier)->value('id'), ['scope_type' => $scope]);
        }

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * 연간 통지 대상이 될 가입 경과 회원을 생성합니다.
     *
     * @param  array  $attributes  테스트 회원 속성
     * @return User 처리 결과
     */
    private function oldUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['status' => UserStatus::Active, 'created_at' => now()->subYears(2)], $attributes));
    }

    /**
     * 대상 건수·명단(관리자 화면)이 발송 판정(후보 + 회원별 발송할 채널)과 같은 모집단이다.
     * 꺼져 있어도 세고, 명단은 통지 조회 + 회원 읽기가 있어야 본다.
     *
     * @return void 반환값 없음
     *
     * @scenario flow=scheduled
     *
     * @effects annual_notice_targets_match_the_dispatch_decision
     */
    public function test_due_count_and_targets_match_the_dispatch_decision(): void
    {
        $this->configure(false, 500, ['mail', 'database']);

        $never = $this->oldUser();
        $both = $this->oldUser();
        $mailOnly = $this->oldUser();
        $pendingBoth = $this->oldUser();
        $this->oldUser(['status' => UserStatus::Blocked]);
        User::factory()->create(['status' => UserStatus::Active, 'created_at' => now()->subMonths(3)]);

        $log = fn (User $user, PrivacyNoticeStatus $status, array $channels, ?\DateTimeInterface $sentAt = null, ?string $attempt = null) => GdprPrivacyNoticeLog::query()->create([
            'user_id' => $user->id, 'attempt_id' => $attempt ?? (string) Str::uuid(), 'status' => $status,
            'channels' => $channels, 'is_test' => false, 'sent_at' => $sentAt, 'snapshot' => [],
        ]);
        $log($both, PrivacyNoticeStatus::Sent, ['mail', 'database'], now()->subMonths(2));
        $log($mailOnly, PrivacyNoticeStatus::Sent, ['mail'], now()->subMonths(2));
        $log($pendingBoth, PrivacyNoticeStatus::Queued, ['mail', 'database']);

        $repository = app(GdprPrivacyNoticeRepositoryInterface::class);
        $service = app(GdprAnnualNoticeService::class);

        // 발송 판정 그대로 — 후보마다 발송할 채널이 남았는지.
        $expected = [];
        foreach ($repository->candidates() as $candidate) {
            if ($repository->dueChannels($candidate->id, ['mail', 'database']) !== []) {
                $expected[] = $candidate->id;
            }
        }
        sort($expected);

        $actual = $repository->dueQuery(['mail', 'database'])->pluck('id')->sort()->values()->all();
        $this->assertSame($expected, $actual, '관리자 화면 모집단 = 발송 판정');
        $this->assertContains($never->id, $actual);
        $this->assertContains($mailOnly->id, $actual, '사이트 알림 몫이 남았다');
        $this->assertNotContains($both->id, $actual);
        $this->assertNotContains($pendingBoth->id, $actual, '결과가 나지 않은 접수가 있으면 대상이 아니다');
        $this->assertSame(count($expected), $service->dueCount(), '꺼져 있어도 건수를 센다');
        $this->assertSame([], $repository->dueQuery([])->pluck('id')->all(), '채널이 없으면 아무도 대상이 아니다');

        $settingsOnly = $this->adminWith(['sirsoft-gdpr.privacy.view']);
        $this->actingAs($settingsOnly)->getJson('/api/plugins/sirsoft-gdpr/admin/annual-notice/preview')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.count', count($expected))
            ->assertJsonPath('data.can_view_targets', false);
        $this->actingAs($settingsOnly)->getJson('/api/plugins/sirsoft-gdpr/admin/annual-notice/targets')->assertForbidden();

        // 회원 조회를 본인 범위로 위임받은 관리자 — 권한은 있지만 명단은 전체 회원이라 열지 않는다.
        $scoped = $this->adminWith(['sirsoft-gdpr.privacy.view', 'core.users.read'], ['core.users.read' => 'self']);
        $this->actingAs($scoped)->getJson('/api/plugins/sirsoft-gdpr/admin/annual-notice/preview')
            ->assertOk()
            ->assertJsonPath('data.can_view_targets', false);
        $this->actingAs($scoped)->getJson('/api/plugins/sirsoft-gdpr/admin/annual-notice/targets')->assertForbidden();

        $viewer = $this->adminWith(['sirsoft-gdpr.privacy.view', 'core.users.read']);
        $list = $this->actingAs($viewer)->getJson('/api/plugins/sirsoft-gdpr/admin/annual-notice/targets?per_page=100')->assertOk();
        $this->assertSame($expected, collect($list->json('data.data'))->pluck('id')->sort()->values()->all());
        $this->assertSame(['title', 'subtitle', 'date'], array_keys($list->json('data.columns')));
    }

    /**
     * 해당 통지 조건과 후속 결과를 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario flow=scheduled
     *
     * @effects notice_queued, channel_result_appended, duplicate_prevented
     */
    public function test_disabled_scheduled_command_does_not_enqueue(): void
    {
        $user = $this->oldUser();
        $this->artisan('sirsoft-gdpr:send-annual-notices --scheduled')->assertSuccessful();
        $this->assertFalse(GdprPrivacyNoticeLog::where('user_id', $user->id)->exists());
    }

    /**
     * 해당 통지 조건과 후속 결과를 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario flow=scheduled
     *
     * @effects notice_queued, channel_result_appended, duplicate_prevented
     */
    public function test_only_active_members_at_least_one_year_old_are_selected_and_rerun_is_idempotent(): void
    {
        $this->configure(true);
        $eligible = $this->oldUser();
        $this->oldUser(['status' => UserStatus::Blocked]);
        $this->oldUser(['status' => UserStatus::Inactive]);
        $this->oldUser(['status' => UserStatus::Withdrawn]);
        $this->oldUser(['status' => UserStatus::PendingVerification]);
        User::factory()->create(['status' => UserStatus::Active]);
        $service = app(GdprAnnualNoticeService::class);
        $this->assertSame(1, $service->run());
        $this->assertSame(0, $service->run());
        $this->assertTrue(GdprPrivacyNoticeLog::where('user_id', $eligible->id)->where('status', PrivacyNoticeStatus::Sent)->exists());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $eligible->id)->count());
    }

    /**
     * 해당 통지 조건과 후속 결과를 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario flow=scheduled
     *
     * @effects notice_queued, channel_result_appended, duplicate_prevented
     */
    public function test_batch_limit_leaves_remaining_member_for_next_run(): void
    {
        $this->configure(true, 1);
        $this->oldUser();
        $this->oldUser();
        $service = app(GdprAnnualNoticeService::class);
        $this->assertSame(1, $service->run());
        $this->assertSame(1, $service->run());
        $this->assertSame(0, $service->run());
    }

    /**
     * 해당 통지 조건과 후속 결과를 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario flow=scheduled
     *
     * @effects notice_queued, channel_result_appended, duplicate_prevented
     */
    public function test_test_notice_does_not_reset_annual_period_and_only_targets_self(): void
    {
        $this->configure(true);
        $user = $this->oldUser();
        $service = app(GdprAnnualNoticeService::class);
        $this->assertNotNull($service->sendTest($user));
        $this->assertSame(1, $service->run());
        $this->assertSame(2, GdprPrivacyNoticeLog::where('user_id', $user->id)->where('status', PrivacyNoticeStatus::Sent)->count());
    }

    /**
     * 해당 통지 조건과 후속 결과를 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario flow=scheduled
     *
     * @effects notice_queued, channel_result_appended, duplicate_prevented
     */
    public function test_queued_request_blocks_duplicate_until_result_and_results_are_append_only(): void
    {
        $this->configure(true);
        $user = $this->oldUser();
        HookManager::clearAction('sirsoft-gdpr.privacy.annual_notice_due');
        $service = app(GdprAnnualNoticeService::class);
        $this->assertSame(1, $service->run());
        $queued = GdprPrivacyNoticeLog::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(0, $service->run());
        $context = ['extension_identifier' => 'sirsoft-gdpr', 'notification_type' => 'annual_privacy_notice', 'data' => ['privacy_notice_attempt' => $queued->attempt_id]];
        $service->recordResult('database', $context, PrivacyNoticeStatus::Sent);
        $service->recordResult('database', $context, PrivacyNoticeStatus::Sent);
        $this->assertSame(PrivacyNoticeStatus::Queued, $queued->fresh()->status);
        $this->assertSame(2, GdprPrivacyNoticeLog::where('user_id', $user->id)->count());
    }

    /**
     * 해당 통지 조건과 후속 결과를 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario flow=scheduled
     *
     * @effects notice_queued, channel_result_appended, duplicate_prevented
     */
    public function test_failure_can_retry_and_sent_channel_is_not_repeated(): void
    {
        $this->configure(true, 500, ['mail', 'database']);
        $user = $this->oldUser();
        HookManager::clearAction('sirsoft-gdpr.privacy.annual_notice_due');
        $service = app(GdprAnnualNoticeService::class);
        $this->assertSame(1, $service->run());
        $queued = GdprPrivacyNoticeLog::where('user_id', $user->id)->firstOrFail();
        $context = ['extension_identifier' => 'sirsoft-gdpr', 'notification_type' => 'annual_privacy_notice', 'data' => ['privacy_notice_attempt' => $queued->attempt_id]];
        $service->recordResult('mail', $context, PrivacyNoticeStatus::Failed);
        $service->recordResult('database', $context, PrivacyNoticeStatus::Sent);
        $this->assertSame(1, $service->run());
        $this->assertSame(['mail'], GdprPrivacyNoticeLog::where('user_id', $user->id)->latest('id')->first()->channels);
    }

    /**
     * 해당 통지 조건과 후속 결과를 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario flow=scheduled
     *
     * @effects notice_queued, channel_result_appended, duplicate_prevented
     */
    public function test_anonymization_preserves_event_rows(): void
    {
        $user = $this->oldUser();
        $service = app(GdprAnnualNoticeService::class);
        $attempt = $service->sendTest($user);
        $count = GdprPrivacyNoticeLog::where('attempt_id', $attempt)->count();
        $service->anonymize($user->id);
        $this->assertSame($count, GdprPrivacyNoticeLog::where('attempt_id', $attempt)->whereNull('user_id')->count());
    }

    /**
     * 해당 통지 조건과 후속 결과를 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario flow=scheduled
     *
     * @effects notice_queued, channel_result_appended, duplicate_prevented
     */
    public function test_last_success_at_year_boundary_becomes_due(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->configure(true);
        $user = $this->oldUser();
        $service = app(GdprAnnualNoticeService::class);
        $this->assertSame(1, $service->run());
        $this->travel(364)->days();
        $this->assertSame(0, $service->run());
        $this->travel(2)->days();
        $this->assertSame(1, $service->run());
        $this->assertSame(2, GdprPrivacyNoticeLog::where('user_id', $user->id)->where('status', PrivacyNoticeStatus::Sent)->count());
    }

    /**
     * 설정 경계값과 본인 시험 발송 권한을 검증합니다.
     *
     * @scenario flow=api
     *
     * @effects settings_validated, self_test_only, permission_enforced
     *
     * @return void 반환값 없음
     */
    public function test_settings_boundaries_and_test_endpoint_permissions(): void
    {
        $url = '/api/plugins/sirsoft-gdpr/admin';
        $this->getJson($url.'/notice-log')->assertUnauthorized();
        $member = User::factory()->create();
        $this->actingAs($member, 'sanctum')->postJson($url.'/annual-notice/test')->assertForbidden();
        $operator = $this->createPrivacyOperatorUser();
        $this->actingAs($operator, 'sanctum');
        foreach ([0, 1001] as $limit) {
            $this->putJson($url.'/settings', ['annual_notice' => ['enabled' => true, 'channels' => ['database'], 'batch_size' => $limit]])->assertUnprocessable()->assertJsonValidationErrors('annual_notice.batch_size');
        }
        $this->putJson($url.'/settings', ['annual_notice' => ['enabled' => true, 'channels' => [], 'batch_size' => 500]])->assertUnprocessable()->assertJsonValidationErrors('annual_notice.channels');
        foreach ([1, 500, 1000] as $limit) {
            $this->putJson($url.'/settings', ['annual_notice' => ['enabled' => false, 'channels' => ['database'], 'batch_size' => $limit, 'introduction' => ['ko' => '안내', 'en' => 'Notice']]])->assertOk()->assertJsonPath('data.settings.annual_notice.batch_size', $limit);
            // Feature 테스트는 여러 HTTP 요청에서도 컨트롤러 객체를 재사용합니다.
            // 실제 PHP 요청 경계처럼 조회 컨트롤러의 인메모리 설정 캐시를 새로 만듭니다.
            foreach (Route::getRoutes() as $route) {
                $route->flushController();
            }
            $this->getJson($url.'/settings')->assertOk()->assertJsonPath('data.settings.annual_notice.batch_size', $limit);
        }
        // 토글을 끈 상태에서는 화면이 하위 입력을 비활성화하므로 빈 값이 올라온다.
        // 이때 422 로 막으면 운영자가 기능을 끌 수 없고, 빈 값을 그대로 저장하면 상한이 사라진다.
        // 채널까지 함께 단언한다 — 상한만 재면 채널 복원 분기를 지워도 green 이고, 채널이 비면
        // 시험 발송까지 막힌다(끈 뒤 다시 켰을 때 드러나는 형태라 화면에서는 늦게 보인다).
        $this->putJson($url.'/settings', ['annual_notice' => ['enabled' => false, 'channels' => [], 'batch_size' => '', 'introduction' => []]])
            ->assertOk()
            ->assertJsonPath('data.settings.annual_notice.batch_size', 1000)
            ->assertJsonPath('data.settings.annual_notice.channels', ['database']);
        foreach (Route::getRoutes() as $route) {
            $route->flushController();
        }
        $this->getJson($url.'/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.annual_notice.channels', ['database'])
            ->assertJsonPath('data.settings.annual_notice.batch_size', 1000);
        // 검증 문구에 필드 키(annual notice.batch size)가 아니라 운영자용 라벨이 나와야 한다.
        $message = $this->putJson($url.'/settings', ['annual_notice' => ['enabled' => true, 'channels' => ['database'], 'batch_size' => 1001]])
            ->assertUnprocessable()
            ->json('errors')['annual_notice.batch_size'][0];
        $this->assertStringNotContainsString('annual notice', $message);
        $this->assertStringContainsString(__('sirsoft-gdpr::messages.settings.fields.annual_notice_batch_size.label'), $message);
        $this->postJson($url.'/annual-notice/test', ['user_id' => $member->id])->assertOk();
        $this->assertTrue(GdprPrivacyNoticeLog::where('user_id', $operator->id)->where('is_test', true)->exists());
        $this->assertFalse(GdprPrivacyNoticeLog::where('user_id', $member->id)->exists());
        $this->getJson($url.'/notice-log')->assertOk()->assertJsonStructure(['data' => ['data', 'pagination' => ['total', 'current_page', 'last_page']]]);
    }

    /**
     * 해당 통지 조건과 후속 결과를 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario flow=scheduled
     *
     * @effects notice_queued, channel_result_appended, duplicate_prevented
     */
    public function test_recipient_locale_and_domain_section_are_in_frozen_snapshot(): void
    {
        $user = $this->oldUser(['language' => 'en']);
        HookManager::addFilter('core.privacy.notice_sections', fn (array $sections, string $locale) => [...$sections, ['title' => 'Orders', 'body' => 'Five years ('.$locale.')']]);
        $service = app(GdprAnnualNoticeService::class);
        $attempt = $service->sendTest($user);
        $log = GdprPrivacyNoticeLog::where('attempt_id', $attempt)->firstOrFail();
        $this->assertSame('en', $log->snapshot['locale']);
        $this->assertSame('Five years (en)', $log->snapshot['sections'][0]['body']);
        $this->assertArrayNotHasKey('email', $log->snapshot);
        $notification = DB::table('notifications')->where('notifiable_id', $user->id)->first();
        $this->assertStringContainsString('Annual privacy notice', $notification->data);
        $this->assertStringContainsString('Five years (en)', json_decode($notification->data, true)['body']);
    }

    /**
     * 통지문의 처리방침 링크는 운영자가 적은 주소를 쓴다 — 사이트 경로는 앱 주소를 붙인 절대 주소로,
     * https 주소는 그대로, 비었으면 빈 값으로 접수 시점 스냅샷에 얼린다. 페이지 모듈 주소 규칙
     * (`/page/{slug}`)으로 조합하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario flow=scheduled
     *
     * @effects notice_queued, annual_notice_links_operator_privacy_policy_url
     */
    public function test_privacy_policy_link_in_snapshot_follows_operator_url(): void
    {
        $settings = app(PluginSettingsService::class);
        $cases = [
            '/privacy' => url('/privacy'),
            'https://example.com/privacy' => 'https://example.com/privacy',
            '' => '',
        ];

        try {
            foreach ($cases as $stored => $expected) {
                $settings->save('sirsoft-gdpr', ['privacy_policy_url' => $stored]);
                // 설정 서비스는 인스턴스마다 값을 캐시하므로(싱글턴 아님) 경우마다 새로 해석한다 —
                // 루프 밖에서 한 번 만들면 첫 경우의 값을 계속 읽어 두 번째 경우부터 검증되지 않는다.
                $attempt = app(GdprAnnualNoticeService::class)->sendTest($this->oldUser());
                $log = GdprPrivacyNoticeLog::where('attempt_id', $attempt)->firstOrFail();

                $this->assertSame($expected, $log->snapshot['privacy_policy_url'], '저장값: '.json_encode($stored));
                $this->assertStringNotContainsString('/page/', (string) $log->snapshot['privacy_policy_url']);
            }
        } finally {
            $settings->save('sirsoft-gdpr', ['privacy_policy_url' => '']);
        }
    }

    /**
     * 비활성 템플릿의 접수가 실패로 닫혀 복구 후 재선정되는지 검증합니다.
     *
     * 트러블슈팅 사례 [case:annual-notice-skipped-channel-pending] 의 회귀 테스트다 —
     * 채널이 준비되지 않아 send 가 아예 호출되지 않으면 성공·실패 훅도 없어서, 접수만
     * 보고 중복을 막으면 그 회원은 다음 배치에서도 영영 빠진다.
     *
     * @scenario flow=scheduled
     *
     * @effects channel_result_appended, duplicate_prevented
     *
     * @return void 반환값 없음
     */
    public function test_disabled_template_can_retry_after_reactivation(): void
    {
        $user = $this->oldUser();
        $this->configure(true);
        $template = NotificationTemplate::whereHas('definition', fn ($query) => $query->where('type', 'annual_privacy_notice'))->where('channel', 'database')->firstOrFail();
        $templates = app(NotificationTemplateService::class);
        $templates->updateTemplate($template, ['is_active' => false]);
        $service = app(GdprAnnualNoticeService::class);
        $this->assertSame(1, $service->run());
        $this->assertSame(1, GdprPrivacyNoticeLog::where('user_id', $user->id)->where('status', PrivacyNoticeStatus::Failed)->count());
        $templates->updateTemplate($template->fresh(), ['is_active' => true]);
        $this->assertSame(1, $service->run());
        $this->assertSame(1, GdprPrivacyNoticeLog::where('user_id', $user->id)->where('status', PrivacyNoticeStatus::Sent)->count());
        $this->assertSame(0, $service->run());
    }

    /**
     * 코어 선택 채널의 키 부재·빈 배열·단일 채널 계약을 실제 훅 디스패치로 검증합니다.
     *
     * @scenario flow=scheduled
     *
     * @effects duplicate_prevented
     *
     * @return void 반환값 없음
     */
    public function test_core_dispatch_channel_context_preserves_legacy_and_empty_selection(): void
    {
        $user = $this->oldUser();
        Notification::fake();
        foreach ([null, [], ['database']] as $selected) {
            HookManager::clearFilter('sirsoft-gdpr.notification.extract_data');
            HookManager::addFilter('sirsoft-gdpr.notification.extract_data', fn () => [
                'notifiable' => $user, 'data' => [],
                'context' => array_merge(['trigger_user' => $user, 'trigger_user_id' => $user->id], $selected === null ? [] : ['channels' => $selected]),
            ]);
            HookManager::doAction('sirsoft-gdpr.privacy.annual_notice_due', $user, 'contract-probe');
            Notification::assertSentToTimes($user, GenericNotification::class, $selected === ['database'] ? 2 : 1);
        }
    }

    /**
     * 실제 명령의 OFF·ON·재실행 건수를 격리된 대상 회원으로 확인합니다.
     *
     * @scenario flow=scheduled
     *
     * @effects notice_queued, duplicate_prevented
     *
     * @return void 반환값 없음
     */
    public function test_command_toggle_and_repeat_are_measured_with_isolated_recipient(): void
    {
        $user = $this->oldUser();
        $this->artisan('sirsoft-gdpr:send-annual-notices --scheduled')->expectsOutput('0명 통지 접수')->assertSuccessful();
        $this->configure(true);
        $this->artisan('sirsoft-gdpr:send-annual-notices --scheduled')->expectsOutput('1명 통지 접수')->assertSuccessful();
        $this->artisan('sirsoft-gdpr:send-annual-notices --scheduled')->expectsOutput('0명 통지 접수')->assertSuccessful();
        $this->assertSame(2, GdprPrivacyNoticeLog::where('user_id', $user->id)->count());
    }

    /**
     * 외부 전송 없이 로그 메일러로 두 채널의 실제 본문과 결과를 확인합니다.
     *
     * @scenario flow=scheduled
     *
     * @effects channel_result_appended, self_test_only
     *
     * @return void 반환값 없음
     */
    public function test_mail_log_contains_domain_section_and_both_channels_succeed(): void
    {
        // 실행마다 새 경로를 쓴다 — 이전 실행의 로그가 남아 있으면 발송이 없어도 아래 단언이 통과한다.
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'g7-gdpr-mail-probe-'.uniqid().'.log';
        config(['mail.default' => 'log', 'mail.mailers.log.channel' => 's3_mail_probe',
            'logging.channels.s3_mail_probe' => ['driver' => 'single', 'path' => $path]]);
        app('mail.manager')->purge('log');
        $checker = $this->createMock(ChannelReadinessCheckerInterface::class);
        $checker->method('isReady')->willReturn(true);
        $this->app->instance(ChannelReadinessCheckerInterface::class, $checker);
        $this->configure(false, 500, ['mail', 'database']);
        HookManager::addFilter('core.privacy.notice_sections', fn (array $sections) => [...$sections, ['title' => 'S3 section', 'body' => 'S3 retention verification']]);
        $user = $this->oldUser(['language' => 'en']);
        $attempt = app(GdprAnnualNoticeService::class)->sendTest($user);
        $this->assertSame(2, GdprPrivacyNoticeLog::where('attempt_id', $attempt)->where('status', PrivacyNoticeStatus::Sent)->count());
        $this->assertFileExists($path);
        $this->assertStringContainsString('S3 retention verification', file_get_contents($path));
        @unlink($path);
    }

    /**
     * 관리자 직권 발송: 코어 직권 실행 API 로 접수하면 연간 통지 토글이 꺼져 있어도 발송 상한만큼 접수하고
     * 남은 인원을 기록에 남긴다. 통지 조회 권한만으로는 실행하지 못한다.
     *
     * @return void 반환값 없음
     *
     * @scenario flow=api
     *
     * @effects manual_send_ignores_the_toggle_and_keeps_the_batch_limit, permission_enforced
     */
    public function test_manual_send_ignores_the_toggle_and_keeps_the_batch_limit(): void
    {
        $this->configure(false, 1);
        $this->oldUser();
        $this->oldUser();

        $viewer = $this->adminWith(['sirsoft-gdpr.privacy.view']);
        $this->actingAs($viewer)->postJson('/api/admin/privacy/manual-runs/sirsoft-gdpr:annual_notice')->assertForbidden();

        $runner = $this->adminWith(['sirsoft-gdpr.privacy.update']);
        $preview = $this->actingAs($runner)->getJson('/api/admin/privacy/manual-runs/sirsoft-gdpr:annual_notice/preview')->assertOk();
        $this->assertSame(2, $preview->json('data.count'));
        $this->assertFalse($preview->json('data.enabled'), '자동 발송은 꺼진 상태');
        $this->assertSame('notify', $preview->json('data.mode'));

        $response = $this->actingAs($runner)->postJson('/api/admin/privacy/manual-runs/sirsoft-gdpr:annual_notice')->assertStatus(202);

        $run = PrivacyManualRun::findOrFail($response->json('data.id'));
        $this->assertSame('completed', $run->status->value);
        $this->assertSame(1, $run->processed_count, '발송 상한 1 — 한 번에 한 명만 접수');
        $this->assertSame(1, $run->remaining_count, '남은 인원은 다시 실행하면 이어서 접수된다');
        $this->assertSame(0, app(GdprAnnualNoticeService::class)->run(), '자동 경로는 여전히 토글을 본다');
    }

    /**
     * 언어팩을 끈 뒤에도 설정을 저장할 수 있다 — 꺼진 언어의 통지 머리말이 저장을 막지 않고, 지워지지도 않는다.
     *
     * 화면은 폼 전체를 보내므로 예전에 입력한 ja 머리말이 늘 함께 올라온다. 통지가 꺼져 있으면
     * 머리말 입력이 잠겨 그 칸을 지울 수도 없어, 지원 목록 밖 키를 거절하면 어떤 설정도 저장할 수 없었다.
     * 값은 보존되어 언어팩을 다시 켜면 그대로 돌아오고, 길이 상한은 모든 키에 그대로 걸린다.
     *
     * @return void 반환값 없음
     *
     * @scenario flow=api
     *
     * @effects stale_locale_introduction_does_not_block_save
     */
    public function test_settings_save_keeps_introduction_of_disabled_language_pack(): void
    {
        config(['app.translatable_locales' => ['ko', 'en']]);
        $url = '/api/plugins/sirsoft-gdpr/admin/settings';
        $this->actingAs($this->createPrivacyOperatorUser(), 'sanctum');
        $introduction = ['ko' => '안내', 'en' => 'Notice', 'ja' => 'お知らせ'];

        $this->putJson($url, [
            'annual_notice' => ['enabled' => false, 'channels' => ['database'], 'batch_size' => 500, 'introduction' => $introduction],
            'legal_entity_name' => '주식회사 예시',
        ])->assertOk()->assertJsonPath('data.settings.annual_notice.introduction', $introduction);

        foreach (Route::getRoutes() as $route) {
            $route->flushController();
        }
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.settings.annual_notice.introduction.ja', 'お知らせ')
            ->assertJsonPath('data.settings.legal_entity_name', '주식회사 예시');

        $this->putJson($url, [
            'annual_notice' => ['enabled' => false, 'channels' => ['database'], 'batch_size' => 500, 'introduction' => ['ko' => '안내', 'ja' => str_repeat('あ', 2001)]],
        ])->assertUnprocessable()->assertJsonValidationErrors('annual_notice.introduction.ja');
    }
}
