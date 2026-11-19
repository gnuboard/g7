<?php

namespace Plugins\Sirsoft\Sso\Bootstrap\Commands;

use Plugins\Sirsoft\Sso\Drivers\Saml\MetadataRefreshCommand;
use Plugins\Sirsoft\Sso\Mock\Saml\MockSamlCommand;

/**
 * artisan 명령 — SAML(메타데이터 갱신 등) 영역.
 *
 * `Bootstrap\Commands::all()` 이 영역별 목록을 합친다. 이 영역의 목록은 이 파일에만 둔다.
 *
 * @since 1.0.0
 */
final class Saml
{
    /**
     * 이 영역의 artisan 명령 목록을 반환합니다.
     *
     * @return array<int, class-string> 명령 클래스 목록
     */
    public static function all(): array
    {
        return [
            MetadataRefreshCommand::class,
            MockSamlCommand::class,
        ];
    }
}
