<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;

/**
 * 13차가 신설한 환경·출처·진입 전제 판정 헬퍼(includes/execution-mode.php) 단위 테스트.
 *
 * 여기 있는 판정은 전부 「엔드포인트 안의 `if` 로 두면 조건이 뒤집혀도 아무도 모르는」 종류다.
 * 그래서 판정을 한 파일이 소유하고, 그 판정 자체를 부른다 (소스 대조는 배선만 본다).
 *
 * 고정하는 계약:
 *  - 번들 가용성은 파일 존재 **와** ZipArchive 가용성을 함께 본다 (환경#4)
 *  - HTTPS 판정은 프록시 헤더를 본다 — 세션 쿠키와 요구사항 화면이 같은 답을 쓴다 (환경#17)
 *  - 상태 변경 요청은 다른 출처에서 오면 거부된다 (보안#4)
 *  - 스트리밍 워커는 진행 중이 아닌 설치를 받지 않고, 자기 모드가 아닌 설치도 받지 않는다 (보안#5·F6)
 *  - finalize 의 멱등 재호출은 **상태 파일 부재**로 판정한다 (D10)
 *
 * 커버 축: bundle_state missing·present / request_scheme direct_https·forwarded_https·plain / request_origin same·cross·absent·opaque / worker_entry running·completed·not_started·failed·polling_mode / finalize_call state_file_present·state_file_absent
 *
 * @effects installer_vendor_bundle_usability_requires_ziparchive, installer_https_judgment_is_proxy_aware, installer_cross_origin_state_change_is_rejected, installer_sse_worker_requires_running_installation, installer_finalize_idempotent_recall_uses_state_file_presence
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerEnvironmentJudgmentTest extends TestCase
{
    use CleansTemporaryBasePath;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__.'/stubs/lang_stub.php';

        if (! defined('BASE_PATH')) {
            define('BASE_PATH', sys_get_temp_dir().'/g7-installer-env-'.bin2hex(random_bytes(4)));
        }

        require_once dirname(__DIR__, 3).'/public/install/includes/execution-mode.php';
    }

    protected function setUp(): void
    {
        parent::setUp();

        // R7 — BASE_PATH 가 실 저장소 루트면 번들 판정이 실 파일을 읽는다. temp 하위가 아니면 skip.
        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
        if (strpos($resolved, $tempPrefix) !== 0) {
            $this->markTestSkipped('BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — 격리 실행: php vendor/bin/phpunit --filter=InstallerEnvironmentJudgmentTest');
        }

        if (! is_dir(BASE_PATH)) {
            mkdir(BASE_PATH, 0777, true);
        }
    }

    public function test_function_availability_is_judged_through_the_shared_resolver(): void
    {
        $this->assertTrue(installerHasFunction('strlen'), '존재하는 함수는 가용으로 판정해야 한다');
        $this->assertFalse(
            installerHasFunction('g7_definitely_not_a_real_function'),
            '없는 함수를 가용으로 판정하면 그 호출부가 그대로 치명 종료한다'
        );
    }

    public function test_vendor_bundle_usability_requires_the_archive_and_ziparchive(): void
    {
        $bundle = BASE_PATH.'/vendor-bundle.zip';
        @unlink($bundle);

        $this->assertFalse(installerVendorBundleUsable(), '묶음이 없으면 in-process 설치는 성립하지 않는다');

        file_put_contents($bundle, 'x');

        if (! class_exists('ZipArchive')) {
            // ext-zip 이 없는 환경 — 그것이 정확히 이 판정이 잡으려는 상태다.
            $this->assertFalse(
                installerVendorBundleUsable(),
                'ZipArchive 가 없으면 묶음이 있어도 풀 수 없다 — 파일 존재만 보면 Step 2 가 통과시킨 뒤 번들 설치기가 멈춘다'
            );

            return;
        }

        $this->assertTrue(installerVendorBundleUsable(), '묶음이 있고 풀 수 있으면 성립한다');

        @unlink($bundle);
    }

    public function test_https_judgment_sees_proxy_headers(): void
    {
        $this->assertFalse(installerRequestIsHttps([]));
        $this->assertFalse(installerRequestIsHttps(['HTTPS' => 'off']));
        $this->assertTrue(installerRequestIsHttps(['HTTPS' => 'on']));
        $this->assertTrue(installerRequestIsHttps(['HTTPS' => 'On']));

        // TLS 종단이 앞단인 배치가 표준이다 — 이 축이 빠지면 secure 쿠키가 붙지 않는다.
        $this->assertTrue(installerRequestIsHttps(['HTTP_X_FORWARDED_PROTO' => 'https']));
        $this->assertTrue(installerRequestIsHttps(['HTTP_X_FORWARDED_PROTO' => 'https, http']));
        $this->assertFalse(installerRequestIsHttps(['HTTP_X_FORWARDED_PROTO' => 'http']));
        $this->assertTrue(installerRequestIsHttps(['HTTP_X_FORWARDED_SSL' => 'on']));
    }

    public function test_cross_origin_state_change_is_rejected(): void
    {
        $host = ['HTTP_HOST' => 'example.test'];

        // 출처를 알 수 없으면 막지 않는다 — 두 헤더를 모두 제거하는 프록시가 있다.
        $this->assertNull(installerCrossOriginRejection($host));

        $this->assertNull(installerCrossOriginRejection($host + ['HTTP_ORIGIN' => 'https://example.test']));
        $this->assertNull(installerCrossOriginRejection($host + ['HTTP_ORIGIN' => 'http://example.test:8080']));
        $this->assertNull(installerCrossOriginRejection($host + ['HTTP_REFERER' => 'https://example.test/install/']));

        // 포트만 다른 Host 도 같은 사이트로 본다 (리버스 프록시가 Host 를 다시 쓴다).
        $this->assertNull(installerCrossOriginRejection(['HTTP_HOST' => 'example.test:8443', 'HTTP_ORIGIN' => 'https://example.test']));

        foreach ([
            ['HTTP_ORIGIN' => 'https://evil.example'],
            ['HTTP_REFERER' => 'https://evil.example/x.html'],
            ['HTTP_ORIGIN' => 'null'],
            ['HTTP_ORIGIN' => 'garbage'],
        ] as $headers) {
            $rejection = installerCrossOriginRejection($host + $headers);

            $this->assertNotNull($rejection, json_encode($headers).' 는 동일 출처가 아니다');
            $this->assertSame('error_cross_origin_request', $rejection['message_key']);
            $this->assertSame(403, $rejection['status']);
        }

        // Origin 이 있으면 그것만 본다 — Referer 로 우회되지 않는다.
        $bypass = installerCrossOriginRejection($host + [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_REFERER' => 'https://example.test/install/',
        ]);
        $this->assertNotNull($bypass, 'Origin 이 다른 출처인데 Referer 로 통과하면 검사가 무의미하다');
    }

    public function test_sse_worker_requires_a_running_installation_of_its_own_mode(): void
    {
        // 진행 중인 설치만 받는다 — 종전에는 상태 전제가 없어 GET 하나로 전 태스크가 돌았다.
        $this->assertNull(installerSseWorkerStateRejection(['installation_mode' => 'sse', 'installation_status' => 'running']));
        $this->assertNull(installerSseWorkerStateRejection(['installation_status' => 'running']), '진행 모드 미기록은 sse 로 본다');
        $this->assertNull(
            installerSseWorkerStateRejection(['installation_mode' => 'sse', 'installation_status' => 'completed']),
            '마지막 태스크 직후의 재연결 창은 형제 입구와 같은 허용 집합이다'
        );

        foreach (['not_started', 'ready', 'failed', 'aborted', null] as $status) {
            $rejection = installerSseWorkerStateRejection(['installation_mode' => 'sse', 'installation_status' => $status]);

            $this->assertNotNull($rejection, var_export($status, true).' 상태의 설치를 워커가 몰아서는 안 된다');
            $this->assertSame('error_sse_not_running', $rejection['message_key']);
            $this->assertSame(409, $rejection['status']);
        }

        $this->assertNotNull(installerSseWorkerStateRejection([]), '설정도 저장되지 않은 상태를 GET 하나로 실행할 수 없다');

        // 이 입구의 모드는 sse 다 — 다른 모드로 확정된 설치는 거부한다 (형제 판정과 대칭).
        foreach (['polling', 'stepwise'] as $mode) {
            $rejection = installerSseWorkerStateRejection(['installation_mode' => $mode, 'installation_status' => 'running']);

            $this->assertNotNull($rejection, $mode.' 로 확정된 설치를 스트리밍 워커가 몰아서는 안 된다');
            $this->assertSame('error_sse_mode_mismatch', $rejection['message_key']);
        }
    }

    public function test_finalize_idempotent_recall_is_judged_by_state_file_presence(): void
    {
        // getInstallationState() 는 파일 부재 시 **기본 상태**(비어 있지 않다)를 돌려준다.
        // 그래서 「빈 배열인가」로 판정하면 멱등 재호출 분기가 실제 경로에서 한 번도 참이 되지
        // 않고, finalize 재시도가 409(미완료)로 막힌다.
        $defaultShapedState = ['installation_status' => 'not_started', 'completed_tasks' => []];

        $this->assertNull(
            installerFinalizeRejection($defaultShapedState, 'POST', '', '', false),
            '상태 파일이 사라졌으면 finalize 는 한 번 끝난 것이다 — 재호출을 막으면 .env 머지 재시도 경로가 함께 닫힌다'
        );

        $rejection = installerFinalizeRejection($defaultShapedState, 'POST', '', '', true);

        $this->assertNotNull($rejection, '상태 파일이 있고 완료 마커가 없으면 아직 끝나지 않은 설치다');
        $this->assertSame('error_finalize_not_completed', $rejection['message_key']);

        // 인자를 주지 않는 기존 호출부의 계약은 그대로다.
        $this->assertNull(installerFinalizeRejection([], 'POST', '', ''));
    }

    public function test_session_ownership_of_a_running_stepwise_install(): void
    {
        $owner = bin2hex(random_bytes(8));

        $this->assertTrue(installerSessionOwnsStepwiseInstall(
            ['installer_session_owner' => $owner],
            ['installer' => ['session_owner' => $owner]],
        ));

        $this->assertFalse(installerSessionOwnsStepwiseInstall([], ['installer' => ['session_owner' => $owner]]));
        $this->assertFalse(installerSessionOwnsStepwiseInstall(['installer_session_owner' => $owner], null));
        $this->assertFalse(
            installerSessionOwnsStepwiseInstall(['installer_session_owner' => ''], ['installer' => ['session_owner' => '']]),
            '표식이 없는 상태에서 빈 값끼리 통과하면 게이트가 없는 것과 같다'
        );
        $this->assertFalse(installerSessionOwnsStepwiseInstall(
            ['installer_session_owner' => $owner],
            ['installer' => ['session_owner' => strrev($owner)]],
        ));
    }

    public function test_probe_rate_limit_counts_within_a_window(): void
    {
        if (! is_dir(BASE_PATH.'/storage')) {
            mkdir(BASE_PATH.'/storage', 0777, true);
        }
        @unlink(BASE_PATH.'/storage/installer-probe-rate.json');

        $bucket = 'test_'.bin2hex(random_bytes(4));

        for ($i = 0; $i < 3; $i++) {
            $this->assertFalse(installerProbeRateLimitExceeded($bucket, 3, 300), '상한 안의 호출은 통과해야 한다');
        }

        $this->assertTrue(installerProbeRateLimitExceeded($bucket, 3, 300), '상한을 넘은 호출은 거부되어야 한다');

        // 버킷이 다르면 서로 영향을 주지 않는다 — DB 확인과 바이너리 확인이 한 상한을 나눠 쓰면
        // 정상 설치가 서로를 막는다.
        $this->assertFalse(installerProbeRateLimitExceeded($bucket.'_other', 3, 300));
    }

    public function test_byte_size_parsing_keeps_unlimited_distinct_from_unknown(): void
    {
        $this->assertSame(256 * 1024 * 1024, installerParseByteSize('256M'));
        $this->assertSame(1024 * 1024 * 1024, installerParseByteSize('1G'));
        $this->assertSame(512 * 1024, installerParseByteSize('512K'));
        $this->assertSame(-1, installerParseByteSize('-1'), '무제한을 0 으로 접으면 "확인 불가" 와 구분되지 않는다');
        $this->assertNull(installerParseByteSize(''));
        $this->assertNull(installerParseByteSize('nonsense'));
    }
}
