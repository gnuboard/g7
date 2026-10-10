<?php

namespace Tests\Feature\LayoutMarkup;

use App\Extension\Traits\ValidatesLayoutFiles;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 조각(`<include>`) 해석의 소스 SSoT 우선 규약 테스트 (S18 P1).
 *
 * 본 레이아웃과 fragment 등록은 이미 `.g7.html` 우선인데 **조각 해석만 `.json` 전용**이었다.
 * 그래서 `partials/*.g7.html` 451건은 소스를 고쳐도 반영되지 않고, 짝 `.json` 을 함께 고쳐야만
 * 화면이 바뀌는 상태였다 — 소스 SSoT 가 사실상 두 벌이었다는 뜻이다.
 *
 * 여기서 네 가지를 고정한다:
 *
 *  - 병존하면 `.g7.html` 이 인라인된다 (`collectLayoutSources` 와 같은 우선순위 규약).
 *  - `.json` 참조인데 짝 마크업만 실재하면 해석된다 (마크업 전용 신규 조각).
 *  - `src="*.g7.html"` 직접 참조도 해석된다.
 *  - `--layout-source=json` 이면 우선순위가 뒤집힌다.
 *
 * **`.json` 참조는 JSON 호스트로 낸다 (S21.2 ⓑ · U-REFEXT 안 B)**. 소스는 소스를 가리키는 것이
 * 규약이 되었으므로(`<include src="*.json">` 은 이제 컴파일 에러다), *"`.json` 참조가 짝 마크업으로
 * 올라간다"* 는 축은 **산출/설치 JSON 이 참조하는 자리**에서 재현해야 한다 — 그 자리가 이 규약이
 * 실제로 쓰이는 곳이고, 소스 표기를 바꿨다고 사라지는 축이 아니다. 축 자체는 그대로 유지된다.
 *
 * 그리고 조각 해석 실패가 **설치 오류로 표면화**되는 것을 함께 고정한다. 종전에는
 * `createPartialErrorComponent` 가 `app.debug=false` 에서 빈 배열을 돌려주어, 설치가 성공한 채
 * DB content 에 구멍이 굽히고 화면 일부가 흔적 없이 비었다 — 이 결함 계열이 오래 안 보인 이유가
 * 정확히 그 침묵이라, 마크업을 읽게 만들면서 침묵을 같이 걷어낸다.
 *
 * @see ValidatesLayoutFiles::resolvePartial()
 */
class MarkupPartialResolutionTest extends TestCase
{
    private object $harness;

    private string $root;

    private string $layouts;

