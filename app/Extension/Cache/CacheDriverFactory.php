<?php

namespace App\Extension\Cache;

/**
 * 캐시 드라이버 생성의 단일 지점 — 실행 역할별 캐시 범위가 모든 드라이버에 같게 적용된다.
 *
 * `new CoreCacheDriver`·`new ModuleCacheDriver`·`new PluginCacheDriver` 직접 생성은 금지한다(정적 검사
 * `cache-driver-factory-only`). 범위를 주지 않으면 키 이름으로 판정한다({@see CacheScope::classify()}).
 *
 * @since 7.2.0
 */
final class CacheDriverFactory
{
    /**
     * 코어 캐시 드라이버.
     *
     * @param  CacheScope|null  $scope  범위 (null 이면 키 이름으로 판정)
     * @param  string  $store  캐시 저장소 (빈 문자열이면 기본)
     * @return CoreCacheDriver 드라이버
     */
    public static function core(?CacheScope $scope = null, string $store = ''): CoreCacheDriver
    {
        return (new CoreCacheDriver($store ?: (string) config('cache.default', 'array')))->withScope($scope);
    }

    /**
     * 확장 캐시 드라이버.
     *
     * @param  string  $type  module|plugin
     * @param  string  $identifier  확장 식별자
     * @param  CacheScope|null  $scope  범위 (null 이면 키 이름으로 판정)
     * @param  string  $store  캐시 저장소 (빈 문자열이면 기본)
     * @return ModuleCacheDriver|PluginCacheDriver 드라이버
     */
    public static function extension(string $type, string $identifier, ?CacheScope $scope = null, string $store = ''): ModuleCacheDriver|PluginCacheDriver
    {
        $store = $store ?: (string) config('cache.default', 'array');
        $driver = $type === 'plugin' ? new PluginCacheDriver($identifier, $store) : new ModuleCacheDriver($identifier, $store);

        return $driver->withScope($scope);
    }
}
