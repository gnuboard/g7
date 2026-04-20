<?php

namespace App\Contracts\Extension;

/**
 * 확장(코어/모듈/플러그인) 캐시 인터페이스
 *
 * 코어, 모듈, 플러그인에서 캐시를 관리하기 위한 표준화된 인터페이스입니다.
 * 접두사 기반으로 캐시 키를 격리하며, 태그 기반 선택적 무효화를 지원합니다.
 *
 * @since engine-v1.18.0
 */
interface CacheInterface
{
    // === 기본 CRUD ===

    /**
     * 캐시에서 값을 조회합니다.
     *
     * @param  string  $key  캐시 키
     * @param  mixed  $default  기본값
     * @return mixed 캐시된 값 또는 기본값
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * 캐시에 값을 저장합니다.
     *
     * @param  string  $key  캐시 키
     * @param  mixed  $value  저장할 값
     * @param  int|null  $ttl  TTL (초), null이면 기본값 사용
     * @return bool 저장 성공 여부
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * 캐시 키가 존재하는지 확인합니다.
     *
     * @param  string  $key  캐시 키
     * @return bool 존재 여부
     */
    public function has(string $key): bool;

    /**
     * 캐시 키를 삭제합니다.
     *
     * @param  string  $key  캐시 키
     * @return bool 삭제 성공 여부
     */
    public function forget(string $key): bool;

    // === Remember 패턴 ===

    /**
     * 캐시에서 값을 조회하거나 콜백 결과를 캐시합니다.
     *
     * @param  string  $key  캐시 키
     * @param  callable  $callback  캐시 미스 시 실행할 콜백
     * @param  int|null  $ttl  TTL (초), null이면 기본값 사용
     * @param  array  $tags  캐시 태그 (무효화용)
     * @return mixed 캐시된 값 또는 콜백 결과
     */
    public function remember(string $key, callable $callback, ?int $ttl = null, array $tags = []): mixed;

    /**
     * 쿼리 해시 기반 캐싱을 수행합니다.
     *
     * 내부적으로 'query:' 접두사를 부여하여 일반 캐시 키와 네임스페이스를 분리합니다.
     *
     * @param  string  $queryHash  쿼리 해시 (예: md5(json_encode($filters)))
     * @param  callable  $callback  캐시 미스 시 실행할 콜백
     * @param  int|null  $ttl  TTL (초), null이면 기본값 사용
     * @param  array  $tags  캐시 태그 (무효화용)
     * @return mixed 캐시된 값 또는 콜백 결과
     */
    public function rememberQuery(string $queryHash, callable $callback, ?int $ttl = null, array $tags = []): mixed;

    // === 벌크 연산 ===

    /**
     * 여러 캐시 키를 한 번에 조회합니다.
     *
     * @param  array  $keys  캐시 키 배열
     * @return array 키 => 값 배열
     */
    public function many(array $keys): array;

    /**
     * 여러 캐시 키-값을 한 번에 저장합니다.
     *
     * @param  array  $values  키 => 값 배열
     * @param  int|null  $ttl  TTL (초), null이면 기본값 사용
     * @return bool 저장 성공 여부
     */
    public function putMany(array $values, ?int $ttl = null): bool;

    // === 무효화 ===

    /**
     * 이 드라이버 소속 전체 캐시를 삭제합니다.
     *
     * @return bool 삭제 성공 여부
     */
    public function flush(): bool;

    /**
     * 특정 태그의 캐시를 삭제합니다.
     *
     * @param  array  $tags  삭제할 태그 배열
     * @return bool 삭제 성공 여부
     */
    public function flushTags(array $tags): bool;

    // === 리프레시 ===

    /**
     * 캐시를 무효화하고 새로고침합니다.
     *
     * forget + remember를 원자적으로 실행합니다.
     *
     * @param  string  $key  캐시 키
     * @param  callable  $callback  새 값을 생성할 콜백
     * @param  int|null  $ttl  TTL (초), null이면 기본값 사용
     * @param  array  $tags  캐시 태그 (무효화용)
     * @return mixed 새로 캐시된 값
     */
    public function refresh(string $key, callable $callback, ?int $ttl = null, array $tags = []): mixed;

    // === 메타 ===

    /**
     * 현재 캐시 스토어가 태그를 지원하는지 확인합니다.
     *
     * @return bool 태그 지원 여부
     */
    public function supportsTags(): bool;

    /**
     * 현재 사용 중인 캐시 스토어 이름을 반환합니다.
     *
     * @return string 스토어 이름
     */
    public function getStore(): string;

    /**
     * 사용할 캐시 스토어를 변경한 새 인스턴스를 반환합니다.
     *
     * 기존 인스턴스는 변경하지 않고, 새 스토어를 사용하는 복제된 인스턴스를 반환합니다.
     *
     * @param  string  $store  캐시 스토어 이름
     * @return static 새 스토어를 사용하는 인스턴스
     */
    public function withStore(string $store): static;

    /**
     * 접두사가 적용된 전체 캐시 키를 반환합니다.
     *
     * 디버깅 및 로그 확인용으로 사용합니다.
     *
     * @param  string  $key  캐시 키
     * @return string 접두사가 포함된 전체 키
     */
    public function resolveKey(string $key): string;
}
