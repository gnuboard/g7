# 제작의뢰 모듈 — 알림·정리·E2E (Plan 4/4)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Plan 1-3 위에 상태 전이와 새 메시지를 알림으로 전달하는 7종 Notification 클래스, 이벤트 구독 Listener 2종, orphan 첨부 정리 명령, 그리고 알림 동작을 보장하는 E2E 검증 테스트를 추가하여 의뢰 모듈 v1을 완성한다.

**Architecture:** 프로젝트의 `App\Notifications\BaseNotification` 추상 클래스를 상속. `HookManager` Filter 훅으로 채널을 동적 결정 (board 모듈 패턴). 인앱(database) + 이메일(mail) 채널. 두 Listener 가 `InquiryStatusTransitioned` / `InquiryMessagePosted` 이벤트를 받아 적절한 Notification을 적절한 수신자에게 발송. Orphan 첨부는 `inquiry:cleanup-orphan-attachments` 명령 + daily schedule 로 정리. E2E 테스트는 `Notification::fake()` 로 발송 여부만 검증.

**Tech Stack:** Laravel 11 Notification + Mail / `App\Notifications\BaseNotification` / HookManager Filter / Schedule.

**Spec:** `docs/superpowers/specs/2026-05-20-제작의뢰-design.md` §9
**Plans 1-3:** `docs/superpowers/plans/2026-05-2{1,1,2}-제작의뢰-*.md`

---

## Critical Facts

### Notification 베이스 패턴

`app/Notifications/BaseNotification.php`:
- `abstract function getHookPrefix(): string` — inquiry는 `'sirsoft-inquiry'`
- `abstract function getNotificationType(): string` — 예: `'inquiry_received'`, `'quote_issued'`
- `via()` 가 HookManager filter `{prefix}.notification.channels` 적용하여 채널 결정
- `toMail()`은 서브클래스 자체 구현 (Laravel 규약)
- `toDatabase()` / `toArray()` 도 서브클래스 구현 (인앱 페이로드)

### 채널 필터 등록

board 패턴: `BoardNotificationChannelListener`가 HookManager에 `sirsoft-board.notification.channels` filter 등록. inquiry도 `InquiryNotificationChannelListener` 신설하여 `sirsoft-inquiry.notification.channels` filter 등록.

기본은 `['mail']`이지만 v1에서는 `['mail', 'database']` 둘 다 지원해야 하므로 filter에서 `database` 추가.

### 수신자 결정 정책

- **운영자 그룹**: `inquiry.notify` 권한 보유자 전원. `User::permission('inquiry.notify')->get()` 식 조회.
- **의뢰자**: `Inquiry::user`.

각 Notification 발송 시 `Notification::send($recipients, new XxxNotification($inquiry))` 호출.

---

## File Structure

```
modules/_bundled/sirsoft-inquiry/src/
  Notifications/
    InquiryNotification.php                # 베이스 (BaseNotification 상속)
    InquiryReceivedToOperators.php
    QuoteIssued.php
    QuoteRevoked.php
    PaymentConfirmed.php
    InquiryCompleted.php
    InquiryCanceled.php
    NewMessageNotification.php             # 메시지 별도
  Listeners/
    DispatchInquiryStatusNotifications.php  # InquiryStatusTransitioned 구독
    DispatchInquiryMessageNotification.php  # InquiryMessagePosted 구독
    InquiryNotificationChannelListener.php  # HookManager filter 등록
  Console/Commands/
    CleanupOrphanAttachmentsCommand.php     # inquiry:cleanup-orphan-attachments
  Providers/
    InquiryServiceProvider.php              # MODIFY: register listeners + schedule

modules/_bundled/sirsoft-inquiry/resources/lang/
  ko/notifications.php                      # 메일 제목·본문 다국어
  en/notifications.php

tests/Feature/Modules/Inquiry/
  NotificationsTest.php
  CleanupOrphanAttachmentsTest.php
```

---

## Pre-check

- [ ] **Pre-1: Plan 3 회귀**

```bash
git branch --show-current
php artisan test --filter="Modules\\\\Inquiry" 2>&1 | tail -3
```

기대: 65 tests pass.

- [ ] **Pre-2: BaseNotification + board 알림 listener 확인**

```bash
cat app/Notifications/BaseNotification.php | head -60
cat modules/_bundled/sirsoft-board/src/Listeners/BoardNotificationChannelListener.php | head -80
cat modules/_bundled/sirsoft-board/src/Listeners/BoardNotificationDataListener.php | head -80
```

