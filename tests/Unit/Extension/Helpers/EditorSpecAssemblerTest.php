<?php

namespace Tests\Unit\Extension\Helpers;

use App\Extension\Helpers\EditorSpecAssembler;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * EditorSpecAssembler 단위 테스트.
 *
 * editor-spec.json 분할(manifest + `$include` 블록) 합본의 정확성·하위 호환·
 * 폴백·디그레이드를 검증한다. BASE_PATH 를 건드리지 않고 storage/app 하위 임시
 * fixture 디렉토리에 manifest/블록 파일을 구성한 뒤 절대 경로로 합본을 호출한다.
 */
class EditorSpecAssemblerTest extends TestCase
{
    private string $testRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testRoot = storage_path('app/test_editor_spec_assembler');
        File::deleteDirectory($this->testRoot);
        File::makeDirectory($this->testRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testRoot);
        parent::tearDown();
    }

    /**
     * manifest + `$include` 블록을 top-level merge 로 합본한다.
     */
    public function test_assembles_include_blocks_into_single_spec(): void
    {
        $this->writeJson('editor-spec.json', [
            'templateId' => 'demo',
            'version' => '1.0.0',
            'darkMode' => ['strategy' => 'ancestor-class'],
            '$include' => [
                'componentPalette' => 'editor-spec/componentPalette.json',
                'controls' => 'editor-spec/controls.json',
            ],
        ]);
        $this->writeJson('editor-spec/componentPalette.json', [
            'groups' => [['label' => 'Layout', 'components' => ['Div']]],
            'entries' => ['Div' => ['label' => 'Div']],
        ]);
        $this->writeJson('editor-spec/controls.json', [
            'textAlign' => ['widget' => 'segmented'],
        ]);

        $spec = EditorSpecAssembler::assemble($this->path('editor-spec.json'));

        $this->assertIsArray($spec);
        // manifest 인라인 메타 보존
        $this->assertSame('demo', $spec['templateId']);
        $this->assertSame('1.0.0', $spec['version']);
        $this->assertSame(['strategy' => 'ancestor-class'], $spec['darkMode']);
        // include 블록이 해당 키 값으로 합본됨
        $this->assertSame(['Div' => ['label' => 'Div']], $spec['componentPalette']['entries']);
        $this->assertSame(['widget' => 'segmented'], $spec['controls']['textAlign']);
        // `$include` 키 자체는 결과에 남지 않음
        $this->assertArrayNotHasKey('$include', $spec);
    }

    /**
     * `$include` 없는 미분할 manifest 는 원본 그대로 반환한다(하위 호환).
     */
    public function test_returns_manifest_as_is_when_no_include(): void
    {
        $original = [
            'templateId' => 'legacy',
            'version' => '1.0.0',
            'componentPalette' => ['entries' => ['Div' => ['label' => 'Div']]],
            'controls' => ['textAlign' => ['widget' => 'segmented']],
        ];
        $this->writeJson('editor-spec.json', $original);

        $spec = EditorSpecAssembler::assemble($this->path('editor-spec.json'));

        $this->assertSame($original, $spec);
    }

    /**
     * include 파일 미존재 시 해당 키만 누락하고 나머지는 합본한다(무손실 디그레이드).
     */
    public function test_skips_missing_include_block(): void
    {
        $this->writeJson('editor-spec.json', [
            'templateId' => 'demo',
            '$include' => [
                'controls' => 'editor-spec/controls.json',
                'missing' => 'editor-spec/does-not-exist.json',
            ],
        ]);
        $this->writeJson('editor-spec/controls.json', ['textAlign' => ['widget' => 'segmented']]);

        $spec = EditorSpecAssembler::assemble($this->path('editor-spec.json'));

        $this->assertIsArray($spec);
        $this->assertArrayHasKey('controls', $spec);
        $this->assertArrayNotHasKey('missing', $spec);
    }

    /**
     * include 분할본이 manifest 인라인 동일 키를 덮어쓴다(분할본이 정본).
     */
    public function test_include_block_overrides_inline_value(): void
    {
        $this->writeJson('editor-spec.json', [
            'controls' => ['textAlign' => ['widget' => 'STALE']],
            '$include' => ['controls' => 'editor-spec/controls.json'],
        ]);
        $this->writeJson('editor-spec/controls.json', ['textAlign' => ['widget' => 'segmented']]);

        $spec = EditorSpecAssembler::assemble($this->path('editor-spec.json'));

        $this->assertSame(['textAlign' => ['widget' => 'segmented']], $spec['controls']);
    }

    /**
     * 합본 결과가 분할 전 단일 파일과 deep-equal 함을 검증한다(분할 무손실 보장).
     */
    public function test_assembled_spec_deep_equals_monolithic(): void
    {
        $monolithic = [
            'templateId' => 'demo',
            'version' => '1.0.0',
            'darkMode' => ['strategy' => 'media-query'],
            'componentPalette' => [
                'groups' => [['label' => 'L', 'components' => ['Div', 'Span']]],
                'entries' => ['Div' => ['label' => 'Div'], 'Span' => ['label' => 'Span']],
            ],
            'controls' => ['textAlign' => ['widget' => 'segmented', 'options' => [1, 2, 3]]],
            'sampleData' => ['byDataSourceId' => ['users' => ['data' => [['id' => 1]]]]],
        ];

        // 분할본 구성: 메타+darkMode 인라인, 나머지 블록은 include.
        $manifest = [
            'templateId' => 'demo',
            'version' => '1.0.0',
            'darkMode' => ['strategy' => 'media-query'],
            '$include' => [
                'componentPalette' => 'editor-spec/componentPalette.json',
                'controls' => 'editor-spec/controls.json',
                'sampleData' => 'editor-spec/sampleData.json',
            ],
        ];
        $this->writeJson('editor-spec.json', $manifest);
        $this->writeJson('editor-spec/componentPalette.json', $monolithic['componentPalette']);
        $this->writeJson('editor-spec/controls.json', $monolithic['controls']);
        $this->writeJson('editor-spec/sampleData.json', $monolithic['sampleData']);

        $spec = EditorSpecAssembler::assemble($this->path('editor-spec.json'));

        $this->assertEquals($monolithic, $spec);
    }

    /**
     * 활성 디렉토리에 파일이 없으면 null 을 반환한다(편집 컨트롤 부재 폴백).
     *
     * _bundled 폴백은 없다 — 활성 경로 단일 기준이므로 미존재 시 즉시 null.
     */
    public function test_returns_null_when_active_file_missing(): void
    {
        // _bundled 에 파일이 있어도 활성 단일 경로만 보므로 무관(폴백 없음).
        $this->writeJson('_bundled/editor-spec.json', ['templateId' => 'bundled']);

        $spec = EditorSpecAssembler::assemble($this->path('active/editor-spec.json'));

        $this->assertNull($spec);
    }

    /**
     * 파싱 실패(비 JSON) manifest 는 null 을 반환한다.
     */
    public function test_returns_null_on_invalid_json(): void
    {
        File::put($this->path('editor-spec.json'), '{ this is not valid json ]');

        $spec = EditorSpecAssembler::assemble($this->path('editor-spec.json'));

        $this->assertNull($spec);
    }

    /**
     * 테스트 루트 기준 상대 경로에 JSON 을 기록합니다.
     *
     * @param  string  $relative  testRoot 기준 상대 경로
     * @param  array<string, mixed>  $data  기록할 배열
     */
    private function writeJson(string $relative, array $data): void
    {
        $path = $this->path($relative);
        File::ensureDirectoryExists(\dirname($path));
        File::put($path, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 테스트 루트 기준 상대 경로의 절대 경로를 반환합니다.
     *
     * @param  string  $relative  testRoot 기준 상대 경로
     * @return string 절대 경로
     */
    private function path(string $relative): string
    {
        return $this->testRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
