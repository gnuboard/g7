<?php

namespace App\Extension\Cache;

use App\Contracts\Extension\CacheInterface;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 캐시 드라이버 추상 클래스
 *
 * CoreCacheDriver, ModuleCacheDriver, PluginCacheDriver의 공통 구현을 제공합니다.
 * 접두사 기반 키 분리, 태그 지원/미지원 드라이버 호환, 키 인덱스 기반 flush를 포함합니다.
 *
 * @since engine-v1.18.0
 */
abstract class AbstractCacheDriver implements CacheInterface
{
    /**
     * 사용할 캐시 스토어 이름
     */
    protected string $store;

    /**
     * 서브클래스에서 접두사를 반환합니다.
     *
     * @return string 접두사 (예: 'g7:core', 'g7:module.sirsoft-ecommerce')
     */
    abstract protected function getPrefix(): string;

    /**
     * 기본 TTL을 반환합니다.
     *
     * g7_core_settings 중앙 관리를 통해 기본값을 결정합니다.
     *
     * @return int 기본 TTL (초)
     */
    protected function getDefaultTtl(): int
    {
        return (int) g7_core_settings('cache.default_ttl', 86400);
    }

    /**
     * 접두사가 적용된 전체 캐시 키를 반환합니다.
     *
     * @param  string  $key  캐시 키
     * @return string 접두사가 포함된 전체 키
     */
    public function resolveKey(string $key): string
    {
        return $this->getPrefix().':'.$key;
    }

    /**
     * Laravel Cache 스토어 인스턴스를 반환합니다.
     *
     * @return Repository 캐시 스토어 인스턴스
     */
    protected function store(): Repository
    {
        return Cache::store($this->store);
    }

    /**
     * 현재 캐시 스토어가 태그를 지원하는지 확인합니다.
     *
     * @return bool 태그 지원 여부
     */
    public function supportsTags(): bool
    {
        return method_exists($this->store()->getStore(), 'tags');
    }

    /**
     * 현재 사용 중인 캐시 스토어 이름을 반환합니다.
     *
     * @return string 스토어 이름
     */
    public function getStore(): string
    {
        return $this->store;
    }

    /**
     * 사용할 캐시 스토어를 변경한 새 인스턴스를 반환합니다.
     *
     * @param  string  $store  캐시 스토어 이름
     * @return static 새 스토어를 사용하는 인스턴스
     */
    public function withStore(string $store): static
    {
        $clone = clone $this;
        $clone->store = $store;

        return $clone;
    }

    // === 기본 CRUD ===

    /**
     * 캐시에서 값을 조회합니다.
     *
     * @param  string  $key  캐시 키
     * @param  mixed  $default  기본값
     * @return mixed 캐시된 값 또는 기본값
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store()->get($this->resolveKey($key), $default);
    }

    /**
     * 캐시에 값을 저장합니다.
     *
     * @param  string  $key  캐시 키
     * @param  mixed  $value  저장할 값
     * @param  int|null  $ttl  TTL (초), null이면 기본값 사용
     * @return bool 저장 성공 여부
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $resolvedKey = $this->resolveKey($key);
        $ttl = $ttl ?? $this->getDefaultTtl();

        try {
            return $this->store()->put($resolvedKey, $value, $ttl);
        } catch (\Throwable $e) {
            $this->warnWriteFailure('put', $resolvedKey, $e);

            return false;
        }
    }

    /**
     * 캐시 키가 존재하는지 확인합니다.
     *
     * @param  string  $key  캐시 키
     * @return bool 존재 여부
     */
    public function has(string $key): bool
    {
        return $this->store()->has($this->resolveKey($key));
    }

    /**
     * 캐시 키를 삭제합니다.
     *
     * @param  string  $key  캐시 키
     * @return bool 삭제 성공 여부
     */
    public function forget(string $key): bool
    {
        try {
            return $this->store()->forget($this->resolveKey($key));
        } catch (\Throwable $e) {
            $this->warnWriteFailure('forget', $this->resolveKey($key), $e);

            return false;
        }
    }

    // === Remember 패턴 ===