board 의 Notification 분배 패턴(Listener → Notification::send) 흐름을 익힘.

- [ ] **Pre-3: 운영자 권한 조회 방법 확인**

```bash
grep -rn "permission\|hasPermission" app/Models/User.php | head -10
grep -rn "Permission::where\|whereHas\('permissions" app/ modules/ 2>/dev/null | head -10
```

`User::permission('inquiry.notify')->get()` 가 작동하지 않으면 `whereHas('roles.permissions')` 패턴 사용.

---

## Task 1: InquiryNotification 베이스 + InquiryReceivedToOperators

**Files:**
- Create: `src/Notifications/InquiryNotification.php`
- Create: `src/Notifications/InquiryReceivedToOperators.php`
- Create: `src/lang/ko/notifications.php`, `src/lang/en/notifications.php`

- [ ] **Step 1: lang 파일 작성**

`src/lang/ko/notifications.php`:

```php
<?php

return [
    'inquiry_received' => [
        'subject' => '[제작의뢰] 새 의뢰가 접수되었습니다',
        'greeting' => '안녕하세요',
        'line' => ':title — :client 님의 새 의뢰가 접수되었습니다.',
        'action' => '의뢰 보기',
    ],
    'quote_issued' => [
        'subject' => '[제작의뢰] 견적이 발행되었습니다',
        'line' => ':title — 견적 #:version (합계 :total원)이 발행되었습니다.',
        'action' => '견적 확인',
    ],
    'quote_revoked' => [
        'subject' => '[제작의뢰] 견적이 철회되었습니다',
        'line' => ':title — 견적 #:version 이 운영자에 의해 철회되었습니다.',
        'action' => '의뢰 보기',
    ],
    'payment_confirmed' => [
        'subject' => '[제작의뢰] 결제가 확인되어 작업이 시작됩니다',
        'line' => ':title — 결제가 확인되어 진행이 시작되었습니다.',
        'action' => '의뢰 보기',
    ],
    'inquiry_completed' => [
        'subject' => '[제작의뢰] 의뢰가 완료되었습니다',
        'line' => ':title — 운영자가 의뢰를 완료 처리했습니다.',
        'action' => '의뢰 보기',
    ],
    'inquiry_canceled' => [
        'subject' => '[제작의뢰] 의뢰가 취소되었습니다',
        'line_by_client' => ':title — 의뢰자가 의뢰를 취소했습니다.',
        'line_by_operator' => ':title — 운영자에 의해 의뢰가 취소되었습니다.',
        'action' => '의뢰 보기',
    ],
    'new_message' => [
        'subject' => '[제작의뢰] 새 메시지가 도착했습니다',
        'line' => ':title — :sender 님이 새 메시지를 남겼습니다.',
        'action' => '의뢰 보기',
    ],
];
```

`src/lang/en/notifications.php` — 같은 키에 영문 (생략 가능 — fallback to ko).

- [ ] **Step 2: 베이스 클래스 작성**

`src/Notifications/InquiryNotification.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Notifications;

use App\Notifications\BaseNotification;
use Illuminate\Bus\Queueable;
use Modules\Sirsoft\Inquiry\Models\Inquiry;

abstract class InquiryNotification extends BaseNotification
{
    use Queueable;

    public function __construct(
        public readonly Inquiry $inquiry,
        public readonly array $params = [],
    ) {}

    protected function getHookPrefix(): string
    {
        return 'sirsoft-inquiry';
    }

    /**
     * 인앱 알림 페이로드 (database 채널).
     */
    public function toArray(object $notifiable): array
    {
        return [
            'inquiry_uuid' => $this->inquiry->uuid,
            'inquiry_title' => $this->inquiry->title,
            'type' => $this->getNotificationType(),
            'params' => $this->params,
            'url' => $this->resolveUrl($notifiable),
        ];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->toArray($notifiable);
    }

    protected function resolveUrl(object $notifiable): string
    {
        // 운영자 화면이면 /admin/inquiry/{uuid}, 일반 사용자면 /inquiry/{uuid}
        $isOperator = method_exists($notifiable, 'can')
            && $notifiable->can(config('inquiry.permissions.manage', 'inquiry.manage'));
        $base = $isOperator ? '/admin/inquiry' : '/inquiry';
        return url($base . '/' . $this->inquiry->uuid);
    }
}
```

- [ ] **Step 3: 첫 Notification 클래스 + 실패 테스트**

