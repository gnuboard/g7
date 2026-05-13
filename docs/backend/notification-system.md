# 알림 시스템 (Notification System)

> 그누보드7 알림 시스템의 아키텍처와 확장 가이드

## TL;DR (5초 요약)

```text
1. GenericNotification 범용 클래스 1개로 모든 알림 처리 (개별 클래스 불필요)
2. notification_definitions 테이블 = 알림 타입 SSoT (채널, 훅, 변수 정의)
3. notification_templates 테이블 = 채널별 독립 템플릿 + 수신자 (mail/database/fcm)
4. 훅 기반 트리거: NotificationHookListener가 template별 독립 발송
5. 수신자 설정: template.recipients JSON으로 채널별 독립 수신자 (4종 타입)
6. 채널 확장: Filter 훅 `{hookPrefix}.notification.channels`로 채널 추가/제거
```

> **본인인증(IDV) 메시지는 별도 시스템**: 본 알림 시스템과 완전히 분리된 IDV 전용 인프라가 [identity-messages.md](identity-messages.md) 에 있습니다. IDV는 `notification_*` 테이블 / `GenericNotification` 을 사용하지 않고, 자체 `identity_message_definitions` / `identity_message_templates` + `IdentityMessageDispatcher` 를 사용합니다.

---

## 아키텍처 개요

### Before vs After

```text
Before (기존):
  17개 개별 Notification 클래스 → 각각 toMail() 하드코딩 → mail 채널만
  mail_templates 테이블 → mail 전용 템플릿
  BoardNotificationChannelListener → Board만 채널 설정 가능

After (현재):
  GenericNotification 1개 → DB 설정 기반 → 다채널 동시 발송
  notification_definitions 테이블 → 알림 타입 정의 (채널, 훅, 변수)
  notification_templates 테이블 → 채널별 독립 템플릿
  코어 채널 관리 API → 전체 모듈 공용
```

### 핵심 테이블

**notification_definitions** — 알림 타입 정의 (SSoT)

| 컬럼 | 설명 |
|------|------|
| type | 알림 타입 (unique): welcome, order_confirmed 등 |
| hook_prefix | 훅 접두사: core.auth, sirsoft-ecommerce 등 |
| extension_type | 확장 타입: core, module, plugin |
| extension_identifier | 확장 식별자 |
| name | 다국어 이름 (JSON) |
| variables | 사용 가능 변수 메타데이터 (JSON) |
| channels | 활성 채널 배열 (JSON): ["mail", "database"] |
| hooks | 트리거 훅 목록 (JSON): ["core.auth.after_register"] |
| is_active | 활성 여부 |

**notification_templates** — 채널별 템플릿

| 컬럼 | 설명 |
|------|------|
| definition_id | 알림 정의 FK |
| channel | 채널: mail, database, fcm |
| subject | 다국어 제목 (JSON) |
| body | 다국어 본문 (JSON) |
| is_active | 해당 채널 활성 여부 |
| user_overrides | 사용자 수정 필드 목록 |
| unique | (definition_id, channel) |

### GenericNotification 클래스

**위치**: `app/Notifications/GenericNotification.php`

```php
$user->notify(new GenericNotification(
    type: 'welcome',
    hookPrefix: 'core.auth',
    data: ['name' => '홍길동', 'app_name' => config('app.name'), ...],
    extensionType: 'core',
    extensionIdentifier: 'core',
));
```

**3계층 구조**:

| 메서드 | 채널 | 동작 |
|--------|------|------|
| `via()` | - | notification_definitions.channels 조회 + Filter 훅 적용 |
| `toMail()` | mail | notification_templates(mail) 조회 → DbTemplateMail 생성 |
| `toArray()` | database | notification_templates(database) 조회 → 변수 치환 |
| `__call()` | 기타 | Filter 훅으로 위임 (fcm 등 미래 채널) |

### 발송 흐름

