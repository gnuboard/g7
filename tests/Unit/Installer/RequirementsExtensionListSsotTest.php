<?php

namespace Tests\Unit\Installer;

use App\Support\PhpExtensionRequirements;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Installer\Concerns\RunsInstallerSubprocess;

/**
 * 인스톨러 PHP 확장 목록 SSoT·확장 선택 단계의 PHP 확장 요구 판정 (#151 W1-a·W1-c, D6)
 *
 * - 요구사항 단계의 차단 목록(REQUIRED_EXTENSIONS)이 코어 SSoT 에서 나오고, 그 결과 zip·pdo_mysql 이 자동 검사 대상이 된다(14 → 16).
 * - 확장 선택 단계는 번들 확장 composer.json 의 require ext-*(없으면 선택 불가)·suggest ext-*(경고만)를 싣고,
 *   저장 단계는 필수 미충족 확장을 서버에서 다시 걸러 낸다(클라이언트 우회 방지).
 *
 * 인스톨러는 프로세스당 1회 정의되는 BASE_PATH 를 쓰므로 격리된 서브프로세스에서 실행한다.
 */
class RequirementsExtensionListSsotTest extends TestCase
{
    use RunsInstallerSubprocess;

    /**
     * 임시 앱 루트를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->makeInstallerTempBase();
    }

    /**
     * 임시 앱 루트를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->removeInstallerTempBase();
        parent::tearDown();
    }

    /**
     * 인스톨러 상수가 SSoT 와 같고, zip·pdo_mysql 이 차단 대상(필수)에 들어 있습니다.
     *
     * @scenario surface=installer_requirements
     *
     * @effects installer_constants_follow_ssot
     *
     * @return void 반환값 없음
     */
    public function test_installer_constants_follow_ssot(): void
    {
        $result = $this->runInstallerScript('echo json_encode([REQUIRED_EXTENSIONS, OPTIONAL_EXTENSIONS]);');
        [$required, $optional] = json_decode(strtok($result['raw'], "\n"), true);

        $ssot = PhpExtensionRequirements::load();
        $this->assertSame($ssot['required'], $required);
        $this->assertSame($ssot['optional'], $optional);
        $this->assertContains('zip', $required);
        $this->assertContains('pdo_mysql', $required);
        $this->assertCount(16, $required);
    }

    /**
     * 번들 확장의 require ext-* 미충족은 선택 불가 목록으로, suggest 미충족은 사유와 함께 경고 목록으로 판정합니다.
     *
     * @scenario surface=installer_extension_selection
     *
     * @effects installer_marks_require_missing_and_suggest_missing
     *
     * @return void 반환값 없음
     */
    public function test_extension_requirement_judgement(): void
    {
        $this->writeBundled('modules', 'g7test-needs_fake', ['require' => ['php' => '^8.2', 'ext-g7fakeext' => '*', 'ext-json' => '*']]);
        $this->writeBundled('plugins', 'g7test-suggests_fake', ['suggest' => ['ext-g7fakesoap' => '모바일 결제에 필요']]);

        $result = $this->runInstallerScript(
            "require_once \$projectRoot.'/public/install/includes/php-extension-requirements.php';"
            .'echo json_encode(['
            ."installer_extension_php_requirements(BASE_PATH.'/modules/_bundled/g7test-needs_fake'),"
            ."installer_extension_php_requirements(BASE_PATH.'/plugins/_bundled/g7test-suggests_fake'),"
            ."installer_extension_php_requirements(BASE_PATH.'/plugins/_bundled/g7test-absent'),"
            .'], JSON_UNESCAPED_UNICODE);'
        );
        [$needs, $suggests, $absent] = json_decode(strtok($result['raw'], "\n"), true);

        $this->assertSame(['g7fakeext'], $needs['missing_php_extensions']);
        $this->assertSame([], $needs['suggested_missing']);
        $this->assertSame([], $suggests['missing_php_extensions']);
        $this->assertSame(['g7fakesoap' => '모바일 결제에 필요'], $suggests['suggested_missing']);
        $this->assertSame(['missing_php_extensions' => [], 'suggested_missing' => []], $absent);
    }

    /**
     * 저장 단계의 서버 측 재검증은 필수 미충족 확장만 빼고, 뺀 이유(없는 확장)를 돌려줍니다.
     *
     * @scenario surface=installer_save_extensions
     *
     * @effects installer_server_skips_require_missing
     *
     * @return void 반환값 없음
     */
    public function test_server_side_filter_skips_require_missing(): void
    {
        $this->writeBundled('modules', 'g7test-needs_fake', ['require' => ['ext-g7fakeext' => '*']]);
        $this->writeBundled('modules', 'g7test-plain', ['require' => ['php' => '^8.2']]);
        $this->writeBundled('modules', 'g7test-suggests', ['suggest' => ['ext-g7fakesoap' => '']]);

        $result = $this->runInstallerScript(
            "require_once \$projectRoot.'/public/install/includes/php-extension-requirements.php';"
            ."echo json_encode(installer_filter_extensions_missing_php(['g7test-needs_fake', 'g7test-plain', 'g7test-suggests'], BASE_PATH.'/modules/_bundled'));"
        );
        $filtered = json_decode(strtok($result['raw'], "\n"), true);

        $this->assertSame(['g7test-plain', 'g7test-suggests'], $filtered['kept']);
        $this->assertSame(['g7test-needs_fake' => ['g7fakeext']], $filtered['skipped']);
    }