`tests/Feature/Modules/Inquiry/NotificationsTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Inquiry;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Notifications\InquiryReceivedToOperators;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = ['modules/_bundled/sirsoft-inquiry'];

    public function test_received_notification_renders_mail(): void
    {
        $client = User::factory()->create(['name' => '홍길동']);
        $inquiry = Inquiry::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $client->id,
            'title' => '홈페이지 리뉴얼',
            'content' => 'X',
            'status' => 'received',
        ]);

        $operator = User::factory()->create();
        $notification = new InquiryReceivedToOperators($inquiry);

        $mail = $notification->toMail($operator);
        $this->assertStringContainsString('홈페이지 리뉴얼', $mail->subject ?? '');
        $payload = $notification->toArray($operator);
        $this->assertSame($inquiry->uuid, $payload['inquiry_uuid']);
        $this->assertSame('inquiry_received', $payload['type']);
    }
}
```

```bash
php artisan test --filter=NotificationsTest 2>&1 | tail -10
```

기대: class not found.

- [ ] **Step 4: InquiryReceivedToOperators 작성**

`src/Notifications/InquiryReceivedToOperators.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class InquiryReceivedToOperators extends InquiryNotification
{
    protected function getNotificationType(): string
    {
        return 'inquiry_received';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = __('inquiry::notifications.inquiry_received.subject');
        $line = __('inquiry::notifications.inquiry_received.line', [
            'title' => $this->inquiry->title,
            'client' => $this->inquiry->user?->name ?? '익명',
        ]);

        return (new MailMessage)
            ->subject($subject)
            ->greeting(__('inquiry::notifications.inquiry_received.greeting'))
            ->line($line)
            ->action(__('inquiry::notifications.inquiry_received.action'), $this->resolveUrl($notifiable));
    }
}
```

- [ ] **Step 5: 테스트 PASS + Commit**

```bash
php artisan test --filter=NotificationsTest 2>&1 | tail -10
git add modules/_bundled/sirsoft-inquiry/src/Notifications/InquiryNotification.php \
        modules/_bundled/sirsoft-inquiry/src/Notifications/InquiryReceivedToOperators.php \
        modules/_bundled/sirsoft-inquiry/src/lang/ko/notifications.php \
        modules/_bundled/sirsoft-inquiry/src/lang/en/notifications.php \
        tests/Feature/Modules/Inquiry/NotificationsTest.php
git commit -m "feat(inquiry): add InquiryNotification base + InquiryReceivedToOperators"
```

---

## Task 2: Quote notifications (Issued + Revoked)

**Files:**
- Create: `src/Notifications/QuoteIssued.php`
- Create: `src/Notifications/QuoteRevoked.php`

- [ ] **Step 1: 작성**

`QuoteIssued.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class QuoteIssued extends InquiryNotification
{
    protected function getNotificationType(): string
    {
        return 'quote_issued';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $params = [
            'title' => $this->inquiry->title,
            'version' => $this->params['version'] ?? '?',
            'total' => isset($this->params['total']) ? number_format((int) $this->params['total']) : '-',
        ];

        return (new MailMessage)
            ->subject(__('inquiry::notifications.quote_issued.subject'))
            ->line(__('inquiry::notifications.quote_issued.line', $params))
            ->action(__('inquiry::notifications.quote_issued.action'), $this->resolveUrl($notifiable));
    }
}
```

`QuoteRevoked.php`: 동일 패턴, `getNotificationType()` = `'quote_revoked'`, 다국어 키 `quote_revoked`.

- [ ] **Step 2: 테스트 추가 (NotificationsTest)**

```php
public function test_quote_issued_renders(): void
{
    $client = User::factory()->create();
    $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $client->id, 'title' => 'X', 'content' => 'Y', 'status' => 'quoted']);

    $n = new \Modules\Sirsoft\Inquiry\Notifications\QuoteIssued($inquiry, ['version' => 2, 'total' => 1500000]);
    $mail = $n->toMail($client);
    $this->assertStringContainsString('견적', $mail->subject);
    $payload = $n->toArray($client);
    $this->assertSame('quote_issued', $payload['type']);
    $this->assertSame(2, $payload['params']['version']);
}
```

- [ ] **Step 3: PASS + Commit**

```bash
php artisan test --filter=NotificationsTest 2>&1 | tail -10
git add modules/_bundled/sirsoft-inquiry/src/Notifications/QuoteIssued.php \
        modules/_bundled/sirsoft-inquiry/src/Notifications/QuoteRevoked.php \
        tests/Feature/Modules/Inquiry/NotificationsTest.php
git commit -m "feat(inquiry): add QuoteIssued + QuoteRevoked notifications"
```

