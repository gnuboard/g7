<?php

namespace Tests\Feature\LayoutMarkup;

use App\Console\Commands\GenerateLayoutTestFixture;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `layout:generate-fixture` 가 fixture 를 **소스 SSoT** 에서 굽는다 (39차 신설).
 *
 * ## 왜 이 축이 39차에 생겼나
 *
 * 이 커맨드는 최상위 레이아웃을 `File::get` + `json_decode` 로 직행해 읽고 있었다. 조각 축은
 * `resolveAllPartials()` 로 규약을 타는데(그래서 조각은 소스로 인라인됐다) **문서 루트만
 * `.json` 전용**이라, 소스를 인자로 줄 수도 없고 짝 소스를 우선할 수도 없었다.
 *
 * 이 자리가 어느 래칫에도 잡히지 않은 이유는 정의 때문이다 — `.json` 전용 소비처 판정은
 * "`.json` 을 **하드코딩해** 고르거나 만드는가" 를 신호로 쓰는데, 이 커맨드는 확장자를 인자로
 * 받으므로 그 신호가 코드에 없다. 판정식을 `json_decode(` 까지 넓히면 규약 소유자 넷
 * (`ModuleManager`·`PluginManager`·`TemplateManager`·`ValidatesLayoutFiles`)이 함께 잡히는 것을
 * 실측으로 확인했으므로(과탐 4), 판정을 넓히는 대신 **소비처를 규약으로 옮겨** 닫는다.
 *
 * 결함의 실질: fixture 는 프론트 회귀 테스트의 입력인데, 화면은 소스로 설치되고 fixture 만
 * 낡은 `.json` 에서 나오면 **그 fixture 로 만든 회귀 테스트가 실물과 갈린다** — 그 상태에서
 * 초록은 아무것도 보증하지 않는다.
 *
 * @see GenerateLayoutTestFixture
 */
class LayoutFixtureCommandSourceTest extends TestCase
{
    private string $root;

    private string $layouts;

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = base_path('storage/framework/testing/fixture_cmd_'.uniqid());
        $this->layouts = $this->root.'/layouts';
        $this->out = $this->root.'/out';

        File::ensureDirectoryExists($this->layouts);
        File::ensureDirectoryExists($this->out);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * `.json` 을 인자로 줘도 짝 소스가 있으면 그것이 fixture 의 원본이다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fixture_command_prefers_the_authoring_source
     */
    public function test_fixture_is_baked_from_the_markup_sibling(): void
    {
        $this->writeMarkup('screen', 'FROM_MARKUP');
        $this->writeJson('screen', 'FROM_JSON_TWIN');

        $this->assertSame(
            'FROM_MARKUP',
            $this->bakeAndReadText($this->layouts.'/screen.json'),
            '짝 소스가 있는데 낡은 `.json` 에서 fixture 를 구웠습니다 — 그 fixture 로 만든 회귀 '
            .'테스트는 실제 화면과 다른 것을 검사한다'
        );
    }

    /**
     * 소스를 직접 인자로 줄 수 있다 (`.json` 짝이 없어도 된다).
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fixture_command_accepts_a_markup_path_argument
     */
    public function test_markup_path_argument_is_accepted(): void
    {
        $this->writeMarkup('markup_only', 'MARKUP_ONLY');

        $this->assertSame(
            'MARKUP_ONLY',
            $this->bakeAndReadText($this->layouts.'/markup_only.g7.html', 'markup_only'),
            '소스 경로를 인자로 받지 못했습니다 — 마크업 전용 레이아웃은 fixture 를 만들 수 없다'
        );
    }

    /**
     * 짝이 없는 `.json` 은 그대로 읽힌다 (기존 사용법 회귀).
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fixture_command_still_reads_json_only_layouts
     */
    public function test_json_only_layout_still_works(): void
    {
        $this->writeJson('json_only', 'JSON_ONLY');

        $this->assertSame(
            'JSON_ONLY',
            $this->bakeAndReadText($this->layouts.'/json_only.json'),
            '짝이 없는 `.json` 레이아웃이 더 이상 읽히지 않습니다 — 규약 편입이 기존 사용법을 깼다'
        );
    }

    /**
     * 커맨드를 실행하고 구워진 fixture 의 첫 노드 텍스트를 돌려줍니다.
     *
     * @param  string  $layoutPath  커맨드 인자로 줄 경로
     * @param  string|null  $expectFile  출력 파일명(확장자 제외). 기본은 레이아웃 이름
     * @return string|null 첫 노드의 `text`
     */
    private function bakeAndReadText(string $layoutPath, ?string $expectFile = null): ?string
    {
        $this->artisan('layout:generate-fixture', [
            'layout' => $layoutPath,
            '--dir' => $this->out,
        ])->assertSuccessful();

        $name = $expectFile ?? pathinfo($layoutPath, PATHINFO_FILENAME);
        $baked = $this->out.'/'.$name.'.json';

        $this->assertFileExists($baked, "fixture 가 생성되지 않았습니다: {$baked}");

        $data = json_decode((string) File::get($baked), true);

        return $data['components'][0]['props']['text'] ?? null;
    }

    /**
     * 소스 레이아웃을 씁니다.
     *
     * @param  string  $name  파일 이름(확장자 제외)
     * @param  string  $text  첫 노드 텍스트
     */
    private function writeMarkup(string $name, string $text): void
    {
        File::put($this->layouts.'/'.$name.'.g7.html',
            "<layout name=\"{$name}\" version=\"1.0.0\">\n  <span text=\"{$text}\"/>\n</layout>\n");
    }

    /**
     * 짝 `.json` 레이아웃을 씁니다.
     *
     * @param  string  $name  파일 이름(확장자 제외)
     * @param  string  $text  첫 노드 텍스트
     */
    private function writeJson(string $name, string $text): void
    {
        File::put($this->layouts.'/'.$name.'.json', json_encode([
            'layout_name' => $name,
            'components' => [
                ['type' => 'basic', 'name' => 'Span', 'props' => ['text' => $text]],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
