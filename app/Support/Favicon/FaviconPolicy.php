<?php

namespace App\Support\Favicon;

use App\Support\AssetUrl;
use App\Support\Template\TemplateSettingCollections;

/**
 * 파비콘 형식·컬렉션 규약
 *
 * 업로드 허용(확장자 교체)·서빙(MIME 화이트리스트)·우선순위 해석(유효 첨부 판정) 세 곳이
 * 같은 목록을 봐야 한다 — 한 곳만 넓히면 올릴 수는 있는데 탭에 안 뜨거나, 뜨는데 올릴 수 없는
 * 형식이 생긴다. 그래서 목록을 여기 한 벌만 둔다.
 *
 * 전역 첨부 정책(`InlineMediaPolicy`)은 넓히지 않는다. ico·svg 는 파비콘 컬렉션에서만 받고,
 * 파비콘 전용 경로에서만 이미지로 내보낸다.
 */
final class FaviconPolicy
{
    /**
     * 코어 일반 탭 파비콘 컬렉션
     */
    public const CORE_COLLECTION = 'site_favicon';

    /**
     * 템플릿 설정의 표준 파비콘 칸 경로(`섹션.키`) — 코어가 파비콘 계층에서 읽는다
     */
    public const TEMPLATE_KEY = 'branding.favicon';

    /**
     * 업로드 허용 확장자
     *
     * @var array<int, string>
     */
    public const EXTENSIONS = ['ico', 'png', 'svg', 'gif', 'jpg', 'jpeg', 'webp'];

    /**
     * 서빙 MIME 화이트리스트 — 저장된 MIME → 응답 Content-Type
     *
     * ico 는 저장 시점 판별기에 따라 두 이름 중 하나로 남으므로 둘 다 받는다.
     *
     * @var array<string, string>
     */
    public const MIME_TYPES = [
        'image/x-icon' => 'image/x-icon',
        'image/vnd.microsoft.icon' => 'image/x-icon',
        'image/svg+xml' => 'image/svg+xml',
        'image/png' => 'image/png',
        'image/gif' => 'image/gif',
        'image/jpeg' => 'image/jpeg',
        'image/webp' => 'image/webp',
    ];

    /**
     * 업로드 최대 크기 (KB)
     */
    public const MAX_SIZE_KB = 1024;

    /**
     * 템플릿 파비콘 칸의 컬렉션 이름을 만듭니다 — `template_settings.{템플릿}.branding.favicon`.
     *
     * @param  string  $templateIdentifier  템플릿 식별자
     * @return string 컬렉션 이름
     */
    public static function templateCollection(string $templateIdentifier): string
    {
        return TemplateSettingCollections::forField($templateIdentifier, self::TEMPLATE_KEY);
    }

    /**
     * 파비콘 컬렉션인지 판정합니다 — 코어 `site_favicon` 또는 `template_settings.{템플릿}.branding.favicon`.
     *
     * @param  string|null  $collection  컬렉션 이름
     * @return bool 파비콘 컬렉션이면 true
     */
    public static function isFaviconCollection(?string $collection): bool
    {
        if ($collection === null || $collection === '') {
            return false;
        }

        if ($collection === self::CORE_COLLECTION) {
            return true;
        }

        return (TemplateSettingCollections::parse($collection)['path'] ?? null) === self::TEMPLATE_KEY;
    }

    /**
     * 저장된 MIME 을 응답 Content-Type 으로 바꿉니다 — 목록 밖이면 null.
     *
     * @param  string|null  $mimeType  첨부 MIME
     * @return string|null 응답 Content-Type
     */
    public static function responseMime(?string $mimeType): ?string
    {
        if ($mimeType === null) {
            return null;
        }

        return self::MIME_TYPES[strtolower($mimeType)] ?? null;
    }

    /**
     * 업로드한 파비콘의 공개 경로를 만듭니다 — 사이트 마운트 접두사 포함(하위 폴더 설치 대응).
     *
     * 교체하면 새 첨부 = 새 해시라 경로 자체가 바뀌므로 응답을 오래 캐시해도 된다.
     *
     * @param  string  $hash  첨부 해시
     * @return string 경로 (예: `/api/favicon/AbC123`)
     */
    public static function url(string $hash): string
    {
        return AssetUrl::basePrefix().'/api/favicon/'.$hash;
    }

    /**
     * 업로드 화면 `accept` 속성 값을 만듭니다.
     *
     * @return string 예: `.ico,.png,.svg,.gif,.jpg,.jpeg,.webp`
     */
    public static function acceptAttribute(): string
    {
        return implode(',', array_map(fn (string $ext) => '.'.$ext, self::EXTENSIONS));
    }
}
