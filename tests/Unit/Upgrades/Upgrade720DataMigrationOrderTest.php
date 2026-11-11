<?php

namespace Tests\Unit\Upgrades;

use Tests\TestCase;

/**
 * 코어 7.2.0 데이터 스텝의 목록·실행 순서를 잠근다.
 *
 * 02(사이트맵 주기)·03(GeoIP 환경변수 주기)은 00(코어 선언 재동기화 — 스케줄 카탈로그 행 생성)이 만든 행의 cron 을
 * 고친다. 순서가 뒤집히면 행이 없어 이관이 보류되고 운영자가 바꿔 둔 주기가 기본값으로 돌아간다. 출시 뒤 이 디렉토리는
 * 동결되므로 목록과 순서 의존을 시험으로 고정한다.
 */
class Upgrade720DataMigrationOrderTest extends TestCase
{
    /**
     * 파일 정렬 순서가 기대 순서와 같고, 행을 만드는 스텝이 행을 고치는 스텝보다 앞선다.
     *
     * @scenario step_dir=core_7_2_0
     *
     * @effects data_migrations_run_in_deterministic_sorted_order, schedule_rows_exist_before_cron_migrations
     *
     * @return void 반환값 없음
     */
    public function test_data_migrations_run_in_locked_order(): void
    {
        $files = glob(base_path('upgrades/data/7.2.0/migrations').'/*.php') ?: [];
        sort($files);
        $names = array_map(fn (string $path) => basename($path, '.php'), $files);

        $this->assertSame([
            '00_ResyncCoreDeclarations',
            '01_SyncActiveExtensionSchedules',
            '02_MigrateSitemapScheduleSetting',
            '03_MigrateGeoipScheduleEnv',
            '04_MovePublicStorageBrokenBackupsOutOfDocroot',
            '05_SeedDeployFragmentLedger',
            '06_RegisterTemplateSettingsLayouts',
        ], $names, '7.2.0 데이터 스텝 목록·순서가 바뀌었다 — 출시 뒤 동결 디렉토리다');

        $at = array_flip($names);
        $this->assertSame(0, $at['00_ResyncCoreDeclarations'], '코어 선언 재동기화(스케줄 카탈로그 행 생성)는 맨 앞이어야 한다');
        $this->assertLessThan($at['02_MigrateSitemapScheduleSetting'], $at['00_ResyncCoreDeclarations']);
        $this->assertLessThan($at['03_MigrateGeoipScheduleEnv'], $at['00_ResyncCoreDeclarations']);
    }
}
