<?php

namespace Tests\Feature\LayoutMarkup;

use App\Extension\Traits\RefreshesLayoutExtensions;
use App\Support\LayoutMarkup\LayoutCorpus;
use App\Support\LayoutMarkup\LayoutMarkupRoundTrip;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 확장 fragment 소스 파일의 설치 소스 선택 테스트 (S10 정정 아키텍처 — D38·D39, B4).
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

        // collectFragmentSources / compileMarkupFragmentFile 만 노출하는 최소 하네스
        // ($layoutExtensionService 는 두 메서드 모두 쓰지 않음).
        $this->harness = new class
        {
            use RefreshesLayoutExtensions;

            public function sources(string $path, array $jsonFiles): array
            {
                return $this->collectFragmentSources($path, $jsonFiles);
            }

            public function compile(string $markupFile): array
            {
                return $this->compileMarkupFragmentFile($markupFile);
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

    /**
     * **이관 실물 fragment 전량**이 설치 시점 컴파일에서 짝 `.json` 과 같은 등록 데이터를 낸다.
     *
     * 위 두 테스트는 **소스 선택**(마크업 우선)만 본다. 정작 "그 마크업이 원본과 같은 것을 등록하는가"
     * 는 합성 픽스처(`<extension target-layout="x"/>`) 위에서만 돌고 있었다 — W2 이관 37건 중 **24건이
     * fragment** 인데 실물은 한 건도 설치 경로를 타지 않았다. 본 레이아웃 쪽 3자 등가성이 F12(`is_partial`
     * 미선언으로 등록 집합이 바뀌는 결함)를 잡아낸 전례가 있으므로, fragment 도 실물로 봉인한다.
     *
     * fragment 는 partial 인라인 같은 설치 고유 변환이 없어 원본 `.json` 과 직접 대조할 수 있다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects migrated_fragment_markup_compiles_to_its_json
     */
    public function test_every_migrated_fragment_compiles_to_its_json(): void
    {
        // 모집단은 {@see LayoutCorpus} 가 SSoT — 종전에는 이 테스트가 글롭을 따로 들고 있었고
        // `modules`·`plugins` 둘뿐이라 **템플릿 fragment 가 통째로 게이트 밖**이었다(㉘ 등재 사각).
        // 게다가 최상위 파일만 훑어 하위 디렉토리의 fragment 도 못 봤다.
        $markupFiles = LayoutCorpus::scanFragments('.g7.html');

        $this->assertNotEmpty($markupFiles, '이관된 fragment 소스 파일을 하나도 찾지 못했습니다.');

        $failures = [];

        foreach ($markupFiles as $markupFile) {
            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $markupFile);
            $jsonFile = preg_replace('/\.g7\.html$/i', '.json', $markupFile);

            if (! File::exists($jsonFile)) {
                $failures[] = "{$relative} — 짝 원본 .json 이 없습니다 (병존 규약 위반)";

                continue;
            }

            try {
                $compiled = $this->harness->compile($markupFile);
            } catch (\Throwable $error) {
                $failures[] = "{$relative} — 설치 시점 컴파일 실패: {$error->getMessage()}";

                continue;
            }

            $origin = json_decode(File::get($jsonFile), true);

            if (! is_array($origin)) {
                $failures[] = "{$relative} — 짝 .json 파싱 실패";

                continue;
            }

            if ($this->canonical($compiled) !== $this->canonical($origin)) {
                $failures[] = "{$relative} — 설치 컴파일 결과가 원본 .json 과 다릅니다";
            }
        }

        $this->assertSame([], $failures, '이관 fragment 설치 등가성 위반:'.PHP_EOL.implode(PHP_EOL, $failures));
    }

    /**
     * 왕복 오라클과 같은 기준으로 정규화합니다 (키 순서 무관 + 문서 층 별칭 + 파생 키 제거).
     *
     * @param  array<string, mixed>  $data  등록 데이터
     * @return string 결정적 JSON
     */
    private function canonical(array $data): string
    {
        return LayoutMarkupRoundTrip::encode(
            LayoutMarkupRoundTrip::canonicalize(
                LayoutMarkupRoundTrip::normalize(
                    LayoutMarkupRoundTrip::normalizeDocument($data)
                )
            )
        );
    }
}