    /**
     * 캐시에서 값을 조회하거나 콜백 결과를 캐시합니다.
     *
     * 소속 태그(확장 접두사)를 항상 포함하여 flush() 시 일괄 삭제가 가능합니다.
     *
     * @param  string  $key  캐시 키
     * @param  callable  $callback  캐시 미스 시 실행할 콜백
     * @param  int|null  $ttl  TTL (초), null이면 기본값 사용
     * @param  array  $tags  캐시 태그 (무효화용)
     * @return mixed 캐시된 값 또는 콜백 결과
     */
    public function remember(string $key, callable $callback, ?int $ttl = null, array $tags = []): mixed
    {
        $resolvedKey = $this->resolveKey($key);
        $ttl = $ttl ?? $this->getDefaultTtl();

        // 소속 태그(확장 접두사)를 항상 포함 → flush() 시 일괄 삭제 가능
        $allTags = array_values(array_unique(array_merge([$this->getPrefix()], $tags)));

        // 항상 일반 remember + 키 인덱스에 태그 매핑 기록
        // Laravel 네이티브 태그 저장은 사용하지 않음 (get/has와의 일관성 보장)
        //
        // 저장 실패는 fail-soft — 캐시는 최적화이므로 콜백 결과를 그대로 반환한다.
        // Repository::remember 를 쓰지 않고 get→callback→put 을 직접 수행하는 이유:
        // put 예외를 잡아도 콜백을 재실행하지 않기 위해서다 (부수효과 이중 실행 금지).
        // 실사례: sudo 업데이트가 키 인덱스 파일을 root 소유로 남기면 웹의 모든
        // remember 가 put 예외로 죽어 부팅 전면 500 이 됐다 (7.0.9→7.0.10).
        $cached = $this->store()->get($resolvedKey);
        if ($cached !== null) {
            return $cached;
        }

        $result = $callback();

        try {
            $this->store()->put($resolvedKey, $result, $ttl);
            $this->recordKeyTags($resolvedKey, $allTags);
        } catch (\Throwable $e) {
            $this->warnWriteFailure('remember', $resolvedKey, $e);
        }

        return $result;
    }

    /**
     * 쿼리 해시 기반 캐싱을 수행합니다.
     *
     * @param  string  $queryHash  쿼리 해시
     * @param  callable  $callback  캐시 미스 시 실행할 콜백
     * @param  int|null  $ttl  TTL (초), null이면 기본값 사용
     * @param  array  $tags  캐시 태그 (무효화용)
     * @return mixed 캐시된 값 또는 콜백 결과
     */
    public function rememberQuery(string $queryHash, callable $callback, ?int $ttl = null, array $tags = []): mixed
    {
        return $this->remember('query:'.$queryHash, $callback, $ttl, $tags);
    }

    // === 벌크 연산 ===

    /**
     * 여러 캐시 키를 한 번에 조회합니다.
     *
     * @param  array  $keys  캐시 키 배열
     * @return array 키 => 값 배열
     */
    public function many(array $keys): array
    {
        $resolvedKeys = [];
        $keyMap = [];

        foreach ($keys as $key) {
            $resolved = $this->resolveKey($key);
            $resolvedKeys[] = $resolved;
            $keyMap[$resolved] = $key;
        }

        $results = $this->store()->many($resolvedKeys);

        // 원래 키로 매핑하여 반환
        $mapped = [];
        foreach ($results as $resolvedKey => $value) {
            $originalKey = $keyMap[$resolvedKey] ?? $resolvedKey;
            $mapped[$originalKey] = $value;
        }

        return $mapped;
    }

    /**
     * 여러 캐시 키-값을 한 번에 저장합니다.
     *
     * @param  array  $values  키 => 값 배열
     * @param  int|null  $ttl  TTL (초), null이면 기본값 사용
     * @return bool 저장 성공 여부
     */
    public function putMany(array $values, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->getDefaultTtl();
        $resolved = [];

        foreach ($values as $key => $value) {
            $resolved[$this->resolveKey($key)] = $value;
        }

        try {
            return $this->store()->putMany($resolved, $ttl);
        } catch (\Throwable $e) {
            $this->warnWriteFailure('putMany', implode(',', array_keys($resolved)), $e);

            return false;
        }
    }

    // === 무효화 ===

    /**
     * 이 드라이버 소속 전체 캐시를 삭제합니다.
     *
     * 태그 지원 드라이버에서는 소속 태그로 삭제하고,
     * 미지원 드라이버에서는 키 인덱스를 기반으로 삭제합니다.
     *
     * @return bool 삭제 성공 여부
     */
    public function flush(): bool
    {
        if ($this->supportsTags()) {
            return $this->flushTags([$this->getPrefix()]);
        }

        return $this->flushByIndex();
    }

    /**
     * 특정 태그의 캐시를 삭제합니다.
     *
     * @param  array  $tags  삭제할 태그 배열
     * @return bool 삭제 성공 여부
     */
    public function flushTags(array $tags): bool
    {
        // 항상 키 인덱스 기반으로 태그 삭제 (get/has와의 일관성 보장)
        return $this->flushTagsByIndex($tags);
    }

    // === 리프레시 ===