---

## Task 3: Payment + Completion + Cancellation notifications

**Files:**
- Create: `src/Notifications/PaymentConfirmed.php`
- Create: `src/Notifications/InquiryCompleted.php`
- Create: `src/Notifications/InquiryCanceled.php`

각각 Task 2 패턴 동일. `getNotificationType()` 값과 다국어 키만 차이.

- `PaymentConfirmed` → `'payment_confirmed'`
- `InquiryCompleted` → `'inquiry_completed'`
- `InquiryCanceled` → `'inquiry_canceled'` — `toMail()` 안에서 `$this->params['actor']` (client/operator) 에 따라 line 다국어 키 분기:

```php
$lineKey = ($this->params['actor'] ?? null) === 'operator'
    ? 'inquiry::notifications.inquiry_canceled.line_by_operator'
    : 'inquiry::notifications.inquiry_canceled.line_by_client';
```

- [ ] **Step 1: 3 클래스 작성** (각각 Task 2 패턴 복사 + 키 변경)

- [ ] **Step 2: 테스트 추가**

```php
public function test_payment_confirmed_renders(): void
{
    $client = User::factory()->create();
    $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $client->id, 'title' => 'X', 'content' => 'Y', 'status' => 'in_progress']);
    $n = new \Modules\Sirsoft\Inquiry\Notifications\PaymentConfirmed($inquiry, ['order' => 'order-uuid']);
    $this->assertSame('payment_confirmed', $n->toArray($client)['type']);
}

public function test_canceled_by_operator_uses_operator_line(): void
{
    $client = User::factory()->create();
    $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $client->id, 'title' => 'X', 'content' => 'Y', 'status' => 'canceled']);
    $n = new \Modules\Sirsoft\Inquiry\Notifications\InquiryCanceled($inquiry, ['actor' => 'operator']);
    $mail = $n->toMail($client);
    $this->assertStringContainsString('운영자', $mail->subject . ' ' . implode(' ', $mail->introLines ?? []));
}
```

- [ ] **Step 3: PASS + Commit**

```bash
php artisan test --filter=NotificationsTest 2>&1 | tail -10
git add modules/_bundled/sirsoft-inquiry/src/Notifications/PaymentConfirmed.php \
        modules/_bundled/sirsoft-inquiry/src/Notifications/InquiryCompleted.php \
        modules/_bundled/sirsoft-inquiry/src/Notifications/InquiryCanceled.php \
        tests/Feature/Modules/Inquiry/NotificationsTest.php
git commit -m "feat(inquiry): add Payment/Completed/Canceled notifications"
```

---

## Task 4: NewMessage notification

**Files:**
- Create: `src/Notifications/NewMessageNotification.php`

`getNotificationType()` = `'new_message'`. params에 `sender_name`, `body_preview` 받음.

```php
<?php

namespace Modules\Sirsoft\Inquiry\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class NewMessageNotification extends InquiryNotification
{
    protected function getNotificationType(): string
    {
        return 'new_message';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('inquiry::notifications.new_message.subject'))
            ->line(__('inquiry::notifications.new_message.line', [
                'title' => $this->inquiry->title,
                'sender' => $this->params['sender_name'] ?? '상대방',
            ]))
            ->action(__('inquiry::notifications.new_message.action'), $this->resolveUrl($notifiable));
    }
}
```

- [ ] **Step 1: 작성 + 테스트 + Commit**

```bash
php artisan test --filter=NotificationsTest 2>&1 | tail -10
git add modules/_bundled/sirsoft-inquiry/src/Notifications/NewMessageNotification.php
git commit -m "feat(inquiry): add NewMessageNotification"
```

---

## Task 5: DispatchInquiryStatusNotifications Listener

**Files:**
- Create: `src/Listeners/DispatchInquiryStatusNotifications.php`

`InquiryStatusTransitioned` 이벤트(`Modules\Sirsoft\Inquiry\Events\InquiryStatusTransitioned`)를 구독하여 전이 종류에 따라 적절한 Notification을 적절한 수신자에게 발송.

- [ ] **Step 1: 작성**

