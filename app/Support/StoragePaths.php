<?php

namespace App\Support;

/**
 * storage 경로 분류(`config/g7-storage.php`)를 읽고 경로가 어느 분류에 드는지 판정합니다.
 *
 * 분류는 넷이다 — 운영 데이터(`shared_data`), 서버별 파일(`server_local`), 업데이트 상태(`update_state`),
 * 임시 파일(`temporary`). 백업 대상이나 서버 간 공유 경로를 정할 때 쓰는 선언이며, 코어 동작은 이 분류로 바뀌지 않는다.
 */
final class StoragePaths
{
    /** 분류 이름 (출력 순서) */
    public const CATEGORIES = ['shared_data', 'server_local', 'update_state', 'temporary'];

    /**
     * 분류별 경로 목록을 반환합니다.
     *
     * @return array<string, list<string>> 분류 => `storage/` 기준 상대 경로 목록 (네 분류가 언제나 들어 있다)
     */
    public static function lists(): array
    {
        $lists = [];

        foreach (self::CATEGORIES as $category) {
            $paths = config('g7-storage.'.$category, []);
            $lists[$category] = array_values(array_map(
                static fn ($path): string => self::normalize((string) $path),
                is_array($paths) ? $paths : [],
            ));
        }

        return $lists;
    }

    /**
     * `storage/` 기준 상대 경로가 드는 분류를 반환합니다.
     *
     * 가장 길게 일치하는 항목의 분류다. 디렉터리 항목은 그 아래 전부와, `-`·`_` 로 끝나는 항목은 그 이름으로
     * 시작하는 경로와 일치한다.
     *
     * @param  string  $relativePath  `storage/` 기준 상대 경로 (예: app/settings/general.json → `storage/app/settings/general.json`)
     * @return string|null 분류 이름, 어느 목록에도 없으면 null
     */
    public static function classify(string $relativePath): ?string
    {
        $path = self::normalize($relativePath);
        if ($path === '') {
            return null;
        }

        $matched = null;
        $matchedLength = -1;

        foreach (self::lists() as $category => $entries) {
            foreach ($entries as $entry) {
                if (self::matches($path, $entry) && strlen($entry) > $matchedLength) {
                    $matched = $category;
                    $matchedLength = strlen($entry);
                }
            }
        }

        return $matched;
    }

    /**
     * 경로가 분류 항목에 드는지 판정합니다.
     *
     * @param  string  $path  정규화한 상대 경로
     * @param  string  $entry  정규화한 분류 항목
     * @return bool 들면 true
     */
    public static function matches(string $path, string $entry): bool
    {
        if ($entry === '') {
            return false;
        }

        if ($path === $entry || str_starts_with($path, $entry.'/')) {
            return true;
        }

        return (str_ends_with($entry, '-') || str_ends_with($entry, '_')) && str_starts_with($path, $entry);
    }

    /**
     * 상대 경로를 비교용으로 정규화합니다 (역슬래시 → `/`, 앞뒤 `/` 제거).
     *
     * @param  string  $path  상대 경로
     * @return string 정규화한 경로
     */
    public static function normalize(string $path): string
    {
        return trim(str_replace('\\', '/', trim($path)), '/');
    }
}
