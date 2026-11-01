<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerSubprocess;

/**
 * 터미널 소유 설치의 웹 잠금 화면 회귀 테스트.
 *
 * 회귀: 터미널 설치(php g7-install)가 실행 중일 때 /install/ 잠금 화면이 「진행 중입니다」 문구와 함께
 * `php g7-install --resume` 명령 상자를 보였다. 그 명령은 실행 중에는 「다른 터미널 설치가 실행 중입니다」로 거부된다.
 * → 실행 중이면 명령 상자를 숨기고, 멈춘 경우에만 보인다.
 *
 * index.php 를 임시 앱 루트의 격리 프로세스에서 실제로 실행해 잠금 화면을 렌더한다.
 */
class InstallerCliOwnedNoticeTest extends TestCase
{
    use RunsInstallerSubprocess;

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
     * 터미널 소유 state 로 index.php 를 실행한다.
     *
     * @param  array<string, mixed>  $overrides  state 덮어쓸 필드
     * @return string 잠금 안내 블록 HTML
     */
    private function renderCliOwnedScreen(array $overrides): string
    {
        $this->makeInstallerTempBase();
        $this->writeInstallerState(array_merge([
            'owner_kind' => 'cli',
            'owner_hash' => hash('sha256', str_repeat('c', 64)),
            'generation' => 3,
            'current_step' => 5,
            'g7_locale' => 'ko',
        ], $overrides));

        $result = $this->runInstallerHarness([
            'mode' => 'endpoint',
            'endpoint' => dirname(__DIR__, 3).'/public/install/index.php',
            'method' => 'GET',
            'session' => ['g7_locale' => 'ko', 'csrf_token' => 'tok', 'installer_current_step' => 0],
        ]);

        $this->assertSame(200, $result['status'], $result['raw']);
        // 화면 본문(잠금 안내 블록)만 본다 — 페이지 끝의 번역 묶음(INSTALLER_LANG)에는 멈춤 문구의 명령도 들어 있다
        $start = strpos($result['raw'], 'id="cli-owned-notice"');
        $end = strpos($result['raw'], '<!-- Installer Footer -->');
        $this->assertNotFalse($start, $result['raw']);
        $this->assertNotFalse($end, $result['raw']);

        return substr($result['raw'], $start, $end - $start);
    }

    /**
     * 터미널 설치가 실행 중이면 이어서 설치 명령 상자를 보이지 않는다.
     *
     * @scenario surface=installer
     *
     * @effects cli_owned_notice_hides_resume_while_running
     *
     * @return void 반환값 없음
     */
    public function test_running_cli_install_hides_resume_command(): void
    {
        $html = $this->renderCliOwnedScreen([
            'installation_status' => 'running',
            'active_worker_id' => 'cli-worker',
            'last_heartbeat' => time(),
        ]);

        $this->assertStringContainsString($this->koLang('cli_owned_notice_running'), $html);
        $this->assertStringNotContainsString('--resume', $html, '실행 중에는 거부되는 --resume 명령을 안내하면 안 된다');
        $this->assertStringNotContainsString('class="code-box"', $html);
    }

    /**
     * 터미널 설치가 멈췄으면(하트비트 끊김·실행 중 아님) 이어서 설치 명령 상자를 보인다.
     *
     * @scenario surface=installer
     *
     * @effects cli_owned_notice_shows_resume_when_stopped
     *
     * @return void 반환값 없음
     */
    public function test_stopped_cli_install_shows_resume_command(): void
    {
        $html = $this->renderCliOwnedScreen([
            'installation_status' => 'running',
            'active_worker_id' => 'cli-worker',
            'last_heartbeat' => time() - 120,
        ]);

        $this->assertStringContainsString($this->koLang('cli_owned_notice_stopped'), $html);
        $this->assertStringContainsString('<div class="code-box"><pre>php g7-install --resume</pre></div>', $html);
    }

    /**
     * 설치기 ko 언어 문구를 읽는다.
     *
     * @param  string  $key  번역 키
     * @return string 문구
     */
    private function koLang(string $key): string
    {
        $lang = require dirname(__DIR__, 3).'/public/install/lang/ko.php';
        $this->assertIsArray($lang);
        $this->assertArrayHasKey($key, $lang);

        return (string) $lang[$key];
    }
}