```text
1. Listener에서 GenericNotification 생성 + $user->notify() 호출
2. via() → notification_definitions에서 채널 조회 → Filter 훅 적용
   → NotificationChannelService 필터 (확장 단위 채널 토글 OFF 채널 제외) ← 0단계
   → ChannelReadinessService 필터 (미설정 채널 제외)
   → NotificationTemplateService 필터 (활성 템플릿 없는 채널 제외)
   → skipped 채널 → notification_logs에 사유 기록
3. toMail() → notification_templates(mail) 조회 → replaceVariables() → DbTemplateMail
4. toArray() → notification_templates(database) 조회 → replaceVariables() → 배열
```

> **템플릿 없는 채널 자동 제외**: 채널이 활성(channels 배열 포함)이고 readiness도 통과해도, 해당 채널에 활성 템플릿이 없으면 via()에서 제외됩니다. 이로써 빈 subject/body가 DB에 저장되는 것을 방지합니다.

### 확장 단위 채널 전역 토글 (channel_disabled_by_extension)

코어/모듈/플러그인 환경설정 > "알림 채널 관리"의 토글이 OFF인 채널은 발송 경로 0단계에서 차단됩니다 — 활성 템플릿 존재 여부와 무관하게 제외되며 `notification_logs`에 `channel_disabled_by_extension` 사유로 skipped 기록됩니다.

**저장 위치** (확장별 독립):

| 확장 타입 | 저장소 | 키 |
|----------|--------|-----|
| 코어 | `settings` 테이블 (`SettingsService`) | `notifications.channels` |
| 모듈 | 모듈 settings (`ModuleSettingsService`) | `notifications.channels` |
| 플러그인 | 플러그인 settings (`PluginSettingsService`) | `notifications.channels` |

**스키마**: `[{id: string, is_active: boolean, sort_order?: number}]`

**조회 API**: `NotificationChannelService::isChannelEnabledForExtension($extensionType, $extensionIdentifier, $channelId)` — 단일 진입점. 엔트리가 없는 채널은 기본 `true`(활성) 반환 — 하위호환 + 플러그인이 추가한 신규 채널 기본 활성 보장.

**훅 필터**: `core.notification.channel_enabled` — 시그니처 `(bool $enabled, string $extensionType, ?string $extensionIdentifier, string $channelId)`. 플러그인이 동적으로 재정의 가능.

**메모이제이션**: 같은 요청 내 동일 조합 반복 조회는 in-memory 캐시. `clearChannelEnabledCache()`로 초기화.

### 언어팩 다국어 보강

`notification_definitions` 는 **다국어 데이터 직접 보유 SSoT** (config/core.php 의 각 entry 가 `name`/`description`/`templates.subject`/`templates.body` 의 ko/en 배열) — lang pack seed 대상.

흐름:

```text
NotificationDefinitionSeeder 실행 (코어)
    ↓
config('core.notification_definitions') 로드
    ↓
applyFilters('seed.notifications.translations', $definitions)
    ↓
LanguagePackSeedInjector::injectNotifications($definitions)
    ↓ 활성 코어 ja 언어팩의 seed/notifications.json 로드
    ↓ 각 entry 에 ja 키 병합 (이미 존재하는 ko/en 보존)
    ↓
NotificationSyncHelper::syncDefinition() — DB upsert
    ↑ user_overrides 마킹된 필드는 운영자 수정값 보존
```

모듈/플러그인 측: `ModuleManager::syncModuleNotificationDefinitions` / `PluginManager::syncPluginNotificationDefinitions` 가 동일 패턴 (`seed.{id}.notifications.translations` 필터 발화).

신규 lang pack 추가 시 운영자가 수정하지 않은 키만 자동 보강. 운영자 편집값은 user_overrides 로 보존되어 시더 재실행/lang pack 재설치에도 덮어써지지 않음.

---

## 훅 기반 트리거

### NotificationHookListener

**위치**: `app/Listeners/NotificationHookListener.php`

notification_definitions의 hooks 필드에 정의된 훅을 동적으로 구독합니다.

```text
notification_definitions 레코드:
  type: "order_confirmed"
  hooks: ["sirsoft-ecommerce.order.after_confirm"]

→ NotificationHookListener가 부팅 시 모든 정의된 훅을 구독
→ 훅 발화 시 → definition 조회 → GenericNotification 생성 → 발송
```

