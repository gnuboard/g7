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
 * BASE_PATH 는 PHP 상수로 클래스 라이프사이클 단위 단 한 번 정의 (setUpBeforeClass).
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class InstallerStateSchemaTest extends TestCase
{
    private static string $sharedBase = '';

    private static string $skipReason = '';

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

    #[Test]
    public function default_state_includes_language_packs_empty_array(): void
    {
        $default = DEFAULT_INSTALLATION_STATE;
        $this->assertArrayHasKey('selected_extensions', $default);
        $this->assertArrayHasKey('language_packs', $default['selected_extensions']);
        $this->assertSame([], $default['selected_extensions']['language_packs']);
    }

    #[Test]
    public function get_state_returns_language_packs_when_state_file_missing(): void
    {
        $state = getInstallationState();
        $this->assertArrayHasKey('selected_extensions', $state);
        $this->assertArrayHasKey('language_packs', $state['selected_extensions']);
        $this->assertSame([], $state['selected_extensions']['language_packs']);
    }

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

}