```php
<?php

namespace Modules\Sirsoft\Inquiry\Listeners;

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Modules\Sirsoft\Inquiry\Enums\TransitionEvent;
use Modules\Sirsoft\Inquiry\Events\InquiryStatusTransitioned;
use Modules\Sirsoft\Inquiry\Notifications\InquiryCanceled;
use Modules\Sirsoft\Inquiry\Notifications\InquiryCompleted;
use Modules\Sirsoft\Inquiry\Notifications\InquiryReceivedToOperators;
use Modules\Sirsoft\Inquiry\Notifications\PaymentConfirmed;
use Modules\Sirsoft\Inquiry\Notifications\QuoteIssued;
use Modules\Sirsoft\Inquiry\Notifications\QuoteRevoked;

class DispatchInquiryStatusNotifications
{
    public function handle(InquiryStatusTransitioned $event): void
    {
        $inquiry = $event->inquiry;
        $client = $inquiry->user;

        switch ($event->event) {
            case TransitionEvent::IssueQuote:
                $latestQuote = $inquiry->quotes()->latest('version')->first();
                Notification::send([$client], new QuoteIssued($inquiry, [
                    'version' => $latestQuote?->version,
                    'total' => $latestQuote?->total_amount,
                ]));
                break;

            case TransitionEvent::RevokeQuote:
                Notification::send([$client], new QuoteRevoked($inquiry, [
                    'version' => $event->inquiry->quotes()->latest('version')->first()?->version,
                ]));
                break;

            case TransitionEvent::AcceptAndPay:
            case TransitionEvent::MarkPaidOffline:
                $operators = $this->operators();
                Notification::send(array_merge([$client], $operators), new PaymentConfirmed($inquiry));
                break;

            case TransitionEvent::MarkCompleted:
                Notification::send([$client], new InquiryCompleted($inquiry));
                break;

            case TransitionEvent::Cancel:
                $actor = $event->actorUserId;
                $actorRole = ($actor === $client->id) ? 'client' : 'operator';
                $recipients = $actorRole === 'client' ? $this->operators() : [$client];
                Notification::send($recipients, new InquiryCanceled($inquiry, ['actor' => $actorRole]));
                break;
        }

        // 새 의뢰 접수 알림 (status='received' 신규 진입은 InquiryRepository에서 이벤트 dispatch 안 함)
        // → 별도 InquiryCreated 이벤트 또는 InquiryRepository::create() 후 fireevent.
        //   여기서는 다루지 않음 (Task 7에서 ServiceProvider 안에 explicit dispatch 처리).
    }

    /** @return array<User> */
    private function operators(): array
    {
        $permission = config('inquiry.permissions.notify', 'inquiry.notify');
        // Try permission relationship first; fallback to roles relationship.
        return User::whereHas('roles.permissions', function ($q) use ($permission) {
            $q->where('identifier', $permission);
        })->get()->all();
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/src/Listeners/DispatchInquiryStatusNotifications.php
git commit -m "feat(inquiry): add DispatchInquiryStatusNotifications listener"
```

---

## Task 6: DispatchInquiryMessageNotification + Inquiry creation notification

**Files:**
- Create: `src/Listeners/DispatchInquiryMessageNotification.php`
- Modify: `src/Http/Controllers/User/InquiryController.php::store()` — 신규 의뢰 생성 후 운영자 그룹에 InquiryReceivedToOperators 발송

- [ ] **Step 1: 메시지 listener**

```php
<?php

namespace Modules\Sirsoft\Inquiry\Listeners;

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Modules\Sirsoft\Inquiry\Enums\SenderRole;
use Modules\Sirsoft\Inquiry\Events\InquiryMessagePosted;
use Modules\Sirsoft\Inquiry\Notifications\NewMessageNotification;

class DispatchInquiryMessageNotification
{
    public function handle(InquiryMessagePosted $event): void
    {
        $msg = $event->message;
        if ($msg->sender_role === SenderRole::System) {
            return; // 시스템 메시지는 알림 발송 안 함
        }

        $inquiry = $msg->inquiry;
        $sender = $msg->sender;

        if ($msg->sender_role === SenderRole::Client) {
            // 클라이언트 → 운영자 그룹
            $recipients = $this->operators();
        } else {
            // 운영자 → 클라이언트
            $recipients = [$inquiry->user];
        }

        Notification::send($recipients, new NewMessageNotification($inquiry, [
            'sender_name' => $sender?->name ?? '익명',
            'body_preview' => mb_substr((string) $msg->body, 0, 80),
        ]));
    }

    /** @return array<User> */
    private function operators(): array
    {
        $permission = config('inquiry.permissions.notify', 'inquiry.notify');
        return User::whereHas('roles.permissions', function ($q) use ($permission) {
            $q->where('identifier', $permission);
        })->get()->all();
    }
}
```

- [ ] **Step 2: InquiryController::store() 에서 신규 의뢰 알림 발송**

`store()` 메서드 끝에 `return` 직전 추가:

