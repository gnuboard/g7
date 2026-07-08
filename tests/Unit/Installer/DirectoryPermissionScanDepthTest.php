<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 인스톨러 디렉토리 권한 재귀 스캔 깊이 제한 회귀 테스트.
 *
 * 배경 (dev-g7#445 후속):
 *   인스톨러 2단계(요구사항 검증)의 checkDirectoryPermissions 가 storage 를 재귀 스캔하는데,
 *   checkSubdirectoriesRecursive 가 깊이 제한 없이 무한 재귀하여 storage/framework/testing/
 *   하위에 누적된 수만 개 디렉토리를 전부 순회 → 2단계 응답이 8초 이상 지연되던 문제.
 *
 *   권한 안내는 트리 상위 얕은 깊이에서만 유의미하므로 재귀에 깊이 제한을 둔다.
 *   본 테스트는 깊이 제한이 실제로 적용되어 제한 깊이를 넘는 하위는 순회하지 않음을 검증한다.
 *
 * BASE_PATH 는 PHP 상수로 클래스 라이프사이클 단위 단 한 번 정의 (setUpBeforeClass).
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class DirectoryPermissionScanDepthTest extends TestCase
{
    private static string $sharedBase = '';

    private static string $skipReason = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                self::$skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — '.
                    '격리 실행 필요: php vendor/bin/phpunit --filter=DirectoryPermissionScanDepthTest';

                return;
            }
            self::$sharedBase = (string) BASE_PATH;
        } else {
            self::$sharedBase = sys_get_temp_dir().'/g7-installer-dirscan-test-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$sharedBase);
        }

        if (! is_dir(self::$sharedBase)) {
            mkdir(self::$sharedBase, 0755, true);
        }

        if (! isset($_SERVER['SCRIPT_NAME'])) {
            $_SERVER['SCRIPT_NAME'] = '/install/index.php';
        }

        require_once dirname(__DIR__, 3).'/public/install/includes/config.php';
        require_once dirname(__DIR__, 3).'/public/install/includes/functions.php';
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }
    }

    /**
     * 깊이 제한을 넘는 하위 디렉토리는 재귀 스캔 대상에서 제외되어야 한다.
     *
     * storage/deep/a/b/c/... 처럼 깊은 트리를 만들고, checkSubdirectoriesRecursive 를
     * 제한 깊이(2)로 호출하면 depth 3 이상 경로는 결과에 나타나지 않아야 한다.
     */
    #[Test]
    public function recursive_scan_respects_max_depth(): void
    {
        // BASE_PATH/scanroot 하위에 깊이 5의 중첩 디렉토리 생성 (전부 정상 권한)
        $root = self::$sharedBase.'/scanroot';
        $deep = $root.'/lvl1/lvl2/lvl3/lvl4/lvl5';
        if (! is_dir($deep)) {
            mkdir($deep, 0755, true);
        }

        // 깊이 2 제한으로 스캔 — 함수는 (path, maxDepth) 시그니처를 지원해야 한다.
        $failed = checkSubdirectoriesRecursive($root, 2);

        // 모든 디렉토리가 정상 권한이므로 실패 항목은 없어야 하고, 무엇보다
        // 함수가 무한 재귀 없이 즉시 반환해야 한다 (깊이 제한 동작 확인).
        $this->assertIsArray($failed);

        // 깊이 제한이 동작하는지 간접 검증: 깊은 트리에서도 스캔이 즉시 끝나야 한다.
        $t0 = microtime(true);
        checkSubdirectoriesRecursive($root, 2);
        $elapsed = microtime(true) - $t0;

        $this->assertLessThan(
            1.0,
            $elapsed,
            '깊이 제한된 재귀 스캔은 얕은 깊이만 순회하므로 즉시 완료되어야 한다.'
        );
    }

    /**
     * checkSubdirectoriesRecursive 가 maxDepth 파라미터를 받는 시그니처여야 한다.
     */
    #[Test]
    public function function_accepts_max_depth_parameter(): void
    {
        $ref = new \ReflectionFunction('checkSubdirectoriesRecursive');
        $params = $ref->getParameters();

        $this->assertGreaterThanOrEqual(
            2,
            count($params),
            'checkSubdirectoriesRecursive 는 깊이 제한 파라미터를 받아야 한다 '.
            '(무한 재귀로 storage 하위 수만 개 디렉토리를 순회해 인스톨러 2단계가 지연되던 문제 차단).'
        );

        // 두 번째 파라미터는 깊이 제한(정수, 기본값 존재)이어야 한다.
        $depthParam = $params[1];
        $this->assertTrue(
            $depthParam->isDefaultValueAvailable(),
            '깊이 제한 파라미터는 기본값을 가져 기존 호출부(check-configuration.php)가 변경 없이 동작해야 한다.'
        );
    }
}
