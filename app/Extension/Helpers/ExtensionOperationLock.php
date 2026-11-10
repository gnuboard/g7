<?php

namespace App\Extension\Helpers;

use App\Exceptions\ExtensionOperationInProgressException;
use App\Exceptions\ExtensionOperationLockUnavailableException;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * 같은 대상(확장·언어팩·코어)의 작업을 한 번에 하나만 수행하게 하는 원자적 잠금
 *
 * 상태 가드(ExtensionStatusGuard)는 status=updating 을 세운 뒤에야 막는다. 그 전 구간(업데이트 확인·백업)에 들어온 두 번째
 * 요청은 함께 통과해 업데이트가 두 번 수행됐다(#138 실측 ② — 버튼 더블클릭). Manager 진입점에서 잠그므로 CLI·관리자 화면·
 * 일괄 업데이트가 같은 잠금을 공유한다. 7.1.5 부터 설치·활성화·비활성화·제거와 언어팩 작업, 코어 업데이트도 이 잠금을 쓴다.
 *
 * 잠금 저장소가 오류를 내면 작업을 시작하지 않는다(503). 7.1.4 까지는 잠금 없이 진행했는데(fail-open) 「재진입은 상태 가드가
 * 막는다」는 근거가 잠금 직후~`updating` 기록 사이 구간에서 성립하지 않아, 같은 확장의 업데이트가 동시에 여러 번 돌았다(#161).
 *
 * 잡은 잠금은 이 객체가 사라질 때 풀린다 — 호출하는 메서드가 지역 변수로 들고 있으면 정상 반환이든 예외든 메서드를 빠져나가는
 * 순간 해제된다. 같은 프로세스가 이미 잡은 키를 다시 잡으면(Service 바깥 잠금 → 안쪽 Manager 진입점 등) 새로 잡지 않고 합류하며,
 * 가장 바깥 핸들이 사라질 때 실제로 풀린다.
 *
 * 오래 걸리는 작업은 {@see keepAlive()}·{@see wrapProgress()} 로 단계마다 유지 시간을 늘린다 — 10분이 넘는 업데이트가 잠금 만료로
 * 「멈춘 업데이트」로 오판되지 않게 한다.
 */
final class ExtensionOperationLock
{
    /**
     * 확장·언어팩 잠금 유지 시간(초). 프로세스가 죽어 해제하지 못해도 이 시간 뒤 풀린다. 진행 중에는 {@see keepAlive()} 가 늘린다.
     */
    public const LOCK_SECONDS = 600;

    /**
     * 코어 업데이트 잠금 키.
     */
    public const CORE_KEY = 'core-update';

    /**
     * 코어 업데이트 잠금 유지 시간(초).
     */
    public const CORE_LOCK_SECONDS = 1800;

    /**
     * 진행 콜백이 잠금을 늘리는 최소 간격(초) — 진행 콜백은 파일 단위로도 불리므로 매번 저장소를 건드리지 않는다.
     */
    public const HEARTBEAT_INTERVAL_SECONDS = 30;

    /**
     * 이 프로세스가 잡은 잠금 — 같은 키를 다시 잡으면 합류한다.
     *
     * @var array<string, array{lock: Lock, depth: int}>
     */
    private static array $held = [];

    /**
     * 저장소 판정 불가를 이미 기록한 종류 — {@see isFree()} 가 목록 행마다 오류 로그를 쌓지 않게 한다.
     *
     * @var array<string, true>
     */
    private static array $loggedUnavailable = [];

    /**
     * 마지막으로 잠금을 늘린 시각(유닉스 초).
     */
    private ?int $lastKeepAliveAt = null;

    /**
     * refresh 미지원 경고를 이미 남겼는지.
     */
    private bool $refreshUnsupportedLogged = false;

    /**
     * 잡은 잠금을 들고 있는 핸들을 만듭니다 — 이 객체가 사라질 때 잠금이 풀립니다(합류 핸들은 깊이만 줄인다).
     *
     * @param  string|null  $key  잡은 잠금 키 (해제 뒤 null)
     * @param  int  $seconds  잠금 유지 시간(초)
     */
    private function __construct(private ?string $key, private readonly int $seconds)
    {
        $this->lastKeepAliveAt = Carbon::now()->getTimestamp();
    }

    /**
     * 잠금 키를 반환합니다.
     *
     * 문자열은 7.1.4 와 같다 — 업그레이드 과도기에 옛 코드 프로세스와 새 코드 프로세스가 같은 키로 서로를 막는다.
     *
     * @param  string  $type  대상 종류 (module|plugin|template|language_pack)
     * @param  string  $identifier  대상 식별자
     * @return string 잠금 키
     */
    public static function key(string $type, string $identifier): string
    {
        return "extension-update:{$type}:{$identifier}";
    }

    /**
     * 잠금을 잡습니다. 돌려받은 객체를 지역 변수로 들고 있는 동안 잠금이 유지됩니다.
     *
     * @param  string  $type  대상 종류 (module|plugin|template|language_pack)
     * @param  string  $identifier  대상 식별자
     * @param  string  $operation  작업 (install|activate|deactivate|uninstall|update) — 거절 문구를 가른다
     * @return self 잠금 보유 객체
     *
     * @throws ExtensionOperationInProgressException 같은 대상의 작업이 이미 진행 중일 때 (409)
     * @throws ExtensionOperationLockUnavailableException 잠금 저장소가 오류를 낼 때 (503)
     */
    public static function acquire(string $type, string $identifier, string $operation = 'update'): self
    {
        return self::acquireKey(self::key($type, $identifier), self::LOCK_SECONDS, $type, $identifier, $operation);
    }

    /**
     * 코어 업데이트 잠금을 잡습니다.
     *
     * @return self 잠금 보유 객체
     *
     * @throws ExtensionOperationInProgressException 다른 코어 업데이트가 진행 중일 때
     * @throws ExtensionOperationLockUnavailableException 잠금 저장소가 오류를 낼 때
     */
    public static function acquireCore(): self
    {
        return self::acquireKey(self::CORE_KEY, self::CORE_LOCK_SECONDS, 'core', 'core', 'update');
    }

    /**
     * 키로 잠금을 잡습니다 — 이 프로세스가 이미 잡은 키면 합류합니다.
     *
     * @param  string  $key  잠금 키
     * @param  int  $seconds  잠금 유지 시간(초)
     * @param  string  $type  대상 종류 (문구·로그용)
     * @param  string  $identifier  대상 식별자 (문구·로그용)
     * @param  string  $operation  작업 (문구·로그용)
     * @return self 잠금 보유 객체
     *
     * @throws ExtensionOperationInProgressException 잠금을 다른 프로세스가 들고 있을 때
     * @throws ExtensionOperationLockUnavailableException 잠금 저장소가 오류를 낼 때
     */
    private static function acquireKey(string $key, int $seconds, string $type, string $identifier, string $operation): self
    {
        if (isset(self::$held[$key])) {
            self::$held[$key]['depth']++;

            return new self($key, $seconds);
        }

        try {
            $lock = Cache::lock($key, $seconds);
            $acquired = $lock->get();
        } catch (Throwable $e) {
            Log::error('확장 작업 잠금 저장소 오류 — 작업을 시작하지 않습니다', [
                'type' => $type,
                'identifier' => $identifier,
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);

            throw new ExtensionOperationLockUnavailableException($type, $identifier, $operation);
        }

        if (! $acquired) {
            throw new ExtensionOperationInProgressException($type, $identifier, $operation);
        }

        self::$held[$key] = ['lock' => $lock, 'depth' => 1];

        return new self($key, $seconds);
    }

    /**
     * 잠금이 비어 있는지 저장소에만 물어봅니다(이 프로세스의 보유 기록은 보지 않는다). 예외를 던지지 않습니다.
     *
     * 잡았다가 바로 푼다. 저장소가 오류를 내면 판정할 수 없다(null) — 목록 화면이 깨지지 않게 하고, 고착 정리는 하지 않는다.
     *
     * @param  string  $type  대상 종류 (module|plugin|template|language_pack)
     * @param  string  $identifier  대상 식별자
     * @return bool|null true 비어 있음 / false 다른 프로세스가 보유 중 / null 판정 불가(저장소 오류)
     */
    public static function isFree(string $type, string $identifier): ?bool
    {
        try {
            $lock = Cache::lock(self::key($type, $identifier), 5);
            if (! $lock->get()) {
                return false;
            }
            $lock->release();

            return true;
        } catch (Throwable $e) {
            if (! isset(self::$loggedUnavailable[$type])) {
                self::$loggedUnavailable[$type] = true;
                Log::error('확장 작업 잠금 저장소 오류 — 진행 중 여부를 판정할 수 없어 멈춘 업데이트로 보지 않습니다', [
                    'type' => $type,
                    'identifier' => $identifier,
                    'error' => $e->getMessage(),
                ]);
            }

            return null;
        }
    }

    /**
     * 잠금 유지 시간을 늘립니다(하트비트). 마지막 실행 뒤 {@see HEARTBEAT_INTERVAL_SECONDS} 가 지나지 않았으면 아무것도 하지 않습니다.
     *
     * 늘리기에 실패하면(만료·`cache:clear` 로 잠금이 사라짐) 같은 잠금으로 다시 잡는다. 그래도 실패하면 오류 로그만 남기고 계속한다 —
     * 업데이트 도중 중단은 롤백을 일으켜 더 위험하고, 멈춘 업데이트 오판은 `$touch`(행 `updated_at` 갱신)가 막는다.
     *
     * @param  \Closure|null  $touch  늘린 뒤 실행할 일 (예: 확장 행 `updated_at` 갱신) — 예외는 삼키고 기록한다
     * @param  bool  $force  간격과 무관하게 지금 늘린다 (스텝 경계·캐시 비우기 직후)
     * @return void 반환값 없음
     */
    public function keepAlive(?\Closure $touch = null, bool $force = false): void
    {
        $now = Carbon::now()->getTimestamp();
        if (! $force && $this->lastKeepAliveAt !== null && $now - $this->lastKeepAliveAt < self::HEARTBEAT_INTERVAL_SECONDS) {
            return;
        }
        $this->lastKeepAliveAt = $now;

        $lock = $this->key !== null ? (self::$held[$this->key]['lock'] ?? null) : null;
        if ($lock !== null) {
            $this->refreshLock($lock);
        }

        if ($touch !== null) {
            try {
                $touch();
            } catch (Throwable $e) {
                Log::error('확장 작업 하트비트 기록 실패 — 작업은 계속합니다', ['key' => $this->key, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * 진행 콜백을 감싸 부를 때마다 잠금을 늘리게 합니다(간격 제한 적용).
     *
     * @param  \Closure|null  $onProgress  원래 진행 콜백
     * @param  \Closure|null  $touch  늘린 뒤 실행할 일 (예: 확장 행 `updated_at` 갱신)
     * @return \Closure 감싼 진행 콜백 (인자를 그대로 넘긴다)
     */
    public function wrapProgress(?\Closure $onProgress, ?\Closure $touch = null): \Closure
    {
        return function (...$args) use ($onProgress, $touch) {
            $this->keepAlive($touch);

            return $onProgress !== null ? $onProgress(...$args) : null;
        };
    }

    /**
     * 잠금 유지 시간을 늘리고, 이미 잃었으면 다시 잡습니다.
     *
     * @param  Lock  $lock  이 프로세스가 잡은 잠금
     * @return void 반환값 없음
     */
    private function refreshLock(Lock $lock): void
    {
        try {
            if (! method_exists($lock, 'refresh')) {
                throw new RuntimeException('refresh unsupported');
            }
            if ($lock->refresh($this->seconds)) {
                return;
            }
        } catch (RuntimeException $e) {
            // refresh 를 지원하지 않는 범용 잠금(CacheLock 등) — 유지 시간은 처음 잡은 그대로 둔다
            if (! $this->refreshUnsupportedLogged) {
                $this->refreshUnsupportedLogged = true;
                Log::warning('작업 잠금 저장소가 잠금 연장을 지원하지 않습니다 — 처음 잡은 유지 시간을 따릅니다', ['key' => $this->key, 'error' => $e->getMessage()]);
            }

            return;
        } catch (Throwable $e) {
            Log::error('작업 잠금 연장 중 저장소 오류 — 작업은 계속합니다', ['key' => $this->key, 'error' => $e->getMessage()]);

            return;
        }

        // 잠금이 사라졌다(만료·캐시 비우기) — 같은 소유자로 다시 잡는다
        try {
            if ($lock->get()) {
                return;
            }
        } catch (Throwable $e) {
            Log::error('작업 잠금 재획득 중 저장소 오류 — 작업은 계속합니다', ['key' => $this->key, 'error' => $e->getMessage()]);

            return;
        }

        Log::error('작업 잠금을 잃었습니다 — 다른 프로세스가 같은 대상을 잡았을 수 있습니다. 작업은 계속합니다', ['key' => $this->key]);
    }

    /**
     * 잠금을 풉니다. 여러 번 불러도 한 번만 풉니다. 합류 핸들은 깊이만 줄이고, 깊이가 0 이 될 때 실제로 풉니다.
     *
     * @return void 반환값 없음
     */
    public function release(): void
    {
        $key = $this->key;
        $this->key = null;
        if ($key === null || ! isset(self::$held[$key])) {
            return;
        }

        if (--self::$held[$key]['depth'] > 0) {
            return;
        }

        $lock = self::$held[$key]['lock'];
        unset(self::$held[$key]);

        try {
            $lock->release();
        } catch (Throwable $e) {
            Log::warning('확장 작업 잠금을 풀지 못했습니다 — 유지 시간이 지나면 풀립니다', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    /**
     * 객체가 사라질 때(메서드를 빠져나갈 때) 잠금을 풉니다.
     */
    public function __destruct()
    {
        $this->release();
    }
}
