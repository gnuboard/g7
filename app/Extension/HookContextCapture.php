<?php

namespace App\Extension;

use App\Models\User;
use App\Support\SiteUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;

/**
 * 훅 리스너 큐 디스패치 시 요청 컨텍스트 캡처/복원 헬퍼.
 *
 * 큐 워커는 별도 프로세스이므로 Auth, Request, App 로케일이 모두 리셋된다.
 * 디스패치 시점(원래 요청 컨텍스트)에 capture()로 스냅샷을 만들고,
 * Job::handle() 진입 시 restore()로 복원하여 리스너가 평소처럼 동작하도록 한다.
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
     * @return array<string, mixed>
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
     * Job::handle() 진입 직후 호출됩니다. Job 종료 시 컨테이너가 폐기되므로
     * 별도 원복(finally) 처리는 불필요합니다.
     *
     * @param  array<string, mixed>  $context
     * @return void 반환값 없음
     */
    public static function restore(array $context): void
    {
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
        // User를 직접 조회하여 setUser()로 주입합니다. (Job 종료 시 컨테이너 폐기되어 자동 해제)
        if (! empty($context['user_id'])) {
            $user = User::find($context['user_id']);
            if ($user !== null) {
                Auth::setUser($user);
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
        }
    }
}