**데이터 추출**: `{hookPrefix}.notification.extract_data` Filter 훅으로 수신자와 데이터를 추출합니다.

### 큐 워커에서의 사용자/로케일 컨텍스트

알림 발송 리스너는 기본적으로 큐로 디스패치되며, 큐 워커는 별도 프로세스라 `Auth::user()`/`App::getLocale()`이 모두 리셋됩니다. 그러나 G7은 디스패치 시점의 컨텍스트를 자동 복원하므로 다음이 보장됩니다:

- 발송 시점의 사용자 로케일이 자동 복원되어 다국어 메시지(메일 본문, 푸시 텍스트 등)가 원래 요청 언어로 정확히 발송
- 알림 발송 로그(`NotificationLogListener`)의 행위자가 실제 트리거한 사용자로 정상 기록

리스너 코드는 변경 불필요 — 평소처럼 `Auth::user()`, `__('...')` 호출하면 됩니다.

> 자세한 동작은 [extension/hooks.md "사용자 컨텍스트 자동 복원"](../extension/hooks.md) 참조

---

## 수신자 설정 (Recipients)

### 개요

`notification_templates.recipients` JSON 컬럼으로 **채널별 독립 수신자**를 DB에서 설정합니다.
동일한 알림 정의라도 메일과 사이트내 알림에 서로 다른 수신자를 지정할 수 있습니다.

### 수신자 타입

| type | 설명 | value | 예시 |
|------|------|-------|------|
| `trigger_user` | 이벤트 유발자 | - | 주문자, 가입자 |
| `related_user` | 관련 사용자 | relation 키 | 문의 작성자 |
| `role` | 역할 기반 | role identifier | admin, manager |
| `specific_users` | 특정 사용자 | user UUID 배열 | ["uuid1", "uuid2"] |

### JSON 구조

```json
[
  {"type": "trigger_user"},
  {"type": "role", "value": "admin", "exclude_trigger_user": true},
  {"type": "related_user", "relation": "author"},
  {"type": "specific_users", "value": ["uuid1", "uuid2"]}
]
```

### 발송 흐름

`NotificationHookListener.dispatch()`에서 채널별 독립 발송:

1. `extract_data` 필터로 data/context 추출
2. definition의 **활성 templates를 순회**
3. 각 template의 `recipients`로 수신자 결정 → `NotificationRecipientResolver`
4. recipients 미설정 시 extract_data의 notifiables로 fallback (레거시 호환)
5. 채널별 독립 `GenericNotification(channel: template.channel)` 발송

### NotificationRecipientResolver

**위치**: `app/Services/NotificationRecipientResolver.php`

recipients 규칙 배열을 해석하여 `Collection<User>`를 반환합니다.

```php
// 시그니처: 순수 규칙 배열 + 컨텍스트
$resolver->resolve(array $rules, array $context): Collection
```

- `exclude_trigger_user: true` 규칙이 있으면 최종 수신자에서 이벤트 유발자를 제외
- 동일 사용자가 여러 규칙에 중복되면 자동 중복 제거
- role 타입에서 역할 사용자가 없으면 superAdmin으로 폴백

### extract_data 필터

모듈/플러그인은 `{hookPrefix}.notification.extract_data` 필터를 구현하여 알림 데이터와 컨텍스트를 제공합니다:

```php
// 반환 형태
return [
    'notifiable' => null,       // 단일 수신자 (레거시 fallback)
    'notifiables' => null,      // 수신자 배열 (레거시 fallback)
    'data' => [...],            // 알림 변수 데이터
    'context' => [              // 수신자 결정용 컨텍스트
        'trigger_user_id' => $userId,
        'trigger_user' => $user,
        'related_users' => ['author' => $author],
    ],
];
```

### {recipient_name} 플레이스홀더

복수 수신자 알림에서 `data.name`에 `{recipient_name}`을 설정하면, 발송 시 각 수신자의 이름으로 자동 치환됩니다.

---

## 알림 정의 관리

### 코어 3종

| type | hooks | 채널 |
|------|-------|------|
| welcome | core.auth.after_register | mail, database |
| reset_password | core.auth.after_reset_password_request | mail, database |
| password_changed | core.auth.after_password_changed | mail, database |

