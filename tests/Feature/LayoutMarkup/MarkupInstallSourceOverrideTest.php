<?php

namespace Tests\Feature\LayoutMarkup;

use App\Extension\Traits\ValidatesLayoutFiles;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 레이아웃 설치 소스 강제(`--layout-source=json`) 테스트 (계획서 §195).
 *
 * 기본 `auto` 는 소스 SSoT 인 `.g7.html` 을 우선한다. `json` 은 진단·복구용 탈출구로, 컴파일러
 * 회귀가 의심될 때 원본 `.json` 기준으로 되돌려 설치하기 위한 것이다. 여기서 세 가지를 고정한다:
 *
 *  - `json` 강제 + 병존 → `.json` 이 이긴다 (기본 우선순위가 뒤집힌다).
 *  - `json` 강제 + 마크업 전용(짝 `.json` 없음) → **누락시키지 않고** 마크업을 그대로 컴파일한다.
 *  - 알 수 없는 값 → `auto` 로 안전하게 떨어진다.
 *
 * 옵션명이 `--source` 가 아닌 이유는 `{type}:update` 가 이미 `--source=auto|bundled|github`
 * (업데이트 패키지 출처)를 점유하고 있기 때문이다.
 */
class MarkupInstallSourceOverrideTest extends TestCase
{
    private object $harness;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->setLocale('en');

        $this->harness = new class
        {
            use ValidatesLayoutFiles;

            public function run(string $path, string $id, string $type): array
            {
                return $this->validateLayoutFiles($path, $id, $type, true);
            }
        };

        $this->tmp = base_path('storage/framework/testing/layout_source_'.uniqid());
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
     * @effects json_forced_source_overrides_markup_priority
     */
    public function test_json_forced_source_overrides_markup_priority(): void
    {
        $this->writeMarkup('demo', "<layout name=\"demo\" version=\"1.0.0\">\n  <div/>\n</layout>\n");
        $this->writeJson('demo', [
            'version' => '1.0.0',
            'layout_name' => 'demo',
            'components' => [['type' => 'basic', 'name' => 'Span', 'text' => 'original']],
        ]);

        // 기본(auto) — 마크업이 이긴다.
        $auto = $this->harness->run($this->tmp, 'gnuboard7-hello_module', 'module');
        $this->assertSame('Div', $auto[0]['data']['components'][0]['name']);

        // json 강제 — 원본 .json 이 이긴다.
        config(['template.layout_markup.install_source' => 'json']);

        $forced = $this->harness->run($this->tmp, 'gnuboard7-hello_module', 'module');

        $this->assertCount(1, $forced, '같은 논리 레이아웃은 여전히 한 번만 등록된다');
        $this->assertSame('Span', $forced[0]['data']['components'][0]['name'],
            '--layout-source=json 이면 짝 .json 이 .g7.html 을 이긴다');
    }

    /**
     * 짝 `.json` 이 없는 마크업 전용 레이아웃은 JSON 강제에서도 **누락되지 않는다**.
     *
     * 조용히 건너뛰면 등록 자체가 사라져 빈 화면이 된다 — 대안이 없으면 마크업을 그대로 컴파일한다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects json_forced_source_keeps_markup_only_layouts
     */
    public function test_json_forced_source_keeps_markup_only_layouts(): void
    {
        $this->writeMarkup('orphan', "<layout name=\"orphan\" version=\"1.0.0\">\n  <div/>\n</layout>\n");

        config(['template.layout_markup.install_source' => 'json']);

        $layouts = $this->harness->run($this->tmp, 'gnuboard7-hello_module', 'module');

        $this->assertCount(1, $layouts,
            '짝 .json 이 없으면 JSON 강제라도 마크업 전용 레이아웃을 누락시키지 않는다');
        $this->assertSame('Div', $layouts[0]['data']['components'][0]['name']);
    }

    /**
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects unknown_install_source_falls_back_to_auto
     */
    public function test_unknown_install_source_falls_back_to_auto(): void
    {
        $this->writeMarkup('demo', "<layout name=\"demo\" version=\"1.0.0\">\n  <div/>\n</layout>\n");
        $this->writeJson('demo', [
            'version' => '1.0.0',
            'layout_name' => 'demo',
            'components' => [['type' => 'basic', 'name' => 'Span', 'text' => 'original']],
        ]);

        config(['template.layout_markup.install_source' => 'nonsense']);

        $layouts = $this->harness->run($this->tmp, 'gnuboard7-hello_module', 'module');

        $this->assertSame('Div', $layouts[0]['data']['components'][0]['name'],
            '알 수 없는 설치 소스 값은 기본 auto 로 떨어진다 (마크업 우선)');
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
     * 손 작성 `.json` 파일을 씁니다.
     *
     * @param  string  $name  파일 베이스 이름
     * @param  array<string, mixed>  $json  JSON 데이터
     */
    private function writeJson(string $name, array $json): void
    {
        File::put("{$this->tmp}/{$name}.json", json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
