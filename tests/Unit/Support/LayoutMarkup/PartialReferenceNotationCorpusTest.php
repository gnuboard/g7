<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Extension\Traits\ValidatesLayoutFiles;
use App\Support\LayoutMarkup\LayoutCorpus;
use App\Support\LayoutMarkup\PartialReferenceNotation;
use Illuminate\Support\Facades\File;

/**
 * 조각 참조 표기 규약을 코퍼스 전량에 대해 봉인한다 (S21.2 ⓑ · U-REFEXT 안 B).
 *
 * ## 규약 (설계 결정 2026-08-02)
 *
 * 소스(`.g7.html`)은 **소스를** 가리키고, 산출 JSON 은 **JSON 문법을 100% 유지**한다 —
 * 즉 경계(컴파일러·역컴파일러)에서 확장자를 정규화한다.
 *
 * ```
 * 작성:  <include src="_x.g7.html"/>      <modal src="_x.g7.html"/>
 * 산출:  {"partial": "_x.json"}           modals[] = {"partial": "_x.json"}
 * ```
 *
 * ## 왜 왕복 게이트로는 부족한가 — 이 파일이 존재하는 이유
 *
 * 안 B 는 양방향 매핑을 신설하므로 **매핑이 낸 이름이 실재하지 않아도 조용히 통과하는 경로**가
 * 생긴다. 왕복 게이트는 `JSON → 마크업 → JSON` 이라 매핑이 정확한 역함수이기만 하면
 * 항등이 성립하고, 그 값이 디스크의 무엇을 가리키는지는 **묻지 않는다**. 짝 `.json` 이 없는
 * 새 조각이 하나 생기면 산출 JSON 은 없는 파일명을 적고 왕복은 초록이다.
 *
 * 그래서 여기서 **왕복이 구조적으로 볼 수 없는 세 가지**를 따로 단언한다 (계획서 신규 회귀 ㄱㄴㄷ):
 *
 *  - (ㄱ) 소스의 `src` 는 전량 소스 확장자(`.g7.html`) 표기다.
 *  - (ㄴ) 그 `src` 가 가리키는 소스가 디스크에 실재한다.
 *  - (ㄷ) **컴파일러가 실제로 산출할 `.json` 이름**이 디스크에 실재한다 — 매핑 결과를 직접 잰다.
 *
 * 세 단언 모두 모집단을 **전수 스캔으로 도출**한다. 파일 목록을 손으로 적으면 새 소스가
 * 생겼을 때 규약 밖에 있는 채로 관측에서 빠진다({@see CommentAnchorStageLedgerTest} 와 같은 이유).
 *
 * 참조가 **해석되는가**(설치 시점 우선순위 규약)는 {@see PartialReferenceCorpusTest} 가 이미
 * 잰다 — 그쪽은 해석기의 대칭 후보를 태우므로 표기와 무관하게 참이고, 이쪽은 표기 그 자체와
 * 매핑 산출물의 실재를 잰다. 둘은 다른 명제이므로 합치지 않는다.
 */
class PartialReferenceNotationCorpusTest extends LayoutMarkupTestCase
{
    /**
     * 코퍼스 참조 수의 하한 — 스캔이 죽으면 "위반 0" 이 아니라 "검사 0" 이다. 그 둘을 가른다.
     */
    private const REFERENCE_MINIMUM = 400;

