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
        $this->assertSame($expected['pulled_by'], $result['pulled_by']);
    }

    /**
     * 언어팩은 그래프 밖에서 코어 범위 팩을 먼저 둔다 — 확장 팩은 같은 로케일 코어 팩이 활성이어야 설치된다(스캔은 디렉터리 이름순).
     *
     * @scenario surface=installer, edge=core_locale_pack
     *
     * @effects installer_language_packs_core_scope_first
     *
     * @return void 반환값 없음
     */
    public function test_language_packs_put_core_scope_first(): void
    {
        $result = installer_resolve_extension_selection(self::fixture()['scan'], [
            'admin_templates' => ['sirsoft-admin_basic'], 'modules' => ['sirsoft-board'], 'language_packs' => ['acme-board-ja', 'g7-core-ja'],
        ]);

        $this->assertSame(['language_packs:g7-core-ja', 'language_packs:acme-board-ja'], array_slice($result['install_order'], -2));
        $this->assertSame(['g7-core-ja', 'acme-board-ja'], $result['selection']['language_packs']);
    }

    /**
     * 종류를 넘는 순환(모듈 → 플러그인 → 모듈)은 `종류:식별자` 전체 경로로 오류를 남긴다 — 사전 검증이 이 문장으로 설치를 멈춘다.
     *
     * @scenario surface=installer, edge=cycle
     *
     * @effects install_order_cross_type_cycle_reported_with_path
     *
     * @return void 반환값 없음
     */
    public function test_cross_type_cycle_reports_full_path(): void
    {
        $result = installer_resolve_extension_selection(self::fixture()['scan'], ['admin_templates' => ['sirsoft-admin_basic'], 'modules' => ['x-cyc_m']]);

        $cycles = array_values(array_filter($result['errors'], fn (array $e): bool => $e['key'] === 'error_extension_dependency_cycle'));
        $this->assertCount(1, $cycles);
        $this->assertSame(['group' => 'extensions', 'cycle' => 'modules:x-cyc_m → plugins:x-cyc_p → modules:x-cyc_m'], $cycles[0]['params']);
        $this->assertSame([], $result['pulled_by']);
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
     * 같은 종류 설치 순서 — 뒤에 고른 의존 대상이 먼저 오고, 연쇄 의존도 풀린다.
     *
     * 설치 작업은 배열 순서를 그대로 따르므로 의존 대상이 뒤에 있으면 의존 검사에서 실패한다(#822 D7).
     *
     * @scenario surface=save_extensions, owner_type=module
     *
     * @effects install_order_dependency_first
     *
     * @return void 반환값 없음
     */
    public function test_install_order_puts_dependencies_first(): void
    {
        $errors = [];
        $this->assertSame(['vendor-board', 'vendor-shop'], self::orderOf('modules', ['vendor-shop', 'vendor-board'], ['vendor-shop' => ['vendor-board'], 'vendor-board' => []], $errors));
        $this->assertSame(
            ['vendor-c', 'vendor-b', 'vendor-a'],
            self::orderOf('modules', ['vendor-a', 'vendor-b', 'vendor-c'], ['vendor-a' => ['vendor-b'], 'vendor-b' => ['vendor-c'], 'vendor-c' => []], $errors)
        );
        $this->assertSame([], $errors);
    }

    /**
     * 의존이 없는 항목끼리는 스캔 순서를 지키고, 선택하지 않은 대상·자기 자신 의존은 무시한다.
     *
     * @scenario surface=save_extensions, owner_type=plugin
     *
     * @effects install_order_stable_for_unrelated
     *
     * @return void 반환값 없음
     */
    public function test_install_order_is_stable_and_ignores_unselected_targets(): void
    {
        $errors = [];
        $this->assertSame(
            ['vendor-c', 'vendor-a', 'vendor-board', 'vendor-shop', 'vendor-b'],
            self::orderOf('plugins', ['vendor-shop', 'vendor-b', 'vendor-board', 'vendor-c', 'vendor-a'], [
                'vendor-c' => [], 'vendor-a' => [], 'vendor-shop' => ['vendor-board'], 'vendor-b' => [], 'vendor-board' => [],
            ], $errors)
        );

        $self = ['vendor-a' => ['vendor-missing', 'vendor-a'], 'vendor-b' => []];
        $this->assertSame(['vendor-a', 'vendor-b'], self::orderOf('plugins', ['vendor-a', 'vendor-b'], $self, $errors));
        $this->assertSame([], self::orderOf('plugins', [], $self, $errors));
        $this->assertSame([], $errors);
    }

    /**
     * 순환 의존이면 기본(스캔) 순서를 그대로 돌려주고 `종류:식별자` 순환 경로를 오류로 남긴다 — 저장 API 는 이 오류로 저장을 막는다.
     *
     * @scenario surface=save_extensions, owner_type=module
     *
     * @effects install_order_cycle_keeps_scan_order_and_reports
     *
     * @return void 반환값 없음
     */
    public function test_install_order_cycle_keeps_scan_order_and_reports(): void
    {
        $errors = [];

        $this->assertSame(
            ['vendor-x', 'vendor-a', 'vendor-b'],
            self::orderOf('modules', ['vendor-b', 'vendor-x', 'vendor-a'], ['vendor-x' => [], 'vendor-a' => ['vendor-b'], 'vendor-b' => ['vendor-a']], $errors)
        );
        $this->assertSame([['key' => 'error_extension_dependency_cycle', 'params' => ['group' => 'extensions', 'cycle' => 'modules:vendor-a → modules:vendor-b → modules:vendor-a']]], $errors);
    }

    /**
     * 저장 API 는 해석기 결과(의존 포함·설치 순서 정렬)를 state 에 저장한다 — 요청 배열을 그대로 저장하지 않는다.
     *
     * save-extensions.php 는 세션·소유권 가드를 거치는 실행 스크립트라 소스 배선을 대조한다.
     *
     * @scenario surface=save_extensions, owner_type=plugin
     *
     * @effects save_extensions_sorts_before_storing
     *
     * @return void 반환값 없음
     */
    public function test_save_api_stores_resolved_selection(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/api/save-extensions.php');

        $this->assertStringContainsString("require_once dirname(__DIR__).'/includes/extension-selection.php';", $source);
        $this->assertStringContainsString("\$state['selected_extensions'] = \$resolution['selection'];", $source);
        $this->assertLessThan(
            strpos($source, "\$state['selected_extensions'] = \$resolution['selection'];"),
            strpos($source, '$resolution = installer_resolve_extension_selection('),
            '해석(정렬)은 state 저장보다 먼저 일어나야 합니다.'
        );
        // Step 5 「…에 필요」 표시용 앞당김 기록도 같은 해석 결과에서 저장한다(#153 D9) — 웹·터미널 같은 키
        $this->assertStringContainsString("\$state['install_pulled_by'] = \$resolution['pulled_by'];", $source);
        $cli = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/includes/cli/install-flow.php');
        $this->assertStringContainsString("\$state['install_pulled_by'] = \$selection['pulled_by'];", $cli);
        $view = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/views/5-installation.php');
        // 서버 값은 비실행 JSON 데이터 블록으로, 전역 대입은 단계 스크립트가 한다(#158 strict CSP)
        $this->assertStringContainsString("'pulled_by' => (object) (\$state['install_pulled_by'] ?? []),", $view);
        $stepScript = (string) file_get_contents(dirname(__DIR__, 3).'/public/install/assets/js/step-5-installation.js');
        $this->assertStringContainsString('window.INSTALLER_PULLED_BY = data.pulled_by || {};', $stepScript);
    }

    /**
     * 필수 PHP 확장이 없어 빠진 확장은 「…에 필요」 기록에서도 빠진다 — 설치하지 않는 항목을 앞당긴 것으로 표시하지 않는다.
     *
     * @scenario surface=installer, edge=module_to_plugin
     *
     * @effects pulled_by_follows_php_extension_exclusion
     *
     * @return void 반환값 없음
     */
    public function test_pulled_by_drops_entries_excluded_for_missing_php_extension(): void
    {
        require_once dirname(__DIR__, 3).'/public/install/includes/php-extension-requirements.php';

        $resolution = installer_resolve_extension_selection(self::fixture()['scan'], ['admin_templates' => ['sirsoft-admin_basic'], 'modules' => ['x-learning']]);
        $this->assertSame(['plugins:sirsoft-ckeditor5' => ['modules:x-learning']], $resolution['pulled_by']);

        // 모듈 x-learning 만 빠졌다고 가정 — 앞당김 근거가 사라지므로 기록도 비어야 한다
        $resolution['selection']['modules'] = [];
        $resolution['install_order'] = array_values(array_diff($resolution['install_order'], ['modules:x-learning']));
        $filtered = installer_exclude_selection_missing_php($resolution, sys_get_temp_dir().'/g7-no-bundled-'.uniqid(), fn (): bool => true)['resolution'];

        $this->assertSame([], $filtered['pulled_by']);
    }

    /**
     * 한 종류만 고른 설치 순서 — 식별자 → 같은 종류 의존 식별자 목록을 스캔 항목으로 펼쳐 `installer_install_order()` 에 넣는다.
     *
     * @param  string  $category  카테고리 (modules·plugins)
     * @param  array<int, string>  $ids  선택 식별자
     * @param  array<string, array<int, string>>  $map  식별자 → 의존 식별자 (키 순서가 스캔 순서)
     * @param  array<int, array{key: string, params: array<string, string>}>  &$errors  오류 목록 (참조)
     * @return array<int, string> 식별자 설치 순서
     */
    private static function orderOf(string $category, array $ids, array $map, array &$errors): array
    {
        $items = [];
        foreach ($map as $id => $deps) {
            $items[$id] = [
                'identifier' => $id,
                'dependencies_detailed' => array_map(fn (string $dep): array => ['type' => $category, 'identifier' => $dep, 'version' => '*'], $deps),
            ];
        }

        $order = installer_install_order([$category => $ids], [$category => $items], [], $errors)['order'];

        return array_map(fn (string $key): string => explode(':', $key, 2)[1], $order);
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