### Board 7종

| type | hooks |
|------|-------|
| new_comment | sirsoft-board.comment.after_create |
| reply_comment | sirsoft-board.comment.after_create |
| post_reply | sirsoft-board.post.after_create |
| post_action | sirsoft-board.post.after_blind/delete/restore |
| new_post_admin | sirsoft-board.post.after_create |
| report_received_admin | sirsoft-board.report.after_create |
| report_action | sirsoft-board.report.after_action |

### 이커머스 7종

| type | hooks | 채널 |
|------|-------|------|
| order_confirmed | sirsoft-ecommerce.order.after_confirm | mail, database |
| order_shipped | sirsoft-ecommerce.order.after_ship | mail, database |
| order_completed | sirsoft-ecommerce.order.after_complete | mail, database |
| order_cancelled | sirsoft-ecommerce.order.after_cancel | mail, database |
| new_order_admin | sirsoft-ecommerce.order.after_create | mail, database |
| inquiry_received | sirsoft-ecommerce.product_inquiry.after_create | mail, database |
| inquiry_replied | sirsoft-ecommerce.product_inquiry.after_reply | mail, database |

**발송 리스너**: `EcommerceNotificationListener` (방식 A — 직접 호출)

게시판과 동일하게 각 훅 메서드에서 수신자를 결정하고 `$user->notify(new GenericNotification(...))` 직접 호출합니다.
관리자 수신자는 `admin` Role 기반 조회 + superAdmin 폴백 패턴을 사용합니다.

---

## 알림 정의 선언 — Declarative SSoT 패턴 (7.0.0-beta.4+)

알림 정의는 **선언적 SSoT 패턴**으로 통일되었습니다 — 권한·메뉴·본인인증과 동일한 구조:

| 영역 | SSoT 위치 | 동기화 트리거 |
|------|----------|--------------|
| **코어** | `config/core.php` 의 `notification_definitions` 블록 | `NotificationDefinitionSeeder` (fresh install / migrate) |
| **모듈** | `module.php::getNotificationDefinitions()` | `ModuleManager` 가 activate / update --force / uninstall(deleteData=true) 시 자동 |
| **플러그인** | `plugin.php::getNotificationDefinitions()` | `PluginManager` 가 동일 시점에 자동 |

### 모듈/플러그인 getter

`AbstractModule::getNotificationDefinitions(): array` 를 오버라이드하여 알림 정의를 선언합니다. `extension_type`/`extension_identifier` 는 Manager 가 자동 주입하므로 반환 배열에 포함하지 않습니다.

```php
public function getNotificationDefinitions(): array
{
    return [
        [
            'type' => 'order_confirmed',
            'hook_prefix' => 'sirsoft-ecommerce',
            'name' => ['ko' => '주문 확인', 'en' => 'Order Confirmed'],
            'description' => ['ko' => '...', 'en' => '...'],
            'channels' => ['mail', 'database'],
            'hooks' => ['sirsoft-ecommerce.order.after_confirm'],
            'variables' => [['key' => 'order_number', 'description' => '주문번호']],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '...', 'en' => '...'],
                    'body' => ['ko' => '...', 'en' => '...'],
                ],
            ],
        ],
    ];
}
```

### 코어 config 블록

`config/core.php`:

```php
'notification_definitions' => [
    'welcome' => [
        'hook_prefix' => 'core.auth',
        'name' => ['ko' => '회원가입 환영', 'en' => 'Welcome'],
        'description' => [...],
        'channels' => ['mail', 'database'],
        'hooks' => ['core.auth.after_register'],
        'variables' => [...],
        'templates' => [...],
    ],
    // ...
],
```

배열 키(`'welcome'`)가 `type` 으로 매핑되며, `extension_type='core'` / `extension_identifier='core'` 가 자동 주입됩니다.

### 자동 동기화 동작

