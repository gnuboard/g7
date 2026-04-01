# Broadcasting (실시간 이벤트)

> **중요도**: 중요
> **관련 문서**: [service-repository.md](service-repository.md) | [authentication.md](authentication.md)

---

## TL;DR (5초 요약)

```text
1. Laravel Reverb 사용 (WebSocket)
2. 이벤트: ShouldBroadcast 인터페이스 구현
3. 채널: public, private, presence
4. 훅 연동: HookManager에서 broadcast() 호출
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

---

## 브로드캐스트 이벤트 생성

### 기본 구조

```php
<?php

namespace App\Events\Dashboard;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 대시보드 업데이트 브로드캐스트 이벤트
 */
class DashboardUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * 이벤트 인스턴스를 생성합니다.
     *
     * @param string $type 업데이트 타입 ('stats', 'resources', 'activities')
     * @param array $data 업데이트된 데이터
     */
    public function __construct(
        public string $type,
        public array $data
    ) {}

    /**
     * 브로드캐스트할 채널을 반환합니다.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.dashboard')];
    }

    /**
     * 브로드캐스트 이벤트명을 반환합니다.
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return "dashboard.{$this->type}.updated";
    }
}
```

### 채널 타입

| 타입 | 클래스 | 용도 | 인증 필요 |
|------|--------|------|----------|
| Public | `Channel` | 모든 사용자 접근 가능 | ❌ |
| Private | `PrivateChannel` | 인증된 사용자만 접근 | ✅ |
| Presence | `PresenceChannel` | 인증 + 접속자 목록 공유 | ✅ |

### 이벤트 발생

```php
// 방법 1: broadcast() 헬퍼 (권장)
broadcast(new DashboardUpdated('stats', $statsData));

// 방법 2: event() 헬퍼
event(new DashboardUpdated('resources', $resourceData));

// 방법 3: 즉시 전송 (큐 미사용)
broadcast(new DashboardUpdated('alerts', $alertData))->toOthers();
```

---

## 채널 인증

### routes/channels.php

```php
<?php

use Illuminate\Support\Facades\Broadcast;

// 사용자별 Private 채널
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// 관리자 대시보드 채널 - 권한 체크
Broadcast::channel('admin.dashboard', function ($user) {
    return $user->hasPermission('core.dashboard.read');
});

// 특정 리소스 채널
Broadcast::channel('orders.{orderId}', function ($user, $orderId) {
    return $user->hasPermission('ecommerce.orders.read');
});
```

### 인증 콜백 규칙

```php
// ✅ DO: boolean 반환 (Private 채널)
Broadcast::channel('admin.dashboard', function ($user) {
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
Broadcast::channel('admin.dashboard', function ($user) {
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

## 훅을 통한 이벤트 발생

Service 계층에서 데이터 변경 시 훅 리스너를 통해 브로드캐스트 이벤트를 발생시킵니다.

### config/hooks.php

```php
return [
    'listeners' => [
        // 대시보드 통계 업데이트 시 브로드캐스트
        'core.dashboard.stats_updated' => [
            \App\Listeners\BroadcastDashboardStats::class,
        ],

        // 사용자 생성 시 대시보드 통계 업데이트
        'core.user.after_create' => [
            \App\Listeners\UpdateDashboardStatsOnUserChange::class,
        ],
    ],
];
```

### 리스너 구현

```php
<?php

namespace App\Listeners;

use App\Events\Dashboard\DashboardUpdated;
use App\Services\DashboardService;

class BroadcastDashboardStats
{
    public function __construct(
        private DashboardService $dashboardService
    ) {}

    /**
     * 대시보드 통계 브로드캐스트
     *
     * @param array $data 훅 데이터
     * @return void
     */
    public function handle(array $data): void
    {
        $stats = $this->dashboardService->getStats();
        broadcast(new DashboardUpdated('stats', $stats));
    }
}
```

---

## 스케줄러를 통한 주기적 브로드캐스트

시스템 리소스 등 주기적으로 업데이트가 필요한 데이터는 스케줄러를 사용합니다.

### Artisan 커맨드

```php
<?php

namespace App\Console\Commands;

use App\Events\Dashboard\DashboardUpdated;
use App\Services\DashboardService;
use Illuminate\Console\Command;

class BroadcastDashboardResources extends Command
{
    protected $signature = 'dashboard:broadcast-resources';
    protected $description = '시스템 리소스 정보를 WebSocket으로 브로드캐스트합니다.';

    public function handle(DashboardService $dashboardService): int
    {
        $resources = $dashboardService->getSystemResources();
        broadcast(new DashboardUpdated('resources', $resources));

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
