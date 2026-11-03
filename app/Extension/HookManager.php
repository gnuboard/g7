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
use Illuminate\Support\Testing\Fakes\EventFake;

class HookManager implements HookManagerInterface
{
    private static array $hooks = [];

    private static array $filters = [];

    private static array $dispatching = [];

    /**
     * `hook.*` 미러를 등록한 이벤트 디스패처 집합 (디스패처가 사라지면 같이 사라진다).
     *
     * @var \WeakMap<object, true>|null
     */
    private static ?\WeakMap $mirroredDispatchers = null;

    /**
     * 필터 등록이 바뀔 때마다 올라가는 번호 — 필터 결과를 요청 안에서 메모하는 쪽이 비교한다(#860).
     */
    private static int $filterRevision = 0;

    /**
     * 구 훅 이름 사용 경고를 이미 남긴 훅 이름 집합 — 요청마다 한 번씩만 경고합니다.
     *
     * @var array<string, bool>
     */
    private static array $legacyHookNoticeShown = [];

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
     * @return void 없음
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
     * @return void 없음
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

        // Laravel 이벤트 시스템에도 미러 (외부 Event::dispatch("hook.x") 용) — 디스패처당 와일드카드 1개
        self::ensureWildcardMirror();
    }

    /**
     * 현재 이벤트 디스패처에 `hook.*` 와일드카드 미러를 한 번만 등록합니다 (#860).
     *
     * 콜백마다 이름 리스너를 걸던 방식은 요청마다 액션 수만큼 리스너를 쌓았다. 등록 여부는 디스패처 객체별로
     * 기억한다 — 새 앱·테스트마다 새 디스패처가 생기므로 정적 bool 로는 안 된다. `Event::fake()` 는 실제
     * 디스패처를 감싸 리스너 등록을 그대로 넘기므로, 감싼 쪽이 아니라 실제 디스패처를 키로 써서 같은
     * 디스패처에 미러가 두 번 걸리지(= 외부 dispatch 시 액션 2회) 않게 한다.
     *
     * @return void 없음
     */
    private static function ensureWildcardMirror(): void
    {
        $events = Event::getFacadeRoot();
        while ($events instanceof EventFake) {
            $events = $events->dispatcher;
        }

        if (! is_object($events)) {
            return;
        }

        self::$mirroredDispatchers ??= new \WeakMap;
        if (isset(self::$mirroredDispatchers[$events])) {
            return;
        }
        self::$mirroredDispatchers[$events] = true;

        $events->listen('hook.*', static function (string $event, array $payload): void {
            self::runMirroredAction(substr($event, 5), $payload);
        });
    }

    /**
     * 외부에서 보낸 `hook.{이름}` 이벤트로 그 훅의 액션 콜백을 실행합니다.
     *
     * doAction() 실행 중이면 배열에서 이미 실행했으므로 건너뛴다(가드 — 이중 실행 방지).
     *
     * @param  string  $hookName  Hook 이름
     * @param  array  $payload  이벤트 인수
     * @return void 없음
     */
    private static function runMirroredAction(string $hookName, array $payload): void
    {
        if (! empty(self::$dispatching[$hookName]) || empty(self::$hooks[$hookName])) {
            return;
        }

        $hooks = self::$hooks[$hookName];
        ksort($hooks);
        $args = array_values($payload);

        foreach ($hooks as $callbacks) {
            foreach ($callbacks as $callback) {
                call_user_func_array($callback, $args);
            }
        }
    }

    /**
     * Filter에 콜백 함수를 등록합니다.
     *
     * @param  string  $filterName  Filter 이름
     * @param  callable  $callback  실행할 콜백 함수
     * @param  int  $priority  실행 우선순위 (기본값: 10)
     * @return void 없음
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
        self::$filterRevision++;
    }

    /**
     * 필터 등록 변경 번호를 반환합니다.
     *
     * addFilter·removeFilter·clearFilter·resetAll 때마다 올라간다. 필터 결과를 요청 안에서 메모하는 쪽은
     * 이 값이 그대로일 때만 메모를 쓴다(내부 전용).
     *
     * @internal
     *
     * @return int 변경 번호
     */
    public static function filterRevision(): int
    {
        return self::$filterRevision;
    }

    /**
     * 이 사이트에서 방송이 실제로 나가는 상태인지 판정합니다.
     *
     * 화면이 「소켓이 있으니 폴링은 끈다」를 정할 때도 같은 식을 써야 한다. 키만 보고 판정하면
     * 키는 있는데 드라이버가 `null` 이거나 호스트가 비어 있는 사이트에서 방송도 폴링도 없어
     * 진행률이 멈춘다(#682 S3 라운드 3).
     *
     * @return bool 방송이 나가면 true
     */
    public static function broadcastingEnabled(): bool
    {
        $driver = config('broadcasting.default');

        if (in_array($driver, ['null', 'log', null], true)) {
            return false;
        }

        // Reverb/Pusher 드라이버: 호스트 미설정 시 연결 시도 없이 건너뜀
        $connection = config("broadcasting.connections.{$driver}");

        return ! empty($connection['options']['host'] ?? null);
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
     * @return void 없음
     */
    public static function broadcast(string $channel, string $eventName, array $payload = []): void
    {
        if (! self::broadcastingEnabled()) {
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
     * @return void 없음
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

        // 미러는 와일드카드가 배열을 읽으므로 배열에서 지우면 끝 — 같은 훅의 외부 Event::listen 리스너는 남긴다
    }

    /**
     * Filter에서 콜백 함수를 제거합니다.
     *
     * @param  string  $filterName  Filter 이름
     * @param  callable  $callback  제거할 콜백 함수
     * @return void 없음
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

        self::$filterRevision++;
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
     * @return void 없음
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
     * @return void 없음
     */
    public static function clearFilter(string $filterName): void
    {
        if (isset(self::$filters[$filterName])) {
            unset(self::$filters[$filterName]);
        }

        self::$filterRevision++;
    }

    /**
     * 테스트 환경에서 등록된 모든 Hook과 Filter를 초기화합니다.
     *
     * 주의: 이 메서드는 테스트 환경에서만 사용해야 합니다.
     *
     * @return void 없음
     */
    public static function resetAll(): void
    {
        self::$hooks = [];
        self::$filters = [];
        self::$dispatching = [];
        self::$filterRevision++;
    }

    /**
     * 훅 실행 전 권한 체크
     *
     * permission_hooks 테이블에 해당 훅이 매핑되어 있으면
     * 현재 사용자가 해당 권한을 보유하고 있는지 확인합니다.
     *
     * @param  string  $hookName  훅 이름
     * @param  User|null  $user  사용자 (null이면 현재 인증된 사용자)
     * @return void 없음
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
     * @return void 없음
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

    /**
     * 표준 이름과 구 이름을 함께 발행하는 Action 실행
     *
     * 이미 공개된 액션 훅의 이름을 옮겨야 할 때 쓴다(공개 훅 이름은 마이너 버전에서 바꾸지 않는다 —
     * 새 이름을 더하고 옛 이름을 함께 발화하며, 제거는 메이저 버전에서). 표준 이름을 먼저 발화하고,
     * 구 이름에 구독자가 있을 때만 같은 인자로 한 번 더 발화하며 개명 경고를 한 번 남긴다.
     *
     * 공개 배포본에서 한 번도 발화된 적 없는 옛 이름은 이 헬퍼로 되살리지 않는다 — 한 번도 돈 적 없는
     * 서드파티 코드가 갑자기 돌게 된다(docs/extension/hooks.md 「사용 중단 훅」 의 공개 배포 발화 이력 「없음」).
     *
     * @param  string  $hookName  표준 훅 이름
     * @param  string  $legacyHookName  구 훅 이름 (하위호환용)
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public static function doActionWithLegacyName(string $hookName, string $legacyHookName, ...$args): void
    {
        static::doAction($hookName, ...$args);

        if (empty(self::$hooks[$legacyHookName])) {
            return;
        }

        if (! isset(self::$legacyHookNoticeShown[$legacyHookName])) {
            self::$legacyHookNoticeShown[$legacyHookName] = true;
            Log::warning('구 훅 이름 사용 중 — 표준 이름으로 옮겨 주세요', [
                'legacy' => $legacyHookName,
                'standard' => $hookName,
            ]);
        }

        static::doAction($legacyHookName, ...$args);
    }

    /**
     * 표준 이름과 구 이름을 함께 발행하는 Filter 실행
     *
     * 이름이 표준(`core.{대상}.{동작}_validation_rules`)과 어긋난 채 이미 공개된 훅을 표준 이름으로
     * 옮길 때 사용합니다. 표준 이름을 먼저 적용하고 그 결과에 구 이름을 다시 적용하므로,
     * 새로 구독하는 확장과 구 이름을 구독 중인 기존 확장이 함께 동작합니다.
     *
     * 구 이름에 실제 구독자가 있을 때만 한 번 경고를 남깁니다 — 개명 사실을 확장 개발자가 알 수 있게
     * 하되, 구독자가 없는 환경에서 로그를 어지럽히지 않기 위함입니다.
     *
     * @param  string  $hookName  표준 훅 이름
     * @param  string  $legacyHookName  구 훅 이름 (하위호환용)
     * @param  mixed  $value  필터링할 값
     * @param  mixed  ...$args  필터에 전달할 추가 인자
     * @return mixed 필터링된 값
     */
    public static function applyFiltersWithLegacyName(
        string $hookName,
        string $legacyHookName,
        mixed $value = null,
        ...$args
    ): mixed {
        $value = static::applyFilters($hookName, $value, ...$args);

        if (! isset(self::$filters[$legacyHookName])) {
            return $value;
        }

        if (! isset(self::$legacyHookNoticeShown[$legacyHookName])) {
            self::$legacyHookNoticeShown[$legacyHookName] = true;
            Log::warning('구 훅 이름 사용 중 — 표준 이름으로 옮겨 주세요', [
                'legacy' => $legacyHookName,
                'standard' => $hookName,
            ]);
        }

        return static::applyFilters($legacyHookName, $value, ...$args);
    }
}
