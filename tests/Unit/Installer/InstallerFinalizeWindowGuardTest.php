<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerSubprocess;

/**
 * 설치 화면 진입 가드 — 완료 표식 뒤 마무리 구간의 새로고침 회귀 테스트.
 *
 * 회귀: 완료 표식(complete_flag 가 storage/app/g7_installed 생성) 뒤 설정·라우트 캐시 굽기·정적 게시가 도는
 * 동안 설치 화면을 새로고침하면 /install/ 이 410(JSON)으로 끝나, 그 구간을 기다렸다 .env 를 확정하는
 * 화면 스크립트(waitForFinalTasks → finalize-env)가 실리지 않았다. 결과로 .env 미확정·runtime.php(평문 비밀값)·
 * 상태 파일이 남았다.
 *
 * 고정하는 계약 — index.php 는 다음을 모두 만족할 때만 410 대신 Step 5(완료 대기) 화면을 낸다:
 *  - GET 요청, g7_installed 있음, .env 완료 플래그 없음
 *  - 상태 파일 있음 + installation_status=completed
 *  - 요청 세션이 그 설치의 소유 세션
 * 그 밖(소유자 아님·터미널 소유·상태 파일 없음·.env 완료·진행 중 상태·POST)은 종전대로 410.
 *
 * index.php 를 임시 앱 루트의 격리 프로세스에서 실제로 실행한다.
 */
class InstallerFinalizeWindowGuardTest extends TestCase
{
    use RunsInstallerSubprocess;

    /** 소유 세션 토큰 */
    private const OWNER_TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

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
     * 마무리 구간의 state(완료 표식 직후)를 만든다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 필드
     * @return array<string, mixed> 상태 파일 내용
     */
    private function completedState(array $overrides = []): array
    {
        return array_merge([
            'installation_status' => 'completed',
            'current_step' => 5,
            'step_status' => ['3' => 'completed', '4' => 'completed', '5' => 'completed'],
            'tasks_finished' => false,
            'owner_hash' => hash('sha256', self::OWNER_TOKEN),
            'generation' => 7,
            'g7_locale' => 'ko',
            'config' => ['app_url' => 'https://example.test'],
        ], $overrides);
    }

    /**
     * 소유 세션 값을 만든다.
     *
     * @param  string  $ownerToken  세션의 소유 토큰 (빈 문자열이면 토큰 없음)
     * @return array<string, mixed> 세션
     */
    private function session(string $ownerToken = self::OWNER_TOKEN): array
    {
        $session = [
            'g7_locale' => 'ko',
            'csrf_token' => 'tok',
            'installer_current_step' => 5,
            'install_config' => ['db_write_password' => 'secret-pass'],
        ];
        if ($ownerToken !== '') {
            $session['install_owner'] = $ownerToken;
        }

        return $session;
    }

    /**
     * 임시 루트에서 index.php 를 실행한다.
     *
     * @param  array<string, mixed>  $session  세션
     * @param  string  $method  요청 메서드(REQUEST_METHOD)
     * @return array{status: int, body: array<string, mixed>|null, raw: string, session: array<string, mixed>} 실행 결과
     */
    private function runIndex(array $session, string $method = 'GET'): array
    {
        return $this->runInstallerHarness([
            'mode' => 'endpoint',
            'endpoint' => dirname(__DIR__, 3).'/public/install/index.php',
            'method' => $method,
            'session' => $session,
        ]);
    }

    /**
     * 마무리 구간(완료 표식 있음, .env 미확정)에 소유 세션이 새로고침하면 410 이 아니라 Step 5 화면이 열린다.
     *
     * @scenario surface=installer
     *
     * @effects installer_index_reopens_step5_for_owner_in_finalize_window
     *
     * @param  bool  $tasksFinished  마무리 작업이 끝났는지(끝났어도 .env 확정 전이면 열려야 한다)
     * @return void 반환값 없음
     */
    #[DataProvider('tasksFinishedProvider')]
    public function test_owner_session_reopens_step5_during_finalize_window(bool $tasksFinished): void
    {
        $base = $this->makeInstallerTempBase();
        file_put_contents($base.'/storage/app/g7_installed', '2026-10-29 12:11:03');
        file_put_contents($base.'/.env', "APP_KEY=\nAPP_URL=http://localhost\n");
        $this->writeInstallerState($this->completedState(['tasks_finished' => $tasksFinished]));

        $result = $this->runIndex($this->session());

        $this->assertSame(200, $result['status'], $result['raw']);
        $this->assertStringNotContainsString('Installer is disabled', $result['raw']);
        // Step 5 화면(완료 섹션 포함) + 화면 스크립트가 실린다 — waitForFinalTasks → finalize-env 경로
        $this->assertStringContainsString('id="completion-section"', $result['raw']);
        $this->assertStringContainsString('window.CURRENT_STEP = 5;', $result['raw']);
        $this->assertStringContainsString('/assets/js/installer.js', $result['raw']);
        $this->assertStringContainsString('window.INSTALLER_OWNERSHIP_LOCKED = false;', $result['raw']);
        // 완료 뒤 재진입 경로와 같이 세션에 남은 설치 설정(비밀값 포함)은 정리한다
        $this->assertArrayNotHasKey('install_config', $result['session']);
        $this->assertStringNotContainsString('secret-pass', $result['raw']);
    }

