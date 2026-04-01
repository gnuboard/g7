# 알림 시스템 (Notification System)

> 그누보드7 알림 시스템의 아키텍처와 확장 가이드

## TL;DR (5초 요약)

```text
1. 모든 알림은 BaseNotification 상속 필수 (via() 보일러플레이트 제거)
2. getHookPrefix() + getNotificationType() 구현 → 훅명 자동 생성
3. toMail()은 각 서브클래스가 직접 구현 (Laravel 규약)
4. 각 모듈은 자체 Mailable 클래스 소유 (AuthNotificationMail, BoardNotificationMail)
5. 채널 확장: 플러그인이 Filter 훅으로 via() 채널 추가
```

---

## 아키텍처 개요

### BaseNotification 추상 클래스

```text
BaseNotification (abstract)
├── getHookPrefix(): string       → 'core.auth', 'sirsoft-board', 'sirsoft-ecommerce'
├── getNotificationType(): string → 'welcome', 'new_comment', 'order_confirmed'
└── via(): array                  → HookManager::applyFilters() 자동 호출
```

**위치**: `app/Notifications/BaseNotification.php`

**역할**: `via()` 메서드만 추상화. `toMail()`은 각 서브클래스가 직접 구현.

### 훅 이름 규칙

```text
{hookPrefix}.notification.channels
```

| 모듈 | hookPrefix | 훅명 |
|------|-----------|------|
| 코어 인증 | `core.auth` | `core.auth.notification.channels` |
| 게시판 | `sirsoft-board` | `sirsoft-board.notification.channels` |
| 이커머스 | `sirsoft-ecommerce` | `sirsoft-ecommerce.notification.channels` |

---

## 코어 알림 클래스

### 인증 알림 (app/Notifications/Auth/)

| 클래스 | hookPrefix | notificationType | Mailable |
|--------|-----------|-----------------|----------|
| WelcomeNotification | `core.auth` | `welcome` | AuthNotificationMail |
| ResetPasswordNotification | `core.auth` | `reset_password` | AuthNotificationMail |
| PasswordChangedNotification | `core.auth` | `password_changed` | AuthNotificationMail |

### 게시판 알림 (modules/sirsoft-board/src/Notifications/)

| 클래스 | hookPrefix | notificationType | Mailable |
|--------|-----------|-----------------|----------|
| NewCommentNotification | `sirsoft-board` | `new_comment` | BoardNotificationMail |
| ReplyCommentNotification | `sirsoft-board` | `reply_comment` | BoardNotificationMail |
| PostReplyNotification | `sirsoft-board` | `post_reply` | BoardNotificationMail |
| PostActionNotification | `sirsoft-board` | `post_action` | BoardNotificationMail |
| NewPostAdminNotification | `sirsoft-board` | `new_post_admin` | BoardNotificationMail |

---

## 새 알림 클래스 작성법

### 1. BaseNotification 상속

```php
namespace Modules\Sirsoft\Ecommerce\Notifications;

use App\Notifications\BaseNotification;
use Modules\Sirsoft\Ecommerce\Mail\EcommerceNotificationMail;

class OrderConfirmedNotification extends BaseNotification
{
    public function __construct(
        private Order $order
    ) {}

    protected function getHookPrefix(): string
    {
        return 'sirsoft-ecommerce';
    }

    protected function getNotificationType(): string
    {
        return 'order_confirmed';
    }

    public function toMail(object $notifiable): EcommerceNotificationMail
    {
        return new EcommerceNotificationMail(
            type: 'order_confirmed',
            order: $this->order,
            recipient: $notifiable
        );
    }
}
```

### 2. Mailable 클래스는 모듈별 소유

각 모듈은 자체 Mailable 클래스를 만듭니다. 데이터 형태가 모듈마다 근본적으로 다르기 때문입니다.

| 모듈 | Mailable | 주요 프로퍼티 |
|------|----------|-------------|
| 코어 인증 | AuthNotificationMail | type, recipient, actionUrl, extraData |
| 게시판 | BoardNotificationMail | type, board, post, comment, recipient, commentAuthorName, actionType |
| 이커머스 | (직접 구현) | type, order, recipient, ... |

---

## 채널 확장 (플러그인)

### Filter 훅으로 채널 추가

```php
// plugins/sirsoft-site-notification/src/Listeners/SiteNotificationListener.php
class SiteNotificationListener implements HookListenerInterface
{
    public function getSubscribedHooks(): array
    {
        return [
            'core.auth.notification.channels' => [
                'method' => 'addDatabaseChannel',
                'type' => 'filter',  // type: 'filter' 필수!
            ],
        ];
    }

    public function addDatabaseChannel(array $channels, string $type, object $notifiable): array
    {
        $channels[] = 'database';
        return $channels;
    }
}
```

### 커스텀 채널 클래스 (FCM, 카카오톡 등)

```php
// via()에 FQCN 추가 → Laravel이 자동 resolve
$channels[] = \Plugins\Sirsoft\Push\Channels\FcmChannel::class;
```

커스텀 채널 클래스는 `send(object $notifiable, Notification $notification)` 메서드를 구현합니다.

---

## 테스트

### BaseNotificationTest

**위치**: `tests/Unit/Notifications/BaseNotificationTest.php`

| 테스트 | 검증 항목 |
|--------|----------|
| extends_laravel_notification | Notification 상속 확인 |
| via_returns_mail_channel_by_default | 기본 ['mail'] 반환 |
| hook_can_add_channels | Filter 훅으로 채널 추가 |
| hook_receives_notification_type | 훅에 type 전달 확인 |
| hook_receives_notifiable | 훅에 notifiable 전달 확인 |
| hook_name_format | 훅 이름 형식 확인 |
| board_module_hook_prefix | 모듈 훅 접두사 확인 |

### 기존 알림 테스트 (수정 없이 통과)

- `WelcomeNotificationTest` (6건)
- `ResetPasswordNotificationTest` (7건)
- `PasswordChangedNotificationTest` (6건)

---

## 금지 사항

```text
❌ BaseNotification 없이 Notification 직접 상속
❌ via()에서 HookManager 직접 호출 (BaseNotification이 처리)
❌ Mailable 추상화 (모듈별 데이터 형태가 다름)
❌ 훅 type: 'filter' 누락 (반환값 무시됨)
```
