<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerSubprocess;

/**
 * state.json generation compare-and-swap 회귀 테스트.
 *
 * - 상태변경 요청은 읽은 세대와 디스크 세대가 같을 때만 커밋하고 세대를 올린다
 * - 뒤늦은(옛 세대) 상태변경은 거부된다
 * - 설치 워커의 진행 쓰기(비-CAS)는 세대를 올리지 않고, 옛 사본을 저장해도 소유권 필드를 되돌리지 않는다
 *   (이슈 #319 SSE↔폴링 경합 회귀 가드)
 */
class InstallerGenerationCasTest extends TestCase
{
    use RunsInstallerSubprocess;

    /**
     * 임시 루트 준비.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->makeInstallerTempBase();
    }

    /**
     * 임시 루트 제거.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->removeInstallerTempBase();

        parent::tearDown();
    }

    /**
     * 기대 세대가 맞으면 저장되고 세대가 1 오른다.
     *
     * @effects cas_commits_and_bumps_generation
     *
     * @return void 반환값 없음
     */
    public function test_cas_commits_and_increments_generation(): void
    {
        $this->writeInstallerState(['installation_status' => 'ready', 'generation' => 2]);

        $result = $this->runInstallerScript(<<<'PHP'
            $state = getInstallationState();
            $state['installation_status'] = 'running';
            var_export(saveInstallationStateCas($state, 2));
        PHP);

        $this->assertStringStartsWith('true', $result['raw']);
        $this->assertSame(3, $this->readInstallerState()['generation']);
        $this->assertSame('running', $this->readInstallerState()['installation_status']);
    }

    /**
     * 뒤늦은 쓰기(읽은 뒤 다른 요청이 커밋)는 거부되고 디스크는 그대로다.
     *
     * @effects cas_rejects_stale_write
     *
     * @return void 반환값 없음
     */
    public function test_stale_cas_write_is_rejected(): void
    {
        $this->writeInstallerState(['installation_status' => 'aborted', 'generation' => 5]);

        $result = $this->runInstallerScript(<<<'PHP'
            var_export(saveInstallationStateCas(['installation_status' => 'running', 'generation' => 4], 4));
        PHP);

        $this->assertStringStartsWith('false', $result['raw']);
        $this->assertSame('aborted', $this->readInstallerState()['installation_status']);
        $this->assertSame(5, $this->readInstallerState()['generation']);
    }

    /**
     * 워커 진행 쓰기(markTaskCompleted 등 비-CAS)는 세대를 올리지 않는다 — 워커가 CAS 충돌을 일으키지 않는다.
     *
     * @effects worker_progress_write_does_not_bump_generation
     *
     * @return void 반환값 없음
     */
    public function test_worker_progress_write_does_not_bump_generation(): void
    {
        $this->writeInstallerState(['installation_status' => 'running', 'generation' => 7, 'completed_tasks' => [], 'current_task' => null]);

        $this->runInstallerScript(<<<'PHP'
            markTaskCompleted('env_update');
            updateCurrentTask('key_generate');
        PHP);

        $state = $this->readInstallerState();
        $this->assertSame(7, $state['generation']);
        $this->assertSame(['env_update'], $state['completed_tasks']);
    }

    /**
     * 옛 세대 사본을 비-CAS 로 저장해도 소유권 필드는 디스크(더 높은 세대) 값이 유지된다 —
     * 소유권 회전·계획 서명이 워커의 옛 사본에 되돌려지지 않는다. 진행 필드는 쓰기 그대로 반영.
     *
     * @effects stale_plain_save_cannot_revert_ownership_fields
     *
     * @return void 반환값 없음
     */
    public function test_stale_plain_save_cannot_revert_ownership_fields(): void
    {
        $this->writeInstallerState([
            'installation_status' => 'running',
            'generation' => 9,
            'owner_hash' => 'new-owner',
            'recovery_hash' => 'new-recovery',
            'install_plan_sig' => 'new-sig',
            'existing_db_action' => 'skip',
            'completed_tasks' => [],
        ]);

        $this->runInstallerScript(<<<'PHP'
            saveInstallationState([
                'installation_status' => 'running',
                'generation' => 8,
                'owner_hash' => 'old-owner',
                'recovery_hash' => 'old-recovery',
                'install_plan_sig' => 'old-sig',
                'existing_db_action' => 'drop_tables',
                'completed_tasks' => ['db_migrate'],
            ]);
        PHP);

        $state = $this->readInstallerState();
        $this->assertSame('new-owner', $state['owner_hash']);
        $this->assertSame('new-recovery', $state['recovery_hash']);
        $this->assertSame('new-sig', $state['install_plan_sig']);
        $this->assertSame('skip', $state['existing_db_action']);
        $this->assertSame(9, $state['generation']);
        $this->assertSame(['db_migrate'], $state['completed_tasks'], '진행 필드는 쓰기 그대로');
    }

    /**
     * 상태 파일이 없을 때(신선 설치) 기본 세대 0 으로 CAS 가 성립한다.
     *
     * @effects cas_commits_and_bumps_generation
     *
     * @return void 반환값 없음
     */
    public function test_cas_on_fresh_install_starts_from_zero(): void
    {
        $result = $this->runInstallerScript(<<<'PHP'
            $state = getInstallationState();
            var_export([(int) $state['generation'], saveInstallationStateCas($state, 0)]);
        PHP);

        $this->assertStringContainsString('0 => 0', $result['raw']);
        $this->assertStringContainsString('1 => true', $result['raw']);
        $this->assertSame(1, $this->readInstallerState()['generation']);
    }
}
