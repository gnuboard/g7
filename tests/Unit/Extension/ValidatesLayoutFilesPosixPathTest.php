<?php

namespace Tests\Unit\Extension;

use App\Exceptions\LayoutIncludeException;
use App\Extension\Traits\ValidatesLayoutFiles;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 리눅스형 절대경로(선행 `/`)에서 JSON 전용 조각이 해석되는지 봉인한다 (#144).
 *
 * 7.1.0 은 조각마다 `.g7.html` 후보를 먼저 보는데, 그 후보가 없으면 수동 정규화가 리눅스 절대경로의 맨 앞 `/` 를
 * 잃어 "layouts 밖" 예외가 났고, 실재하는 `.json` 으로 넘어가지 못했다. Windows 는 드라이브 문자(`C:`)가 첫 조각으로
 * 남아 드러나지 않았으므로, 이 테스트는 Windows 에서 **드라이브 문자를 뗀 절대경로**(`\Users\…`)를 기준으로 삼아
 * 리눅스와 같은 분기를 태운다. 리눅스에서는 경로를 그대로 쓴다.
 *
 * 트러블슈팅 `[case:backend-55]` 의 회귀 그물이다.
 */
class ValidatesLayoutFilesPosixPathTest extends TestCase
{
    /** 임시 트리 최상위 (드라이브 문자 포함 — 정리용) */
    private string $tree;

    /** 리눅스형으로 바꾼 템플릿 루트 */
    private string $tpl;

    /**
     * JSON 전용 템플릿 모양의 임시 트리를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tree = storage_path('framework/testing/posix_partial_'.uniqid());
        $tpl = $this->tree.'/templates/acme-json/layouts';

        File::makeDirectory($tpl.'/auth', 0777, true);
        File::makeDirectory($tpl.'/partials/auth', 0777, true);
        File::makeDirectory($tpl.'/partials/common', 0777, true);
        File::makeDirectory($this->tree.'/templates/acme-json/layouts-evil', 0777, true);

        $this->writeJsonFixture($tpl.'/partials/auth/_redirect.json', ['type' => 'basic', 'name' => 'Div', 'text' => 'I144-REDIRECT']);
        $this->writeJsonFixture($tpl.'/auth/_sibling.json', ['type' => 'basic', 'name' => 'Div', 'text' => 'I144-SIBLING', 'children' => [
            ['partial' => '../partials/common/_inner.json'],
        ]]);
        $this->writeJsonFixture($tpl.'/partials/common/_inner.json', ['type' => 'basic', 'name' => 'Span', 'text' => 'I144-NESTED']);
        File::put($tpl.'/partials/common/_markup_only.g7.html', '<div>I144-MARKUP</div>');
        $this->writeJsonFixture($this->tree.'/templates/acme-json/layouts-evil/e.json', ['type' => 'basic', 'name' => 'Div', 'text' => 'EVIL']);
        $this->writeJsonFixture($this->tree.'/templates/outside.json', ['type' => 'basic', 'name' => 'Div', 'text' => 'OUTSIDE']);

        // Windows 에선 드라이브 문자를 떼어 선행 구분자만 있는 절대경로로 만든다 — 리눅스 `/…` 와 같은 분기.
        $this->tpl = PHP_OS_FAMILY === 'Windows' ? (string) preg_replace('/^[A-Za-z]:/', '', $tpl) : $tpl;
    }

    /**
     * 임시 트리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->tree);
        config(['template.layout_markup.install_source' => 'auto']);

        parent::tearDown();
    }

    /**
     * 짝 `.g7.html` 이 없는 `partials/…` 참조가 layouts 루트 폴백으로 `.json` 에 닿는다 — 제보 사례.
     *
     * @scenario case=posix_json_only_partial_root_fallback
     *
     * @effects json_only_partial_resolves_on_posix_absolute_path
     *
     * @return void 반환값 없음
     */
    public function test_json_only_partial_falls_back_to_layouts_root(): void
    {
        $source = $this->resolver()->source('partials/auth/_redirect.json', $this->tpl.'/auth');

        $this->assertTrue($source['exists']);
        $this->assertFalse($source['markup']);
        $this->assertStringEndsWith('/layouts/partials/auth/_redirect.json', $source['path']);
    }

    /**
     * 같은 폴더 조각이 인라인된다.
     *
     * @scenario case=posix_json_only_partial_sibling
     *
     * @effects json_only_partial_resolves_on_posix_absolute_path
     *
     * @return void 반환값 없음
     */
    public function test_sibling_json_partial_is_inlined(): void
    {
        $encoded = json_encode($this->resolver()->all(['components' => [['partial' => '_sibling.json']]], $this->tpl.'/auth'));

        $this->assertStringContainsString('I144-SIBLING', $encoded);
        $this->assertStringNotContainsString('"partial"', $encoded);
    }

    /**
     * 조각 안의 조각(상대 `../partials/…`)까지 인라인된다 — 바깥 조각이 `partials/…` 루트 폴백으로 들어와도 같다.
     *
     * @scenario case=posix_nested_partial
     *
     * @effects json_only_partial_resolves_on_posix_absolute_path
     *
     * @return void 반환값 없음
     */
    public function test_nested_json_partials_are_inlined(): void
    {
        $encoded = json_encode($this->resolver()->all([
            'components' => [
                ['partial' => 'partials/auth/_redirect.json'],
                ['partial' => '_sibling.json'],
            ],
        ], $this->tpl.'/auth'));

        $this->assertStringContainsString('I144-REDIRECT', $encoded);
        $this->assertStringContainsString('I144-NESTED', $encoded);
        $this->assertStringNotContainsString('"partial"', $encoded);
    }

    /**
     * JSON 문서의 없는 조각은 "layouts 밖" 이 아니라 파일 부재로 판정돼 빈 자리로 관용된다 (#712).
     *
     * @scenario case=posix_missing_partial_tolerated
     *
     * @effects missing_json_partial_is_tolerated_on_posix_absolute_path
     *
     * @return void 반환값 없음
     */
    public function test_missing_partial_in_json_document_is_tolerated(): void
    {
        $resolver = $this->resolver();
        $resolved = $resolver->all(['components' => [['partial' => 'partials/auth/_absent.json'], ['type' => 'basic', 'name' => 'Div']]], $this->tpl.'/auth', true);

        $this->assertCount(1, $resolved['components']);
        $warnings = $resolver->takeLayoutIncludeWarnings();
        $this->assertCount(1, $warnings);
        $this->assertSame('include_file_not_found', $warnings[0]['reason']);
    }

    /**
     * 이름이 비슷한 형제 폴더(`layouts-evil`)의 조각은 layouts 밖으로 거부된다.
     *
     * @scenario case=posix_sibling_prefix_rejected
     *
     * @effects outside_reference_rejected_with_author_reference
     *
     * @return void 반환값 없음
     */
    public function test_sibling_prefix_directory_is_rejected(): void
    {
        $this->assertOutsideRejected('../../layouts-evil/e.json');
    }

    /**
     * layouts 위로 빠져나가는 참조는 거부된다.
     *
     * @scenario case=posix_outside_rejected
     *
     * @effects outside_reference_rejected_with_author_reference
     *
     * @return void 반환값 없음
     */
    public function test_reference_above_layouts_is_rejected(): void
    {
        $this->assertOutsideRejected('../../../outside.json');
    }

    /**
     * 거부 오류와 include 경로에는 작성자가 쓴 참조값이 실리고 내부 후보 `.g7.html` 이름은 드러나지 않는다.
     *
     * @scenario case=error_message_author_reference
     *
     * @effects outside_reference_rejected_with_author_reference
     *
     * @return void 반환값 없음
     */
    public function test_rejection_reports_author_reference_not_markup_candidate(): void
    {
        $e = $this->assertOutsideRejected('../../layouts-evil/e.json');

        $this->assertStringContainsString('../../layouts-evil/e.json', $e->getMessage());
        $this->assertStringNotContainsString('.g7.html', $e->getMessage());
        $this->assertSame('../../layouts-evil/e.json', $e->getIncludePath());
    }

    /**
     * JSON 강제여도 짝 `.json` 이 없는 마크업 전용 조각은 마크업으로 해석된다.
     *
     * @scenario case=json_forced_markup_only_partial
     *
     * @effects markup_only_partial_resolves_under_json_forced_on_posix_path
     *
     * @return void 반환값 없음
     */
    public function test_json_forced_source_still_resolves_markup_only_partial(): void
    {
        config(['template.layout_markup.install_source' => 'json']);

        $source = $this->resolver()->source('partials/common/_markup_only.json', $this->tpl.'/auth');

        $this->assertTrue($source['exists']);
        $this->assertTrue($source['markup']);
        $this->assertStringEndsWith('/layouts/partials/common/_markup_only.g7.html', $source['path']);
    }

    /**
     * 참조가 layouts 밖으로 거부되는지 확인하고 그 예외를 돌려줍니다.
     *
     * @param  string  $reference  조각 참조값
     * @return LayoutIncludeException 거부 예외
     */
    private function assertOutsideRejected(string $reference): LayoutIncludeException
    {
        try {
            $this->resolver()->source($reference, $this->tpl.'/auth');
        } catch (LayoutIncludeException $e) {
            $this->assertStringContainsString(Str::before(__('exceptions.layout.include_outside_directory'), ':path'), $e->getMessage());

            return $e;
        }

        $this->fail("layouts 밖 참조가 통과했습니다: {$reference}");
    }

    /**
     * 트레이트를 쓰는 해석기를 만듭니다.
     *
     * @return object 해석기
     */
    private function resolver(): object
    {
        return new class
        {
            use ValidatesLayoutFiles;

            /**
             * 조각 참조의 소스를 고릅니다.
             *
             * @param  string  $reference  조각 참조값
             * @param  string  $basePath  기준 경로
             * @return array{path: string, markup: bool, exists: bool} 소스 디스크립터
             */
            public function source(string $reference, string $basePath): array
            {
                return $this->resolvePartialSource($reference, $basePath);
            }

            /**
             * 레이아웃 데이터의 조각을 모두 해석합니다.
             *
             * @param  array<string, mixed>  $data  레이아웃 데이터
             * @param  string  $basePath  기준 경로
             * @param  bool  $lenient  JSON 문서 관용 여부
             * @return array<string, mixed> 조각이 인라인된 데이터
             */
            public function all(array $data, string $basePath, bool $lenient = false): array
            {
                $this->partialStack = [];

                return $this->resolveAllPartials($data, $basePath, 0, [], $lenient);
            }
        };
    }

    /**
     * JSON 파일을 씁니다.
     *
     * @param  string  $path  파일 경로
     * @param  array<string, mixed>  $data  내용
     * @return void 반환값 없음
     */
    private function writeJsonFixture(string $path, array $data): void
    {
        File::put($path, json_encode($data));
    }
}