    /**
     * 캐시를 무효화하고 새로고침합니다.
     *
     * @param  string  $key  캐시 키
     * @param  callable  $callback  새 값을 생성할 콜백
     * @param  int|null  $ttl  TTL (초), null이면 기본값 사용
     * @param  array  $tags  캐시 태그 (무효화용)
     * @return mixed 새로 캐시된 값
     */
    public function refresh(string $key, callable $callback, ?int $ttl = null, array $tags = []): mixed
    {
        $this->forget($key);

        return $this->remember($key, $callback, $ttl, $tags);
    }

    // === 키 인덱스 (태그 미지원 드라이버용) ===

    /**
     * 캐시 쓰기 실패를 경고 로그로 강등합니다 (fail-soft).
     *
     * 캐시는 최적화다 — 쓰기 실패(권한/디스크)가 페이지를 죽이면 안 된다. 실사례:
     * sudo 코어 업데이트가 키 인덱스 파일을 root 소유로 남겨 웹 프로세스의 모든
     * 캐시 쓰기가 Permission denied 로 죽고 부팅 경로가 전면 500 이 됐다
     * (예외가 로거 도달 전에 발생해 laravel.log 도 비어 있었다).
     *
     * 로그 폭주 방지: 같은 (연산, 예외 메시지) 조합은 프로세스당 1회만 기록한다.
     *
     * @param  string  $operation  실패한 연산 (put/forget/putMany/remember/index)
     * @param  string  $key  대상 키 (진단용)
     * @param  \Throwable  $e  원인 예외
     */
    protected function warnWriteFailure(string $operation, string $key, \Throwable $e): void
    {
        static $warned = [];

        $signature = $operation.'|'.$e->getMessage();
        if (isset($warned[$signature])) {
            return;
        }
        $warned[$signature] = true;

        try {
            Log::warning('캐시 쓰기 실패 — 무캐시로 계속 동작합니다 (스토리지 권한/디스크 확인 필요)', [
                'operation' => $operation,
                'key' => $key,
                'store' => $this->store,
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable) {
            // 로그 기록조차 불가한 환경(로그 디렉토리 권한 등) — 조용히 계속
        }
    }

    /**
     * 키-태그 매핑을 인덱스에 기록합니다.
     *
     * file/database 드라이버처럼 태그를 지원하지 않는 경우,
     * flush()와 flushTags()를 위해 키-태그 매핑을 별도 캐시 키에 기록합니다.
     *
     * @param  string  $resolvedKey  접두사 적용된 전체 키
     * @param  array  $tags  태그 배열
     * @return void
     */
    private function recordKeyTags(string $resolvedKey, array $tags): void
    {
        $indexKey = $this->getIndexKey();
        $index = $this->store()->get($indexKey, []);
        $index[$resolvedKey] = array_values(array_unique(
            array_merge($index[$resolvedKey] ?? [], $tags)
        ));
        $this->store()->put($indexKey, $index, 86400 * 30); // 30일
    }

    /**
     * 키 인덱스 기반으로 전체 캐시를 삭제합니다.
     *
     * @return bool 삭제 성공 여부
     */
    private function flushByIndex(): bool
    {
        try {
            $indexKey = $this->getIndexKey();
            $index = $this->store()->get($indexKey, []);

            foreach (array_keys($index) as $key) {
                $this->store()->forget($key);
            }

            $this->store()->forget($indexKey);

            return true;
        } catch (\Throwable $e) {
            $this->warnWriteFailure('flush', $this->getIndexKey(), $e);

            return false;
        }
    }

    /**
     * 키 인덱스 기반으로 특정 태그의 캐시를 삭제합니다.
     *
     * @param  array  $tags  삭제할 태그 배열
     * @return bool 삭제 성공 여부
     */
    private function flushTagsByIndex(array $tags): bool
    {
        try {
            $indexKey = $this->getIndexKey();
            $index = $this->store()->get($indexKey, []);
            $tagsSet = array_flip($tags);

            foreach ($index as $key => $keyTags) {
                if (array_intersect_key(array_flip($keyTags), $tagsSet)) {
                    $this->store()->forget($key);
                    unset($index[$key]);
                }
            }

            $this->store()->put($indexKey, $index, 86400 * 30);

            return true;
        } catch (\Throwable $e) {
            $this->warnWriteFailure('flushTags', implode(',', $tags), $e);

            return false;
        }
    }

    /**
     * 키 인덱스의 캐시 키를 반환합니다.
     *
     * @return string 인덱스 캐시 키
     */
    private function getIndexKey(): string
    {
        return 'g7:_idx:'.$this->getPrefix();
    }
}
