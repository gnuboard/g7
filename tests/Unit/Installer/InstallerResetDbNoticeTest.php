<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerSubprocess;

/**
 * 설치 상태 초기화(state-management.php?action=reset)의 DB 수동 정리 안내 회귀 테스트.
 *
 * 회귀: 의존성 사전 검증 실패 화면의 「Step 4 로 돌아가기」는 데이터베이스를 건드리기 전인데도
 * 초기화 때마다 「DB는 자동으로 초기화되지 않습니다. 필요 시 수동으로 정리해주세요.」 를 설치 로그에 남겼다.
 * → 이번 설치 시도에서 DB 작업(db_cleanup 부터)이 시작된 적이 없으면 그 안내를 남기지 않는다.
 *   DB 작업이 하나라도 끝났거나 DB 작업에서 멈췄으면 종전대로 남긴다.
 *
 * 시나리오 SSoT: tests/scenarios/extension-cross-type-order.yaml
 */
class InstallerResetDbNoticeTest extends TestCase
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
     * 실패 상태별로 초기화가 DB 안내를 남기는지.
     *
     * @scenario surface=installer, edge=cycle
     *
     * @effects reset_skips_db_notice_before_database_tasks, reset_keeps_db_notice_after_database_tasks
     *
     * @param  array<string, mixed>  $state  초기화 직전 상태(소유·세대 필드는 테스트가 채운다)
     * @param  bool  $expectNotice  DB 안내가 남아야 하는지
     * @return void 반환값 없음
     */
    #[DataProvider('resetStateProvider')]
    public function test_reset_logs_db_notice_only_when_database_was_touched(array $state, bool $expectNotice): void
    {
        $base = $this->makeInstallerTempBase();
        $ownerToken = str_repeat('d', 64);
        $this->writeInstallerState($state + [
            'owner_hash' => hash('sha256', $ownerToken),
            'generation' => 5,
            'current_step' => 5,
        ]);

        $result = $this->runInstallerEndpoint('state-management.php', [
            'get' => ['action' => 'reset'],
            'session' => ['install_owner' => $ownerToken, 'csrf_token' => 'tok'],
            'headers' => ['HTTP_X_CSRF_TOKEN' => 'tok'],
        ]);

        $this->assertSame(200, $result['status'], $result['raw']);
        $this->assertTrue($result['body']['success'] ?? false, $result['raw']);
        $this->assertSame(4, $result['body']['target_step'] ?? null);

        $log = (string) @file_get_contents($base.'/storage/logs/installation.log');
        $this->assertNotSame('', $log, '초기화 요청 로그가 남지 않았다');

        $notices = $this->dbNoticeTexts();
        $found = false;
        foreach ($notices as $notice) {
            if (str_contains($log, $notice)) {
                $found = true;
            }
        }

        if ($expectNotice) {
            $this->assertTrue($found, "DB 작업 뒤 초기화인데 DB 수동 정리 안내가 없다:\n".$log);
        } else {
            $this->assertFalse($found, "DB 를 건드리기 전 초기화인데 DB 수동 정리 안내가 남았다:\n".$log);
        }

        $after = $this->readInstallerState();
        $this->assertSame('not_started', $after['installation_status'] ?? null);
        $this->assertSame([], $after['completed_tasks'] ?? null);
    }

    /**
     * 초기화 직전 상태 목록.
     *
     * @return array<string, array{0: array<string, mixed>, 1: bool}> 사례 이름 → [초기화 직전 상태, DB 안내 기대 여부]
     */
    public static function resetStateProvider(): array
    {
        $preDb = ['composer_check', 'composer_install', 'env_update', 'key_generate'];

        return [
            '의존성 사전 검증 실패' => [[
                'installation_status' => 'failed',
                'failed_task' => 'dependency_precheck',
                'error_message_key' => 'dependency_precheck_failed',
                'completed_tasks' => $preDb,
            ], false],
            '환경 설정 단계 실패(composer)' => [[
                'installation_status' => 'failed',
                'failed_task' => 'composer_install',
                'completed_tasks' => ['composer_check'],
            ], false],
            '설치 시작 전(작업 없음)' => [[
                'installation_status' => 'not_started',
                'completed_tasks' => [],
            ], false],
            'DB 마이그레이션 실패' => [[
                'installation_status' => 'failed',
                'failed_task' => 'db_migrate',
                'completed_tasks' => array_merge($preDb, ['dependency_precheck', 'db_cleanup']),
            ], true],
            '확장 설치 실패(DB 작업 끝난 뒤)' => [[
                'installation_status' => 'failed',
                'failed_task' => 'module_install:sirsoft-page',
                'completed_tasks' => array_merge($preDb, ['dependency_precheck', 'db_cleanup', 'db_migrate', 'db_seed']),
            ], true],
            'DB 작업 중 중단' => [[
                'installation_status' => 'aborted',
                'current_task' => 'db_seed',
                'completed_tasks' => array_merge($preDb, ['dependency_precheck', 'db_cleanup', 'db_migrate']),
            ], true],
        ];
    }

    /**
     * state_reset_db_notice 의 모든 로케일 문구.
     *
     * @return list<string> 문구 목록
     */
    private function dbNoticeTexts(): array
    {
        $texts = [];
        foreach (glob(dirname(__DIR__, 3).'/public/install/lang/*.php') ?: [] as $file) {
            $lang = require $file;
            if (is_array($lang) && isset($lang['state_reset_db_notice'])) {
                $texts[] = (string) $lang['state_reset_db_notice'];
            }
        }
        $this->assertNotEmpty($texts, 'state_reset_db_notice 문구를 찾지 못했다');

        return $texts;
    }
}
