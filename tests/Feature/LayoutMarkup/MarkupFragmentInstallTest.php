<?php

namespace Tests\Feature\LayoutMarkup;

use App\Extension\Traits\RefreshesLayoutExtensions;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 확장 fragment 저작 파일의 설치 소스 선택 테스트 (S10 정정 아키텍처 — D38·D39, B4).
 *
 * 본 레이아웃과 대칭으로, fragment 도 `.g7.html`(있으면 우선) 을 등록 시점에 메모리 컴파일하고
 * `.json` 은 직접 읽는다. 여기서는 `RefreshesLayoutExtensions::collectFragmentSources` 의 마크업 우선
 * 판별을 고정한다 (컴파일 자체는 본 레이아웃과 동일한 파사드를 태우므로 컴파일러 fixture 로 커버됨).
 */
class MarkupFragmentInstallTest extends TestCase
{
    private object $harness;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        // collectFragmentSources 만 노출하는 최소 하네스 ($layoutExtensionService 는 이 메서드가 쓰지 않음).
        $this->harness = new class
        {
            use RefreshesLayoutExtensions;

            public function sources(string $path, array $jsonFiles): array
            {
                return $this->collectFragmentSources($path, $jsonFiles);
            }
        };

        $this->tmp = base_path('storage/framework/testing/fragment_markup_'.uniqid());
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
     * @effects fragment_markup_wins_over_json_sibling
     */
    public function test_fragment_markup_wins_over_json_sibling(): void
    {
        File::put("{$this->tmp}/frag.g7.html", "<extension target-layout=\"x\"/>\n");
        File::put("{$this->tmp}/frag.json", '{}');

        $sources = $this->harness->sources($this->tmp, ["{$this->tmp}/frag.json"]);

        $this->assertCount(1, $sources, '같은 논리 fragment 는 한 소스만 — 짝 .json 은 건너뜀');
        $this->assertTrue($sources[0]['markup']);
        $this->assertStringEndsWith('frag.g7.html', $sources[0]['path']);
    }

    /**
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fragment_json_used_when_no_markup
     */
    public function test_fragment_json_used_when_no_markup(): void
    {
        File::put("{$this->tmp}/frag.json", '{}');

        $sources = $this->harness->sources($this->tmp, ["{$this->tmp}/frag.json"]);

        $this->assertCount(1, $sources);
        $this->assertFalse($sources[0]['markup']);
        $this->assertStringEndsWith('frag.json', $sources[0]['path']);
    }
}
