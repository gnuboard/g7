<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 인스톨러 — 번들 언어팩 스캔 단위 테스트.
 *
 * `public/install/api/scan-extensions.php` 의 `scanLanguagePacks()` 함수를
 * 라이브러리 모드(SCAN_EXTENSIONS_LIBRARY) 로 require 하여 직접 호출.
 * Laravel 부팅 없이 BASE_PATH 만 격리된 디렉토리로 정의해 매니페스트 스캔
 * 결과를 검증한다.
 *
 * BASE_PATH 는 PHP 상수이므로 클래스 라이프사이클 단위로 단 한 번 정의 (setUpBeforeClass).
 * 각 테스트는 setUp 에서 BASE_PATH 하위의 lang-packs/_bundled 를 비워 초기화한다.
 *
 * 검증 항목:
 *  - language-pack.json 매니페스트가 응답 항목으로 노출됨
 *  - scope/target_identifier/locale 필드가 보존됨
 *  - hidden=true 매니페스트는 제외됨
 *  - 동일 디렉토리에 다수 패키지(코어 + 확장 + 동일 확장의 다중 locale)가 모두 노출됨
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class ScanLanguagePacksTest extends TestCase
{
    private static string $sharedBase = '';

    private static string $skipReason = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // 안전 가드: BASE_PATH 가 시스템 temp 디렉토리 하위가 아니면 (= 다른 테스트가 프로젝트 루트로 먼저 박은 경우)
        // setUp/tearDown 의 enumerate-delete 가 실제 lang-packs/_bundled 를 파괴할 수 있으므로 skip.
        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                self::$skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — '.
                    '다른 Installer 테스트의 BASE_PATH 정의가 선행됨. 격리 실행 필요: '.
                    'php vendor/bin/phpunit --filter=ScanLanguagePacksTest';

                return;
            }
            self::$sharedBase = (string) BASE_PATH;
        } else {
            self::$sharedBase = sys_get_temp_dir().'/g7-installer-scanlp-test-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$sharedBase);
        }

        $bundleDir = self::$sharedBase.'/lang-packs/_bundled';
        if (! is_dir($bundleDir)) {
            mkdir($bundleDir, 0755, true);
        }

        if (! defined('SCAN_EXTENSIONS_LIBRARY')) {
            define('SCAN_EXTENSIONS_LIBRARY', true);
        }

        require_once dirname(__DIR__, 3).'/public/install/api/scan-extensions.php';
    }

    public static function tearDownAfterClass(): void
    {
        // skipReason 이 설정되어 있으면 sharedBase 가 미초기화 — destructive cleanup 회피
        if (self::$skipReason === '' && self::$sharedBase !== '') {
            $bundleDir = self::$sharedBase.'/lang-packs/_bundled';
            if (is_dir($bundleDir)) {
                foreach (scandir($bundleDir) as $e) {
                    if ($e === '.' || $e === '..') {
                        continue;
                    }
                    self::removeRecursiveStatic($bundleDir.DIRECTORY_SEPARATOR.$e);
                }
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

        // 각 테스트 시작 시 lang-packs/_bundled 디렉토리 초기화
        $bundle = self::$sharedBase.'/lang-packs/_bundled';
        if (is_dir($bundle)) {
            foreach (scandir($bundle) as $e) {
                if ($e === '.' || $e === '..') {
                    continue;
                }
                self::removeRecursiveStatic($bundle.DIRECTORY_SEPARATOR.$e);
            }
        } else {
            mkdir($bundle, 0755, true);
        }
    }

    #[Test]
    public function scan_returns_empty_when_directory_empty(): void
    {
        $result = scanLanguagePacks();
        $this->assertSame([], $result);
    }

    #[Test]
    public function scan_includes_core_and_extension_packs(): void
    {
        $this->writeManifest('g7-core-ja', [
            'identifier' => 'g7-core-ja',
            'scope' => 'core',
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_native_name' => '日本語',
            'name' => ['ko' => 'G7 Core Japanese', 'en' => 'G7 Core Japanese'],
            'version' => '1.0.0',
        ]);

        $this->writeManifest('g7-module-board-ja', [
            'identifier' => 'g7-module-board-ja',
            'scope' => 'module',
            'target_identifier' => 'sirsoft-board',
            'locale' => 'ja',
            'locale_native_name' => '日本語',
            'name' => 'Board JP',
            'version' => '1.0.0',
        ]);

        $result = scanLanguagePacks();

        $ids = array_column($result, 'identifier');
        sort($ids);
        $this->assertSame(['g7-core-ja', 'g7-module-board-ja'], $ids);

        $core = $this->findById($result, 'g7-core-ja');
        $this->assertSame('core', $core['scope']);
        $this->assertNull($core['target_identifier']);
        $this->assertSame('ja', $core['locale']);
        $this->assertSame('日本語', $core['locale_native_name']);

        $module = $this->findById($result, 'g7-module-board-ja');
        $this->assertSame('module', $module['scope']);
        $this->assertSame('sirsoft-board', $module['target_identifier']);
    }

    #[Test]
    public function scan_excludes_hidden_manifests(): void
    {
        $this->writeManifest('g7-sample-hidden-ja', [
            'identifier' => 'g7-sample-hidden-ja',
            'scope' => 'core',
            'locale' => 'ja',
            'name' => 'Hidden Sample',
            'version' => '1.0.0',
            'hidden' => true,
        ]);

        $this->writeManifest('g7-core-ja', [
            'identifier' => 'g7-core-ja',
            'scope' => 'core',
            'locale' => 'ja',
            'name' => 'G7 Core Japanese',
            'version' => '1.0.0',
        ]);

        $result = scanLanguagePacks();
        $ids = array_column($result, 'identifier');

        $this->assertContains('g7-core-ja', $ids);
        $this->assertNotContains('g7-sample-hidden-ja', $ids);
    }

    #[Test]
    public function scan_supports_multiple_locales_for_same_extension(): void
    {
        $this->writeManifest('g7-module-board-ja', [
            'identifier' => 'g7-module-board-ja',
            'scope' => 'module',
            'target_identifier' => 'sirsoft-board',
            'locale' => 'ja',
            'name' => 'Board JP',
            'version' => '1.0.0',
        ]);

        $this->writeManifest('g7-module-board-zh-CN', [
            'identifier' => 'g7-module-board-zh-CN',
            'scope' => 'module',
            'target_identifier' => 'sirsoft-board',
            'locale' => 'zh-CN',
            'name' => 'Board CN',
            'version' => '1.0.0',
        ]);

        $result = scanLanguagePacks();
        $this->assertCount(2, $result);

        $locales = array_column($result, 'locale');
        sort($locales);
        $this->assertSame(['ja', 'zh-CN'], $locales);

        foreach ($result as $row) {
            $this->assertSame('sirsoft-board', $row['target_identifier']);
        }
    }

    #[Test]
    public function scan_excludes_packs_targeting_hidden_host_extensions(): void
    {
        // hidden 처리된 모듈 매니페스트 작성 (학습용 샘플 시뮬레이션)
        $modulesDir = self::$sharedBase.'/modules/_bundled/sample-hidden-module';
        if (! is_dir($modulesDir)) {
            mkdir($modulesDir, 0755, true);
        }
        file_put_contents($modulesDir.'/module.json', json_encode([
            'identifier' => 'sample-hidden-module',
            'name' => ['ko' => '샘플 모듈', 'en' => 'Sample Module'],
            'version' => '1.0.0',
            'hidden' => true,
        ], JSON_UNESCAPED_UNICODE));

        // 일반 모듈 매니페스트
        $normalDir = self::$sharedBase.'/modules/_bundled/sirsoft-board';
        if (! is_dir($normalDir)) {
            mkdir($normalDir, 0755, true);
        }
        file_put_contents($normalDir.'/module.json', json_encode([
            'identifier' => 'sirsoft-board',
            'name' => ['ko' => '게시판', 'en' => 'Board'],
            'version' => '1.0.0',
        ], JSON_UNESCAPED_UNICODE));

        // hidden 모듈 종속 언어팩
        $this->writeManifest('g7-module-sample-hidden-module-ja', [
            'identifier' => 'g7-module-sample-hidden-module-ja',
            'scope' => 'module',
            'target_identifier' => 'sample-hidden-module',
            'locale' => 'ja',
            'name' => 'Sample Hidden JP',
            'version' => '1.0.0',
        ]);

        // 정상 모듈 종속 언어팩
        $this->writeManifest('g7-module-sirsoft-board-ja', [
            'identifier' => 'g7-module-sirsoft-board-ja',
            'scope' => 'module',
            'target_identifier' => 'sirsoft-board',
            'locale' => 'ja',
            'name' => 'Board JP',
            'version' => '1.0.0',
        ]);

        $result = scanLanguagePacks();
        $ids = array_column($result, 'identifier');

        $this->assertContains('g7-module-sirsoft-board-ja', $ids);
        $this->assertNotContains('g7-module-sample-hidden-module-ja', $ids, 'hidden 호스트 확장 종속 언어팩은 cascade 제외되어야 함');

        // fixture 정리
        @unlink($modulesDir.'/module.json');
        @rmdir($modulesDir);
        @unlink($normalDir.'/module.json');
        @rmdir($normalDir);
    }

    #[Test]
    public function scan_skips_directory_without_manifest(): void
    {
        mkdir(self::$sharedBase.'/lang-packs/_bundled/g7-orphan-ja', 0755, true);

        $this->writeManifest('g7-core-ja', [
            'identifier' => 'g7-core-ja',
            'scope' => 'core',
            'locale' => 'ja',
            'name' => 'G7 Core Japanese',
            'version' => '1.0.0',
        ]);

        $result = scanLanguagePacks();
        $this->assertCount(1, $result);
        $this->assertSame('g7-core-ja', $result[0]['identifier']);
    }

    private function writeManifest(string $directory, array $data): void
    {
        $dir = self::$sharedBase.'/lang-packs/_bundled/'.$directory;
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir.'/language-pack.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function findById(array $rows, string $id): array
    {
        foreach ($rows as $row) {
            if ($row['identifier'] === $id) {
                return $row;
            }
        }
        $this->fail("Identifier {$id} not found in scan result");
    }

    private static function removeRecursiveStatic(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        $entries = scandir($path);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeRecursiveStatic($path.DIRECTORY_SEPARATOR.$entry);
        }
        @rmdir($path);
    }
}
