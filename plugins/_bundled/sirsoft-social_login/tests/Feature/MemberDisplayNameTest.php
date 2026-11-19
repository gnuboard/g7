<?php

namespace Plugins\Sirsoft\SocialLogin\Tests\Feature;

use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\NicknameRequirement;
use App\Extension\Helpers\NotificationSyncHelper;
use App\Extension\HookManager;
use App\Listeners\NotificationHookListener;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Plugins\Sirsoft\SocialLogin\Models\SocialAccount;
use Plugins\Sirsoft\SocialLogin\Plugin;
use Plugins\Sirsoft\SocialLogin\Tests\PluginTestCase;
use Tests\Helpers\MocksChannelReadiness;

/**
 * 소셜 로그인 플러그인이 기록과 알림에 싣는 회원 이름 (#490)
 *
 * - 새로 남기는 활동 로그의 회원 이름(`user_name`)은 그 시점의 관리자 영역(`core:admin`) 표시 이름이다.
 * - 회원 본인에게 가는 알림의 받는 사람 이름(`name`)은 자리표시(`{recipient_name}`)이고, 코어가 받는 사람과 채널마다 푼다.
 * - 설정을 바꾸지 않은 사이트는 수정 전과 같이 회원 이름이 실린다.
 */
class MemberDisplayNameTest extends PluginTestCase
{
    use MocksChannelReadiness;

    /**
     * 카카오(어드민 키 포함)를 켜고 알림 정의를 시험 DB 에 넣습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync']);
        $this->enableKakao(['admin_key' => 'kakao-admin']);
        Http::fake(['kapi.kakao.com/v1/user/unlink' => Http::response(['id' => 1])]);

        $helper = app(NotificationSyncHelper::class);
        foreach ((new Plugin)->getNotificationDefinitions() as $data) {
            $definition = $helper->syncDefinition($data + ['extension_type' => 'plugin', 'extension_identifier' => 'sirsoft-social_login']);
            foreach ($data['templates'] as $template) {
                $helper->syncTemplate($definition->id, $template);
            }
        }
        app(NotificationHookListener::class)->registerDynamicHooks();
    }

    /**
     * 관리자 영역을 닉네임으로 둔 사이트에서 관리자 강제 해제의 활동 로그 회원 이름은 닉네임이다.
     *
     * @scenario case=activity_log_nickname_admin_area
     *
     * @effects activity_log_user_name_is_admin_area_name
     *
     * @return void 반환값 없음
     */
    public function test_activity_log_records_admin_area_name(): void
    {
        $this->areas(['core:admin' => ['mode' => 'fixed', 'kind' => 'nickname']]);
        $member = $this->linkedMember();

        $this->adminUnlink($member);

        $this->assertSame('길동이', $this->unlinkLog($member)->description_params['user_name']);
    }

    /**
     * 설정을 바꾸지 않은 사이트의 활동 로그 회원 이름은 수정 전과 같은 이름이다.
     *
     * @scenario case=activity_log_default_site
     *
     * @effects activity_log_user_name_unchanged_on_default_site
     *
     * @return void 반환값 없음
     */
    public function test_activity_log_keeps_name_on_default_site(): void
    {
        $member = $this->linkedMember();

        $this->adminUnlink($member);

        $this->assertSame('홍길동', $this->unlinkLog($member)->description_params['user_name']);
    }

    /**
     * 회원 대상 알림 2종의 받는 사람 이름은 자리표시이고, 대상 회원 문맥은 그대로 실린다.
     *
     * @scenario case=notification_member_name_placeholder
     *
     * @effects notification_member_recipient_name_is_placeholder
     *
     * @return void 반환값 없음
     */
    public function test_member_notification_name_is_placeholder(): void
    {
        $member = $this->member();
        $base = ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];

