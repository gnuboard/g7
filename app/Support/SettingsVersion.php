<?php

namespace App\Support;

use App\Contracts\Extension\CacheInterface;
use Illuminate\Support\Facades\Log;

/**
 * 설정 버전 — 설정을 저장할 때마다 공유 캐시에 저장 시각을 남긴다
 *
 * 큐 워커 같은 상주 프로세스는 부팅 때 읽은 설정을 프로세스가 끝날 때까지 들고 있다. 설정 저장은 저장한
 * 프로세스의 설정 미러만 다시 채우므로, 다른 프로세스가 「설정이 바뀌었다」 를 알 수 있도록 저장 시각을 공유
 * 캐시(코어 캐시 저장소 — 웹 요청과 워커가 함께 본다)에 남긴다.
 *
 * 워커 쪽 비교·다시 읽기는 {@see WorkerSettingsRefresher} 가 맡는다.
 */
class SettingsVersion
{
    /**
     * 공유 캐시 키
     */
    public const KEY = 'g7.settings.version';

    /**
     * 보관 기간(초) — 사실상 영구. 만료돼도 워커가 한 번 더 다시 읽을 뿐이다.
     */
    private const TTL = 31536000;

    /**
     * 설정 버전을 지금 시각(마이크로초 포함)으로 올립니다.
     *
     * 캐시 저장소가 실패해도 설정 저장 흐름을 막지 않는다 — 경고만 남기고, 워커는 다음 저장 또는 재시작 때 따라온다.
     *
     * @return void 반환값 없음
     */
    public static function bump(): void
    {
        try {
            if (! app(CacheInterface::class)->put(self::KEY, now()->format('Y-m-d H:i:s.u'), self::TTL)) {
                Log::warning('[Settings] 설정 버전 저장 실패', ['reason' => 'cache put returned false']);
            }
        } catch (\Throwable $e) {
            Log::warning('[Settings] 설정 버전 저장 실패', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 현재 설정 버전을 돌려줍니다.
     *
     * @return string|null 마지막 저장 시각 (`Y-m-d H:i:s.u`), 기록이 없거나 읽지 못하면 null
     */
    public static function current(): ?string
    {
        try {
            $value = app(CacheInterface::class)->get(self::KEY);
        } catch (\Throwable $e) {
            Log::warning('[Settings] 설정 버전 조회 실패', ['error' => $e->getMessage()]);

            return null;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * 설정 버전을 지웁니다 (테스트 격리·진단용).
     *
     * @return void 반환값 없음
     */
    public static function forget(): void
    {
        try {
            app(CacheInterface::class)->forget(self::KEY);
        } catch (\Throwable $e) {
            Log::warning('[Settings] 설정 버전 삭제 실패', ['error' => $e->getMessage()]);
        }
    }
}
