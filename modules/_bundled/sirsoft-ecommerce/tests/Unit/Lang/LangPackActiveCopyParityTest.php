<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Lang;

use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 언어팩 `_bundled` 원본의 키가 **활성 사본에도 있는가** (S2-D12 회귀 · #599 통합실측 세션2)
 *
 * **증상**: 일본어 사용자의 주문 이력에 날짜 줄이 이렇게 떴다.
 *
 * ```
 * mypage.order_detail.delivered_at      2026-09-15 14:02
 * mypage.order_detail.confirmed_at      2026-09-16 09:31
 * ↑ 라벨 자리에 원문 키
 * ```
 *
 * **원인**: 언어팩은 `lang-packs/_bundled/<pack>/`(원본)과 `lang-packs/<pack>/`(활성 사본)
 * 두 벌로 산다. **화면이 읽는 것은 활성 사본**이다. S2-D8 로 `delivered_at`·`confirmed_at`
 * 두 줄을 추가하며 ko·en 템플릿과 ja **원본**에는 넣었는데 ja **활성 사본**에 넣지 않아,
 * 고친 사람 화면(ko)에서는 정상이고 일본어 사용자에게만 키가 노출됐다.
 *
 * **이것이 세 번째다.** 같은 모양이 반복됐다 —
 *
 *   - S2-D11: `languages.ja` 라벨이 원본에만
 *   - 계획서준수검증 결함1: 구매확정 경고 ja 오역이 원본에만 수정됨
 *   - S2-D12: 이 건
 *
 * 앞의 둘은 **그 키를 아는 사람이 그 키만** 검사하는 가드로 닫았다. 그 방식은 다음에
 * 추가되는 키를 지키지 못한다 — 실제로 지키지 못해 이 건이 났다.
 *
 * 그래서 이 테스트는 **키를 열거하지 않는다.** `_bundled` 에 있는 키는 활성 사본에도
 * 있어야 한다는 **구조 계약**만 잰다. 앞으로 어떤 키가 늘어나든 한쪽만 고치면 red 가 선다.
 *
 * **값이 다른 것은 잡지 않는다** — 활성 사본은 운영자가 화면에서 번역을 덮어쓸 수 있는
 * 자리이고, 그 수정을 회귀로 다루면 정당한 운영 행위가 red 가 된다. 문제는 늘 **키의
 * 부재**(원문 키 노출)였지 값의 차이가 아니었다.
 *
 * @scenario langpack_copy=active_parity
 *
 * @effects every_bundled_langpack_key_exists_in_the_active_copy
 */
class LangPackActiveCopyParityTest extends ModuleTestCase
{
    /**
     * 저장소 루트.
     *
     * 단계 수를 고정하지 않는다 — 이 파일은 `_bundled` 원본과 활성 사본 양쪽에 복제되고
     * 두 경로의 깊이가 한 단계 다르다(2차 결함3 과 같은 이유).
     *
     * @return string 저장소 루트 절대 경로
     */
    private function repoRoot(): string
    {
        $dir = __DIR__;

        while (! is_file($dir.'/artisan') || ! is_file($dir.'/composer.json')) {
            $parent = dirname($dir);

            if ($parent === $dir) {
                $this->fail('저장소 루트를 찾지 못했습니다(artisan·composer.json 마커 부재) — 시작: '.__DIR__);
            }

            $dir = $parent;
        }

        return $dir;
    }

    /**
     * 중첩 배열을 `a.b.c` 형태의 평탄한 키 맵으로 폅니다.
     *
     * @param  array<string, mixed>  $data  원본 배열
     * @param  string  $prefix  상위 키 경로
     * @return array<string, scalar|null> 평탄화 결과
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $out += $this->flatten($value, $path);
            } else {
                $out[$path] = $value;
            }
        }

        return $out;
    }

    /**
     * 활성 사본이 설치된 언어팩의 `_bundled` JSON 파일을 모읍니다.
     *
     * 활성 사본이 아예 없는 패키지는 **미설치**이지 드리프트가 아니다 — 건너뛴다.
     *
     * @return array<int, array{pack: string, rel: string, bundled: string, active: string}>
     */
    private function langPackFilePairs(): array
    {
        $root = $this->repoRoot();
        $pairs = [];

        foreach (glob("{$root}/lang-packs/_bundled/*", GLOB_ONLYDIR) ?: [] as $bundledDir) {
            $pack = basename($bundledDir);
            $activeDir = "{$root}/lang-packs/{$pack}";

            if (! is_dir($activeDir)) {
                continue;
            }

            $base = str_replace('\\', '/', (string) realpath($bundledDir));
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($bundledDir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (strtolower($file->getExtension()) !== 'json') {
                    continue;
                }

                $rel = ltrim(substr(str_replace('\\', '/', (string) $file->getRealPath()), strlen($base)), '/');

                $pairs[] = [
                    'pack' => $pack,
                    'rel' => $rel,
                    'bundled' => (string) $file->getRealPath(),
                    'active' => "{$activeDir}/{$rel}",
                ];
            }
        }

        return $pairs;
    }

    /**
     * 검사 대상이 **실제로 존재**하는지 먼저 잠근다.
     *
     * 경로 규칙이 바뀌어 목록이 비면 아래 전수 검사는 「위반 0」으로 조용히 통과한다.
     * 그 초록은 아무것도 증명하지 않으므로 여기서 세운다.
     *
     * @scenario langpack_copy=active_parity
     *
     * @effects every_bundled_langpack_key_exists_in_the_active_copy
     */
    public function test_language_pack_pairs_are_discovered(): void
    {
        $pairs = $this->langPackFilePairs();

        $this->assertNotEmpty(
            $pairs,
            '언어팩 사본 쌍을 하나도 찾지 못했습니다 — 경로 규칙이 바뀌었다면 이 테스트가 '
                .'전수 검사를 못 한 채 초록이 됩니다. 탐색 기준: '.$this->repoRoot().'/lang-packs/'
        );

        $packs = array_unique(array_column($pairs, 'pack'));

        $this->assertContains(
            'g7-template-sirsoft-basic-ja',
            $packs,
            'S2-D12 가 났던 패키지가 검사 대상에 없습니다 — 회귀가 그대로 통과합니다.'
        );
    }

    /**
     * `_bundled` 에 있는 키는 **활성 사본에도 있어야 한다.**
     *
     * 없으면 화면에 원문 키가 뜬다 — 서버·DB 에는 흔적이 없고 그 언어 사용자만 본다.
     *
     * @scenario langpack_copy=active_parity
     *
     * @effects every_bundled_langpack_key_exists_in_the_active_copy
     */
    public function test_every_bundled_key_exists_in_the_active_copy(): void
    {
        $violations = [];

        foreach ($this->langPackFilePairs() as $pair) {
            if (! is_file($pair['active'])) {
                $violations[] = "{$pair['pack']}/{$pair['rel']} — 활성 사본 파일 자체가 없습니다.";

                continue;
            }

            $bundled = $this->flatten(json_decode((string) file_get_contents($pair['bundled']), true) ?: []);
            $active = $this->flatten(json_decode((string) file_get_contents($pair['active']), true) ?: []);

            $missing = array_keys(array_diff_key($bundled, $active));

            if ($missing !== []) {
                $shown = array_slice($missing, 0, 5);
                $more = count($missing) > 5 ? ' 외 '.(count($missing) - 5).'건' : '';
                $violations[] = "{$pair['pack']}/{$pair['rel']} — 활성 사본에 없는 키 "
                    .count($missing).'건: '.implode(', ', $shown).$more;
            }
        }

        $this->assertSame(
            [],
            $violations,
            "언어팩 활성 사본에 키가 빠져 있습니다 — 그 언어 사용자의 화면에 원문 키가 그대로 뜹니다\n"
                ."(화면이 읽는 것은 `_bundled` 원본이 아니라 활성 사본입니다. S2-D8→S2-D12 가 그 경로였습니다).\n\n"
                .implode("\n", $violations)
        );
    }
}
