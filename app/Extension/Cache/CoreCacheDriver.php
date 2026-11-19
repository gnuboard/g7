<?php

namespace App\Extension\Cache;

/**
 * 코어 캐시 드라이버
 *
 * 코어 서비스(LayoutService, SeoCacheManager, SettingsService 등)에서 사용하는
 * 캐시를 관리합니다. 접두사 패턴: g7:core:{key}
 *
 * @since engine-v1.18.0
 */
class CoreCacheDriver extends AbstractCacheDriver
{
    /**
     * CoreCacheDriver 생성자
     *
     * @param  string  $store  캐시 스토어 이름 (빈 문자열이면 기본 스토어)
     */
    public function __construct(string $store = '')
    {
        $this->store = $store ?: config('cache.default');
    }

    /**
     * 코어 캐시 접두사를 반환합니다.
     *
     * @return string 접두사
     */
    protected function getPrefix(): string
    {
        return 'g7:core';
    }

    /**
     * 코어는 put()·putMany() 키를 키 인덱스에 적지 않습니다.
     *
     * 코어 드라이버는 flush() 호출처가 없고, put() 키가 IP·URL·코드마다 고유라 적으면 인덱스만 커진다.
     *
     * @return bool 기록 여부 (항상 false)
     */
    protected function recordsPlainWrites(): bool
    {
        return false;
    }
}
