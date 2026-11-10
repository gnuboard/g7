<?php

namespace App\Support\ContentMedia;

use App\Contracts\Extension\StorageInterface;
use App\Contracts\Extension\StreamingStorageInterface;
use App\Extension\Helpers\StorageTransaction;
use Illuminate\Support\Str;

/**
 * 본문 미디어 소유 복제 도구 — 「저장 때 새 문서 소유로 복제」 (#887)
 *
 * 본문 미디어 행은 문서(글·페이지·상품 설명 등) 하나에 묶인다. 다른 문서가 같은 서빙 주소를 본문에 쓰면(답글 인용·복사·붙여넣기)
 * 그 문서에는 행이 없어, 원본 문서가 미디어를 빼거나 지워지는 순간 함께 깨졌다. 확장은 저장 동기화에서 이 도구로
 *
 * 1. 본문이 같은 사이트 정식 주소로 참조하는 해시 중 이 문서 소유가 아닌 것을 고르고(`foreignHashes`),
 * 2. 원본을 읽을 수 있는지 자기 게이트로 판정한 뒤 파일을 복사하고(`copyFile` — 트랜잭션이 되돌아가면 사본을 지운다),
 * 3. 새 행의 해시로 본문 주소를 바꾼다(`replaceHashes`).
 *
 * 다른 출처 주소·속성 값 일부에만 들어간 주소는 대상이 아니다 — 서명 쿼리를 뗀(`ContentMediaUrlRewriter::normalize`) 뒤의
 * 상대 정식 주소가 속성 값 전체인 곳만 본다. 어느 행을 원본으로 인정할지(같은 확장·같은 범위, 열람 권한)는 확장이 정한다.
 *
 * @since 7.1.5
 */
final class ContentMediaCloner
{
    /**
     * 본문이 미디어 태그(`img`·`video`·`audio`·`source` 의 `src`, `video` 의 `poster`)에서 **상대 정식 주소**로 참조하는 해시.
     *
     * @param  string|null  $html  본문 HTML (서명 쿼리를 뗀 뒤)
     * @param  string  $pattern  서빙 경로 정규식 본문(구분자 없음, `(?<hash>…)` 그룹 필수)
     * @return array<int, string> 해시 (문서 순서, 중복 제거)
     */
    public static function canonicalHashes(?string $html, string $pattern): array
    {
        $tagHashes = ContentMediaReferenceExtractor::hashes($html, $pattern);
        if ($tagHashes === []) {
            return [];
        }

        if (preg_match_all(self::regex($pattern), (string) $html, $matches) === false) {
            return [];
        }
        $canonical = array_flip($matches['hash'] ?? []);

        return array_values(array_filter($tagHashes, fn (string $hash) => isset($canonical[$hash])));
    }

    /**
     * 본문들(로케일마다)이 참조하는 해시 중 이미 아는 것(이 문서 소유 + 이번에 잇는 임시 행)을 뺀 것 — 복제 후보.
     *
     * @param  array<int|string, mixed>  $htmls  본문 목록 (문자열이 아닌 값은 건너뜀)
     * @param  string  $pattern  서빙 경로 정규식 본문
     * @param  array<int, string>  $knownHashes  이 문서가 이미 소유하거나 이번에 이을 해시
     * @return array<int, string> 복제 후보 해시 (처음 나온 순서, 중복 제거)
     */
    public static function foreignHashes(array $htmls, string $pattern, array $knownHashes): array
    {
        $known = array_flip($knownHashes);
        $foreign = [];
        foreach ($htmls as $html) {
            if (! is_string($html)) {
                continue;
            }
            foreach (self::canonicalHashes($html, $pattern) as $hash) {
                if (! isset($known[$hash])) {
                    $foreign[$hash] = true;
                }
            }
        }

        return array_keys($foreign);
    }

