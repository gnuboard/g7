<?php

namespace Tests\Unit\Upgrades;

require_once __DIR__.'/../../../upgrades/data/7.2.1/migrations/02_NarrowSettingsFilePermissions.php';

use App\Extension\UpgradeContext;
use App\Upgrades\Data\V7_2_1\Migrations\NarrowSettingsFilePermissions;
use Tests\TestCase;

/**
 * 7.2.1 업그레이드 스텝 — 설정 파일 권한을 설정 폴더 범위로 좁힘
 *
 * 7.2.0 설치본은 설정 파일을 만든 프로세스의 umask 를 따라 `identity.json` 만 0664 였다. 이 스텝은 이미 있는 파일에서
 * 폴더 범위를 넘는 비트만 걷어낸다 — 웹호스팅(폴더 755)의 「그 밖 사용자」 읽기는 그대로 둔다.
 */
class NarrowSettingsFilePermissionsTest extends TestCase
{
    /**
     * 스텝의 동결된 판정이 폴더 범위를 넘는 비트만 걷어낸다.
     *
     * @scenario file_state=existing
     *
     * @effects settings_file_existing_drops_bits_beyond_directory_scope
     *
     * @return void 반환값 없음
     */
    public function test_frozen_rule_narrows_only_beyond_directory_scope(): void
    {
        $this->assertSame(0600, NarrowSettingsFilePermissions::narrowedMode(0664, 0700));
        $this->assertSame(0644, NarrowSettingsFilePermissions::narrowedMode(0644, 0755));
        $this->assertSame(0600, NarrowSettingsFilePermissions::narrowedMode(0600, 0755));
        $this->assertSame(0660, NarrowSettingsFilePermissions::narrowedMode(0666, 0770));
    }

    /**
     * 실행해도 업그레이드를 멈추지 않는다 — 권한을 바꾸지 못하는 환경(Windows·chmod 차단)은 건너뛴다.
     *
     * @scenario file_state=existing
     *
     * @effects settings_file_existing_drops_bits_beyond_directory_scope
     *
     * @return void 반환값 없음
     */
    public function test_run_never_throws(): void
    {
        $migration = new NarrowSettingsFilePermissions;

        $migration->run(new UpgradeContext('7.2.0', '7.2.1', '7.2.1'));

        $this->assertSame('NarrowSettingsFilePermissions', $migration->name());
    }
}
