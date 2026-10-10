<?php

namespace Tests\Unit\Support\LayoutMarkup;

use App\Extension\Traits\ValidatesLayoutFiles;
use App\Support\LayoutMarkup\LayoutCorpus;
use Illuminate\Support\Facades\File;

/**
 * 코퍼스의 조각 참조가 전부 **소스 SSoT** 로 해석되는지 전수 봉인 (S18 P1).
 *
 * S18 P1 의 D38 동작 불변 실측은 "조각 참조가 전부 소스로 해석된다" 였는데, 그 값이 계획서
 * 산문에만 있고 **모집단 정의가 어디에도 없었다**. 값이 산문에 박히면 두 가지가 같이 일어난다 —
 * 다음 사람이 재집계할 수 없고, 규약이 변해도 아무 실패 모드가 없다. 그래서 값을 적는 대신
 * **성질을 단언한다**.
 *
 * 모집단 정의가 결과를 좌우하므로 여기 못박는다:
 *
 *  - 대상 파일: {@see LayoutCorpus::layoutRoots()} 아래 `.g7.html` **재귀 전량**(조각 자신 포함 —
 *    조각이 조각을 참조하는 자리가 실재한다).
 *  - 대상 참조: `<include src>` **와** `<modal src>` 둘 다. 둘 다 산출 JSON 에서 `partial` 키가 되어
 *    같은 해석기({@see ValidatesLayoutFiles::resolvePartial()})를 탄다. 27차 실측이 `<include>` 만
 *    세어 **213건(`<modal src>`)을 빠뜨린 채 272 로 기록**했다 — 전수는 485다.
 *
 * 숫자를 단언하지 않는 이유: 이관·신규 화면으로 참조 수는 정상 작업에서도 늘고 준다. 대신
 * ⓐ 후보 0건이면 red ⓑ 해석 실패 하드 0 ⓒ 짝 소스가 실재하는 참조는 전량 소스 해석을 본다.
 */
class PartialReferenceCorpusTest extends LayoutMarkupTestCase
{
    /**
     * 조각 참조 전량이 해석되고, 소스가 있으면 소스가 이긴다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects corpus_partial_references_resolve_to_authoring_sources
     */
    public function test_every_corpus_partial_reference_resolves_to_its_authoring_source(): void
    {
        $harness = new class
        {
            use ValidatesLayoutFiles;

            /**
             * @return array{path: string, markup: bool, exists: bool}
             */
            public function source(string $reference, string $basePath): array
            {
                return $this->resolvePartialSource($reference, $basePath);
            }
        };

        $references = 0;
        $unresolved = [];
        $jsonDespiteMarkup = [];
        $referencesPerRoot = [];

        foreach ($this->corpusMarkupFilesByRoot() as $rootGlob => $rootFiles) {
            $referencesPerRoot[$rootGlob] = 0;

            foreach ($rootFiles as $file) {
                $basePath = dirname($file);

                foreach ($this->partialReferencesIn(File::get($file)) as $reference) {
                    $references++;
                    $referencesPerRoot[$rootGlob]++;

                    try {
                        $source = $harness->source($reference, $basePath);
                    } catch (\Throwable $e) {
                        $unresolved[] = $this->relative($file).' → '.$reference.' ('.class_basename($e).')';

                        continue;
                    }

                    if (! $source['exists']) {
                        $unresolved[] = $this->relative($file).' → '.$reference.' (부재)';

                        continue;
                    }

                    // 소스가 실재하는데 `.json` 이 선택됐다면 우선순위 규약이 뒤집힌 것이다.
                    if (! $source['markup'] && File::exists(preg_replace('/\.json$/i', '.g7.html', $source['path']))) {
                        $jsonDespiteMarkup[] = $this->relative($file).' → '.$reference;
                    }
                }
            }
        }

        // 하한은 **루트별**이다 (31차). 전역 하나면 한 루트가 통째로 관측 밖이 되어도 나머지가 채워
        // 침묵한다 — `FragmentSourceCopyGuardTest` 가 29차에 템플릿 축을 그렇게 잃었던 형태다.
        foreach ($referencesPerRoot as $rootGlob => $count) {
            $this->assertGreaterThan(0, $count,
                "이 레이아웃 루트에서 조각 참조가 0건 도출됐다: {$rootGlob} — 순회가 그 축에 도달하지 "
                .'못하는 상태인지, 참조 표기가 바뀌었는지 확인해야 한다');
        }

        $this->assertGreaterThan(0, $references,
            '조각 참조 후보가 0건이다 — 모집단 도출이 죽었는지 먼저 확인해야 한다 ("위반 0" 과 "검사 0" 은 다른 명제다)');

        $this->assertSame([], $unresolved,
            '해석되지 않는 조각 참조가 있습니다 (설치 시점에 오류로 설치를 세운다).');

        $this->assertSame([], $jsonDespiteMarkup,
            '소스가 실재하는데 `.json` 이 선택된 참조가 있습니다 — 소스 SSoT 가 두 벌로 갈라진 상태입니다.');
    }

    /**
     * 코퍼스 본 레이아웃 루트별로 `.g7.html` 을 재귀 도출합니다.
     *
     * 루트를 키로 유지하는 이유는 관측 하한을 **축별로** 걸기 위해서다 — 전역 하한 하나면 한 루트가
     * 통째로 순회에서 빠져도 나머지가 채워 침묵한다.
     *
     * @return array<string, array<int, string>> 루트 글롭 → 절대 경로 목록
     */
    private function corpusMarkupFilesByRoot(): array
    {
        $byRoot = [];

        foreach (LayoutCorpus::layoutRoots() as $rootGlob) {
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
     * @return array<int, string> 참조 값 목록 (중복 포함 — 참조 수를 세는 것이 목적이다)
     */
    private function partialReferencesIn(string $content): array
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
