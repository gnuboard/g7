<?php

namespace App\Extension;

use App\Jobs\DispatchHookListenerJob;
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
     * @var array<string, true>  key: "{source}::{listenerClass}"
     */
    private static array $registered = [];

    /**
     * 리스너 클래스를 HookManager에 등록합니다.
     *
     * 동일 source + listenerClass 조합이 이미 등록된 경우 skip (idempotent).
     *
     * @param  string  $listenerClass  HookListenerInterface 구현 클래스의 FQCN
     * @param  string|null  $source  등록 출처 (로그용: 'core', 모듈/플러그인 식별자)
     * @return void
     */
    public static function register(string $listenerClass, ?string $source = null): void
    {
        $key = ($source ?? 'unknown').'::'.$listenerClass;
        if (isset(self::$registered[$key])) {
            return; // 동일 PHP process 내 중복 등록 방지
        }
        self::$registered[$key] = true;

        try {
            $subscribedHooks = $listenerClass::getSubscribedHooks();
        } catch (\Throwable $e) {
            // 실패 시 캐시 롤백하여 재시도 가능 상태 유지
            unset(self::$registered[$key]);

            Log::error('훅 리스너 등록 실패: getSubscribedHooks() 오류', [
                'listener' => $listenerClass,
                'source' => $source,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($subscribedHooks as $hookName => $config) {
            $method = $config['method'] ?? 'handle';
            $priority = $config['priority'] ?? 10;
            $type = $config['type'] ?? 'action';
            $forceSync = ! empty($config['sync']);

            if ($type === 'filter') {
                // Filter: 항상 동기 실행 (반환값 체인이므로 큐 불가)
                HookManager::addFilter($hookName, function ($value, ...$args) use ($listenerClass, $method) {
                    return app($listenerClass)->{$method}($value, ...$args);
                }, $priority);
            } elseif ($forceSync) {
                // Action + sync: true → 동기 실행 (개발자가 명시적으로 opt-out)
                HookManager::addAction($hookName, function (...$args) use ($listenerClass, $method) {
                    app($listenerClass)->{$method}(...$args);
                }, $priority);
            } else {
                // Action 기본: 큐 디스패치
                // 큐 드라이버가 sync이면 Laravel이 즉시 실행 → 하위호환 보장
                // HookContextCapture::capture()로 Auth/Request/Locale 스냅샷을 함께 전달하여
                // 큐 워커에서 리스너가 평소처럼 사용자 컨텍스트를 사용할 수 있도록 한다.
                HookManager::addAction($hookName, function (...$args) use ($listenerClass, $method) {
                    dispatch(new DispatchHookListenerJob(
                        $listenerClass,
                        $method,
                        HookArgumentSerializer::serialize($args),
                        HookContextCapture::capture(),
                    ));
                }, $priority);
            }

            Log::info('훅 리스너 등록 완료', [
                'hook' => $hookName,
                'listener' => $listenerClass,
                'method' => $method,
                'priority' => $priority,
                'type' => $type,
                'sync' => $forceSync,
                'source' => $source,
            ]);
        }
    }

    /**
     * 등록 이력 캐시를 비웁니다.
     *
     * 모듈 install/uninstall 시나리오 또는 테스트 격리가 필요할 때 호출.
     * 캐시 비운 후 register() 호출하면 listener 가 다시 HookManager 에 추가됨.
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$registered = [];
    }
}