    /**
     * 공유 해석기 결과에서 필수 미충족 모듈·플러그인을 빼고 설치 순서도 같은 집합으로 맞추며, 웹 저장과 터미널 설치가 같은 함수를 씁니다.
     *
     * @scenario surface=installer_save_extensions, entry=web_and_cli
     *
     * @effects installer_resolution_excludes_require_missing
     *
     * @return void 반환값 없음
     */
    public function test_resolution_exclusion_is_shared_by_web_and_cli(): void
    {
        $this->writeBundled('modules', 'g7test-needs_fake', ['require' => ['ext-g7fakeext' => '*']]);
        $this->writeBundled('modules', 'g7test-plain', ['require' => ['php' => '^8.2']]);
        $this->writeBundled('plugins', 'g7test-plugin_needs', ['require' => ['ext-g7fakesoap' => '*']]);
        $this->writeBundled('plugins', 'g7test-plugin_plain', []);

        $resolution = [
            'selection' => [
                'admin_templates' => ['g7test-admin'],
                'user_templates' => [],
                'modules' => ['g7test-plain', 'g7test-needs_fake'],
                'plugins' => ['g7test-plugin_needs', 'g7test-plugin_plain'],
                'language_packs' => [],
            ],
            'install_order' => [
                'admin_templates:g7test-admin',
                'modules:g7test-plain',
                'modules:g7test-needs_fake',
                'plugins:g7test-plugin_needs',
                'plugins:g7test-plugin_plain',
            ],
        ];

        $result = $this->runInstallerScript(
            "require_once \$projectRoot.'/public/install/includes/php-extension-requirements.php';"
            .'echo json_encode(installer_exclude_selection_missing_php('.var_export($resolution, true).', BASE_PATH));'
        );
        $excluded = json_decode(strtok($result['raw'], "\n"), true);

        $this->assertSame(['g7test-plain'], $excluded['resolution']['selection']['modules']);
        $this->assertSame(['g7test-plugin_plain'], $excluded['resolution']['selection']['plugins']);
        $this->assertSame(['g7test-admin'], $excluded['resolution']['selection']['admin_templates']);
        $this->assertSame(
            ['admin_templates:g7test-admin', 'modules:g7test-plain', 'plugins:g7test-plugin_plain'],
            $excluded['resolution']['install_order']
        );
        $this->assertSame(['g7test-needs_fake' => ['g7fakeext'], 'g7test-plugin_needs' => ['g7fakesoap']], $excluded['skipped']);

        // 두 진입점 모두 해석(의존 자동 포함) 뒤, 저장·설치 전에 거른다.
        $root = dirname(__DIR__, 3);
        foreach ([
            'public/install/api/save-extensions.php' => "\$state['selected_extensions'] = \$resolution['selection'];",
            'public/install/includes/cli/install-flow.php' => "return ['selection' => \$resolution['selection'], 'names' => \$names];",
        ] as $path => $storeLine) {
            $source = (string) file_get_contents($root.'/'.$path);
            $resolvePos = strpos($source, 'installer_resolve_extension_selection(');
            $excludePos = strpos($source, 'installer_exclude_selection_missing_php($resolution, BASE_PATH)');
            $this->assertNotFalse($excludePos, $path.': 필수 PHP 확장 재검증 배선 누락');
            $this->assertLessThan($excludePos, $resolvePos, $path.': 해석보다 먼저 거르면 의존 자동 포함이 다시 넣습니다.');
            $this->assertLessThan(strpos($source, $storeLine), $excludePos, $path.': 저장·설치 전에 걸러야 합니다.');
        }
    }

    /**
     * 확장 선택 화면의 스캔 응답이 PHP 확장 판정 필드를 싣고, 화면이 필수 미충족 카드를 끕니다.
     *
     * @scenario surface=installer_extension_selection
     *
     * @effects installer_scan_and_card_disable_wiring
     *
     * @return void 반환값 없음
     */
    public function test_scan_and_card_wiring(): void
    {
        $root = dirname(__DIR__, 3).'/public/install';
        $scan = (string) file_get_contents($root.'/api/scan-extensions.php');
        $this->assertSame(2, substr_count($scan, '...installer_extension_php_requirements('), '모듈·플러그인 스캔 둘 다 판정 필드를 실어야 한다');

        $js = (string) file_get_contents($root.'/assets/js/installer.js');
        $this->assertStringContainsString('if (blockedByPhp) return;', $js, '필수 미충족 카드 클릭 차단 누락');
        $this->assertStringContainsString('if (hasMissingRequiredPhpExtensions(item)) return;', $js, '일괄 선택 제외 누락');
        $this->assertStringContainsString("card.dataset.phpBlocked === '1'", $js, '의존 자동 선택 차단 누락');

        foreach (['ko', 'en'] as $locale) {
            $lang = require $root."/lang/{$locale}.php";
            foreach (['extension_php_missing_required', 'extension_php_missing_suggested', 'log_extension_skipped_php_extension_missing'] as $key) {
                $this->assertArrayHasKey($key, $lang, "{$locale} 인스톨러 문구 {$key} 누락");
            }
        }
    }

    /**
     * 임시 루트에 번들 확장 composer.json 을 씁니다.
     *
     * @param  string  $type  확장 유형 디렉토리 (modules|plugins)
     * @param  string  $identifier  확장 식별자
     * @param  array<string, mixed>  $composer  composer.json 내용
     * @return void 반환값 없음
     */
    private function writeBundled(string $type, string $identifier, array $composer): void
    {
        $dir = $this->installerTempBase."/{$type}/_bundled/{$identifier}";
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/composer.json', json_encode($composer, JSON_UNESCAPED_UNICODE));
    }
}
