<?php

namespace Tests\Unit\LanguagePack;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 번들 언어팩이 원본(ko)의 키를 하나도 빠뜨리지 않았는지 잰다.
 *
 * 누락된 키는 예외도 경고도 남기지 않는다 — 그 로케일에서 조용히 ko 로 폴백할 뿐이라,
 * 일본어 사이트에 한국어 문구가 섞여 나가는 것이 유일한 증상이다. 실제로 샘플 모듈의
 * 팩은 **화면 번역 파일 자체가 없었고**(11키), 그 원인은 팩 빌드 스크립트의 원본 목록이
 * 손으로 유지되는데 그 팩 한 줄만 빠져 있던 것이었다
 * (G8 실측 2026-09-02 · 계획서 §9.12.5 F10).
 *
 * 기존 동기 검사(`check-language-pack-sync`)는 매니페스트·구조만 보고 **키 파리티는 재지
 * 않는다.** 그래서 이 축에는 게이트가 없었다.
 *
 * 모집단은 디스크에서 도출한다 — 팩 목록도, 각 팩의 ko 원본 배치도 손으로 들지 않는다.
 * 원본을 찾지 못한 팩은 0 으로 접지 않고 **비교 불가로 따로 세어 실패시킨다**: 접으면
 * 「누락이 없다」와 「보지 않았다」가 구분되지 않는다(이 검사를 만들 때 실제로 그 형태로
 * 18팩이 공허하게 통과했다).
 *
 * 커버 축: language_pack_key_parity
 */
class BundledLanguagePackKeyParityTest extends TestCase
{
    /**
     * 중첩 배열을 점 표기 키 집합으로 편다.
     *
     * @param  array<mixed>  $data  원본 배열
     * @param  string  $prefix  키 접두사
     * @return array<string, true> 키 집합
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];

        foreach ($data as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix.'.'.$k;

            if (is_array($v)) {
                $out += $this->flatten($v, $key);

                continue;
            }

            $out[$key] = true;
        }

        return $out;
    }

    /**
     * 디렉토리의 PHP 언어 파일 키를 모은다 (파일명이 첫 세그먼트).
     *
     * @param  string  $dir  디렉토리 절대 경로
     * @return array<string, true> 키 집합
     */
    private function phpDir(string $dir): array
    {
        $out = [];

        foreach (glob(rtrim($dir, '/\\').DIRECTORY_SEPARATOR.'*.php') ?: [] as $file) {
            $data = include $file;

            if (is_array($data)) {
                $out += $this->flatten($data, basename($file, '.php'));
            }
        }

        return $out;
    }

    /**
     * JSON 파일의 키를 모은다.
     *
     * @param  string  $path  파일 절대 경로
     * @return array<string, true> 키 집합
     */
    private function jsonFile(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $this->flatten($data) : [];
    }

    /**
     * 디렉토리 하위 JSON 파일의 키를 모은다 (상대 경로가 첫 세그먼트).
     *
     * @param  string  $dir  디렉토리 절대 경로
     * @return array<string, true> 키 집합
     */
    private function jsonDir(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $out = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $f) {
            if ($f->getExtension() !== 'json') {
                continue;
            }

            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
            $rel = substr($rel, 0, -5);

            foreach ($this->jsonFile($f->getPathname()) as $k => $_) {
                $out[$rel.'.'.$k] = true;
            }
        }

        return $out;
    }

    /**
     * 팩이 담고 있는 키 집합.
     *
     * @param  string  $packDir  팩 디렉토리
     * @param  string  $locale  로케일
     * @return array<string, true> 키 집합
     */
    private function packKeys(string $packDir, string $locale): array
    {
        return $this->phpDir($packDir.'/backend/'.$locale)
            + $this->jsonFile($packDir.'/frontend/'.$locale.'.json')
            + $this->jsonDir($packDir.'/frontend/partial');
    }

    /**
     * 원본(ko) 키 집합. 확장 유형마다 배치가 다르므로 알려진 배치를 모두 훑는다.
     *
     * @param  string|null  $type  module|plugin|template, 코어면 null
     * @param  string|null  $id  확장 식별자
     * @return array<string, true> 키 집합
     */
    private function sourceKeys(?string $type, ?string $id): array
    {
        if ($type === null) {
            return $this->phpDir(base_path('lang/ko'))
                + $this->jsonFile(base_path('lang/ko.json'))
                + $this->jsonDir(base_path('lang/partial/ko'));
        }

        $base = base_path(match ($type) {
            'module' => 'modules/_bundled/'.$id,
            'plugin' => 'plugins/_bundled/'.$id,
            'template' => 'templates/_bundled/'.$id,
        });

        if (! is_dir($base)) {
            return [];
        }

        return $this->phpDir($base.'/src/lang/ko')
            + $this->phpDir($base.'/lang/ko')
            + $this->jsonFile($base.'/lang/ko.json')
            + $this->jsonDir($base.'/lang/partial/ko')
            + $this->jsonFile($base.'/resources/lang/ko.json')
            + $this->jsonDir($base.'/lang/partial/ko')
            + $this->jsonDir($base.'/resources/lang/partial/ko');
    }

    /**
     * @effects bundled_language_pack_covers_every_source_key
     */
    #[Test]
    public function test_every_bundled_pack_covers_its_source_keys(): void
    {
        $packs = glob(base_path('lang-packs/_bundled/g7-*')) ?: [];

        $this->assertNotEmpty($packs, '번들 언어팩을 하나도 찾지 못했다 — 모집단이 비면 이 검사는 아무것도 재지 않는다.');

        $compared = 0;
        $failures = [];

        foreach ($packs as $packDir) {
            $id = basename($packDir);

            if (! preg_match('~^g7-(?:(module|plugin|template)-(.+)|core)-([a-z]{2}(?:[-_][A-Za-z]+)?)$~', $id, $m)) {
                $failures[] = $id.': 팩 이름에서 대상·로케일을 읽지 못했다';

                continue;
            }

            $type = $m[1] !== '' ? $m[1] : null;
            $target = $m[1] !== '' ? $m[2] : null;
            $locale = $m[3];

            $source = $this->sourceKeys($type, $target);

            if ($source === []) {
                $failures[] = $id.': ko 원본을 찾지 못했다 — 비교 불가는 「누락 0」이 아니다';

                continue;
            }

            $missing = array_diff_key($source, $this->packKeys($packDir, $locale));
            $compared++;

            if ($missing !== []) {
                $sample = implode(', ', array_slice(array_keys($missing), 0, 5));
                $failures[] = sprintf('%s: 누락 %d건 (예: %s)', $id, count($missing), $sample);
            }
        }

        $this->assertGreaterThan(
            0,
            $compared,
            '비교에 성공한 팩이 하나도 없다 — 원본 탐색이 통째로 깨졌다는 뜻이다.'
        );

        $this->assertSame([], $failures, "번들 언어팩 키 파리티 위반:\n".implode("\n", $failures));
    }
}
