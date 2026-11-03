<?php

namespace App\Providers;

use App\Broadcasting\BroadcastChannelRegistrar;
use Illuminate\Support\ServiceProvider;

/**
 * 브로드캐스트 채널 인가 정의(`routes/channels.php`)의 등록기를 바인딩합니다 — 실제 등록은 인가 요청에서 합니다.
 *
 * 왜 프레임워크 기본 경로를 쓰지 않는가:
 *   `bootstrap/app.php` 의 `withRouting(channels: ...)` 인자는 두 가지를 한꺼번에 한다 —
 *   ① `routes/channels.php` 로드(원하는 것) ② `Broadcast::routes()` 자동 호출(원치 않는 것).
 *   ②가 등록하는 `GET|POST /broadcasting/auth` 에는 어떤 게이트도 없어서, 웹소켓 사용 OFF
 *   (`broadcasting.default === 'null'`, 공개#50) 킬스위치를 통째로 우회하는 경로가 된다.
 *   프론트(`WebSocketManager`)는 게이트된 `/api/broadcasting/auth` 만 호출하므로 그 라우트는
 *   死라우트이면서 우회로이기만 했다 — production 에서 미인증 POST 가 200 을 받았다(공개#128).
 *
 *   그래서 `channels:` 인자를 떼어 ②의 자동 등록을 끊고, ①만 이 프로바이더가 담당한다.
 *   인증 엔드포인트의 SSoT 는 `routes/api.php` 의 `api.broadcasting.auth` 하나다
 *   (`auth:sanctum` + 킬스위치 가드).
 *
 * 지연 등록 (#860):
 *   채널 인가 콜백은 인가 요청에서만 쓰이는데, 부팅마다 `Broadcast::channel()` 을 부르면 그때마다
 *   드라이버(reverb 면 Pusher·HTTP 클라이언트)가 만들어졌다. 그래서 이 프로바이더는
 *   {@see BroadcastChannelRegistrar} 만 등록하고, `api.broadcasting.auth` 라우트가 `Broadcast::auth()`
 *   직전에 `ensureRegistered()` 로 코어(`routes/channels.php`)·활성 확장 채널을 한 번 등록한다.
 *   라우트 처리 안에서 부르므로 라우트 캐시와 무관하다. 등록이 빠지면 예외 없이 **모든 private 채널
 *   구독이 403** 이 되므로(콜백이 없는 채널은 거부) 인가 라우트의 호출을 지우지 않는다.
 */
class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * 채널 등록기를 싱글톤으로 등록합니다.
     *
     * @return void 반환값 없음
     */
    public function register(): void
    {
        $this->app->singleton(BroadcastChannelRegistrar::class);
    }
}