    /**
     * 소스의 조각 참조가 전량 소스 확장자 표기이고, 그 짝이 양쪽 다 실재한다.
     *
     * @scenario compile_unit=layout,source_health=valid
     *
     * @effects corpus_partial_references_use_authoring_extension_and_both_pairs_exist
     */
    public function test_every_corpus_reference_uses_authoring_notation_with_both_pairs_present(): void
    {
        // 참조 경로 해석은 **프로덕션 해석기**에 위임한다 — 형제 디렉토리 조인이 아니라
        // `partials/` 접두 시 layouts 루트로 떨어지는 fallback 이 있고(`resolvePartialPath`),
        // 그 규칙을 테스트가 복제하면 여기서만 참인 결론이 나온다.
        $resolver = new class
        {
            use ValidatesLayoutFiles;

            public function absolute(string $reference, string $basePath): ?string
            {
                try {
                    return $this->resolvePartialPath($reference, $basePath);
                } catch (\Throwable) {
                    return null;
                }
            }
        };

        $wrongNotation = [];
        $missingMarkup = [];
        $missingJson = [];
        $references = 0;
        $perRoot = [];
        $filesPerRoot = [];

        foreach ($this->corpusMarkupFilesByRoot() as $rootGlob => $files) {
            $perRoot[$rootGlob] = 0;
            $filesPerRoot[$rootGlob] = count($files);

            foreach ($files as $file) {
                $base = dirname($file);

                foreach ($this->referencesIn(File::get($file)) as $reference) {
                    $references++;
                    $perRoot[$rootGlob]++;
                    $where = $this->relative($file).' → '.$reference;

                    // (ㄱ) 표기 규약.
                    if (! PartialReferenceNotation::isAuthoringNotation($reference)) {
                        $wrongNotation[] = $where;

                        continue;
                    }

                    // (ㄴ) 가리키는 소스 실재.
                    $markupPath = $resolver->absolute($reference, $base);

                    if ($markupPath === null || ! File::exists($markupPath)) {
                        $missingMarkup[] = $where;
                    }

                    // (ㄷ) 컴파일러가 산출할 이름 실재 — 매핑 결과를 직접 잰다.
                    $emitted = PartialReferenceNotation::toJson($reference);
                    $jsonPath = $resolver->absolute($emitted, $base);

                    if ($jsonPath === null || ! File::exists($jsonPath)) {
                        $missingJson[] = $where.' ⇒ '.$emitted;
                    }
                }
            }
        }

        // 하한은 루트별이다 — 전역 하나면 한 루트가 통째로 관측 밖이 되어도 나머지가 채워 침묵한다.
        //
        // 두 층으로 나눈다. **파일 도달**은 fragment 루트를 포함한 전 루트에 요구하고(순회가 그 축에
        // 닿았는가), **참조 도출**은 본 레이아웃 루트에만 요구한다. fragment 루트는 오늘 조각 참조가
        // 0건이라 참조 하한을 걸면 상시 red 지만, 파일 하한이 없으면 순회가 죽어도 침묵한다 —
        // fragment 에 첫 `<include|modal src>` 가 생기는 날 (ㄴ)(ㄷ) 가 그 파일을 못 보는 것이
        // 이 가드가 닫으려던 사각이다.
        $referenceBearingRoots = LayoutCorpus::layoutRoots();

        foreach ($filesPerRoot as $rootGlob => $count) {
            $this->assertGreaterThan(0, $count,
                "이 루트에서 `.g7.html` 이 0건 도출됐다: {$rootGlob} — 순회가 그 축에 도달하지 못하는 상태다");
        }

        foreach ($perRoot as $rootGlob => $count) {
            if (! in_array($rootGlob, $referenceBearingRoots, true)) {
                continue;
            }

            $this->assertGreaterThan(0, $count,
                "이 레이아웃 루트에서 조각 참조가 0건 도출됐다: {$rootGlob} — 순회가 그 축에 도달하지 못하는 상태다");
        }

        $this->assertGreaterThanOrEqual(self::REFERENCE_MINIMUM, $references,
            '조각 참조 도출이 '.$references.'건이다 — 모집단이 무너지면 아래 단언이 공허하게 참이 된다');

        $this->assertSame([], $wrongNotation,
            '조각 참조가 소스 확장자(`.g7.html`)가 아닌 표기를 쓴다 — 소스는 소스를 가리킨다 '
            .'(산출 JSON 의 `.json` 표기는 컴파일러가 경계에서 만든다):'
            .PHP_EOL.implode(PHP_EOL, $wrongNotation));

        $this->assertSame([], $missingMarkup,
            '참조가 가리키는 소스가 디스크에 없다:'.PHP_EOL.implode(PHP_EOL, $missingMarkup));

        $this->assertSame([], $missingJson,
            '컴파일러가 산출할 `.json` 이름이 디스크에 없다 — 왕복 게이트(JSON→마크업→JSON)는 '
            .'매핑이 역함수이기만 하면 항등이라 이 자리를 구조적으로 보지 못한다:'
            .PHP_EOL.implode(PHP_EOL, $missingJson));
    }

    /**
     * 코퍼스 루트별로 `.g7.html` 을 재귀 도출합니다 — 본 레이아웃 + 확장 fragment 둘 다.
     *
     * fragment(`extensions/`)를 포함하는 이유는 오늘의 실측이 아니라 **내일의 사각**이다. 지금
     * fragment 의 조각 참조는 0건이지만 `<include src>`·`<modal src>` 는 그 자리에서도 문법상
     * 성립하므로, 모집단을 본 레이아웃으로 좁혀 두면 첫 참조가 생기는 날 (ㄴ)(ㄷ) 검사가 그 파일을
     * 조용히 지나친다. audit 룰은 `**\/extensions\/**` 를 보지만 그것은 (ㄱ) 표기만 맡는다.
     *
     * @return array<string, array<int, string>> 루트 글롭 → 절대 경로 목록
     */
    private function corpusMarkupFilesByRoot(): array
    {
        $byRoot = [];

        foreach (LayoutCorpus::roots() as $rootGlob) {
            $byRoot[$rootGlob] = [];

            foreach (File::glob($rootGlob) ?: [] as $root) {
                if (! File::isDirectory($root)) {
                    continue;
                }

                foreach (File::allFiles($root) as $file) {
                    if (str_ends_with($file->getFilename(), '.g7.html')) {
                        $byRoot[$rootGlob][] = $file->getPathname();
                    }
                }
            }
        }

        return $byRoot;
    }

    /**
     * 소스에서 조각 참조를 뽑습니다 — `<include src>` 와 `<modal src>` 둘 다.
     *
     * @param  string  $content  `.g7.html` 내용
     * @return array<int, string> 참조 값 목록 (중복 포함)
     */
    private function referencesIn(string $content): array
    {
        preg_match_all('/<(?:include|modal)\s[^>]*src="([^"]+)"/i', $content, $matches);

        return $matches[1];
    }

    /**
     * 절대 경로를 저장소 상대 경로로 바꿉니다.
     *
     * @param  string  $path  절대 경로
     * @return string 상대 경로
     */
    private function relative(string $path): string
    {
        return str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
    }
}