    /**
     * 마무리 작업 진행 여부 목록.
     *
     * @return array<string, array{0: bool}> 사례 이름 → [tasks_finished]
     */
    public static function tasksFinishedProvider(): array
    {
        return [
            '마무리 작업 진행 중' => [false],
            '마무리 작업 끝, .env 확정 전' => [true],
        ];
    }

    /**
     * 마무리 구간이 아니거나 소유 세션이 아니면 종전대로 410 으로 막는다.
     *
     * @scenario surface=installer
     *
     * @effects installer_index_stays_gone_outside_owner_finalize_window
     *
     * @param  array<string, mixed>|null  $state  상태 파일 (null 이면 상태 파일 없음)
     * @param  string  $env  .env 내용
     * @param  string  $ownerToken  요청 세션의 소유 토큰
     * @param  string  $method  요청 메서드(REQUEST_METHOD)
     * @return void 반환값 없음
     */
    #[DataProvider('blockedProvider')]
    public function test_installer_stays_gone_outside_owner_finalize_window(?array $state, string $env, string $ownerToken, string $method): void
    {
        $base = $this->makeInstallerTempBase();
        file_put_contents($base.'/storage/app/g7_installed', '2026-10-29 12:11:03');
        file_put_contents($base.'/.env', $env);
        if ($state !== null) {
            $this->writeInstallerState($state);
        }

        $result = $this->runIndex($this->session($ownerToken), $method);

        $this->assertSame(410, $result['status'], $result['raw']);
        $this->assertSame(false, $result['body']['success'] ?? null, $result['raw']);
        $this->assertStringNotContainsString('id="completion-section"', $result['raw']);
    }

    /**
     * 410 이어야 하는 사례 목록.
     *
     * @return array<string, array{0: array<string, mixed>|null, 1: string, 2: string, 3: string}> 사례 이름 → [state, .env, 세션 소유 토큰, 메서드]
     */
    public static function blockedProvider(): array
    {
        $owner = hash('sha256', self::OWNER_TOKEN);
        $completed = [
            'installation_status' => 'completed',
            'current_step' => 5,
            'step_status' => ['5' => 'completed'],
            'tasks_finished' => false,
            'owner_hash' => $owner,
            'generation' => 7,
        ];
        $pendingEnv = "APP_KEY=\n";

        return [
            '소유 세션이 아님' => [$completed, $pendingEnv, str_repeat('b', 64), 'GET'],
            '세션에 소유 토큰 없음' => [$completed, $pendingEnv, '', 'GET'],
            '터미널 소유 설치' => [['owner_kind' => 'cli', 'owner_hash' => hash('sha256', str_repeat('c', 64))] + $completed, $pendingEnv, self::OWNER_TOKEN, 'GET'],
            '소유자 미각인 상태' => [array_diff_key($completed, ['owner_hash' => true]), $pendingEnv, self::OWNER_TOKEN, 'GET'],
            '.env 완료 플래그 참' => [$completed, "APP_KEY=base64:x\nINSTALLER_COMPLETED=true\n", self::OWNER_TOKEN, 'GET'],
            '상태 파일 없음' => [null, $pendingEnv, self::OWNER_TOKEN, 'GET'],
            '상태가 진행 중(완료 아님)' => [['installation_status' => 'running'] + $completed, $pendingEnv, self::OWNER_TOKEN, 'GET'],
            '소유 세션이라도 POST' => [$completed, $pendingEnv, self::OWNER_TOKEN, 'POST'],
        ];
    }
}
