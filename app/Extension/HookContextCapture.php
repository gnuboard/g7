<?php

namespace App\Extension;

use App\Http\Middleware\SetTimezone;
use App\Models\User;
use App\Support\SiteUrl;
use Illuminate\Foundation\Bootstrap\SetRequestForConsole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;

/**
 * 훅 리스너 큐 디스패치 시 요청 컨텍스트 캡처/복원 헬퍼.
 *
 * 큐 워커는 별도 프로세스이므로 Auth, Request, App 로케일이 모두 리셋된다.
 * 디스패치 시점(원래 요청 컨텍스트)에 capture()로 스냅샷을 만들고,
 * Job::handle() 진입 시 restore()로 복원하여 리스너가 평소처럼 동작하도록 한다.
 *
 * 상주 워커(`queue:work`)는 컨테이너를 잡 사이에 재사용한다 — 인증 사용자·요청 바인딩·로케일이 다음
 * 잡으로 그대로 넘어간다. 잡 안에서 복원할 때는 within() 으로 감싸거나 snapshot()/revert() 짝으로
 * 잡이 끝나면(성공·실패 모두) 이전 상태로 되돌린다.
 *
 * 코어 기본 항목: user_id, ip_address, user_agent, locale, path, origin
 * 확장점:
 *  - capture() 마지막에 'hook.context.capture' 필터 발화 (플러그인 키 추가 가능)
 *  - restore() 진입 직후 'hook.context.restore' 액션 발화 (플러그인 복원 로직)
 */
class HookContextCapture
{
    /**
     * 복원된 진입점(origin)을 담는 컨테이너 키.
     */
    public const ORIGIN_BINDING = 'hook.context.origin';

    /**
     * 실행 중인 리스너 잡의 이벤트 ID 를 담는 컨테이너 키 (#139).
     */
    public const EVENT_ID_BINDING = 'hook.context.event_id';

    /**
     * 지금 실행 중인 훅 리스너 잡의 이벤트 ID 를 반환합니다 (#139).
     *
     * `DispatchHookListenerJob` 이 디스패치 때 만든 uuid 로, 그 잡이 재시도·재예약되어도 같다. 리스너는 이 값으로
     * 「이 사건에서 이미 한 일」을 가려낸다(예: 알림 수신자별 중복 방지 키). 리스너 잡 단위라 같은 훅의 다른
     * 리스너 잡과는 값이 다르고, 큐를 거치지 않는 동기 실행(`'sync' => true`·직접 호출)에서는 null 이다.
     *
     * @return string|null 이벤트 ID (리스너 잡 밖이면 null)
     */
    public static function eventId(): ?string
    {
        $app = app();

        return $app->bound(self::EVENT_ID_BINDING) ? (string) $app->make(self::EVENT_ID_BINDING) : null;
    }

    /**
     * 현재 작업의 진입점을 반환합니다 — 'cli'(Artisan 커맨드·스케줄러) 또는 'http'(웹 요청).
     *
     * 큐 워커도 콘솔 프로세스라 runningInConsole() 만으로 판정하면, 어드민 화면에서 시작돼 큐로 넘어온
     * 작업까지 CLI 로 오판한다. 그래서 디스패치 시점에 capture() 로 진입점을 싣고, 워커의 restore() 가
     * 그 값을 ORIGIN_BINDING 에 복원한다. 복원값이 있으면 그것을, 없으면 현재 프로세스로 판정한다.
     * PHPUnit 프로세스는 콘솔이지만 요청 시뮬레이션이 대부분이라 'http' 로 본다.
     *
     * @return string 'cli' 또는 'http'
     */
    public static function origin(): string
    {
        $app = app();

        if ($app->bound(self::ORIGIN_BINDING)) {
            return (string) $app->make(self::ORIGIN_BINDING);
        }

        return $app->runningInConsole() && ! $app->runningUnitTests() ? 'cli' : 'http';
    }

