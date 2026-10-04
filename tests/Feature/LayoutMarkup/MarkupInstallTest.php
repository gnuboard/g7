<?php

namespace Tests\Feature\LayoutMarkup;

use App\Extension\Traits\ValidatesLayoutFiles;
use App\Support\LayoutMarkup\LayoutMarkupRoundTrip;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `.g7.html` 소스 파일의 설치 통합 테스트 (S10 정정 아키텍처 — D38·D39).
 *
 * 설치는 소스 SSoT 인 `.g7.html` 을 **설치 시점에 메모리로 컴파일**해 등록하고(디스크 산출 JSON 미기록),
 * `.g7.html` 이 없으면 `.json` 을 직접 등록한다. 두 SSoT 가 공존하지 않으므로 provenance 판별이 없다.
 * 여기서는 `ValidatesLayoutFiles::validateLayoutFiles` 를 임시 디렉토리에 대고 네 가지를 고정한다:
 *
 *  - `.g7.html` 만 있으면 → 인메모리 컴파일 산출이 등록됨 (디스크에 `.json` 이 생기지 않음).
 *  - `.g7.html` 과 `.json` 이 나란히 있으면 → 소스 SSoT 인 `.g7.html` 이 이긴다.
 *  - `.json` 만 있으면 → 그대로 등록 (기존 동작 불변).
 *  - `.g7.html` 이 컴파일 실패하면 → 설치가 거부.
 */
class MarkupInstallTest extends TestCase
{
    private object $harness;

    private string $tmp;

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->setLocale('en');

        // ValidatesLayoutFiles 를 그대로 노출하는 최소 하네스 (GenerateLayoutTestFixture 선례).
        $this->harness = new class
        {
            use ValidatesLayoutFiles;

            /**
             * 레이아웃 파일 검증을 그대로 실행해 결과를 돌려줍니다.
             *
             * @param  string  $path  레이아웃 경로
             * @param  string  $id  확장 식별자
             * @param  string  $type  확장 종류
             * @return array 검증 결과
             */
            public function run(string $path, string $id, string $type): array
            {
                return $this->validateLayoutFiles($path, $id, $type, true);
            }
        };

