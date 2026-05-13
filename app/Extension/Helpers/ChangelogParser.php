<?php

namespace App\Extension\Helpers;

use Illuminate\Support\Facades\File;

/**
 * Changelog 파서 헬퍼
 *
 * CHANGELOG.md 파일을 파싱하여 버전별 구조화된 배열을 반환합니다.
 * Keep a Changelog (https://keepachangelog.com) 형식을 따릅니다.
 */
class ChangelogParser
{
    /**
     * CHANGELOG.md 파일을 파싱하여 버전별 구조화된 배열을 반환합니다.
     *
     * @param  string  $filePath  CHANGELOG.md 파일 경로
     * @return array 버전별 구조화된 배열
     */
    public static function parse(string $filePath): array
    {
        if (! File::exists($filePath)) {
            return [];
        }

        $content = File::get($filePath);

        if (empty(trim($content))) {
            return [];
        }

        return static::parseContent($content);
    }

    /**
     * 지정된 버전 범위(from 초과 ~ to 이하)의 엔트리를 추출합니다.
     *
     * @param  string  $filePath  CHANGELOG.md 파일 경로
     * @param  string  $fromVersion  시작 버전 (이 버전은 포함하지 않음)
     * @param  string  $toVersion  끝 버전 (이 버전은 포함)
     * @return array 범위 내 버전 엔트리 배열
     */
    public static function getVersionRange(string $filePath, string $fromVersion, string $toVersion): array
    {
        $entries = static::parse($filePath);

        if (empty($entries)) {
            return [];
        }

        return array_values(array_filter($entries, function (array $entry) use ($fromVersion, $toVersion) {
            $version = $entry['version'];

            return version_compare($version, $fromVersion, '>') && version_compare($version, $toVersion, '<=');
        }));
    }

    /**
     * 소스별(active/bundled) CHANGELOG.md 파일 경로를 결정합니다.
     *
     * active 소스에서 파일을 찾지 못하면 _bundled로 자동 폴백합니다.
     * (미설치 확장도 _bundled의 CHANGELOG를 표시할 수 있도록)
     *
     * @param  string  $basePath  확장 타입의 기본 경로 (예: base_path('modules'))
     * @param  string  $identifier  확장 식별자
     * @param  string|null  $source  소스 ('active', 'bundled', null=active)
     * @return string|null CHANGELOG.md 파일 경로 (파일 미존재 시 null)
     */
    public static function resolveChangelogPath(string $basePath, string $identifier, ?string $source = null): ?string
    {
        $source = $source ?? 'active';

        if ($source === 'bundled') {
            $filePath = $basePath.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.$identifier.DIRECTORY_SEPARATOR.'CHANGELOG.md';
        } else {
            $filePath = $basePath.DIRECTORY_SEPARATOR.$identifier.DIRECTORY_SEPARATOR.'CHANGELOG.md';
        }

        if (File::exists($filePath)) {
            return $filePath;
        }

        // active에서 못 찾으면 _bundled로 폴백
        if ($source === 'active') {
            $bundledPath = $basePath.DIRECTORY_SEPARATOR.'_bundled'.DIRECTORY_SEPARATOR.$identifier.DIRECTORY_SEPARATOR.'CHANGELOG.md';

            if (File::exists($bundledPath)) {
                return $bundledPath;
            }
        }

        return null;
    }

    /**
     * CHANGELOG.md 문자열 콘텐츠를 파싱합니다.
     *
     * @param  string  $content  파일 내용
     * @return array 버전별 구조화된 배열
     */
    public static function parseFromString(string $content): array
    {
        if (empty(trim($content))) {
            return [];
        }

        return static::parseContent($content);
    }

    /**
     * 문자열 콘텐츠에서 지정된 버전 범위(from 초과 ~ to 이하)의 엔트리를 추출합니다.
     *
     * @param  string  $content  CHANGELOG.md 파일 내용
     * @param  string  $fromVersion  시작 버전 (이 버전은 포함하지 않음)
     * @param  string  $toVersion  끝 버전 (이 버전은 포함)
     * @return array 범위 내 버전 엔트리 배열
     */
    public static function getVersionRangeFromString(string $content, string $fromVersion, string $toVersion): array
    {
        $entries = static::parseFromString($content);

        if (empty($entries)) {
            return [];
        }

        return array_values(array_filter($entries, function (array $entry) use ($fromVersion, $toVersion) {
            $version = $entry['version'];

            return version_compare($version, $fromVersion, '>') && version_compare($version, $toVersion, '<=');
        }));
    }

    /**
     * CHANGELOG.md 내용을 파싱합니다.
     *
     * @param  string  $content  파일 내용
     * @return array 버전별 구조화된 배열
     */
    private static function parseContent(string $content): array
    {
        $lines = explode("\n", $content);
        $entries = [];
        $currentEntry = null;
        $currentCategoryIndex = -1;

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            // ## [버전] - 날짜 패턴 (프리릴리스 지원: 7.0.0-alpha.1 등)
            if (preg_match('/^##\s+\[?(\d+\.\d+\.\d+(?:-[a-zA-Z0-9]+(?:\.[a-zA-Z0-9]+)*)?)\]?\s*(?:-\s*(.+))?$/', $trimmedLine, $matches)) {
                if ($currentEntry !== null) {
                    $entries[] = $currentEntry;
                }

                $currentEntry = [
                    'version' => $matches[1],
                    'date' => isset($matches[2]) ? trim($matches[2]) : null,
                    'categories' => [],
                ];
                $currentCategoryIndex = -1;

                continue;
            }

            // ### 카테고리 패턴
            if (preg_match('/^###\s+(.+)$/', $trimmedLine, $matches)) {
                if ($currentEntry !== null) {
                    $currentEntry['categories'][] = [
                        'name' => trim($matches[1]),
                        'items' => [],
                    ];
                    $currentCategoryIndex = count($currentEntry['categories']) - 1;
                }

                continue;
            }

            // - 항목 패턴
            if (preg_match('/^-\s+(.+)$/', $trimmedLine, $matches)) {
                if ($currentEntry !== null && $currentCategoryIndex >= 0) {
                    $currentEntry['categories'][$currentCategoryIndex]['items'][] = trim($matches[1]);
                }

                continue;
            }
        }

        // 마지막 엔트리 추가
        if ($currentEntry !== null) {
            $entries[] = $currentEntry;
        }

        return $entries;
    }
}
