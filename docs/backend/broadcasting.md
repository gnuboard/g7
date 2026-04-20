# Broadcasting (실시간 이벤트)

> **중요도**: 중요
> **관련 문서**: [service-repository.md](service-repository.md) | [authentication.md](authentication.md)

---

## TL;DR (5초 요약)

```text
1. Laravel Reverb 사용 (WebSocket)
2. 브로드캐스트 필수: HookManager::broadcast($channel, $eventName, $payload) 사용
3. 채널: public, private, presence (인증은 routes/channels.php)
4. 개별 Event 클래스 직접 생성 금지 → HookManager::broadcast() 사용
5. 클라이언트: Laravel Echo + Pusher-js
```

---

## 목차

1. [개요](#개요)
2. [Laravel Reverb 설정](#laravel-reverb-설정)
3. [브로드캐스트 이벤트 생성](#브로드캐스트-이벤트-생성)
4. [채널 인증](#채널-인증)
5. [API 인증 엔드포인트](#api-인증-엔드포인트)
6. [훅을 통한 이벤트 발생](#훅을-통한-이벤트-발생)
7. [스케줄러를 통한 주기적 브로드캐스트](#스케줄러를-통한-주기적-브로드캐스트)
8. [개발 환경 설정](#개발-환경-설정)
9. [프로덕션 환경 설정](#프로덕션-환경-설정)

---

## 개요

G7은 **Laravel Reverb**를 사용하여 실시간 WebSocket 통신을 지원합니다.

**주요 사용 사례**:
- 대시보드 실시간 통계 업데이트
- 알림 실시간 전송
- 채팅 기능
- 실시간 협업 기능

**아키텍처**:
```
클라이언트 (Echo/Pusher-js)
        ↕ WebSocket
Laravel Reverb 서버
        ↕
Laravel 백엔드 (broadcast() 호출)
        ↕
Queue Worker (이벤트 처리)
```

---

## Laravel Reverb 설정

### 환경변수 (.env)

```env
# Broadcasting 드라이버
BROADCAST_CONNECTION=reverb

# Reverb 서버 설정
REVERB_APP_ID=467955
REVERB_APP_KEY=zm0vobuy4zpqorc3ro9r
REVERB_APP_SECRET=your-secret-key
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=https

# 개발 환경에서 자체 서명 인증서 사용 시
REVERB_VERIFY_SSL=false

# 클라이언트용 설정 (Vite)
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

### config/broadcasting.php

```php
'reverb' => [
    'driver' => 'reverb',
    'key' => env('REVERB_APP_KEY'),
    'secret' => env('REVERB_APP_SECRET'),
    'app_id' => env('REVERB_APP_ID'),
    'options' => [
        'host' => env('REVERB_HOST'),
        'port' => env('REVERB_PORT', 443),
        'scheme' => env('REVERB_SCHEME', 'https'),
        'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
    ],
    'client_options' => [
        // 개발 환경에서 자체 서명 인증서 사용 시 SSL 검증 비활성화
        'verify' => env('REVERB_VERIFY_SSL', true),
    ],
],
```

> **주의**: 프로덕션 환경에서는 `REVERB_VERIFY_SSL=true`로 설정해야 합니다.

### 클라이언트/서버 endpoint 분리 (리버스 프록시 환경)

WebSocket은 두 가지 endpoint를 가집니다:

| Endpoint | 용도 | 사용 주체 | 예시 |
|----------|------|---------|------|
| 클라이언트 (외부) | 브라우저가 WebSocket 접속 | 브라우저 → Reverb (직접 또는 리버스 프록시 경유) | `g7.dev:443` (https) |
| 서버 (내부) | 백엔드가 broadcast HTTP API 호출 | Pusher SDK → Reverb (Laravel queue worker 내부) | `127.0.0.1:8080` (http) |

**환경설정 → 드라이버 → 웹소켓**에서 두 endpoint를 분리 입력할 수 있습니다:

- 호스트/포트/프로토콜 (클라이언트) — 브라우저용 외부 endpoint
- 서버 호스트/포트/프로토콜 (내부) — 백엔드 broadcast HTTP API용

서버 endpoint가 비어있으면 클라이언트 값으로 fallback (단일 호스트 환경 호환). 리버스 프록시 환경(예: Apache가 `/apps/*`를 Reverb로 프록시하지 못하는 경우)에서는 반드시 server endpoint를 `127.0.0.1:8080` 등 내부 직접 주소로 입력해야 합니다. 그렇지 않으면 Pusher SDK가 외부 host로 POST하여 Apache가 받아 `Method Not Allowed` 발생.

`SettingsServiceProvider::applyWebsocketConfig()`는 클라이언트 endpoint를 `g7.websocket.client.{host,port,scheme}` config 키에, 서버 endpoint를 `broadcasting.connections.reverb.options.*` 및 `reverb.apps.apps.0.options.*`에 분리 적용합니다. Blade(`admin.blade.php`/`app.blade.php`)는 `g7.websocket.client.*`를 우선 읽어 브라우저로 전달합니다.

### Settings 저장 시 큐 워커 재시작 자동화

`SettingsService::saveSettings()`는 drivers 탭 저장 시 자동으로 `Artisan::call('queue:restart')`를 호출합니다. 이는 long-running queue worker가 SettingsServiceProvider boot 시점의 config를 메모리에 캐싱하기 때문입니다. drivers 변경이 워커에 반영되려면 워커가 정상 종료 후 supervisor/스크립트로 재시작되어야 합니다.

---

## HookManager::broadcast() API

### 사용법

```php
use App\Extension\HookManager;

// 기본 사용법 — 채널, 이벤트명, 데이터
HookManager::broadcast('core.admin.dashboard', 'dashboard.stats.updated', [
    'type' => 'stats',
    'data' => $statsData,
]);

// 사용자별 알림 브로드캐스트 — UUID 사용 (User ID 노출 방지, 보안 강화)
HookManager::broadcast("core.user.notifications.{$user->uuid}", 'notification.received', [
    'subject' => '새 주문',
    'body' => '주문이 접수되었습니다.',
    'type' => 'order_created',
]);
```

### 파라미터

| 파라미터 | 타입 | 설명 | 예시 |
|---------|------|------|------|
| `$channel` | string | Private 채널명 | `'core.admin.dashboard'`, `'core.user.notifications.{uuid}'` |
| `$eventName` | string | 클라이언트 수신 이벤트명 | `'dashboard.stats.updated'` |
| `$payload` | array | 브로드캐스트 데이터 | `['type' => 'stats', 'data' => [...]]` |

### 절대 금지

```
❌ broadcast(new SpecificEvent(...))  — 개별 Event 클래스 직접 생성/사용 금지
❌ event(new SpecificEvent(...))      — event() 헬퍼 직접 사용 금지
✅ HookManager::broadcast(...)       — 유일한 브로드캐스트 방법
```

내부적으로 `GenericBroadcastEvent`를 사용하지만, 이는 HookManager의 구현 디테일이므로 외부에서 직접 참조하지 않습니다.

### Graceful Skip (안전한 건너뛰기)

`HookManager::broadcast()`는 아래 조건에서 브로드캐스트를 **시도하지 않고 즉시 반환**합니다:

| 조건 | 동작 |
|------|------|
| 관리자 환경설정 → 드라이버 → 웹소켓 사용 OFF (`websocket_enabled=false`) | `SettingsServiceProvider::applyWebsocketConfig()`가 `broadcasting.default`를 `'null'`로 강제 → 즉시 return |
| `BROADCAST_CONNECTION=null` 또는 `log` | 즉시 return (연결 시도 없음) |
| 드라이버의 `host` 미설정 (예: `REVERB_HOST` 비어있음) | 즉시 return (연결 시도 없음) |
| 설정 정상이나 서버 미실행 (cURL 연결 실패) | `Log::warning` 기록 후 return (예외 미전파) |

브로드캐스팅은 부가 기능이므로 실패해도 메인 작업(사용자 업데이트, 주문 처리, 알림 발송 등)을 중단시키지 않습니다. 개별 리스너에서 try-catch를 추가할 필요가 없습니다.

> **중요**: 웹소켓 OFF 시 `broadcasting.default`가 `'null'`로 강제되는 동작은 `.env`의 `BROADCAST_CONNECTION` 값을 무시하고 적용됩니다. 따라서 PO가 환경설정에서 OFF한 경우, `.env`에 `REVERB_HOST=localhost` 등이 남아 있어도 broadcast 시도가 발생하지 않습니다. 알림 시스템(mail/database 채널)은 이 설정과 독립적으로 정상 동작합니다.

### 채널 타입

| 타입 | 클래스 | 용도 | 인증 필요 |
|------|--------|------|----------|
| Public | `Channel` | 모든 사용자 접근 가능 | ❌ |
| Private | `PrivateChannel` | 인증된 사용자만 접근 | ✅ |
| Presence | `PresenceChannel` | 인증 + 접속자 목록 공유 | ✅ |

현재 `HookManager::broadcast()`는 Private 채널만 지원합니다. Public/Presence 채널이 필요한 경우 HookManager 확장이 필요합니다.

---

## 채널 네이밍 규칙

코어와 확장(모듈/플러그인) 간 채널명 충돌을 방지하기 위한 필수 컨벤션:

| 소스 | 패턴 | 예시 |
|------|------|------|
| 코어 | `core.*` | `core.admin.dashboard`, `core.user.notifications.{id}` |
| 모듈 | `module.{identifier}.*` | `module.sirsoft-ecommerce.orders.{id}` |
| 플러그인 | `plugin.{identifier}.*` | `plugin.sirsoft-payment.status.{id}` |

---

## 모듈/플러그인 채널 등록

모듈/플러그인에서 WebSocket 채널이 필요한 경우 `getChannels()` 메서드를 오버라이드합니다.
`ModuleManager`/`PluginManager`가 로드 시 자동으로 `Broadcast::channel()`에 등록합니다.

### 모듈 예시

```php
// modules/sirsoft-ecommerce/src/module.php
class EcommerceModule extends AbstractModule
{
    public function getChannels(): array
    {
        return [
            'module.sirsoft-ecommerce.orders.{id}' => [
                'permission' => 'sirsoft-ecommerce.orders.read',
            ],
            'module.sirsoft-ecommerce.cart.{cartKey}' => [
                // permission 없음 → 인증만 필요
            ],
        ];
    }
}
```

### 플러그인 예시

```php
// plugins/sirsoft-payment/src/plugin.php
class PaymentPlugin extends AbstractPlugin
{
    public function getChannels(): array
    {
        return [
            'plugin.sirsoft-payment.status.{id}' => [
                'permission' => 'sirsoft-payment.payments.read',
            ],
        ];
    }
}
```

### 채널 정의 형식

| 키 | 타입 | 설명 | 기본값 |
|-----|------|------|--------|
| `permission` | `string\|null` | 권한 식별자 (`hasPermission()` 체크) | `null` (인증만) |
| `type` | `string` | 채널 타입 (`private`) | `private` |

### 프론트엔드에서 수신

모듈 레이아웃에서 WebSocket 데이터소스를 정의합니다:

```json
{
    "id": "order_updates_ws",
    "type": "websocket",
    "channel": "module.sirsoft-ecommerce.orders.{{_global.currentUser?.id}}",
    "event": "order.updated",
    "channel_type": "private",
    "target_source": "orders"
}
```

### 모듈에서 브로드캐스트 전송

훅 리스너에서 `HookManager::broadcast()`를 호출합니다:

```php
HookManager::broadcast(
    "module.sirsoft-ecommerce.orders.{$userId}",
    'order.updated',
    ['order_id' => $order->id, 'status' => $order->status]
);
```

---

## 채널 인증

### routes/channels.php

```php
<?php

use Illuminate\Support\Facades\Broadcast;

// 사용자별 Private 채널 — UUID 사용 (User ID 노출 방지)
Broadcast::channel('core.user.notifications.{uuid}', function ($user, $uuid) {
    return $user->uuid === $uuid && $user->hasPermission('core.user-notifications.read', \App\Enums\PermissionType::User);
});

// 관리자 대시보드 채널 - 권한 체크
Broadcast::channel('core.admin.dashboard', function ($user) {
    return $user->hasPermission('core.dashboard.read');
});

// 모듈 리소스 채널 (모듈의 getChannels()로 자동 등록 — 참고용 예시)
// Broadcast::channel('module.sirsoft-ecommerce.orders.{orderId}', function ($user, $orderId) {
//     return $user->hasPermission('sirsoft-ecommerce.orders.read');
// });
```

### 사용자별 채널은 UUID 사용 (보안)

```php
// ✅ DO: UUID 기반 — 다른 사용자 채널 추측 사실상 불가능
Broadcast::channel('core.user.notifications.{uuid}', function ($user, $uuid) {
    return $user->uuid === $uuid && $user->hasPermission('core.user-notifications.read', \App\Enums\PermissionType::User);
});

// 백엔드 broadcast 시
HookManager::broadcast(
    "core.user.notifications.{$user->uuid}",
    'notification.received',
    ['subject' => '...', 'body' => '...']
);

// ❌ DON'T: 정수 ID — 1, 2, 3... 순차 ID 노출로 채널 추측 가능
Broadcast::channel('core.user.notifications.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
```

### 인증 콜백 규칙

```php
// ✅ DO: boolean 반환 (Private 채널)
Broadcast::channel('core.admin.dashboard', function ($user) {
    return $user->hasPermission('core.dashboard.read');
});

// ✅ DO: 배열 반환 (Presence 채널 - 사용자 정보 공유)
Broadcast::channel('chat.{roomId}', function ($user, $roomId) {
    if ($user->canJoinRoom($roomId)) {
        return ['id' => $user->id, 'name' => $user->name];
    }
    return false;
});

// ❌ DON'T: 예외 던지기
Broadcast::channel('core.admin.dashboard', function ($user) {
    throw new \Exception('Unauthorized'); // 금지
});
```

---

## API 인증 엔드포인트

G7은 SPA 환경에서 **Sanctum 토큰 기반 인증**을 사용합니다. 기본 `/broadcasting/auth` 엔드포인트는 세션 기반이므로, API용 별도 엔드포인트를 사용합니다.

### routes/api.php

```php
// 브로드캐스팅 인증 (Sanctum 토큰 사용)
Route::middleware(['auth:sanctum'])->post('broadcasting/auth', function (\Illuminate\Http\Request $request) {
    return \Illuminate\Support\Facades\Broadcast::auth($request);
})->name('api.broadcasting.auth');
```

### 클라이언트 설정 (WebSocketManager)

```typescript
const pusherOptions = {
  // ... 기타 옵션
  authEndpoint: '/api/broadcasting/auth',
  auth: {
    headers: {
      'Authorization': `Bearer ${authToken}`,
      'Accept': 'application/json',
    },
  },
};
```

---

## 훅을 통한 브로드캐스트 발생

Service 계층에서 데이터 변경 시 훅 리스너를 통해 `HookManager::broadcast()`를 호출합니다.

### 리스너 구현 패턴

```php
<?php

namespace App\Listeners\Dashboard;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookManager;
use App\Services\DashboardService;

class DashboardStatsListener implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        return [
            'core.user.after_create' => ['method' => 'handleStatsUpdate', 'priority' => 10],
            'core.user.after_update' => ['method' => 'handleStatsUpdate', 'priority' => 10],
        ];
    }

    public function __construct(
        private DashboardService $dashboardService
    ) {}

    public function handle(...$args): void {}

    /**
     * 대시보드 통계 업데이트를 브로드캐스트합니다.
     */
    public function handleStatsUpdate(...$args): void
    {
        HookManager::broadcast('core.admin.dashboard', 'dashboard.stats.updated', [
            'type' => 'stats',
            'data' => $this->dashboardService->getStats(),
        ]);
    }
}
```

### 큐 워커에서의 사용자 컨텍스트

훅 리스너는 기본적으로 큐로 디스패치되며, 큐 워커는 별도 프로세스이므로 `Auth::user()`/`request()->ip()`/`App::getLocale()`이 모두 리셋됩니다. 그러나 G7은 디스패치 시점의 컨텍스트를 자동 캡처/복원하므로 워커에서도 평소처럼 사용할 수 있습니다:

- broadcast 페이로드에 `Auth::user()->name` 같은 사용자 정보를 포함시켜도 안전
- 활동로그/알림 발송 시 행위자가 실제 로그인 사용자로 정상 기록
- 다국어 메시지가 원래 요청 로케일로 발송

> 자세한 동작과 플러그인 확장 방법은 [extension/hooks.md "사용자 컨텍스트 자동 복원"](../extension/hooks.md) 참조

---

## 스케줄러를 통한 주기적 브로드캐스트

시스템 리소스 등 주기적으로 업데이트가 필요한 데이터는 스케줄러를 사용합니다.

### Artisan 커맨드

```php
<?php

namespace App\Console\Commands;

use App\Extension\HookManager;
use App\Services\DashboardService;
use Illuminate\Console\Command;

class BroadcastDashboardResources extends Command
{
    protected $signature = 'dashboard:broadcast-resources';
    protected $description = '시스템 리소스 정보를 WebSocket으로 브로드캐스트합니다.';

    public function handle(DashboardService $dashboardService): int
    {
        HookManager::broadcast('core.admin.dashboard', 'dashboard.resources.updated', [
            'type' => 'resources',
            'data' => $dashboardService->getSystemResources(),
        ]);

        $this->info('시스템 리소스 정보가 브로드캐스트되었습니다.');
        return Command::SUCCESS;
    }
}
```

### routes/console.php (스케줄러 등록)

```php
use Illuminate\Support\Facades\Schedule;

// 30초마다 시스템 리소스 브로드캐스트
Schedule::command('dashboard:broadcast-resources')->everyThirtySeconds();
```

### 실행 방법

```bash
# 스케줄러 실행 (개발 환경)
php artisan schedule:work

# 큐 워커 실행 (브로드캐스트 이벤트 처리)
php artisan queue:work

# Reverb 서버 실행
php artisan reverb:start --debug
```

---

## 개발 환경 설정

### 필요한 프로세스

개발 환경에서 WebSocket을 테스트하려면 다음 프로세스가 모두 실행 중이어야 합니다:

```bash
# 터미널 1: Laravel 개발 서버
php artisan serve

# 터미널 2: Reverb WebSocket 서버
php artisan reverb:start --debug

# 터미널 3: 큐 워커 (브로드캐스트 이벤트 처리)
php artisan queue:work

# 터미널 4: 스케줄러 (주기적 브로드캐스트 사용 시)
php artisan schedule:work
```

### SSL 인증서 문제 해결

개발 환경에서 자체 서명 인증서 사용 시 다음 설정이 필요합니다:

```env
# .env
REVERB_VERIFY_SSL=false
```

이 설정은 Laravel이 Reverb 서버로 이벤트를 전송할 때 SSL 인증서 검증을 비활성화합니다.

---

## 프로덕션 환경 설정

### 권장 설정

```env
# .env (프로덕션)
REVERB_VERIFY_SSL=true
REVERB_SCHEME=https
REVERB_PORT=443
```

### Supervisor 설정 예시

```ini
[program:reverb]
command=php /var/www/g7/artisan reverb:start
directory=/var/www/g7
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/reverb.log

[program:queue-worker]
command=php /var/www/g7/artisan queue:work --sleep=3 --tries=3
directory=/var/www/g7
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/queue-worker.log
```

---

## 디버깅

### 이벤트 전송 확인

```bash
# 수동으로 이벤트 발생
php artisan dashboard:broadcast-resources

# 큐 작업 확인
php artisan queue:work --once
```

### 로그 확인

```bash
# Laravel 로그
tail -f storage/logs/laravel.log

# Reverb 서버 로그 (--debug 옵션 사용 시)
php artisan reverb:start --debug
```

### 클라이언트 디버깅

브라우저 개발자 도구에서:
1. **Network** 탭 → **WS** 필터 → WebSocket 연결 확인
2. **Messages** 탭에서 송수신 메시지 확인
3. **Console** 탭에서 `[WebSocketManager]` 로그 확인

---

## 관련 문서

- [Service-Repository 패턴](service-repository.md) - 훅 실행 위치
- [인증](authentication.md) - Sanctum 토큰 인증
- [프론트엔드 WebSocket](../frontend/data-sources-advanced.md) - 클라이언트 WebSocket 구독
