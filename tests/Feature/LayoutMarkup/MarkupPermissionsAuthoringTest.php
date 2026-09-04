<?php

namespace Tests\Feature\LayoutMarkup;

use App\Support\LayoutMarkup\LayoutCorpus;
use App\Support\LayoutMarkup\LayoutMarkupCompiler;
use App\Support\LayoutMarkup\MarkupCompileContextResolver;
use App\Support\LayoutMarkup\MarkupPermissionsEditor;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `layout:add-permissions` 의 소스 전환 계약 (S18.5 축 A — 예고된 red ⓓ).
 *
 * 32차가 `AddLayoutPermissionsCommand` 를 `JSON_ONLY_LAYOUT_CONSUMER_DEBT` 에 등재하면서 사유를
 * 이렇게 적었다 — *"짝이 전건 병존하는 현재는 결함이 0 이지만, 굽기가 `.json` 을 기계 산출로 바꾸면
 * 이 커맨드의 편집은 다음 굽기에 소실된다"*. S18.5 가 그 전환을 이행했으므로 여기서 봉인한다.
 *
 * 고정하는 것은 넷이다:
 *
 *  - 소스에 문서 최상위 `<permissions>` 를 넣으면 **컴파일 산출의 `permissions` 키가 된다**
 *    (편집이 문법으로 성립함을 산출로 확인 — 문자열이 들어갔다는 것만으로는 부족하다).
 *  - 기존 블록은 **교체**되고 나머지 문서는 한 글자도 바뀌지 않는다.
 *  - **노드 레벨 `<permissions>`(D36)를 문서 권한으로 오인하지 않는다** — 같은 문법이 두 층에서
 *    쓰이므로 문자열 매칭이면 조건 렌더 권한을 레이아웃 권한으로 덮어쓴다.
 *  - 커맨드가 `.json` 을 in-place 로 고치지 않는다 (짝이 있으면 소스를 고른다).
 */
