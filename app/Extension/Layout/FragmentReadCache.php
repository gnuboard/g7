<?php

namespace App\Extension\Layout;

use App\Extension\Traits\CollectsFragmentSources;

/**
 * 확장 조각 소스 읽기 결과를 프로세스 동안 기억합니다 — 성공한 컴파일 결과와 실패 원인 둘 다.
 *
 * 조각 컴파일은 템플릿과 무관하다(2026-09-28 결정). 그런데 등록 경로는 템플릿마다·경로마다 같은 파일을 다시 읽고,
 * 실패는 기억하지 않아 깨진 조각 하나가 확장 활성화 한 번에 「활성 템플릿 수 × 경로 수」 줄의 ERROR 를 남겼다(#725).
 * 실패도 성공과 같은 키(경로+mtime+크기)로 기억해 **같은 파일의 같은 실패는 프로세스에 한 번만** 알린다. 파일을
 * 고치면 mtime·크기가 바뀌어 새 키가 되므로 다시 읽는다.
 *
 * 읽기 트레이트({@see CollectsFragmentSources})의 정적 속성으로 두지 않는 이유: 트레이트의
 * 정적 속성은 **트레이트를 쓰는 클래스마다 따로** 생긴다. 확장 관리자·레이아웃 확장 서비스·점검 명령이 같은 파일을
 * 읽으면 클래스 수만큼 다시 컴파일하고 다시 기록했다.
 */
final class FragmentReadCache
{
    /**
     * 컴파일에 성공한 조각 JSON (키 → JSON).
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $compiled = [];

    /**
     * 읽기에 실패한 조각의 원인 (키 → 메시지).
     *
     * @var array<string, string>
     */
    private static array $failures = [];

    /**
     * 경로별 마지막 실패 원인 (경로 → 메시지) — 점검 명령이 보고서에 원인을 싣는다.
     *
     * @var array<string, string>
     */
    private static array $failuresByPath = [];

    /**
     * 이미 한 번 알린 안내의 키 집합.
     *
     * @var array<string, true>
     */
    private static array $notices = [];

    /**
     * 파일 하나의 캐시 키를 만듭니다 — 경로 + mtime + 크기.
     *
     * @param  string  $path  파일 경로
     * @return string 캐시 키
     */
    public static function key(string $path): string
    {
        return $path.'|'.(@filemtime($path) ?: 0).'|'.(@filesize($path) ?: 0);
    }

    /**
     * 기억한 컴파일 결과를 돌려줍니다.
     *
     * @param  string  $key  캐시 키
     * @return array<string, mixed>|null 없으면 null
     */
    public static function compiled(string $key): ?array
    {
        return self::$compiled[$key] ?? null;
    }

    /**
     * 컴파일 결과를 기억합니다.
     *
     * @param  string  $key  캐시 키
     * @param  array<string, mixed>  $json  컴파일된 조각 JSON
     * @return array<string, mixed> 받은 JSON 그대로
     */
    public static function rememberCompiled(string $key, array $json): array
    {
        return self::$compiled[$key] = $json;
    }

    /**
     * 기억한 실패 원인을 돌려줍니다.
     *
     * @param  string  $key  캐시 키
     * @return string|null 실패한 적이 없으면 null
     */
    public static function failure(string $key): ?string
    {
        return self::$failures[$key] ?? null;
    }

    /**
     * 실패 원인을 기억합니다.
     *
     * @param  string  $key  캐시 키
     * @param  string  $path  파일 경로
     * @param  string  $message  실패 원인
     * @return void 반환값 없음
     */
    public static function rememberFailure(string $key, string $path, string $message): void
    {
        self::$failures[$key] = $message;
        self::$failuresByPath[$path] = $message;
    }

    /**
     * 경로의 마지막 실패 원인을 돌려줍니다.
     *
     * @param  string  $path  파일 경로
     * @return string|null 실패한 적이 없으면 null
     */
    public static function lastFailureFor(string $path): ?string
    {
        return self::$failuresByPath[$path] ?? null;
    }

    /**
     * 같은 안내를 처음 알리는 것인지 판정하고 표시합니다 — 처음이면 true.
     *
     * @param  string  $key  안내 키
     * @return bool 처음이면 true
     */
    public static function firstNotice(string $key): bool
    {
        if (isset(self::$notices[$key])) {
            return false;
        }

        return self::$notices[$key] = true;
    }

    /**
     * 기억한 모든 결과를 비웁니다 — 테스트가 프로세스 안에서 처음 상태로 되돌릴 때 쓴다.
     *
     * @return void 반환값 없음
     */
    public static function flush(): void
    {
        self::$compiled = [];
        self::$failures = [];
        self::$failuresByPath = [];
        self::$notices = [];
    }
}
