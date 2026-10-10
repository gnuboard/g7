<?php

namespace Plugins\Sirsoft\Totp\Tests\Feature;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\Totp\Listeners\TotpAdminResetActivityLogListener;
use Plugins\Sirsoft\Totp\Models\TotpCredential;
use Plugins\Sirsoft\Totp\Plugin;
use Plugins\Sirsoft\Totp\Tests\PluginTestCase;

/**
 * 관리자 강제 해제의 활동 로그 기록 테스트.
 *
 * 강제 해제는 코드 확인 없이 남의 2단계 인증을 푸는 경로다 — 감사 흔적이 없으면 그
 * 경로 자체가 권한 상승 수단이 된다. 컨트롤러는 훅만 발행하고 기록은 리스너가 맡으므로,
 * 리스너를 직접 부르지 않고 **실제 훅 체인**(`HookManager::doAction`)을 태워 확인한다 —
 * 그래야 `plugin.php` 의 리스너 등록이 빠지면 red 가 된다(mock 금지 규정).
 *
 * @since 1.0.0
 */
class TotpAdminResetActivityLogTest extends PluginTestCase
{
    /**
     * 테스트용으로 리스너를 훅에 붙입니다.
     *
     * 운영에서는 `plugin.php::getHookListeners()` 등록분을 코어가 자동으로 붙이지만,
     * 플러그인 테스트 부팅에는 그 경로가 없다. 등록 자체가 빠지는 회귀는
     * `a_plugin_registers_the_activity_log_listener` 가 잡는다.
     *
     * @return void 반환값 없음
     */
    private function subscribeListener(): void
    {
        $listener = app(TotpAdminResetActivityLogListener::class);

        foreach (TotpAdminResetActivityLogListener::getSubscribedHooks() as $hook => $meta) {
            HookManager::addAction(
                $hook,
                fn (...$args) => $listener->{$meta['method']}(...$args),
                $meta['priority'] ?? 50,
            );
        }
    }

