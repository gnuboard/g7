<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 범용 캐시 서비스
 *
 * 시스템 전반에서 사용되는 캐시를 일관되게 관리합니다.
 * ServiceProvider register() 시점에서도 안전하게 사용할 수 있습니다.
 */
class CacheService
{
    /**
     * 캐시 키 prefix
     */
    private const PREFIX = 'g7';

    /**
     * 기본 TTL (24시간)
     */
    private const DEFAULT_TTL = 86400;

    /**
     * 캐시 키 생성
     *
     * @param  string  $group  캐시 그룹 (예: 'modules', 'plugins', 'templates')
     * @param  string  $key  캐시 키
     * @return string 전체 캐시 키
     */
    public static function key(string $group, string $key): string
    {
        return self::PREFIX.':'.$group.':'.$key;
    }

    /**
     * DB 연결이 준비되었는지 확인합니다.
     *
     * @return bool DB 연결 가능 여부
     */
    protected static function isDatabaseReady(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 캐시에서 값을 조회하거나 콜백 결과를 캐시합니다.
     * DB 테이블이 없거나 연결 오류 시 빈 배열을 반환합니다.
     *
     * 중요: 빈 배열([])은 캐시하지 않습니다.
     * - 모듈/플러그인 활성 목록이 빈 배열로 캐시되면 리스너 등록 실패 문제 방지
     * - 빈 배열이 정상적인 상태라면 매 요청마다 DB 조회 (성능 영향 최소화)
     *
     * @param  string  $group  캐시 그룹
     * @param  string  $key  캐시 키
     * @param  callable  $callback  캐시 미스 시 실행할 콜백
     * @param  int|null  $ttl  캐시 TTL (초), null이면 기본값 사용
     * @param  string|null  $requiredTable  필수 테이블명 (DB 준비 상태 확인용)
     * @return mixed 캐시된 값 또는 콜백 결과
     */
    public static function remember(
        string $group,
        string $key,
        callable $callback,
        ?int $ttl = null,
        ?string $requiredTable = null
    ): mixed {
        // DB 테이블 확인이 필요한 경우
        if ($requiredTable !== null) {
            // DB 연결 확인
            if (! self::isDatabaseReady()) {
                return [];
            }

            try {
                if (! Schema::hasTable($requiredTable)) {
                    return [];
                }
            } catch (\Exception $e) {
                return [];
            }
        }

        $cacheKey = self::key($group, $key);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        try {
            // 캐시에서 값 조회
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }

            // 캐시 미스: 콜백 실행
            $value = $callback();

            // 빈 배열은 캐시하지 않음 (모듈/플러그인 상태 캐시 문제 방지)
            if (is_array($value) && empty($value)) {
                Log::debug('빈 배열은 캐시하지 않음', [
                    'key' => $cacheKey,
                ]);

                return $value;
            }

            // 값이 있는 경우에만 캐시 저장
            Cache::put($cacheKey, $value, $ttl);

            return $value;
        } catch (\Exception $e) {
            Log::warning('캐시 조회 실패, 콜백 직접 실행', [
                'key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);

            try {
                return $callback();
            } catch (\Exception $e) {
                return [];
            }
        }
    }

    /**
     * 캐시에서 값을 조회합니다.
     *
     * @param  string  $group  캐시 그룹
     * @param  string  $key  캐시 키
     * @param  mixed  $default  기본값
     * @return mixed 캐시된 값 또는 기본값
     */
    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        return Cache::get(self::key($group, $key), $default);
    }

    /**
     * 캐시에 값을 저장합니다.
     *
     * @param  string  $group  캐시 그룹
     * @param  string  $key  캐시 키
     * @param  mixed  $value  저장할 값
     * @param  int|null  $ttl  캐시 TTL (초)
     * @return bool 저장 성공 여부
     */
    public static function put(string $group, string $key, mixed $value, ?int $ttl = null): bool
    {
        return Cache::put(self::key($group, $key), $value, $ttl ?? self::DEFAULT_TTL);
    }

    /**
     * 특정 캐시 키를 삭제합니다.
     *
     * @param  string  $group  캐시 그룹
     * @param  string  $key  캐시 키
     * @return bool 삭제 성공 여부
     */
    public static function forget(string $group, string $key): bool
    {
        return Cache::forget(self::key($group, $key));
    }

    /**
     * 그룹 내 여러 캐시 키를 삭제합니다.
     *
     * @param  string  $group  캐시 그룹
     * @param  array<string>  $keys  삭제할 캐시 키 배열
     * @return void
     */
    public static function forgetMany(string $group, array $keys): void
    {
        foreach ($keys as $key) {
            self::forget($group, $key);
        }
    }

    /**
     * 캐시 태그가 지원되는 경우 태그로 캐시를 삭제합니다.
     *
     * @param  array<string>  $tags  삭제할 태그 배열
     * @return bool 삭제 성공 여부
     */
    public static function flushTags(array $tags): bool
    {
        try {
            if (method_exists(Cache::getStore(), 'tags')) {
                Cache::tags($tags)->flush();

                return true;
            }
        } catch (\Exception $e) {
            Log::warning('태그 기반 캐시 삭제 실패', [
                'tags' => $tags,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * 캐시를 무효화하고 새로고침합니다.
     *
     * @param  string  $group  캐시 그룹
     * @param  string  $key  캐시 키
     * @param  callable  $callback  새 값을 생성할 콜백
     * @param  int|null  $ttl  캐시 TTL (초)
     * @return mixed 새로 캐시된 값
     */
    public static function refresh(string $group, string $key, callable $callback, ?int $ttl = null): mixed
    {
        self::forget($group, $key);

        return self::remember($group, $key, $callback, $ttl);
    }
}
