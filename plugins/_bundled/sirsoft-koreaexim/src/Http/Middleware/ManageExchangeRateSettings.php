<?php

namespace Plugins\Sirsoft\Koreaexim\Http\Middleware;

use App\Http\Middleware\PermissionMiddleware;
use Closure;
use Illuminate\Http\Request;

/** 코어 설정 저장 경로에 이 플러그인의 관리 권한을 추가합니다. */
class ManageExchangeRateSettings
{
    /**
     * 등록된 처리를 실행합니다.
     *
     * @param  Request  $request  request 입력값
     * @param  Closure  $next  next 입력값
     * @return mixed 처리 결과
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->isMethod('PUT')) {
            return app(PermissionMiddleware::class)->handle($request, $next, 'admin', 'sirsoft-koreaexim.exchange_rate.manage');
        }

        return $next($request);
    }
}