    /**
     * 본문의 상대 정식 주소에서 해시를 바꿉니다(원본 해시 → 복제 해시). 문자열 또는 로케일 배열을 받는다.
     *
     * 같은 해시가 여러 번 나오면 모두 바꾼다. 다른 출처 주소·서명 주소·맵에 없는 해시는 그대로 둔다.
     *
     * @param  mixed  $content  본문 (HTML 문자열 또는 로케일 => HTML)
     * @param  string  $pattern  서빙 경로 정규식 본문
     * @param  array<string, string>  $map  원본 해시 => 새 해시
     * @return mixed 바꾼 본문 (형태는 입력과 같다)
     */
    public static function replaceHashes(mixed $content, string $pattern, array $map): mixed
    {
        if ($map === []) {
            return $content;
        }

        if (is_array($content)) {
            return array_map(fn ($html) => self::replaceHashes($html, $pattern, $map), $content);
        }

        if (! is_string($content) || $content === '') {
            return $content;
        }

        $replaced = preg_replace_callback(self::regex($pattern), function (array $m) use ($map) {
            [$hash, $hashOffset] = $m['hash'];
            if (! isset($map[$hash])) {
                return $m[0][0];
            }

            [$whole, $wholeOffset] = $m[0];
            $relative = $hashOffset - $wholeOffset;

            return substr($whole, 0, $relative).$map[$hash].substr($whole, $relative + strlen($hash));
        }, $content, -1, $count, PREG_OFFSET_CAPTURE) ?? $content;

        return self::replaceDerivedAlt($replaced, $map);
    }

    /**
     * 값이 옛 해시와 정확히 같은 alt 속성을 새 해시로 바꿉니다.
     *
     * HTML 정화기(HTMLPurifier)는 alt 없는 이미지에 src 의 파일 이름을 alt 로 채운다 — 본문 미디어 주소면 그 값이 서빙 해시다.
     * 주소만 새 해시로 바꾸면 alt 에 원본 해시가 남으므로, 정화기가 만든 것과 같은 꼴(값 전체가 해시)만 함께 바꾼다.
     * 사람이 쓴 alt 문구는 해시와 정확히 같을 일이 없으므로 건드리지 않는다.
     *
     * @param  string  $html  본문 HTML
     * @param  array<string, string>  $map  옛 해시 => 새 해시
     * @return string 바꾼 본문
     */
    private static function replaceDerivedAlt(string $html, array $map): string
    {
        return preg_replace_callback(
            '/(\salt\s*=\s*)(["\'])([A-Za-z0-9]{12})\2/',
            fn (array $m) => isset($map[$m[3]]) ? $m[1].$m[2].$map[$m[3]].$m[2] : $m[0],
            $html,
        ) ?? $html;
    }

    /**
     * 원본 파일을 새 경로로 복사합니다 — 저장 트랜잭션이 되돌아가면 사본을 지운다(`StorageTransaction::put`).
     *
     * 스트림 저장소면 메모리에 통째로 올리지 않는다. 원본과 대상 저장소(디스크)가 달라도 된다.
     *
     * @param  StorageInterface  $source  원본 저장소 (원본 행의 디스크)
     * @param  string  $sourceCategory  원본 카테고리
     * @param  string  $sourcePath  원본 상대 경로
     * @param  StorageInterface  $target  대상 저장소
     * @param  string  $targetCategory  대상 카테고리
     * @param  string  $targetPath  대상 상대 경로
     * @param  string|null  $connection  DB 연결 이름 (null 이면 기본)
     * @return bool 복사 성공 여부 — 원본이 없으면 false
     */
    public static function copyFile(
        StorageInterface $source,
        string $sourceCategory,
        string $sourcePath,
        StorageInterface $target,
        string $targetCategory,
        string $targetPath,
        ?string $connection = null,
    ): bool {
        if ($source instanceof StreamingStorageInterface) {
            $stream = $source->readStream($sourceCategory, $sourcePath);
            if (! is_resource($stream)) {
                return false;
            }

            try {
                return StorageTransaction::put($target, $targetCategory, $targetPath, $stream, $connection);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        $content = $source->get($sourceCategory, $sourcePath);
        if ($content === null) {
            return false;
        }

        return StorageTransaction::put($target, $targetCategory, $targetPath, $content, $connection);
    }

    /**
     * 복제본 저장 파일명 — 새 UUID + 원본 확장자(소문자).
     *
     * @param  string  $sourceFilename  원본 저장 파일명 또는 경로
     * @return string 새 저장 파일명
     */
    public static function clonedFilename(string $sourceFilename): string
    {
        $extension = strtolower((string) pathinfo($sourceFilename, PATHINFO_EXTENSION));

        return Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
    }

    /**
     * 속성 값(따옴표 안) 전체가 상대 정식 주소인 곳을 잡는 정규식 — 다른 출처·쿼리가 붙은 주소는 잡지 않는다.
     *
     * @param  string  $pattern  서빙 경로 정규식 본문
     * @return string 정규식
     */
    private static function regex(string $pattern): string
    {
        return '#(?<quote>["\'])'.str_replace('#', '\#', $pattern).'(?P=quote)#';
    }
}
