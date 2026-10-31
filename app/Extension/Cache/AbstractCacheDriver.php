<?php

namespace App\Extension\Cache;

use App\Contracts\Extension\CacheInterface;
use Illuminate\Cache\Lock;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
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
     * 키 인덱스 조각 수
     *
     * 인덱스를 한 값에 두면 쓸 때마다 전체를 읽고 다시 써서, 키가 많은 확장(게시판 조회수 중복 방지 —
     * 글×방문자마다 하루짜리 키)에서 put 한 번이 수십 ms 가 됐다(실측: 5만 항목 84ms). 키 해시로 나눠
     * 한 조각만 고친다.
     */
    private const INDEX_SHARDS = 64;

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
            $stored = $this->store()->put($resolvedKey, $value, $ttl);
        } catch (\Throwable $e) {
            $this->warnWriteFailure('put', $resolvedKey, $e);

            return false;
        }

        if ($stored) {
            $this->recordPlainWrites([$resolvedKey], $ttl);
        }

        return $stored;
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
            $this->recordKeyTags([$resolvedKey], $allTags, $ttl);
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
            $stored = $this->store()->putMany($resolved, $ttl);
        } catch (\Throwable $e) {
            $this->warnWriteFailure('putMany', implode(',', array_keys($resolved)), $e);

            return false;
        }

        if ($stored) {
            $this->recordPlainWrites(array_keys($resolved), $ttl);
        }

        return $stored;
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
     * @param  string  $operation  실패한 연산 (put/forget/putMany/remember/index/index-lock)
     * @param  string  $key  대상 키 (진단용)
     * @param  \Throwable  $e  원인 예외
     * @return void 반환값 없음
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
     * put()·putMany() 로 둔 키를 키 인덱스에 기록할지 반환합니다.
     *
     * flush() 는 인덱스에 적힌 키만 지운다. 모듈·플러그인은 비활성·제거 때 flush() 로 자기 캐시를 비우므로
     * put() 키도 적어야 한다. 코어 드라이버는 flush() 호출처가 없고 put() 키가 IP·URL·코드마다 고유라,
     * 적으면 인덱스 쓰기만 늘어난다 — CoreCacheDriver 가 false 로 끈다.
     *
     * @return bool 기록 여부
     */
    protected function recordsPlainWrites(): bool
    {
        return true;
    }

    /**
     * put()·putMany() 로 둔 키를 소속 태그(확장 접두사)로 인덱스에 기록합니다.
     *
     * 값은 이미 저장됐으므로 인덱스 쓰기 실패는 경고만 남기고 저장 결과를 바꾸지 않는다.
     *
     * @param  array<int, string>  $resolvedKeys  접두사 적용된 전체 키 목록
     * @param  int  $ttl  값의 TTL (초)
     * @return void 반환값 없음
     */
    private function recordPlainWrites(array $resolvedKeys, int $ttl): void
    {
        if (! $this->recordsPlainWrites() || $ttl <= 0) {
            return;
        }

        try {
            $this->recordKeyTags($resolvedKeys, [$this->getPrefix()], $ttl);
        } catch (\Throwable $e) {
            $this->warnWriteFailure('index', implode(',', $resolvedKeys), $e);
        }
    }

    /**
     * 키-태그 매핑을 인덱스에 기록합니다.
     *
     * flush()와 flushTags()는 저장소의 태그 지원 여부와 관계없이 이 인덱스로 지운다.
     * 항목은 `['tags' => [...], 'expires' => 만료 시각]` 이고, 기록할 때마다 만료 시각이 지난 항목을 정리해
     * 인덱스 크기를 살아 있는 키 수로 묶는다(키마다 고유한 put() 키가 쌓이지 않게). 인덱스는 키 해시로
     * INDEX_SHARDS 조각에 나눠 두고, 키가 속한 조각만 잠그고 고친다.
     *
     * @param  array<int, string>  $resolvedKeys  접두사 적용된 전체 키 목록
     * @param  array  $tags  태그 배열
     * @param  int  $ttl  값의 TTL (초)
     * @return void 반환값 없음
     */
    private function recordKeyTags(array $resolvedKeys, array $tags, int $ttl): void
    {
        $byShard = [];
        foreach ($resolvedKeys as $resolvedKey) {
            $byShard[$this->shardIndexKey($resolvedKey)][] = $resolvedKey;
        }

        foreach ($byShard as $indexKey => $keys) {
            $this->withIndexLock($indexKey, function () use ($indexKey, $keys, $tags, $ttl): void {
                $now = Carbon::now()->getTimestamp();
                $index = $this->pruneExpiredEntries($this->store()->get($indexKey, []), $now);

                foreach ($keys as $resolvedKey) {
                    $index[$resolvedKey] = [
                        'tags' => array_values(array_unique(
                            array_merge(isset($index[$resolvedKey]) ? $this->entryTags($index[$resolvedKey]) : [], $tags)
                        )),
                        'expires' => $now + $ttl,
                    ];
                }

                $this->store()->put($indexKey, $index, 86400 * 30); // 30일
            });
        }
    }

    /**
     * 만료 시각이 지난 인덱스 항목을 뺍니다. 만료 시각이 없는 옛 형식 항목은 그대로 둔다.
     *
     * @param  array  $index  키 인덱스
     * @param  int  $now  현재 시각 (유닉스 초)
     * @return array 정리한 키 인덱스
     */
    private function pruneExpiredEntries(array $index, int $now): array
    {
        return array_filter(
            $index,
            fn ($entry) => ! is_array($entry) || ! isset($entry['expires']) || $entry['expires'] > $now
        );
    }

    /**
     * 인덱스 항목의 태그 목록을 반환합니다.
     *
     * 옛 형식(태그 배열 그대로)과 새 형식(`['tags' => [...], 'expires' => …]`)을 모두 읽는다.
     *
     * @param  mixed  $entry  인덱스 항목
     * @return array<int, string> 태그 목록
     */
    private function entryTags(mixed $entry): array
    {
        if (! is_array($entry)) {
            return [];
        }

        return array_key_exists('tags', $entry) && is_array($entry['tags']) ? $entry['tags'] : $entry;
    }

    /**
     * 키 인덱스를 고치는 동안 잠금을 겁니다.
     *
     * 인덱스 갱신은 「읽기 → 고치기 → 쓰기」라 여러 요청이 동시에 고치면 서로의 기록을 덮어써
     * 일부 키가 인덱스에서 빠진다. 빠진 키는 태그 비우기로 지워지지 않아, 게시판을 만들어도 메뉴
     * 캐시가 TTL(최대 하루) 동안 예전 값으로 남았다(실측: 8 프로세스 동시 저장 → 인덱스 1~3개).
     *
     * 저장소가 잠금을 지원할 때만 건다. 잠금을 2초 안에 얻지 못하거나 잠금 자체가 실패하면
     * 경고를 남기고 잠금 없이 진행한다 — 캐시 쓰기 실패와 같은 fail-soft 태도다(잠금 장애가
     * 화면 장애가 되면 안 된다).
     *
     * @param  string  $indexKey  고칠 인덱스(조각) 키 — 잠금은 조각마다 따로 건다
     * @param  callable  $mutate  인덱스를 읽고 고쳐 쓰는 콜백
     * @return mixed 콜백 반환값
     */
    private function withIndexLock(string $indexKey, callable $mutate): mixed
    {
        $store = $this->store()->getStore();

        if (! $store instanceof LockProvider) {
            return $mutate();
        }

        try {
            $lock = $store->lock($indexKey.':lock', 5);
        } catch (\Throwable $e) {
            $this->warnWriteFailure('index-lock', $indexKey, $e);

            return $mutate();
        }

        // 콜백이 시작됐는지·끝났는지로 예외의 출처를 가른다 — 잠금을 얻는 중의 오류는 잠금 없이 다시 고치고,
        // 콜백 자체의 오류는 그대로 올려 보내고(두 번 고치지 않는다), 고친 뒤 잠금 해제 오류는 결과를 살린다.
        $started = false;
        $done = false;
        $result = null;
        $guarded = function () use ($mutate, &$started, &$done, &$result) {
            $started = true;
            $result = $mutate();
            $done = true;

            return $result;
        };

        try {
            // 기본 재시도 간격(250ms)은 2초 대기에 8번뿐이다 — 인덱스 갱신은 짧으니 촘촘히 다시 묻는다
            if ($lock instanceof Lock) {
                $lock->betweenBlockedAttemptsSleepFor(10);
            }

            return $lock->block(2, $guarded);
        } catch (LockTimeoutException $e) {
            $this->warnWriteFailure('index-lock', $indexKey, $e);

            return $mutate();
        } catch (\Throwable $e) {
            if ($started && ! $done) {
                throw $e;
            }

            $this->warnWriteFailure('index-lock', $indexKey, $e);

            return $done ? $result : $mutate();
        }
    }

    /**
     * 키 인덱스 기반으로 전체 캐시를 삭제합니다.
     *
     * 모든 조각과 옛 단일 인덱스를 본다. 빈 조각은 잠그지 않고 건너뛴다.
     *
     * @return bool 삭제 성공 여부
     */
    private function flushByIndex(): bool
    {
        try {
            foreach ($this->allIndexKeys() as $indexKey) {
                if ($this->store()->get($indexKey, []) === []) {
                    continue;
                }

                $this->withIndexLock($indexKey, function () use ($indexKey): void {
                    foreach (array_keys($this->store()->get($indexKey, [])) as $key) {
                        $this->store()->forget($key);
                    }

                    $this->store()->forget($indexKey);
                });
            }

            return true;
        } catch (\Throwable $e) {
            $this->warnWriteFailure('flush', $this->legacyIndexKey(), $e);

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
        $tagsSet = array_flip($tags);

        try {
            foreach ($this->allIndexKeys() as $indexKey) {
                if ($this->store()->get($indexKey, []) === []) {
                    continue;
                }

                $this->withIndexLock($indexKey, function () use ($indexKey, $tagsSet): void {
                    $index = $this->store()->get($indexKey, []);
                    $changed = false;

                    foreach ($index as $key => $entry) {
                        if (array_intersect_key(array_flip($this->entryTags($entry)), $tagsSet)) {
                            $this->store()->forget($key);
                            unset($index[$key]);
                            $changed = true;
                        }
                    }

                    if ($changed) {
                        $this->store()->put($indexKey, $index, 86400 * 30);
                    }
                });
            }

            return true;
        } catch (\Throwable $e) {
            $this->warnWriteFailure('flushTags', implode(',', $tags), $e);

            return false;
        }
    }

    /**
     * 옛 단일 키 인덱스의 캐시 키를 반환합니다.
     *
     * 조각으로 나누기 전의 인덱스다. 새 기록은 하지 않고, flush()·flushTags() 가 남은 항목을 지울 때만 읽는다.
     *
     * @return string 인덱스 캐시 키
     */
    private function legacyIndexKey(): string
    {
        return 'g7:_idx:'.$this->getPrefix();
    }

    /**
     * 키가 속한 인덱스 조각의 캐시 키를 반환합니다.
     *
     * @param  string  $resolvedKey  접두사 적용된 전체 키
     * @return string 조각 인덱스 캐시 키 (`g7:_idx:{접두사}:{0..INDEX_SHARDS-1}`)
     */
    private function shardIndexKey(string $resolvedKey): string
    {
        // crc32 는 선형이라 숫자 몇 자만 다른 키(글 번호·방문자)가 하위 비트를 덜 바꿔 조각의 절반만 쓴다(실측 200키 → 32조각) — md5 앞 4자리로 고르게 나눈다
        return $this->legacyIndexKey().':'.(hexdec(substr(md5($resolvedKey), 0, 4)) % self::INDEX_SHARDS);
    }

    /**
     * 모든 인덱스 조각과 옛 단일 인덱스의 캐시 키를 반환합니다.
     *
     * @return array<int, string> 인덱스 캐시 키 목록
     */
    private function allIndexKeys(): array
    {
        $keys = [];
        for ($shard = 0; $shard < self::INDEX_SHARDS; $shard++) {
            $keys[] = $this->legacyIndexKey().':'.$shard;
        }
        $keys[] = $this->legacyIndexKey();

        return $keys;
    }
}
