<?php

namespace Tests\Feature\Console\Cascade;

/**
 * `plugin:install` 의 연쇄 설치 옵션 (`--with-dependencies`·`--with-language-packs`) — 검증 본문은 공통 기반 클래스.
 */
class InstallPluginCommandCascadeTest extends InstallCommandCascadeTestCase
{
    /**
     * 명령 이름.
     *
     * @return string 명령
     */
    protected function command(): string
    {
        return 'plugin:install';
    }

    /**
     * 확장 종류.
     *
     * @return string 종류
     */
    protected function type(): string
    {
        return 'plugin';
    }
}
