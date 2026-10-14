<?php

namespace App\Extension;

use App\Jobs\DispatchHookListenerJob;
use App\Support\Queue\ThrottledQueueWarning;
use Illuminate\Support\Facades\Log;

/**
 * 훅 리스너를 HookManager에 등록하는 공통 유틸리티
 *
 * CoreServiceProvider, ModuleManager, PluginManager 3곳의
 * 중복된 등록 로직을 통합합니다.
 *
 * 기본 동작:
 * - Action 훅: 큐 드라이버에 따라 자동으로 큐/동기 실행
 * - Filter 훅: 항상 동기 실행 (반환값 체인)
 * - `'sync' => true` 선언 시: 큐 드라이버 무관하게 동기 실행
 * - `'queue' => [...]` 선언 시: 큐로 도는 Action 의 실행 옵션(연결·큐·재시도·제한시간 등 10개, #139).
 *   Filter·sync 와 함께 쓰면 무시하고 경고한다 — 큐로 가지 않으니 적용할 곳이 없다.
 */
class HookListenerRegistrar
{
    /**
     * 등록 이력 캐시 (process-wide idempotency).
     *
     * Laravel ServiceProvider boot 가 PHPUnit 테스트 환경에서 매 setUp 마다 다시
     * 호출되며 listener 가 누적 등록되어 hook 카운트 폭증으로 hang 을 유발하던
     * 문제 차단. production 환경은 boot 가 1회만 호출되므로 무영향.
     *
     * 모듈 install/uninstall 시나리오에서 재등록이 필요하면 clear() 사용.
     *
     * @var array<string, true> key: "{source}::{listenerClass}"
     */
    private static array $registered = [];

    /**
     * 클래스가 없어 건너뛴 리스너 — 경고는 프로세스에 한 번만 남긴다.
     *
     * @var array<string, true>
     */
    private static array $missingListenersReported = [];

