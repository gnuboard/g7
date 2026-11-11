<?php

namespace App\Support;

use App\Contracts\Extension\CacheInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * 공개 디스크 직접 주소의 보호 헤더 자기 점검
 *
 * 규칙 파일(`PublicDiskHardening`)을 만들어도 웹서버가 읽지 않으면(nginx, 재정의를 막은 Apache) 보호가 걸리지
 * 않는다. 공개 디스크 루트에 시험 파일을 잠깐 두고 사이트 자신의 `/storage/…` 주소를 불러 응답 헤더를 확인한다.
 * 결과는 캐시에 두고, 관리자 환경설정의 드라이버 탭이 걸리지 않았을 때만 경고를 보여 준다.
 *
 * 설정 저장을 막지 않는다 — 알리기만 한다.
 *
 * @since 7.1.6
 */
final class PublicDiskHardeningProbe
{
    /**
     * 점검 결과 캐시 키
     */
    public const CACHE_KEY = 'core.public_disk_hardening';

    /**
     * 점검 결과 보관 기간(초) — 설정을 다시 저장하면 갱신된다.
     */
    private const CACHE_TTL = 2592000;

    /**
     * 직접 주소의 응답 헤더를 점검하고 결과를 저장합니다.
     *
     * `ok` 는 nosniff 와 내려받기 지시가 모두 있을 때만 true, 파일은 내려왔는데(200) 하나라도 없거나
     * 접근이 막혔으면(403 — 헤더 모듈 없음) false 다. 서버가 자기 주소에 닿지 못했거나 점검 파일이
     * 내려오지 않은 응답(리다이렉트·404·서버 오류)은 보호 규칙 적용 여부를 알려 주지 않으므로 null 이다.
     *
     * @param  string|null  $directory  공개 디스크 루트 (null 이면 `storage/app/public`)
     * @return array{ok: bool|null, nosniff: bool, disposition: bool, status: int|null, checked_at: string} 점검 결과
     */
    public static function run(?string $directory = null): array
    {
        $directory ??= storage_path('app/public');

        $result = [
            'ok' => null,
            'nosniff' => false,
            'disposition' => false,
            'status' => null,
            'checked_at' => now()->toIso8601String(),
        ];

        $name = 'g7-header-probe-'.strtolower(Str::random(16)).'.txt';
        $file = $directory.DIRECTORY_SEPARATOR.$name;

        if (! is_dir($directory) || @file_put_contents($file, 'g7 header probe') === false) {
            return self::store($result);
        }

        try {
            $url = SiteUrl::absolute('/storage/'.$name);

            $response = Http::timeout(3)
                ->withOptions(SiteLoopback::transportOptions($url) + ['allow_redirects' => false])
                ->get($url);

            $result['status'] = $response->status();
            $result['nosniff'] = strtolower(trim((string) $response->header('X-Content-Type-Options'))) === 'nosniff';
            $result['disposition'] = str_starts_with(
                strtolower(trim((string) $response->header('Content-Disposition'))),
                'attachment'
            );
            // 파일이 내려온 응답(200)과 보호 규칙이 막은 응답(403)만 판정한다. 리다이렉트·404·서버 오류는
            // 규칙 적용 여부와 무관한 원인(사이트 주소 설정, 링크 없음)이라 판정하지 않는다(ok=null).
            $result['ok'] = match (true) {
                $response->status() === 200 => $result['nosniff'] && $result['disposition'],
                $response->status() === 403 => false,
                default => null,
            };
        } catch (\Throwable) {
            // 서버가 자기 주소에 닿지 못함 — 판정하지 않는다(ok=null).
        } finally {
            @unlink($file);
        }

        return self::store($result);
    }

    /**
     * 저장된 점검 결과를 반환합니다.
     *
     * @return array{ok: bool|null, nosniff: bool, disposition: bool, status: int|null, checked_at: string}|null 결과 (없으면 null)
     */
    public static function cached(): ?array
    {
        $cached = app(CacheInterface::class)->get(self::CACHE_KEY);

        return is_array($cached) ? $cached : null;
    }

    /**
     * 저장된 점검 결과를 지웁니다(공개 디스크를 로컬 직접 주소로 쓰지 않게 됐을 때).
     *
     * @return void 반환값 없음
     */
    public static function forget(): void
    {
        app(CacheInterface::class)->forget(self::CACHE_KEY);
    }

    /**
     * 점검 결과를 캐시에 저장하고 그대로 돌려줍니다.
     *
     * @param  array{ok: bool|null, nosniff: bool, disposition: bool, status: int|null, checked_at: string}  $result  점검 결과
     * @return array{ok: bool|null, nosniff: bool, disposition: bool, status: int|null, checked_at: string} 같은 결과
     */
    private static function store(array $result): array
    {
        app(CacheInterface::class)->put(self::CACHE_KEY, $result, self::CACHE_TTL);

        return $result;
    }
}
