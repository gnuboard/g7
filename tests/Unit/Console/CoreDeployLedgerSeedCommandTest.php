<?php

namespace Tests\Unit\Console;

use App\Services\CoreUpdateService;
use Tests\TestCase;

/**
 * `core:deploy-ledger-seed` — 인스톨러 마무리 단계가 부르는 원장 씨앗 통로.
 *
 * 인스톨러는 Laravel 부팅 전이라 서비스를 직접 부를 수 없고 `installerRunArtisan()` 단일 지점으로만
 * Artisan 을 실행한다. 그 통로가 서비스의 씨앗 메서드를 **프로젝트 루트**로 부르고, 결과와 무관하게
 * 설치를 막지 않는(종료 코드 0) 계약을 여기서 고정한다.
 */
class CoreDeployLedgerSeedCommandTest extends TestCase
{
    /**
     * 원장 씨앗 커맨드가 프로젝트 루트로 서비스 씨앗기를 한 번 부르고, 씨앗한 조각 수를 출력하며 성공 코드로 끝나는지 확인한다.
     *
     * @scenario deploy_fragment_ledger=absent
     *
     * @effects installer_seeds_deploy_fragment_ledger_at_finalize
     *
     * @return void 반환값 없음
     */
    public function test_command_seeds_the_ledger_from_the_project_root(): void
    {
        $service = $this->mock(CoreUpdateService::class);
        $service->shouldReceive('seedDeployFragmentLedger')
            ->once()
            ->with(base_path())
            ->andReturn(['nginx/g7-layout2.conf' => 'abc', 'caddy/g7-layout2.caddyfile' => 'def']);
        $service->shouldReceive('getLastRootFileWarnings')->andReturn([]);

        $this->artisan('core:deploy-ledger-seed')
            ->expectsOutputToContain('2')
            ->assertExitCode(0);
    }

    /**
     * 원장 쓰기 실패는 「기록했습니다」로 위장되지 않고 비-0 + 사유 방출로 드러난다.
     *
     * 씨앗기는 쓰기 실패 시 반환값을 `[]` 로 주고 경고 통로에 사유를 싣는다. 종전 커맨드는 반환값의
     * `[]` 만 보고 「건너뜀(=정상)」으로 SUCCESS 를 냈다 — `storage/app` 이 쓰기 불가인 사이트는
     * 원장이 없는 채로 「완료」만 봤고, 다음 업데이트가 전 조각을 「판정 불가」로 떨어뜨렸다.
     *
     * @scenario deploy_fragment_ledger=unwritable
     *
     * @effects installer_seeds_deploy_fragment_ledger_at_finalize
     *
     * @return void 반환값 없음
     */
    public function test_command_reports_failure_and_emits_the_reason_when_the_ledger_write_fails(): void
    {
        $warning = 'deploy 조각 해시 원장을 쓰지 못했습니다: /srv/site/storage/app/core-update/deploy-fragments.json';

        $service = $this->mock(CoreUpdateService::class);
        $service->shouldReceive('seedDeployFragmentLedger')->once()->andReturn([]);
        $service->shouldReceive('getLastRootFileWarnings')->andReturn([$warning]);

        $this->artisan('core:deploy-ledger-seed')
            ->expectsOutputToContain($warning)
            ->assertExitCode(1);
    }

    /**
     * 이미 원장이 있어 씨앗할 조각이 없을 때 커맨드가 건너뜀 문구를 내고 여전히 성공 코드로 끝나 설치를 막지 않는지 확인한다.
     *
     * @scenario deploy_fragment_ledger=present
     *
     * @effects installer_seeds_deploy_fragment_ledger_at_finalize
     *
     * @return void 반환값 없음
     */
    public function test_command_is_idempotent_and_never_fails_the_install(): void
    {
        $service = $this->mock(CoreUpdateService::class);
        $service->shouldReceive('seedDeployFragmentLedger')->once()->andReturn([]);
        $service->shouldReceive('getLastRootFileWarnings')->andReturn([]);

        $this->artisan('core:deploy-ledger-seed')
            ->expectsOutputToContain(__('settings.core_update.deploy_fragment_ledger_seed_skipped'))
            ->assertExitCode(0);
    }

    /**
     * 인스톨러 task-runner 의 마무리 단계가 원장 씨앗 커맨드를 단일 Artisan 러너(installerRunArtisan)로 부르는지 소스에서 확인한다.
     *
     * @scenario deploy_fragment_ledger=absent
     *
     * @effects installer_seeds_deploy_fragment_ledger_at_finalize
     *
     * @return void 반환값 없음
     */
    public function test_installer_finalize_calls_the_command_through_the_single_artisan_runner(): void
    {
        $source = (string) file_get_contents(base_path('public/install/includes/task-runner.php'));

        // 설치기만 `--fresh-install` 로 번들 출하 목록까지 씨앗한다(손으로 돌리는 실행은 원장만)
        $this->assertMatchesRegularExpression(
            "/installerRunArtisan\\('core:deploy-ledger-seed', \\['--fresh-install' => true\\]\\)/",
            $source,
            '인스톨러 마무리 단계가 원장 씨앗 커맨드를 단일 Artisan 러너로 불러야 한다'
        );
    }
}