```php
// 운영자 그룹에 신규 의뢰 알림
$operators = \App\Models\User::whereHas('roles.permissions', function ($q) {
    $q->where('identifier', config('inquiry.permissions.notify', 'inquiry.notify'));
})->get();
if ($operators->isNotEmpty()) {
    \Illuminate\Support\Facades\Notification::send($operators, new \Modules\Sirsoft\Inquiry\Notifications\InquiryReceivedToOperators($inquiry));
}
```

- [ ] **Step 3: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/src/Listeners/DispatchInquiryMessageNotification.php \
        modules/_bundled/sirsoft-inquiry/src/Http/Controllers/User/InquiryController.php
git commit -m "feat(inquiry): add message + inquiry-created notification dispatch"
```

---

## Task 7: Channel filter listener + ServiceProvider event/listener 등록

**Files:**
- Create: `src/Listeners/InquiryNotificationChannelListener.php`
- Modify: `src/Providers/InquiryServiceProvider.php`

- [ ] **Step 1: Channel filter listener**

`src/Listeners/InquiryNotificationChannelListener.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Listeners;

use App\Extension\HookManager;

class InquiryNotificationChannelListener
{
    /**
     * 알림 채널 정책: 항상 인앱(database) + 이메일(mail) 두 채널.
     * 사용자별 끄기는 v2 범위.
     */
    public function register(): void
    {
        HookManager::addFilter(
            'sirsoft-inquiry.notification.channels',
            function (array $channels, string $type, object $notifiable): array {
                return ['mail', 'database'];
            },
            10,
            3
        );
    }
}
```

(`HookManager::addFilter` 시그니처는 board 패턴 확인 — `BoardNotificationChannelListener`의 호출 형태를 그대로 사용.)

- [ ] **Step 2: ServiceProvider 에 listener·hook 등록**

`InquiryServiceProvider::boot()` 안에 추가:

```php
// 채널 필터 등록
$this->app->make(\Modules\Sirsoft\Inquiry\Listeners\InquiryNotificationChannelListener::class)->register();

// 이벤트 listener 등록
\Illuminate\Support\Facades\Event::listen(
    \Modules\Sirsoft\Inquiry\Events\InquiryStatusTransitioned::class,
    \Modules\Sirsoft\Inquiry\Listeners\DispatchInquiryStatusNotifications::class . '@handle'
);
\Illuminate\Support\Facades\Event::listen(
    \Modules\Sirsoft\Inquiry\Events\InquiryMessagePosted::class,
    \Modules\Sirsoft\Inquiry\Listeners\DispatchInquiryMessageNotification::class . '@handle'
);
```

- [ ] **Step 3: 권한 시드** — `inquiry.notify` 권한이 DB에 있어야 운영자 그룹 조회 가능. board 처럼 모듈 install 시 권한 자동 생성. `module.php` 의 `getPermissions()` 또는 `boot()` 안에 firstOrCreate:

```php
// In InquiryServiceProvider::boot() 안:
$this->app->booted(function () {
    if (\Schema::hasTable('permissions')) {
        \App\Models\Permission::firstOrCreate(
            ['identifier' => 'inquiry.notify'],
            ['name' => 'Inquiry Notify']
        );
        \App\Models\Permission::firstOrCreate(
            ['identifier' => 'inquiry.manage'],
            ['name' => 'Inquiry Manage']
        );
    }
});
```

(board 의 권한 시드 방식 확인 후 동일 패턴 사용 — `module.php` 에 `getPermissions()` 가 있다면 그쪽으로.)

- [ ] **Step 4: Commit**

```bash
git add modules/_bundled/sirsoft-inquiry/src/Listeners/InquiryNotificationChannelListener.php \
        modules/_bundled/sirsoft-inquiry/src/Providers/InquiryServiceProvider.php
git commit -m "feat(inquiry): register notification channels filter + event listeners"
```

---

## Task 8: E2E 알림 검증 테스트

**Files:**
- Modify: `tests/Feature/Modules/Inquiry/NotificationsTest.php` (append)

`Notification::fake()` 로 발송 여부 검증.

- [ ] **Step 1: 테스트 추가**

```php
public function test_quote_issued_notification_sent_on_transition(): void
{
    Notification::fake();
    $client = User::factory()->create();
    $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $client->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);

    $sm = app(\Modules\Sirsoft\Inquiry\Services\InquiryStateMachine::class);
    $inquiry->quotes()->create(['version' => 1, 'total_amount' => 1000000, 'currency' => 'KRW', 'status' => 'issued', 'issued_at' => now()]);
    $sm->transition(
        $inquiry,
        \Modules\Sirsoft\Inquiry\Enums\TransitionEvent::IssueQuote,
        actorUserId: $client->id,
        payload: ['quote_version' => 1, 'quote_total' => 1000000],
    );

    Notification::assertSentTo([$client], \Modules\Sirsoft\Inquiry\Notifications\QuoteIssued::class);
}

