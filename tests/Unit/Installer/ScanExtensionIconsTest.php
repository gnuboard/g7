<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 인스톨러 — 확장 카드 아이콘 data URI 단위 테스트.
 *
 * 인스톨러 Step 4 는 **Laravel 없이** 도는 화면이라 아이콘 서빙 라우트를 쓸 수 없다.
 * 대신 `scan-extensions.php` 가 검증기를 직접 require 해서 규격을 통과한 바이트만
 * data URI 로 카드에 실어 보낸다. 이 테스트가 그 경계를 잠근다.
 *
 * 검증기가 없어도(파일 부재·다른 배포 형태) fatal 없이 아이콘만 빠져야 한다 —
 * 인스톨러가 죽으면 설치 자체가 막히기 때문이다.
 *
 * @scenario surface=installer_scan
 *
 * @effects installer_scan_embeds_valid_icon_only
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class ScanExtensionIconsTest extends TestCase
{
    /** @var string 정사각 유효 SVG */
    private const VALID_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><title>t</title><rect width="64" height="64" fill="#336699"/></svg>';

    /** @var string 이벤트 핸들러가 박힌 규격 위반 SVG */
    private const UNSAFE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" onload="alert(1)"/></svg>';

    private static string $sharedBase = '';

    private static string $skipReason = '';

    /**
     * 격리된 BASE_PATH 를 정의하고 스캔 스크립트를 라이브러리 모드로 적재합니다.
     *
     * @return void 반환값 없음
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // 안전 가드: BASE_PATH 가 시스템 temp 하위가 아니면 정리 루틴이 실제 저장소를
        // 지울 수 있으므로 건너뛴다(ScanLanguagePacksTest 와 같은 규칙).
        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                self::$skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — '.
                    '격리 실행 필요: php vendor/bin/phpunit --filter=ScanExtensionIconsTest';

                return;
            }
            self::$sharedBase = (string) BASE_PATH;
        } else {
            self::$sharedBase = sys_get_temp_dir().'/g7-installer-icon-test-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$sharedBase);
        }

        foreach (['modules', 'plugins', 'templates'] as $type) {
            $dir = self::$sharedBase.'/'.$type.'/_bundled';
            if (! is_dir($dir)) {
                mkdir($dir, 0o755, true);
            }
        }

        if (! defined('SCAN_EXTENSIONS_LIBRARY')) {
            define('SCAN_EXTENSIONS_LIBRARY', true);
        }

        require_once dirname(__DIR__, 3).'/public/install/api/scan-extensions.php';
    }

    /**
     * 픽스처 디렉터리를 정리합니다.
     *
     * @return void 반환값 없음
     */
    public static function tearDownAfterClass(): void
    {
        if (self::$skipReason === '' && self::$sharedBase !== '') {
            foreach (['modules', 'plugins', 'templates'] as $type) {
                $dir = self::$sharedBase.'/'.$type.'/_bundled';
                if (is_dir($dir)) {
                    foreach (scandir($dir) as $e) {
                        if ($e !== '.' && $e !== '..') {
                            self::removeRecursiveStatic($dir.DIRECTORY_SEPARATOR.$e);
                        }
                    }
                }
            }
        }

        parent::tearDownAfterClass();
    }

    /**
     * 각 테스트 전에 번들 디렉터리를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipReason !== '') {
            $this->markTestSkipped(self::$skipReason);
        }

        foreach (['modules', 'plugins', 'templates'] as $type) {
            $dir = self::$sharedBase.'/'.$type.'/_bundled';
            if (is_dir($dir)) {
                foreach (scandir($dir) as $e) {
                    if ($e !== '.' && $e !== '..') {
                        self::removeRecursiveStatic($dir.DIRECTORY_SEPARATOR.$e);
                    }
                }
            } else {
                mkdir($dir, 0o755, true);
            }
        }
    }

    /**
     * 관례 아이콘이 있으면 SVG data URI 가 실립니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=installer_scan, declaration=convention_svg, validity=valid
     *
     * @effects installer_scan_embeds_valid_icon_only
     */
    #[Test]
    public function valid_convention_icon_is_embedded_as_data_uri(): void
    {
        $this->writeExtension('modules', 'test-icon-mod', [], ['assets/icon.svg' => self::VALID_SVG]);

        $rows = scanModules();

        $this->assertCount(1, $rows);
        $this->assertIsString($rows[0]['icon_data_uri']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $rows[0]['icon_data_uri']);
        $this->assertSame(
            self::VALID_SVG,
            base64_decode(substr($rows[0]['icon_data_uri'], strlen('data:image/svg+xml;base64,')), true)
        );
    }

    /**
     * 규격 위반 아이콘은 실리지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=installer_scan, declaration=convention_svg, validity=invalid_svg
     *
     * @effects installer_scan_embeds_valid_icon_only
     */
    #[Test]
    public function invalid_icon_is_not_embedded(): void
    {
        $this->writeExtension('plugins', 'test-icon-bad', [], ['assets/icon.svg' => self::UNSAFE_SVG]);

        $rows = scanPlugins();

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['icon_data_uri']);
    }

    /**
     * 100KB 를 넘는 아이콘은 실리지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=installer_scan, declaration=convention_svg, validity=too_large
     *
     * @effects installer_scan_embeds_valid_icon_only
     */
    #[Test]
    public function oversized_icon_is_not_embedded(): void
    {
        $padding = str_repeat('a', 102400);
        $bloated = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><title>'.$padding.'</title></svg>';
        $this->writeExtension('plugins', 'test-icon-big', [], ['assets/icon.svg' => $bloated]);

        $rows = scanPlugins();

        $this->assertNull($rows[0]['icon_data_uri']);
    }

    /**
     * 아이콘이 없으면 null 이고 카드는 유형 글리프로 남습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=installer_scan, declaration=none
     *
     * @effects installer_scan_embeds_valid_icon_only
     */
    #[Test]
    public function missing_icon_yields_null(): void
    {
        $this->writeExtension('modules', 'test-icon-none', [], []);

        $rows = scanModules();

        $this->assertNull($rows[0]['icon_data_uri']);
    }

    /**
     * 매니페스트 icon 이 패키지 밖을 가리키면 관례로 폴백합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=installer_scan, declaration=manifest_path, validity=path_escape
     *
     * @effects installer_scan_embeds_valid_icon_only
     */
    #[Test]
    public function manifest_icon_escaping_the_package_falls_back_to_convention(): void
    {
        $this->writeExtension(
            'plugins',
            'test-icon-escape',
            ['icon' => '../../../etc/passwd.svg'],
            ['assets/icon.svg' => self::VALID_SVG]
        );

        $rows = scanPlugins();

        $this->assertIsString($rows[0]['icon_data_uri'], '탈출 경로는 무시하고 관례 아이콘을 써야 합니다.');
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $rows[0]['icon_data_uri']);
    }

    /**
     * 템플릿 카드도 같은 규칙으로 아이콘을 싣습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=installer_scan, extension_type=template, declaration=convention_svg
     *
     * @effects installer_scan_embeds_valid_icon_only
     */
    #[Test]
    public function template_rows_carry_the_icon_too(): void
    {
        $this->writeExtension('templates', 'test-icon-tpl', ['type' => 'user'], ['assets/icon.svg' => self::VALID_SVG]);

        $result = scanTemplates();

        $this->assertCount(1, $result['user']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $result['user'][0]['icon_data_uri']);
    }

    /**
     * 언어팩 행에는 아이콘 키를 두지 않습니다 (체크박스 행이라 그림 자리가 없음).
     *
     * @return void 반환값 없음
     *
     * @scenario surface=installer_scan, extension_type=language_pack
     *
     * @effects installer_scan_embeds_valid_icon_only
     */
    #[Test]
    public function language_pack_rows_have_no_icon_key(): void
    {
        $dir = self::$sharedBase.'/lang-packs/_bundled/g7-core-ja';
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        file_put_contents($dir.'/language-pack.json', json_encode([
            'identifier' => 'g7-core-ja',
            'namespace' => 'g7',
            'vendor' => 'test',
            'name' => ['ko' => '코어 일본어', 'en' => 'Core Japanese'],
            'version' => '1.0.0',
            'scope' => 'core',
            'locale' => 'ja',
            'locale_native_name' => '日本語',
        ], JSON_UNESCAPED_UNICODE));

        $rows = scanLanguagePacks();

        $this->assertNotSame([], $rows);
        $this->assertArrayNotHasKey('icon_data_uri', $rows[0]);

        self::removeRecursiveStatic($dir);
    }

    /**
     * 번들 확장을 픽스처로 만듭니다.
     *
     * @param  string  $type  확장 유형 디렉터리 (modules|plugins|templates)
     * @param  string  $identifier  확장 식별자
     * @param  array<string, mixed>  $manifestExtra  매니페스트에 덧붙일 키
     * @param  array<string, string>  $files  루트 기준 상대경로 → 내용
     * @return void 반환값 없음
     */
    private function writeExtension(string $type, string $identifier, array $manifestExtra, array $files): void
    {
        $manifestFile = match ($type) {
            'modules' => 'module.json',
            'plugins' => 'plugin.json',
            default => 'template.json',
        };

        $root = self::$sharedBase.'/'.$type.'/_bundled/'.$identifier;
        if (! is_dir($root)) {
            mkdir($root, 0o755, true);
        }

        file_put_contents($root.'/'.$manifestFile, json_encode(array_merge([
            'identifier' => $identifier,
            'version' => '1.0.0',
            'vendor' => 'test',
            'name' => ['ko' => '아이콘 픽스처', 'en' => 'Icon fixture'],
            'description' => ['ko' => '', 'en' => ''],
        ], $manifestExtra), JSON_UNESCAPED_UNICODE));

        foreach ($files as $relative => $contents) {
            $path = $root.'/'.$relative;
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0o755, true);
            }
            file_put_contents($path, $contents);
        }
    }

    /**
     * 검증기가 없으면 아이콘만 빠지고 스캔은 끝까지 돕니다.
     *
     * 인스톨러는 Laravel autoload 없이 도는 화면이라 검증기를 고정 경로에서 require 한다.
     * 그 파일이 없는 배포본에서 fatal 이 나면 **설치 자체가 막힌다** — 아이콘 하나 때문에
     * 사이트를 세우지 못하는 것이 이 테스트가 막는 최악의 경로다.
     *
     * 현재 프로세스에는 검증기가 이미 적재돼 있어 `class_exists` 가드를 여기서 재현할 수
     * 없다. 그래서 검증기 파일이 **없는** 트리에 스캔 스크립트를 복사해 자식 PHP 로 돌린다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=installer_scan, declaration=convention_svg
     *
     * @effects installer_scan_embeds_valid_icon_only
     */
    #[Test]
    public function scan_survives_without_the_validator_and_drops_the_icon(): void
    {
        $tree = sys_get_temp_dir().'/g7-installer-novalidator-'.bin2hex(random_bytes(4));
        $apiDir = $tree.'/public/install/api';
        mkdir($apiDir, 0o755, true);

        // 스캔 스크립트만 옮긴다 — app/Support/ExtensionIcon/ExtensionIconValidator.php 는
        // 일부러 만들지 않는다. 이것이 "검증기 부재" 의 재현이다.
        copy(dirname(__DIR__, 3).'/public/install/api/scan-extensions.php', $apiDir.'/scan-extensions.php');
        $this->assertFileDoesNotExist($tree.'/app/Support/ExtensionIcon/ExtensionIconValidator.php');

        $base = $tree.'/scan-target';
        mkdir($base.'/plugins/_bundled/test-icon-novalidator/assets', 0o755, true);
        file_put_contents(
            $base.'/plugins/_bundled/test-icon-novalidator/plugin.json',
            json_encode([
                'identifier' => 'test-icon-novalidator',
                'version' => '1.0.0',
                'vendor' => 'test',
                'name' => ['ko' => '검증기 없음', 'en' => 'No validator'],
                'description' => ['ko' => '', 'en' => ''],
            ], JSON_UNESCAPED_UNICODE)
        );
        // 규격을 지킨 아이콘을 둔다 — 그래도 검증기가 없으면 실리지 않아야 한다.
        file_put_contents($base.'/plugins/_bundled/test-icon-novalidator/assets/icon.svg', self::VALID_SVG);

        $runner = $tree.'/run.php';
        file_put_contents($runner, '<?php '.PHP_EOL
            .'define("BASE_PATH", '.var_export($base, true).');'.PHP_EOL
            .'define("SCAN_EXTENSIONS_LIBRARY", true);'.PHP_EOL
            .'require '.var_export($apiDir.'/scan-extensions.php', true).';'.PHP_EOL
            .'$rows = scanPlugins();'.PHP_EOL
            // `??` 는 "키가 없다" 와 "값이 null 이다" 를 못 가른다 — 이 테스트가 가르려는
            // 바로 그 둘이라 array_key_exists 로 따로 싣는다.
            .'echo json_encode(['.PHP_EOL
            .'    "count" => count($rows),'.PHP_EOL
            .'    "has_key" => isset($rows[0]) && array_key_exists("icon_data_uri", $rows[0]),'.PHP_EOL
            .'    "icon" => $rows[0]["icon_data_uri"] ?? null,'.PHP_EOL
            .']);'.PHP_EOL);

        // 명령을 문자열로 준다 — Windows 에서는 배열 형태의 인자 이스케이프가 셸을 거치며
        // 달라져 경로에 공백이 있으면 스크립트를 못 찾는다.
        $command = escapeshellarg(PHP_BINARY).' -d error_reporting=E_ALL '.escapeshellarg($runner);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);
        $this->assertIsResource($process, '자식 PHP 프로세스를 띄우지 못했습니다.');

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::removeRecursiveStatic($tree);

        // ① 죽지 않는다.
        $this->assertSame(0, $exitCode, '검증기가 없을 때 스캔이 죽었습니다: '.$stderr);
        $this->assertStringNotContainsStringIgnoringCase('fatal error', $stderr);
        $this->assertStringNotContainsStringIgnoringCase('class "app\\support', $stderr);

        // ② 스캔은 끝까지 돌아 행을 돌려준다.
        $payload = json_decode(trim($stdout), true);
        $this->assertIsArray($payload, '스캔 출력이 JSON 이 아닙니다: '.$stdout.$stderr);
        $this->assertSame(1, $payload['count'], '검증기가 없어도 확장 행은 그대로 나와야 합니다.');

        // ③ 아이콘 자리는 키를 유지한 채 null 이다 — 카드가 유형 글리프로 떨어진다.
        //    키가 통째로 빠지면 프런트가 `item.icon_data_uri` 를 읽다 undefined 를 만난다.
        $this->assertTrue($payload['has_key'], 'icon_data_uri 키 자체가 사라졌습니다.');
        $this->assertNull($payload['icon'], '검증기가 없으면 아이콘을 실어서는 안 됩니다.');
    }

    /**
     * 디렉터리를 재귀적으로 지웁니다.
     *
     * @param  string  $path  대상 경로
     * @return void 반환값 없음
     */
    private static function removeRecursiveStatic(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) as $e) {
                if ($e !== '.' && $e !== '..') {
                    self::removeRecursiveStatic($path.DIRECTORY_SEPARATOR.$e);
                }
            }
            @rmdir($path);

            return;
        }

        @unlink($path);
    }
}