    /**
     * 강제 해제 훅이 활동 로그 한 건을 남기는지 확인합니다.
     *
     * @scenario listener=admin_reset, hook=sirsoft-totp.credential.admin_reset
     *
     * @effects admin_forced_reset_is_written_to_the_activity_log
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_forced_reset_is_written_to_the_activity_log(): void
    {
        $this->subscribeListener();

        $target = User::factory()->create();
        $actor = User::factory()->create();

        HookManager::doAction('sirsoft-totp.credential.admin_reset', $target, [
            'actor_id' => $actor->id,
            'ip_address' => '203.0.113.7',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'credential.admin_reset',
            'loggable_type' => $target->getMorphClass(),
            'loggable_id' => $target->id,
        ]);
    }

    /**
     * 기록된 로그가 행위자와 접속 IP 를 담는지 확인합니다.
     *
     * 「누가」 없이 「풀렸다」만 남으면 추적 목적을 이루지 못한다.
     *
     * @effects admin_forced_reset_log_carries_actor_and_ip
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_log_carries_the_actor_and_the_ip(): void
    {
        $this->subscribeListener();

        $target = User::factory()->create();
        $actor = User::factory()->create();

        HookManager::doAction('sirsoft-totp.credential.admin_reset', $target, [
            'actor_id' => $actor->id,
            'ip_address' => '203.0.113.7',
        ]);

        $log = DB::table('activity_logs')
            ->where('action', 'credential.admin_reset')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);

        $properties = json_decode((string) $log->properties, true);

        $this->assertSame($actor->id, $properties['actor_id'] ?? null);
        $this->assertSame('203.0.113.7', $properties['ip_address'] ?? null);
        $this->assertSame($target->id, $properties['target_user_id'] ?? null);
    }

    /**
     * 사용자가 아닌 인자로 발화하면 기록하지 않는지 확인합니다.
     *
     * @effects admin_reset_hook_with_a_non_user_payload_logs_nothing
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_non_user_payload_writes_nothing(): void
    {
        $this->subscribeListener();

        HookManager::doAction('sirsoft-totp.credential.admin_reset', null, []);

        $this->assertDatabaseMissing('activity_logs', [
            'action' => 'credential.admin_reset',
        ]);
    }

    /**
     * 플러그인이 이 리스너를 실제로 등록하는지 확인합니다.
     *
     * 리스너 파일만 있고 `getHookListeners()` 에서 빠지면 운영에서는 아무것도 기록되지
     * 않는다 — 그 회귀를 이 케이스가 잡는다.
     *
     * @effects admin_reset_listener_is_registered_by_the_plugin
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_plugin_registers_the_activity_log_listener(): void
    {
        $this->assertContains(
            TotpAdminResetActivityLogListener::class,
            (new Plugin)->getHookListeners(),
        );
    }

    /**
     * 서버 셸의 `totp:reset` 강제 해제도 같은 감사 로그를 남기는지 확인합니다.
     *
     * 훅을 컨트롤러에서만 발화해 CLI 로 풀면 아무 기록도 남지 않았다(#138 후속 전수 점검 6번).
     * CLI 는 요청 사용자·IP 가 없으므로 행위자·IP 는 비어 있고, 진입점이 CLI 로 표시된다.
     *
     * @effects cli_forced_reset_is_written_to_the_activity_log
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_cli_reset_command_is_written_to_the_activity_log(): void
    {
        $this->subscribeListener();

        $target = User::factory()->create(['email' => 'totp-cli-reset@test.com']);
        TotpCredential::create([
            'user_id' => $target->id,
            'secret' => 'JBSWY3DPEHPK3PXP',
            'confirmed_at' => now(),
        ]);

        $this->artisan('totp:reset', ['email' => $target->email])->assertExitCode(0);

        $log = DB::table('activity_logs')->where('action', 'credential.admin_reset')->latest('id')->first();

        $this->assertNotNull($log, 'CLI 강제 해제가 활동 로그를 남기지 않았다');
        $this->assertSame($target->id, (int) $log->loggable_id);

        $properties = json_decode((string) $log->properties, true);
        $this->assertNull($properties['actor_id'] ?? null);
    }

    /**
     * 관리자 화면(DELETE)의 강제 해제가 행위자와 IP 를 담아 기록되는지 확인합니다.
     *
     * 훅 발화를 컨트롤러에서 Service 로 옮겼으므로 화면 경로가 그대로 기록되는지 다시 잰다.
     *
     * @effects admin_forced_reset_log_carries_actor_and_ip
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_admin_screen_reset_is_written_with_the_actor(): void
    {
        $this->subscribeListener();

        $operator = $this->createCredentialOperatorUser();
        $target = User::factory()->create();
        TotpCredential::create([
            'user_id' => $target->id,
            'secret' => 'JBSWY3DPEHPK3PXP',
            'confirmed_at' => now(),
        ]);

        $this->actingAs($operator)
            ->deleteJson('/api/plugins/sirsoft-totp/admin/credentials/'.$target->uuid)
            ->assertOk()
            ->assertJsonPath('data.removed', true);

        $log = DB::table('activity_logs')->where('action', 'credential.admin_reset')->latest('id')->first();

        $this->assertNotNull($log);
        $properties = json_decode((string) $log->properties, true);
        $this->assertSame($operator->id, $properties['actor_id'] ?? null);
        $this->assertNotNull($properties['ip_address'] ?? null);
    }

    /**
     * 관리자 화면(확장 관리자 API)의 강제 해제는 「관리자」 유형 로그다 — 코어 관리자 API 와 같은 분류
     * (7.1.0 실측 O-B2: 종전에는 경로가 `api/admin/*` 가 아니라 「사용자」로 남았다).
     *
     * @effects admin_screen_reset_is_logged_as_admin_type
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_admin_screen_reset_is_logged_as_the_admin_type(): void
    {
        $this->subscribeListener();

        $operator = $this->createCredentialOperatorUser();
        $target = User::factory()->create();
        TotpCredential::create([
            'user_id' => $target->id,
            'secret' => 'JBSWY3DPEHPK3PXP',
            'confirmed_at' => now(),
        ]);

        $this->actingAs($operator)
            ->deleteJson('/api/plugins/sirsoft-totp/admin/credentials/'.$target->uuid)
            ->assertOk();

        $log = DB::table('activity_logs')->where('action', 'credential.admin_reset')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('admin', $log->log_type);
    }

    /**
     * 등록된 인증 앱이 없어 해제할 것이 없으면 기록하지 않는지 확인합니다.
     *
     * @effects admin_reset_hook_with_a_non_user_payload_logs_nothing
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_cli_reset_without_a_credential_writes_nothing(): void
    {
        $this->subscribeListener();

        $target = User::factory()->create(['email' => 'totp-cli-none@test.com']);

        $this->artisan('totp:reset', ['email' => $target->email])->assertExitCode(0);

        $this->assertDatabaseMissing('activity_logs', ['action' => 'credential.admin_reset']);
    }

    /**
     * 등록한 인증 앱이 없는 회원을 관리자 화면에서 해제하면 「해제했습니다」 가 아니라 해제할 것이 없다고 알리는지
     * 확인합니다 — 카드는 미등록 회원에게 해제 단추를 숨기지만, 먼저 해제된 뒤 다시 누르거나 API 를 직접 부를 때 이 응답이 상태를 알린다.
     *
     * @effects admin_reset_without_credential_says_nothing_to_remove
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_admin_screen_reset_without_a_credential_says_there_was_nothing(): void
    {
        $this->subscribeListener();
        app()->setLocale('ko');

        $operator = $this->createCredentialOperatorUser();
        $target = User::factory()->create();

        $response = $this->actingAs($operator)
            ->deleteJson('/api/plugins/sirsoft-totp/admin/credentials/'.$target->uuid)
            ->assertOk()
            ->assertJsonPath('data.removed', false);

        $this->assertSame(__('sirsoft-totp::messages.enrollment.admin_reset_none'), $response->json('message'));
        $this->assertNotSame(__('sirsoft-totp::messages.enrollment.admin_reset'), $response->json('message'));
        $this->assertDatabaseMissing('activity_logs', ['action' => 'credential.admin_reset']);
    }
}
