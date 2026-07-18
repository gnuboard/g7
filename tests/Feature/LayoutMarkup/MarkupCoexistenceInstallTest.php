<?php

namespace Tests\Feature\LayoutMarkup;

use App\Extension\Traits\ValidatesLayoutFiles;
use App\Support\LayoutMarkup\LayoutProvenance;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `.g7.html` 산출 JSON 공존 provenance 의 설치 통합 테스트 (S7).
 *
 * 설치는 산출 `.json` 을 등록 소스로 읽고(Architecture B), `.g7.html` 은 provenance 표식으로만
 * 짝을 확인한다. 여기서는 `ValidatesLayoutFiles::validateLayoutFiles` 를 임시 디렉토리에 대고
 * 네 가지 경로를 고정한다:
 *
 *  - 표식 일치 → 산출 `.json`(meta.authoring 포함)이 그대로 등록됨 (drift 마커가 DB 로 흐름).
 *  - 표식 없음 → 손저작 중복으로 거부.
 *  - 해시 불일치 → 미재컴파일(stale)로 거부.
 *  - 짝 `.json` 부재 → 미컴파일로 거부.
 *
 * 컴파일러를 직접 부르지 않는다 — 공존 판별은 `.json` 의 표식만 보므로, `.json` 본문은 최소 유효
 * 레이아웃이면 되고 소스는 해시 계산용 문자열이면 된다.
 */
class MarkupCoexistenceInstallTest extends TestCase
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
     * @effects compiled_json_with_authoring_registers_and_keeps_the_drift_marker
     */
    public function test_matching_provenance_registers_the_compiled_json_with_authoring(): void
    {
        $source = "<layout name=\"demo\" version=\"1.0.0\">\n  <div/>\n</layout>\n";
        $this->writeMarkup('demo', $source);
        $this->writeJson('demo', $this->stampedJson('demo', $source));

        $layouts = $this->harness->run($this->tmp, 'gnuboard7-hello_module', 'module');

        $this->assertCount(1, $layouts);
        $this->assertArrayHasKey('authoring', $layouts[0]['data']['meta'],
            '산출 JSON 의 meta.authoring 가 등록 content 에 남아야 편집기 drift 마커가 성립한다');
    }

    /**
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects hand_authored_duplicate_is_rejected
     */
    public function test_json_without_authoring_marker_is_rejected_as_hand_authored_duplicate(): void
    {
        $source = "<layout name=\"demo\" version=\"1.0.0\">\n  <div/>\n</layout>\n";
        $this->writeMarkup('demo', $source);
        // 표식 없는 손저작 .json — 두 SSoT.
        $this->writeJson('demo', ['version' => '1.0.0', 'layout_name' => 'demo', 'components' => []]);

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/not compiled from it/');

        $this->harness->run($this->tmp, 'gnuboard7-hello_module', 'module');
    }

    /**
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects stale_compiled_json_is_rejected
     */
    public function test_json_with_stale_hash_is_rejected(): void
    {
        $source = "<layout name=\"demo\" version=\"1.0.0\">\n  <div/>\n</layout>\n";
        $this->writeMarkup('demo', $source);
        // 표식은 있으나 다른 소스의 해시 — 소스 편집 후 미재컴파일.
        $this->writeJson('demo', $this->stampedJson('demo', $source.'<!-- edited -->'));

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/changed since it was compiled/');

        $this->harness->run($this->tmp, 'gnuboard7-hello_module', 'module');
    }

    /**
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects uncompiled_markup_source_is_rejected
     */
    public function test_markup_without_compiled_json_is_rejected(): void
    {
        $this->writeMarkup('demo', "<layout name=\"demo\" version=\"1.0.0\">\n  <div/>\n</layout>\n");

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/no compiled \.json/');

        $this->harness->run($this->tmp, 'gnuboard7-hello_module', 'module');
    }

    /**
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects reinstall_is_idempotent
     */
    public function test_reinstall_is_idempotent(): void
    {
        $source = "<layout name=\"demo\" version=\"1.0.0\">\n  <div/>\n</layout>\n";
        $this->writeMarkup('demo', $source);
        $this->writeJson('demo', $this->stampedJson('demo', $source));

        $first = $this->harness->run($this->tmp, 'gnuboard7-hello_module', 'module');
        $second = $this->harness->run($this->tmp, 'gnuboard7-hello_module', 'module');

        $this->assertEquals($first, $second, '같은 파일 집합은 재설치해도 동일 결과여야 한다');
    }

    /**
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects keep_strategy_hash_is_source_format_agnostic
     */
    public function test_registered_content_equals_a_plain_json_install(): void
    {
        // `.g7.html` 산출(표식 포함) 을 지운 content 는 손저작 .json 설치 content 와 같아야
        // preserveModified(--layout-strategy=keep) 의 원본 해시 비교가 소스 포맷과 무관하다.
        $source = "<layout name=\"demo\" version=\"1.0.0\">\n  <div/>\n</layout>\n";
        $stamped = $this->stampedJson('demo', $source);

        $this->assertSame(
            ['version' => '1.0.0', 'layout_name' => 'demo', 'components' => []],
            LayoutProvenance::strip($stamped),
        );
    }

    /**
     * hello_module 의 실제 `.g7.html` 데모 레이아웃이 산출 `.json` 에서 등록되는지 실증합니다 (②).
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects hello_extension_markup_layout_installs_from_compiled_json
     */
    public function test_hello_module_markup_demo_layout_installs_from_compiled_json(): void
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

        $this->assertNotNull($demo, '.g7.html 로 저작한 hello 데모 레이아웃이 산출 .json 에서 등록되어야 한다');
        $this->assertSame('Div', $demo['data']['components'][0]['name']);
        $this->assertArrayHasKey('authoring', $demo['data']['meta']);
        $this->assertSame(
            'modules/_bundled/gnuboard7-hello_module/resources/layouts/admin/admin_markup_demo.g7.html',
            $demo['data']['meta']['authoring']['source'],
        );
    }

    /**
     * 최소 유효 레이아웃 JSON 에 provenance 표식을 얹습니다.
     *
     * @param  string  $name  레이아웃 이름
     * @param  string  $source  표식 해시 계산용 소스 문자열
     * @return array<string, mixed> 표식이 얹힌 JSON
     */
    private function stampedJson(string $name, string $source): array
    {
        $json = ['version' => '1.0.0', 'layout_name' => $name, 'components' => []];

        return LayoutProvenance::stamp($json, $source, "tmp/{$name}.g7.html");
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
     * 산출 `.json` 파일을 씁니다.
     *
     * @param  string  $name  파일 베이스 이름
     * @param  array<string, mixed>  $json  JSON 데이터
     */
    private function writeJson(string $name, array $json): void
    {
        File::put("{$this->tmp}/{$name}.json", json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
