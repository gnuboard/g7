<?php

namespace App\Extension;

use App\Contracts\Extension\HookManagerInterface;
use App\Events\GenericBroadcastEvent;
use App\Models\PermissionHook;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

class HookManager implements HookManagerInterface
{
    private static array $hooks = [];

    private static array $filters = [];

    private static array $dispatching = [];

    /**
     * 현재 실행 중인 훅 이름 스택 — 정책 Listener 등 "어느 훅에서 호출되었는지" 알아야 하는
     * 단일 핸들러 패턴에 사용됩니다. (내부 전용)
     *
     * @var array<int, string>
     */
    private static array $runningHookStack = [];

    /**
     * Hook 이벤트를 발생시켜 등록된 콜백들을 실행합니다.
     *
     * @param  string  $hookName  Hook 이름
     * @param  mixed  ...$args  Hook에 전달할 인수들
     */
    public static function doAction(string $hookName, ...$args): void
    {
        // 가드 플래그 설정 (addAction으로 등록된 콜백의 Event::listen 중복 실행 방지)
        self::$dispatching[$hookName] = true;
        self::$runningHookStack[] = $hookName;

        try {
            // 등록된 Hook이 있는지 확인
            if (isset(self::$hooks[$hookName])) {
                // Hook들을 우선순위에 따라 정렬
                $hooks = self::$hooks[$hookName];
                ksort($hooks);

                // 각 Hook을 순차적으로 실행
                foreach ($hooks as $priority => $callbacks) {
                    foreach ($callbacks as $callback) {
                        call_user_func_array($callback, $args);
                    }
                }
            }

            // Laravel 이벤트 시스템에도 전달 (직접 Event::listen으로 등록한 외부 리스너용)
            Event::dispatch("hook.{$hookName}", $args);
        } finally {
            array_pop(self::$runningHookStack);
            unset(self::$dispatching[$hookName]);
        }
    }

    /**
     * 현재 실행 중인 (가장 내부의) 훅 이름을 반환합니다.
     * 단일 handler 메서드가 여러 훅에 구독되어 "어느 훅에서 호출되었는지" 구분이 필요할 때 사용.
     *
     * @return string|null 실행 중 훅 이름 또는 null (최상위 컨텍스트)
     */
    public static function getRunningHook(): ?string
    {
        $count = count(self::$runningHookStack);

        return $count > 0 ? self::$runningHookStack[$count - 1] : null;
    }

    /**
     * Filter를 적용하여 데이터를 변환합니다.
     *
     * @param  string  $filterName  Filter 이름
     * @param  mixed  $value  변환할 원본 값
     * @param  mixed  ...$args  Filter에 전달할 추가 인수들
     * @return mixed 변환된 값
     */
    public static function applyFilters(string $filterName, $value, ...$args)
    {
        $eventName = "filter.{$filterName}";

        // 등록된 필터가 있는지 확인
        if (! isset(self::$filters[$filterName])) {
            return $value;
        }

        // 필터들을 우선순위에 따라 정렬
        $filters = self::$filters[$filterName];
        ksort($filters);

        // 각 필터를 순차적으로 적용
        foreach ($filters as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $value = call_user_func_array($callback, array_merge([$value], $args));
            }
        }

