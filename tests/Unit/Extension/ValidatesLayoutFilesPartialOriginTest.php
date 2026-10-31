<?php

namespace Tests\Unit\Extension;

use App\Extension\Traits\ValidatesLayoutFiles;
use App\Support\LayoutMarkup\LayoutPartialOrigin;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 조각 출처 표식(`__partial_origin`)이 병합 기준 원본에만 남고 서비스 본문은 표식 도입 전과 같은지 고정한다 (#727 T-U7).
 *
 * 표식은 3자 병합이 조각 경계를 알기 위한 것이라 `validateLayoutFiles()` 반환의 `original` 에만 있어야 한다.
 * `data`(DB `content`·해시·편집기로 가는 판)가 한 바이트라도 달라지면 설치본 전부가 "수정됨" 으로 보이거나
 * 캐시 버전이 흔들린다.
 */
class ValidatesLayoutFilesPartialOriginTest extends TestCase
{
    /** 임시 layouts 루트 */
    private string $root;

    /**
     * 임시 디렉토리를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/partial-origin-'.substr(md5((string) microtime(true)), 0, 8).'/layouts');
    }

    /**
     * 임시 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->root));
        parent::tearDown();
    }

    /**
     * 트레이트의 보호 메서드를 부를 수 있는 객체를 만듭니다.
     *
     * @return object 트레이트 사용 객체
     */
    private function subject(): object
    {
        return new class
        {
            use ValidatesLayoutFiles;

            /**
             * 검증을 실행합니다.
             *
             * @param  string  $path  layouts 경로
             * @return array<int, array<string, mixed>> 검증 결과
             */
            public function validate(string $path): array
            {
                return $this->validateLayoutFiles($path, 'probe', 'module', true);
            }

            /**
             * 표식 없이(종전 방식) 조각을 해석합니다.
             *
             * @param  array<string, mixed>  $data  레이아웃 원본
             * @param  string  $basePath  기준 경로
             * @return array<string, mixed> 해석 결과
             */
            public function resolvePlain(array $data, string $basePath): array
            {
                return $this->resolveAllPartials($data, $basePath, 0, $data['data_sources'] ?? []);
            }

            /**
             * 마크업 파일을 컴파일합니다.
             *
             * @param  string  $file  `.g7.html` 경로
             * @return array<string, mixed> 컴파일 결과
             */
            public function compile(string $file): array
            {
                return $this->compileMarkupLayoutFile($file);
            }
        };
    }

    /**
     * 실제 번들(게시판 관리자 화면)에서 `data` 는 표식 없이 해석한 판과 바이트가 같고, `original` 에만 표식이 있다.
     *
     * @effects compile_marks_partial_origin_only_in_original
     *
     * @return void 반환값 없음
     */
    public function test_real_bundle_data_is_byte_identical_and_original_carries_markers(): void
    {
        $layouts = base_path('modules/_bundled/sirsoft-board/resources/layouts');
        $subject = $this->subject();
        $validated = collect($subject->validate($layouts))->keyBy(fn ($l) => basename($l['file']));

        $checked = 0;
        foreach (['admin_board_form.g7.html', 'admin_board_index.g7.html', 'admin_board_posts_index.g7.html'] as $file) {
            $entry = $validated->get($file);
            $this->assertNotNull($entry, "{$file} 가 검증 결과에 있어야 한다");

            $plain = $subject->resolvePlain($subject->compile($entry['file']), dirname($entry['file']));
            $this->assertSame(json_encode($plain), json_encode($entry['data']), "{$file} 의 data 가 표식 도입 전과 달라졌다");
            $this->assertFalse(LayoutPartialOrigin::has($entry['data']), 'data 에 표식이 남으면 안 된다');
            $this->assertEquals($entry['data'], LayoutPartialOrigin::strip($entry['original']));
            $checked++;
        }

        $markers = substr_count(json_encode($validated->get('admin_board_form.g7.html')['original']), LayoutPartialOrigin::KEY);
        $includes = substr_count(File::get($layouts.'/admin/admin_board_form.g7.html'), '<include src=');
        $this->assertSame(3, $checked);
        $this->assertGreaterThanOrEqual($includes, $markers, '조각 인스턴스마다 표식이 있어야 한다(중첩 조각 포함)');
        $this->assertStringContainsString('"'.LayoutPartialOrigin::KEY.'":"admin/partials/admin_board_form/_tab_basic"', json_encode($validated->get('admin_board_form.g7.html')['original'], JSON_UNESCAPED_SLASHES));
    }

    /**
     * 리스트 루트 조각과 자기 재귀 조각(`_recursive`)에는 표식을 붙이지 않는다.
     *
     * @effects compile_marks_partial_origin_only_in_original
     *
     * @return void 반환값 없음
     */
    public function test_list_root_and_recursive_partials_have_no_marker(): void
    {
        File::ensureDirectoryExists($this->root.'/partials/x');
        File::put($this->root.'/page.json', json_encode([
            'layout_name' => 'page',
            'components' => [
                ['partial' => 'partials/x/_list.json'],
                ['partial' => 'partials/x/_tree.json'],
            ],
        ]));
        File::put($this->root.'/partials/x/_list.json', json_encode([['id' => 'l1', 'name' => 'Div'], ['id' => 'l2', 'name' => 'Div']]));
        File::put($this->root.'/partials/x/_tree.json', json_encode([
            'meta' => ['is_partial' => true],
            'id' => 'tree', 'name' => 'Div',
            'children' => [['partial' => 'partials/x/_tree.json']],
        ]));

        $entry = $this->subject()->validate($this->root)[0];
        $original = $entry['original'];

        $this->assertArrayNotHasKey(LayoutPartialOrigin::KEY, $original['components'][0], '리스트 루트 조각에는 표식이 없어야 한다');
        $this->assertSame('partials/x/_tree', $original['components'][1][LayoutPartialOrigin::KEY]);
        $this->assertTrue($original['components'][1]['children'][0]['_recursive']);
        $this->assertArrayNotHasKey(LayoutPartialOrigin::KEY, $original['components'][1]['children'][0], '_recursive 노드에는 표식이 없어야 한다');
    }
}
