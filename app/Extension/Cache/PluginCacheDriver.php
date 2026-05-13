<?php

namespace App\Extension\Cache;

/**
 * 플러그인 캐시 드라이버
 *
 * 각 플러그인에서 사용하는 캐시를 관리합니다.
 * 접두사 패턴: g7:plugin.{identifier}:{key}
 *
 * @since engine-v1.18.0
 */
class PluginCacheDriver extends AbstractCacheDriver
{
    /**
     * 플러그인 식별자
     */
    private string $identifier;

    /**
     * PluginCacheDriver 생성자
     *
     * @param  string  $identifier  플러그인 식별자 (예: sirsoft-payment)
     * @param  string  $store  캐시 스토어 이름 (빈 문자열이면 기본 스토어)
     */
    public function __construct(string $identifier, string $store = '')
    {
        $this->identifier = $identifier;
        $this->store = $store ?: config('cache.default');
    }

    /**
     * 플러그인 캐시 접두사를 반환합니다.
     *
     * @return string 접두사
     */
    protected function getPrefix(): string
    {
        return 'g7:plugin.' . $this->identifier;
    }
}
