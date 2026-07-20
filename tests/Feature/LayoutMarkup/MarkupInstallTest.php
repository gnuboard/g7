<?php

namespace Tests\Feature\LayoutMarkup;

use App\Extension\Traits\ValidatesLayoutFiles;
use App\Support\LayoutMarkup\LayoutMarkupRoundTrip;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `.g7.html` 저작 파일의 설치 통합 테스트 (S10 정정 아키텍처 — D38·D39).
 *
 * 설치는 저작 SSoT 인 `.g7.html` 을 **설치 시점에 메모리로 컴파일**해 등록하고(디스크 산출 JSON 미기록),
 * `.g7.html` 이 없으면 `.json` 을 직접 등록한다. 두 SSoT 가 공존하지 않으므로 provenance 판별이 없다.
 * 여기서는 `ValidatesLayoutFiles::validateLayoutFiles` 를 임시 디렉토리에 대고 네 가지를 고정한다:
 *
 *  - `.g7.html` 만 있으면 → 인메모리 컴파일 산출이 등록됨 (디스크에 `.json` 이 생기지 않음).
 *  - `.g7.html` 과 `.json` 이 나란히 있으면 → 저작 SSoT 인 `.g7.html` 이 이긴다.
 *  - `.json` 만 있으면 → 그대로 등록 (기존 동작 불변).
 *  - `.g7.html` 이 컴파일 실패하면 → 설치가 거부.
 */
class MarkupInstallTest extends TestCase
{
    private object $harness;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->setLocale('en');

        // ValidatesLayoutFiles 를 그대로 노출하는 최소 하네스 (GenerateLayoutTestFixture 선례).
        $this->harness = new class
        {
            use ValidatesLayoutFiles;

            public function run(string $path, string $id, string $type): array
            {
                return $this->validateLayoutFiles($path, $id, $type, true);
            }
        };

        $this->tmp = base_path('storage/framework/testing/layout_markup_'.uniqid());
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);

        parent::tearDown();
    }

    /**
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects markup_source_compiles_in_memory_and_registers
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
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects install_writes_no_json_to_disk
     */
    public function test_install_writes_no_json_to_disk(): void
    {
        $this->writeMarkup('demo', "<layout name=\"demo\" version=\"1.0.0\">\n  <div/>\n</layout>\n");

        $this->harness->run($this->tmp, 'gnuboard7-hello_module', 'module');

        $this->assertFileDoesNotExist("{$this->tmp}/demo.json",
            '설치는 컴파일 산출 JSON 을 디스크에 쓰지 않는다 (D38)');
    }

    /**
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects markup_wins_when_both_present
     */
    public function test_markup_wins_when_both_present(): void
    {
        // 저작 SSoT(.g7.html) 은 Div, 곁의 손저작 .json 은 Span — 등록 content 는 마크업이 이긴다.
        $this->writeMarkup('demo', "<layout name=\"demo\" version=\"1.0.0\">\n  <div/>\n</layout>\n");
        $this->writeJson('demo', [
            'version' => '1.0.0',
            'layout_name' => 'demo',
            'components' => [['type' => 'basic', 'name' => 'Span', 'text' => 'stale']],
        ]);

        $layouts = $this->harness->run($this->tmp, 'gnuboard7-hello_module', 'module');

        $this->assertCount(1, $layouts, '같은 논리 레이아웃은 한 번만 등록된다 (.json 은 건너뜀)');
        $this->assertSame('Div', $layouts[0]['data']['components'][0]['name'],
            '.g7.html 과 .json 이 병존하면 저작 SSoT 인 .g7.html 이 이긴다');
    }

    /**
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects json_only_installs_directly
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
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects invalid_markup_source_is_rejected
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

        $this->assertNotNull($demo, '.g7.html 로 저작한 hello 데모 레이아웃이 설치 시점 컴파일로 등록되어야 한다');
        $this->assertSame('Div', $demo['data']['components'][0]['name']);
        $this->assertArrayNotHasKey('authoring', $demo['data']['meta'] ?? [],
            'provenance 표식은 폐기됐다 — 등록 content 에 authoring 이 남으면 안 된다');
    }

    /**
     * 설치 경로의 **3자 등가성**을 봉인합니다 (계획서 §199): HTML 설치 == JSON 설치 == 원본 `.json`.
     *
     * 코퍼스 게이트는 컴파일 레이어(`compile(.g7.html)` vs `.json`)만 본다. 설치 경로는 그 위에
     * 소스 선택·partial 해석·data_source 검증·layout_name 결정을 얹으므로, 그 변환들이 두 소스에서
     * 같은 등록 content 를 내는지는 **설치 레이어에서 따로** 봉인해야 한다. 실제 이관된 hello 템플릿
     * (`.g7.html` + 원본 `.json` 병존) 을 그대로 쓴다:
     *
     *  - HTML 설치 = 실 디렉토리(마크업 우선) 그대로
     *  - JSON 설치 = 같은 디렉토리를 복사한 뒤 `.g7.html` 만 지운 임시 디렉토리
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects html_install_equals_json_install_equals_original_json
     */
    public function test_html_install_equals_json_install_equals_original_json(): void
    {
        $layoutsPath = base_path('templates/_bundled/gnuboard7-hello_admin_template/layouts');

        // JSON 전용 사본 — 같은 트리에서 `.g7.html` 만 제거해 "JSON 선택 설치" 를 재현한다.
        $jsonOnly = "{$this->tmp}/json_only";
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
            $originalJson = json_decode(File::get($jsonInstall[$layoutName]['file']), true);

            $html = $this->canonical($htmlContent['data']);
            $json = $this->canonical($jsonInstall[$layoutName]['data']);
            $origin = $this->canonical($originalJson);

            $this->assertSame($json, $html, "[{$layoutName}] HTML 설치 content 가 JSON 설치 content 와 달라졌다");
            $this->assertSame($origin, $html, "[{$layoutName}] HTML 설치 content 가 원본 .json 과 달라졌다");
        }
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
     * @return string 비교용 정규화 문자열
     */
    private function canonical(array $layout): string
    {
        return LayoutMarkupRoundTrip::encode(
            LayoutMarkupRoundTrip::canonicalize(LayoutMarkupRoundTrip::normalize($layout)),
        );
    }

    /**
     * `.g7.html` 소스 파일을 씁니다.
     *
     * @param  string  $name  파일 베이스 이름
     * @param  string  $source  소스 내용
     */
    private function writeMarkup(string $name, string $source): void
    {
        File::put("{$this->tmp}/{$name}.g7.html", $source);
    }

    /**
     * 손저작 `.json` 파일을 씁니다.
     *
     * @param  string  $name  파일 베이스 이름
     * @param  array<string, mixed>  $json  JSON 데이터
     */
    private function writeJson(string $name, array $json): void
    {
        File::put("{$this->tmp}/{$name}.json", json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
