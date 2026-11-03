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
 * 효과 요약(마커 아님 — 평문): installer_vendor_bundle_usability_requires_ziparchive, installer_https_judgment_is_proxy_aware, installer_cross_origin_state_change_is_rejected, installer_sse_worker_requires_running_installation, installer_finalize_idempotent_recall_uses_state_file_presence
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerEnvironmentJudgmentTest extends TestCase
{
    use CleansTemporaryBasePath;

    /**
     * 클래스 시작 때 언어 스텁을 읽고 기준 경로가 없으면 임시 경로로 정한 뒤 실행 모드 판정 헬퍼 소스를 읽어 들인다.
     *
     * @return void 반환값 없음
     */
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__.'/stubs/lang_stub.php';

        if (! defined('BASE_PATH')) {
            define('BASE_PATH', sys_get_temp_dir().'/g7-installer-env-'.bin2hex(random_bytes(4)));
        }

        require_once dirname(__DIR__, 3).'/public/install/includes/execution-mode.php';
    }

    /**
     * 기준 경로가 시스템 임시 경로 밖이면 실제 파일을 읽지 않도록 건너뛰고, 기준 디렉터리가 없으면 만든다.
     *
     * @return void 반환값 없음
     */
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

    /**
     * 공용 함수 가용 판정이 존재하는 함수는 참으로, 없는 함수는 거짓으로 돌려주는지 확인한다.
     *
     * @effects installer_blocked_function_call_sites_are_guarded
     *
     * @return void 반환값 없음
     */
    public function test_function_availability_is_judged_through_the_shared_resolver(): void
    {
        $this->assertTrue(installerHasFunction('strlen'), '존재하는 함수는 가용으로 판정해야 한다');
        $this->assertFalse(
            installerHasFunction('g7_definitely_not_a_real_function'),
            '없는 함수를 가용으로 판정하면 그 호출부가 그대로 치명 종료한다'
        );
    }

    /**
     * 번들 묶음 파일이 없으면 사용 불가, 있으면 ZipArchive 가용 여부까지 함께 봐서 판정하는지 확인한다.
     *
     * @scenario bundle_state=present
     *
     * @effects installer_vendor_bundle_usability_requires_ziparchive
     *
     * @return void 반환값 없음
     */
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

    /**
     * HTTPS 판정이 서버 변수뿐 아니라 프록시가 넘기는 전달 프로토콜 헤더와 SSL 헤더까지 보는지 확인한다.
     *
     * @scenario request_scheme=forwarded_https
     *
     * @effects installer_https_judgment_is_proxy_aware
     *
     * @return void 반환값 없음
     */
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

    /**
     * 같은 출처나 출처 불명 요청은 통과시키고, 다른 출처, null, 깨진 Origin 은 403 거부로 돌려주며 Referer 로 우회되지 않는지 확인한다.
     *
     * @scenario request_origin=cross
     *
     * @effects installer_cross_origin_state_change_is_rejected
     *
     * @return void 반환값 없음
     */
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

    /**
     * 스트리밍 워커 입구가 진행 중이거나 막 끝난 sse 설치만 받고, 다른 상태나 다른 진행 모드의 설치는 거부 사유와 함께 돌려보내는지 확인한다.
     *
     * @scenario worker_entry=not_started
     *
     * @effects installer_sse_worker_requires_running_installation
     *
     * @return void 반환값 없음
     */
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

    /**
     * 마무리 재호출 판정이 상태 내용이 아니라 상태 파일 존재 여부로 갈려, 파일이 없으면 통과하고 있으면서 미완료면 거부되는지 확인한다.
     *
     * @scenario finalize_call=state_file_absent
     *
     * @effects installer_finalize_idempotent_recall_uses_state_file_presence
     *
     * @return void 반환값 없음
     */
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

    /**
     * 단계별 설치의 세션 소유 판정이 상태와 세션의 소유 표식이 같고 비어 있지 않을 때만 참인지 확인한다.
     *
     * @scenario installation_mode=stepwise
     *
     * @effects installer_stepwise_token_is_rendered_only_for_the_owning_session
     *
     * @return void 반환값 없음
     */
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

    /**
     * 프로브 호출 상한이 창 안에서 상한까지는 통과시키고 넘으면 거부하며, 버킷끼리는 서로 세지 않는지 확인한다.
     *
     * @scenario probe_identity=stable
     *
     * @effects probe_rate_limit_counts_calls_within_the_window
     *
     * @return void 반환값 없음
     */
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

    /**
     * 클라이언트 식별이 **세션**을 실제로 쓴다 (프록시 뒤 공유 IP 방어).
     *
     * `installerProbeRateLimitClientKey()` 는 종전에 `session_status() === PHP_SESSION_ACTIVE`
     * 로 가드했다. 그런데 이 함수를 부르는 유일한 액션(`?action=requirements`)은 그 전에
     * `session_write_close()` 를 하므로 상태가 `PHP_SESSION_NONE` 이고, 그 가드는 **언제나
     * 거짓**이었다 — 세션 축이 죽어 식별이 사실상 `REMOTE_ADDR` 단독이었다.
     *
     * 앞단 프록시·CDN·LB 뒤에서는 전 방문자가 같은 IP 로 도착한다(이 점검이 대상으로 삼는
     * 호스팅 계층이 바로 그 구성이다). 그 상태에서는 무인증 원격자가 창 안에서 상한만큼
     * 호출하는 것만으로 **운영자의 storage 계열이 상한 초과로 대상에서 빠져** 노출 자동
     * 차단(D3)이 수동 확인(D21)으로 강등된다 — 화면에는 아무 이상이 없다.
     *
     * 종전 테스트는 `REMOTE_ADDR` 만 심어 「IP = 클라이언트」를 전제로 쟀으므로 이 축을
     * 볼 수 없었다 (11차 G3 라운드).
     *
     * @scenario rate_limit=client_identity, deployment=reverse_proxy_shared_ip
     *
     * @effects probe_rate_limit_is_scoped_per_browser_behind_a_proxy
     *
     * @return void 반환값 없음
     */
    public function test_client_key_uses_the_session_even_after_write_close(): void
    {
        $previousAddr = $_SERVER['REMOTE_ADDR'] ?? null;

        try {
            // 프록시 뒤 — 두 방문자가 같은 IP 로 도착한다.
            $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            @session_id('g7-round11-session-a');
            @session_start();
            session_write_close();

            $this->assertSame(
                PHP_SESSION_NONE,
                session_status(),
                'write-close 뒤 상태가 ACTIVE 면 이 회귀 축 자체가 성립하지 않는다'
            );

            $first = installerProbeRateLimitClientKey();

            @session_id('g7-round11-session-b');
            @session_start();
            session_write_close();

            $second = installerProbeRateLimitClientKey();

            $this->assertNotSame(
                '',
                $first,
                'write-close 뒤 세션 식별자를 읽지 못한다 — 상한이 IP 단독으로 떨어진다'
            );
            $this->assertNotSame(
                $first,
                $second,
                '같은 IP 의 서로 다른 브라우저가 같은 상한 버킷을 쓴다 — 프록시 뒤에서 '
                .'원격자가 운영자의 노출 점검을 강등시킬 수 있다'
            );
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            if ($previousAddr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previousAddr;
            }
        }
    }

    /**
     * 상한을 **클라이언트별로 나누는** 축이 실제로 동작한다.
     *
     * 이 축이 필요한 이유: 카운터 하나를 전 클라이언트가 공유하면 그 상한 자체가 공격
     * 수단이 된다 — 무인증 원격자가 창을 소진하면 운영자의 storage 계열이 상시
     * `rate_limited` 로 떨어지고, 그러면 노출 **자동 차단(D3)** 이 운영자 **수동 확인(D21)**
     * 으로 강등된다. 화면상 이상이 없어 알아챌 방법도 없다.
     *
     * 종전에는 이 분기에 **들어가는 테스트가 하나도 없었다** — 식별자는 세션 또는
     * `REMOTE_ADDR` 에서 오는데 PHPUnit CLI 에는 둘 다 없어 언제나 빈 문자열이었고,
     * 그래서 분기 전체를 지워도 초록이었다. 여기서 `REMOTE_ADDR` 을 심어 그 경로를 연다.
     *
     * @scenario rate_limit=client_identity
     *
     * @effects exposure_probe_rate_limit_cannot_downgrade_the_blocking_verdict
     *
     * @return void 반환값 없음
     */
    public function test_probe_rate_limit_is_counted_per_client(): void
    {
        if (! is_dir(BASE_PATH.'/storage')) {
            mkdir(BASE_PATH.'/storage', 0777, true);
        }
        @unlink(BASE_PATH.'/storage/installer-probe-rate.json');

        $bucket = 'test_'.bin2hex(random_bytes(4));
        $previous = $_SERVER['REMOTE_ADDR'] ?? null;

        try {
            $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
            $first = installerProbeRateLimitClientKey();
            $this->assertNotSame('', $first, '원격 주소가 있으면 클라이언트 식별자가 나와야 한다');

            for ($i = 0; $i < 3; $i++) {
                $this->assertFalse(installerProbeRateLimitExceeded($bucket, 3, 300));
            }
            $this->assertTrue(
                installerProbeRateLimitExceeded($bucket, 3, 300),
                '자기 창을 다 쓴 클라이언트는 거부되어야 한다'
            );

            // 다른 클라이언트는 **자기 창**을 갖는다 — 이것이 D3→D21 강등을 막는 성질이다.
            $_SERVER['REMOTE_ADDR'] = '203.0.113.99';
            $this->assertNotSame($first, installerProbeRateLimitClientKey(), '클라이언트가 다르면 식별자도 달라야 한다');
            $this->assertFalse(
                installerProbeRateLimitExceeded($bucket, 3, 300),
                '남이 소진한 창이 내 판정을 막으면 노출 자동 차단이 수동 확인으로 강등된다'
            );

            // 식별에 실패하면 종전(전역 카운터 하나)으로 되돌아간다.
            unset($_SERVER['REMOTE_ADDR']);
            $this->assertSame('', installerProbeRateLimitClientKey(), '식별 불가는 빈 문자열이어야 한다');
        } finally {
            if ($previous === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous;
            }
        }
    }

    /**
     * 판정 집합을 깎는 자리는 **전역 창을 보지 않는다** (`clientScopeOnly`).
     *
     * 전역 창은 신원을 갈아 끼우는 총량을 막는 장치인데, 그 창이 차면 운영자의 storage
     * 계열이 프로브 대상에서 빠진다. 대상 집합이 줄면 판정은 `exposed` → `unknown` 방향으로만
     * 움직이므로 노출 자동 차단(D3)이 수동 확인(D21)으로 **강등**된다 — 무인증 원격자가
     * 그 강등을 일으킬 수 있었다.
     *
     * @effects exposure_probe_rate_limit_cannot_downgrade_the_blocking_verdict
     *
     * @return void 반환값 없음
     */
    public function test_client_scoped_limit_ignores_the_global_window(): void
    {
        if (! is_dir(BASE_PATH.'/storage')) {
            mkdir(BASE_PATH.'/storage', 0777, true);
        }
        @unlink(BASE_PATH.'/storage/installer-probe-rate.json');

        $bucket = 'test_'.bin2hex(random_bytes(4));
        $previous = $_SERVER['REMOTE_ADDR'] ?? null;

        try {
            // 남들이 전역 창(limit×10)을 채운다.
            for ($i = 0; $i < 20; $i++) {
                $_SERVER['REMOTE_ADDR'] = '198.51.100.'.$i;
                installerProbeRateLimitExceeded($bucket, 2, 300);
                installerProbeRateLimitExceeded($bucket, 2, 300);
            }

            $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

            $this->assertFalse(
                installerProbeRateLimitExceeded($bucket, 2, 300, clientScopeOnly: true),
                '남이 채운 전역 창이 내 판정을 깎으면 노출 자동 차단이 수동 확인으로 강등된다'
            );

            // 전역 축을 보는 호출은 여전히 총량을 막는다 (그 역할은 유지된다).
            $this->assertTrue(
                installerProbeRateLimitExceeded($bucket, 2, 300),
                '총량 방어까지 사라지면 신원 교체로 무제한 생성이 열린다'
            );
        } finally {
            if ($previous === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous;
            }
        }
    }

    /**
     * 신원(세션)을 갈아 끼워도 **원격 주소 창**이 총량을 막는다.
     *
     * 11차는 클라이언트 식별을 `REMOTE_ADDR` 에서 `session_id()` 로 옮기면서 이 분기의
     * 총량 방어(전역 창)를 함께 뺐다. 그런데 `session_id()` 는 **요청하는 쪽이 정하는
     * 값**이다 — 쿠키를 보내지 않으면 매 요청이 새 세션이고, 아무 값이나 보내면 그
     * 값이 된다. 그래서 그 두 버킷(`probe_exposure_sentinel`·`probe_symlink`)은 쿠키를
     * 갈아 끼우는 것만으로 상한이 통째로 사라진 상태였다. 그 상한이 지키던 것은
     * **무인증 파일 생성**이므로, 결과는 `storage/installer` 에 sentinel 이 무제한으로
     * 쌓이는 것이다.
     *
     * 이 테스트가 없으면 그 구멍은 드러나지 않는다 — PHPUnit CLI 에는 세션이 없어
     * 식별자가 `REMOTE_ADDR` 로 폴백하고, 그러면 신원 교체 자체가 재현되지 않는다.
     * 여기서는 주소를 고정한 채 **세션만** 갈아 끼워 그 축을 정확히 연다.
     *
     * @scenario probe_identity=stable
     *
     * @effects probe_rate_limit_survives_identity_rotation
     *
     * @return void 반환값 없음
     */
    public function test_client_scoped_limit_still_bounds_identity_rotation(): void
    {
        if (! is_dir(BASE_PATH.'/storage')) {
            mkdir(BASE_PATH.'/storage', 0777, true);
        }
        @unlink(BASE_PATH.'/storage/installer-probe-rate.json');

        $bucket = 'test_'.bin2hex(random_bytes(4));
        $previousAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        $previousSession = session_id();

        try {
            $_SERVER['REMOTE_ADDR'] = '203.0.113.42';

            // 세션을 매번 갈아 끼우며 부른다 — 클라이언트 창(2)은 매번 새것이라
            // 종전에는 **영원히** 통과했다.
            $rejected = false;
            for ($i = 0; $i < 40; $i++) {
                session_id('rotating-'.$i.'-'.bin2hex(random_bytes(4)));

                if (installerProbeRateLimitExceeded($bucket, 2, 300, clientScopeOnly: true)) {
                    $rejected = true;
                    break;
                }
            }

            $this->assertTrue(
                $rejected,
                '신원을 갈아 끼우는 것만으로 상한이 사라지면 무인증 원격자가 파일을 무제한 생성한다'
            );

            // 그런데 **다른 주소**의 운영자는 여전히 자기 창을 쓴다 — 이 성질이
            // 깨지면 원격자가 남을 강등시킬 수 있고, 그것이 11차가 전역 창을 뺀 이유다.
            $_SERVER['REMOTE_ADDR'] = '198.51.100.5';
            session_id('operator-'.bin2hex(random_bytes(4)));

            $this->assertFalse(
                installerProbeRateLimitExceeded($bucket, 2, 300, clientScopeOnly: true),
                '남이 소진한 창이 내 판정을 막으면 노출 자동 차단이 수동 확인으로 강등된다'
            );
        } finally {
            session_id($previousSession === '' ? '' : $previousSession);

            if ($previousAddr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previousAddr;
            }
        }
    }

    /**
     * 메모리 크기 문자열 해석이 단위를 바이트로 바꾸고, 무제한은 -1 로, 빈 값이나 알 수 없는 값은 null 로 구분하는지 확인한다.
     *
     * @effects installer_memory_budget_declarations_are_consistent
     *
     * @return void 반환값 없음
     */
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