        return $value;
    }

    /**
     * Hook 이벤트에 콜백 함수를 등록합니다.
     *
     * @param  string  $hookName  Hook 이름
     * @param  callable  $callback  실행할 콜백 함수
     * @param  int  $priority  실행 우선순위 (기본값: 10)
     */
    public static function addAction(string $hookName, callable $callback, int $priority = 10): void
    {
        if (! isset(self::$hooks[$hookName])) {
            self::$hooks[$hookName] = [];
        }

        if (! isset(self::$hooks[$hookName][$priority])) {
            self::$hooks[$hookName][$priority] = [];
        }

        self::$hooks[$hookName][$priority][] = $callback;

        // Laravel 이벤트 시스템에도 등록 (가드 래핑으로 doAction 실행 중 중복 방지)
        Event::listen("hook.{$hookName}", function (...$args) use ($hookName, $callback) {
            if (! empty(self::$dispatching[$hookName])) {
                return; // doAction() 내부 배열에서 이미 실행됨 → 스킵
            }
            call_user_func_array($callback, $args);
        });
    }

    /**
     * Filter에 콜백 함수를 등록합니다.
     *
     * @param  string  $filterName  Filter 이름
     * @param  callable  $callback  실행할 콜백 함수
     * @param  int  $priority  실행 우선순위 (기본값: 10)
     */
    public static function addFilter(string $filterName, callable $callback, int $priority = 10): void
    {
        if (! isset(self::$filters[$filterName])) {
            self::$filters[$filterName] = [];
        }

        if (! isset(self::$filters[$filterName][$priority])) {
            self::$filters[$filterName][$priority] = [];
        }

        self::$filters[$filterName][$priority][] = $callback;
    }

    /**
     * WebSocket 브로드캐스트를 실행합니다.
     *
     * broadcast(new Event(...)) 직접 호출 대신 이 메서드를 사용합니다.
     * 내부적으로 GenericBroadcastEvent를 생성하여 Laravel broadcast()를 실행합니다.
     *
     * @param  string  $channel  채널명 (예: 'admin.dashboard', 'user.notifications.123')
     * @param  string  $eventName  이벤트명 (예: 'dashboard.stats.updated', 'notification.received')
     * @param  array  $payload  브로드캐스트 데이터
     */
    public static function broadcast(string $channel, string $eventName, array $payload = []): void
    {
        $driver = config('broadcasting.default');

        if (in_array($driver, ['null', 'log', null], true)) {
            return;
        }

        // Reverb/Pusher 드라이버: 호스트 미설정 시 연결 시도 없이 건너뜀
        $connection = config("broadcasting.connections.{$driver}");
        if (empty($connection['options']['host'] ?? null)) {
            return;
        }

        try {
            broadcast(new GenericBroadcastEvent($channel, $eventName, $payload));
        } catch (\Throwable $e) {
            Log::warning('브로드캐스트 실패 (Reverb 미실행 가능)', [
                'channel' => $channel,
                'event' => $eventName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Hook 이벤트에서 콜백 함수를 제거합니다.
     *
     * @param  string  $hookName  Hook 이름
     * @param  callable  $callback  제거할 콜백 함수
     */
    public static function removeAction(string $hookName, callable $callback): void
    {
        if (isset(self::$hooks[$hookName])) {
            foreach (self::$hooks[$hookName] as $priority => $callbacks) {
                $key = array_search($callback, $callbacks, true);
                if ($key !== false) {
                    unset(self::$hooks[$hookName][$priority][$key]);
                }
            }
        }

        // Laravel 이벤트 시스템에서도 제거
        Event::forget("hook.{$hookName}");
    }

    /**
     * Filter에서 콜백 함수를 제거합니다.
     *
     * @param  string  $filterName  Filter 이름
     * @param  callable  $callback  제거할 콜백 함수
     */
    public static function removeFilter(string $filterName, callable $callback): void
    {
        if (isset(self::$filters[$filterName])) {
            foreach (self::$filters[$filterName] as $priority => $callbacks) {
                $key = array_search($callback, $callbacks, true);
                if ($key !== false) {
                    unset(self::$filters[$filterName][$priority][$key]);
                }
            }
        }
    }

    /**
     * 등록된 모든 Hook 목록을 조회합니다.
     *
     * @return array Hook 목록 배열
     */
    public static function getHooks(): array
    {
        return self::$hooks;
    }

    /**
     * 등록된 모든 Filter 목록을 조회합니다.
     *
     * @return array Filter 목록 배열
     */
    public static function getFilters(): array
    {
        return self::$filters;
    }

    /**
     * 특정 Hook에 등록된 모든 콜백을 제거합니다.
     *
     * @param  string  $hookName  Hook 이름
     */
    public static function clearAction(string $hookName): void
    {
        if (isset(self::$hooks[$hookName])) {
            unset(self::$hooks[$hookName]);
        }

        // Laravel 이벤트 시스템에서도 제거
        Event::forget("hook.{$hookName}");
    }

    /**
     * 특정 Filter에 등록된 모든 콜백을 제거합니다.
     *
     * @param  string  $filterName  Filter 이름
     */
    public static function clearFilter(string $filterName): void
    {
        if (isset(self::$filters[$filterName])) {
            unset(self::$filters[$filterName]);
        }
    }

    /**
     * 테스트 환경에서 등록된 모든 Hook과 Filter를 초기화합니다.
     *
     * 주의: 이 메서드는 테스트 환경에서만 사용해야 합니다.
     */
    public static function resetAll(): void
    {
        self::$hooks = [];
        self::$filters = [];
        self::$dispatching = [];
    }

    /**
     * 훅 실행 전 권한 체크
     *
     * permission_hooks 테이블에 해당 훅이 매핑되어 있으면
     * 현재 사용자가 해당 권한을 보유하고 있는지 확인합니다.
     *
     * @param  string  $hookName  훅 이름
     * @param  User|null  $user  사용자 (null이면 현재 인증된 사용자)
     *
     * @throws AuthorizationException 권한이 없는 경우
     */
    public static function checkHookPermission(string $hookName, ?User $user = null): void
    {
        // 훅에 권한 매핑이 없으면 모든 사용자 허용
        if (! PermissionHook::hasPermissionMapping($hookName)) {
            return;
        }

        // 사용자 결정 (파라미터 우선, 없으면 현재 인증 사용자)
        $user = $user ?? Auth::user();

        // 비인증 사용자는 권한 매핑된 훅 접근 불가
        if (! $user) {
            throw new AuthorizationException(__('auth.unauthorized'));
        }

        // 관리자는 모든 권한 보유
        if ($user->isAdmin()) {
            return;
        }

        // 사용자가 훅에 매핑된 권한 중 하나라도 보유하는지 확인
        $requiredPermissions = PermissionHook::getPermissionsForHook($hookName);

        foreach ($requiredPermissions as $permission) {
            if ($user->hasPermission($permission->identifier)) {
                return;
            }
        }

        throw new AuthorizationException(__('auth.unauthorized'));
    }

    /**
     * 훅 실행 전 권한 체크 후 Action 실행
     *
     * @param  string  $hookName  훅 이름
     * @param  User|null  $user  권한 체크 대상 사용자
     * @param  mixed  ...$args  훅에 전달할 인자
     *
     * @throws AuthorizationException 권한이 없는 경우
     */
    public static function doActionWithPermission(string $hookName, ?User $user = null, ...$args): void
    {
        static::checkHookPermission($hookName, $user);
        static::doAction($hookName, ...$args);
    }

    /**
     * 훅 실행 전 권한 체크 후 Filter 실행
     *
     * @param  string  $hookName  훅 이름
     * @param  User|null  $user  권한 체크 대상 사용자
     * @param  mixed  $value  필터링할 값
     * @param  mixed  ...$args  필터에 전달할 추가 인자
     * @return mixed 필터링된 값
     *
     * @throws AuthorizationException 권한이 없는 경우
     */
    public static function applyFiltersWithPermission(string $hookName, ?User $user = null, mixed $value = null, ...$args): mixed
    {
        static::checkHookPermission($hookName, $user);

        return static::applyFilters($hookName, $value, ...$args);
    }
}
