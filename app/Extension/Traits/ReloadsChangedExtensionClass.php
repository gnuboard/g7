<?php

namespace App\Extension\Traits;

use App\Extension\ExtensionManager;

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
    /** 실은 정의의 지문을 알 수 없음 — 싣는 사이 파일이 바뀌었거나 다른 경로로 먼저 올라온 클래스 */
    private const UNKNOWN_DECLARATION = '?';

    /** @var array<string, string> 진입 클래스 FQCN → 메모리에 올린 정의의 파일 지문 */
    private static array $loadedClassFileHashes = [];

    /** @var array<string, array{hash: string, instance: object}> 진입 클래스 FQCN → 바뀐 파일로 만든 최신 인스턴스 */
    private static array $freshClassInstances = [];

    /** @var array<string, string> 진입 클래스 FQCN → 이 프로세스가 마지막으로 인스턴스를 만들 때 쓴 정의의 파일 지문 */
    private static array $usedDeclarationHashes = [];

    /**
     * 진입 클래스를 처음 메모리에 올렸을 때 그 파일 지문을 적어 둡니다.
     *
     * 싣기(require) **전에** 뜬 지문을 함께 받는다. 실은 뒤에만 지문을 뜨면, 실은 직후 파일이 교체된 경우 기록은 새 파일 것이고
     * 메모리 클래스는 옛것이 되어 옛 선언을 지금 정의로 보았다(교체 중 부팅한 요청의 요청 끝 훅 캐시 재생성이 지운 리스너를 되살림).
     * 앞뒤 지문이 다르면 실은 정의를 알 수 없으므로 「알 수 없음」으로 적는다 — 그러면 다음 인스턴스 생성이 새 파일을 다시 읽고,
     * 읽지 못하면 훅 캐시처럼 선언으로 만드는 산출물의 쓰기가 거절된다({@see usedDeclarationIsCurrent()}).
     *
     * @param  string  $class  진입 클래스 FQCN
     * @param  string  $file  진입 파일 경로
     * @param  string|null  $hashBeforeLoad  싣기 전에 뜬 파일 지문 (null 이면 실은 뒤 지문만 쓴다 — 종전 동작)
     * @return void 반환값 없음
     */
    protected function rememberLoadedExtensionClassFile(string $class, string $file, ?string $hashBeforeLoad = null): void
    {
        $hash = @md5_file($file);
        if (! is_string($hash)) {
            return;
        }

        self::$loadedClassFileHashes[$class] = ($hashBeforeLoad === null || $hashBeforeLoad === $hash) ? $hash : self::UNKNOWN_DECLARATION;
        unset(self::$freshClassInstances[$class]);
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

        // 다른 경로로 먼저 올라온 클래스 — 보통 부팅 오토로드(`ExtensionManager` 의 진입 파일 싣기)다. 그쪽이 싣기 전·후로 뜬 지문을
        // 쓴다(싣는 사이 교체됐으면 「알 수 없음」이라 아래에서 새 파일을 한 번 읽는다). 기록 자체가 없는 경로(Composer 오토로드 —
        // 확장 PHPUnit 이 시험 대상 클래스를 먼저 싣는다)는 지금 파일 그대로의 정의로 본다. 「알 수 없음」으로 두면 매번 새 정의를
        // eval 해 다른 클래스(`*_fresh_*`)가 나가 타입 선언·instanceof 가 깨진다.
        if ($loadedHash === null && is_string($diskHash)) {
            self::$loadedClassFileHashes[$class] = $loadedHash = ExtensionManager::entryFileLoadHash($file) ?? $diskHash;
        }

        if (is_string($diskHash) && $diskHash !== $loadedHash) {
            $cached = self::$freshClassInstances[$class] ?? null;
            if ($cached !== null && $cached['hash'] === $diskHash) {
                self::$usedDeclarationHashes[$class] = $diskHash;

                return $cached['instance'];
            }

            $fresh = $evalFresh();
            if (is_object($fresh)) {
                self::$freshClassInstances[$class] = ['hash' => $diskHash, 'instance' => $fresh];
                self::$usedDeclarationHashes[$class] = $diskHash;

                return $fresh;
            }
        }

        // 메모리의 정의를 그대로 쓴다 — 새 파일을 읽지 못했으면 옛 정의다(쓴 지문으로 남겨 훅 캐시 쓰기가 가려낸다)
        if ($loadedHash !== null) {
            self::$usedDeclarationHashes[$class] = $loadedHash;
        }

        return class_exists($class) ? new $class : null;
    }

    /**
     * 이 프로세스가 그 진입 클래스에 쓴 정의가 지금 디스크의 파일과 같은지 판정합니다.
     *
     * 훅 캐시처럼 확장 선언으로 만드는 공유 산출물을 쓰기 직전에 본다. 교체 중에 부팅해 옛 정의를 쥔 프로세스가 새 선언으로
     * 이미 만든 산출물을 옛 선언으로 덮지 않게 한다. 쓴 기록이 없거나 파일을 읽을 수 없으면 판정하지 않는다(같다고 본다).
     *
     * @param  string  $class  진입 클래스 FQCN
     * @param  string  $file  진입 파일 경로
     * @return bool 같으면(또는 판정할 수 없으면) true
     */
    protected function usedDeclarationIsCurrent(string $class, string $file): bool
    {
        $used = self::$usedDeclarationHashes[$class] ?? null;
        $disk = @md5_file($file);

        return $used === null || ! is_string($disk) || $used === $disk;
    }
}