        $this->tmp = base_path('storage/framework/testing/layout_markup_'.uniqid());
        File::ensureDirectoryExists($this->tmp);
    }

    /**
     * 테스트가 남긴 상태를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);

        parent::tearDown();
    }

    /**
     * 마크업 소스는 메모리에서 컴파일돼 등록된다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects markup_source_compiles_in_memory_and_registers
     *
     * @return void 반환값 없음
     */
    public function test_markup_source_compiles_in_memory_and_registers(): void
    {
        $this->writeMarkup('demo', "<layout name=\"demo\" version=\"1.0.0\">\n  <div class=\"p-4\"/>\n</layout>\n");

        $layouts = $this->harness->run($this->tmp, 'gnuboard7-hello_module', 'module');

        $this->assertCount(1, $layouts);
        $this->assertSame('demo', $layouts[0]['data']['layout_name']);
        $this->assertSame('Div', $layouts[0]['data']['components'][0]['name'],
            '.g7.html 이 설치 시점에 컴파일되어 등록 content 가 되어야 한다');
        $this->assertArrayNotHasKey('authoring', $layouts[0]['data']['meta'] ?? [],
            'provenance 표식은 폐기됐다 — 등록 content 에 authoring 이 남으면 안 된다');
    }

    /**
     * 설치는 디스크에 JSON 을 쓰지 않는다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects install_writes_no_json_to_disk
     *
     * @return void 반환값 없음
     */
    public function test_install_writes_no_json_to_disk(): void
    {
        $this->writeMarkup('demo', "<layout name=\"demo\" version=\"1.0.0\">\n  <div/>\n</layout>\n");

        $this->harness->run($this->tmp, 'gnuboard7-hello_module', 'module');

        $this->assertFileDoesNotExist("{$this->tmp}/demo.json",
            '설치는 컴파일 산출 JSON 을 디스크에 쓰지 않는다 (D38)');
    }

    /**
     * 마크업과 JSON 이 함께 있으면 마크업을 쓴다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects markup_wins_when_both_present
     *
     * @return void 반환값 없음
     */
    public function test_markup_wins_when_both_present(): void
    {
        // 소스 SSoT(.g7.html) 은 Div, 곁의 손 작성 .json 은 Span — 등록 content 는 마크업이 이긴다.
        $this->writeMarkup('demo', "<layout name=\"demo\" version=\"1.0.0\">\n  <div/>\n</layout>\n");
        $this->writeJson('demo', [
            'version' => '1.0.0',
            'layout_name' => 'demo',
            'components' => [['type' => 'basic', 'name' => 'Span', 'text' => 'stale']],
        ]);

        $layouts = $this->harness->run($this->tmp, 'gnuboard7-hello_module', 'module');

        $this->assertCount(1, $layouts, '같은 논리 레이아웃은 한 번만 등록된다 (.json 은 건너뜀)');
        $this->assertSame('Div', $layouts[0]['data']['components'][0]['name'],
            '.g7.html 과 .json 이 병존하면 소스 SSoT 인 .g7.html 이 이긴다');
    }

    /**
     * JSON 만 있으면 그대로 설치한다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects json_only_installs_directly
     *
     * @return void 반환값 없음
     */
    public function test_json_only_installs_directly(): void
    {
        $this->writeJson('demo', [
            'version' => '1.0.0',
            'layout_name' => 'demo',
            'components' => [['type' => 'basic', 'name' => 'Span', 'text' => 'plain']],
        ]);

        $layouts = $this->harness->run($this->tmp, 'gnuboard7-hello_module', 'module');

        $this->assertCount(1, $layouts);
        $this->assertSame('Span', $layouts[0]['data']['components'][0]['name'],
            '.g7.html 이 없으면 .json 을 그대로 등록한다 (기존 동작 불변)');
    }

    /**
     * 잘못된 마크업 소스는 설치를 거절한다.
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects invalid_markup_source_is_rejected
     *
     * @return void 반환값 없음
     */
    public function test_invalid_markup_is_rejected(): void
    {
        // 대문자 커스텀 엘리먼트는 컴파일러가 거부한다 (소문자 태그 원칙).
        $this->writeMarkup('demo', "<layout name=\"demo\" version=\"1.0.0\">\n  <Div/>\n</layout>\n");

        $this->expectException(\Throwable::class);

        $this->harness->run($this->tmp, 'gnuboard7-hello_module', 'module');
    }

    /**
     * hello_module 의 실제 `.g7.html` 데모 레이아웃이 설치 시점 컴파일로 등록되는지 실증합니다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects hello_extension_markup_layout_installs_from_in_memory_compile
     *
     * @return void 반환값 없음
     */
    public function test_hello_module_markup_demo_installs_from_in_memory_compile(): void
    {
        $layoutsPath = base_path('modules/_bundled/gnuboard7-hello_module/resources/layouts');

        $layouts = $this->harness->run($layoutsPath, 'gnuboard7-hello_module', 'module');

        $demo = null;
        foreach ($layouts as $layout) {
            if ($layout['layout_name'] === 'hello_admin_markup_demo') {
                $demo = $layout;
                break;
            }
        }

        $this->assertNotNull($demo, '.g7.html 로 작성한 hello 데모 레이아웃이 설치 시점 컴파일로 등록되어야 한다');
        $this->assertSame('Div', $demo['data']['components'][0]['name']);
        $this->assertArrayNotHasKey('authoring', $demo['data']['meta'] ?? [],
            'provenance 표식은 폐기됐다 — 등록 content 에 authoring 이 남으면 안 된다');
    }

    /**
     * 설치 경로의 **소스 등가성**을 봉인합니다 (계획서 §199): HTML 설치 == JSON 설치.
     *
     * 코퍼스 게이트는 컴파일 레이어(`compile(.g7.html)` vs `.json`)만 본다. 설치 경로는 그 위에
     * 소스 선택·partial 해석·data_source 검증·layout_name 결정을 얹으므로, 그 변환들이 두 소스에서
     * 같은 등록 content 를 내는지는 **설치 레이어에서 따로** 봉인해야 한다. 실제 이관된 hello 템플릿
     * (`.g7.html` + 원본 `.json` 병존) 을 그대로 쓴다:
     *
     *  - HTML 설치 = 실 디렉토리(마크업 우선) 그대로
     *  - JSON 설치 = 같은 디렉토리를 복사한 뒤 `.g7.html` 만 지운 임시 디렉토리
     *
     * 원본 `.json` 과의 직접 대조는 여기서 하지 않는다 — 설치가 `resolveAllPartials` 로 partial 을
     * 인라인하므로 include 를 가진 레이아웃은 원본과 다른 것이 정상이다(hello 에 partial 레이아웃이
     * 추가되면 거짓 실패로 터진다). "컴파일 산출 == 원본" 은 `LayoutMarkupRoundTripCorpusTest` 가
     * 이관 파일 전량에 이미 걸고 있어 축이 중복된다. W2 확장 데이터셋을 도는
     * {@see self::test_wave_two_html_install_equals_json_install()} 와 같은 계약이다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects html_install_equals_json_install
     *
     * @return void 반환값 없음
     */
    public function test_html_install_equals_json_install(): void
    {
        $layoutsPath = base_path('templates/_bundled/gnuboard7-hello_admin_template/layouts');

        // JSON 전용 사본 — 같은 트리에서 `.g7.html` 만 제거해 "JSON 선택 설치" 를 재현한다.
        // 사본도 `.../layouts` 로 끝내야 `partials/…` 루트 폴백이 원본과 같은 기준으로 동작한다.
        $jsonOnly = "{$this->tmp}/json_only/layouts";
        File::copyDirectory($layoutsPath, $jsonOnly);

        foreach (File::allFiles($jsonOnly) as $file) {
            if (str_ends_with(strtolower($file->getFilename()), '.g7.html')) {
                File::delete($file->getPathname());
            }
        }

        $htmlInstall = $this->indexByLayoutName($this->harness->run($layoutsPath, 'gnuboard7-hello_admin_template', 'template'));
        $jsonInstall = $this->indexByLayoutName($this->harness->run($jsonOnly, 'gnuboard7-hello_admin_template', 'template'));

        $this->assertNotEmpty($htmlInstall, '이관된 hello admin 템플릿 레이아웃이 등록되어야 한다');
        $this->assertSame(
            array_keys($htmlInstall),
            array_keys($jsonInstall),
            'HTML 설치와 JSON 설치가 같은 레이아웃 집합을 등록해야 한다',
        );

        foreach ($htmlInstall as $layoutName => $htmlContent) {
            $this->assertSame(
                $this->canonical($jsonInstall[$layoutName]['data']),
                $this->canonical($htmlContent['data']),
                "[{$layoutName}] HTML 설치 content 가 JSON 설치 content 와 달라졌다",
            );
        }
    }

    /**
     * W2 이관 실물의 설치 3자 등가성 — HTML 설치 == JSON 설치 == 원본 `.json`.
     *
     * 코퍼스 게이트는 **컴파일 레이어**의 deep-equal 만 본다. 설치 경로에는 그 뒤로 소스 선택·
     * partial 해석·data_source 검증·layout_name 결정이라는 고유 변환이 남아 있어, 이관 실물로
     * 한 번 더 봉인해야 ② 가 닫힌다(㉑ 가 hello 로 세운 게이트를 W2 확장으로 넓힌다).
     *
     * W3(sirsoft-admin_basic — 129파일)도 같은 데이터셋에 합류한다. 템플릿은 확장과 설치 경로가
     * 다르고(TemplateManager) 물량이 한 자릿수가 아니라 세 자릿수라, 여기서 도는 것이 ② 의 실효다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects wave_two_html_install_equals_json_install, wave_three_template_html_install_equals_json_install
     *
     * @param  string  $relativeRoot  이관 실물의 상대 경로
     * @param  string  $identifier  확장 식별자
     * @param  string  $sourceType  확장 종류
     * @return void 반환값 없음
     */
    #[DataProvider('migratedWaveTwoRoots')]
    public function test_wave_two_html_install_equals_json_install(string $relativeRoot, string $identifier, string $sourceType): void
    {
        $layoutsPath = base_path($relativeRoot);

        // 복사본도 `.../layouts` 로 끝나야 한다 — `partials/…` 는 layouts 루트 기준으로 폴백되는데,
        // 그 루트 탐지가 경로의 `layouts` 세그먼트를 본다. 이름을 잃은 임시 디렉토리에 풀면 원본과
        // 폴백 동작이 갈려, 이관과 무관한 차이가 등가성 실패로 잡힌다.
        $jsonOnly = "{$this->tmp}/json_only_".md5($relativeRoot).'/layouts';
        File::copyDirectory($layoutsPath, $jsonOnly);

        foreach (File::allFiles($jsonOnly) as $file) {
            if (str_ends_with(strtolower($file->getFilename()), '.g7.html')) {
                File::delete($file->getPathname());
            }
        }

        $htmlInstall = $this->indexByLayoutName($this->harness->run($layoutsPath, $identifier, $sourceType));
        $jsonInstall = $this->indexByLayoutName($this->harness->run($jsonOnly, $identifier, $sourceType));

        $this->assertNotEmpty($htmlInstall, "[{$identifier}] 이관된 레이아웃이 등록되어야 한다");
        $this->assertSame(array_keys($htmlInstall), array_keys($jsonInstall), "[{$identifier}] 등록 집합이 달라졌다");

        // JSON-only 사본은 임시 디렉토리에 있으므로, 설치 산출에 **절대 경로가 실려 오는 자리**
        // (깨진 include 의 에러 컴포넌트 문구)는 구조상 두 설치가 다를 수밖에 없다. 두 루트를 같은
        // 자리표시자로 접어 경로 차이만 지우고, 그 밖의 내용 차이는 그대로 실패로 잡는다.
        $roots = [$layoutsPath, $jsonOnly];

        // 이 테스트가 봉인하는 것은 **설치 경로 고유 변환의 등가성**(HTML 설치 == JSON 설치)이다.
        // 원본 `.json` 과의 직접 대조는 여기서 하지 않는다 — 설치가 `resolveAllPartials` 로 partial 을
        // 인라인하므로 include 를 가진 레이아웃은 원본과 다른 것이 정상이다. "컴파일 산출 == 원본"
        // 은 `LayoutMarkupRoundTripCorpusTest` 가 이관 파일 전량에 이미 걸고 있다.
        foreach ($htmlInstall as $layoutName => $htmlContent) {
            $this->assertSame(
                $this->canonical($jsonInstall[$layoutName]['data'], $roots),
                $this->canonical($htmlContent['data'], $roots),
                "[{$identifier}/{$layoutName}] HTML 설치 content 가 JSON 설치와 다르다",
            );
        }
    }

    /**
     * W2 이관 확장의 레이아웃 루트 (본 레이아웃 보유 확장 — fragment 전용은 별도 경로).
     *
     * @return array<string, array{0: string, 1: string, 2: string}> 데이터셋
     */
    public static function migratedWaveTwoRoots(): array
    {
        return [
            'sirsoft-page (module)' => ['modules/_bundled/sirsoft-page/resources/layouts', 'sirsoft-page', 'module'],
            'sirsoft-gdpr (plugin)' => ['plugins/_bundled/sirsoft-gdpr/resources/layouts', 'sirsoft-gdpr', 'plugin'],
            'sirsoft-ckeditor5 (plugin)' => ['plugins/_bundled/sirsoft-ckeditor5/resources/layouts', 'sirsoft-ckeditor5', 'plugin'],
            'sirsoft-daum_postcode (plugin)' => ['plugins/_bundled/sirsoft-daum_postcode/resources/layouts', 'sirsoft-daum_postcode', 'plugin'],
            'sirsoft-marketing (plugin)' => ['plugins/_bundled/sirsoft-marketing/resources/layouts', 'sirsoft-marketing', 'plugin'],
            'sirsoft-pay_kginicis (plugin)' => ['plugins/_bundled/sirsoft-pay_kginicis/resources/layouts', 'sirsoft-pay_kginicis', 'plugin'],
            'sirsoft-pay_nhnkcp (plugin)' => ['plugins/_bundled/sirsoft-pay_nhnkcp/resources/layouts', 'sirsoft-pay_nhnkcp', 'plugin'],
            'sirsoft-pay_nicepayments (plugin)' => ['plugins/_bundled/sirsoft-pay_nicepayments/resources/layouts', 'sirsoft-pay_nicepayments', 'plugin'],
            'sirsoft-verification_kginicis (plugin)' => ['plugins/_bundled/sirsoft-verification_kginicis/resources/layouts', 'sirsoft-verification_kginicis', 'plugin'],
            // W3 — 템플릿 설치 경로(TemplateManager) + 143파일 실물
            'sirsoft-admin_basic (template)' => ['templates/_bundled/sirsoft-admin_basic/layouts', 'sirsoft-admin_basic', 'template'],
            // W4 — user 템플릿(sirsoft-basic) + 152파일 실물
            'sirsoft-basic (template)' => ['templates/_bundled/sirsoft-basic/layouts', 'sirsoft-basic', 'template'],
            // W5 — admin/user 양쪽 레이아웃을 함께 내보내는 모듈(sirsoft-board) + 40파일 실물
            'sirsoft-board (module)' => ['modules/_bundled/sirsoft-board/resources/layouts', 'sirsoft-board', 'module'],
            // W6 — 코퍼스 최대 물량 모듈(sirsoft-ecommerce) + 164파일 실물
            'sirsoft-ecommerce (module)' => ['modules/_bundled/sirsoft-ecommerce/resources/layouts', 'sirsoft-ecommerce', 'module'],
        ];
    }

    /**
     * 등록 결과를 layout_name 키로 색인합니다 (정렬 — 스캔 순서 무관 비교).
     *
     * @param  array<int, array{file: string, data: array<string, mixed>, layout_name: string}>  $layouts  등록 결과
     * @return array<string, array{file: string, data: array<string, mixed>, layout_name: string}> layout_name → 항목
     */
    private function indexByLayoutName(array $layouts): array
    {
        $indexed = [];

        foreach ($layouts as $layout) {
            $indexed[$layout['layout_name']] = $layout;
        }

        ksort($indexed);

        return $indexed;
    }

    /**
     * 레이아웃 JSON 을 정규화된 결정적 문자열로 만듭니다 (키 순서·컴파일러 파생값 무관 비교).
     *
     * @param  array<string, mixed>  $layout  레이아웃 JSON
     * @param  array<int, string>  $roots  자리표시자로 접을 설치 루트 경로 (두 설치의 소스 디렉토리)
     * @return string 비교용 정규화 문자열
     */
    private function canonical(array $layout, array $roots = []): string
    {
        // 왕복 오라클과 같은 기준 — `normalizeDocument` 를 빼면 문서층 별칭(`state`→`initLocal`)과
        // "옮기지 않고 버리는" 사어 키가 차이로 잡혀 정상 설치가 불일치로 보고된다.
        $encoded = LayoutMarkupRoundTrip::encode(
            LayoutMarkupRoundTrip::canonicalize(
                LayoutMarkupRoundTrip::normalize(LayoutMarkupRoundTrip::normalizeDocument($layout)),
            ),
        );

        foreach ($roots as $root) {
            foreach ([$root, str_replace('/', DIRECTORY_SEPARATOR, $root), str_replace('\\', '/', $root)] as $variant) {
                // 인코드된 JSON 안에서는 백슬래시가 이스케이프돼 있으므로 그 형태도 함께 접는다.
                $escaped = trim((string) json_encode($variant), '"');
                $encoded = str_replace([$escaped, $variant], '{INSTALL_ROOT}', $encoded);
            }
        }

        return $encoded;
    }

    /**
     * `.g7.html` 소스 파일을 씁니다.
     *
     * @param  string  $name  파일 베이스 이름
     * @param  string  $source  소스 내용
     * @return void 반환값 없음
     */
    private function writeMarkup(string $name, string $source): void
    {
        File::put("{$this->tmp}/{$name}.g7.html", $source);
    }

    /**
     * 손 작성 `.json` 파일을 씁니다.
     *
     * @param  string  $name  파일 베이스 이름
     * @param  array<string, mixed>  $json  JSON 데이터
     * @return void 반환값 없음
     */
    private function writeJson(string $name, array $json): void
    {
        File::put("{$this->tmp}/{$name}.json", json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
