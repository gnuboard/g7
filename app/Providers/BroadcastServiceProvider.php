<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * 브로드캐스트 채널 인가 정의를 로드합니다 (`routes/channels.php`).
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
 * 라우트 캐시 안전:
 *   `Broadcast::channel()` 은 라우트가 아니라 브로드캐스터의 인가 콜백 등록이라 라우트 캐시와
 *   무관하다. 매 부팅마다 실행되어야 하며, 누락되면 예외 없이 **모든 private 채널 구독이 403**
 *   이 된다(콜백이 없는 채널은 거부되므로).
 */
class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * 채널 인가 정의를 등록합니다.
     *
     * @return void
     */
    public function boot(): void
    {
        $channels = base_path('routes/channels.php');

        if (is_file($channels)) {
            require $channels;
        }
    }
}
