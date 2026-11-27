<?php

namespace App\Extension\Helpers;

/**
 * 확장 웹 업데이트의 수동 ZIP 대기 폴더 (7.2.1 출시 전).
 *
 * 터미널은 `{type}:update --zip=<경로>` 로 아무 경로나 받지만, 웹은 경로를 받지 않는다. 운영자가 FTP 로 서버가 정한 폴더
 * (`config('app.update.extension_pending_path')`)에 ZIP 을 올리고, 화면은 그 폴더의 **파일 이름만** 고른다 — 코어 웹 업데이트의
 * 수동 ZIP(`CoreUpdateOrchestrator::manualSources()`)과 같은 방식이다.
 */
final class ExtensionManualZipSources
{
    /**
     * 대기 폴더 경로를 돌려줍니다.
     *
     * @return string 대기 폴더 절대 경로 (설정이 비면 빈 문자열)
     */
    public static function directory(): string
    {
        return (string) config('app.update.extension_pending_path', '');
    }

    /**
     * 대기 폴더에 올라온 ZIP 목록을 돌려줍니다 (최근 수정 순).
     *
     * @return list<array{name: string, size: int, modified_at: string}> ZIP 파일 목록
     */
    public static function list(): array
    {
        $directory = self::directory();

        if ($directory === '' || ! is_dir($directory)) {
            return [];
        }

        $sources = [];

        foreach ((array) @scandir($directory) as $entry) {
            if (! is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;

            if (! is_file($path) || strtolower((string) pathinfo($entry, PATHINFO_EXTENSION)) !== 'zip') {
                continue;
            }

            $sources[] = [
                'name' => $entry,
                'size' => (int) @filesize($path),
                'modified_at' => date('c', (int) @filemtime($path)),
            ];
        }

        usort($sources, static fn (array $a, array $b): int => strcmp($b['modified_at'], $a['modified_at']));

        return $sources;
    }

    /**
     * 파일 이름을 대기 폴더 안의 절대 경로로 바꿉니다.
     *
     * 이름만 받는다 — 경로 구분자가 든 이름, 폴더 밖을 가리키는 결과(심볼릭 링크 포함), ZIP 이 아닌 파일은 거부한다.
     *
     * @param  string  $name  대기 폴더 안의 파일 이름
     * @return string|null 절대 경로 (없거나 범위를 벗어나면 null)
     */
    public static function resolve(string $name): ?string
    {
        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || $name === '.' || $name === '..') {
            return null;
        }

        if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
            return null;
        }

        $directory = realpath(self::directory());

        if ($directory === false) {
            return null;
        }

        $path = realpath($directory.DIRECTORY_SEPARATOR.$name);

        if ($path === false || ! is_file($path) || ! str_starts_with($path, $directory.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $path;
    }
}
