<?php

namespace Tests\Feature\LayoutMarkup;

use App\Extension\Traits\RefreshesLayoutExtensions;
use App\Support\LayoutMarkup\LayoutProvenance;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 확장 fragment 의 `.g7.html` 산출 JSON 공존 provenance 설치 통합 테스트 (S7).
 *
 * 본 레이아웃과 같은 규율을 fragment 등록 경로(`RefreshesLayoutExtensions`)에도 건다: 확장
 * 디렉토리의 `.g7.html` 마다 짝 `.json` 이 그 소스에서 컴파일된 것인지 표식으로 확인하고,
 * 어긋나면 등록 전에 예외로 세운다(부분 상태 방지).
 */
class FragmentMarkupCoexistenceTest extends TestCase
{
    private object $harness;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->setLocale('en');

        $this->harness = new class
        {
            use RefreshesLayoutExtensions;

            public function run(string $path): void
            {
                $this->assertExtensionMarkupCoexistence($path, 'sirsoft-page');
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
     * @effects fragment_with_matching_provenance_passes
     */
    public function test_fragment_with_matching_provenance_passes(): void
    {
        $source = "<extension target-layout=\"admin_dashboard\">\n  <inject target-id=\"root\"/>\n</extension>\n";
        File::put("{$this->tmp}/panel.g7.html", $source);
        File::put("{$this->tmp}/panel.json", json_encode(
            LayoutProvenance::stamp(['target_layout' => 'admin_dashboard', 'injections' => []], $source, 'tmp/panel.g7.html'),
        ));

        $this->harness->run($this->tmp);

        // 예외 없이 통과하면 성공 (반환값 없음).
        $this->addToAssertionCount(1);
    }

    /**
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects fragment_hand_authored_duplicate_is_rejected
     */
    public function test_fragment_without_authoring_marker_is_rejected(): void
    {
        $source = "<extension target-layout=\"admin_dashboard\">\n  <inject target-id=\"root\"/>\n</extension>\n";
        File::put("{$this->tmp}/panel.g7.html", $source);
        File::put("{$this->tmp}/panel.json", json_encode(['target_layout' => 'admin_dashboard', 'injections' => []]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not compiled from it/');

        $this->harness->run($this->tmp);
    }

    /**
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects fragment_uncompiled_markup_source_is_rejected
     */
    public function test_fragment_without_compiled_json_is_rejected(): void
    {
        File::put("{$this->tmp}/panel.g7.html", "<extension target-layout=\"admin_dashboard\">\n  <inject target-id=\"root\"/>\n</extension>\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no compiled \.json/');

        $this->harness->run($this->tmp);
    }

    /**
     * fragment 도 keep(preserveModified) 경로 위에 있다 — `LayoutExtensionService::registerExtension`
     * 이 `content` 해시를 `original_content_hash` 와 비교한다(:1195-1213). 따라서 fragment 산출
     * `.json`(표식 포함) 을 걷어낸 content 가 손저작 fragment `.json` 과 같아야 keep 의 원본 해시
     * 비교가 소스 포맷과 무관하다 — 레이아웃측(MarkupCoexistenceInstallTest)과 같은 보장을 fragment
     * 에도 명시(계획서 §975 "레이아웃+fragment 양쪽").
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects fragment_keep_strategy_hash_is_source_format_agnostic
     */
    public function test_fragment_registered_content_equals_a_plain_json_install(): void
    {
        $source = "<extension target-layout=\"admin_dashboard\">\n  <inject target-id=\"root\"/>\n</extension>\n";
        $stamped = LayoutProvenance::stamp(['target_layout' => 'admin_dashboard', 'injections' => []], $source, 'tmp/panel.g7.html');

        $this->assertSame(
            ['target_layout' => 'admin_dashboard', 'injections' => []],
            LayoutProvenance::strip($stamped),
        );
    }
}
