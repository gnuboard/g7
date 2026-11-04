<?php

namespace App\Support\Template;

/**
 * 템플릿 설정 첨부 컬렉션 이름 규약 (#434)
 *
 * 첨부 칸마다 컬렉션이 갈린다 — `template_settings.{템플릿}.{섹션}.{키}`. 소유자는 컬렉션 이름으로만 표시한다
 * (첨부 행에 템플릿 소유 열이 없다). 업로드 정책·저장 검증·고아 정리 보호·삭제 파기가 모두 이 이름을 본다.
 */
final class TemplateSettingCollections
{
    /**
     * 컬렉션 접두사
     */
    public const PREFIX = 'template_settings.';

    /**
     * 칸 경로(`섹션.키`)의 컬렉션 이름을 만듭니다.
     *
     * @param  string  $templateIdentifier  템플릿 식별자
     * @param  string  $path  칸 경로 (`섹션.키`)
     * @return string 컬렉션 이름
     */
    public static function forField(string $templateIdentifier, string $path): string
    {
        return self::PREFIX.$templateIdentifier.'.'.$path;
    }

    /**
     * 한 템플릿의 컬렉션 접두사 — 삭제 파기·고아 보호가 쓴다.
     *
     * @param  string  $templateIdentifier  템플릿 식별자
     * @return string 접두사 (`template_settings.{템플릿}.`)
     */
    public static function templatePrefix(string $templateIdentifier): string
    {
        return self::PREFIX.$templateIdentifier.'.';
    }

    /**
     * 컬렉션 이름을 템플릿 식별자와 칸 경로로 나눕니다 — 템플릿 설정 컬렉션이 아니면 null.
     *
     * @param  string|null  $collection  컬렉션 이름
     * @return array{template: string, path: string}|null 분해 결과
     */
    public static function parse(?string $collection): ?array
    {
        if ($collection === null || preg_match('/^'.preg_quote(self::PREFIX, '/').'([a-z0-9][a-z0-9_-]*)\.([a-z][a-z0-9_]*\.[a-z][a-z0-9_]*)$/', $collection, $m) !== 1) {
            return null;
        }

        return ['template' => $m[1], 'path' => $m[2]];
    }
}