class MarkupPermissionsAuthoringTest extends TestCase
{
    /**
     * 합성 소스가 놓이는 디렉토리 (git 미추적, 코퍼스 스캔 밖).
     */
    private const SCRATCH = 'storage/framework/testing/markup-permissions';

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(base_path(self::SCRATCH));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path(self::SCRATCH));

        parent::tearDown();
    }

    /**
     * 삽입한 `<permissions>` 가 컴파일 산출의 `permissions` 키가 된다.
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects authored_permissions_block_compiles_to_the_permissions_key
     */
    public function test_inserted_permissions_block_compiles_to_the_permissions_key(): void
    {
        $file = $this->writeMarkup('insert', <<<'HTML'
        <layout name="perm_probe" version="1.0.0">
          <div class="p-4">
            <h2>권한 표본</h2>
          </div>
        </layout>
        HTML);

        $edited = MarkupPermissionsEditor::apply(File::get($file), ['core.users.read'], $file);

        $this->assertSame(['core.users.read'], $this->compile($file, $edited)['permissions'] ?? null,
            '삽입한 블록이 문서 권한으로 컴파일되지 않았다');
    }

    /**
     * 빈 권한 목록은 `permissions: []` 로 컴파일된다 (키 부재와 구별된다).
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects authored_empty_permissions_block_compiles_to_an_empty_array
     */
    public function test_empty_permissions_block_compiles_to_an_empty_array(): void
    {
        $file = $this->writeMarkup('empty', <<<'HTML'
        <layout name="perm_probe" version="1.0.0">
          <div class="p-4"></div>
        </layout>
        HTML);

        $compiled = $this->compile($file, MarkupPermissionsEditor::apply(File::get($file), [], $file));

        $this->assertArrayHasKey('permissions', $compiled, '빈 권한이 키 자체를 없앴다');
        $this->assertSame([], $compiled['permissions']);
    }

    /**
     * 기존 블록은 교체되고 나머지 문서는 그대로다.
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects authored_permissions_block_is_replaced_without_touching_the_rest
     */
    public function test_existing_block_is_replaced_and_the_rest_is_untouched(): void
    {
        $file = $this->writeMarkup('replace', <<<'HTML'
        <layout name="perm_probe" version="1.0.0">
          <permissions>
            <permission>old.one</permission>
          </permissions>
          <div class="p-4">
            <h2>권한 표본</h2>
          </div>
        </layout>
        HTML);

        $before = $this->compile($file, File::get($file));
        $after = $this->compile($file, MarkupPermissionsEditor::apply(File::get($file), ['new.one', 'new.two'], $file));

        $this->assertSame(['old.one'], $before['permissions']);
        $this->assertSame(['new.one', 'new.two'], $after['permissions']);

        unset($before['permissions'], $after['permissions']);

        $this->assertSame($before, $after, '권한 교체가 문서의 다른 부분을 바꿨다');
    }

    /**
     * 노드 레벨 `<permissions>`(D36)를 문서 권한으로 오인하지 않는다.
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects node_level_permissions_are_not_mistaken_for_document_permissions
     */
    public function test_node_level_permissions_are_not_mistaken_for_document_permissions(): void
    {
        $file = $this->writeMarkup('node_level', <<<'HTML'
        <layout name="perm_probe" version="1.0.0">
          <div class="p-4">
            <permissions>
              <permission>node.only</permission>
            </permissions>
            <h2>권한 표본</h2>
          </div>
        </layout>
        HTML);

        $this->assertFalse(MarkupPermissionsEditor::hasDocumentPermissions(File::get($file), $file),
            '노드 레벨 권한을 문서 권한으로 봤다 — 그러면 --force 가 조건 렌더 권한을 덮어쓴다');

        $compiled = $this->compile($file, MarkupPermissionsEditor::apply(File::get($file), ['doc.level'], $file));

        $this->assertSame(['doc.level'], $compiled['permissions'],
            '문서 권한이 삽입되지 않았다');
        $this->assertSame(['node.only'], $compiled['components'][0]['permissions'] ?? null,
            '노드 레벨 권한이 덮어써졌다');
    }

    /**
     * 편집은 LF 로 정규화된다 (CRLF 로 작성된 파일도 산출은 LF).
     *
     * @scenario compile_unit=document,source_health=valid
     *
     * @effects authored_permissions_edit_normalizes_line_endings_to_lf
     */
    public function test_edit_normalizes_line_endings_to_lf(): void
    {
        $file = $this->writeMarkup('crlf', str_replace("\n", "\r\n", <<<'HTML'
        <layout name="perm_probe" version="1.0.0">
          <div class="p-4"></div>
        </layout>
        HTML));

        $edited = MarkupPermissionsEditor::apply(File::get($file), ['core.users.read'], $file);

        $this->assertStringNotContainsString("\r", $edited, '편집 산출에 CR 이 남았다');
    }

    /**
     * 커맨드가 짝 병존 레이아웃에서 **소스**을 고른다 (`.json` in-place 금지).
     *
     * 굽기 이후 `.json` 은 기계 산출이므로, 여기 기록하면 다음 `layout:decompile --all --sync` 에
     * 소실된다. 짝을 일부러 갈라 두고 어느 쪽이 바뀌는지로 선택을 관측한다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects add_permissions_command_edits_the_authoring_source_not_the_json_twin
     */
    public function test_command_targets_the_authoring_source_for_paired_layouts(): void
    {
        $candidates = $this->pairedCorpusLayoutsWithoutPermissions();

        // 모집단 하한 — skip 으로 넘기면 도출이 죽어도 이 테스트는 영원히 초록이다. 코퍼스에서
        // 이 표본이 사라지는 날(모든 레이아웃이 권한을 갖게 되는 날)은 red 가 정답이고, 그때 할
        // 일은 하한을 지우는 것이 아니라 관측을 합성 표본으로 옮기는 것이다(31차 처방).
        $this->assertNotEmpty($candidates,
            '짝이 병존하면서 permissions 가 없는 본 레이아웃이 코퍼스에 0건이다 — 도출이 죽었는지 '
            .'먼저 확인하고, 실제로 전건 권한 보유 상태가 됐다면 관측을 합성 표본으로 옮겨야 한다');

        [$markupFile, $jsonFile, $layoutName] = $candidates[0];

        $markupBefore = File::get($markupFile);
        $jsonBefore = File::get($jsonFile);

        try {
            $this->artisan('layout:add-permissions', ['--layout' => $layoutName])
                ->assertExitCode(0)
                ->run();

            $this->assertNotSame($markupBefore, File::get($markupFile),
                '소스가 그대로다 — 커맨드가 소스를 고르지 않았다');
            $this->assertSame($jsonBefore, File::get($jsonFile),
                '짝 `.json` 이 바뀌었다 — 굽기 산출물에 in-place 로 기록하면 다음 굽기에 소실된다');
            $this->assertTrue(MarkupPermissionsEditor::hasDocumentPermissions(File::get($markupFile), $markupFile));
        } finally {
            File::put($markupFile, $markupBefore);
            File::put($jsonFile, $jsonBefore);
        }
    }

    /**
     * 짝이 병존하면서 `permissions` 가 없는 본 레이아웃을 코퍼스에서 전량 고릅니다.
     *
     * 표본을 코퍼스에 심지 않고 **실물에서 고르는** 이유는, 커맨드의 모집단이 `LayoutCorpus`(번들
     * 트리)라 스크래치 디렉토리의 합성 파일이 그 모집단에 들어오지 않기 때문이다. 원본은 테스트가
     * 되돌린다.
     *
     * @return array<int, array{0: string, 1: string, 2: string}> `[소스, 짝 .json, 레이아웃 이름]` 목록
     */
    private function pairedCorpusLayoutsWithoutPermissions(): array
    {
        $candidates = [];

        foreach (LayoutCorpus::scanLayouts('.g7.html') as $markupFile) {
            if (str_contains(str_replace('\\', '/', $markupFile), '/partials/')) {
                continue;
            }

            $jsonFile = (string) preg_replace('/\.g7\.html$/i', '.json', $markupFile);

            if (! File::exists($jsonFile)) {
                continue;
            }

            $layout = json_decode(File::get($jsonFile), true);

            if (! is_array($layout) || array_key_exists('permissions', $layout)) {
                continue;
            }

            $name = $layout['layout_name'] ?? null;

            // 공개 레이아웃(베이스·에러·인증)은 커맨드가 건너뛰므로 관측을 제공하지 못한다.
            if (! is_string($name) || $name === '' || str_starts_with($name, '_')
                || str_starts_with($name, 'errors/') || str_starts_with($name, 'auth')) {
                continue;
            }

            $candidates[] = [$markupFile, $jsonFile, $name];
        }

        return $candidates;
    }

    /**
     * 소스를 설치 시점과 같은 파사드로 컴파일합니다.
     *
     * @param  string  $file  경로 (레지스트리·문맥 해석용)
     * @param  string  $source  소스
     * @return array<string, mixed> 컴파일 산출
     */
    private function compile(string $file, string $source): array
    {
        $resolver = new MarkupCompileContextResolver;

        return (new LayoutMarkupCompiler($resolver->registryFor($file)))
            ->compile($source, $file, $resolver->contextFor($file))
            ->json;
    }

    /**
     * 합성 소스를 스크래치 디렉토리에 씁니다.
     *
     * @param  string  $name  파일 이름(확장자 제외)
     * @param  string  $markup  소스
     * @return string `.g7.html` 절대 경로
     */
    private function writeMarkup(string $name, string $markup): string
    {
        $path = base_path(self::SCRATCH."/{$name}.g7.html");

        File::put($path, $markup);

        return $path;
    }
}
