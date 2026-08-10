<?php

namespace App\Contracts\Extension;

/**
 * 캐시/스토리지 도메인 분리를 제공하는 확장의 좁은 contract.
 *
 * AbstractModule / AbstractPlugin 이 공통으로 노출하는 캐시·스토리지
 * 접근자를 한 인터페이스로 묶어, AbstractExtensionServiceProvider 가
 * 모듈·플러그인 양쪽을 균등하게 다룰 수 있도록 합니다.
 */
interface CacheableExtensionInterface
{
    /**
     * 확장 식별자를 반환합니다 (vendor-extension 형식).
     *
     * @return string 확장 식별자
     */
    public function getIdentifier(): string;

    /**
     * 확장 도메인에 격리된 스토리지 드라이버를 반환합니다.
     *
     * @return StorageInterface 확장 전용 스토리지 인스턴스
     */
    public function getStorage(): StorageInterface;

    /**
     * 확장 도메인에 격리된 캐시 드라이버를 반환합니다.
     *
     * @return CacheInterface 확장 전용 캐시 인스턴스
     */
    public function getCache(): CacheInterface;
}