public function test_new_message_notification_sent_to_operators_on_client_message(): void
{
    Notification::fake();
    $client = User::factory()->create();
    $operator = User::factory()->create();

    // 운영자에게 inquiry.notify 권한 부여 — PolicyTest 패턴 참고
    $perm = \App\Models\Permission::firstOrCreate(['identifier' => 'inquiry.notify'], ['name' => 'Inquiry Notify']);
    $role = \App\Models\Role::firstOrCreate(['identifier' => 'inquiry-notify-test'], ['name' => 'Inquiry Notify']);
    $role->permissions()->syncWithoutDetaching([$perm->id => ['granted_at' => now()]]);
    $operator->roles()->syncWithoutDetaching([$role->id => ['assigned_at' => now()]]);

    $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $client->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);
    $msg = $inquiry->messages()->create(['sender_user_id' => $client->id, 'sender_role' => 'client', 'body' => '안녕하세요']);
    \Modules\Sirsoft\Inquiry\Events\InquiryMessagePosted::dispatch($msg);

    Notification::assertSentTo([$operator], \Modules\Sirsoft\Inquiry\Notifications\NewMessageNotification::class);
}

public function test_canceled_by_operator_notification_to_client_only(): void
{
    Notification::fake();
    $client = User::factory()->create();
    $operator = User::factory()->create();
    $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $client->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);

    $sm = app(\Modules\Sirsoft\Inquiry\Services\InquiryStateMachine::class);
    $sm->transition(
        $inquiry,
        \Modules\Sirsoft\Inquiry\Enums\TransitionEvent::Cancel,
        actorUserId: $operator->id,
        payload: ['actor' => 'operator'],
    );

    Notification::assertSentTo([$client], \Modules\Sirsoft\Inquiry\Notifications\InquiryCanceled::class);
}
```

- [ ] **Step 2: PASS + Commit**

```bash
php artisan test --filter=NotificationsTest 2>&1 | tail -15
git add tests/Feature/Modules/Inquiry/NotificationsTest.php
git commit -m "test(inquiry): verify notifications fire on state transitions + messages"
```

---

## Task 9: inquiry:cleanup-orphan-attachments command

**Files:**
- Create: `src/Console/Commands/CleanupOrphanAttachmentsCommand.php`
- Modify: `src/Providers/InquiryServiceProvider.php` — register + schedule

- [ ] **Step 1: 실패 테스트**

`tests/Feature/Modules/Inquiry/CleanupOrphanAttachmentsTest.php`:

```php
<?php

namespace Tests\Feature\Modules\Inquiry;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Tests\TestCase;

class CleanupOrphanAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = ['modules/_bundled/sirsoft-inquiry'];

    public function test_cleanup_removes_old_unattached_attachments(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);

        // Orphan: message_id is null AND created_at > 30 min ago
        $orphan = $inquiry->attachments()->create([
            'message_id' => null,
            'uploader_user_id' => $user->id,
            'disk' => 'local',
            'path' => 'inquiries/test/orphan.pdf',
            'original_name' => 'orphan.pdf',
            'mime' => 'application/pdf',
            'size' => 100,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        Storage::disk('local')->put($orphan->path, 'content');

        // Fresh upload: not orphan yet
        $fresh = $inquiry->attachments()->create([
            'message_id' => null,
            'uploader_user_id' => $user->id,
            'disk' => 'local',
            'path' => 'inquiries/test/fresh.pdf',
            'original_name' => 'fresh.pdf',
            'mime' => 'application/pdf',
            'size' => 100,
        ]);
        Storage::disk('local')->put($fresh->path, 'content');

        $this->artisan('inquiry:cleanup-orphan-attachments')->assertExitCode(0);

        $this->assertNull($orphan->fresh());
        Storage::disk('local')->assertMissing($orphan->path);
        $this->assertNotNull($fresh->fresh());
    }
}
```

- [ ] **Step 2: Command 작성**

`src/Console/Commands/CleanupOrphanAttachmentsCommand.php`:

```php
<?php

namespace Modules\Sirsoft\Inquiry\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryAttachmentRepositoryInterface;

