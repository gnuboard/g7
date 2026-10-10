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

        // 이어 쓴 줄을 붙일 항목의 위치(현재 카테고리 items 의 인덱스). 항목 밖이면 null.
        $openItemIndex = null;
        // 바로 앞 줄이 빈 줄이었는지 — 빈 줄 뒤에는 들여쓴 줄만 같은 항목으로 본다.
        $afterBlank = false;
        // 코드 블록 안인지, 그 코드 블록이 항목 안에서 열렸는지.
        $inFence = false;
        $fenceInItem = false;

        foreach ($lines as $line) {
            $line = rtrim($line, "\r");
            $trimmedLine = trim($line);
            $indented = $trimmedLine !== '' && preg_match('/^\s/', $line) === 1;

            // ``` 코드 블록 — 항목 안(들여쓴 울타리)이면 내용을 항목에 이어 붙이고, 항목 밖이면 통째로 건너뛴다.
            // 코드 블록 안의 `- `·`##` 줄을 항목·버전으로 잘못 읽지 않게 하는 것이 목적이다.
            if (str_starts_with($trimmedLine, '```')) {
                if (! $inFence) {
                    $inFence = true;
                    $fenceInItem = $openItemIndex !== null && ($indented || ! $afterBlank);
                    if (! $fenceInItem) {
                        $openItemIndex = null;
                    }
                } else {
                    $inFence = false;
                }
                $afterBlank = false;

                continue;
            }
            if ($inFence) {
                if ($fenceInItem && $trimmedLine !== '') {
                    static::appendToItem($currentEntry, $currentCategoryIndex, $openItemIndex, $trimmedLine);
                }

                continue;
            }

            if ($trimmedLine === '') {
                $afterBlank = true;

                continue;
            }

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
                $openItemIndex = null;
                $afterBlank = false;

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
                $openItemIndex = null;
                $afterBlank = false;

                continue;
            }

            // 그 밖의 제목(#, ##, ####…)은 항목을 끝낸다 — 앞 항목 글에 붙이지 않는다.
            if (preg_match('/^#{1,6}\s/', $trimmedLine)) {
                $openItemIndex = null;
                $afterBlank = false;

                continue;
            }

            // - 항목 패턴 (들여쓴 중첩 항목도 따로 한 항목으로 둔다)
            if (preg_match('/^-\s+(.+)$/', $trimmedLine, $matches)) {
                $openItemIndex = null;
                if ($currentEntry !== null && $currentCategoryIndex >= 0) {
                    $currentEntry['categories'][$currentCategoryIndex]['items'][] = trim($matches[1]);
                    $openItemIndex = count($currentEntry['categories'][$currentCategoryIndex]['items']) - 1;
                }
                $afterBlank = false;

                continue;
            }

            // 이어 쓴 줄 — 항목 바로 밑 줄(들여쓰기 무관), 또는 빈 줄 뒤라도 들여쓴 줄이면 같은 항목의 다음 문단이다.
            // 빈 줄 뒤 들여쓰지 않은 문단은 항목 밖 안내문이라 항목을 끝낸다.
            if ($openItemIndex !== null && (! $afterBlank || $indented)) {
                static::appendToItem($currentEntry, $currentCategoryIndex, $openItemIndex, $trimmedLine);
            } else {
                $openItemIndex = null;
            }
            $afterBlank = false;
        }

        // 마지막 엔트리 추가
        if ($currentEntry !== null) {
            $entries[] = $currentEntry;
        }

        return $entries;
    }

    /**
     * 열린 항목 뒤에 이어 쓴 줄을 공백 하나로 붙입니다.
     *
     * @param  array|null  $currentEntry  현재 버전 엔트리 (참조)
     * @param  int  $categoryIndex  현재 카테고리 인덱스
     * @param  int|null  $itemIndex  이어 붙일 항목 인덱스
     * @param  string  $text  앞뒤 공백을 뺀 줄
     * @return void 반환값 없음
     */
    private static function appendToItem(?array &$currentEntry, int $categoryIndex, ?int $itemIndex, string $text): void
    {
        if ($currentEntry === null || $categoryIndex < 0 || $itemIndex === null) {
            return;
        }

        $item = &$currentEntry['categories'][$categoryIndex]['items'][$itemIndex];
        $item = $item === '' ? $text : $item.' '.$text;
    }
}
