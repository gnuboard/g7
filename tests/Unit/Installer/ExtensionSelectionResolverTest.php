<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 설치 확장 선택 해석기(PHP) 검증 — 웹 화면 JS 사본과 같은 픽스처로 같은 결과를 내는지 잠근다.
 *
 * 같은 픽스처(tests/fixtures/installer/extension-resolver-cases.json)를 vitest
 * `installer-extension-resolver.test.ts` 가 JS 해석기로 돌린다. 두 테스트가 모두 초록이면 두 구현이 같다.
 */
class ExtensionSelectionResolverTest extends TestCase
{
    /**
     * 해석기 함수를 로드한다.
     *
     * @return void 반환값 없음
     */
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3).'/public/install/includes/extension-selection.php';
    }

    /**
     * 공용 픽스처를 읽는다.
     *
     * @return array<string, mixed> 픽스처
     */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/installer/extension-resolver-cases.json'), true);
    }

    /**
     * 픽스처의 해석 사례.
     *
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}> 사례 이름 → (요청, 기대값)
     */
    public static function caseProvider(): array
    {
        $cases = [];
        foreach (self::fixture()['cases'] as $case) {
            $cases[$case['name']] = [$case['requested'], $case['expected']];
        }

        return $cases;
    }

    /**
     * 픽스처 사례마다 선택·자동 포함·제외 언어팩·오류 키·설치 순서가 기대값과 같다.
     *
     * @param  array<string, mixed>  $requested  요청 선택
     * @param  array<string, mixed>  $expected  기대 결과
     *
     * @effects resolver_php_matches_shared_fixture
     *
     * @return void 반환값 없음
     */
    #[DataProvider('caseProvider')]
    public function test_resolution_matches_shared_fixture(array $requested, array $expected): void
    {
        $result = installer_resolve_extension_selection(self::fixture()['scan'], $requested);

        $this->assertSame($expected['selection'], $result['selection']);
        $this->assertSame($expected['auto_added'], $result['auto_added']);
        $this->assertSame($expected['excluded_language_packs'], $result['excluded_language_packs']);
        $this->assertSame($expected['error_keys'], array_column($result['errors'], 'key'));
        $this->assertSame($expected['install_order'], $result['install_order']);
    }

    /**
     * 버전 제약 판정이 픽스처 표와 같다.
     *
     * @effects resolver_version_constraint_matches_shared_fixture
     *
     * @return void 반환값 없음
     */
    public function test_version_constraint_table(): void
    {
        foreach (self::fixture()['version_cases'] as [$available, $constraint, $expected]) {
            $this->assertSame($expected, installer_satisfies_version_constraint($available, $constraint), "{$available} vs {$constraint}");
        }
    }

    /**
     * 오류 문장의 확장 이름은 현재 설치 언어를 먼저 쓴다 — 화면 JS(getLocalizedText)와 같은 현재 언어 → ko → en.
     *
     * 종전에는 ko → en 고정이라 영어 설치에서도 서버 오류에 한국어 이름이 나왔다.
     *
     * @effects resolver_error_owner_name_follows_installer_locale
     *
     * @return void 반환값 없음
     */
    public function test_error_owner_name_follows_installer_locale(): void
    {
        $scan = [
            'admin_templates' => [['identifier' => 'tpl-admin', 'name' => 'Admin', 'version' => '1.0.0']],
            'user_templates' => [],
            'modules' => [['identifier' => 'mod-shop', 'name' => ['ko' => '상점', 'en' => 'Shop'], 'version' => '1.0.0']],
            'plugins' => [['identifier' => 'plg-pay', 'name' => ['ko' => '결제', 'en' => 'Pay'], 'version' => '1.0.0',
                'dependencies_detailed' => [['identifier' => 'mod-shop', 'type' => 'modules', 'version' => '>=2.0.0']]]],
            'language_packs' => [],
        ];
        $requested = ['admin_templates' => ['tpl-admin'], 'plugins' => ['plg-pay']];
        $previous = $_SESSION ?? null;

        try {
            $owners = [];
            foreach (['en', 'ko', 'ja'] as $locale) {
                $_SESSION = ['g7_locale' => $locale];
                $errors = installer_resolve_extension_selection($scan, $requested)['errors'];
                $this->assertSame(['error_extension_dependency_version'], array_column($errors, 'key'), $locale);
                $owners[$locale] = $errors[0]['params']['owner'];
            }
        } finally {
            $_SESSION = $previous;
        }

        // ja 처럼 이름이 없는 언어는 ko 로 물러난다
        $this->assertSame(['en' => 'Pay', 'ko' => '결제', 'ja' => '결제'], $owners);
    }

    /**
     * 실제 번들 매니페스트에서 결제 플러그인을 고르면 이커머스가 자동 포함되고 먼저 설치된다.
     *
     * @effects resolver_on_bundled_manifests_adds_ecommerce_for_payment_plugin
     *
     * @return void 반환값 없음
     */
    public function test_bundled_manifests_payment_plugin_pulls_ecommerce(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 3));
        $code = "define('BASE_PATH', '{$root}'); require '{$root}/public/install/includes/extension-selection.php';"
            ."\$r = installer_resolve_extension_selection(installer_scan_extensions(), ['admin_templates' => ['sirsoft-admin_basic'], 'plugins' => ['sirsoft-tosspayments']]);"
            .'echo json_encode([$r["selection"]["modules"], $r["install_order"], array_column($r["errors"], "key")]);';

        // Windows 의 escapeshellarg 는 큰따옴표를 공백으로 바꾸므로 셸을 거치지 않고 인자 배열로 실행한다.
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded, $stdout.$stderr);

        $this->assertSame(['sirsoft-ecommerce'], $decoded[0]);
        $this->assertSame(['admin_templates:sirsoft-admin_basic', 'modules:sirsoft-ecommerce', 'plugins:sirsoft-tosspayments'], $decoded[1]);
        $this->assertSame([], $decoded[2]);
    }
}
