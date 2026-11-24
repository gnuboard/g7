<?php

namespace Tests\Concerns;

use ZipArchive;

/**
 * 실제 배포 도구가 만드는 모양의 ZIP 픽스처를 만든다.
 *
 * `ZipArchive::addFromString()` 만 쓰면 파일 항목만 생겨, 실제 압축 파일이 가진 모양을 재현하지 못한다.
 * 그 공백 때문에 GitHub 소스 ZIP(감싼 폴더 `g7-7.2.0/` 의 **폴더 항목이 맨 앞**)을 브라우저 압축 풀기가
 * 「배포본 아님」으로 거부하는 결함이 green 테스트 아래에서 출시됐다(#971). 옵션으로 고르는 모양:
 *
 * - `wrapper`: 모든 항목을 감쌀 최상위 폴더 이름(끝 `/` 없이). 기본 없음.
 * - `directoryEntries`: true 면 감싼 폴더와 모든 중간 폴더의 폴더 항목(`이름/`)을 **파일보다 먼저** 넣는다(GitHub 모양).
 * - `macosx`: true 면 Mac Finder 압축이 붙이는 `__MACOSX/` 부속 항목(`__MACOSX/<wrapper>/._<파일>`)을 덧붙인다.
 * - `backslash`: true 면 항목 이름의 구분자를 `\` 로 저장한다(Windows PowerShell 5.1 모양). 폴더 항목은 `이름\`.
 */
trait BuildsZipFixtures
{
    /**
     * 옵션대로 ZIP 픽스처를 만든다.
     *
     * @param  string  $path  만들 ZIP 경로 (있으면 덮어쓴다)
     * @param  array<string, string>  $files  감싼 폴더 기준 상대 경로 => 내용
     * @param  array{wrapper?: string, directoryEntries?: bool, macosx?: bool, backslash?: bool}  $options  모양 옵션
     * @return void 반환값 없음
     */
    protected function makeZipFixture(string $path, array $files, array $options = []): void
    {
        $wrapper = trim((string) ($options['wrapper'] ?? ''), '/');
        $prefix = $wrapper === '' ? '' : $wrapper.'/';
        $separator = ! empty($options['backslash']) ? '\\' : '/';
        $name = static fn (string $slashed): string => str_replace('/', $separator, $slashed);

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('ZIP 픽스처를 만들지 못했다: '.$path);
        }

        if (! empty($options['directoryEntries'])) {
            foreach ($this->zipFixtureDirectories(array_keys($files), $prefix) as $directory) {
                // addEmptyDir 은 끝에 `/` 를 붙인다 — 역슬래시 모양은 이름 그대로 0바이트 항목으로 넣는다.
                $zip->addFromString($name($directory), '');
            }
        }

        foreach ($files as $relative => $content) {
            $zip->addFromString($name($prefix.$relative), $content);
        }

        if (! empty($options['macosx'])) {
            $zip->addFromString($name('__MACOSX/'), '');
            if ($prefix !== '') {
                $zip->addFromString($name('__MACOSX/'.$prefix), '');
            }
            foreach (array_keys($files) as $relative) {
                $dir = dirname($relative);
                $dot = ($dir === '.' ? '' : $dir.'/').'._'.basename($relative);
                $zip->addFromString($name('__MACOSX/'.$prefix.$dot), "\x00\x05\x16\x07AppleDouble");
            }
        }

        $zip->close();
    }

    /**
     * 파일 목록이 필요로 하는 폴더 항목 이름을 위에서부터 차례로 돌려준다 (끝 `/` 포함).
     *
     * @param  array<int, string>  $relatives  감싼 폴더 기준 파일 경로 목록
     * @param  string  $prefix  감싼 폴더 접두사 (`g7-9.9.9/` 또는 빈 문자열)
     * @return array<int, string> 폴더 항목 이름 목록 (중복 없음, 얕은 것부터)
     */
    private function zipFixtureDirectories(array $relatives, string $prefix): array
    {
        $directories = $prefix === '' ? [] : [$prefix];

        foreach ($relatives as $relative) {
            $segments = explode('/', $relative);
            array_pop($segments);
            $path = $prefix;
            foreach ($segments as $segment) {
                $path .= $segment.'/';
                if (! in_array($path, $directories, true)) {
                    $directories[] = $path;
                }
            }
        }

        return $directories;
    }
}