    /**
     * 테스트 준비 — 필요한 서비스와 임시 자원을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->setLocale('en');

        // 침묵 소멸이 debug 플래그에 의존하지 않는다는 것을 보이기 위해 프로덕션 값으로 고정한다.
        config(['app.debug' => false]);

        $this->harness = new class
        {
            use ValidatesLayoutFiles;

            /**
             * 테스트 대역의 실행 진입점입니다.
             *
             * @param  string  $path  경로
             * @return array 사례 목록
             */
            public function run(string $path): array
            {
                return $this->validateLayoutFiles($path, 'gnuboard7-hello_module', 'module', true);
            }
        };

        $this->root = base_path('storage/framework/testing/partial_res_'.uniqid());
        $this->layouts = $this->root.'/layouts';
        File::ensureDirectoryExists($this->layouts.'/partials');
    }

    /**
     * 테스트 정리 — 만든 임시 자원을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * 병존하면 소스 SSoT(`.g7.html`)가 인라인된다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects partial_markup_wins_over_json_sibling
     *
     * @return void 반환값 없음
     */
    public function test_partial_markup_wins_over_json_sibling(): void
    {
        $this->writeJsonHost('partials/frag.json');
        $this->writePartialMarkup('frag', 'from-markup');
        $this->writePartialJson('frag', 'from-json');

        $layouts = $this->harness->run($this->layouts);

        $this->assertSame('from-markup', $this->inlinedText($layouts),
            '조각도 본 레이아웃과 같이 .g7.html 이 소스 SSoT 다');
    }

    /**
     * `.json` 참조인데 짝 마크업만 실재하면 해석된다 (마크업 전용 신규 조각).
     *
     * 이 축이 실제로 쓰이는 자리는 산출/설치 JSON 의 `partial` 이다 — 소스는 이제 소스를
     * 가리키므로(S21.2 ⓑ), 호스트를 JSON 으로 두어야 규약이 관측된다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects partial_json_reference_resolves_via_markup_sibling
     *
     * @return void 반환값 없음
     */
    public function test_partial_json_reference_resolves_via_markup_sibling(): void
    {
        $this->writeJsonHost('partials/frag.json');
        $this->writePartialMarkup('frag', 'markup-only');

        $layouts = $this->harness->run($this->layouts);

        $this->assertSame('markup-only', $this->inlinedText($layouts),
            '짝 .json 을 굽지 않아도 조각이 해석된다');
    }

    /**
     * `src="*.g7.html"` 직접 참조도 해석된다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects partial_direct_markup_reference_resolves
     *
     * @return void 반환값 없음
     */
    public function test_partial_direct_markup_reference_resolves(): void
    {
        $this->writeHost('partials/frag.g7.html');
        $this->writePartialMarkup('frag', 'direct-markup');

        $layouts = $this->harness->run($this->layouts);

        $this->assertSame('direct-markup', $this->inlinedText($layouts),
            'src 가 소스 확장자를 직접 가리켜도 해석된다');
    }

    /**
     * `--layout-source=json` 이면 조각 우선순위도 뒤집힌다 (본 레이아웃과 같은 규약).
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects json_forced_source_makes_partial_json_win
     *
     * @return void 반환값 없음
     */
    public function test_json_forced_source_makes_partial_json_win(): void
    {
        $this->writeJsonHost('partials/frag.json');
        $this->writePartialMarkup('frag', 'from-markup');
        $this->writePartialJson('frag', 'from-json');

        config(['template.layout_markup.install_source' => 'json']);

        $layouts = $this->harness->run($this->layouts);

        $this->assertSame('from-json', $this->inlinedText($layouts),
            '진단·복구용 탈출구는 조각에도 같은 방향으로 적용된다');
    }

    /**
     * `--layout-source=json` + 마크업 전용 조각은 **누락되지 않는다**.
     *
     * 대안이 없을 때 조용히 떨어뜨리면 화면이 비므로 마크업을 그대로 컴파일한다 —
     * 본 레이아웃 `collectLayoutSources` 의 규약과 같다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects json_forced_source_keeps_markup_only_partials
     *
     * @return void 반환값 없음
     */
    public function test_json_forced_source_keeps_markup_only_partials(): void
    {
        $this->writeJsonHost('partials/frag.json');
        $this->writePartialMarkup('frag', 'markup-only');

        config(['template.layout_markup.install_source' => 'json']);

        $layouts = $this->harness->run($this->layouts);

        $this->assertSame('markup-only', $this->inlinedText($layouts),
            'JSON 강제라도 짝이 없으면 마크업으로 해석한다 (빈 화면 방지)');
    }

    /**
     * 조각 마크업 컴파일 실패는 **조각 해석 경로에서도** 설치 오류로 표면화된다.
     *
     * 계획서 test 7 은 이 경우를 "현행은 `app.debug=false` 에서 `[]`" 로 적었는데, 실측하면 절반만
     * 맞다 — 조각 소스는 `layouts/` 재귀 스캔(모든 설치 호출처가 `recursive: true`)에도 걸려
     * 독립 컴파일 오류로 이미 설치를 세운다. 조용히 사라지는 것은 **인라인 결과**뿐이었다.
     * 그래서 여기서는 오류 종류를 구별해, 조각 해석 경로가 자기 몫의 오류를 내는지 본다.
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects partial_compile_failure_surfaces_as_install_error
     *
     * @return void 반환값 없음
     */
    public function test_partial_markup_compile_failure_surfaces_as_install_error(): void
    {
        $this->writeHost('partials/frag.g7.html');
        // 컴파일은 템플릿과 무관하므로 "목록에 없는 태그" 는 오류가 아니다 — 문법 자체가 틀린 조각으로 실패를 만든다.
        File::put($this->layouts.'/partials/frag.g7.html',
            "<partial>\n  <div data-type=\"widget\"/>\n</partial>\n");

        $message = $this->expectInstallFailure();

        $this->assertStringContainsString('Failed to process partial', $message,
            '조각 해석 경로가 스캔과 별개로 자기 오류를 낸다 (인라인 침묵 금지)');
        $this->assertIncludeErrorKind('invalid_include_markup', $message);
    }

    /**
     * 조각 파일 부재는 설치 오류로 표면화된다.
     *
     * 종전에는 프로덕션에서 빈 배열이 되어 설치가 성공한 채 화면 일부가 사라졌다.
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects partial_missing_file_surfaces_as_install_error
     *
     * @return void 반환값 없음
     */
    public function test_partial_missing_file_surfaces_as_install_error(): void
    {
        $this->writeHost('partials/absent.g7.html');

        $message = $this->expectInstallFailure();

        $this->assertStringContainsString('Failed to process partial', $message,
            '조각 해석 경로가 자기 오류를 낸다 (경로 문자열만 확인하면 어떤 예외든 통과한다)');
        $this->assertIncludeErrorKind('include_file_not_found', $message);
    }

    /**
     * 조각 `.json` 파싱 실패는 설치 오류로 표면화된다 (짝 마크업이 없는 순수 JSON 조각).
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects partial_invalid_json_surfaces_as_install_error
     *
     * @return void 반환값 없음
     */
    public function test_partial_invalid_json_surfaces_as_install_error(): void
    {
        $this->writeHost('partials/broken.g7.html');
        File::put($this->layouts.'/partials/broken.json', '{ "type": "basic", ');

        $message = $this->expectInstallFailure();

        $this->assertStringContainsString('Failed to process partial', $message);
        $this->assertIncludeErrorKind('invalid_include_json', $message);
    }

    /**
     * 조각 순환 참조(A→B→A)는 설치 오류로 표면화된다.
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects partial_circular_reference_surfaces_as_install_error
     *
     * @return void 반환값 없음
     */
    public function test_partial_circular_reference_surfaces_as_install_error(): void
    {
        $this->writeHost('partials/a.g7.html');
        File::put($this->layouts.'/partials/a.g7.html',
            "<partial>\n  <div>\n    <include src=\"partials/b.g7.html\"/>\n  </div>\n</partial>\n");
        File::put($this->layouts.'/partials/b.g7.html',
            "<partial>\n  <div>\n    <include src=\"partials/a.g7.html\"/>\n  </div>\n</partial>\n");

        $message = $this->expectInstallFailure();

        $this->assertStringContainsString('Failed to process partial', $message);
        $this->assertIncludeErrorKind('circular_include', $message);
    }

    /**
     * `layouts/` 밖을 가리키는 조각 참조는 설치 오류로 표면화된다 (보안 검증 유지 + 침묵 제거).
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects partial_outside_layouts_surfaces_as_install_error
     *
     * @return void 반환값 없음
     */
    public function test_partial_outside_layouts_surfaces_as_install_error(): void
    {
        File::put($this->root.'/escaped.json', json_encode(['type' => 'basic', 'name' => 'Span']));
        $this->writeHost('../escaped.g7.html');

        $message = $this->expectInstallFailure();

        $this->assertStringContainsString('Failed to process partial', $message);
        $this->assertIncludeErrorKind('include_outside_directory', $message);
    }

    /**
     * 침묵 4갈래가 서로 **구별되는** 오류를 낸다.
     *
     * 위 넷은 종전에 공통 wrapper 문자열 하나(`Failed to process partial`)만 단언했다 — 그러면
     * ⓐ 를 ⓑ 로 바꿔도, 마크업 파싱 실패를 JSON 파싱 실패로 바꿔도 넷 다 초록을 유지한다.
     * "각 갈래가 자기 몫의 오류를 낸다" 를 관측하려면 갈래별 메시지가 서로 달라야 하므로,
     * 그 전제 자체를 여기서 잠근다 (키가 사라지거나 문구가 합쳐지면 red).
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects include_failure_kinds_are_mutually_distinguishable
     *
     * @return void 반환값 없음
     */
    public function test_include_failure_kinds_are_mutually_distinguishable(): void
    {
        $kinds = [
            'include_outside_directory',
            'circular_include',
            'include_file_not_found',
            'invalid_include_markup',
            'invalid_include_json',
        ];

        $prefixes = [];

        foreach ($kinds as $kind) {
            $prefix = $this->includeErrorPrefix($kind);

            $this->assertNotSame('', $prefix, "빈 오류 문구: {$kind}");
            $this->assertStringNotContainsString('exceptions.layout', $prefix,
                "번역 키가 그대로 나옵니다 (키 부재): exceptions.layout.{$kind}");

            $prefixes[$kind] = $prefix;
        }

        $this->assertSame(count($kinds), count(array_unique($prefixes)),
            '침묵 갈래 둘 이상이 같은 문구를 씁니다 — 그러면 갈래를 서로 구별하는 단언이 성립하지 않는다: '
            .json_encode($prefixes, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 설치 오류 메시지가 **그 갈래의** 예외에서 나왔는지 단언합니다.
     *
     * 공통 wrapper 문자열만 보면 네 갈래가 서로 구별되지 않는다 — 판정을 갈래별 번역 키에 건다.
     *
     * @param  string  $kind  `exceptions.layout.*` 키 이름
     * @param  string  $message  설치 오류 메시지
     * @return void 반환값 없음
     */
    private function assertIncludeErrorKind(string $kind, string $message): void
    {
        $prefix = $this->includeErrorPrefix($kind);

        $this->assertStringContainsString($prefix, $message,
            "이 갈래의 오류가 아닙니다 (기대: exceptions.layout.{$kind}) — 실제 메시지: {$message}");
    }

    /**
     * `exceptions.layout.*` 메시지에서 플레이스홀더 앞의 고정 문구를 뽑습니다.
     *
     * 영어 원문을 테스트에 하드코딩하면 문구를 고칠 때 테스트가 낡는다. 키에서 도출하면
     * 키가 사라졌을 때(번역 미정의) 그 자체로 red 가 된다.
     *
     * @param  string  $kind  `exceptions.layout.*` 키 이름
     * @return string 첫 플레이스홀더 앞까지의 문구
     */
    private function includeErrorPrefix(string $kind): string
    {
        return trim(explode(':', (string) __("exceptions.layout.{$kind}"), 2)[0]);
    }

    /**
     * 조각 안의 조각도 마크업 우선으로 재귀 해석된다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects nested_partial_markup_resolves_recursively
     *
     * @return void 반환값 없음
     */
    public function test_nested_partial_markup_resolves_recursively(): void
    {
        $this->writeHost('partials/outer.g7.html');
        File::put($this->layouts.'/partials/outer.g7.html',
            "<partial>\n  <div>\n    <include src=\"partials/inner.g7.html\"/>\n  </div>\n</partial>\n");
        $this->writePartialMarkup('inner', 'nested-markup');

        $layouts = $this->harness->run($this->layouts);

        $this->assertSame('nested-markup', $layouts[0]['data']['components'][0]['children'][0]['props']['text'] ?? null,
            '중첩 조각도 같은 규약으로 해석된다');
    }

    /**
     * 설치가 실패하는 것을 확인하고 그 오류 메시지를 돌려줍니다.
     *
     * `expectException` 대신 쓰는 이유는 오류 **종류**까지 단언해야 하기 때문이다 —
     * 그냥 예외가 났다는 사실만으로는 조각 해석 경로가 낸 것인지 구별되지 않는다.
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

        $this->fail('조각 해석 실패가 설치 오류로 표면화되지 않았습니다 (침묵 소멸).');
    }

    /**
     * 조각을 참조하는 호스트 레이아웃을 씁니다.
     *
     * @param  string  $src  `<include src>` 값
     * @return void 반환값 없음
     */
    private function writeHost(string $src): void
    {
        File::put($this->layouts.'/host.g7.html',
            "<layout name=\"host\" version=\"1.0.0\">\n  <include src=\"{$src}\"/>\n</layout>\n");
    }

    /**
     * 조각을 참조하는 **JSON 호스트**를 씁니다 (`.json` 참조 축 — S21.2 ⓑ).
     *
     * 소스는 소스를 가리키므로, `.json` 참조가 짝 마크업으로 올라가는 규약은 산출/설치 JSON 이
     * 참조하는 자리에서 재현한다. 마크업 호스트를 함께 쓰지 않는 이유는 병존 시 마크업이 이기기
     * 때문이다 — 그러면 이 축이 관측되지 않는다.
     *
     * @param  string  $partial  `partial` 참조 값
     * @return void 반환값 없음
     */
    private function writeJsonHost(string $partial): void
    {
        File::put($this->layouts.'/host.json', json_encode([
            'name' => 'host',
            'version' => '1.0.0',
            'components' => [['partial' => $partial]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 조각 컴파일 결과가 요청 단위로 캐시되고, 파일이 바뀌면 무효화된다 (34차 ⚠️ — 무관측 산출물).
     *
     * 사양 §683 이 이 캐시를 요구한 근거는 성능이다(조각 소스 451개가 485회 참조되므로 부모마다
     * 다시 컴파일하면 설치가 느려진다). 성능은 기능 단언으로 관측되지 않으므로 **캐시 키 계약**을
     * 관측한다 — 키가 `경로|mtime|크기` 이므로, 셋이 같으면 이전 산출을 돌려주고 하나라도 다르면
     * 다시 굽는다. 캐시 조회를 지우면 첫 축이, 키에서 mtime·크기를 빼면 둘째 축이 red 다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects partial_compile_result_is_cached_per_request_and_invalidated_by_file_change
     *
     * @return void 반환값 없음
     */
    public function test_partial_compile_result_is_cached_and_invalidated_by_change(): void
    {
        $this->writeHost('partials/frag.g7.html');
        $this->writePartialMarkup('frag', 'cached-v1');

        $path = $this->layouts.'/partials/frag.g7.html';
        $stamp = filemtime($path);

        $this->assertSame('cached-v1', $this->inlinedText($this->harness->run($this->layouts)),
            '첫 컴파일 결과가 인라인되지 않았다');

        // ⓐ 경로·mtime·크기가 모두 같으면 캐시가 이긴다 — 같은 길이로 내용만 바꾸고 시각을 되돌린다.
        $this->writePartialMarkup('frag', 'cached-v2');
        touch($path, $stamp);
        clearstatcache(true, $path);

        $this->assertSame('cached-v1', $this->inlinedText($this->harness->run($this->layouts)),
            '캐시 키가 같은데 다시 컴파일했다 — 요청 단위 캐시가 동작하지 않으면 조각 하나가 '
            .'부모 수만큼 반복 컴파일된다');

        // ⓑ 크기가 달라지면 무효화된다 — 캐시가 낡은 산출을 붙들지 않는다.
        $this->writePartialMarkup('frag', 'cached-v3-longer');
        clearstatcache(true, $path);

        $this->assertSame('cached-v3-longer', $this->inlinedText($this->harness->run($this->layouts)),
            '파일이 바뀌었는데 캐시가 낡은 산출을 돌려줬다');
    }

    /**
     * 조각 소스(`.g7.html`)을 씁니다.
     *
     * @param  string  $name  조각 베이스 이름
     * @param  string  $text  식별용 텍스트
     * @return void 반환값 없음
     */
    private function writePartialMarkup(string $name, string $text): void
    {
        File::put($this->layouts."/partials/{$name}.g7.html",
            "<partial>\n  <span text=\"{$text}\"/>\n</partial>\n");
    }

    /**
     * 조각 짝 `.json` 을 씁니다.
     *
     * @param  string  $name  조각 베이스 이름
     * @param  string  $text  식별용 텍스트
     * @return void 반환값 없음
     */
    private function writePartialJson(string $name, string $text): void
    {
        File::put($this->layouts."/partials/{$name}.json", json_encode([
            'meta' => ['is_partial' => true],
            'type' => 'basic',
            'name' => 'Span',
            'props' => ['text' => $text],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 인라인된 조각의 식별 텍스트를 꺼냅니다.
     *
     * @param  array<int, array<string, mixed>>  $layouts  검증 결과
     * @return string|null 텍스트
     */
    private function inlinedText(array $layouts): ?string
    {
        return $layouts[0]['data']['components'][0]['props']['text'] ?? null;
    }
}