| 시점 | 동작 |
|------|------|
| 모듈/플러그인 install | getter 결과를 helper 로 upsert + cleanup stale |
| 모듈/플러그인 update --force | 동일 (운영자 user_overrides 보존) |
| 모듈/플러그인 uninstall(deleteData=true) | `extension_type`/`extension_identifier` 매칭 정의 전부 정리 (FK cascade 로 템플릿 자동 정리) |
| 모듈/플러그인 uninstall(deleteData=false) | 보존 (재설치 시 user_overrides 복원) |

### 금지 사항

- 모듈/플러그인 측에 별도 `*NotificationDefinitionSeeder.php` 파일을 두면 안 됩니다. 동일 데이터를 두 곳에서 유지하면 SSoT 가 깨지며, audit 룰 `notification-seeder-orphan` 이 차단합니다.
- 코어는 `database/seeders/NotificationDefinitionSeeder.php` 가 fresh install 진입점으로 유지되지만, 데이터 자체는 `config/core.php` 가 SSoT 입니다 (시더는 config 를 읽기만 함).

---

## 알림 정의 동기화 (NotificationSyncHelper)

업그레이드/시더 재실행 시 알림 정의(Definition) 와 템플릿(Template) 의 정합성을 **완전 동기화** 패턴으로 유지합니다. 로직은 `NotificationSyncHelper` 에 집중되어 있고 Seeder 는 얇은 진입점입니다.

### Helper 메서드

**파일**: `app/Extension/Helpers/NotificationSyncHelper.php`

```php
public function syncDefinition(array $data): NotificationDefinition;
public function syncTemplate(int $definitionId, array $data): NotificationTemplate;
public function cleanupStaleDefinitions(string $extensionType, string $extensionIdentifier, array $currentTypes): int;
public function cleanupStaleTemplates(int $definitionId, array $currentChannels): int;
```

### 동기화 호출 패턴

코어 시더 / Manager 모두 동일한 패턴으로 helper 를 호출합니다 — SSoT 위치(config 또는 module getter)만 다릅니다.

```php
// 예: ModuleManager::syncModuleNotificationDefinitions() 와 동일한 흐름
$helper = app(\App\Extension\Helpers\NotificationSyncHelper::class);
$definedTypes = [];

foreach ($module->getNotificationDefinitions() as $data) {
    $data['extension_type'] = 'module';
    $data['extension_identifier'] = $module->getIdentifier();

    $definition = $helper->syncDefinition($data);
    $definedTypes[] = $definition->type;

    $definedChannels = [];
    foreach ($data['templates'] ?? [] as $template) {
        $helper->syncTemplate($definition->id, $template);
        $definedChannels[] = $template['channel'];
    }
    // 정의 유지 + 채널 제거 시 stale 삭제
    $helper->cleanupStaleTemplates($definition->id, $definedChannels);
}

// SSoT 에서 제거된 definition 삭제 (FK cascade 로 template 도 자동 정리)
$helper->cleanupStaleDefinitions('module', $module->getIdentifier(), $definedTypes);
```

### 동작 보장

| 상황 | 동작 |
|---|---|
| 정의 신규 추가 | 생성 |
| 정의 유지 + 사용자가 `name`/`is_active` 수정 | user_overrides 에 등록된 필드 보존, 나머지 갱신 |
| 정의 제거 (seeder 에 없음) | 삭제 + FK cascade 로 연결된 모든 template 자동 정리 |
| 템플릿 채널 재구성 | 제거된 채널의 template 만 삭제, 유지된 채널은 user_overrides 보존 |

### 호출처

- **코어**: `database/seeders/NotificationDefinitionSeeder.php` (fresh install 진입점, `config/core.php` 에서 데이터 로드)
- **모듈**: `ModuleManager::syncModuleNotificationDefinitions()` (activate / update --force / uninstall 자동 호출)
- **플러그인**: `PluginManager::syncPluginNotificationDefinitions()` (동일)
- **확장 측 별도 Seeder 는 작성 금지** — `module.php::getNotificationDefinitions()` / `plugin.php::getNotificationDefinitions()` 만 정의

### 참고

- [data-sync-helpers.md](data-sync-helpers.md) — Helper 5종 사용 가이드
- [user-overrides.md](user-overrides.md) — 사용자 수정 보존

---

## 서비스 계층

