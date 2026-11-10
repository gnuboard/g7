<?php

namespace App\Support\Files;

use Illuminate\Http\UploadedFile;

/**
 * 업로드 파일 내용 판정의 단일 입구 — 내용 MIME 과 내용 추론 확장자 (#887)
 *
 * 업로드 검증 두 규칙이 같은 곳에서 파일 내용을 읽게 한다.
 *
 * - 본문 미디어(`ContentMediaFile`)는 `mimeType()` — finfo 로 읽은 내용 MIME 이 1순위이고, finfo 를 쓸 수 없을 때만
 *   `getMimeType()` 으로 물러난다. 서빙 Content-Type 도 이 값이다.
 * - 첨부(`AttachmentFileContent`)는 `extension()` — Symfony 의 내용 추론(`getMimeType()` → 첫 확장자)이다. 실제 업로드에서
 *   `getMimeType()` 은 finfo 를 먼저 쓰므로 출처가 같다. 판정 결과(추론 확장자)는 7.1.0 의 `mimes:` 호환 규칙 그대로다 —
 *   `mimeType()` 기준으로 바꾸면 내용을 보고하지 않는 파일 구현(시험용 가짜 파일 등)에서 판정이 달라지므로 바꾸지 않는다.
 *
 * @since 7.1.5
 */
final class FileContentSniffer
{
    /**
     * 파일 내용으로 MIME 을 추론합니다(finfo). 클라이언트가 보낸 Content-Type·이름은 보지 않습니다.
     *
     * `UploadedFile::getMimeType()` 은 구현에 따라 이름 확장자로 답할 수 있어(시험용 가짜 파일 등) 내용 판정의 근거가 되지
     * 못한다. finfo 를 쓸 수 없거나 파일이 없을 때만 `getMimeType()` 으로 물러난다.
     *
     * @param  UploadedFile  $file  업로드 파일
     * @return string|null 소문자 MIME (알 수 없으면 null)
     */
    public static function mimeType(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();
        if ($path !== false && is_file($path) && class_exists(\finfo::class)) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            if (is_string($mime) && $mime !== '') {
                return strtolower($mime);
            }
        }

        $mime = $file->getMimeType();

        return $mime === null ? null : strtolower($mime);
    }

    /**
     * 파일 내용으로 추론한 확장자 — Laravel `mimes:` 규칙과 같은 판정(`guessExtension()`).
     *
     * @param  UploadedFile  $file  업로드 파일
     * @return string|null 추론 확장자 (알 수 없으면 null)
     */
    public static function extension(UploadedFile $file): ?string
    {
        return $file->guessExtension();
    }
}
