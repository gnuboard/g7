<?php

namespace App\Extension\Traits;

/**
 * 같은 프로세스 안에서 확장 진입 클래스(module.php·plugin.php) 파일이 바뀌었으면 새 정의로 인스턴스를 만드는 공통 조각
 *
 * PHP 는 한 프로세스에서 클래스를 다시 정의할 수 없다. 확장 업데이트(관리자 화면 [업데이트]·artisan 둘 다)는 부팅 때
 * 구버전 클래스를 이미 읽어 둔 프로세스 안에서 파일을 교체하고, 끝에 훅 캐시를 다시 굽는다. 그때 매니저가 목록을
 * 다시 읽으며 `new $class` 를 하면 **구버전** 인스턴스가 나와 `getHookListeners()` 가 옛 목록을 돌려주고, 새로 더한
 * 리스너가 `bootstrap/cache/hooks.php` 에서 빠졌다(2026-10-07 TOTP 업데이트: UserTotpEnrollmentInfoListener 누락).
 *
 * 처음 읽을 때 파일 지문을 적어 두고, 다시 읽을 때 디스크 지문이 다르면 새 파일을 임시 클래스명으로 eval 해
 * 그 인스턴스를 쓴다(지문별로 한 번만 eval). 지문 표는 정적이라 같은 프로세스의 다른 매니저 인스턴스도 공유한다.
 */
trait ReloadsChangedExtensionClass
{
    /** @var array<string, string> 진입 클래스 FQCN → 메모리에 올린 정의의 파일 지문 */
    private static array $loadedClassFileHashes = [];

    /** @var array<string, array{hash: string, instance: object}> 진입 클래스 FQCN → 바뀐 파일로 만든 최신 인스턴스 */
    private static array $freshClassInstances = [];

    /**
     * 진입 클래스를 처음 메모리에 올렸을 때 그 파일 지문을 적어 둡니다.
     *
     * @param  string  $class  진입 클래스 FQCN
     * @param  string  $file  진입 파일 경로
     * @return void 반환값 없음
     */
    protected function rememberLoadedExtensionClassFile(string $class, string $file): void
    {
        $hash = @md5_file($file);
        if (is_string($hash)) {
            self::$loadedClassFileHashes[$class] = $hash;
            unset(self::$freshClassInstances[$class]);
        }
    }

    /**
     * 바뀐 파일로 eval 해 만든 인스턴스를 그 지문과 함께 적어 둡니다 (같은 지문으로 다시 eval 하지 않게).
     *
     * @param  string  $class  진입 클래스 FQCN
     * @param  string  $file  진입 파일 경로
     * @param  object  $instance  새로 만든 인스턴스
     * @return void 반환값 없음
     */
    protected function rememberFreshExtensionInstance(string $class, string $file, object $instance): void
    {
        $hash = @md5_file($file);
        if (is_string($hash)) {
            self::$freshClassInstances[$class] = ['hash' => $hash, 'instance' => $instance];
        }
    }

    /**
     * 디스크의 현재 파일 정의에 맞는 진입 클래스 인스턴스를 돌려줍니다.
     *
     * 메모리의 정의가 디스크와 같으면 `new $class`, 다르면 `$evalFresh` 로 새 파일을 읽은 인스턴스(지문별 1회)를 쓴다.
     * 지문을 적어 두지 않은 클래스(다른 경로로 먼저 올라온 것)는 지금 디스크와 같다고 보고 기록만 한다.
     *
     * @param  string  $class  진입 클래스 FQCN (이미 메모리에 있어야 한다)
     * @param  string  $file  진입 파일 경로
     * @param  callable(): ?object  $evalFresh  새 파일을 임시 클래스명으로 읽어 인스턴스를 만드는 함수
     * @return object|null 인스턴스 (만들 수 없으면 null)
     */
    protected function instantiateCurrentExtensionClass(string $class, string $file, callable $evalFresh): ?object
    {
        $diskHash = @md5_file($file);
        $loadedHash = self::$loadedClassFileHashes[$class] ?? null;

        if ($loadedHash === null && is_string($diskHash)) {
            self::$loadedClassFileHashes[$class] = $loadedHash = $diskHash;
        }

        if (is_string($diskHash) && $diskHash !== $loadedHash) {
            $cached = self::$freshClassInstances[$class] ?? null;
            if ($cached !== null && $cached['hash'] === $diskHash) {
                return $cached['instance'];
            }

            $fresh = $evalFresh();
            if (is_object($fresh)) {
                self::$freshClassInstances[$class] = ['hash' => $diskHash, 'instance' => $fresh];

                return $fresh;
            }
        }

        return class_exists($class) ? new $class : null;
    }
}
