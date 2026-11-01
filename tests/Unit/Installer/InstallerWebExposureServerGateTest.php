<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 웹 노출 점검(B-4)의 **서버측** 게이트 (14차 G3 W2-3).
 *
 * 판정은 브라우저가 내리고 [다음 단계] 버튼도 화면이 잠근다 — 그런데 서버는 그 값을
 * 로그에만 싣고 `exposed` 인 POST 도 그대로 Step 3 으로 넘겼다. 화면 게이트는 개발자
 * 도구 한 번으로 풀리므로, 「노출됨」으로 판정된 배치가 설치 로그에 그 사실을 남긴 채
 * 그대로 설치되는 상태였다. 서버가 같은 닫힌 집합으로 판정해 `exposed` 면 Step 2 에
 * 머문다. `safe`·`confirmed`(판정 불능을 운영자가 확인)·`unknown`·미보고는 종전대로다.
 *
 * `safe` 경로는 `redirectToStep()` 이 `exit` 하므로 프로세스 안에서 직접 부를 수 없다 —
 * 그 경로는 소스 대조로 잠근다.
 *
 * 효과 요약(마커 아님 — 평문): 노출됨 판정의 서버측 거부와 2단계 머무름. 표식은 각 테스트 메서드에 있다.
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerWebExposureServerGateTest extends TestCase
{
    use CleansTemporaryBasePath;
    use ScansPhpSourceFunctions;

    /**
     * 번역 대역과 기록에 필요한 설치기 상수를 갖추고, 실제 저장소 루트를 박지 않도록 임시 BASE_PATH 아래에서 요청 처리기 파일들을 불러온다.
     *
     * @return void 반환값 없음
     */
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__.'/stubs/lang_stub.php';

        $projectRoot = dirname(__DIR__, 3);
        if (! defined('BASE_PATH')) {
            $base = sys_get_temp_dir().'/g7-installer-exposure-gate-'.bin2hex(random_bytes(4));
            @mkdir($base.'/storage/logs', 0755, true);
            define('BASE_PATH', $base);
        }
        if (! defined('SUPPORTED_LANGUAGES')) {
            define('SUPPORTED_LANGUAGES', ['ko' => '한국어', 'en' => 'English']);
        }
        if (! defined('MAX_APP_NAME_LENGTH')) {
            define('MAX_APP_NAME_LENGTH', 100);
        }
        // config.php 를 require 하면 BASE_PATH 가 실 저장소 루트로 박히므로(격리 규약) addLog 가
        // 읽는 기본 상태만 같은 형태로 세운다.
        if (! defined('DEFAULT_INSTALLATION_STATE')) {
            define('DEFAULT_INSTALLATION_STATE', ['current_step' => 0, 'step_status' => [], 'completed_tasks' => [], 'config' => []]);
        }

        require_once $projectRoot.'/public/install/includes/functions.php';
        require_once $projectRoot.'/public/install/includes/installer-state.php';
        require_once $projectRoot.'/public/install/includes/request-handler.php';
    }

    /**
     * BASE_PATH 가 임시 폴더 아래가 아니면 건너뛰고, 이전 테스트가 남긴 설치 기록 파일을 지운다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
        if (strpos($resolved, $tempPrefix) !== 0) {
            $this->markTestSkipped('BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — 격리 실행: php vendor/bin/phpunit --filter=InstallerWebExposureServerGateTest');
        }

        $log = BASE_PATH.'/storage/logs/installation.log';
        if (is_file($log)) {
            unlink($log);
        }
    }

    /**
     * 테스트가 채운 POST 값을 비운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    /**
     * 노출됨 판정을 실은 2단계 POST 가 오류 키를 받고 서버측 차단이 설치 기록에 남는지 확인한다.
     *
     * @scenario exposure_probe=exposed
     *
     * @effects exposed_verdict_is_rejected_server_side_and_stays_on_step_2
     *
     * @return void 반환값 없음
     */
    public function test_exposed_verdict_keeps_step_2_and_reports_an_error(): void
    {
        $_POST = ['proceed' => '1', 'web_exposure_verdict' => 'exposed'];
        $error = null;

        handleStep2Post($error);

        $this->assertSame('error_web_exposure_blocked', $error, '노출됨 판정의 POST 가 오류 없이 통과한다');

        $log = (string) @file_get_contents(BASE_PATH.'/storage/logs/installation.log');
        $this->assertStringContainsString('log_web_exposure_blocked', $log, '서버측 차단이 설치 로그에 남지 않는다');
    }

    /**
     * 판정값의 정규화는 한 곳이다 — 로그 문구와 게이트가 서로 다른 집합을 보면 한쪽이 샌다.
     *
     * @scenario exposure_probe=exposed, exposure_probe=safe, exposure_probe=unknown, exposure_probe=unknown_confirmed
     *
     * @effects exposed_verdict_is_rejected_server_side_and_stays_on_step_2
     *
     * @return void 반환값 없음
     */
    public function test_verdict_normalization_is_a_closed_set_shared_by_label_and_gate(): void
    {
        $this->assertSame('exposed', installerNormalizeWebExposureVerdict('exposed'));
        $this->assertSame('safe', installerNormalizeWebExposureVerdict('safe'));
        $this->assertSame('confirmed', installerNormalizeWebExposureVerdict('confirmed'));
        $this->assertSame('unknown', installerNormalizeWebExposureVerdict('unknown'));
        $this->assertSame('unreported', installerNormalizeWebExposureVerdict(null));
        $this->assertSame('unreported', installerNormalizeWebExposureVerdict('EXPOSED'), '대소문자 변형은 집합 밖이다 — 화면은 소문자만 보낸다');
        $this->assertSame('unreported', installerNormalizeWebExposureVerdict(['exposed']));

        $handler = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/includes/request-handler.php');
        $this->assertStringContainsString(
            'installerNormalizeWebExposureVerdict(',
            $this->functionBody($handler, 'installerWebExposureVerdictLabel'),
            '로그 문구가 게이트와 다른 정규화를 쓴다'
        );
    }

    /**
     * 숨은 필드의 초기값 `pending` 도 닫힌 집합의 일원이다 (16차 G3 D-6).
     *
     * 화면은 판정 전 `pending` 을 싣고 태어난다(`public/install/views/2-requirements.php`). 프로브가 끝나기
     * 전에 POST 가 오면 서버는 그 값을 받는데, 집합 밖으로 접으면 로그에 「보고되지 않음」
     * — 배선 파손 신호 — 이 남는다. 그 둘은 운영자가 할 일이 다르므로 구분해 남긴다.
     *
     * @scenario exposure_probe=unknown
     *
     * @effects installer_web_exposure_pending_verdict_is_in_the_closed_set
     *
     * @return void 반환값 없음
     */
    public function test_pending_verdict_is_in_the_closed_set_with_its_own_label(): void
    {
        $this->assertSame('pending', installerNormalizeWebExposureVerdict('pending'), '숨은 필드 초기값이 집합 밖으로 접힌다');

        $handler = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/includes/request-handler.php');
        $this->assertStringContainsString(
            "'pending' => lang('web_exposure_verdict_pending')",
            $this->functionBody($handler, 'installerWebExposureVerdictLabel'),
            'pending 판정에 전용 로그 문구가 없다'
        );

        foreach (['ko', 'en'] as $locale) {
            $lang = require dirname(__DIR__, 3).'/public/install/lang/'.$locale.'.php';
            $this->assertArrayHasKey('web_exposure_verdict_pending', $lang, $locale.' 에 pending 로그 문구가 없다');
        }
    }

    /**
     * 비-노출 판정은 종전대로 진행한다 — 그리고 진입점이 Step 2 핸들러에 `$error` 를 넘기며
     * 화면이 그것을 그린다. 함수만 받고 배선이 없으면 차단은 「조용한 무반응」이 된다.
     *
     * @scenario exposure_probe=safe
     *
     * @effects exposed_verdict_is_rejected_server_side_and_stays_on_step_2
     *
     * @return void 반환값 없음
     */
    public function test_non_exposed_verdicts_still_advance_and_the_error_is_wired_to_the_screen(): void
    {
        $root = dirname(__DIR__, 3);
        $handler = $this->sourceWithoutComments($root.'/public/install/includes/request-handler.php');
        $body = $this->functionBody($handler, 'handleStep2Post');

        $this->assertStringContainsString('updateStepStatus(2, 3)', $body, '진행 경로가 사라졌다');
        $this->assertStringContainsString('redirectToStep(3)', $body, '진행 경로가 사라졌다');
        $this->assertMatchesRegularExpression(
            '/2\s*=>\s*handleStep2Post\(\$error\)/',
            $this->functionBody($handler, 'handlePostRequest'),
            '디스패치가 Step 2 핸들러에 $error 를 넘기지 않는다'
        );

        $view = $this->sourceWithoutComments($root.'/public/install/views/2-requirements.php');
        $this->assertStringContainsString('htmlspecialchars($error)', $view, 'Step 2 화면이 서버측 오류를 그리지 않는다');

        foreach (['ko', 'en'] as $locale) {
            $lang = require $root.'/public/install/lang/'.$locale.'.php';
            $this->assertArrayHasKey('error_web_exposure_blocked', $lang, $locale.' 에 차단 문구가 없다');
            $this->assertArrayHasKey('log_web_exposure_blocked', $lang, $locale.' 에 차단 로그 문구가 없다');
        }
    }
}
