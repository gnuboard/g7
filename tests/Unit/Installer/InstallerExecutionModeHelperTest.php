<?php

namespace Tests\Unit\Installer;

use App\Support\ExecutionEnvironment;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;

/**
 * 인스톨러 실행 모드 판정 헬퍼(includes/execution-mode.php) 단위 테스트.
 *
 * Step 3 선택값(auto|subprocess|inprocess)과 환경 감지를 합쳐 유효 모드를 정하는 규칙,
 * 그리고 inprocess 가 유효할 때 Vendor 설치 방식을 bundled 로 고정하는 규칙(A-4)을 고정한다.
 * 명시 선택은 감지를 건너뛰어야 하고(R9/R10 — 모드 전파), 저장 상태에 박힌 확정값은
 * 재판정보다 우선해야 한다(R1 — 진입 1회 결정).
 *
 * 커버 축: execution_mode_choice auto·subprocess·inprocess·invalid / vendor_mode_choice auto·composer·bundled / execution_mode_resolved subprocess·inprocess·absent / installation_mode sse·polling·stepwise
 *
 * @effects installer_execution_mode_explicit_choice_skips_detection, installer_execution_mode_inprocess_forces_bundled_vendor, installer_execution_mode_resolved_state_wins_over_redetection, installer_resolved_php_binary_prefers_explicit_then_detected, installer_progress_mode_guards_are_symmetric, installer_stepwise_blocked_when_composer_can_run, installer_installation_mode_forced_to_stepwise_under_inprocess, installer_finalize_rejects_unfinished_or_unauthorized_requests, installer_start_rejects_live_worker_and_token_hijack, installer_token_rejection_separates_missing_from_mismatch
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerExecutionModeHelperTest extends TestCase
{
    use CleansTemporaryBasePath;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__.'/stubs/lang_stub.php';

        if (! defined('BASE_PATH')) {
            define('BASE_PATH', sys_get_temp_dir().'/g7-installer-execmode-'.bin2hex(random_bytes(4)));
        }

        require_once dirname(__DIR__, 3).'/public/install/includes/execution-mode.php';
    }

    protected function setUp(): void
    {
        parent::setUp();

        // R7 — BASE_PATH 가 실 저장소 루트면 번들 존재 판정이 실 파일을 읽는다. temp 하위가 아니면 skip.
        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
        if (strpos($resolved, $tempPrefix) !== 0) {
            $this->markTestSkipped('BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — 격리 실행: php vendor/bin/phpunit --filter=InstallerExecutionModeHelperTest');
        }
    }

    public function test_normalize_accepts_known_values_and_falls_back_to_auto(): void
    {
        $this->assertSame('auto', installerNormalizeExecutionMode('auto'));
        $this->assertSame('subprocess', installerNormalizeExecutionMode('subprocess'));
        $this->assertSame('inprocess', installerNormalizeExecutionMode(' inprocess '));
        $this->assertSame('auto', installerNormalizeExecutionMode('nonsense'));
        $this->assertSame('auto', installerNormalizeExecutionMode(null));
        $this->assertSame('auto', installerNormalizeExecutionMode(['inprocess']));
    }

    /**
     * 명시 선택은 감지를 건너뛴다 — source 가 explicit 이고 detection 이 null 이다.
     */
    public function test_explicit_choice_skips_detection(): void
    {
        $sub = installerResolveExecutionMode(['execution_mode' => 'subprocess', 'php_binary' => '/nonexistent/php']);
        $this->assertSame(ExecutionEnvironment::MODE_SUBPROCESS, $sub['mode']);
        $this->assertSame('explicit', $sub['source']);
        $this->assertNull($sub['detection']);

        $in = installerResolveExecutionMode(['execution_mode' => 'inprocess']);
        $this->assertSame(ExecutionEnvironment::MODE_INPROCESS, $in['mode']);
        $this->assertSame('explicit', $in['source']);
    }

    /**
     * auto 는 감지 결과를 따른다 — 일반 개발 환경(함수 가용 + php 실행 가능)은 subprocess.
     */
    public function test_auto_follows_detection(): void
    {
        if (! ExecutionEnvironment::hasFunction('exec')) {
            $this->markTestSkipped('exec 차단 환경 — 감지 결과가 inprocess 로 고정된다 (P0 프로필)');
        }

        $auto = installerResolveExecutionMode(['execution_mode' => 'auto', 'php_binary' => PHP_BINARY]);

        $this->assertSame('detected', $auto['source']);
        $this->assertIsArray($auto['detection']);
        $this->assertArrayHasKey('bundle_available', $auto['detection']);
        $this->assertSame(ExecutionEnvironment::MODE_SUBPROCESS, $auto['mode']);
    }

    /**
     * A-4 — inprocess 가 유효하면 Vendor 설치 방식은 bundled 로 고정된다 (auto 포함).
     */
    public function test_inprocess_forces_bundled_vendor_mode(): void
    {
        $this->assertSame('bundled', installerCoerceVendorModeForExecution('composer', ExecutionEnvironment::MODE_INPROCESS));
        $this->assertSame('bundled', installerCoerceVendorModeForExecution('auto', ExecutionEnvironment::MODE_INPROCESS));
        $this->assertSame('bundled', installerCoerceVendorModeForExecution('bundled', ExecutionEnvironment::MODE_INPROCESS));
    }

    /**
     * subprocess 에서는 운영자 선택을 그대로 존중한다 (기본값 불변 — 현행과 동일).
     */
    public function test_subprocess_keeps_vendor_mode_choice(): void
    {
        $this->assertSame('composer', installerCoerceVendorModeForExecution('composer', ExecutionEnvironment::MODE_SUBPROCESS));
        $this->assertSame('auto', installerCoerceVendorModeForExecution('auto', ExecutionEnvironment::MODE_SUBPROCESS));
        $this->assertSame('bundled', installerCoerceVendorModeForExecution('bundled', ExecutionEnvironment::MODE_SUBPROCESS));
        $this->assertSame('auto', installerCoerceVendorModeForExecution('weird', ExecutionEnvironment::MODE_SUBPROCESS));
    }

    /**
     * R1 — 설치 시작 시 state 에 박힌 확정값이 있으면 재판정하지 않는다.
     */
    public function test_resolved_state_wins_over_redetection(): void
    {
        $state = [
            'execution_mode_resolved' => ExecutionEnvironment::MODE_INPROCESS,
            'config' => ['execution_mode' => 'subprocess'],
        ];

        $this->assertSame(ExecutionEnvironment::MODE_INPROCESS, installerEffectiveExecutionMode($state));
    }

    public function test_effective_mode_falls_back_to_config_when_not_resolved(): void
    {
        $state = ['config' => ['execution_mode' => 'inprocess']];

        $this->assertSame(ExecutionEnvironment::MODE_INPROCESS, installerEffectiveExecutionMode($state));
    }

    public function test_reasons_describe_blocked_functions_or_probe_failure(): void
    {
        // lang() 은 번역이 주입돼 있을 때만 치환한다 — 스텁/실제 구현 모두 같은 계약
        $GLOBALS['translations'] = [
            'execution_mode_reason_functions' => ':functions blocked',
            'execution_mode_reason_php_probe' => 'php probe failed',
        ];

        $blocked = installerExecutionModeReasons([
            'blocked_functions' => ['exec', 'proc_open'],
            'php_probe' => ['status' => 'unavailable'],
        ]);
        $this->assertCount(1, $blocked);
        $this->assertStringContainsString('exec, proc_open', $blocked[0]);

        $probe = installerExecutionModeReasons([
            'blocked_functions' => [],
            'php_probe' => ['status' => 'invalid'],
        ]);
        $this->assertCount(1, $probe);

        $this->assertSame([], installerExecutionModeReasons([
            'blocked_functions' => [],
            'php_probe' => ['status' => 'valid'],
        ]));
    }

    /**
     * 명시 선택은 이 서버에서 성립할 때만 저장된다 (A-4 대칭).
     *
     * A-4 는 Vendor 축에서 「화면만 막고 서버는 안 막는다」를 닫았는데 실행 방식 축에는 같은
     * 게이트가 없었다 — 그래서 고를 수 없는 방식을 저장할 수 있었고, 그 실패는 설치 중반
     * (태스크 실행)에야 드러났다. 판정은 순수 함수라 환경과 무관하게 고정할 수 있다.
     *
     * `auto` 는 감지가 정하므로 성립하지 않는 값이 될 수 없다 — 절대 거부되지 않는다.
     */
    public function test_explicit_execution_mode_is_rejected_when_the_environment_cannot_run_it(): void
    {
        $blockedNoBundle = ['blocked_functions' => ['exec', 'proc_open'], 'bundle_available' => false];
        $blockedWithBundle = ['blocked_functions' => ['exec', 'proc_open'], 'bundle_available' => true];
        $openNoBundle = ['blocked_functions' => [], 'bundle_available' => false];
        $openWithBundle = ['blocked_functions' => [], 'bundle_available' => true];

        // subprocess — 필수 함수가 하나라도 막히면 별도 프로세스를 띄울 방법이 없다.
        $this->assertSame(
            'error_execution_mode_subprocess_unavailable',
            installerExecutionModeRejection(ExecutionEnvironment::MODE_SUBPROCESS, $blockedWithBundle),
        );
        $this->assertNull(installerExecutionModeRejection(ExecutionEnvironment::MODE_SUBPROCESS, $openNoBundle));

        // inprocess — 동봉 묶음을 쓸 수 없으면 의존성을 놓을 방법이 없다.
        $this->assertSame(
            'error_execution_mode_inprocess_unavailable',
            installerExecutionModeRejection(ExecutionEnvironment::MODE_INPROCESS, $openNoBundle),
        );
        $this->assertNull(installerExecutionModeRejection(ExecutionEnvironment::MODE_INPROCESS, $blockedWithBundle));

        // auto 는 어떤 환경에서도 거부되지 않는다.
        foreach ([$blockedNoBundle, $blockedWithBundle, $openNoBundle, $openWithBundle] as $detection) {
            $this->assertNull(
                installerExecutionModeRejection('auto', $detection),
                'auto 는 감지가 정한다 — 저장 게이트의 대상이 아니다',
            );
        }
    }

    /**
     * 거부 사유는 두 로케일에 정의돼 있어야 한다 — 없으면 화면에 키 문자열이 그대로 나간다.
     */
    public function test_execution_mode_rejection_keys_are_defined_in_every_locale(): void
    {
        $root = dirname(__DIR__, 3).'/public/install/lang';

        foreach (['ko', 'en'] as $locale) {
            /** @var array<string, mixed> $table */
            $table = require $root.'/'.$locale.'.php';

            foreach ([
                'error_execution_mode_subprocess_unavailable',
                'error_execution_mode_inprocess_unavailable',
            ] as $key) {
                $this->assertArrayHasKey($key, $table, $locale.' 로케일에 '.$key.' 가 없다');
                $this->assertNotSame('', trim((string) $table[$key]));
            }
        }
    }

    /**
     * D35 — 설치 시작 시 확정하는 PHP 바이너리: 운영자 명시 > 자동 탐색 결과 > 'php'.
     */
    public function test_resolved_php_binary_prefers_explicit_then_detected(): void
    {
        $detected = ['mode' => 'subprocess', 'source' => 'detected', 'detection' => ['php_binary' => '/opt/php/bin/php']];

        $this->assertSame('/opt/php/bin/php', installerResolvedPhpBinary(['php_binary' => 'php'], $detected));
        $this->assertSame('/opt/php/bin/php', installerResolvedPhpBinary([], $detected));
        $this->assertSame('/usr/bin/php82', installerResolvedPhpBinary(['php_binary' => '/usr/bin/php82'], $detected));
        $this->assertSame('php', installerResolvedPhpBinary(['php_binary' => 'php'], ['mode' => 'subprocess', 'source' => 'explicit', 'detection' => null]));
        $this->assertSame('php', installerResolvedPhpBinary(['php_binary' => 'php'], ['mode' => 'inprocess', 'source' => 'detected', 'detection' => ['php_binary' => '']]));
    }

    /**
     * 단계별 실행 창구의 토큰 판정을 행위로 고정한다 (R3).
     *
     * run-task.php 는 인스톨러에서 Artisan 커맨드를 실행하는 유일한 창구이고 이 판정이
     * 그 인증 전부다. 「가드 호출이 지워지지 않았다」를 보는 소스 대조로는 `hash_equals`
     * 를 `==` 로 바꾸거나 비교를 뒤집는 회귀를 잡지 못한다 — 판정 함수를 직접 부른다.
     */
    public function test_stepwise_token_judgment_accepts_only_the_exact_issued_token(): void
    {
        $issued = str_repeat('a', 63).'b';

        $this->assertTrue(
            installerStepwiseTokenAccepted($issued, $issued),
            '발급된 토큰과 정확히 같으면 통과한다'
        );

        $this->assertFalse(installerStepwiseTokenAccepted(str_repeat('a', 63).'c', $issued), '한 글자만 달라도 거부한다');
        $this->assertFalse(installerStepwiseTokenAccepted(substr($issued, 0, 32), $issued), '접두사만 같은 값은 거부한다');
        $this->assertFalse(installerStepwiseTokenAccepted($issued.'x', $issued), '뒤에 덧붙인 값은 거부한다');

        // 느슨한 비교(`==`)로 되돌리면 통과해 버리는 조합 — PHP 의 숫자 문자열 비교에서
        // '0e...' 형태끼리는 == 가 참이 된다. hash_equals 는 이를 거부한다.
        $this->assertFalse(
            installerStepwiseTokenAccepted('0e111111111111111111111111111111', '0e222222222222222222222222222222'),
            '느슨한 비교였다면 통과하는 조합이 거부되어야 한다'
        );

        // 토큰 미발급 상태에서 빈 토큰을 제시하면 통과하는 구멍이 생기지 않아야 한다
        $this->assertFalse(installerStepwiseTokenAccepted('', ''), '미발급 상태의 빈 토큰은 거부한다');
        $this->assertFalse(installerStepwiseTokenAccepted('anything', ''), '미발급 상태에서는 어떤 값도 거부한다');
        $this->assertFalse(installerStepwiseTokenAccepted('', $issued), '빈 제시값은 거부한다');
    }

    /**
     * 두 진행 입구의 거부 규칙이 대칭인지 고정한다 (R1 — 모드는 진입 1회 결정).
     *
     * 한쪽만 고치면 그 입구가 우회로가 된다. 소스 문자열 검사로는 판정 로직이 뒤집혀도
     * 통과하므로 판정 함수 자체를 부른다.
     */
    public function test_progress_mode_guards_are_symmetric_between_two_entry_points(): void
    {
        $stepwiseRunning = ['installation_mode' => 'stepwise', 'installation_status' => 'running'];

        // stepwise 로 확정된 설치: run-task 는 통과, SSE 워커는 거부
        $this->assertNull(installerStepwiseEndpointRejection($stepwiseRunning));
        $this->assertTrue(installerSseWorkerRejected($stepwiseRunning));

        // stepwise 가 아닌 설치: 정확히 반대
        foreach (['sse', 'polling'] as $mode) {
            $state = ['installation_mode' => $mode, 'installation_status' => 'running'];
            $rejection = installerStepwiseEndpointRejection($state);
            $this->assertNotNull($rejection, $mode.' 설치는 run-task 를 쓰지 않는다');
            $this->assertSame('error_stepwise_mode_mismatch', $rejection['message_key']);
            $this->assertSame(409, $rejection['status']);
            $this->assertFalse(installerSseWorkerRejected($state));
        }

        // 진행 모드 미기록(레거시 state)은 SSE 로 본다
        $this->assertFalse(installerSseWorkerRejected([]));
        $this->assertNotNull(installerStepwiseEndpointRejection([]));
    }

    /**
     * 단계별 실행 + composer 조합은 서버가 되돌린다 (10차 #M3).
     *
     * 단계별 실행은 요청 하나가 180초를 사는데 `composer install` 은 그보다 오래 걸리는 것이
     * 정상이고, 그 구간은 러너를 거치지 않아 시간 예산이 올라가지도 않는다 — 조합을 허용하면
     * 의존성이 반쯤 설치된 채 요청이 죽는다. 화면이 비활성으로 막지만 저장된 값·직접 요청이
     * 그대로 들어올 수 있으므로 판정은 서버가 다시 적용한다 (R9).
     */
    public function test_stepwise_is_blocked_when_composer_can_run(): void
    {
        // subprocess + composer 계열(auto 포함 — auto 는 composer 가 있으면 composer 를 돈다)
        foreach (['auto', 'composer'] as $vendorMode) {
            $this->assertTrue(
                installerStepwiseBlockedByVendorMode($vendorMode, 'subprocess'),
                $vendorMode.' 는 composer 가 돌 수 있어 단계별 실행과 함께 고를 수 없다'
            );
            $this->assertSame(
                'sse',
                installerCoerceInstallationModeForVendor('stepwise', $vendorMode, 'subprocess'),
                '막힌 조합은 기본 진행 방식으로 되돌린다'
            );
        }

        // subprocess + bundled 는 성립한다 — 운영자가 고를 수 있어야 한다
        $this->assertFalse(installerStepwiseBlockedByVendorMode('bundled', 'subprocess'));
        $this->assertSame('stepwise', installerCoerceInstallationModeForVendor('stepwise', 'bundled', 'subprocess'));

        // inprocess 는 Vendor 방식이 bundled 로 고정되므로(A-4) 어떤 입력이 와도 막지 않는다 —
        // 그 모드에서 단계별 실행은 유일하게 성립하는 진행 방식이다
        foreach (['auto', 'composer', 'bundled'] as $vendorMode) {
            $this->assertFalse(
                installerStepwiseBlockedByVendorMode($vendorMode, 'inprocess'),
                'inprocess 에서 단계별 실행을 막으면 그 환경에는 쓸 수 있는 진행 방식이 없다'
            );
            $this->assertSame('stepwise', installerCoerceInstallationModeForVendor('stepwise', $vendorMode, 'inprocess'));
        }

        // 단계별 실행이 아닌 요청은 건드리지 않는다
        foreach (['sse', 'polling'] as $mode) {
            $this->assertSame($mode, installerCoerceInstallationModeForVendor($mode, 'composer', 'subprocess'));
        }
    }

    /**
     * 진행 방식 확정 — inprocess 강제와 composer 조합 차단이 한 판정에 있다 (10차 #G1 · #M3).
     *
     * inprocess 강제는 A-2·R1 의 뼈대인데 저장소 전체에서 참조 0 이었다: 이 줄을 지워도 전
     * 스위트가 초록이고, 결과는 inprocess 설치가 한 요청에 전 태스크를 연속 실행하는 것
     * (= A-2 가 피하려던 컨테이너 오염). SSE 워커 쪽 2차 방어도 「stepwise 인가」를 보므로
     * 함께 발화하지 않는다.
     */
    public function test_installation_mode_is_forced_to_stepwise_under_inprocess(): void
    {
        foreach (['sse', 'polling', 'stepwise', 'garbage'] as $requested) {
            $decision = installerResolveInstallationMode($requested, 'inprocess', 'bundled');

            $this->assertSame('stepwise', $decision['mode'], $requested.' 요청이 inprocess 에서 stepwise 로 확정되지 않았다');
            $this->assertSame($requested !== 'stepwise', $decision['forced'], '강제 여부 표기가 실제 전환과 어긋난다');
            $this->assertFalse($decision['downgraded']);
        }

        // subprocess 는 요청을 존중한다 (bundled — composer 차단 규칙에 걸리지 않는 조합)
        foreach (['sse', 'polling', 'stepwise'] as $requested) {
            $decision = installerResolveInstallationMode($requested, 'subprocess', 'bundled');
            $this->assertSame($requested, $decision['mode']);
            $this->assertFalse($decision['forced']);
            $this->assertFalse($decision['downgraded']);
        }

        // 허용 어휘 밖 값은 기본 진행 방식으로
        $this->assertSame('sse', installerResolveInstallationMode('garbage', 'subprocess', 'bundled')['mode']);

        // subprocess + composer 는 단계별 실행을 내린다 (#M3)
        $downgraded = installerResolveInstallationMode('stepwise', 'subprocess', 'composer');
        $this->assertSame('sse', $downgraded['mode']);
        $this->assertTrue($downgraded['downgraded'], '내렸다는 사실을 표기하지 않으면 화면이 그 사유를 설명할 수 없다');
    }

    /**
     * run-task 는 running 과 completed 만 받는다 — completed 는 complete_flag 직후
     * config_cache 재개용이라 열려 있어야 한다.
     */
    public function test_stepwise_endpoint_accepts_only_running_and_completed(): void
    {
        foreach (['running', 'completed'] as $status) {
            $this->assertNull(installerStepwiseEndpointRejection([
                'installation_mode' => 'stepwise',
                'installation_status' => $status,
            ]), $status.' 는 통과해야 한다');
        }

        $cases = ['aborted' => 'aborted', 'failed' => 'failed', 'pending' => 'rejected'];
        foreach ($cases as $status => $expectedOutcome) {
            $rejection = installerStepwiseEndpointRejection([
                'installation_mode' => 'stepwise',
                'installation_status' => $status,
            ]);
            $this->assertNotNull($rejection, $status.' 는 거부해야 한다');
            $this->assertSame($expectedOutcome, $rejection['outcome']);
            $this->assertSame('error_stepwise_not_running', $rejection['message_key']);
            $this->assertSame(409, $rejection['status']);
        }
    }

    /**
     * Step 3 재감지가 쓰는 PHP 경로 정규화 — 되돌렸으면 그 사실을 함께 돌려준다.
     *
     * 이 판정이 엔드포인트 안에 있던 동안은 어떤 단위·픽스처·소스 대조도 없었다
     * (`detect-execution-mode` 액션 전체가 검사 0). 되돌림 사실을 버리면 위쪽 입력 상자의
     * 빨강과 아래 배지의 파랑이 한 화면에서 서로를 부정한다.
     */
    public function test_detection_php_binary_reports_when_it_falls_back_to_default(): void
    {
        $ok = installerDetectionPhpBinary('/usr/local/bin/php');
        $this->assertSame('/usr/local/bin/php', $ok['binary'], '형태가 맞는 경로는 그대로 쓴다');
        $this->assertFalse($ok['substituted']);

        $plain = installerDetectionPhpBinary('php');
        $this->assertSame('php', $plain['binary']);
        $this->assertFalse($plain['substituted'], '기본값 그대로는 되돌린 것이 아니다');

        $blank = installerDetectionPhpBinary('   ');
        $this->assertSame('php', $blank['binary']);
        $this->assertFalse($blank['substituted'], '빈 입력은 "고르지 않았다" 이지 "되돌렸다" 가 아니다');

        $bad = installerDetectionPhpBinary('/usr/local/php 8.2/bin/php');
        $this->assertSame('php', $bad['binary'], '형태 위반 경로는 기본 php 로 되돌린다');
        $this->assertTrue($bad['substituted'], '되돌렸다는 사실이 화면에 도달해야 한다');
    }

    /**
     * 잡히지 않은 예외의 500 봉투도 응답 계약을 지킨다 (9차 #L1).
     *
     * `outcome` 이 없으면 클라이언트(StepwiseMonitor)는 「알 수 없는 응답」 분기로 떨어지고,
     * 같이 실린 예외 원문을 실패 화면 **본문**으로 그린다 — 8차가 HTTP 상태 표기를 본문에서
     * 진단 상세로 내린 규약(§12.0 W3)이 이 봉투에는 적용되지 않았다.
     */
    public function test_stepwise_failure_payload_keeps_the_response_contract(): void
    {
        $payload = installerStepwiseFailurePayload('SQLSTATE[HY000] boom', ['status' => 'failed']);

        $this->assertSame('failed', $payload['outcome'], 'outcome 이 없으면 클라이언트가 「알 수 없는 응답」으로 처리한다');
        $this->assertSame('error_unexpected_exception', $payload['message_key'], '사유는 번역 키로 전달한다');
        $this->assertArrayNotHasKey('message', $payload, '예외 원문이 본문 자리(message)에 실리면 실패 화면 제목이 된다');
        $this->assertSame('SQLSTATE[HY000] boom', $payload['error_detail'], '원문은 진단 상세로만 내려간다');
        $this->assertSame(['status' => 'failed'], $payload['state']);
    }

    /**
     * 거부 판정은 치환값도 함께 돌려준다 (9차 #L2 — 시그니처 드리프트).
     *
     * 같은 파일이 「치환값은 키를 정한 곳이 함께 돌려준다」를 규약으로 선언해 두고 있으므로,
     * 그 계약을 **값까지** 고정한다. 종전에는 `assertArrayHasKey` 하나여서 값이 `[]` 로 비어도
     * 초록이었고, 그 상태의 결과는 운영자 화면에 `:status` 가 원문으로 나가는 것이다
     * (`error_stepwise_not_running` 은 자리표시자를 가진 키다). 7차 #1·8차 #16 이 두 번 닫은
     * 결함군의 세 번째 재현 통로였다 (12차 M-2).
     */
    public function test_endpoint_rejection_carries_message_params(): void
    {
        $rejection = installerStepwiseEndpointRejection([
            'installation_mode' => 'stepwise',
            'installation_status' => 'failed',
        ]);

        $this->assertNotNull($rejection);
        $this->assertSame('error_stepwise_not_running', $rejection['message_key'] ?? null);
        $this->assertSame(
            ['status' => 'failed'],
            $rejection['message_params'] ?? null,
            '치환값이 비면 화면에 자리표시자가 그대로 남는다 — 키 존재만으로는 그 상태가 구분되지 않는다'
        );

        // 실제 문구로도 확인한다 — 자리표시자가 남았는지는 번역을 거쳐야만 드러난다.
        $GLOBALS['translations'] = require dirname(__DIR__, 3).'/public/install/lang/ko.php';
        $rendered = lang($rejection['message_key'], $rejection['message_params']);

        $this->assertStringNotContainsString(':status', $rendered, '치환되지 않은 자리표시자가 운영자 화면에 나간다');
        $this->assertStringContainsString('failed', $rendered);
    }

    /**
     * 건너뛰기의 토큰 요구 조건 (9차 #G2).
     *
     * 판정 함수(installerStepwiseTokenAccepted)는 행위 테스트가 덮지만, 그 함수를 **부를지
     * 말지** 정하는 조건은 종전에 엔드포인트 안의 if 였다 — 뒤집어도 red 가 되지 않았고,
     * 그 상태의 결과는 stepwise 설치의 [건너뛰고 계속] 이 무인증이 되는 것이다.
     */
    public function test_skip_requires_the_stepwise_token_only_in_stepwise_mode(): void
    {
        $this->assertTrue(installerRequiresStepwiseToken(['installation_mode' => 'stepwise']));
        $this->assertFalse(installerRequiresStepwiseToken(['installation_mode' => 'sse']));
        $this->assertFalse(installerRequiresStepwiseToken(['installation_mode' => 'polling']));
        $this->assertFalse(installerRequiresStepwiseToken([]), '모드가 없는 설치(레거시 state)는 종전대로 세션 흐름이다');
    }

    /**
     * 토큰 거부 사유를 나눈다 (11차 #A10).
     *
     * 「보관본이 없다」와 「제시한 값이 틀렸다」는 조치가 정반대다 — 후자는 새로고침으로
     * 복구되지만 전자(runtime.php 소실·판독 실패)는 몇 번을 새로고침해도 같은 403 이다.
     * 종전에는 서버가 그 차이를 알면서 같은 문구로 버렸다.
     */
    public function test_token_rejection_separates_missing_from_mismatch(): void
    {
        $missing = installerStepwiseTokenRejection('abc', '');
        $this->assertNotNull($missing);
        $this->assertSame('error_stepwise_token_missing', $missing['message_key']);
        $this->assertSame(403, $missing['status']);

        // 제시값이 비어 있어도 보관본이 없으면 사유는 「보관본 없음」이다 — 운영자의 조치가 그것이다.
        $bothEmpty = installerStepwiseTokenRejection('', '');
        $this->assertNotNull($bothEmpty);
        $this->assertSame('error_stepwise_token_missing', $bothEmpty['message_key']);

        $mismatch = installerStepwiseTokenRejection('wrong', 'right');
        $this->assertNotNull($mismatch);
        $this->assertSame('error_stepwise_token_invalid', $mismatch['message_key']);

        $empty = installerStepwiseTokenRejection('', 'right');
        $this->assertNotNull($empty, '빈 토큰은 통과하지 않는다');
        $this->assertSame('error_stepwise_token_invalid', $empty['message_key']);

        $this->assertNull(installerStepwiseTokenRejection('right', 'right'));
    }

    /**
     * 설치 마무리 엔드포인트의 진입 조건 (11차 #A1).
     *
     * 이 엔드포인트는 `.env` 에 완료 표식을 박고 runtime.php(비밀·토큰)와 상태 파일을
     * 지운다 — 인스톨러에서 가장 되돌릴 수 없는 동작인데 진입 조건이 「이미 finalize
     * 됐는가」 하나뿐이었다. 설치 진행 중에 GET 한 번이면 마이그레이션이 반쯤 된 DB +
     * 인스톨러 진입 불가가 남는다.
     */
    public function test_finalize_rejects_wrong_method_unfinished_install_and_bad_token(): void
    {
        $done = ['completed_tasks' => ['db_migrate', 'complete_flag'], 'installation_mode' => 'sse'];

        $get = installerFinalizeRejection($done, 'GET', '', '');
        $this->assertNotNull($get, 'GET 으로는 마무리할 수 없다');
        $this->assertSame(405, $get['status']);
        $this->assertSame('error_finalize_method_not_allowed', $get['message_key']);

        $running = installerFinalizeRejection(
            ['completed_tasks' => ['db_migrate'], 'installation_mode' => 'sse'],
            'POST',
            '',
            '',
        );
        $this->assertNotNull($running, '끝나지 않은 설치는 마무리하지 않는다');
        $this->assertSame(409, $running['status']);
        $this->assertSame('error_finalize_not_completed', $running['message_key']);

        // 상태 파일이 이미 없으면 finalize 가 한 번 끝난 것 — 멱등 재호출을 막으면
        // `.env` 머지 실패 뒤의 재시도 경로까지 함께 닫힌다.
        $this->assertNull(installerFinalizeRejection([], 'POST', '', ''));

        $this->assertNull(installerFinalizeRejection($done, 'POST', '', ''), 'stepwise 가 아니면 토큰을 요구하지 않는다');
        $this->assertNull(installerFinalizeRejection($done, 'post', '', ''), '메서드 판정은 대소문자를 가리지 않는다');

        $stepwise = ['completed_tasks' => ['complete_flag'], 'installation_mode' => 'stepwise'];
        $noToken = installerFinalizeRejection($stepwise, 'POST', '', 'secret');
        $this->assertNotNull($noToken, 'stepwise 설치의 마무리는 토큰을 요구한다');
        $this->assertSame(403, $noToken['status']);
        $this->assertNull(installerFinalizeRejection($stepwise, 'POST', 'secret', 'secret'));
    }

    /**
     * 설치 시작의 거부 조건 (11차 #A2·#A4).
     *
     * ① 살아 있는 워커가 있으면 거절한다 — 종전에는 두 번째 탭이 상태를 덮어쓰고
     *    `started` 를 받은 뒤 폴링 분기가 조용히 죽어 두 탭이 함께 멈췄다.
     * ② 진행 중인 stepwise 설치는 기존 토큰을 요구한다 — 이 엔드포인트가 무인증으로 새
     *    토큰을 발급해 온 탓에 10차가 [중단]·[초기화]·[건너뛰고 계속]에 건 게이트가
     *    요청 하나로 우회 가능했다.
     */
    public function test_start_rejects_live_worker_and_requires_existing_token_while_running(): void
    {
        $busy = installerStartRejection(['installation_mode' => 'sse'], true, '', '');
        $this->assertNotNull($busy);
        $this->assertSame(409, $busy['status']);
        $this->assertSame('error_installation_already_running', $busy['message_key']);

        // 첫 발급은 종전대로 무토큰이다 — 아니면 설치를 시작할 방법이 없다.
        $this->assertNull(installerStartRejection([], false, '', ''));
        $this->assertNull(installerStartRejection(['installation_mode' => 'sse'], false, '', ''));

        $running = ['installation_mode' => 'stepwise', 'installation_status' => 'running'];
        $hijack = installerStartRejection($running, false, '', 'secret');
        $this->assertNotNull($hijack, '진행 중인 단계별 설치는 기존 토큰 없이 재발급하지 않는다');
        $this->assertSame(403, $hijack['status']);
        $this->assertNull(installerStartRejection($running, false, 'secret', 'secret'));

        // 끝났거나 멈춘 설치의 재시작은 새 토큰을 받는 정상 경로다 — 막으면 복구 수단이 사라진다.
        foreach (['failed', 'aborted', 'completed', 'not_started'] as $status) {
            $this->assertNull(
                installerStartRejection(
                    ['installation_mode' => 'stepwise', 'installation_status' => $status],
                    false,
                    '',
                    'secret',
                ),
                $status.' 상태의 재시작은 통과해야 한다',
            );
        }
    }
}
