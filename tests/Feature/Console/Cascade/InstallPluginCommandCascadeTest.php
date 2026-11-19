<?php

namespace Tests\Feature\Console\Cascade;

/**
 * `plugin:install` 의 연쇄 설치 옵션 (`--with-dependencies`·`--with-language-packs`) — 검증 본문은 공통 기반 클래스.
 *
 * 기반 클래스의 시험 전부가 이 명령으로 돈다(효과 마킹은 기반 클래스 메서드에 있다).
 *
 * @scenario command=plugin_install
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