        foreach (['social_login_last_login_method_removed', 'social_login_link_removed_by_admin'] as $type) {
            $extracted = HookManager::applyFilters('sirsoft-social_login.notification.extract_data', $base, $type, [$member, 'kakao']);
            $this->assertSame('{recipient_name}', $extracted['data']['name'], $type);
            $this->assertSame($member->id, $extracted['context']['trigger_user_id'], $type);
        }
    }

    /**
     * 알림 영역을 닉네임으로 둔 사이트에서 실제로 나간 메일 본문의 받는 사람 이름은 닉네임이다.
     *
     * @scenario case=notification_delivered_nickname_notification_area
     *
     * @effects delivered_mail_uses_notification_area_name
     *
     * @return void 반환값 없음
     */
    public function test_delivered_mail_uses_notification_area_name(): void
    {
        $this->areas(['core:notification' => ['mode' => 'fixed', 'kind' => 'nickname']]);
        $member = $this->member();

        $body = $this->sendLastMethodMail($member);

        $this->assertStringContainsString('길동이', $body);
        $this->assertStringNotContainsString('홍길동', $body);
        $this->assertStringNotContainsString('{recipient_name}', $body);
    }

    /**
     * 설정을 바꾸지 않은 사이트에서 실제로 나간 메일 본문의 받는 사람 이름은 수정 전과 같은 이름이다.
     *
     * @scenario case=notification_delivered_default_site
     *
     * @effects delivered_mail_name_unchanged_on_default_site
     *
     * @return void 반환값 없음
     */
    public function test_delivered_mail_keeps_name_on_default_site(): void
    {
        $member = $this->member();

        $body = $this->sendLastMethodMail($member);

        $this->assertStringContainsString('홍길동', $body);
        $this->assertStringNotContainsString('{recipient_name}', $body);
    }

    /**
     * 이름과 닉네임이 있는 회원을 만듭니다.
     *
     * @return User 회원
     */
    private function member(): User
    {
        return User::factory()->create(['name' => '홍길동', 'nickname' => '길동이', 'email' => $this->uniqueEmail('dn')]);
    }

    /**
     * 카카오에 연결된 회원을 만듭니다.
     *
     * @return User 회원
     */
    private function linkedMember(): User
    {
        $member = $this->member();
        SocialAccount::query()->create([
            'user_id' => $member->id, 'provider_key' => 'kakao', 'provider_user_id' => (string) random_int(1000000000, 9999999999),
            'email' => 'k@example.test', 'access_token' => 'at', 'linked_at' => now(),
        ]);

        return $member;
    }

    /**
     * 관리자 화면에서 회원의 카카오 연결을 강제로 해제합니다.
     *
     * @param  User  $member  대상 회원
     * @return void 반환값 없음
     */
    private function adminUnlink(User $member): void
    {
        Sanctum::actingAs($this->makeAdminUser(
            ['email' => $this->uniqueEmail('unlinker')],
            ['sirsoft-social_login.accounts.view', 'sirsoft-social_login.accounts.unlink'],
        ));

        $this->deleteJson('/api/plugins/sirsoft-social_login/admin/users/'.$member->uuid.'/accounts/kakao')->assertOk();
    }

    /**
     * 회원의 관리자 강제 해제 활동 로그를 읽습니다.
     *
     * @param  User  $member  대상 회원
     * @return ActivityLog 활동 로그
     */
    private function unlinkLog(User $member): ActivityLog
    {
        return ActivityLog::query()->where('action', 'account_unlinked_by_admin')->where('loggable_id', $member->id)->latest('id')->firstOrFail();
    }

    /**
     * 로그인 수단 없음 안내 메일을 실제로 보내고 발송 이력에 남은 본문을 돌려줍니다.
     *
     * @param  User  $member  받는 회원
     * @return string 메일 본문
     */
    private function sendLastMethodMail(User $member): string
    {
        // 시험 환경은 발신 주소가 없어 메일 채널이 준비 안 됨으로 건너뛴다. 준비 상태를 고정하고 array 메일러로 보낸다.
        $this->mockAllChannelsReady();
        config(['mail.default' => 'array']);

        HookManager::doAction('sirsoft-social_login.account.unlinked_last_method', $member, 'kakao');

        return (string) DB::table('notification_logs')->where('recipient_user_id', $member->id)->where('channel', 'mail')->latest('id')->value('body');
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