| 서비스 | 역할 |
|--------|------|
| `NotificationDefinitionService` | 정의 조회(캐싱), 수정, 토글, 캐시 무효화 |
| `NotificationTemplateService` | 채널별 템플릿 조회(캐싱), 수정, 미리보기, 복원 |

### 캐시 전략

- 정의: `notification_definition:{type}` — 1시간
- 템플릿: `notification_template:{type}:{channel}` — 1시간
- 수정/토글 시 자동 무효화

---

## API 엔드포인트

### 사용자 알림 API

| 메서드 | URL | 권한 | 설명 |
|--------|-----|------|------|
| GET | /api/user/notifications | `core.user-notifications.read` (user) | 알림 목록 (페이지네이션) |
| GET | /api/user/notifications/unread-count | `core.user-notifications.read` (user) | 미읽음 카운트 |
| PATCH | /api/user/notifications/{id}/read | `core.user-notifications.update` (user) | 개별 읽음 처리 |
| POST | /api/user/notifications/read-batch | `core.user-notifications.update` (user) | 배치 읽음 (ID 배열) |
| POST | /api/user/notifications/read-all | `core.user-notifications.update` (user) | 전체 읽음 |
| DELETE | /api/user/notifications/all | `core.user-notifications.delete` (user) | 전체 삭제 |
| DELETE | /api/user/notifications/{id} | `core.user-notifications.delete` (user) | 개별 삭제 |

