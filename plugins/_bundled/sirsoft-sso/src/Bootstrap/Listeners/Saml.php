<?php

namespace Plugins\Sirsoft\Sso\Bootstrap\Listeners;

/**
 * 훅 리스너 — SAML 2.0 드라이버 영역.
 *
 * `Bootstrap\Listeners::all()` 이 영역별 목록을 합친다. 이 영역의 목록은 이 파일에만 둔다.
 *
 * @since 1.0.0
 */
final class Saml
{
    /**
     * 이 영역의 훅 리스너 목록을 반환합니다.
     *
     * @return array<int, class-string> 리스너 클래스 목록
     */
    public static function all(): array
    {
        return [];
    }
}
