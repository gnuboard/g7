<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 인스톨러 — 확장 카드의 선택 연동(`integrations_detailed`)과 dependencies 목록형 해석 단위 테스트.
 *
 * 설치 마법사 Step 4 는 고른 확장의 `integrations` 대상(모듈·플러그인)을 한 번 자동 체크하고,
 * 번들 버전이 제약에 못 미치면 「연동 버전 미달」을 보인다. 그 입력이 `scan-extensions.php` 가
 * 카드에 싣는 `integrations_detailed: [{type, id, version}]` 이다.
 *
 * 함께 잠그는 결함: `{"modules": ["a"]}` 처럼 유형별 목록형으로 쓴 dependencies 를 키 순회로 읽어
 * 식별자 자리에 `0`·`1` 이 들어가던 문제(카드 「의존성」 줄에 0 이 찍힘).
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class ScanExtensionsIntegrationsTest extends TestCase
{
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

        // 안전 가드: BASE_PATH 가 시스템 temp 하위가 아니면 정리 루틴이 실제 저장소를 지울 수 있다.
        $tempPrefix = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        if (defined('BASE_PATH')) {
            $resolved = realpath((string) BASE_PATH) ?: (string) BASE_PATH;
            if (strpos($resolved, $tempPrefix) !== 0) {
                self::$skipReason = 'BASE_PATH ('.$resolved.') 가 시스템 temp 하위가 아님 — '.
                    '격리 실행 필요: php vendor/bin/phpunit --filter=ScanExtensionsIntegrationsTest';

                return;
            }
            self::$sharedBase = (string) BASE_PATH;
        } else {
            self::$sharedBase = sys_get_temp_dir().'/g7-installer-integrations-test-'.bin2hex(random_bytes(4));
            define('BASE_PATH', self::$sharedBase);
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
            self::clearBundles();
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

        self::clearBundles();
    }

    /**
     * 목록형 연동은 버전 무관(`*`)이고 type·id·version 세 키를 갖습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=installer_scan, owner_type=plugin
     *
     * @effects installer_scan_integrations_list_form_is_any_version
     */
    #[Test]
    public function list_form_integrations_become_any_version(): void
    {
        $this->writeExtension('plugins', 'sirsoft-message_test', [
            'integrations' => ['modules' => ['sirsoft-board', 'sirsoft-ecommerce']],
        ]);

        $rows = scanPlugins();

        $this->assertSame([
            ['type' => 'modules', 'id' => 'sirsoft-board', 'version' => '*'],
            ['type' => 'modules', 'id' => 'sirsoft-ecommerce', 'version' => '*'],
        ], $rows[0]['integrations_detailed']);
    }

    /**
     * 버전 제약 객체형은 값을 그대로 싣고, 버전식이 아닌 설명형 문자열은 `*` 로 받습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=installer_scan, owner_type=module
     *
     * @effects installer_scan_integrations_version_constraint_kept, installer_scan_integrations_descriptive_value_is_any_version
     */
    #[Test]
    public function object_form_keeps_constraints_and_downgrades_descriptions(): void
    {
        $this->writeExtension('modules', 'sirsoft-shop_test', [
            'integrations' => [
                'modules' => [
                    'sirsoft-board' => '>=1.2.1',
                    'sirsoft-page' => '게시판 알림 탭을 끼운다',
                    'sirsoft-faq' => null,
                ],
                'plugins' => ['sirsoft-daum_postcode' => '^1.0'],
            ],
        ]);

        $rows = scanModules();

        $this->assertSame([
            ['type' => 'modules', 'id' => 'sirsoft-board', 'version' => '>=1.2.1'],
            ['type' => 'modules', 'id' => 'sirsoft-page', 'version' => '*'],
            ['type' => 'modules', 'id' => 'sirsoft-faq', 'version' => '*'],
            ['type' => 'plugins', 'id' => 'sirsoft-daum_postcode', 'version' => '^1.0'],
        ], $rows[0]['integrations_detailed']);
    }

    /**
     * 템플릿 카드도 연동을 싣고, 대상 유형 templates 도 그대로 보존합니다(자동 체크 여부는 화면이 정함).
     *
     * @return void 반환값 없음
     *
     * @scenario surface=installer_scan, owner_type=template
     *
     * @effects installer_scan_integrations_version_constraint_kept, installer_scan_integrations_list_form_is_any_version
     */
    #[Test]
    public function template_rows_carry_integrations_including_template_targets(): void
    {
        $this->writeExtension('templates', 'sirsoft-basic_test', [
            'type' => 'user',
            'integrations' => [
                'modules' => ['sirsoft-board' => '>=1.2.1'],
                'plugins' => ['sirsoft-daum_postcode'],
                'templates' => ['sirsoft-admin_basic'],
            ],
        ]);

        $result = scanTemplates();

        $this->assertSame([
            ['type' => 'modules', 'id' => 'sirsoft-board', 'version' => '>=1.2.1'],
            ['type' => 'plugins', 'id' => 'sirsoft-daum_postcode', 'version' => '*'],
            ['type' => 'templates', 'id' => 'sirsoft-admin_basic', 'version' => '*'],
        ], $result['user'][0]['integrations_detailed']);
    }

    /**
     * 모르는 유형 키·문자열이 아닌 식별자·같은 유형 중복은 버립니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=installer_scan, owner_type=module
     *
     * @effects installer_scan_integrations_invalid_entries_dropped
     */
    #[Test]
    public function unknown_types_invalid_ids_and_duplicates_are_dropped(): void
    {
        $detailed = extractIntegrationsDetailedFromJson([
            'integrations' => [
                'modules' => ['sirsoft-board', 42, ['nested'], '', 'sirsoft-board'],
                'widgets' => ['sirsoft-widget'],
                'plugins' => 'sirsoft-not-a-list',
            ],
        ]);

        $this->assertSame([
            ['type' => 'modules', 'id' => 'sirsoft-board', 'version' => '*'],
        ], $detailed);
    }

    /**
     * 연동 선언이 없거나 모양이 틀리면 빈 배열이고, 키는 모든 카드에 있습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=installer_scan, owner_type=module
     *
     * @effects installer_scan_rows_always_carry_integrations_key
     */
    #[Test]
    public function rows_without_integrations_still_carry_an_empty_list(): void
    {
        $this->writeExtension('modules', 'sirsoft-plain_test', []);
        $this->writeExtension('plugins', 'sirsoft-broken_test', ['integrations' => 'sirsoft-board']);

        // 매니페스트 없이 module.php 만 있는 역호환 분기
        $legacyDir = self::$sharedBase.'/modules/_bundled/legacy-module';
        mkdir($legacyDir, 0o755, true);
        file_put_contents($legacyDir.'/module.php', '<?php');

        $modules = scanModules();
        $plugins = scanPlugins();

        $this->assertCount(2, $modules);
        foreach ($modules as $row) {
            $this->assertArrayHasKey('integrations_detailed', $row);
            $this->assertSame([], $row['integrations_detailed']);
        }
        $this->assertSame([], $plugins[0]['integrations_detailed']);
    }

    /**
     * dependencies 유형별 목록형은 키(0, 1)가 아니라 값을 식별자로 읽습니다(D6).
     *
     * @return void 반환값 없음
     *
     * @scenario surface=installer_scan, owner_type=plugin
     *
     * @effects installer_scan_list_form_dependencies_yield_identifiers
     */
    #[Test]
    public function list_form_dependencies_yield_identifiers_not_indexes(): void
    {
        $this->writeExtension('plugins', 'sirsoft-pg_test', [
            'dependencies' => [
                'modules' => ['sirsoft-ecommerce', 'sirsoft-board'],
                'plugins' => ['sirsoft-base_pay' => '>=1.0.0'],
            ],
        ]);

        $rows = scanPlugins();

        $this->assertSame(['sirsoft-ecommerce', 'sirsoft-board', 'sirsoft-base_pay'], $rows[0]['dependencies']);
        $this->assertSame([
            ['type' => 'modules', 'identifier' => 'sirsoft-ecommerce', 'version' => '*'],
            ['type' => 'modules', 'identifier' => 'sirsoft-board', 'version' => '*'],
            ['type' => 'plugins', 'identifier' => 'sirsoft-base_pay', 'version' => '>=1.0.0'],
        ], $rows[0]['dependencies_detailed']);
    }

    /**
     * 객체형·옛 배열형 dependencies 의 식별자 목록은 종전과 같습니다(하위 호환).
     *
     * @return void 반환값 없음
     *
     * @scenario surface=installer_scan, owner_type=template
     *
     * @effects installer_scan_dependency_identifiers_backward_compatible
     */
    #[Test]
    public function object_and_legacy_dependencies_keep_previous_identifiers(): void
    {
        $this->assertSame(
            ['sirsoft-ecommerce', 'sirsoft-daum_postcode'],
            extractDependenciesFromJson(['dependencies' => [
                'modules' => ['sirsoft-ecommerce' => '>=1.3.0'],
                'plugins' => ['sirsoft-daum_postcode' => '>=1.0.0'],
            ]])
        );
        $this->assertSame(['mod-a', 'mod-b'], extractDependenciesFromJson(['dependencies' => ['mod-a', 'mod-b']]));
        $this->assertSame([], extractDependenciesFromJson([]));
        $this->assertSame([], extractDependenciesFromJson(['dependencies' => 'mod-a']));
    }

    /**
     * 버전식 판정 — 코어가 받는 흔한 Composer 제약 모양은 받고 설명 문장은 거부합니다.
     *
     * @param  string  $value  판정할 문자열
     * @param  bool  $expected  버전식이면 true
     * @return void 반환값 없음
     *
     * @scenario surface=installer_scan, owner_type=module
     *
     * @effects installer_version_constraint_detection
     */
    #[Test]
    #[DataProvider('versionConstraintProvider')]
    public function version_constraint_detection(string $value, bool $expected): void
    {
        $this->assertSame($expected, installerLooksLikeVersionConstraint($value), $value);
    }

    /**
     * 버전식 판정 사례.
     *
     * @return array<string, array{0: string, 1: bool}> 사례 목록
     */
    public static function versionConstraintProvider(): array
    {
        return [
            'gte' => ['>=1.2.1', true],
            'caret' => ['^1.0', true],
            'tilde' => ['~1.2.3', true],
            'exact' => ['1.2.3', true],
            'wildcard' => ['*', true],
            'x-range' => ['1.x', true],
            'and-space' => ['>=1.0 <2.0', true],
            'and-comma' => ['>=1.0,<2.0', true],
            'or' => ['^1.0 || ^2.0', true],
            'hyphen' => ['1.0 - 2.0', true],
            'prerelease' => ['>=1.0.0-beta.1', true],
            'korean-description' => ['게시판 알림 연동', false],
            'english-description' => ['board integration', false],
            'empty' => ['', false],
            'operator-only' => ['>=', false],
        ];
    }

    /**
     * 번들 확장을 픽스처로 만듭니다.
     *
     * @param  string  $type  확장 유형 디렉터리 (modules|plugins|templates)
     * @param  string  $identifier  확장 식별자
     * @param  array<string, mixed>  $manifestExtra  매니페스트에 덧붙일 키
     * @return void 반환값 없음
     */
    private function writeExtension(string $type, string $identifier, array $manifestExtra): void
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
            'name' => ['ko' => '연동 픽스처', 'en' => 'Integration fixture'],
            'description' => ['ko' => '', 'en' => ''],
        ], $manifestExtra), JSON_UNESCAPED_UNICODE));
    }

    /**
     * 세 유형의 번들 디렉터리를 비우고 다시 만듭니다.
     *
     * @return void 반환값 없음
     */
    private static function clearBundles(): void
    {
        foreach (['modules', 'plugins', 'templates'] as $type) {
            $dir = self::$sharedBase.'/'.$type.'/_bundled';
            if (is_dir($dir)) {
                foreach (scandir($dir) as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        self::removeRecursive($dir.DIRECTORY_SEPARATOR.$entry);
                    }
                }
            } else {
                mkdir($dir, 0o755, true);
            }
        }
    }

    /**
     * 디렉터리를 재귀적으로 지웁니다.
     *
     * @param  string  $path  대상 경로
     * @return void 반환값 없음
     */
    private static function removeRecursive(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeRecursive($path.DIRECTORY_SEPARATOR.$entry);
                }
            }
            @rmdir($path);

            return;
        }

        @unlink($path);
    }
}
