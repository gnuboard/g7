<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 인스톨러 상태 스키마 단위 테스트.
 *
 * `public/install/includes/config.php` 의 `DEFAULT_INSTALLATION_STATE` 와
 * `installer-state.php` 의 `saveInstallationState`/`getInstallationState`
 * 라운드트립이 신규 `selected_extensions.language_packs` 키를 보존하는지 검증.
 *
 * 조회 응답의 실패·건너뜀 필드 집합(`installerClientFailureFields`)도 여기서 고정한다 —
 * 폴링과 단계별 실행이 같은 집합을 내보내야 하고, 한쪽에만 추가된 필드는 그 진행 모드에서만
 * 조용히 빠진다.
 *
 * BASE_PATH 는 PHP 상수로 클래스 라이프사이클 단위 단 한 번 정의 (setUpBeforeClass).
 *
 * 커버 축: state_schema default·missing_file·roundtrip / failure_fields fresh·populated
 *
 * 효과 요약(마커 아님 — 평문): 상태 스키마의 언어팩 키 보존, 실패 필드 집합의 화면 키 포함, 단계별 상태 요약과 폴링의 일치. 표식은 각 테스트 메서드에 있다.
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerStateSchemaTest extends TestCase
{
    private static string $sharedBase = '';

    private static string $skipReason = '';

    /**
     * 클래스 공용 임시 BASE_PATH 를 정하고 설치기 설정과 상태 파일 모듈을 불러온다.
     *
     * 다른 테스트가 BASE_PATH 를 임시 폴더 밖으로 먼저 정해 두었으면 실제 상태 파일을 지우지 않도록 건너뛸 사유만 남긴다.
     *
     * @return void 반환값 없음
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // 안전 가드: BASE_PATH 가 시스템 temp 하위가 아니면 (= 다른 테스트가 프로젝트 루트로 박은 경우)
        // setUp/tearDown 의 storage/installer-state.json @unlink 가 실제 설치 상태를 파괴할 수 있으므로 skip.
        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                self::$skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — '.
                    '다른 Installer 테스트의 BASE_PATH 정의가 선행됨. 격리 실행 필요: '.
                    'php vendor/bin/phpunit --filter=InstallerStateSchemaTest';

                return;
            }
            self::$sharedBase = (string) BASE_PATH;
        } else {
            self::$sharedBase = sys_get_temp_dir().'/g7-installer-state-test-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$sharedBase);
        }

        $storageDir = self::$sharedBase.'/storage';
        if (! is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        // config.php 의 INSTALLER_BASE_URL 계산이 $_SERVER['SCRIPT_NAME'] 에 의존
        if (! isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/install/index.php';
        }

        require_once dirname(__DIR__, 3).'/public/install/includes/config.php';
        require_once dirname(__DIR__, 3).'/public/install/includes/installer-state.php';
    }

    /**
     * 건너뛰지 않았을 때만 공용 임시 폴더의 상태 파일을 지운다.
     *
     * @return void 반환값 없음
     */
    public static function tearDownAfterClass(): void
    {
        // skipReason 이 설정되어 있으면 sharedBase 가 미초기화 — destructive cleanup 회피
        if (self::$skipReason === '' && self::$sharedBase !== '') {
            $statePath = self::$sharedBase.'/storage/installer-state.json';
            if (file_exists($statePath)) {
                @unlink($statePath);
            }
        }
        parent::tearDownAfterClass();
    }

    /**
     * 건너뛸 사유가 있으면 건너뛰고, 테스트마다 상태 파일을 지워 빈 상태에서 시작한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }

        // 각 테스트 시작 시 state.json 초기화
        $statePath = self::$sharedBase.'/storage/installer-state.json';
        if (file_exists($statePath)) {
            @unlink($statePath);
        }
    }

    /**
     * 기본 설치 상태가 선택 확장 아래에 빈 언어팩 목록을 갖는지 확인한다.
     *
     * @scenario pack_initial_state=unselected
     *
     * @effects installer_state_schema_preserves_language_packs, state_json_default_includes_language_packs_empty_array
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function default_state_includes_language_packs_empty_array(): void
    {
        $default = DEFAULT_INSTALLATION_STATE;
        $this->assertArrayHasKey('selected_extensions', $default);
        $this->assertArrayHasKey('language_packs', $default['selected_extensions']);
        $this->assertSame([], $default['selected_extensions']['language_packs']);
    }

    /**
     * 상태 파일이 없을 때 읽은 상태에도 빈 언어팩 목록이 실리는지 확인한다.
     *
     * @scenario pack_initial_state=unselected
     *
     * @effects installer_state_schema_preserves_language_packs
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function get_state_returns_language_packs_when_state_file_missing(): void
    {
        $state = getInstallationState();
        $this->assertArrayHasKey('selected_extensions', $state);
        $this->assertArrayHasKey('language_packs', $state['selected_extensions']);
        $this->assertSame([], $state['selected_extensions']['language_packs']);
    }

    /**
     * 언어팩 선택을 저장했다 다시 읽어도 언어팩 목록과 기존 모듈 선택이 그대로 남는지 확인한다.
     *
     * @scenario scope=core, scope=module, user_action=select_pack
     *
     * @effects installer_state_schema_preserves_language_packs, save_extensions_persists_language_packs_array_to_state_json
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function save_and_get_roundtrip_preserves_language_packs(): void
    {
        $state = getInstallationState();
        $state['selected_extensions'] = [
            'admin_templates' => ['sirsoft-admin_basic'],
            'user_templates' => ['sirsoft-basic'],
            'modules' => ['sirsoft-board'],
            'plugins' => [],
            'language_packs' => ['g7-core-ja', 'g7-module-sirsoft-board-ja'],
        ];

        $saved = saveInstallationState($state);
        $this->assertTrue($saved, 'state.json 저장이 성공해야 함');

        $reloaded = getInstallationState();
        $this->assertSame(
            ['g7-core-ja', 'g7-module-sirsoft-board-ja'],
            $reloaded['selected_extensions']['language_packs'],
            'language_packs 배열이 라운드트립에서 보존되어야 함'
        );
        $this->assertSame(
            ['sirsoft-board'],
            $reloaded['selected_extensions']['modules'],
            '기존 modules 키도 함께 보존되어야 함 (회귀 가드)'
        );
    }

    /**
     * 조회 응답의 실패 필드 집합은 한 곳이 만든다.
     *
     * 같은 데이터를 두 엔드포인트가 내보낸다 — 폴링(state-management.php?action=get)과
     * 단계별 실행(run-task.php 의 상태 요약)이다. 각자 배열 리터럴을 갖고 있으면 한쪽에만
     * 필드가 추가되고, 그 차이는 오류 없이 **한 진행 모드에서만 문구가 비는 것**으로만
     * 나타난다. 화면이 실제로 읽는 키를 여기서 고정한다.
     *
     * @scenario installation_mode=polling, installation_mode=stepwise
     *
     * @effects installer_client_failure_fields_cover_screen_keys
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function client_failure_fields_carry_every_key_the_screen_reads(): void
    {
        $fields = installerClientFailureFields([
            'current_task' => 'module_install:sirsoft-board',
            'completed_tasks' => ['db_migrate'],
            'skipped_tasks' => ['plugin_install:x'],
            'best_effort_failed_tasks' => ['language_pack_install:g7-core-ja'],
            'failed_task' => 'module_install:sirsoft-board',
            'failed_task_skippable' => 1,
            'error_message_key' => 'error_module_install_failed',
            'error_message_params' => ['errors' => 'detail'],
            'error_detail' => 'raw detail',
            'rollback_failure' => ['message_key' => 'failed_rollback_retry'],
            'manual_commands' => ['php artisan module:install x'],
        ]);

        foreach ([
            'current_task', 'completed_tasks', 'skipped_tasks', 'best_effort_failed_tasks',
            'failed_task', 'failed_task_skippable', 'error_message_key', 'error_message_params',
            'error_detail', 'rollback_failure', 'manual_commands',
        ] as $key) {
            $this->assertArrayHasKey($key, $fields, "화면이 읽는 {$key} 가 빠지면 그 진행 모드에서만 표시가 사라진다");
        }

        $this->assertTrue($fields['failed_task_skippable'], 'skippable 은 화면이 boolean 으로 읽는다');
        $this->assertSame(['errors' => 'detail'], $fields['error_message_params'], '치환값이 빠지면 자리표시자가 원문으로 나간다');
    }

    /**
     * 단계별 실행 응답의 상태 요약이 폴링 조회와 같은 집합을 싣는다.
     *
     * 요약은 종전에 `run-task.php`(최상위 스크립트) 안에 있어 형태·값이 무검사였다 —
     * 필드가 빠지거나 이름이 바뀌어도 소스 대조는 「부른다」만 확인했다.
     *
     * @scenario installation_mode=stepwise, execution_mode_resolved=inprocess
     *
     * @effects installer_stepwise_state_summary_matches_polling
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function stepwise_state_summary_matches_the_polling_field_set(): void
    {
        require_once dirname(__DIR__, 3).'/public/install/includes/execution-mode.php';

        $state = [
            'installation_status' => 'failed',
            'installation_mode' => 'stepwise',
            'execution_mode_resolved' => 'inprocess',
            'last_updated' => '2026-08-23T12:00:00Z',
            'failed_task' => 'db_migrate',
            'error_message_key' => 'error_db_migration_failed',
        ];

        $summary = installerStepwiseStateSummary($state);

        $this->assertSame('failed', $summary['status']);
        $this->assertSame('stepwise', $summary['installation_mode']);
        $this->assertSame('inprocess', $summary['execution_mode']);
        $this->assertSame('2026-08-23T12:00:00Z', $summary['last_updated']);

        // 실패·건너뜀 필드는 공용 SSoT 가 만든다 — 두 진행 모드가 같은 집합을 내보내야 한다
        foreach (array_keys(installerClientFailureFields($state)) as $key) {
            $this->assertArrayHasKey($key, $summary, $key.' 가 단계별 실행 응답에서만 빠지면 그 모드의 화면이 사유를 못 그린다');
        }

        // 상태가 비어 있어도 봉투는 같은 모양이다
        $empty = installerStepwiseStateSummary([]);
        $this->assertSame('not_started', $empty['status']);
        $this->assertNull($empty['installation_mode']);
        $this->assertNull($empty['execution_mode']);
    }

    /**
     * 값이 없는 상태에서도 같은 키 집합을 돌려준다 — 화면이 키 부재로 분기하지 않게.
     *
     * @scenario installation_mode=polling, installation_mode=stepwise
     *
     * @effects installer_client_failure_fields_cover_screen_keys
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function client_failure_fields_are_stable_for_a_fresh_state(): void
    {
        $fresh = installerClientFailureFields([]);
        $filled = installerClientFailureFields(['failed_task' => 'x']);

        $this->assertSame(array_keys($filled), array_keys($fresh));
        $this->assertFalse($fresh['failed_task_skippable']);
        $this->assertNull($fresh['error_message_params']);
    }
}
