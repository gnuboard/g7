<?php

namespace App\Http\Requests\LanguagePack\Concerns;

/**
 * 언어팩 ZIP 파일 검증 규칙의 단일 출처.
 *
 * 미리보기와 설치가 같은 파일을 받는데 규칙이 서로 달랐다 — 미리보기는 5MB·`mimes:zip`,
 * 설치는 10MB·`mimetypes:…` 였다. 그래서 **5~10MB 팩은 설치는 되는데 미리보기만 422** 가 되고,
 * 브라우저가 `application/octet-stream` 으로 올린 zip 은 반대로 미리보기만 통과했다.
 *
 * "설치는 되는데 미리보기는 안 되는 파일" 은 정의상 버그이므로 **설치 규칙에 맞춘다** —
 * 미리보기가 더 좁으면 운영자는 설치할 수 있는 팩의 내용을 확인할 수 없다.
 */
trait ValidatesLanguagePackArchive
{
    /** 언어팩 ZIP 최대 크기 (KB) — 미리보기·설치가 공유한다. */
    public const LANGUAGE_PACK_MAX_KILOBYTES = 10240;

    /**
     * 언어팩 ZIP 검증 규칙을 반환합니다.
     *
     * `mimes:zip` 이 아니라 `mimetypes:` 인 이유: 브라우저·OS 에 따라 zip 이
     * `application/x-zip-compressed` 나 `application/octet-stream` 으로 올라온다.
     *
     * @return array<int, string> `file` 필드 검증 규칙
     */
    protected function languagePackArchiveRules(): array
    {
        return [
            'required',
            'file',
            'max:'.self::LANGUAGE_PACK_MAX_KILOBYTES,
            'mimetypes:application/zip,application/x-zip-compressed,application/octet-stream',
        ];
    }

    /**
     * 언어팩 ZIP 검증 메시지를 반환합니다.
     *
     * @return array<string, string> 메시지 배열
     */
    protected function languagePackArchiveMessages(): array
    {
        return [
            'file.required' => __('language_packs.validation.file_required'),
            'file.file' => __('language_packs.validation.file_invalid'),
            'file.max' => __('language_packs.validation.file_too_large'),
            'file.mimetypes' => __('language_packs.validation.file_not_zip'),
        ];
    }
}
