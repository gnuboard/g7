<?php

namespace App\Support\MenuIcon;

use App\Enums\ExtensionOwnerType;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\Attachment;
use App\Support\AssetUrl;

/**
 * 메뉴 아이콘 저장값을 API 응답 모양(`icon` 호환 문자열 + `icon_spec` 구조형)으로 바꾼다.
 *
 * - `icon` — class 면 클래스 문자열, image·svg 면 null (외부 관리자 템플릿 호환 — FA 클래스만 기대)
 * - `icon_spec` — `{type, value}` / `{type:'image', source, path|attachment_id+hash, url}` / `{type:'svg', value}`
 *
 * url 은 응답 시점에 만든다 — 사이트 경로는 마운트 접두, 첨부는 해시 URL, 확장 상대경로는 확장 자산 URL(+버전).
 * 버전은 매니저 메모리 값만 읽는다(쿼리 없음).
 *
 * @since 7.2.1
 */
final class MenuIconPresenter
{
    /**
     * 저장값을 응답 필드로 바꿉니다.
     *
     * @param  string|null  $stored  `menus.icon` 원문
     * @param  ExtensionOwnerType|string|null  $extensionType  메뉴의 확장 유형
     * @param  string|null  $extensionIdentifier  메뉴의 확장 식별자
     * @return array{icon: ?string, icon_spec: ?array<string, mixed>} 응답 필드
     */
    public static function present(?string $stored, ExtensionOwnerType|string|null $extensionType, ?string $extensionIdentifier): array
    {
        $spec = MenuIconSpec::fromStored($stored);

        if ($spec === null) {
            return ['icon' => null, 'icon_spec' => null];
        }

        $data = $spec->toArray();

        if ($spec->type === MenuIconSpec::TYPE_IMAGE) {
            $data['url'] = self::imageUrl($spec, self::ownerType($extensionType), $extensionIdentifier);
        }

        // 저장 SVG 도 입력과 같은 검사를 통과할 때만 코드를 싣는다 — 7.2.0 이하 API 로 저장된 JSON 모양 문자열은 입력
        // 검사를 거치지 않았다(화면은 value 가 없으면 대체 아이콘). 렌더 정제는 템플릿 몫이지만 서버도 내보내지 않는다
        if ($spec->isUnsafeSvg()) {
            $data['value'] = null;
        }

        return ['icon' => $spec->legacyIcon(), 'icon_spec' => $data];
    }

    /**
     * 이미지 아이콘의 표시 URL 을 만듭니다.
     *
     * @param  MenuIconSpec  $spec  이미지 아이콘
     * @param  ExtensionOwnerType|null  $type  메뉴 확장 유형
     * @param  string|null  $identifier  메뉴 확장 식별자
     * @return string|null 표시 URL, 만들 수 없으면 null
     */
    private static function imageUrl(MenuIconSpec $spec, ?ExtensionOwnerType $type, ?string $identifier): ?string
    {
        // 저장값도 입력과 같은 경로 규칙을 지킬 때만 url 을 만든다 — 7.2.0 이하 API 로 저장된 JSON 모양 문자열은
        // 입력 검사를 거치지 않아 `//외부` 같은 주소가 남아 있을 수 있다(화면은 url 이 없으면 대체 아이콘)
        if ($spec->source === MenuIconSpec::SOURCE_SITE) {
            $path = (string) $spec->path;

            return MenuIconSpec::sitePathViolation($path) === null ? AssetUrl::sitePath($path) : null;
        }

        if ($spec->source === MenuIconSpec::SOURCE_ATTACHMENT) {
            return $spec->hash !== null && $spec->hash !== '' ? Attachment::urlForHash($spec->hash) : null;
        }

        if (MenuIconSpec::packagePathViolation((string) $spec->path) !== null) {
            return null;
        }

        $path = ltrim((string) $spec->path, '/');

        return match ($type) {
            ExtensionOwnerType::Module => $identifier !== null
                ? AssetUrl::moduleAsset($identifier, $path, self::moduleVersion($identifier))
                : null,
            ExtensionOwnerType::Plugin => $identifier !== null
                ? AssetUrl::pluginAsset($identifier, $path, self::pluginVersion($identifier))
                : null,
            default => AssetUrl::sitePath('/'.$path),
        };
    }

    /**
     * 확장 유형 값을 enum 으로 바꿉니다.
     *
     * @param  ExtensionOwnerType|string|null  $type  유형 값
     * @return ExtensionOwnerType|null enum, 없으면 null(=코어·사용자 메뉴)
     */
    private static function ownerType(ExtensionOwnerType|string|null $type): ?ExtensionOwnerType
    {
        if ($type instanceof ExtensionOwnerType || $type === null) {
            return $type;
        }

        return ExtensionOwnerType::tryFrom($type);
    }

    /**
     * 모듈 버전을 매니저 메모리에서 읽습니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @return string|null 버전, 모르면 null
     */
    private static function moduleVersion(string $identifier): ?string
    {
        try {
            return app(ModuleManager::class)->getModule($identifier)?->getVersion();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 플러그인 버전을 매니저 메모리에서 읽습니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @return string|null 버전, 모르면 null
     */
    private static function pluginVersion(string $identifier): ?string
    {
        try {
            return app(PluginManager::class)->getPlugin($identifier)?->getVersion();
        } catch (\Throwable) {
            return null;
        }
    }
}
