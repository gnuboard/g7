<?php

namespace App\Extension\Helpers;

use App\Extension\Exceptions\UnsafeArchiveException;

/**
 * zip 을 풀지 않고 항목 이름으로 읽기 위한 색인.
 *
 * 업데이트 사전 점검(번들 인벤토리·PHP 확장 요구·개발자 예약 자리)은 배포본 zip 을 풀기 전에 안의 파일 몇 개를
 * 읽는다. 그때 같은 규칙을 지켜야 실제 배포 도구가 만든 zip 을 모두 읽는다.
 *
 * - 이름은 해제기(`SafeArchiveExtractor::normalizeEntryName`)와 같이 정규화한다 — Windows 압축 도구의 `\` 구분자.
 * - 읽기는 정규화한 이름이 아니라 **원본 인덱스**로 한다. 정규화한 이름으로 `getFromName()` 하면
 *   원본(`config\app.php`)과 달라 조용히 「없음」이 된다.
 * - Mac Finder 압축의 최상위 `__MACOSX/` 부속 항목과 압축 밖을 가리키는 위험한 이름은 색인에 넣지 않는다
 *   (읽기 전용 판정이라 거부는 해제기의 몫이다).
 * - 감싼 폴더(GitHub 소스 zip 의 `g7-7.2.0/`)는 폴더 항목까지 포함해 모든 항목이 같은 최상위 폴더 하나를
 *   공유할 때만 감싼 것으로 본다.
 *
 * @since 7.2.1
 */
final class ZipEntryIndex
{
    /**
     * @param  \ZipArchive  $zip  열린 아카이브 (닫는 것은 호출자 몫)
     * @param  array<string, int>  $fileIndexes  정규화 파일 이름 => 원본 인덱스
     * @param  array<int, string>  $tops  항목들의 최상위 구간 (중복 없음)
     * @param  bool  $hasRootFile  최상위에 파일 항목이 있는지
     */
    private function __construct(
        private readonly \ZipArchive $zip,
        private readonly array $fileIndexes,
        private readonly array $tops,
        private readonly bool $hasRootFile,
    ) {}

    /**
     * 열린 아카이브의 항목 이름을 색인합니다.
     *
     * @param  \ZipArchive  $zip  열린 아카이브 (색인을 쓰는 동안 열어 두어야 한다)
     * @return self 색인
     */
    public static function fromArchive(\ZipArchive $zip): self
    {
        $fileIndexes = [];
        $tops = [];
        $hasRootFile = false;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $raw = $zip->getNameIndex($i);
            if ($raw === false || $raw === '') {
                continue;
            }

            try {
                $normalized = SafeArchiveExtractor::normalizeEntryName($raw);
            } catch (UnsafeArchiveException) {
                continue;
            }

            if ($normalized === '' || SafeArchiveExtractor::isJunkEntry($normalized)) {
                continue;
            }

            $slash = strpos($normalized, '/');
            $isDirectory = SafeArchiveExtractor::isDirectoryEntry($raw);
            $top = $slash === false ? $normalized : substr($normalized, 0, $slash);
            $tops[$top] = true;

            if ($isDirectory) {
                continue;
            }

            if ($slash === false) {
                $hasRootFile = true;
            }

            // 같은 이름이 두 번 있으면 해제기처럼 뒤의 것이 이긴다(뒤 항목이 앞 파일을 덮어쓴다).
            $fileIndexes[$normalized] = $i;
        }

        return new self($zip, $fileIndexes, array_keys($tops), $hasRootFile);
    }

    /**
     * 파일 항목의 정규화된 이름 목록 (폴더 항목·부속 항목 제외, 아카이브 순서).
     *
     * @return array<int, string> 파일 이름 목록
     */
    public function files(): array
    {
        return array_keys($this->fileIndexes);
    }

    /**
     * 표식 파일 위치로 배포본 루트 접두사를 찾습니다 — 루트(`''`) 또는 1단계 폴더(`wrapper/`).
     *
     * 루트와 1단계 폴더 양쪽에 표식이 있으면 루트를 고른다(번들 인벤토리의 기존 판정 그대로).
     *
     * @param  string  $marker  배포본 루트 기준 표식 파일 (예: `config/app.php`)
     * @return string|null 접두사, 표식이 없으면 null
     */
    public function prefixFor(string $marker): ?string
    {
        $pattern = '#^((?:[^/]+/)?)'.preg_quote($marker, '#').'$#';
        $first = null;

        foreach ($this->files() as $name) {
            if (preg_match($pattern, $name, $m) !== 1) {
                continue;
            }
            if ($m[1] === '') {
                return '';
            }
            $first ??= $m[1];
        }

        return $first;
    }

    /**
     * 모든 항목이 같은 최상위 폴더 하나를 공유하면 그 접두사를 돌려줍니다.
     *
     * @return string 감싼 폴더 접두사(`g7-7.2.0/`), 감싸지 않았으면 빈 문자열
     */
    public function wrapperPrefix(): string
    {
        if ($this->hasRootFile || count($this->tops) !== 1) {
            return '';
        }

        return $this->tops[0].'/';
    }

    /**
     * 정규화된 이름의 파일 내용을 원본 인덱스로 읽습니다.
     *
     * @param  string  $normalized  정규화된 파일 이름 (예: `g7-7.2.0/config/app.php`)
     * @return string|null 내용, 없거나 폴더 항목이면 null
     */
    public function read(string $normalized): ?string
    {
        $index = $this->fileIndexes[$normalized] ?? null;
        if ($index === null) {
            return null;
        }

        $content = $this->zip->getFromIndex($index);

        return is_string($content) ? $content : null;
    }
}
