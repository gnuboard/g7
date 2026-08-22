# 활동 로그 훅 레퍼런스 (Activity Log Hooks Reference)

> 모든 ActivityLog 리스너가 구독하는 훅의 전체 목록 (코어 + 모듈)

## TL;DR (5초 요약)

```text
1. 코어 66훅 + 확장 132훅 = 총 198훅 (확장별 목록은 그 확장이 소유)
2. Listener에서 Log::channel('activity')->info() 직접 호출 (Monolog → ActivityLogHandler → DB)
3. 스냅샷 패턴: before_update(priority 5) → 캡처, after_update → ChangeDetector로 비교
4. 사용자 행위: ActivityLogType::User (장바구니/위시리스트/쿠폰 다운로드/주문/결제)
5. 새 훅 추가 시: Listener에 구독 + lang 파일에 description_key 정의
```

---

## 목차

1. [아키텍처 개요](#1-아키텍처-개요)
2. [코어 훅 (CoreActivityLogListener)](#2-코어-훅-coreactivityloglistener)
3. [확장 모듈 훅](#3-확장-모듈-훅)
4. [스냅샷/변경감지 패턴](#4-스냅샷변경감지-패턴)
5. [새 모듈에 ActivityLog 추가하기](#5-새-모듈에-activitylog-추가하기)

---

## 1. 아키텍처 개요

```text
Service → doAction('hook.name') → ActivityLogListener → Log::channel('activity') → ActivityLogHandler → DB
```

- **Listener**: `HookListenerInterface` 구현, `getSubscribedHooks()`로 훅-메서드 매핑 정의
- **로그 기록**: `Log::channel('activity')->info($action, $context)` 직접 호출
- **context 구조**: `log_type`, `loggable`, `description_key`, `description_params`, `properties`, `changes`
- **LogType**: `ActivityLogType::Admin` (관리자 행위) / `ActivityLogType::User` (사용자 행위)
- **변경 감지**: `ChangeDetector::detect($model, $snapshot)` — 스냅샷과 현재 상태 비교

---

## 2. 코어 훅 (CoreActivityLogListener)

**파일**: `app/Listeners/CoreActivityLogListener.php`
**총 66훅**

### User (8훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.user.after_create` | `handleUserAfterCreate` | `user.create` | Admin | User |
| `core.user.after_update` | `handleUserAfterUpdate` | `user.update` | Admin | User |
| `core.user.after_delete` | `handleUserAfterDelete` | `user.delete` | Admin | User |
| `core.user.after_withdraw` | `handleUserAfterWithdraw` | `user.withdraw` | Admin | User |
| `core.user.after_show` | `handleUserAfterShow` | `user.show` | Admin | User |
| `core.user.after_list` | `handleUserAfterList` | `user.list` | Admin | - |
| `core.user.after_search` | `handleUserAfterSearch` | `user.search` | Admin | - |
| `sirsoft-core.user.after_bulk_update` | `handleUserAfterBulkUpdate` | `user.bulk_update` | Admin | - |

### Auth (9훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.auth.after_login` | `handleAuthAfterLogin` | `auth.login` | User | User |
| `core.auth.logout` | `handleAuthLogout` | `auth.logout` | User | User |
| `core.auth.register` | `handleAuthRegister` | `auth.register` | User | User |
| `core.auth.forgot_password` | `handleAuthForgotPassword` | `auth.forgot_password` | User | - |
| `core.auth.reset_password` | `handleAuthResetPassword` | `auth.reset_password` | User | - |
| `core.auth.record_consents` | `handleAuthRecordConsents` | `auth.record_consents` | User | User |
| `core.auth.login_failed` | `handleAuthLoginFailed` | `auth.login_failed` | User | - |
| `core.auth.account_locked` | `handleAuthAccountLocked` | `auth.account_locked` | User | User |
| `core.auth.account_unlocked` | `handleAuthAccountUnlocked` | `auth.account_unlocked` | User | User |

### Identity (3훅)

본인인증(IDV) 훅은 DTO(`VerificationChallenge`/`VerificationResult`)를 인자로 받으므로 `sync => true` 로 등록된다 — 큐 직렬화 대상에 POPO 가 포함되지 않아 큐로 넘기면 `null` 이 전달된다.

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.identity.after_request` | `handleIdentityRequested` | `identity.request` | User | - |
| `core.identity.after_verify` | `handleIdentityVerified` | `identity.verify` / `identity.verify_failed` | User | - |
| `core.identity.challenge_expired` | `handleIdentityExpired` | `identity.expired` | User | - |

### Role (5훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.role.after_create` | `handleRoleAfterCreate` | `role.create` | Admin | Role |
| `core.role.after_update` | `handleRoleAfterUpdate` | `role.update` | Admin | Role |
| `core.role.after_delete` | `handleRoleAfterDelete` | `role.delete` | Admin | Role |
| `core.role.after_sync_permissions` | `handleRoleAfterSyncPermissions` | `role.sync_permissions` | Admin | Role |
| `core.role.after_toggle_status` | `handleRoleAfterToggleStatus` | `role.toggle_status` | Admin | Role |

### Menu (6훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.menu.after_create` | `handleMenuAfterCreate` | `menu.create` | Admin | Menu |
| `core.menu.after_update` | `handleMenuAfterUpdate` | `menu.update` | Admin | Menu |
| `core.menu.after_delete` | `handleMenuAfterDelete` | `menu.delete` | Admin | Menu |
| `core.menu.after_update_order` | `handleMenuAfterUpdateOrder` | `menu.update_order` | Admin | - |
| `core.menu.after_toggle_status` | `handleMenuAfterToggleStatus` | `menu.toggle_status` | Admin | Menu |
| `core.menu.after_sync_roles` | `handleMenuAfterSyncRoles` | `menu.sync_roles` | Admin | Menu |

### Settings (2훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.settings.after_save` | `handleSettingsAfterSave` | `settings.save` | Admin | - |
| `core.settings.after_set` | `handleSettingsAfterSet` | `settings.set` | Admin | - |

### Schedule (6훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.schedule.after_create` | `handleScheduleAfterCreate` | `schedule.create` | Admin | Schedule |
| `core.schedule.after_update` | `handleScheduleAfterUpdate` | `schedule.update` | Admin | Schedule |
| `core.schedule.after_delete` | `handleScheduleAfterDelete` | `schedule.delete` | Admin | Schedule |
| `core.schedule.after_run` | `handleScheduleAfterRun` | `schedule.run` | Admin | Schedule |
| `core.schedule.after_bulk_update` | `handleScheduleAfterBulkUpdate` | `schedule.bulk_update` | Admin | - |
| `core.schedule.after_bulk_delete` | `handleScheduleAfterBulkDelete` | `schedule.bulk_delete` | Admin | - |

### Attachment (3훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.attachment.after_upload` | `handleAttachmentAfterUpload` | `attachment.upload` | Admin | Attachment |
| `core.attachment.after_delete` | `handleAttachmentAfterDelete` | `attachment.delete` | Admin | Attachment |
| `core.attachment.after_bulk_delete` | `handleAttachmentAfterBulkDelete` | `attachment.bulk_delete` | Admin | - |

### Module (6훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.modules.after_install` | `handleModuleAfterInstall` | `module.install` | Admin | - |
| `core.modules.after_activate` | `handleModuleAfterActivate` | `module.activate` | Admin | - |
| `core.modules.after_deactivate` | `handleModuleAfterDeactivate` | `module.deactivate` | Admin | - |
| `core.modules.after_uninstall` | `handleModuleAfterUninstall` | `module.uninstall` | Admin | - |
| `core.modules.after_update` | `handleModuleAfterUpdate` | `module.update` | Admin | - |
| `core.modules.after_refresh_layouts` | `handleModuleAfterRefreshLayouts` | `module.refresh_layouts` | Admin | - |

### Plugin (5훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.plugins.after_install` | `handlePluginAfterInstall` | `plugin.install` | Admin | - |
| `core.plugins.after_activate` | `handlePluginAfterActivate` | `plugin.activate` | Admin | - |
| `core.plugins.after_deactivate` | `handlePluginAfterDeactivate` | `plugin.deactivate` | Admin | - |
| `core.plugins.after_uninstall` | `handlePluginAfterUninstall` | `plugin.uninstall` | Admin | - |
| `core.plugins.after_update` | `handlePluginAfterUpdate` | `plugin.update` | Admin | - |

### Template (6훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.templates.after_install` | `handleTemplateAfterInstall` | `template.install` | Admin | - |
| `core.templates.after_activate` | `handleTemplateAfterActivate` | `template.activate` | Admin | - |
| `core.templates.after_deactivate` | `handleTemplateAfterDeactivate` | `template.deactivate` | Admin | - |
| `core.templates.after_uninstall` | `handleTemplateAfterUninstall` | `template.uninstall` | Admin | - |
| `core.templates.after_version_update` | `handleTemplateAfterVersionUpdate` | `template.version_update` | Admin | - |
| `core.templates.after_refresh_layouts` | `handleTemplateAfterRefreshLayouts` | `template.refresh_layouts` | Admin | - |

### Extension 공통 (1훅)

템플릿·모듈·플러그인 세 타입이 같은 훅을 공유한다 (권한도 `core.extensions.custom_assets.manage` 하나다).

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.custom_assets.after_change` | `handleCustomAssetAfterChange` | `custom_asset.{save\|upload\|delete}` | Admin | 운영자 CSS·JS 는 사이트 전 화면에서 실행되므로 변경 이력이 남아야 한다 |

### Layout (2훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.layout.after_update` | `handleLayoutAfterUpdate` | `layout.update` | Admin | - |
| `core.layout.after_version_restore` | `handleLayoutAfterVersionRestore` | `layout.version_restore` | Admin | - |

### Module Settings (2훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.module_settings.after_save` | `handleModuleSettingsAfterSave` | `module_settings.save` | Admin | - |
| `core.module_settings.after_reset` | `handleModuleSettingsAfterReset` | `module_settings.reset` | Admin | - |

### Plugin Settings (2훅)

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `core.plugin_settings.after_save` | `handlePluginSettingsAfterSave` | `plugin_settings.save` | Admin | - |
| `core.plugin_settings.after_reset` | `handlePluginSettingsAfterReset` | `plugin_settings.reset` | Admin | - |

---

## 3. 확장 모듈 훅

확장이 구독하는 활동 로그 훅 목록은 **그 확장이 소유**합니다(#601). 확장이 훅을 추가할 때
코어 문서를 고쳐야 하는 역방향 의존을 없애기 위해서이며, 코어에는 아래 총계와 링크만 남습니다.

| 확장 | 훅 수 | 문서 |
|------|------|------|
| `sirsoft-ecommerce` | 92 | [docs/extension-points.md](../../modules/_bundled/sirsoft-ecommerce/docs/extension-points.md) |
| `sirsoft-board` | 30 | [docs/extension-points.md](../../modules/_bundled/sirsoft-board/docs/extension-points.md) |
| `sirsoft-page` | 7 | [docs/extension-points.md](../../modules/_bundled/sirsoft-page/docs/extension-points.md) |
| `sirsoft-pay_kginicis` | 1 | [docs/extension-points.md](../../plugins/_bundled/sirsoft-pay_kginicis/docs/extension-points.md) |
| `sirsoft-pay_nhnkcp` | 1 | [docs/extension-points.md](../../plugins/_bundled/sirsoft-pay_nhnkcp/docs/extension-points.md) |
| `sirsoft-pay_nicepayments` | 1 | [docs/extension-points.md](../../plugins/_bundled/sirsoft-pay_nicepayments/docs/extension-points.md) |
| **합계** | **132** | |

코어 66훅 + 확장 132훅 = **총 198훅**입니다.

확장에 새 활동 로그 항목을 추가할 때도 **다국어 키는 코어가 SSoT** 입니다 — action 라벨과
description 본문을 코어 `lang/{ko,en}/activity_log.php` 에 정의해야 하며, 모듈 lang 파일에
넣으면 해석되지 않습니다. 번들 일본어 팩도 같은 작업 단위에서 동기화합니다.

---

## 4. 스냅샷/변경감지 패턴

ActivityLog에서 수정(update) 작업의 변경 이력을 기록하려면 **스냅샷 패턴**을 사용합니다.

### 동작 흐름

```text
1. Service에서 before_update 훅 발행 (doAction)
2. Listener의 캡처 메서드 실행 (priority: 5, 다른 리스너보다 먼저)
   → 현재 모델 상태를 $snapshots 배열에 저장
3. Service에서 실제 DB 업데이트 수행
4. Service에서 after_update 훅 발행 (doAction)
5. Listener의 핸들러 메서드 실행 (priority: 20)
   → ChangeDetector::detect($model, $snapshot) 호출
   → 변경된 필드만 추출하여 로그 기록
   → 스냅샷 정리 (unset)
```

### 코드 예시

```php
// 1. getSubscribedHooks()에서 before/after 쌍 등록
public static function getSubscribedHooks(): array
{
    return [
        'my-module.entity.before_update' => ['method' => 'captureSnapshot', 'priority' => 5],
        'my-module.entity.after_update' => ['method' => 'handleAfterUpdate', 'priority' => 20],
    ];
}

// 2. 스냅샷 캡처 (before_update, priority 5)
public function captureSnapshot(Model $entity, array $data): void
{
    $this->snapshots['entity_' . $entity->id] = $entity->toArray();
}

// 3. 변경 감지 + 로그 기록 (after_update, priority 20)
public function handleAfterUpdate(Model $entity): void
{
    $snapshot = $this->snapshots['entity_' . $entity->id] ?? null;
    $changes = ChangeDetector::detect($entity, $snapshot);

    Log::channel('activity')->info('entity.update', [
        'log_type' => ActivityLogType::Admin,
        'loggable' => $entity,
        'description_key' => 'my-module::activity_log.description.entity_update',
        'description_params' => ['entity_id' => $entity->id],
        'changes' => $changes,
    ]);

    unset($this->snapshots['entity_' . $entity->id]);
}
```

### ChangeDetector

**파일**: `app/ActivityLog/ChangeDetector.php`

- 모델의 `$activityLogFields` 속성에 정의된 필드만 비교
- 각 필드의 `label_key` (다국어 키)를 포함한 구조화된 변경 이력 생성
- `BackedEnum` 자동 변환 지원
- 스냅샷이 `null`이면 `null` 반환 (변경 없음)

### 스냅샷은 Service 가 잡아 넘긴다

리스너는 `before_*` 훅을 구독해 스냅샷을 잡지 않습니다. **Service 가 수정 직전에 스냅샷을
만들어 `after_*` 훅의 인자로 넘기고**, 리스너는 그것을 `ChangeDetector::detect()` 에 그대로
전달합니다:

```php
// Service (예: ProductService::update())
HookManager::doAction('sirsoft-ecommerce.product.after_update', $product, $snapshot);

// Listener
public function handleProductAfterUpdate(Product $product, ?array $snapshot = null): void
{
    $changes = ChangeDetector::detect($product, $snapshot);
    // ...
}
```

`before_*` 훅 자체는 발행되므로 다른 확장이 구독할 수 있습니다 — 다만 **활동 로그 리스너의
구독 대상은 아닙니다.** 확장별 구독 목록에서 `before_*` 가 보이지 않는 것은 누락이 아니라
이 구조 때문입니다.

---

## 5. 새 모듈에 ActivityLog 추가하기

### Step 1: Listener 클래스 생성

```php
<?php

namespace Modules\Vendor\MyModule\Listeners;

use App\ActivityLog\ChangeDetector;
use App\Contracts\Extension\HookListenerInterface;
use App\Enums\ActivityLogType;
use Illuminate\Support\Facades\Log;

class MyModuleActivityLogListener implements HookListenerInterface
{
    private array $snapshots = [];

    public static function getSubscribedHooks(): array
    {
        return [
            'vendor-mymodule.entity.after_create' => ['method' => 'handleAfterCreate', 'priority' => 20],
            'vendor-mymodule.entity.before_update' => ['method' => 'captureSnapshot', 'priority' => 5],
            'vendor-mymodule.entity.after_update' => ['method' => 'handleAfterUpdate', 'priority' => 20],
            'vendor-mymodule.entity.after_delete' => ['method' => 'handleAfterDelete', 'priority' => 20],
        ];
    }

    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    // ... 각 핸들러 메서드 구현
}
```

### Step 2: module.php에 Listener 등록

```php
// module.php의 listeners 배열에 추가
'listeners' => [
    MyModuleActivityLogListener::class,
],
```

### Step 3: 다국어 파일에 description_key 정의

```php
// lang/ko/activity_log.php
return [
    'description' => [
        'entity_create' => ':entity_name 엔티티가 생성되었습니다.',
        'entity_update' => ':entity_name 엔티티가 수정되었습니다.',
        'entity_delete' => ':entity_name 엔티티가 삭제되었습니다.',
    ],
];
```

### Step 4: 모델에 $activityLogFields 정의 (ChangeDetector 사용 시)

```php
// Model 클래스에 추가
protected array $activityLogFields = [
    'name' => ['label_key' => 'vendor-mymodule::activity_log.fields.name'],
    'status' => ['label_key' => 'vendor-mymodule::activity_log.fields.status'],
];
```

### Step 5: 테스트 작성

```php
// tests/Unit/Listeners/MyModuleActivityLogListenerTest.php
class MyModuleActivityLogListenerTest extends TestCase
{
    public function test_getSubscribedHooks_returns_expected_hooks(): void
    {
        $hooks = MyModuleActivityLogListener::getSubscribedHooks();

        $this->assertArrayHasKey('vendor-mymodule.entity.after_create', $hooks);
        $this->assertArrayHasKey('vendor-mymodule.entity.before_update', $hooks);
        // ...
    }

    public function test_handleAfterCreate_logs_activity(): void
    {
        Log::shouldReceive('channel')
            ->with('activity')
            ->andReturnSelf();
        Log::shouldReceive('info')
            ->once()
            ->with('entity.create', Mockery::type('array'));

        $listener = new MyModuleActivityLogListener();
        $listener->handleAfterCreate($entity, $data);
    }
}
```

### 훅 네이밍 규칙

| 패턴 | 용도 | Priority |
|------|------|----------|
| `{module}.{entity}.before_update` | 스냅샷 캡처 | 5 (높은 우선순위) |
| `{module}.{entity}.after_create` | 생성 로그 | 20 |
| `{module}.{entity}.after_update` | 수정 로그 (변경감지) | 20 |
| `{module}.{entity}.after_delete` | 삭제 로그 | 20 |
| `{module}.{entity}.after_bulk_*` | 일괄 작업 로그 | 20 |
| `{module}.{entity}.after_toggle_*` | 상태 전환 로그 | 20 |

### context 구조

| 키 | 타입 | 설명 | 필수 |
|----|------|------|------|
| `log_type` | `ActivityLogType` | Admin 또는 User | O |
| `loggable` | `Model` | 대상 모델 (polymorphic) | - |
| `description_key` | `string` | 다국어 설명 키 | O |
| `description_params` | `array` | 설명 파라미터 | - |
| `properties` | `array` | 추가 속성 (JSON) | - |
| `changes` | `array\|null` | ChangeDetector 결과 | - |