> 사용자 알림 라우트는 `permission:user,...` 미들웨어 + `core.user-notifications.*` (`type=user`) 권한을 사용합니다. 관리자용 `core.notifications.*` (`type=admin`)와는 별도 식별자입니다. 상세: [permissions.md](../extension/permissions.md#코어-권한에서-타입-지정-configcorephp)

#### 응답 필드 (UserNotificationResource)

| 필드 | 타입 | 설명 |
|------|------|------|
| id | string | 알림 UUID |
| type | string | 알림 타입 식별자 (예: `welcome`, `order_confirmed`) |
| type_label | string | 다국어 라벨 (`NotificationDefinition.name`에서 사용자 로케일 기준 해석, 정의 없으면 빈 문자열) |
| subject | string\|null | 제목 (database 채널 템플릿 기반) |
| body | string\|null | 본문 (database 채널 템플릿 기반) |
| data | object | 알림 데이터 (변수 등 원본) |
| read_at | string\|null | 읽음 시각 (사용자 타임존, `Y-m-d H:i:s`) |
| created_at | string | 생성 시각 (사용자 타임존, `Y-m-d H:i:s`) |

> `type_label`은 `UserNotificationCollection`에서 `NotificationDefinitionRepository::getLabelMap($locale)`을 한 번만 호출하여 N+1을 회피합니다. 새 알림 타입 추가는 시더만 갱신하면 자동 반영됩니다.

### 관리자 알림 API (Admin)

| 메서드 | URL | 설명 |
|--------|-----|------|
| GET | /api/admin/notifications | 관리자 본인 알림 목록 |
| GET | /api/admin/notifications/unread-count | 미읽음 카운트 |
| PATCH | /api/admin/notifications/{id}/read | 개별 읽음 |
| POST | /api/admin/notifications/read-batch | 배치 읽음 |
| POST | /api/admin/notifications/read-all | 전체 읽음 |
| DELETE | /api/admin/notifications/all | 전체 삭제 |
| DELETE | /api/admin/notifications/{id} | 개별 삭제 |

> 관리자 알림 API는 `permission:admin,core.notifications.*` 미들웨어를 사용합니다. 사용자 API와 동일한 `UserNotificationResource`/`UserNotificationCollection`을 공유합니다.

### 알림 정의/템플릿 관리 API (Admin)

| 메서드 | URL | 설명 |
|--------|-----|------|
| GET | /api/admin/notification-definitions | 알림 정의 목록 |
| GET | /api/admin/notification-definitions/{id} | 알림 정의 상세 (templates 포함) |
| PUT | /api/admin/notification-definitions/{id} | 채널/훅 수정 |
| PATCH | /api/admin/notification-definitions/{id}/toggle-active | 활성 토글 |
| POST | /api/admin/notification-definitions/{id}/reset | **정의 단위 일괄 기본값 복원** (소속 모든 채널 템플릿 리셋) |
| PUT | /api/admin/notification-templates/{id} | 템플릿 수정 |
| PATCH | /api/admin/notification-templates/{id}/toggle-active | 템플릿 활성 토글 |
| POST | /api/admin/notification-templates/preview | 미리보기 |
| POST | /api/admin/notification-templates/{id}/reset | 단일 템플릿 기본값 복원 |

**리셋 동작**:
- 템플릿 편집 시 `Template.is_default = false` + `Definition.is_default = false` 자동 전환
- 정의 리셋 시 모든 소속 템플릿을 `is_default = true`로 복원 + `Definition.is_default = true` 복구
- 복원 대상 기본값은 코어는 `config('core.notification_definitions')`, 확장은 `module.php::getNotificationDefinitions()` / `plugin.php::getNotificationDefinitions()` 에서 조회 (filter 훅 통합)

### 모듈/플러그인 기본 정의 기여 (Filter 훅)

코어 리셋 로직이 확장의 시더를 조회할 수 있도록 `core.notification.filter_default_definitions` 필터 훅을 노출합니다.

```php
// 모듈 Listener에서
public static function getSubscribedHooks(): array
{
    return [
        'core.notification.filter_default_definitions' => [
            'method' => 'contributeDefaultDefinitions',
            'priority' => 20,
            'type' => 'filter',
        ],
    ];
}

public function contributeDefaultDefinitions(array $definitions, array $context = []): array
{
    $seeder = new \Modules\Vendor\Module\Database\Seeders\MyNotificationDefinitionSeeder();

    return array_merge($definitions, $seeder->getDefaultDefinitions());
}
```

컨텍스트: `['type' => string, 'channel' => string]` — 필요 시 특정 타입/채널만 필터링 가능.

---

## 채널 확장

플러그인이 Filter 훅으로 채널을 추가할 수 있습니다:

```php
// 플러그인 리스너에서
HookManager::addFilter(
    'core.auth.notification.channels',
    function (array $channels, string $type, object $notifiable) {
        $channels[] = 'fcm';
        return $channels;
    },
    priority: 10,
);
```

GenericNotification의 `__call()`이 `toFcm()` 호출을 `{hookPrefix}.notification.to_fcm` Filter 훅으로 위임합니다.

### 채널 메타데이터 다국어 규칙

채널의 이름(`name`), 설명(`description`), 출처 라벨(`source_label`)은 **`{field}_key` 패턴으로 lang key 만 선언**하고, `NotificationChannelService::getAvailableChannels()` 가 활성 locale 기준으로 string 으로 해석해 반환합니다 (registry payload `name_key` 계약, 7.0.0-beta.4+).

```php
// config/notification.php — 올바른 패턴
[
    'id' => 'mail',
    'name_key' => 'notification.channels.mail.name',
    'description_key' => 'notification.channels.mail.description',
    'source' => 'core',
    'source_label_key' => 'notification.channels.core_default',
]
```

```php
// lang/ko/notification.php — lang key 정의 (en/ja 도 동일 구조)
'channels' => [
    'core_default' => '코어 기본 채널',
    'mail' => ['name' => '메일', 'description' => '이메일로 알림 발송'],
],
```

API 응답: `name` / `description` / `source_label` 가 **이미 활성 locale 로 해석된 string** 으로 반환됩니다. (`name_key` 등 lang key 도 응답에 함께 포함되어 lang pack 보강 가능)

프론트엔드 레이아웃에서 접근:

```json
"text": "{{ch.name ?? ch.id}}"
"text": "{{ch.source_label ?? ch.source}}"
```

| 금지 | 올바른 사용 |
|------|------------|
| `$t:admin.settings.notification_definitions.source_core` | `ch.source_label` (백엔드 해석 string) |
| 번역 파일에 채널 메타데이터 하드코딩 | `config/notification.php` 의 `*_key` + `lang/{locale}/notification.php` 의 lang key |
| `ch.name?.[$locale] ?? ch.name?.ko` (다국어 객체 가정) | `ch.name` (백엔드가 이미 해석) |

---

## 채널 Readiness 검증

미설정 채널(SMTP 미구성 등)은 발송 시도 자체를 건너뜁니다.

### 아키텍처

```text
GenericNotification::via()
  ├→ definition.channels (DB)
  ├→ hook filter (플러그인 채널 추가/제거)
  ├→ ChannelReadinessService 필터 (미설정 채널 제외)
  ├→ NotificationTemplateService 필터 (활성 템플릿 없는 채널 제외)
  │   └→ skipped 채널 → notification_logs에 사유 기록
  └→ return readyChannels

NotificationDispatcher::sendToNotifiable() — 모든 채널의 공통 게이트포인트
  ├→ core.notification.before_channel_send 훅
  ├→ parent::sendToNotifiable() (실제 발송)
  ├→ 성공 → core.notification.after_channel_send 훅 → NotificationLogListener가 자동 로깅
  └→ 실패 → core.notification.channel_send_failed 훅 → 에러 로깅 + 다른 채널 계속 발송
```

### 발송 공통 훅 (NotificationDispatcher)

| 훅 | 시점 | 용도 |
|---|------|------|
| `core.notification.before_channel_send` | 발송 전 | 전처리, 필터링 |
| `core.notification.after_channel_send` | 발송 성공 | 로깅, 통계 |
| `core.notification.channel_send_failed` | 발송 실패 | 에러 로깅 |

모든 채널(mail, database, 플러그인 추가 채널)이 자동으로 이 훅을 통과합니다.
플러그인/모듈이 별도 조치 없이도 모든 채널 발송이 `notification_logs`에 자동 기록됩니다.
커스텀 로깅이 필요하면 동일 훅을 별도 리스너에서 구독하면 됩니다.

### 코어 채널 체크 조건

| 채널 | mailer | 필수 조건 |
|------|--------|----------|
| mail | smtp | `host`, `port`, `from_address` (기본값 제외) |
| mail | mailgun | `mailgun_domain`, `mailgun_secret`, `from_address` |
| mail | ses | `ses_key`, `ses_secret`, `from_address` |
| mail | log/array | 항상 ready (개발용) |
| database | - | `notifications` 테이블 존재 |

### 플러그인 채널 확장

채널 등록 시 readiness 체커도 함께 등록해야 합니다:

```php
// 1. 채널 추가 (기존)
HookManager::addFilter('core.notification.filter_available_channels', function ($channels) {
    $channels[] = ['id' => 'fcm', 'name' => [...], ...];
    return $channels;
}, priority: 10);

// 2. readiness 체커 등록 (필수)
HookManager::addFilter('core.notification.channel_readiness', function ($result, $channelId) {
    if ($channelId !== 'fcm') return $result;
    if (empty(config('services.fcm.server_key'))) {
        return ['ready' => false, 'reason' => 'fcm.server_key_empty'];
    }
    return ['ready' => true, 'reason' => null];
}, priority: 10);
```

미등록 채널은 기본 `ready=true` (발송 시도 허용).

### 관리자 API

`GET /api/admin/notification-channels` 응답에 각 채널별 `readiness` 포함:

```json
{ "id": "mail", "readiness": { "ready": false, "reason": "notification.readiness.mail_smtp_host_empty" } }
```

---

## 기존 시스템과의 호환

- `mail_templates`, `board_mail_templates`, `ecommerce_mail_templates` 테이블 + 모델/시더/컨트롤러/Repository는 **7.0.0-beta.2 에서 일괄 제거됨**
- 운영 환경 데이터는 `Upgrade_7_0_0_beta_2` (코어) + `Upgrade_1_0_0_beta_2` (보드/이커머스) 가 `notification_definitions` + `notification_templates` 로 이관
- `drop_*_mail_templates_table` 마이그레이션이 레거시 테이블을 제거 (down 시 스키마 복원)
- 다국어 헬퍼 + 변수 치환 trait 은 `NotificationContentBehavior` 로 리네임되어 NotificationTemplate 전용으로 사용됨 (구 `MailTemplateBehavior`)
