<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;

/**
 * 단계별 실행 토큰이 **설치를 시작한 세션에만** 실리는가 (13차 보안#3).
 *
 * 진행 중인 stepwise 설치가 있으면 `index.php` 는 세션이 없는 요청도 상태 파일의
 * `current_step` 을 채택해 Step 5 로 안내한다. 그 화면이 토큰을 무조건 렌더하면 **무세션
 * GET 한 번으로 토큰이 유출되고**, 9~11차가 run-task·abort·reset·skip_task·finalize 에 건
 * 토큰 게이트 전부가 그 통로 하나로 우회된다.
 *
 * 막는 것은 페이지가 아니라 토큰이다 — Step 5 렌더 자체를 막으면 `showInstallationRunningAlert()`
 * (step=5 강제)와 무한 루프가 되고, 세션이 GC 로 만료된 정상 운영자가 영구 잠금된다.
 *
 * 화면을 **실제로 렌더해** 검사한다. 소스 문자열 대조는 판정 함수를 부르는 줄이 남아 있는지만
 * 보므로, 조건이 뒤집히거나 파생 로직이 잘못돼도 그대로 통과한다.
 *
 * 커버 축: session_ownership owner·absent·mismatch / install_state running·not_started
 *
 * 효과 요약(마커 아님 — 평문): installer_stepwise_token_is_rendered_only_for_the_owning_session
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class InstallerStepwiseTokenExposureTest extends TestCase
{
    use CleansTemporaryBasePath;

    /** 발급된 토큰을 흉내 내는 값 (runtime.php 에만 있어야 하는 비밀). */
    private const TOKEN = 'f00dcafef00dcafef00dcafef00dcafe';

    /** 설치를 시작한 세션의 소유자 표식. */
    private const OWNER = '0123456789abcdef0123456789abcdef';

    private string $tempBase = '';

    /**
     * 임시 기준 경로를 정하고 설치기 상태 디렉터리를 만든 뒤 화면 렌더에 필요한 요청 변수와 설치기 설정, 상태, 런타임, 실행 모드, 태스크 러너 소스를 읽어 들인다.
     * 기준 경로 상수가 시스템 임시 경로 밖으로 이미 정의돼 있으면 실제 저장소를 지키려고 건너뛴다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 실 저장소의 상태 파일·runtime.php 를 건드리지 않는다 (R7).
        $this->tempBase = sys_get_temp_dir().'/g7-installer-token-exposure-'.bin2hex(random_bytes(4));
        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();

        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                $this->markTestSkipped(
                    'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — 실 storage 파괴 방지를 위해 skip. '
                    .'격리 실행: php vendor/bin/phpunit --filter=InstallerStepwiseTokenExposureTest'
                );
            }
            $this->tempBase = (string) BASE_PATH;
        } else {
            define('BASE_PATH', $this->tempBase);
        }

        @mkdir($this->tempBase.'/storage/installer', 0755, true);

        $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/install/';

        $installRoot = dirname(__DIR__, 3).'/public/install';
        require_once $installRoot.'/includes/config.php';
        require_once $installRoot.'/includes/functions.php';
        require_once $installRoot.'/includes/installer-state.php';
        require_once $installRoot.'/includes/installer-runtime.php';
        require_once $installRoot.'/includes/execution-mode.php';
        require_once $installRoot.'/includes/task-runner.php';
    }

    /**
     * 진행 중인 단계별 설치 + 발급된 토큰을 상태 파일과 runtime.php 에 심는다.
     *
     * @return void 반환값 없음
     */
    private function seedRunningStepwiseInstall(): void
    {
        $state = getInstallationState();
        $state['current_step'] = 5;
        $state['installation_mode'] = 'stepwise';
        $state['installation_status'] = 'running';
        saveInstallationState($state);

        writeInstallerRuntime([
            'installer' => ['stepwise_token' => self::TOKEN, 'session_owner' => self::OWNER],
        ]);
    }

    /**
     * Step 5 화면을 렌더한다.
     *
     * @param  array<string, mixed>  $session  이 요청의 $_SESSION 스냅샷
     * @return string 렌더된 Step 5 화면 HTML
     */
    private function renderStep5(array $session): string
    {
        $_SESSION = $session;
        $state = getInstallationState();

        ob_start();
        require dirname(__DIR__, 3).'/public/install/views/5-installation.php';

        return (string) ob_get_clean();
    }

    /**
     * 렌더 결과에서 화면에 실린 토큰 값을 꺼낸다 (없으면 null).
     *
     * strict CSP 에서 인라인 스크립트가 실행되지 않으므로 Step 5 는 토큰을 `#installer-step-data`
     * JSON 블록의 `stepwise_token` 키로 싣고, step-5-installation.js 가 그 값을 전역으로 옮긴다.
     * 그래서 화면에 실린 토큰은 그 블록을 JSON 으로 해석해 읽는다.
     *
     * @param  string  $html  Step 5 렌더 결과 HTML
     * @return string|null 화면에 실린 토큰 값, 실리지 않았으면 null
     */
    private function renderedToken(string $html): ?string
    {
        $matched = preg_match(
            '/<script\b[^>]*\bid="installer-step-data"[^>]*>(.*?)<\/script>/s',
            $html,
            $m
        );
        $this->assertSame(1, $matched, 'Step 5 가 화면 데이터 블록(#installer-step-data)을 내보내지 않는다 — 이 검사의 기준점이 사라졌다');

        $data = json_decode(trim($m[1]), true);
        $this->assertIsArray($data, 'Step 5 화면 데이터 블록이 JSON 으로 해석되지 않는다');
        $this->assertArrayHasKey(
            'stepwise_token',
            $data,
            'Step 5 가 토큰 전달 키(stepwise_token)를 내보내지 않는다 — 이 검사의 기준점이 사라졌다'
        );

        return is_string($data['stepwise_token']) ? $data['stepwise_token'] : null;
    }

    /**
     * 진행 중인 단계별 설치를 시작한 세션의 표식으로 Step 5 를 렌더하면 발급된 토큰이 화면에 실리는지 확인한다.
     *
     * @scenario installation_mode=stepwise, session_ownership=owner
     *
     * @effects installer_stepwise_token_is_rendered_only_for_the_owning_session
     *
     * @return void 반환값 없음
     */
    public function test_설치를_시작한_세션에는_토큰이_실린다(): void
    {
        $this->seedRunningStepwiseInstall();

        $html = $this->renderStep5(['installer_session_owner' => self::OWNER]);

        $this->assertSame(
            self::TOKEN,
            $this->renderedToken($html),
            '소유 세션에서 토큰이 빠지면 새로고침 뒤 단계별 실행을 이어갈 수 없다'
        );
    }

    /**
     * 세션 없는 요청으로 Step 5 를 렌더하면 토큰 칸이 비고 토큰 문자열이 화면 어디에도 없는지 확인한다.
     *
     * @scenario installation_mode=stepwise, session_ownership=absent
     *
     * @effects installer_stepwise_token_is_rendered_only_for_the_owning_session
     *
     * @return void 반환값 없음
     */
    public function test_세션이_없는_요청에는_토큰이_실리지_않는다(): void
    {
        $this->seedRunningStepwiseInstall();

        $html = $this->renderStep5([]);

        $this->assertNull(
            $this->renderedToken($html),
            '무세션 GET 한 번으로 토큰이 유출되면 run-task·abort·reset·skip_task·finalize 게이트가 통째로 우회된다'
        );
        $this->assertStringNotContainsString(
            self::TOKEN,
            $html,
            '토큰 문자열이 화면 어디에도 남아 있으면 안 된다'
        );
    }

    /**
     * 다른 소유자 표식을 가진 세션으로 Step 5 를 렌더하면 토큰이 실리지 않는지 확인한다.
     *
     * @scenario installation_mode=stepwise, session_ownership=mismatch
     *
     * @effects installer_stepwise_token_is_rendered_only_for_the_owning_session
     *
     * @return void 반환값 없음
     */
    public function test_다른_세션의_표식으로는_토큰이_실리지_않는다(): void
    {
        $this->seedRunningStepwiseInstall();

        $html = $this->renderStep5(['installer_session_owner' => strrev(self::OWNER)]);

        $this->assertNull(
            $this->renderedToken($html),
            '표식이 다른 세션은 이 설치를 시작한 세션이 아니다'
        );
        $this->assertStringNotContainsString(self::TOKEN, $html);
    }
}