    /**
     * 리스너 클래스를 HookManager에 등록합니다.
     *
     * 런타임에 $listenerClass::getSubscribedHooks() 를 호출해 구독 훅을 조회한 뒤
     * 등록한다. 동일 source + listenerClass 조합이 이미 등록된 경우 skip (idempotent).
     * 사전 계산된 캐시 매핑으로 등록하려면 registerFromCache() 를 사용한다.
     *
     * @param  string  $listenerClass  HookListenerInterface 구현 클래스의 FQCN
     * @param  string|null  $source  등록 출처 (로그용: 'core', 모듈/플러그인 식별자)
     * @return void 반환값 없음
     */
    public static function register(string $listenerClass, ?string $source = null): void
    {
        $key = ($source ?? 'unknown').'::'.$listenerClass;
        if (isset(self::$registered[$key])) {
            return; // 동일 PHP process 내 중복 등록 방지
        }

        try {
            $subscribedHooks = $listenerClass::getSubscribedHooks();
        } catch (\Throwable $e) {
            Log::error('훅 리스너 등록 실패: getSubscribedHooks() 오류', [
                'listener' => $listenerClass,
                'source' => $source,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        self::applySubscribedHooks($listenerClass, $subscribedHooks, $source);
    }

    /**
     * 캐시된 구독 훅 매핑으로 리스너를 등록합니다 (클래스 로딩 없음).
     *
     * bootstrap/cache/hooks.php 에서 읽어온 사전 계산 getSubscribedHooks() 결과를
     * 그대로 받아 HookManager 에 등록한다. register() 와 달리 $listenerClass::getSubscribedHooks()
     * 를 호출하지 않으므로 리스너 클래스가 부팅 시점에 오토로딩되지 않는다 —
     * 실제 클래스 로딩은 훅 발화 시 클로저 내부 app($listenerClass) 에서 지연 수행된다.
     *
     * 등록 결과(HookManager 훅 매핑)는 register() 스캔 경로와 바이트 동일해야 한다
     * (동일 applySubscribedHooks 위임).
     *
     * @param  string  $listenerClass  리스너 FQCN
     * @param  array<string, array<string, mixed>>  $subscribedHooks  사전 계산된 getSubscribedHooks() 결과
     * @param  string|null  $source  등록 출처 ('core', 모듈/플러그인 식별자)
     * @return void 반환값 없음
     */
    public static function registerFromCache(string $listenerClass, array $subscribedHooks, ?string $source = null): void
    {
        $key = ($source ?? 'unknown').'::'.$listenerClass;
        if (isset(self::$registered[$key])) {
            return; // 동일 PHP process 내 중복 등록 방지 (스캔 경로와 동일 계약)
        }

        self::applySubscribedHooks($listenerClass, $subscribedHooks, $source);
    }

    /**
     * 구독 훅 배열을 HookManager 에 등록합니다 (스캔/캐시 경로 공통).
     *
     * register()(런타임 getSubscribedHooks 호출)와 registerFromCache()(캐시 읽기)가
     * 이 메서드에 위임하여 등록 결과의 동일성을 보장한다.
     *
     * @param  string  $listenerClass  리스너 FQCN
     * @param  array<string, array<string, mixed>>  $subscribedHooks  구독 훅 매핑
     * @param  string|null  $source  등록 출처
     * @return void 반환값 없음
     */
    private static function applySubscribedHooks(string $listenerClass, array $subscribedHooks, ?string $source): void
    {
        $key = ($source ?? 'unknown').'::'.$listenerClass;
        self::$registered[$key] = true;

        foreach ($subscribedHooks as $hookName => $config) {
            $method = $config['method'] ?? 'handle';
            $priority = $config['priority'] ?? 10;
            $type = $config['type'] ?? 'action';
            $forceSync = ! empty($config['sync']);
            $rawQueueOptions = $config['queue'] ?? null;

            if ($rawQueueOptions !== null && ($type === 'filter' || $forceSync)) {
                ThrottledQueueWarning::warn(
                    "opt-ignored|{$listenerClass}|{$hookName}",
                    "훅 리스너의 'queue' 옵션은 큐로 도는 Action 에만 적용됩니다 — Filter·sync 구독이라 무시합니다",
                    ['listener' => $listenerClass, 'hook' => $hookName, 'type' => $type, 'sync' => $forceSync],
                );
            }

            if ($type === 'filter') {
                // Filter: 항상 동기 실행 (반환값 체인이므로 큐 불가)
                HookManager::addFilter($hookName, function ($value, ...$args) use ($listenerClass, $method, $hookName) {
                    if (! self::listenerClassExists($listenerClass, $hookName)) {
                        return $value;
                    }

                    return app($listenerClass)->{$method}($value, ...$args);
                }, $priority);
            } elseif ($forceSync) {
                // Action + sync: true → 동기 실행 (개발자가 명시적으로 opt-out)
                HookManager::addAction($hookName, function (...$args) use ($listenerClass, $method, $hookName) {
                    if (self::listenerClassExists($listenerClass, $hookName)) {
                        app($listenerClass)->{$method}(...$args);
                    }
                }, $priority);
            } else {
                // Action 기본: 큐 디스패치 (큐 드라이버가 sync 면 Laravel 이 즉시 실행 → 하위호환)
                self::addQueuedAction(
                    $hookName,
                    $listenerClass,
                    $method,
                    $priority,
                    [],
                    ListenerQueueOptions::normalize($rawQueueOptions, $listenerClass, $hookName),
                );
            }

            // 등록 성공은 로그로 남기지 않는다. 리스너 112개 × 구독 400건이 요청마다 부팅되므로
            // 한 줄씩만 남겨도 매 요청 400줄이 쌓인다. 기본 설치가 production + LOG_LEVEL 조합상
            // 이 줄들을 그대로 기록하므로, 정상 동작을 알리는 데 드는 비용이 동작 자체보다 커진다.
            // 등록 실패는 아래 catch 에서 계속 기록한다.
        }
    }

    /**
     * DB 기반 동적 훅을 큐 디스패치 정책에 맞춰 등록합니다.
     *
     * 정적 getSubscribedHooks() 으로 표현할 수 없는 동적 구독(DB 정의에 따라 훅 대상이
     * 런타임에 결정되는 경우, 예: NotificationHookListener::registerDynamicHooks)을 위한 진입점.
     * register() 의 Action 기본 동작과 동일하게 DispatchHookListenerJob 으로 큐 디스패치한다 —
     * 리스너가 직접 dispatch(new DispatchHookListenerJob(...)) 를 작성하지 않고 이 헬퍼에 위임하여,
     * 큐 래핑 로직의 소유권을 Registrar 한 곳에 유지한다(정적/동적 일관).
     *
     * $boundArgs 로 훅 발화 인자 앞에 고정 인자(예: NotificationDefinition)를 붙일 수 있다.
     * 워커는 listenerClass::method(...$boundArgs, ...$hookArgs) 형태로 복원 호출한다.
     *
     * @param  string  $hookName  구독할 훅 이름
     * @param  string  $listenerClass  리스너 FQCN
     * @param  string  $method  큐 워커에서 호출할 public 메서드명
     * @param  array<int, mixed>  $boundArgs  훅 발화 인자 앞에 고정으로 붙일 인자 (직렬화 가능해야 함)
     * @param  int  $priority  실행 우선순위
     * @param  bool  $sync  true 면 큐 래핑 없이 즉시 동기 실행 (큐 드라이버 무관)
     * @param  array<string, mixed>  $queueOptions  큐 실행 옵션 (`getSubscribedHooks()` 의 `'queue'` 와 같은 10개 키, #139)
     * @return void 반환값 없음
     */
    public static function registerDynamicAction(
        string $hookName,
        string $listenerClass,
        string $method,
        array $boundArgs = [],
        int $priority = 10,
        bool $sync = false,
        array $queueOptions = [],
    ): void {
        if ($sync) {
            HookManager::addAction($hookName, function (...$args) use ($listenerClass, $method, $boundArgs) {
                app($listenerClass)->{$method}(...$boundArgs, ...$args);
            }, $priority);

            return;
        }

        self::addQueuedAction(
            $hookName,
            $listenerClass,
            $method,
            $priority,
            $boundArgs,
            ListenerQueueOptions::normalize($queueOptions, $listenerClass, $hookName),
        );
    }

    /**
     * 리스너 메서드를 큐 잡으로 바로 디스패치합니다 (훅 발화 없이, #139).
     *
     * 리스너가 일을 나눠 자식 잡으로 보낼 때 쓴다(예: 알림 대량 발송의 수신자 묶음). 훅 리스너와 같은
     * `DispatchHookListenerJob`·직렬화·컨텍스트 캡처를 거치므로 워커에서 같은 방식으로 복원된다.
     *
     * @param  string  $listenerClass  리스너 FQCN
     * @param  string  $method  큐 워커에서 호출할 public 메서드명
     * @param  array<int, mixed>  $args  메서드 인자 (HookArgumentSerializer 로 직렬화 — Model 이 아닌 객체는 null 이 된다)
     * @param  array<string, mixed>  $queueOptions  큐 실행 옵션 (10개 키)
     * @param  string  $hookName  경고 문맥에 남길 이름
     * @return void 반환값 없음
     */
    public static function dispatchQueued(
        string $listenerClass,
        string $method,
        array $args,
        array $queueOptions = [],
        string $hookName = '',
    ): void {
        self::dispatchJob(
            $listenerClass,
            $method,
            $args,
            ListenerQueueOptions::normalize($queueOptions, $listenerClass, $hookName),
            $hookName,
        );
    }

    /**
     * 훅에 큐 디스패치(DispatchHookListenerJob) 콜백을 등록합니다 (정적/동적 공통).
     *
     * 큐 드라이버가 sync 면 Laravel 이 즉시 실행하므로 하위호환은 그대로 유지된다.
     * HookContextCapture::capture() 로 Auth/Request/Locale 스냅샷을 함께 전달하여
     * 큐 워커에서 리스너가 평소처럼 사용자 컨텍스트를 사용할 수 있도록 한다.
     *
     * @param  string  $hookName  구독할 훅 이름
     * @param  string  $listenerClass  리스너 FQCN
     * @param  string  $method  큐 워커에서 호출할 메서드명
     * @param  int  $priority  실행 우선순위
     * @param  array<int, mixed>  $boundArgs  훅 발화 인자 앞에 고정으로 붙일 인자
     * @param  array<string, mixed>  $queueOptions  정규화된 큐 실행 옵션 (ListenerQueueOptions::normalize 결과)
     * @return void 반환값 없음
     */
    private static function addQueuedAction(
        string $hookName,
        string $listenerClass,
        string $method,
        int $priority,
        array $boundArgs = [],
        array $queueOptions = [],
    ): void {
        HookManager::addAction($hookName, function (...$args) use ($listenerClass, $method, $boundArgs, $hookName, $queueOptions) {
            if (! self::listenerClassExists($listenerClass, $hookName)) {
                return;
            }

            self::dispatchJob($listenerClass, $method, [...$boundArgs, ...$args], $queueOptions, $hookName);
        }, $priority);
    }

    /**
     * 리스너 잡을 만들어 디스패치합니다 (훅 콜백·dispatchQueued 공통).
     *
     * 옵션이 비면 ListenerQueueOptions::apply() 를 거치지 않는다 — 옵션을 선언하지 않은 리스너의 잡은 연결·큐·
     * 재시도 설정이 종전과 같다(payload 에는 새 기본값 속성과 eventId 가 함께 실리지만 동작은 바뀌지 않는다).
     *
     * @param  string  $listenerClass  리스너 FQCN
     * @param  string  $method  큐 워커에서 호출할 메서드명
     * @param  array<int, mixed>  $args  메서드 인자 (직렬화 전)
     * @param  array<string, mixed>  $queueOptions  정규화된 큐 실행 옵션
     * @param  string  $hookName  경고 문맥에 남길 훅 이름
     * @return void 반환값 없음
     */
    private static function dispatchJob(
        string $listenerClass,
        string $method,
        array $args,
        array $queueOptions,
        string $hookName,
    ): void {
        $job = new DispatchHookListenerJob(
            $listenerClass,
            $method,
            HookArgumentSerializer::serialize($args),
            HookContextCapture::capture(),
        );

        if ($queueOptions !== []) {
            ListenerQueueOptions::apply($job, $queueOptions, $listenerClass, $hookName);
        }

        dispatch($job);
    }

    /**
     * 구독한 리스너 클래스가 지금도 있는지 봅니다 — 없으면 경고를 한 번 남기고 false.
     *
     * 리스너는 훅이 발화할 때 지연 로딩한다. 확장 업데이트가 리스너 클래스를 지우면, 업데이트를 실행한 프로세스에는
     * 부팅 때 등록한 옛 구독이 남아 그 뒤 같은 훅(예: 업데이트 완료 훅)이 발화할 때 「Target class … does not exist」 로
     * 터졌다 — 업데이트는 끝났는데 실패로 보고되고, 같은 훅의 다른 리스너도 돌지 못했다(#725). 사라진 리스너는 그 구독만
     * 건너뛴다. 다음 프로세스는 새 판의 구독으로 부팅하므로 이 경고는 업데이트를 실행한 프로세스에서만 난다.
     *
     * @param  string  $listenerClass  리스너 FQCN
     * @param  string  $hookName  발화 중인 훅 이름 (경고 문맥)
     * @return bool 클래스가 있으면 true
     */
    private static function listenerClassExists(string $listenerClass, string $hookName): bool
    {
        if (class_exists($listenerClass)) {
            return true;
        }

        if (! isset(self::$missingListenersReported[$listenerClass])) {
            self::$missingListenersReported[$listenerClass] = true;
            Log::warning('구독은 남아 있는데 리스너 클래스가 없어 건너뜁니다 — 확장 업데이트가 리스너를 지운 경우 이 프로세스에서만 일어납니다', [
                'listener' => $listenerClass,
                'hook' => $hookName,
            ]);
        }

        return false;
    }

    /**
     * 등록 이력 캐시를 비웁니다.
     *
     * 모듈 install/uninstall 시나리오 또는 테스트 격리가 필요할 때 호출.
     * 캐시 비운 후 register() 호출하면 listener 가 다시 HookManager 에 추가됨.
     *
     * @return void 반환값 없음
     */
    public static function clear(): void
    {
        self::$registered = [];
        self::$missingListenersReported = [];
    }
}