class CleanupOrphanAttachmentsCommand extends Command
{
    protected $signature = 'inquiry:cleanup-orphan-attachments';
    protected $description = 'Remove orphan inquiry attachments (uploaded but not linked to a message within the cutoff window).';

    public function handle(InquiryAttachmentRepositoryInterface $attachments): int
    {
        $minutes = (int) config('inquiry.attachment.orphan_cleanup_after_minutes', 30);
        $orphans = $attachments->listOrphansOlderThanMinutes($minutes);

        $count = 0;
        foreach ($orphans as $att) {
            try {
                Storage::disk($att->disk)->delete($att->path);
            } catch (\Throwable $e) {
                $this->warn("Failed to delete file for attachment #{$att->id}: {$e->getMessage()}");
            }
            $attachments->delete($att);
            $count++;
        }

        $this->info("Cleaned up {$count} orphan attachment(s).");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 3: ServiceProvider 등록**

`InquiryServiceProvider::boot()` 안 `$this->commands([...])` 배열에 `CleanupOrphanAttachmentsCommand::class` 추가. Schedule 도 callAfterResolving 안에 추가:

```php
$schedule->command('inquiry:cleanup-orphan-attachments')->hourly();
```

- [ ] **Step 4: PASS + Commit**

```bash
php artisan test --filter=CleanupOrphanAttachmentsTest 2>&1 | tail -10
git add modules/_bundled/sirsoft-inquiry/src/Console/Commands/CleanupOrphanAttachmentsCommand.php \
        modules/_bundled/sirsoft-inquiry/src/Providers/InquiryServiceProvider.php \
        tests/Feature/Modules/Inquiry/CleanupOrphanAttachmentsTest.php
git commit -m "feat(inquiry): add inquiry:cleanup-orphan-attachments command"
```

---

## Task 10: 최종 통합 회귀 + Phase 4 회고 (no commit)

- [ ] **Step 1: 전체 inquiry 테스트**

```bash
php artisan test --filter="Modules\\\\Inquiry" 2>&1 | tail -10
```

기대: Plan 3의 65 + Plan 4의 ~9 = 74+ tests pass.

- [ ] **Step 2: 빌드 + push**

```bash
cd templates/_bundled/sirsoft-basic && npm run build 2>&1 | tail -5
cd ../../..
git push fork feature/sirsoft-inquiry-foundation 2>&1 | tail -3
```

- [ ] **Step 3: 회고**

```bash
git log --oneline 5194e28..HEAD | head -50    # Plan 3-4 commits
git diff --stat 5194e28..HEAD | tail -3
```

v1 의뢰 모듈 완성. 

남은 v2 후속 (spec §14):
- 완료 의뢰 포트폴리오 공개 (`is_showcase`)
- 부분/분할 결제, 자동 환불
- SLA·계약서 PDF
- SMS·카카오톡 알림
- 실시간 푸시 (WebSocket)
- 다중 작업자 배정

---

## 부록 A — 자주 발생할 수 있는 문제

**HookManager::addFilter 시그니처 미일치**

board 의 `BoardNotificationChannelListener` 의 실제 호출 형태(파라미터 개수, 콜백 시그니처)를 확인해서 똑같이. 본 plan의 코드는 추정이므로 실제로 다르면 보정.

**Permission 시드 시점**

`booted()` 콜백 안에서 `\Schema::hasTable('permissions')` 체크 — 마이그레이션 전이면 skip. board 모듈이 권한을 어떻게 시드하는지 (`module.php` 의 `getPermissions()` 또는 별도 Seeder) 확인 후 동일 패턴.

**`Notification::fake()` 와 hook 채널 필터**

`fake()` 는 채널 필터를 우회하므로 `via()` 결과와 무관하게 발송 추적. 단, Notification 클래스의 `toMail()` / `toDatabase()` 가 실제 호출되지는 않음. 발송 여부만 검증 가능.

**User 모델의 `roles()` / `permissions()` 관계**

`$user->roles()->whereHas('permissions', ...)` 는 본 프로젝트의 자체 Role-Permission 구조에 의존. 안 되면 `App\Models\Role::whereHas('permissions', ...)->whereHas('users', ...)` 식으로 우회.

**오케스트레이션 충돌**

`DispatchInquiryStatusNotifications` 가 모든 전이를 한 handler에서 처리하므로, payload 에서 의도 추출 (예: Cancel 의 actor=client|operator) 필요. `$event->actorUserId === $event->inquiry->user_id` 비교로 actor 판단.
