<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 인스톨러 진입 가드 회귀 테스트
 *
 * 설치 완료 후 public/install/api/ 하위 모든 엔드포인트가 비즈니스 로직 진입 전
 * HTTP 410 으로 차단되어야 하는 정책의 단위 검증.
 *
 * 판정 신호:
 *  1. {BASE_PATH}/storage/app/g7_installed 락 파일 존재
 *  2. .env 의 INSTALLER_COMPLETED=true (또는 1, yes)
 *
 * exit/header 동작은 단위 테스트 내에서 직접 검증할 수 없으므로
 * installer_is_completed() 의 결정론적 판정 결과만 검증한다.
 * 410 응답 본문은 PoC 매뉴얼 검증 영역.
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerGuardTest extends TestCase
{
    use CleansTemporaryBasePath;
    use ScansPhpSourceFunctions;

    private static string $tempBase = '';

    private static string $skipReason = '';

    /**
     * 클래스 공용 임시 BASE_PATH 와 저장 폴더를 갖추고 진입 가드 파일을 불러온다.
     *
     * 다른 테스트가 BASE_PATH 를 임시 폴더 밖으로 먼저 정해 두었으면 실제 환경 파일을 지우지 않도록 건너뛸 사유만 남긴다.
     *
     * @return void 반환값 없음
     */
    public static function setUpBeforeClass(): void
    {
        // 안전 가드: 본 테스트는 BASE_PATH/.env 와 BASE_PATH/storage/app/g7_installed 를
        // 직접 write/unlink 한다. BASE_PATH 는 PHP 상수라 한 프로세스에서 단 한 번만 정의되며,
        // 다른 Installer 테스트가 먼저 프로젝트 루트로 박으면 본 테스트의 cleanup() 이
        // 실제 운영 .env 를 삭제하게 된다. BASE_PATH 가 시스템 temp 하위가 아니면 skip 한다.
        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();

        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                self::$skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — '
                    .'다른 Installer 테스트의 BASE_PATH 정의가 선행됨. 실제 .env 파괴 방지를 위해 skip. '
                    .'격리 실행: php vendor/bin/phpunit --filter=InstallerGuardTest';

                return;
            }
            self::$tempBase = (string) BASE_PATH;
        } else {
            self::$tempBase = sys_get_temp_dir().'/g7-installer-guard-test-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$tempBase);
        }

        if (! is_dir(self::$tempBase.'/storage/app')) {
            @mkdir(self::$tempBase.'/storage/app', 0755, true);
        }

        require_once dirname(__DIR__, 3).'/public/install/api/_guard.php';
    }

    /**
     * 건너뛸 사유가 있으면 건너뛰고, 아니면 이전 테스트가 남긴 완료 신호 파일을 지운다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }

        $this->cleanup();
    }

    /**
     * 테스트가 만든 완료 신호 파일을 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    /**
     * 임시 BASE_PATH 의 설치 완료 잠금 파일과 환경 파일을 지운다 (건너뛰는 중이면 아무것도 하지 않는다).
     *
     * @return void 반환값 없음
     */
    private function cleanup(): void
    {
        // skip 상태(= BASE_PATH 가 실 루트)면 파괴적 정리를 절대 수행하지 않는다.
        if (self::$skipReason !== '') {
            return;
        }

        $lock = BASE_PATH.'/storage/app/g7_installed';
        if (is_file($lock)) {
            @unlink($lock);
        }
        $env = BASE_PATH.'/.env';
        if (is_file($env)) {
            @unlink($env);
        }
    }

    /**
     * 완료 신호(잠금 파일, 환경 파일 플래그)가 하나도 없으면 설치 완료로 보지 않는지 확인한다.
     *
     * @scenario installation_state=not_started
     *
     * @effects guard_returns_false_when_no_signals_present
     *
     * @return void 반환값 없음
     */
    public function test_no_signals_means_not_completed(): void
    {
        $this->assertFalse(installer_is_completed());
    }

    /**
     * 설치 완료 잠금 파일만 있어도 일반 가드가 설치 완료로 판정하는지 확인한다.
     *
     * @scenario installation_state=completed_lock_only
     *
     * @effects guard_returns_true_when_lock_file_present
     *
     * @return void 반환값 없음
     */
    public function test_lock_file_only_marks_completed(): void
    {
        file_put_contents(BASE_PATH.'/storage/app/g7_installed', '');

        $this->assertTrue(installer_is_completed());
    }

    /**
     * 환경 파일의 설치 완료 플래그가 true 이면 잠금 파일 없이도 설치 완료로 판정하는지 확인한다.
     *
     * @scenario installation_state=completed_env_only
     *
     * @effects guard_returns_true_when_env_flag_true_present
     *
     * @return void 반환값 없음
     */
    public function test_env_flag_only_marks_completed(): void
    {
        file_put_contents(BASE_PATH.'/.env', "APP_ENV=local\nINSTALLER_COMPLETED=true\n");

        $this->assertTrue(installer_is_completed());
    }

    /**
     * 따옴표로 감싼 true 도 설치 완료 플래그로 받아들이는지 확인한다.
     *
     * @scenario installation_state=completed_env_only
     *
     * @effects guard_returns_true_when_env_flag_quoted_true_present
     *
     * @return void 반환값 없음
     */
    public function test_env_flag_accepts_quoted_true(): void
    {
        file_put_contents(BASE_PATH.'/.env', 'INSTALLER_COMPLETED="true"'."\n");

        $this->assertTrue(installer_is_completed());
    }

    /**
     * 설치 완료 플래그 값 1 도 참으로 받아들이는지 확인한다.
     *
     * @scenario installation_state=completed_env_only
     *
     * @effects guard_returns_true_when_env_flag_one_present
     *
     * @return void 반환값 없음
     */
    public function test_env_flag_accepts_one(): void
    {
        file_put_contents(BASE_PATH.'/.env', 'INSTALLER_COMPLETED=1'."\n");

        $this->assertTrue(installer_is_completed());
    }

    /**
     * 설치 완료 플래그가 false 이면 설치 완료로 보지 않는지 확인한다.
     *
     * @scenario installation_state=in_progress
     *
     * @effects guard_returns_false_when_env_flag_false_present
     *
     * @return void 반환값 없음
     */
    public function test_env_flag_false_does_not_mark_completed(): void
    {
        file_put_contents(BASE_PATH.'/.env', "APP_ENV=local\nINSTALLER_COMPLETED=false\n");

        $this->assertFalse(installer_is_completed());
    }

    /**
     * 환경 파일은 있으나 설치 완료 플래그 줄이 없으면 설치 완료로 보지 않는지 확인한다.
     *
     * @scenario installation_state=in_progress
     *
     * @effects guard_returns_false_when_env_missing_flag
     *
     * @return void 반환값 없음
     */
    public function test_env_flag_missing_does_not_mark_completed(): void
    {
        file_put_contents(BASE_PATH.'/.env', "APP_ENV=local\n");

        $this->assertFalse(installer_is_completed());
    }

    /**
     * 잠금 파일과 환경 파일 플래그가 함께 있으면 설치 완료로 판정하는지 확인한다.
     *
     * @scenario installation_state=completed_both
     *
     * @effects guard_returns_true_when_both_signals_present
     *
     * @return void 반환값 없음
     */
    public function test_both_signals_present_marks_completed(): void
    {
        file_put_contents(BASE_PATH.'/storage/app/g7_installed', '');
        file_put_contents(BASE_PATH.'/.env', 'INSTALLER_COMPLETED=true'."\n");

        $this->assertTrue(installer_is_completed());
    }

    // ------------------------------------------------------------------------
    // finalize 전용 가드 — installer_finalize_is_completed()
    //
    // 일반 가드와 달리 g7_installed 락 파일은 차단 사유에서 제외되어야 한다
    // (complete_flag task 가 락 파일을 먼저 생성한 직후 finalize 가 호출되는
    //  설계상의 호출 순서 때문 — 자가 차단 회귀 가드).
    // ------------------------------------------------------------------------

    /**
     * 잠금 파일만 있고 환경 파일 병합 전인 정상 첫 마무리 호출을 마무리 전용 가드가 막지 않는지 확인한다.
     *
     * @scenario finalize_guard_signal=lock_only, g7_installed_lock_state=present_before_finalize
     *
     * @effects finalize_guard_passes_when_only_g7_installed_lock_is_present
     *
     * @return void 반환값 없음
     */
    public function test_finalize_guard_passes_with_lock_only(): void
    {
        // 정상 1회차 finalize 시나리오: 락 파일은 존재하나 .env 머지 아직 안 됨
        file_put_contents(BASE_PATH.'/storage/app/g7_installed', '');

        $this->assertFalse(
            installer_finalize_is_completed(),
            'g7_installed 락 파일 단독 존재 시 finalize 차단되면 자가 차단 회귀'
        );
    }

    /**
     * 환경 파일 자체가 없는 신규 설치에서 마무리 전용 가드가 통과시키는지 확인한다.
     *
     * @scenario finalize_guard_signal=neither
     *
     * @effects finalize_guard_passes_when_env_lacks_installer_completed_flag
     *
     * @return void 반환값 없음
     */
    public function test_finalize_guard_passes_with_no_env_file(): void
    {
        // .env 자체가 없는 신규 설치 시나리오
        $this->assertFalse(installer_finalize_is_completed());
    }

    /**
     * 이미 마무리되어 환경 파일 플래그가 true 인 상태의 재호출을 마무리 전용 가드가 막는지 확인한다.
     *
     * @scenario finalize_guard_signal=env_completed_true_only
     *
     * @effects finalize_guard_blocks_when_env_has_installer_completed_true_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_finalize_guard_blocks_when_env_completed_true(): void
    {
        // 이미 finalize 된 상태 — 멱등 차단
        file_put_contents(BASE_PATH.'/.env', "APP_ENV=local\nINSTALLER_COMPLETED=true\n");

        $this->assertTrue(installer_finalize_is_completed());
    }

    /**
     * 잠금 파일과 환경 파일 플래그가 함께 있을 때 마무리 전용 가드가 막는지 확인한다.
     *
     * @scenario finalize_guard_signal=lock_and_env_completed_true
     *
     * @effects finalize_guard_blocks_when_env_has_installer_completed_true_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_finalize_guard_blocks_with_lock_and_env_completed(): void
    {
        // 락 + .env true 동시 존재 → 차단 (멱등)
        file_put_contents(BASE_PATH.'/storage/app/g7_installed', '');
        file_put_contents(BASE_PATH.'/.env', 'INSTALLER_COMPLETED=true'."\n");

        $this->assertTrue(installer_finalize_is_completed());
    }

    /**
     * 환경 파일 플래그가 false 이면 마무리 전용 가드가 통과시키는지 확인한다.
     *
     * @scenario finalize_guard_signal=neither
     *
     * @effects finalize_guard_passes_when_env_lacks_installer_completed_flag
     *
     * @return void 반환값 없음
     */
    public function test_finalize_guard_passes_when_env_completed_false(): void
    {
        file_put_contents(BASE_PATH.'/.env', "INSTALLER_COMPLETED=false\n");

        $this->assertFalse(installer_finalize_is_completed());
    }

    /**
     * 마무리 전용 가드가 따옴표 true, 1, yes 를 모두 완료 플래그로 받아들이는지 확인한다.
     *
     * @scenario finalize_guard_signal=env_completed_true_only
     *
     * @effects finalize_guard_blocks_when_env_has_installer_completed_true_with_quoted_value, finalize_guard_accepts_alternative_truthy_values_1_and_yes
     *
     * @return void 반환값 없음
     */
    public function test_finalize_guard_accepts_quoted_and_alt_truthy_values(): void
    {
        file_put_contents(BASE_PATH.'/.env', 'INSTALLER_COMPLETED="true"'."\n");
        $this->assertTrue(installer_finalize_is_completed());

        file_put_contents(BASE_PATH.'/.env', 'INSTALLER_COMPLETED=1'."\n");
        $this->assertTrue(installer_finalize_is_completed());

        file_put_contents(BASE_PATH.'/.env', 'INSTALLER_COMPLETED=yes'."\n");
        $this->assertTrue(installer_finalize_is_completed());
    }

    // ------------------------------------------------------------------------
    // state-management.php 진입 가드 정적 회귀 (KVE-2026-1056)
    //
    // state-management.php?action=get 은 정상 설치 흐름의 1초 폴링이며,
    // complete_flag task 가 g7_installed 락 파일을 finalize 보다 먼저 생성한다.
    // 따라서 일반 가드(installer_guard_or_410, 락 파일도 차단 사유)를 적용하면
    // "락만 있고 .env 플래그는 아직 없는" 폴링 구간이 410 으로 끊겨 완료 화면이
    // 표시되지 않는 자가 차단 회귀가 발생한다.
    //
    // 올바른 조치: finalize 전용 가드(installer_guard_finalize_or_410,
    // .env INSTALLER_COMPLETED=true 단독 판정)를 객체 생성 전에 호출한다.
    // exit/header 는 단위에서 검증 불가하므로 소스 정적 검사로 회귀를 못박는다.
    // ------------------------------------------------------------------------

    /**
     * 상태 관리 엔드포인트 소스가 있는지 확인하고 주석을 걷어낸 원문을 돌려준다.
     *
     * @return string 주석이 제거된 상태 관리 엔드포인트 소스
     */
    private function stateManagementSource(): string
    {
        $path = dirname(__DIR__, 3).'/public/install/api/state-management.php';

        $this->assertFileExists($path, 'state-management.php 가 존재해야 한다');

        // 주석을 지운 뒤 대조한다 — 원문에는 주석 처리된 배선도 글자로 남아, 원문 대조는 「그 배선이 살아 있다」를 증명하지 못한다 (12차 메타 검사 ①).
        return $this->stripComments((string) file_get_contents($path));
    }

    /**
     * 상태 관리 엔드포인트 소스가 가드 파일을 불러오는지 주석을 걷어낸 원문으로 확인한다.
     *
     * @scenario entry_point=state_management
     *
     * @effects state_management_loads_guard_file
     *
     * @return void 반환값 없음
     */
    public function test_state_management_loads_guard_file(): void
    {
        $src = $this->stateManagementSource();

        $this->assertMatchesRegularExpression(
            '/require(?:_once)?\s+__DIR__\s*\.\s*[\'"]\/_guard\.php[\'"]/',
            $src,
            'state-management.php 가 _guard.php 를 로드해야 한다'
        );
    }

    /**
     * 상태 관리 엔드포인트가 일반 가드가 아니라 마무리 전용 가드를 부르는지 확인한다.
     *
     * @scenario entry_point=state_management
     *
     * @effects state_management_calls_finalize_guard_not_general_guard
     *
     * @return void 반환값 없음
     */
    public function test_state_management_calls_finalize_guard_not_general_guard(): void
    {
        $src = $this->stateManagementSource();

        $this->assertStringContainsString(
            'installer_guard_finalize_or_410()',
            $src,
            'state-management.php 는 finalize 전용 가드를 호출해야 한다 (.env 플래그 단독 판정)'
        );

        // 일반 가드를 쓰면 락-only 폴링 구간이 410 으로 끊겨 정상 설치가 깨진다.
        $this->assertDoesNotMatchRegularExpression(
            '/(?<!finalize_)installer_guard_or_410\s*\(\s*\)/',
            $src,
            'state-management.php 는 일반 installer_guard_or_410() 을 호출하면 안 된다 '
                .'(complete_flag 락 파일로 인한 폴링 자가 차단 회귀)'
        );
    }

    /**
     * 상태 관리 엔드포인트에서 가드 호출이 API 객체 생성보다 앞에 있는지 확인한다.
     *
     * @scenario entry_point=state_management
     *
     * @effects state_management_guard_runs_before_api_instantiation
     *
     * @return void 반환값 없음
     */
    public function test_state_management_guard_runs_before_api_instantiation(): void
    {
        $src = $this->stateManagementSource();

        $guardPos = strpos($src, 'installer_guard_finalize_or_410()');
        $instantiatePos = strpos($src, 'new StateManagementApi');

        $this->assertNotFalse($guardPos, 'finalize 가드 호출이 존재해야 한다');
        $this->assertNotFalse($instantiatePos, 'StateManagementApi 인스턴스화가 존재해야 한다');
        $this->assertLessThan(
            $instantiatePos,
            $guardPos,
            '가드는 StateManagementApi 객체 생성 전에 호출되어야 한다 (모든 action 일괄 차단)'
        );
    }

    /**
     * 잠금 파일만 있는 완료 직전 폴링 구간에서 상태 관리 엔드포인트의 가드가 통과시키는지 확인한다.
     *
     * @scenario entry_point=state_management
     *
     * @effects state_management_polling_passes_with_lock_only
     *
     * @return void 반환값 없음
     */
    public function test_state_management_polling_passes_with_lock_only(): void
    {
        // 핵심 비파괴 불변식: complete_flag 직후~finalize 전 구간(락만 존재)에서
        // finalize 가드는 통과해야 한다 — 폴링이 completed 상태를 받아야 완료 화면 전환.
        file_put_contents(BASE_PATH.'/storage/app/g7_installed', '');

        $this->assertFalse(
            installer_finalize_is_completed(),
            'g7_installed 락 단독 존재 시 state-management 폴링이 차단되면 '
                .'정상 설치가 "진행 중"에 고착된다 (KVE-2026-1056 조치의 비파괴 조건)'
        );
    }

    /**
     * 환경 파일 플래그까지 기록된 완전 완료 상태에서 상태 관리 엔드포인트가 막히는지 확인한다.
     *
     * @scenario entry_point=state_management, installation_state=completed_env_only
     *
     * @effects state_management_blocks_when_env_completed_true
     *
     * @return void 반환값 없음
     */
    public function test_state_management_blocks_when_env_completed(): void
    {
        // 완전 완료 상태(.env 플래그 set)에서는 get/reset/abort 가 차단되어야 한다 —
        // KVE-2026-1056 의 보호장치 우회·상태 변조 공격 표면 제거.
        file_put_contents(BASE_PATH.'/.env', "APP_ENV=local\nINSTALLER_COMPLETED=true\n");

        $this->assertTrue(
            installer_finalize_is_completed(),
            '완전 설치 완료 상태에서 state-management 가 차단되어야 한다 (KVE-2026-1056)'
        );
    }
}
