<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\DetectsRequestLocale;
use App\Support\PrivilegedDatabaseAccounts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * DB 사용자명이 사용 불가일 때 전용 에러 페이지를 렌더링합니다.
 *
 * 배경: DB 사용자명이 최고권한 계정이거나 비어 있으면 CoreServiceProvider 가
 * 확장(모듈·플러그인·템플릿) 로딩 전체를 스킵한다. 그 결과 템플릿 없는 깨진
 * 화면만 뜨고 원인은 로그 한 줄에만 남아 운영자가 알 방법이 없었다.
 * 이 미들웨어가 그 "조용한 실패" 를 드러낸다.
 *
 * ServiceProvider::boot() 가 아니라 미들웨어에 두는 이유: boot() 에서 abort 하면
 * `php artisan` 자체가 죽어 복구 수단을 잃는다. 미들웨어는 웹 요청만 타므로
 * 콘솔이 자연히 통과하고 운영자가 config:clear 등으로 복구할 수 있다.
 */
class DatabaseCredentialGuard
{
    use DetectsRequestLocale;

    /**
     * DB 자격증명을 검사해 사용 불가 시 503 응답을 반환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 미설치 상태는 통과 — DB 설정이 비어 있는 것이 정상이며,
        // public/index.php 가 이미 /install 로 유도한다.
        if (! config('app.installer_completed')) {
            return $next($request);
        }

        $username = $this->resolveUsername();

        if (PrivilegedDatabaseAccounts::isUsable($username)) {
            return $next($request);
        }

        // SetLocale 미실행 상태이므로 로케일을 자체 감지한다.
        // API/HTML 분기보다 먼저 적용해야 JSON 메시지도 활성 로케일을 반영한다.
        app()->setLocale($this->detectLocale($request));

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'message' => __('database_credential.message'),
            ], 503);
        }

        // 확장·템플릿이 로딩되지 않은 상태이므로 템플릿 뷰나 errors/layout 을
        // 쓸 수 없다. DB/JS 무의존 자체 완결 정적 페이지를 반환한다.
        return response()->view('database-credential-error', [
            // 실제 계정명은 전달하지 않는다 — 미인증 공개 페이지이므로 정보 유출.
            'reason' => PrivilegedDatabaseAccounts::isBlocked($username) ? 'blocked' : 'empty',
        ], 503);
    }

    /**
     * 현재 DB 커넥션 설정에서 사용자명을 해석합니다.
     *
     * DB::connection() 을 거치지 않고 config 배열만 읽습니다. 이 가드는 DB 설정이
     * 잘못된 상황에서 동작해야 하므로, 판정을 위해 커넥션을 해석하는 것 자체가
     * 새로운 실패 지점이 됩니다. read/write 우선순위 규칙은
     * PrivilegedDatabaseAccounts 가 CoreServiceProvider 와 공유합니다.
     *
     * @return string|null 해석된 사용자명 (없으면 null)
     */
    protected function resolveUsername(): ?string
    {
        $connection = config('database.default');

        return PrivilegedDatabaseAccounts::resolveUsername(
            config("database.connections.{$connection}", [])
        );
    }
}
