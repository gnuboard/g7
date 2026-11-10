<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 부팅 캐시 파일(bootstrap/cache/*.php)을 비는 순간 없이 바꿔 끼우는 도우미.
 *
 * 종전에는 라우트·설정 캐시를 「지운 뒤 다시 굽고」(route:clear → route:cache), 훅·확장 오토로드 캐시를 제자리에
 * 덮어썼다(File::put). 굽는 1~2초 사이에 들어온 다른 요청은 `file_exists()` 를 통과한 뒤 `require` 직전에 파일이
 * 지워져 500(`routes-v7.php: No such file`)을 받거나, 반쯤 쓰인 파일을 읽었다 — 확장을 켜고 끄는 동안 다른 관리자가
 * 화면을 열면 「초기화 실패 Failed to reload routes」가 났다(#882 실측). 이제 새 파일을 같은 폴더의 임시 파일에 다 쓴 뒤
 * `rename` 으로 한 번에 바꿔 끼운다.
 *
 * Windows 는 다른 요청이 그 순간 파일을 열고 있으면 `rename` 이 「Access is denied」로 실패하므로 짧게 다시 시도한다.
 * 끝내 못 바꾸면 종전 방식으로 물러선다(낡은 캐시는 폴백이 없으므로 남겨 두지 않는다).
 */
final class AtomicBootstrapCache
{
    /**
     * 바꿔 끼우기 재시도 횟수.
     */
    public const PROMOTE_ATTEMPTS = 20;

    /**
     * 재시도 간격(밀리초).
     */
    public const PROMOTE_INTERVAL_MS = 50;

    /**
     * 캐시를 굽는 명령의 출력 경로를 임시 파일로 돌려 굽고, 다 구워지면 실제 파일과 바꿔 끼웁니다.
     *
     * Laravel 의 `route:cache`·`config:cache` 는 출력 경로를 `APP_ROUTES_CACHE`·`APP_CONFIG_CACHE` 환경 값에서 매번
     * 읽는다(명령 안의 `X:clear`·일회용 앱의 캐시 확인도 같은 값을 본다). 굽는 동안만 그 값을 임시 파일로 돌리므로
     * 실제 파일은 굽는 내내 그대로 남아 다른 요청이 계속 쓴다. 굽기가 실패하면(임시 파일 없음·예외) 낡은 캐시를
     * `$clearStale` 로 지운다 — 다음 요청은 비캐시로 정확하게 부팅한다(종전 「먼저 지우기」와 같은 결과).
     *
     * @param  string  $envKey  출력 경로 환경 값 이름(`APP_ROUTES_CACHE`·`APP_CONFIG_CACHE`)
     * @param  string  $targetPath  실제 캐시 파일 절대 경로(돌리기 전에 구한 값)
     * @param  callable  $generate  캐시를 굽는 작업(예: 라우트 캐시 굽기 명령)
     * @param  callable  $clearStale  굽기 실패 시 낡은 캐시를 지우는 작업(예: 라우트 캐시 지우기 명령)
     * @return bool 새 캐시로 바꿔 끼웠으면 true
     *
     * @throws Throwable 굽는 작업이 던진 예외(낡은 캐시를 지운 뒤 다시 던진다)
     */
    public static function regenerate(string $envKey, string $targetPath, callable $generate, callable $clearStale): bool
    {
        $relativeTemp = self::relativeTempPath($targetPath);
        if ($relativeTemp === null) {
            // 실제 파일이 앱 폴더 밖이면 상대 경로로 돌릴 수 없다 — 종전 방식(지운 뒤 굽기)
            $clearStale();
            $generate();

            return File::exists($targetPath);
        }

        $tempPath = base_path($relativeTemp);
        $previous = self::redirectEnv($envKey, $relativeTemp);
        $error = null;

        try {
            $generate();
        } catch (Throwable $e) {
            $error = $e;
        } finally {
            self::restoreEnv($envKey, $previous);
        }

        if ($error === null && File::exists($tempPath) && self::promote($tempPath, $targetPath)) {
            return true;
        }

        File::delete($tempPath);
        $clearStale();

        if ($error !== null) {
            throw $error;
        }

        return false;
    }

    /**
     * 내용을 같은 폴더의 임시 파일에 다 쓴 뒤 실제 파일과 바꿔 끼웁니다.
     *
     * @param  string  $targetPath  실제 파일 절대 경로
     * @param  string  $content  새 내용
     * @return void 반환값 없음
     */
    public static function write(string $targetPath, string $content): void
    {
        $tempPath = self::tempPathBeside($targetPath);

        if (File::put($tempPath, $content) !== false && File::exists($tempPath) && self::promote($tempPath, $targetPath)) {
            return;
        }

        // 바꿔 끼우지 못했다 — 임시 파일을 치우고 종전처럼 제자리에 쓴다
        File::delete($tempPath);
        File::put($targetPath, $content);
    }

    /**
     * 다 쓴 임시 파일을 실제 파일 자리로 옮깁니다 — 다른 요청이 열고 있어 실패하면 짧게 다시 시도합니다.
     *
     * @param  string  $tempPath  임시 파일 경로
     * @param  string  $targetPath  실제 파일 경로
     * @return bool 옮겼으면 true
     */
    public static function promote(string $tempPath, string $targetPath): bool
    {
        for ($attempt = 1; $attempt <= self::PROMOTE_ATTEMPTS; $attempt++) {
            try {
                // rename 실패는 경고로 나고 Laravel 오류 처리기가 ErrorException 으로 바꾼다 — 재시도 대상이다
                $moved = File::move($tempPath, $targetPath);
            } catch (\ErrorException) {
                $moved = false;
            }

            if ($moved) {
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($targetPath, true);
                }

                return true;
            }

            if ($attempt < self::PROMOTE_ATTEMPTS) {
                usleep(self::PROMOTE_INTERVAL_MS * 1000);
            }
        }

        Log::warning('부팅 캐시 파일을 바꿔 끼우지 못했습니다 — 다른 프로세스가 붙잡고 있습니다', [
            'target' => basename($targetPath),
            'attempts' => self::PROMOTE_ATTEMPTS,
        ]);

        return false;
    }

    /**
     * 실제 파일과 같은 폴더의 임시 파일 경로를 만듭니다.
     *
     * @param  string  $targetPath  실제 파일 경로
     * @return string 임시 파일 절대 경로
     */
    private static function tempPathBeside(string $targetPath): string
    {
        return dirname($targetPath).DIRECTORY_SEPARATOR.self::tempName($targetPath);
    }

    /**
     * 실제 파일 옆 임시 파일의 앱 폴더 기준 상대 경로를 만듭니다 — 앱 폴더 밖이면 null.
     *
     * Laravel 은 환경 값이 `/`·`\` 로 시작하지 않으면 앱 폴더 기준 상대 경로로 본다(Windows 의 `C:\…` 절대 경로는
     * 받지 않는다). 그래서 앱 폴더 기준 상대 경로로 돌린다.
     *
     * @param  string  $targetPath  실제 파일 절대 경로
     * @return string|null 상대 경로
     */
    private static function relativeTempPath(string $targetPath): ?string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $dir = str_replace('\\', '/', dirname($targetPath)).'/';

        if (! str_starts_with(strtolower($dir), strtolower($base))) {
            return null;
        }

        return substr($dir, strlen($base)).self::tempName($targetPath);
    }

    /**
     * 프로세스마다 겹치지 않는 임시 파일 이름을 만듭니다.
     *
     * @param  string  $targetPath  실제 파일 경로
     * @return string 파일 이름
     */
    private static function tempName(string $targetPath): string
    {
        return pathinfo($targetPath, PATHINFO_FILENAME).'.'.getmypid().'.'.bin2hex(random_bytes(4)).'.tmp.php';
    }

    /**
     * 환경 값을 임시 경로로 돌리고 이전 값을 돌려줍니다.
     *
     * @param  string  $key  환경 값 이름
     * @param  string  $value  새 값
     * @return array{env: array{0: bool, 1: mixed}, server: array{0: bool, 1: mixed}, getenv: string|false} 이전 값
     */
    private static function redirectEnv(string $key, string $value): array
    {
        $previous = [
            'env' => [array_key_exists($key, $_ENV), $_ENV[$key] ?? null],
            'server' => [array_key_exists($key, $_SERVER), $_SERVER[$key] ?? null],
            'getenv' => getenv($key),
        ];

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");

        return $previous;
    }

    /**
     * 돌렸던 환경 값을 되돌립니다.
     *
     * @param  string  $key  환경 값 이름
     * @param  array{env: array{0: bool, 1: mixed}, server: array{0: bool, 1: mixed}, getenv: string|false}  $previous  이전 값
     * @return void 반환값 없음
     */
    private static function restoreEnv(string $key, array $previous): void
    {
        [$hadEnv, $envValue] = $previous['env'];
        if ($hadEnv) {
            $_ENV[$key] = $envValue;
        } else {
            unset($_ENV[$key]);
        }

        [$hadServer, $serverValue] = $previous['server'];
        if ($hadServer) {
            $_SERVER[$key] = $serverValue;
        } else {
            unset($_SERVER[$key]);
        }

        putenv($previous['getenv'] === false ? $key : "{$key}={$previous['getenv']}");
    }
}
