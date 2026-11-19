<?php

namespace Tests\Unit\Installer;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\CleansTemporaryBasePath;
use Tests\Concerns\ScansPhpSourceFunctions;

/**
 * 테이블 삭제 범위의 공개·기록 회귀 가드 (21차 #21-2, 제품 결정 §16.27.1 #2 「목록 표시+기록」)
 *
 * 삭제 대상 선정은 무경계 접두 매칭이다 — 같은 DB 에 더 긴 접두사 사이트(`g7_` 입력 시
 * `g7_shop_*`)가 있으면 [테이블 삭제] 동의가 이웃 사이트를 통째로 DROP 한다. 제품 결정은
 * 매칭 규칙 변경이 아니라 **투명성**이다:
 * ① 동의 화면이 실제 삭제 대상 목록(삭제 계산과 동일 산출 — `filterTablesByPrefix`)을 보여준다
 * ② 더 긴 접두사의 다른 G7 설치가 감지되면 별도 경고를 띄운다
 * ③ 지운 테이블명을 설치 기록에 남긴다 (종전에는 count 만 남아 사후에도 알 수 없었다)
 *
 * 효과 요약(마커 아님 — 평문): 삭제 대상 테이블의 공개와 기록. 표식은 각 테스트 메서드에 있다.
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerDropTargetDisclosureTest extends TestCase
{
    use CleansTemporaryBasePath;
    use ScansPhpSourceFunctions;

    /**
     * 임시 BASE_PATH 를 정하고 설치기 설정, 상태, 공용 함수 파일과 번역 대역을 불러온다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('BASE_PATH')) {
            define('BASE_PATH', sys_get_temp_dir().'/g7-drop-disclosure-'.bin2hex(random_bytes(4)));
            @mkdir(BASE_PATH.'/storage', 0755, true);
        }

        $projectRoot = dirname(__DIR__, 3);
        require_once __DIR__.'/stubs/lang_stub.php';
        require_once $projectRoot.'/public/install/includes/config.php';
        require_once $projectRoot.'/public/install/includes/installer-state.php';
        require_once $projectRoot.'/public/install/includes/functions.php';
    }

    /**
     * 테이블 목록 조회에 주어진 이름들을 돌려주는 PDO 대역을 만든다.
     *
     * @param  array<int, string>  $tables  조회 결과로 돌려줄 테이블 이름 목록
     * @return PDO 조회 결과가 고정된 PDO 대역
     */
    private function pdoWithTables(array $tables): PDO
    {
        $stmt = $this->createStub(PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($tables);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn($stmt);

        return $pdo;
    }

    /**
     * ②: 더 긴 접두사에 G7 시그니처 4종이 모두 있으면 「다른 G7 설치」로 감지한다.
     *
     * @scenario task=db_cleanup
     *
     * @effects installer_drop_targets_are_disclosed_and_recorded
     *
     * @return void 반환값 없음
     */
    public function test_nested_g7_prefix_is_detected(): void
    {
        $tables = [
            'g7_users', 'g7_migrations', 'g7_roles', 'g7_permissions', 'g7_posts',
            'g7_shop_users', 'g7_shop_migrations', 'g7_shop_roles', 'g7_shop_permissions', 'g7_shop_orders',
            'unrelated_table',
        ];

        $this->assertSame(['g7_shop_'], installerDetectNestedG7Prefixes($tables, 'g7_'), '더 긴 접두사의 G7 설치가 감지되지 않았다 (#21-2)');
    }

    /**
     * ②: 시그니처가 4종 미만이면 감지하지 않고(오경보 방지), 빈 접두사는 빈 결과다.
     *
     * @scenario task=db_cleanup
     *
     * @effects installer_drop_targets_are_disclosed_and_recorded
     *
     * @return void 반환값 없음
     */
    public function test_nested_detection_requires_full_signature(): void
    {
        $tables = ['g7_users', 'g7_shop_users', 'g7_shop_orders'];

        $this->assertSame([], installerDetectNestedG7Prefixes($tables, 'g7_'));
        $this->assertSame([], installerDetectNestedG7Prefixes([], 'g7_'));
        $this->assertSame([], installerDetectNestedG7Prefixes(['g7_users'], ''));
    }

    /**
     * ①·②: 감지 응답이 삭제 대상 목록(삭제 계산과 동일 산출)과 이웃 경고를 싣는다.
     *
     * @scenario task=db_cleanup
     *
     * @effects installer_drop_targets_are_disclosed_and_recorded
     *
     * @return void 반환값 없음
     */
    public function test_existing_tables_response_discloses_drop_targets(): void
    {
        $tables = [
            'g7_users', 'g7_migrations', 'g7_roles', 'g7_permissions',
            'g7_shop_users', 'g7_shop_migrations', 'g7_shop_roles', 'g7_shop_permissions',
            'other_app',
        ];

        $result = checkExistingTables($this->pdoWithTables($tables), 'db', 'g7_');

        $this->assertArrayHasKey('drop_targets', $result, '동의 화면이 그릴 삭제 대상 목록이 응답에 없다 (#21-2 ①)');
        $this->assertSame(filterTablesByPrefix($tables, 'g7_'), $result['drop_targets'], '표시 목록과 삭제 계산이 다르면 동의가 거짓이 된다');
        $this->assertSame(['g7_shop_'], $result['nested_g7_prefixes'] ?? null, '이웃 G7 설치 경고가 응답에 없다 (#21-2 ②)');
    }

    /**
     * ①·②-배선: 동의 카드가 삭제 대상 목록과 이웃 경고를 실제로 그린다.
     *
     * @scenario task=db_cleanup
     *
     * @effects installer_drop_targets_are_disclosed_and_recorded
     *
     * @return void 반환값 없음
     */
    public function test_consent_card_renders_disclosure(): void
    {
        $js = $this->sourceWithoutComments(dirname(__DIR__, 3).'/public/install/assets/js/installer.js');

        $this->assertStringContainsString('drop_targets', $js, '동의 카드가 삭제 대상 목록을 그리지 않는다 (#21-2 ①)');
        $this->assertStringContainsString('nested_g7_prefixes', $js, '동의 카드가 이웃 G7 설치 경고를 그리지 않는다 (#21-2 ②)');
    }

    /**
     * ③-배선: 드롭·TRUNCATE 가 테이블명을 기록에 남긴다.
     *
     * @scenario task=db_cleanup
     *
     * @effects installer_drop_targets_are_disclosed_and_recorded
     *
     * @return void 반환값 없음
     */
    public function test_drop_and_truncate_record_table_names(): void
    {
        $projectRoot = dirname(__DIR__, 3);
        $taskRunner = $this->sourceWithoutComments($projectRoot.'/public/install/includes/task-runner.php');
        $rollback = $this->sourceWithoutComments($projectRoot.'/public/install/api/rollback-functions.php');
        $ko = $this->sourceWithoutComments($projectRoot.'/public/install/lang/ko.php');

        $this->assertStringContainsString('log_db_cleanup_dropped_tables', $taskRunner, '드롭한 테이블명이 기록되지 않는다 (#21-2 ③)');
        // TRUNCATE 경로는 기왕부터 테이블별 기록(rollback_table_truncated)을 갖는다 — 그 계약의 잔존만 잠근다.
        $this->assertStringContainsString('rollback_table_truncated', $rollback, 'TRUNCATE 의 테이블별 기록이 사라졌다');
        $this->assertStringContainsString('log_db_cleanup_dropped_tables', $ko);
    }
}
