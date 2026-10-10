<?php

namespace Tests\Feature\Console;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Enums\ActivityLogType;
use App\Models\ActivityLog;
use App\Support\ExtensionSettingsMirror;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * IP 접근 제한 구제 커맨드(security:ip-restriction)를 고정합니다.
 *
 * 허용 목록을 잘못 저장해 관리자 화면에 들어갈 수 없게 되면 화면으로는 되돌릴 수 없다. 이
 * 커맨드가 그 상태의 유일한 탈출구이므로, "실제로 풀리는가" 를 요청 경로까지 이어서 잰다.
 * 해제는 마스터 토글만 끄고 목록·규칙은 남긴다 — 운영자가 무엇이 잘못됐는지 화면에서 보고
 * 고친 뒤 다시 켜야 하기 때문이다.
 *
 * DB 는 활동 로그 행만 쓰므로 트랜잭션으로 격리한다.
 */
class SecurityIpRestrictionCommandTest extends TestCase
{
    use DatabaseTransactions;

    /** 허용 목록 밖의 방문자 */
    private const OUTSIDE_IP = '198.51.100.7';

    /** 사무실 대역 */
    private const OFFICE_RANGE = '203.0.113.0/24';

    /**
     * security 카테고리를 저장하고 설정 미러까지 맞춥니다 (운영 중인 사이트의 상태 재현).
     *
     * @param  array<string, mixed>  $security  덮어쓸 security 키
     * @return void 반환값 없음
     */
    private function storeSecurity(array $security): void
    {
        $repository = app(ConfigRepositoryInterface::class);
        $repository->saveCategory('security', array_merge($repository->getCategory('security'), $security));

        app(ExtensionSettingsMirror::class)->refreshCore($repository);
    }

    /**
     * 옵션 없이 실행하면 현재 설정을 보여 주기만 하고 바꾸지 않습니다.
     *
     * 형식이 잘못된 항목은 판정에서 조용히 무시되므로, 운영자가 원인을 볼 수 있는 곳은 여기뿐이다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=core_admin_api
     *
     * @effects status_reports_settings_and_invalid_entries
     */
    public function test_status_prints_current_settings_without_changing_them(): void
    {
        $this->storeSecurity([
            'ip_restriction_enabled' => true,
            'admin_allowed_ips' => [self::OFFICE_RANGE, 'office.example.com'],
            'ip_restriction_rules' => [['pattern' => '/api/admin/users*', 'ips' => ['203.0.113.10'], 'memo' => '본사']],
        ]);

        // 출력 한 줄은 기대 하나만 소비한다 — 같은 줄에 걸리는 기대를 겹쳐 두지 않는다.
        $this->artisan('security:ip-restriction')
            ->expectsOutputToContain('켜짐')
            ->expectsOutputToContain(self::OFFICE_RANGE)
            ->expectsOutputToContain('형식이 잘못된 항목 (판정에서 무시됨): office.example.com')
            ->assertExitCode(0);

        $this->assertTrue(app(ConfigRepositoryInterface::class)->getCategory('security')['ip_restriction_enabled']);
    }

    /**
     * 해제는 마스터 토글만 끄고 목록·규칙을 남기며, 시스템 활동 로그를 남깁니다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=core_admin_api
     *
     * @effects artisan_disable_turns_off_only_the_toggle
     */
    public function test_disable_turns_off_only_the_toggle_and_logs_it(): void
    {
        $rules = [['pattern' => '/api/admin/users*', 'ips' => ['203.0.113.10'], 'memo' => '본사', 'enabled' => true]];
        $this->storeSecurity([
            'ip_restriction_enabled' => true,
            'admin_allowed_ips' => [self::OFFICE_RANGE],
            'ip_restriction_rules' => $rules,
        ]);

        $this->artisan('security:ip-restriction --disable')
            ->expectsOutputToContain('IP 접근 제한을 껐습니다')
            ->assertExitCode(0);

        $stored = app(ConfigRepositoryInterface::class)->getCategory('security');

        $this->assertFalse($stored['ip_restriction_enabled']);
        $this->assertSame([self::OFFICE_RANGE], $stored['admin_allowed_ips'], '해제가 허용 목록까지 지웠습니다.');
        $this->assertSame($rules, $stored['ip_restriction_rules'], '해제가 규칙까지 지웠습니다.');
        $this->assertFalse(g7_core_settings('security.ip_restriction_enabled'), '설정 미러가 옛 값을 계속 읽습니다 — 저장 경로를 우회했습니다.');

        $log = ActivityLog::query()->where('action', 'security.ip_restriction_disabled')->latest('id')->first();

        $this->assertNotNull($log, '비상 해제가 활동 로그에 남지 않았습니다.');
        $this->assertSame(ActivityLogType::System, $log->log_type);
        $this->assertSame(__('activity_log.action.ip_restriction_disabled'), $log->action_label);
    }

    /**
     * 이미 꺼져 있으면 아무것도 바꾸지 않고 기록도 남기지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=off
     *
     * @effects artisan_disable_is_idempotent
     */
    public function test_disable_is_idempotent_when_already_off(): void
    {
        $this->storeSecurity(['ip_restriction_enabled' => false]);
        $before = ActivityLog::query()->where('action', 'security.ip_restriction_disabled')->count();

        $this->artisan('security:ip-restriction --disable')
            ->expectsOutputToContain('이미 꺼져 있습니다')
            ->assertExitCode(0);

        $this->assertSame($before, ActivityLog::query()->where('action', 'security.ip_restriction_disabled')->count());
    }

    /**
     * `--status` 와 `--disable` 을 함께 주면 아무것도 바꾸지 않고 멈춥니다.
     *
     * `--status` 는 「보기만 한다」는 뜻이다. 둘을 같이 받은 채 끄면, 상태만 보려던 사람이 제한을
     * 꺼 버린 것이 된다 — 무엇을 의도했는지 알 수 없으므로 고르게 한다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=admin_shell, admin_list=contains_client
     *
     * @effects artisan_status_and_disable_are_mutually_exclusive
     */
    public function test_status_and_disable_cannot_be_combined(): void
    {
        $this->storeSecurity(['ip_restriction_enabled' => true, 'admin_allowed_ips' => ['203.0.113.5']]);

        $this->artisan('security:ip-restriction --status --disable')
            ->expectsOutputToContain('함께 쓸 수 없습니다')
            ->assertExitCode(1);

        $this->assertTrue(app(ConfigRepositoryInterface::class)->getCategory('security')['ip_restriction_enabled']);
    }

    /**
     * 잠긴 관리자가 커맨드 한 줄로 다시 관리자 영역에 도달합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario master_toggle=on, surface=core_admin_api, admin_list=excludes_client
     *
     * @effects artisan_disable_rescues_locked_admin
     */
    public function test_disable_unlocks_a_locked_out_admin(): void
    {
        $this->storeSecurity([
            'ip_restriction_enabled' => true,
            'admin_allowed_ips' => [self::OFFICE_RANGE],
            'ip_restriction_rules' => [],
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => self::OUTSIDE_IP])
            ->getJson('/api/admin/settings')
            ->assertStatus(403);

        $this->artisan('security:ip-restriction --disable')->assertExitCode(0);

        $this->withServerVariables(['REMOTE_ADDR' => self::OUTSIDE_IP])
            ->getJson('/api/admin/settings')
            ->assertStatus(401);
    }
}