    /**
     * 현재 요청 컨텍스트를 스냅샷으로 캡처합니다.
     *
     * dispatch 클로저에서 호출되므로 Auth/Request가 살아있는 상태입니다.
     *
     * @return array<string, mixed> 캡처한 요청 컨텍스트 (user_id·ip_address·user_agent·locale·path·origin 과 확장 키)
     */
    public static function capture(): array
    {
        $request = request();

        $context = [
            'user_id' => Auth::id(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'locale' => App::getLocale(),
            'path' => $request?->path(),
            'origin' => self::origin(),
        ];

        return HookManager::applyFilters('hook.context.capture', $context);
    }

    /**
     * 캡처된 컨텍스트를 큐 워커 환경에 복원합니다.
     *
     * Job::handle() 진입 직후 호출됩니다. 상주 워커는 컨테이너를 잡 사이에 재사용하므로
     * 복원한 값은 저절로 사라지지 않습니다 — 잡 안에서는 within() 을 쓰거나 snapshot() 을 떠 두었다가
     * finally 에서 revert() 하세요.
     *
     * 컨텍스트에 user_id 가 없으면 인증 사용자를 비웁니다 — 앞 잡이 남긴 사용자가 이 잡의 행위자로
     * 기록되지 않게 한다. 확장 복원 액션(`hook.context.restore`)은 그 뒤에 돌므로 확장이 둔 사용자는 남습니다.
     *
     * @param  array<string, mixed>  $context  capture() 로 만든 요청 컨텍스트
     * @return void 반환값 없음
     */
    public static function restore(array $context): void
    {
        // 사용자 없는 작업(비회원·CLI·시스템)은 사용자 없이 — 확장 복원보다 먼저 비워 확장이 둔 사용자를 지우지 않는다
        if (empty($context['user_id'])) {
            self::forgetAuthenticatedUser();
        }

        // 확장 복원 로직 (코어보다 먼저 실행 — 플러그인이 코어 동작에 영향 주는 컨텍스트 설정 가능)
        HookManager::doAction('hook.context.restore', $context);

        // 진입점 — 워커 프로세스 자신(콘솔)이 아니라 원 작업의 진입점으로 판정되게 한다
        if (! empty($context['origin'])) {
            app()->instance(self::ORIGIN_BINDING, (string) $context['origin']);
        }

        // 로케일
        if (! empty($context['locale'])) {
            App::setLocale($context['locale']);
        }

        // 인증 사용자
        // 주의: Sanctum의 RequestGuard는 onceUsingId()를 지원하지 않으므로
        // User를 직접 조회하여 setUser()로 주입합니다. 되돌리기는 revert() 몫입니다.
        if (! empty($context['user_id'])) {
            $user = User::find($context['user_id']);
            if ($user !== null) {
                Auth::setUser($user);
            } else {
                // 그새 지워진 사용자 — 앞 잡의 사용자를 남기지 않는다
                self::forgetAuthenticatedUser();
            }
        }

        // Request 재구성 (IP, User-Agent, Path)
        // ResolvesActivityLogType::resolveLogType() 등이 request()->path() / is('api/admin/*') 를
        // 큐 워커 컨텍스트에서 호출하기 때문에 path 복원이 필수입니다.
        $server = [];
        if (! empty($context['ip_address'])) {
            $server['REMOTE_ADDR'] = $context['ip_address'];
        }
        if (! empty($context['user_agent'])) {
            $server['HTTP_USER_AGENT'] = $context['user_agent'];
        }
        if (! empty($context['path'])) {
            $server['REQUEST_URI'] = '/'.ltrim($context['path'], '/');
        }

        if ($server !== []) {
            // 사이트 주소의 origin 으로 재구성한다 — Host 없는 요청을 바인딩하면 그 안의 url()·route() 가
            // `http://localhost/…` 를 만들어 큐에서 만든 알림 링크가 깨진다(사이트 주소가 없으면 종전처럼 경로만).
            $request = Request::create(
                SiteUrl::origin().($server['REQUEST_URI'] ?? '/'),
                'GET',
                [],
                [],
                [],
                $server,
            );
            app()->instance('request', $request);
            // Request 파사드는 처음 해석한 인스턴스를 붙들고 있다 — 비우지 않으면 활동 로그 프로세서의
            // Request::ip() 가 새 바인딩이 아니라 앞 요청의 IP·브라우저 정보를 읽는다.
            Facade::clearResolvedInstance('request');
        }
    }

    /**
     * 지금 프로세스의 문맥(요청 바인딩·인증 사용자·로케일·진입점·이벤트 ID)을 떠 둡니다.
     *
     * restore() 전에 떠 두었다가 잡이 끝나면 revert() 로 되돌린다. 인증 사용자는 hasUser() 로 읽어
     * 인증 해석(세션·토큰 조회)을 새로 일으키지 않는다.
     *
     * @return array{request: mixed, user: mixed, locale: string, origin: mixed, event_id: mixed} 떠 둔 문맥
     */
    public static function snapshot(): array
    {
        $app = app();

        return [
            'request' => $app->bound('request') ? $app->make('request') : null,
            'user' => Auth::hasUser() ? Auth::user() : null,
            'locale' => App::getLocale(),
            'origin' => $app->bound(self::ORIGIN_BINDING) ? $app->make(self::ORIGIN_BINDING) : null,
            'event_id' => $app->bound(self::EVENT_ID_BINDING) ? $app->make(self::EVENT_ID_BINDING) : null,
        ];
    }

    /**
     * snapshot() 으로 떠 둔 문맥으로 되돌립니다.
     *
     * 큐 드라이버가 sync 면 잡이 원 요청과 같은 컨테이너에서 돌므로 원 요청의 바인딩·사용자가 돌아오고,
     * 상주 워커에서는 잡 전의 빈 상태(사용자 없음)로 돌아간다.
     *
     * @param  array<string, mixed>  $snapshot  snapshot() 결과
     * @return void 반환값 없음
     */
    public static function revert(array $snapshot): void
    {
        $app = app();

        if (($snapshot['request'] ?? null) !== null) {
            $app->instance('request', $snapshot['request']);
            Facade::clearResolvedInstance('request');
        }

        if (($snapshot['origin'] ?? null) !== null) {
            $app->instance(self::ORIGIN_BINDING, $snapshot['origin']);
        } else {
            $app->forgetInstance(self::ORIGIN_BINDING);
        }

        if (($snapshot['event_id'] ?? null) !== null) {
            $app->instance(self::EVENT_ID_BINDING, $snapshot['event_id']);
        } else {
            $app->forgetInstance(self::EVENT_ID_BINDING);
        }

        if (($snapshot['user'] ?? null) !== null) {
            Auth::setUser($snapshot['user']);
        } else {
            self::forgetAuthenticatedUser();
        }

        if (! empty($snapshot['locale']) && App::getLocale() !== $snapshot['locale']) {
            App::setLocale((string) $snapshot['locale']);
        }
    }

    /**
     * 컨텍스트를 복원한 채 콜백을 실행하고, 끝나면(예외여도) 이전 문맥으로 되돌립니다.
     *
     * @template T
     *
     * @param  array<string, mixed>  $context  capture() 결과
     * @param  callable(): T  $callback  복원된 문맥에서 실행할 콜백
     * @return T 콜백의 반환값
     */
    public static function within(array $context, callable $callback): mixed
    {
        $snapshot = self::snapshot();

        try {
            self::restore($context);

            return $callback();
        } finally {
            self::revert($snapshot);
        }
    }

    /**
     * 큐 워커가 잡을 집기 직전에 문맥을 워커 기본 상태로 되돌립니다.
     *
     * within()·snapshot()/revert() 를 쓰지 않고 사용자·요청을 바꿔 둔 채 끝난 잡이 있어도 다음 잡이 그것을
     * 물려받지 않게 하는 마지막 울타리다 — 인증 사용자 없음, 콘솔 요청, 기본 로케일, 진입점·이벤트 ID·보는
     * 사람 시간대 바인딩 없음. sync 연결 잡은 웹 요청 안에서 돌므로 호출하지 않는다.
     *
     * @return void 반환값 없음
     */
    public static function resetForQueueJob(): void
    {
        $app = app();

        self::forgetAuthenticatedUser();

        $app->forgetInstance(self::ORIGIN_BINDING);
        $app->forgetInstance(self::EVENT_ID_BINDING);
        $app->forgetInstance(SetTimezone::TIMEZONE_KEY);

        (new SetRequestForConsole)->bootstrap($app);
        Facade::clearResolvedInstance('request');

        $locale = (string) config('app.locale', '');
        if ($locale !== '' && App::getLocale() !== $locale) {
            App::setLocale($locale);
        }
    }

    /**
     * 기본 가드의 인증 사용자를 비웁니다 (가드가 비우기를 지원할 때만).
     *
     * @return void 반환값 없음
     */
    private static function forgetAuthenticatedUser(): void
    {
        $guard = Auth::guard();

        if (method_exists($guard, 'forgetUser')) {
            $guard->forgetUser();
        }
    }
}
