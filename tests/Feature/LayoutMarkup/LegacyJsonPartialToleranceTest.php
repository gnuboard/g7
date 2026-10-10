<?php

namespace Tests\Feature\LayoutMarkup;

use App\Extension\Traits\ValidatesLayoutFiles;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 7.0 JSON 레이아웃의 깨진 조각 참조 관용 테스트 (#712 W1).
 *
 * 7.0.11 은 조각 해석 실패를 `app.debug=false` 에서 빈 배열로 바꿔 설치를 통과시켰다. 7.1.0 은 그 침묵을
 * 걷어내며 실패를 설치 오류로 올렸는데, 그 결과 7.0 에서 설치되던 JSON 전용 확장이 조각 하나가 없다는 이유로
 * 설치·활성화·업데이트 전부에서 거부됐다.
 *
 * 결정(#712 Q1·D1~D3): 실패한 참조를 **담고 있는 문서가 `.json`** 이면 파일 부재·조각 JSON 파싱 실패·순환
 * 참조 세 갈래를 관용한다 — 그 자리를 비우고(리스트면 원소 제거, 아니면 `[]`) 경고를 남긴다. `layouts/` 밖
 * 참조·최대 깊이·마크업 조각 컴파일 실패는 JSON 문서 안이어도 엄격하고, `.g7.html` 문서는 종전대로 엄격하다
 * ({@see MarkupPartialResolutionTest}).
 *
 * 트러블슈팅 `[case:backend-50]` 의 회귀 그물이다.
 *
 * @see ValidatesLayoutFiles::resolvePartial()
 */
class LegacyJsonPartialToleranceTest extends TestCase
{
    private object $harness;

    private string $root;

    private string $layouts;

    /**
     * 테스트 준비 — 조각 해석 대역과 임시 layouts 디렉토리를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->setLocale('en');
        config(['app.debug' => false]);

        $this->harness = new class
        {
            use ValidatesLayoutFiles;

            /**
             * 레이아웃 검증을 실행합니다.
             *
             * @param  string  $path  layouts 경로
             * @return array 검증된 레이아웃 목록
             */
            public function run(string $path): array
            {
                return $this->validateLayoutFiles($path, 'gnuboard7-hello_module', 'module', true);
            }

            /**
             * 수집된 경고를 꺼냅니다.
             *
             * @return array 경고 목록
             */
            public function warnings(): array
            {
                return $this->takeLayoutIncludeWarnings();
            }

            /**
             * JSON 문서 하나의 조각 참조만 해석합니다 (디렉토리 스캔 없이).
             *
             * @param  array  $data  JSON 문서 내용
             * @param  string  $basePath  문서가 있는 디렉토리
             * @return array 해석 결과
             */
            public function resolveJson(array $data, string $basePath): array
            {
                return $this->resolveAllPartials($data, $basePath, 0, [], true);
            }
        };

        $this->root = base_path('storage/framework/testing/legacy_json_partial_'.uniqid());
        $this->layouts = $this->root.'/layouts';
        File::ensureDirectoryExists($this->layouts.'/partials');
    }

    /**
     * 테스트 정리 — 임시 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * JSON 호스트의 부재 조각 참조는 설치를 막지 않고, 리스트 원소에서 빠지며, 경고 1건을 남긴다.
     *
     * @scenario case=json_missing_partial
     *
     * @effects json_host_missing_partial_is_tolerated_and_removed
     *
     * @return void 반환값 없음
     */
    public function test_json_host_missing_partial_is_tolerated_and_removed(): void
    {
        $this->writeJson('host.json', [
            'version' => '1.0.0',
            'components' => [
                ['type' => 'basic', 'name' => 'Span', 'props' => ['text' => 'before']],
                ['partial' => 'partials/does_not_exist.json'],
                ['type' => 'basic', 'name' => 'Span', 'props' => ['text' => 'after']],
            ],
        ]);

        $layouts = $this->harness->run($this->layouts);
        $components = $this->hostComponents($layouts);

        $this->assertCount(2, $components, '부재 조각 자리는 리스트에서 빠진다 (빈 원소를 남기지 않는다)');
        $this->assertSame(['before', 'after'], array_column(array_column($components, 'props'), 'text'));
        $this->assertSame([0, 1], array_keys($components), '리스트는 재색인된다 (JSON 객체로 바뀌지 않는다)');

        $warnings = $this->harness->warnings();
        $this->assertCount(1, $warnings);
        $this->assertSame('include_file_not_found', $warnings[0]['reason']);
        $this->assertSame('partials/does_not_exist.json', $warnings[0]['partial']);
        $this->assertStringEndsWith('layouts/host.json', $warnings[0]['file']);
        $this->assertNotSame('', $warnings[0]['message']);
        // 경고는 관리자 API 응답(`layout_warnings`)으로 나간다 — 서버 절대 경로를 싣지 않는다(2026-10-01 실측에서 노출).
        $this->assertStringNotContainsString(base_path(), $warnings[0]['message']);
        $this->assertStringNotContainsString(str_replace('\\', '/', base_path()), $warnings[0]['message']);
        $this->assertSame([], $this->harness->warnings(), '꺼내면 비워진다');
    }

    /**
     * JSON 조각 파일의 파싱 실패는 **조각 해석 단계에서** 관용한다.
     *
     * 다만 그 파일이 `layouts/` 안에 있으면 재귀 스캔이 단독 레이아웃으로도 읽어 JSON 파싱 오류로 설치를 세운다 —
     * 7.0.11 도 같은 스캔으로 실패했으므로(그때도 설치 불가) 그 갈래는 바꾸지 않는다. 관용은 참조 쪽의 오류
     * (`partial_error`)를 더하지 않는 데까지다.
     *
     * @scenario case=json_invalid_partial
     *
     * @effects json_host_invalid_partial_json_is_tolerated
     *
     * @return void 반환값 없음
     */
    public function test_json_host_invalid_partial_json_is_tolerated(): void
    {
        File::put($this->layouts.'/partials/broken.json', '{ "meta": {"is_partial": true}, "type": "basic", ');

        $resolved = $this->harness->resolveJson(
            ['version' => '1.0.0', 'components' => [['partial' => 'partials/broken.json'], ['type' => 'basic', 'name' => 'Span']]],
            $this->layouts
        );

        $this->assertSame(['Span'], array_column($resolved['components'], 'name'));
        $this->assertSame(['invalid_include_json'], array_column($this->harness->warnings(), 'reason'));

        // 디렉토리 검증은 깨진 파일 자체의 단독 스캔에서 실패한다(7.0.11 동일) — 참조 쪽 오류는 더하지 않는다.
        $this->writeJson('host.json', ['version' => '1.0.0', 'components' => [['partial' => 'partials/broken.json']]]);
        $message = $this->expectInstallFailure();
        // 영어 개수 문구는 1이면 단수다(7.1.0 단수형 원칙) — 「1 errors」 가 아니라 「1 error found」.
        $this->assertStringContainsString('1 error found', $message, '단독 스캔의 파싱 오류 1건만 — 참조 쪽 partial 오류는 없다');
        $this->assertStringNotContainsString('Failed to process partial', $message);
    }

    /**
     * JSON 조각끼리의 순환 참조(A→B→A)도 관용한다 — 순환을 만든 자리만 빈다.
     *
     * @scenario case=json_circular_partial
     *
     * @effects json_circular_partial_is_tolerated
     *
     * @return void 반환값 없음
     */
    public function test_json_circular_partial_is_tolerated(): void
    {
        $this->writeJson('host.json', ['version' => '1.0.0', 'components' => [['partial' => 'partials/a.json']]]);
        $this->writeJson('partials/a.json', ['meta' => ['is_partial' => true], 'type' => 'basic', 'name' => 'Div', 'children' => [['partial' => 'partials/b.json']]]);
        $this->writeJson('partials/b.json', ['meta' => ['is_partial' => true], 'type' => 'basic', 'name' => 'Section', 'children' => [['partial' => 'partials/a.json']]]);

        $layouts = $this->harness->run($this->layouts);
        $a = $this->hostComponents($layouts)[0];

        $this->assertSame('Div', $a['name']);
        $this->assertSame('Section', $a['children'][0]['name']);
        $this->assertSame([], $a['children'][0]['children'], 'b 안의 a 재참조(순환) 자리만 빈다');

        $warnings = $this->harness->warnings();
        $this->assertSame(['circular_include'], array_column($warnings, 'reason'));
        $this->assertStringEndsWith('partials/b.json', $warnings[0]['file'], '경고의 파일은 실패한 참조를 담은 문서다');
    }

    /**
     * 리스트가 아닌 자리의 부재 조각은 `[]` 로 비운다 (7.0.11 과 같은 표현).
     *
     * @scenario case=json_non_list_slot
     *
     * @effects json_non_list_slot_missing_partial_becomes_empty_array
     *
     * @return void 반환값 없음
     */
    public function test_non_list_position_becomes_empty_array(): void
    {
        $this->writeJson('host.json', [
            'version' => '1.0.0',
            'components' => [['type' => 'basic', 'name' => 'Div', 'props' => ['slotted' => ['partial' => 'partials/absent.json']]]],
        ]);

        $layouts = $this->harness->run($this->layouts);

        $this->assertSame([], $this->hostComponents($layouts)[0]['props']['slotted']);
        $this->assertCount(1, $this->harness->warnings());
    }

    /**
     * JSON → JSON → 부재 의 중첩도 관용하고, 경고는 안쪽 JSON 조각을 가리킨다.
     *
     * @scenario case=json_nested_partial
     *
     * @effects nested_json_partial_missing_reference_is_tolerated
     *
     * @return void 반환값 없음
     */
    public function test_nested_json_partial_missing_reference_is_tolerated(): void
    {
        $this->writeJson('host.json', ['version' => '1.0.0', 'components' => [['partial' => 'partials/outer.json']]]);
        $this->writeJson('partials/outer.json', [
            'meta' => ['is_partial' => true], 'type' => 'basic', 'name' => 'Div',
            'children' => [['partial' => 'partials/gone.json'], ['type' => 'basic', 'name' => 'Span']],
        ]);

        $layouts = $this->harness->run($this->layouts);
        $outer = $this->hostComponents($layouts)[0];

        $this->assertSame(['Span'], array_column($outer['children'], 'name'));

        $warnings = $this->harness->warnings();
        $this->assertCount(1, $warnings);
        $this->assertStringEndsWith('partials/outer.json', $warnings[0]['file']);
    }

    /**
     * `layouts/` 밖 참조는 JSON 문서 안이어도 엄격하다 (보안 경계, D1).
     *
     * @scenario case=json_outside_layouts_strict
     *
     * @effects json_host_outside_layouts_still_fails
     *
     * @return void 반환값 없음
     */
    public function test_json_host_outside_layouts_still_fails(): void
    {
        File::put($this->root.'/escaped.json', json_encode(['type' => 'basic', 'name' => 'Span']));
        $this->writeJson('host.json', ['version' => '1.0.0', 'components' => [['partial' => '../escaped.json']]]);

        $message = $this->expectInstallFailure();

        $this->assertStringContainsString(trim(explode(':', (string) __('exceptions.layout.include_outside_directory'), 2)[0]), $message);
    }

    /**
     * JSON 호스트가 부른 `.g7.html` 조각 안의 부재 참조는 엄격하다 (판정 단위는 참조를 담은 문서, D2).
     *
     * @scenario case=markup_partial_strict
     *
     * @effects markup_partial_inside_json_host_stays_strict
     *
     * @return void 반환값 없음
     */
    public function test_markup_partial_inside_json_host_stays_strict(): void
    {
        $this->writeJson('host.json', ['version' => '1.0.0', 'components' => [['partial' => 'partials/m.json']]]);
        File::put($this->layouts.'/partials/m.g7.html',
            "<partial>\n  <div>\n    <include src=\"partials/absent.g7.html\"/>\n  </div>\n</partial>\n");

        $message = $this->expectInstallFailure();

        $this->assertStringContainsString(trim(explode(':', (string) __('exceptions.layout.include_file_not_found'), 2)[0]), $message);
        // 엄격 갈래의 검증 실패 문구도 관리자 응답으로 나간다 — 서버 절대 경로를 싣지 않는다.
        $this->assertStringNotContainsString(base_path(), $message);
        $this->assertStringNotContainsString(str_replace('\\', '/', base_path()), $message);
        $this->assertSame([], $this->harness->warnings(), '엄격 갈래는 경고로 남지 않는다');
    }

    /**
     * 설치가 실패하는 것을 확인하고 그 오류 메시지를 돌려줍니다.
     *
     * @return string 설치 오류 메시지
     */
    private function expectInstallFailure(): string
    {
        try {
            $this->harness->run($this->layouts);
        } catch (\Exception $e) {
            return $e->getMessage();
        }

        $this->fail('엄격 갈래인데 설치가 통과했습니다.');
    }

    /**
     * layouts 아래 상대 경로에 JSON 을 씁니다.
     *
     * @param  string  $relative  layouts 기준 상대 경로
     * @param  array<string, mixed>  $data  내용
     * @return void 반환값 없음
     */
    private function writeJson(string $relative, array $data): void
    {
        File::put($this->layouts.'/'.$relative, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 검증 결과에서 호스트 레이아웃의 최상위 컴포넌트를 꺼냅니다.
     *
     * @param  array<int, array<string, mixed>>  $layouts  검증 결과
     * @return array<int|string, mixed> 컴포넌트 목록
     */
    private function hostComponents(array $layouts): array
    {
        foreach ($layouts as $layout) {
            if (str_ends_with(str_replace('\\', '/', $layout['file']), 'layouts/host.json')) {
                return $layout['data']['components'];
            }
        }

        $this->fail('호스트 레이아웃이 검증 결과에 없습니다.');
    }
}
