# 알림 시스템 (Notification System)

> 그누보드7 알림 시스템의 아키텍처와 확장 가이드

## TL;DR (5초 요약)

```text
1. GenericNotification 범용 클래스 1개로 모든 알림 처리 (개별 클래스 불필요)
2. notification_definitions 테이블 = 알림 타입 SSoT (채널, 훅, 변수 정의)
3. notification_templates 테이블 = 채널별 독립 템플릿 + 수신자 (mail/database/fcm)
4. 훅 기반 트리거: NotificationHookListener가 template별 독립 발송
5. 수신자 설정: template.recipients JSON으로 채널별 독립 수신자 (4종 타입)
6. 채널 확장: 채널 제공 확장은 코어 훅 `core.notification.channels` 하나에 답하고(도메인 비인지), 도메인은 `{hookPrefix}.notification.channels`로 좁힌다
7. 전칭 규칙: 사용자·운영자에게 나가는 모든 발송 메시지는 알림 시스템의 정의·템플릿·채널 설정·디스패처를 경유한다.
   별도 발송 경로·별도 메시지 정의 테이블을 만들지 않는다. 예외는 아래 「예외」 표에 이유와 함께 등재해야 한다
8. 본인인증 메일(인증번호·인증 링크)도 알림 정의다 — category=identity (7.1.0~, 아래 「본인인증 알림」)
```

> **본인인증 메일도 이 시스템을 쓴다(7.1.0~)**: 예전의 별도 메시지 테이블(`identity_message_definitions`·`identity_message_templates`)은
> 7.1.0 에서 알림 정의(`category = 'identity'`)로 통합되었습니다. 규칙·폴백·마스킹은 아래 [본인인증 알림](#본인인증-알림-categoryidentity) 절,
> 확장 개발자용 요약은 [identity-messages.md](identity-messages.md) 에 있습니다.

### 발송 경로 단일화

| ❌ 금지 | ✅ 올바른 사용 |
|---|---|
| 서비스·리스너에서 `Mail::to()->send()`·`Mail::raw()`·`Notification::route()` 로 직접 보내기, 기능 전용 `*_message_definitions`·`*_mail_templates` 테이블 만들기 | 알림 정의를 선언(`config('core.notification_definitions')` 또는 확장 `getNotificationDefinitions()`)하고 `GenericNotification` 을 `notify()`/`NotificationDispatcher` 로 보낸다 |

직접 보내면 운영자가 끈 채널로도 메일이 나가고(채널 토글 무시), 발송 로그(`notification_logs`)가 남지 않으며, 문구를 관리 화면에서
고칠 수 없습니다. 같은 이유로 기능마다 메시지 정의 테이블을 따로 두면 언어팩·기본값 복원·편집 화면을 기능 수만큼 다시 만들게 됩니다.

**예외** — 아래만 알림 시스템을 거치지 않습니다. 새 예외는 이 표에 이유와 함께 추가합니다.

| 대상 | 이유 |
|---|---|
| `SettingsService::sendTestMail()` (환경설정 > 메일 [테스트 메일 발송]) | 입력 중인 SMTP 설정 자체를 시험하는 기능이다 — 알림 채널이 꺼져 있어도, 저장 전 설정으로도 보내야 의미가 있다 |
| `App\Models\MailTemplate` (호환 심) | 테이블 없는 빈 모델이다. 업데이트 도중 메모리에 남은 옛 코드가 클래스를 찾다 죽지 않게 남겨 둔 것으로, 발송·정의에 쓰지 않는다 |
| `Database\Seeders\IdentityMessageDefinitionSeeder` (호환 심) | 아무것도 하지 않는 시더다. 7.0.x 에서 7.1.0 으로 올리는 업데이트 프로세스는 메모리에 남은 옛 코어 설정 재동기화 코드를 실행하고, 그 코드가 이 클래스를 만든다 — 클래스가 없으면 업데이트는 진행되지만 업데이트 로그에 재시딩 실패 경고가 남아 운영자를 오도하므로 이름만 남겼다. 본인인증 정의는 `NotificationDefinitionSeeder` 가 시드한다 |

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
| category | 분류 (7.1.0~): `identity`(본인인증 메일 문구) 또는 null(일반 알림). 관리 화면 분류 칩·발송 로그 마스킹이 이 값을 본다 |
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
applyFilters('core.notification.filter_seed_definitions', $list, $context)   ← 공통 시딩 훅 (7.1.0)
    ↓ type 을 담은 순차 배열 + 소유자 컨텍스트 — 채널 제공 확장이 자기 채널 template 을 더함
    ↓
NotificationSyncHelper::syncDefinition() — DB upsert
    ↑ user_overrides 마킹된 필드는 운영자 수정값 보존
```

모듈/플러그인 측: `ModuleManager::syncModuleNotificationDefinitions` / `PluginManager::syncPluginNotificationDefinitions` 가 동일 패턴 (`seed.{id}.notifications.translations` 필터 → 공통 시딩 훅 순서로 발화).

### 공통 시딩 훅 (7.1.0)

`core.notification.filter_seed_definitions` 는 코어·모든 모듈·플러그인의 알림 정의가 저장되기 직전 한 번 발화하는
코어 필터입니다(`NotificationSyncHelper::applySeedDefinitionsFilter()` 가 단일 진입점, 상수 `SEED_DEFINITIONS_FILTER`).

- 인자: `(array $definitions, array $context)` — 정의는 `type`·`extension_type`·`extension_identifier` 를 담은 순차 배열,
  컨텍스트는 `['extension_type' => 'core'|'module'|'plugin', 'extension_identifier' => 식별자]`.
- 문자·알림톡처럼 채널을 제공하는 확장은 도메인 이름을 모른 채 이 훅 하나에 답해 회원 대상 정의에 자기 채널 template 을
  더합니다. 도메인별 `seed.{id}.notifications.translations` 는 언어팩 병합 전용이라 채널 확장이 구독하지 않습니다.
- 훅이 더한 template 은 시딩 주체가 자기 선언처럼 저장하므로 `cleanupStaleTemplates` 에서 지워지지 않습니다.
- 리스너가 빈 목록이나 배열이 아닌 값을 돌려주면 원본으로 시딩합니다(소유자 정의 전량 삭제 방어). 소유자 필드는
  시딩 주체가 다시 채우므로 리스너가 바꿀 수 없습니다.
- 채널 확장을 켤 때 이미 저장된 정의에 훅을 다시 태우는 공개 진입점:
  `ModuleManager::resyncActiveModuleNotificationDefinitions()` / `PluginManager::resyncActivePluginNotificationDefinitions()`.
  설치·활성 확장의 알림 정의만 설치본 기준으로 재동기화하고(`_bundled`·역할·권한·메뉴 무관), `user_overrides` 는 보존합니다.
  코어 정의는 `NotificationDefinitionSeeder` 를 다시 실행합니다. 반환값: `['synced' => 식별자[], 'failed' => [식별자 => 사유]]`.

상세 계약: [훅 시스템 — 알림 정의 시딩 계약](../extension/hooks.md#알림-정의-시딩-계약-710).

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

### 상태별 알림은 상태마다 고유 훅이 필요하다

`NotificationHookListener` 는 정의의 `hooks` 에 적힌 이름을 **그대로** 구독한다 — 그 훅이 발화하면 그 훅에 묶인 정의가 **전부** 발송된다. 그래서 여러 상태를 하나의 전이 훅(`*.status_changed` 등)에 묶으면, 어떤 상태로 옮겨도 그 훅에 묶인 알림이 한꺼번에 나간다.

| ❌ 금지 | ✅ 올바른 사용 |
|--------|---------------|
| 상태 N종의 정의를 공통 전이 훅 하나에 묶기 | 상태마다 고유한 알림 전용 훅(`{도메인}.notify_{상태}` 등)을 두고 매핑 리스너가 전이 훅을 받아 그 하나만 발화 |
| 매핑 기준을 재조회한 모델의 현재 상태로 삼기 | 발화 측이 넘긴 **전이 시점 목표 상태**(스칼라)로 판정 — 리스너는 큐로 디스패치되고 워커가 모델을 PK 로 재조회하므로, 큐가 밀린 사이 또 전이되면 엉뚱한 알림이 나간다 |
| 발화 목록과 정의 목록을 각각 손으로 유지 | 한쪽을 정본으로 두고 다른 쪽이 읽게 한다 — 갈라지면 **발화되는데 정의가 없거나(무음) 정의만 있고 아무도 발화하지 않는다(사문)** |
| 같은 사실을 알리는 도메인 훅과 알림 훅을 둘 다 정의에 묶기 | 알림 소스는 하나로 — 두 훅이 같은 순간에 발화하는 경로가 있으면 같은 고객이 두 통을 받는다 |

발화 측이 이 규칙을 따른 예: 주문 축은 `order.after_status_change` 를 `OrderStatusNotificationListener` 가 받아 `order.after_ship` 등 순수 알림 훅으로 옮기고, 정의는 그 알림 훅에만 묶는다.

### 큐 워커에서의 사용자/로케일 컨텍스트

알림 발송 리스너는 기본적으로 큐로 디스패치되며, 큐 워커는 별도 프로세스라 `Auth::user()`/`App::getLocale()`이 모두 리셋됩니다. 그러나 G7은 디스패치 시점의 컨텍스트를 자동 복원하므로 다음이 보장됩니다:

- 알림 발송 로그(`NotificationLogListener`)의 행위자가 실제 트리거한 사용자로 정상 기록

리스너 코드는 변경 불필요 — 평소처럼 `Auth::user()`, `__('...')` 호출하면 됩니다.

#### 알림 언어는 수신자 기준

알림 본문/제목의 렌더 언어는 요청자(트리거한 관리자/사용자)의 app locale 이 아니라 **수신자 본인 언어(`users.language`)** 로 결정됩니다. 관리자가 한국어 UI 에서 영어 사용자에게 주문 알림을 보내도, 그 알림은 수신자 언어로 렌더됩니다.

- `User` 는 Laravel `HasLocalePreference` contract 를 구현하며, `preferredLocale()` 이 `users.language`(SSoT)를 반환합니다. 빈값/미지원 로케일이면 `config('app.locale')` 으로 폴백합니다 (요청자 locale 폴백 없음).
- 비회원(`GuestNotifiable`)도 동일 contract 를 구현하며, 발송 트리거 시점에 저장된 로케일(예: 주문 시 `orderer_locale`)을 `guest_recipient.locale` 로 받아 `preferredLocale()` 이 반환합니다. 미저장 시 `app locale` 폴백.
- 렌더 3지점(`GenericNotification::toMail`/`toArray`, `NotificationDispatcher::resolveRenderedContent`)은 `BaseNotification::resolveNotifiableLocale($notifiable)` 헬퍼로 수신자 선호 로케일을 우선 해석합니다. 알림 렌더에서 `$notifiable->locale` 을 직접 참조하지 않습니다 (해당 속성은 존재하지 않아 항상 폴백됨).

> 자세한 동작은 [extension/hooks.md "사용자 컨텍스트 자동 복원"](../extension/hooks.md) 참조

---

## 수신자 설정 (Recipients)

### 개요

`notification_templates.recipients` JSON 컬럼으로 **채널별 독립 수신자**를 DB에서 설정합니다.
동일한 알림 정의라도 메일과 사이트내 알림에 서로 다른 수신자를 지정할 수 있습니다.

### 수신자 타입

| type | 설명 | value | 예시 |
|------|------|-------|------|
| `trigger_user` | 이벤트 유발자 (회원 User 또는 비회원 게스트) | - | 주문자, 가입자 |
| `related_user` | 관련 사용자 | relation 키 | 문의 작성자 |
| `role` | 역할 기반 | role identifier | admin, manager |
| `specific_users` | 특정 사용자 | user UUID 배열 | ["uuid1", "uuid2"] |

> `trigger_user` 는 회원/비회원을 동일 규칙으로 처리합니다. context 에 `trigger_user_id` 가 있으면 회원(User), 없고 표준 키 `guest_recipient` 가 있으면 비회원([비회원 발송](#비회원게스트-알림-발송) 참조)으로 해석됩니다.

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

### 코어 정의

전수는 `config/core.php` 의 `notification_definitions` 가 단독으로 소유한다 — 아래는 그중 인증·휴면 계열이다. 비밀번호 만료 2종(#736)은 `auth:process-password-expiry` 가 발행하며, 변경 화면 주소(`{action_url}`)가 수신자에 따라 다르다 — 관리자는 관리자 회원 수정 화면·관리자 로그인, 회원은 마이페이지 비밀번호 변경·로그인.

| type | hooks | 채널 |
|------|-------|------|
| welcome | core.auth.after_register | mail, database |
| reset_password | core.auth.after_reset_password_request | mail, database |
| password_changed | core.auth.after_password_changed | mail, database |
| password_expiry_notice | core.auth.password_expiry_notice | mail, database |
| password_expired | core.auth.password_expired | mail, database |
| dormant_pre_notice | core.privacy.dormant_pre_notice | mail, database |
| dormant_converted | core.privacy.dormant_converted | mail, database |
| dormant_withdraw_notice | core.privacy.dormant_withdraw_notice | mail, database |

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

### 이커머스 (주문·문의 발췌)

| type | hooks | 채널 |
|------|-------|------|
| order_confirmed | sirsoft-ecommerce.order.after_confirm | mail, database |
| order_shipped | sirsoft-ecommerce.order.after_ship | mail, database |
| order_completed | sirsoft-ecommerce.order.after_complete | mail, database |
| order_cancelled | sirsoft-ecommerce.order.after_cancel | mail, database |
| order_pending_deposit | sirsoft-ecommerce.order.after_create | mail, database |
| new_order_admin | sirsoft-ecommerce.order.after_admin_notify | mail, database |
| inquiry_received | sirsoft-ecommerce.product_inquiry.after_create | mail, database |
| inquiry_replied | sirsoft-ecommerce.product_inquiry.after_reply | mail, database |

> `new_order_admin` 의 `order.after_admin_notify` 훅은 결제수단별로 발화 시점이 다르다. `OrderProcessingService` 가 무통장/비-PG/0원 주문은 주문 생성 시점에, 카드(PG) 주문은 결제완료(`completePayment`) 시점에 1회 발화한다. 카드 주문은 생성 시점이 `pending_order`(결제 전)라 그 시점 발송 시 결제 미완료/이탈 주문에 오발송된다. `order_pending_deposit` 는 무통장 한정으로 `EcommerceNotificationDataListener` 가 dbank 외 결제수단을 빈 결과로 게이팅한다.
>
> 위 표는 주문·문의 축만 담은 발췌다. 이커머스 정의의 정본은 `module.php::getNotificationDefinitions()` 이며, 여기에 마일리지 소멸 예정 1종과 클레임(교환·반품·환불) 상태별 29종이 더 있다. 클레임 29종은 유형 × 고객 가시 상태의 곱이라 표로 옮기면 곧 낡으므로 옮기지 않는다 — 목록과 훅 이름은 `ClaimStatusNotificationListener::notifiableStatuses()` 를 읽는다.

**발송**: 이커머스는 직접 `notify()` 를 부르지 않습니다. 정의의 `hooks` 를 코어 `NotificationHookListener` 가 구독해 발송하고,
`EcommerceNotificationDataListener`·`ClaimNotificationDataListener` 가 `sirsoft-ecommerce.notification.extract_data` 필터로 변수·컨텍스트를
채웁니다. 받는 사람은 템플릿의 `recipients` 규칙이 정합니다. 상태 전이는 `OrderStatusNotificationListener`·`ClaimStatusNotificationListener` 가
상태별 알림 훅으로 바꿔 발화합니다.

그 밖에 훅이 아니라 화면 조작으로 보내는 정의가 둘 있습니다 — 주문 상세 「고객에게 메일 보내기」의 `order_custom_email`(1.3.0~, 관리자가
쓴 제목·본문을 `{subject}`·`{message}` 로 끼우는 틀)과 본인인증 문구 `identity.purpose.checkout_verification` 입니다. 둘 다 `hooks` 가
비어 있고, 메일 채널이 꺼져 있으면 보내지 않습니다(주문 메일은 422 로 거절).

---

## 본인인증 알림 (category=identity)

7.1.0 부터 본인인증 메일(인증번호·인증 링크)은 일반 알림과 같은 `notification_definitions`·`notification_templates` 행입니다.
구분은 `category = 'identity'` 하나이고, 훅으로 발송하지 않으므로 `hooks` 는 비어 있습니다.

### type 규칙

| type | 뜻 | 소유 |
|---|---|---|
| `identity.default` | 공통 기본 문구 — 아래 둘이 없을 때 | 코어 |
| `identity.purpose.{purpose}` | 인증 목적별 문구 — `signup`·`password_reset`·`self_update`·`sensitive_action`·`login`(관리자 2단계 인증), 확장 목적(예: 이커머스 `checkout_verification`) | 코어·확장 |
| `identity.policy.{policy_key}` | 정책별 문구 — 운영자가 관리 화면에서 추가한다. type 이 100자를 넘으면 `identity.policy.~{sha1(policy_key) 앞 20자}` | 코어(`extension_identifier = 'admin'`) |

규칙의 SSoT 는 `App\Notifications\Identity\IdentityNotificationType` 입니다(`default()`·`forPurpose()`·`forPolicy()`·`candidates()`). type 문자열을
손으로 조립하지 않습니다 — 100자 해시 규칙이 갈립니다.

### 문구 고르기 — 3단 폴백

발송 시 `candidates($purpose, $policyKey)` 순서(정책 → 목적 → 기본)로 보고, **활성이고 `category = identity` 이며 메일 템플릿이 있는**
첫 정의를 씁니다. 운영자가 목적별 문구를 꺼 두면 공통 기본 문구로 내려갑니다. 셋 다 없으면 훅 `core.identity.message.resolve_failed` 를
발화하고 보내지 않습니다(발송 실패 → 인증 시작 502).

### 발송 경로

```text
MailIdentityProvider::dispatchMessage()
  → IdentityMessageDispatcher::dispatch()          (얇은 파사드 — 정의 고르기 + 받는 사람 고르기)
      → GenericNotification(type, data, channel=mail)->withDeliveryReport($report)
      → NotificationDispatcher (일반 알림과 같은 via() 필터: 채널 토글 · readiness · 템플릿 활성)
      → notification_logs 기록 (code·action_url 마스킹)
  ← $report->wasSent('mail')  → false 면 인증 시작 실패
```

- **받는 사람**: 컨텍스트의 `user_id` 회원 이메일이 인증 대상 주소와 같으면 그 `User`, 아니면 `GuestNotifiable`(대상 주소 + 현재 로케일)입니다.
  가입 전 인증·이메일 변경 인증처럼 회원이 아닌 주소로 보내는 경우가 후자입니다.
- **결과 판정 — `DeliveryReport`**: 라라벨 `notify()` 는 채널을 건너뛰어도 예외 없이 끝나므로, 디스패처가 채널별 결과(`sent`·`skipped`·`failed` + 사유)를
  리포트에 적고 호출자가 읽습니다. 인증 메일이 실제로 나갔는지 확인하지 않고 「발송했습니다」 응답을 내면 사용자는 오지 않는 메일을 기다립니다.
- **로그 마스킹**: `NotificationLogListener` 는 `category = identity` 발송의 제목·본문에서 `code`·`action_url` 값(HTML 이스케이프 형태 포함)을 `******` 로 바꿔 저장합니다.
  발송 로그를 볼 수 있는 운영자가 남의 인증번호로 인증을 가로채지 못하게 하기 위해서입니다. 가리는 것은 로그 기록뿐이고 **발송 훅의 컨텍스트
  원본은 그대로**입니다 — `core.notification.*` 훅을 구독해 제목·본문을 따로 저장하는 확장은 `category = identity` 일 때 `code`·`action_url`
  (`IdentityNotificationType::sensitiveVariables()`) 값을 스스로 가려야 합니다.
- **호환 훅**: `core.identity.message.before_send`·`after_send`·`send_failed`·`resolve_failed` 는 계속 발화합니다(@deprecated 7.1.0).
  새 코드는 알림 공통 훅(`core.notification.*`)을 구독합니다.

### 메일 채널 토글과 자기 잠금

본인인증 메일은 「환경설정 > 알림 설정」의 **메일 채널 토글을 따릅니다**. 메일을 끄면 회원가입·비밀번호 찾기·정보 변경 인증번호와 관리자
2단계 인증 메일이 모두 멈추고, OTP 앱(TOTP)을 등록하지 않은 관리자는 로그인할 수 없습니다(2단계 인증 메일 → 503). 관리 화면은 메일을
켜짐 → 꺼짐으로 바꿔 저장할 때 확인 창을 띄웁니다.

잠겼을 때 복구: 서버에서 `storage/app/settings/notifications.json` 의 `channels` 중 `"id": "mail"` 항목을 `"is_active": true` 로 고친 뒤
`php artisan cache:clear` 를 실행합니다(설정을 캐시해 둔 서버는 `php artisan config:clear` 도). 다음 로그인부터 인증번호 메일이 다시 나갑니다.

### 관리 화면 · API

- 화면: 「환경설정 > 알림 설정」 분류 칩 「본인인증」(`?tab=notification_definitions&category=identity`). 편집·활성 토글·기본값 복원은 일반 알림과 같고,
  수신자 규칙 영역 대신 「인증을 요청한 사람에게만」 고정 문구가 보입니다. 확장 소유 문구(예: 이커머스)는 그 확장의 설정 > 알림에 있습니다.
- 정책별 문구 추가 `POST /api/admin/notification-definitions` (`category=identity` + 운영자 정책 `policy_key`) · 삭제 `DELETE /api/admin/notification-definitions/{id}`
  (운영자가 추가한 정의만 — `abilities.can_delete`). 권한은 `core.settings.read`·`core.settings.update` 입니다.
- 7.1.0 에서 제거: `/api/admin/identity/messages/*` 11개, 권한 `core.admin.identity.messages.read`·`update`.

### 확장의 선언

확장은 본인인증 목적별 문구를 `getNotificationDefinitions()` 에 일반 알림과 나란히 선언합니다.

```php
[
    'type' => IdentityNotificationType::forPurpose('checkout_verification'), // identity.purpose.checkout_verification
    'category' => 'identity',
    'hook_prefix' => 'sirsoft-ecommerce',
    'hooks' => [],
    'channels' => ['mail'],
    'variables' => IdentityNotificationType::commonVariables(),
    'templates' => [['channel' => 'mail', 'subject' => [...], 'body' => [...]]],
],
```

예전 getter `getIdentityMessages()` 는 @deprecated 입니다. 아직 구현한 확장은 설치·업데이트 때 `IdentityMessageDeclarationConverter` 가 알림 정의
선언으로 바꿔 병합하지만(메일이 아닌 provider 의 선언은 경고 후 메일 템플릿으로 흡수, 알 수 없는 범위·다른 소유자의 type 과 겹치는 선언은 경고 후 건너뜀), 새 확장은 쓰지 않습니다.

### 업그레이드 (7.1.0)

`upgrades/data/7.1.0/migrations/07_MigrateIdentityMessagesToNotifications.php` 가 옛 테이블의 행을 위 규칙의 알림 정의로 옮깁니다 — 운영자가 고친
문구(`user_overrides`·`updated_by`)는 그대로 두고, 운영자 정책 문구는 `core`/`admin` 정의로 만들며, 메일이 아닌 provider 행은 건너뜁니다. 옮긴 건수가
맞지 않으면 옛 테이블을 지우지 않고 업그레이드를 멈춥니다. 맞으면 두 테이블과 권한 2개를 지웁니다.

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
            'audience' => 'customer',          // 'customer' | 'staff' — 아래 표 참조
            'relations' => ['author'],         // 선택. 「관련 사용자」로 고를 수 있는 관계 키
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

#### `audience` — 이 알림이 누구를 향하는가

| 값 | 뜻 | 수신자 규칙이 따라야 하는 형태 |
| --- | --- | --- |
| `customer` | 고객이 받는 알림 | `trigger_user` · `related_user` 중심 |
| `staff` | 운영자가 받는 알림 | `role` 규칙으로 **그 권한의 주인 역할을 전부** 나열 |

DB 컬럼이 늘지 않는다 — `NotificationSyncHelper` 가 저장 필드를 화이트리스트로 고르므로 `audience` 는 **선언 계층에만** 남고, 계약 테스트가 이 값을 읽어 수신자 규칙과의 정합을 검사한다.

**`staff` 로 선언하면 역할을 빠짐없이 적어야 한다.** 권한 하나를 여러 역할이 나눠 갖는 구조라 역할 하나를 빠뜨리면 그 역할의 운영자는 **알림을 한 건도 받지 못하고**, 화면 어디에도 그 사실이 드러나지 않는다. 모듈이 권한 선언에 적어 둔 `roles` 목록을 그대로 옮긴다.

#### `relations` — 「관련 사용자」로 고를 수 있는 관계

리스너가 `context.related_users` 에 실어 주는 키 목록이다. 관리자 화면의 수신자 타입 「관련 사용자」는 **이 선언에서만** 선택지를 만든다.

- 선언이 비면 타입 드롭다운에서 「관련 사용자」 자체가 **표시되지 않는다**. 관계를 주지 않는 알림에서 그 타입을 고르면 수신자가 0명이 되고 로그도 남지 않기 때문이다.
- 서버는 저장 시 `relation` 값이 이 목록 안에 있는지 검증한다 — 오타가 발송 시점이 아니라 저장 시점에 드러난다.
- 리스너가 실제로 그 키를 공급하는지는 선언과 별개다. 선언만 하고 공급하지 않으면 같은 「조용한 0명」이 된다.
- **관계마다 이름을 언어 파일에 둔다.** 관리자 화면은 선택지와 목록에 관계 키(`post_author`) 대신 이름(「게시글 작성자」)을 보인다. 정의 응답의 `relation_labels` 가 그 이름이며, 확장 알림은 그 확장의 `{식별자}::messages.notification_relations.{관계}`, 코어 알림은 `notification.relations.{관계}` 에서 찾는다(코어 정의는 아직 관계를 두지 않아 이 그룹은 비어 있다 — 코어 알림이 관계를 선언할 때 함께 적는다). 키가 없으면 관계 키를 그대로 보인다 — 운영자에게 개발용 이름이 노출되므로 ko·en(번들 언어팩 포함)에 함께 적는다.

```php
// modules/_bundled/sirsoft-board/src/lang/ko/messages.php
'notification_relations' => [
    'post_author' => '게시글 작성자',
    'board_managers' => '게시판 관리자',
],
```

#### `variables` — 변수 설명은 언어 파일에서 찾는다 (7.1.0)

선언의 `variables[].description` 은 한국어 한 벌이고 DB 에도 그대로 저장된다. 편집 창 「사용 가능한 변수」 가 이 문자열을 그대로 보이면 영어·일본어 화면에 한국어가 섞이므로, 정의 응답(`NotificationDefinitionResource`)은 변수 설명을 **요청 언어의 언어 파일**에서 찾아 싣는다(`NotificationDefinition::variableDescription()`). 찾는 순서:

| 알림 | 1순위 | 2순위 | 없으면 |
| --- | --- | --- | --- |
| 확장 알림 | `{식별자}::messages.notification_variables.by_type.{type}.{키}` (그 알림에서만 뜻이 다른 변수) | `{식별자}::messages.notification_variables.common.{키}` | 선언 문자열 |
| 코어 알림 | `notification.variables.by_type.{type}.{키}` | `notification.variables.common.{키}` | 선언 문자열 |
| 본인인증 알림(`identity.*`) | `notification.variables.identity.{키}` (코어 공통 변수, 선언한 확장과 무관) | — | 선언 문자열 |

- ko 언어 파일 값은 선언 문자열과 **글자 단위로 같게** 둔다 — 선언 설명을 바꾸면 ko·en(번들 언어팩 포함) 키도 함께 고친다. 번들 확장은 `NotificationVariableDescriptionLocaleTest` 가 선언 전수를 ko=선언·en 한글 없음·ja 팩 키 일치로 대조한다.
- 키가 없으면 선언 문자열(대개 한국어)이 그대로 보인다 — 외부 확장도 위 경로에 ko·en 키를 두면 화면 언어로 나온다. 본인인증 알림에 공통 변수 밖의 키를 선언하면 그 키는 언어 파일 경로가 없어 선언 문자열로 보인다.

```php
// modules/_bundled/sirsoft-ecommerce/src/lang/en/messages.php
'notification_variables' => [
    'common' => ['order_number' => 'Order number'],
    'by_type' => ['new_order_admin' => ['name' => 'Recipient (administrator) name']],
],
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

- 모듈/플러그인 측에 별도 `*NotificationDefinitionSeeder.php` 파일을 두면 안 됩니다. 동일 데이터를 두 곳에서 유지하면 SSoT 가 깨집니다.
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
| GET | /api/admin/notification-definitions | 알림 정의 목록 (`category` 로 분류 필터) |
| POST | /api/admin/notification-definitions | 본인인증 정책별 문구 추가 (7.1.0~) |
| GET | /api/admin/notification-definitions/{id} | 알림 정의 상세 (templates 포함) |
| PUT | /api/admin/notification-definitions/{id} | 채널/훅 수정 |
| DELETE | /api/admin/notification-definitions/{id} | 운영자가 추가한 본인인증 정의 삭제 (7.1.0~) |
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

채널을 제공하는 플러그인은 **코어 훅 하나**(`core.notification.channels`)에 답해 모든 도메인의 채널
후보에 자기 채널을 더합니다. 도메인 접두사(`core.auth`·게시판·이커머스 …)를 구독하지 않습니다 —
목록에 없는 도메인에서 채널이 빠지기 때문입니다.

```php
// 채널 제공 플러그인 리스너에서
HookManager::addFilter(
    'core.notification.channels',
    function (array $channels, string $type, ?object $notifiable, string $hookPrefix) {
        $channels[] = 'fcm';
        return $channels;
    },
    priority: 20,
);
```

코어는 `NotificationChannelService::resolveChannelCandidates($hookPrefix, $channels, $type, $notifiable)` 에서
① `core.notification.channels`(채널 제공 확장) → ② `{hookPrefix}.notification.channels`(도메인이 자기 설정으로
좁히거나 더함) 순으로 후보를 거칩니다. 도메인이 활성 채널을 화면에 보여 줘야 하면 자기 훅을 직접 발화하지
말고 이 진입점을 부릅니다. 계약 표: [hooks.md — 알림 채널 후보 계약](../extension/hooks.md#알림-채널-후보-계약-710).

채널 템플릿 행(알림 설정 화면에 그려지는 채널별 제목·본문·수신자)을 정의에 미리 넣어야 하면 마찬가지로 **코어
시딩 훅 하나**(`core.notification.filter_seed_definitions`)에 답합니다 — 위 [공통 시딩 훅](#공통-시딩-훅-710) 참조.

GenericNotification의 `__call()`이 `toFcm()` 호출을 `{hookPrefix}.notification.to_fcm` Filter 훅으로 위임합니다.

---

## 비회원(게스트) 알림 발송

user_id 없는 비회원(주문자 이메일/이름만 보유)도 회원과 동일한 발송 경로로 알림(이메일)을 받습니다. 비회원을 위한 별도 발송 흐름을 만들지 않고, **1급 수신자 값 객체**로 승격하여 기존 파이프라인을 그대로 탑니다.

### 핵심 구성

| 구성 | 위치 | 역할 |
|------|------|------|
| `GuestNotifiable` | `app/Notifications/GuestNotifiable.php` | 비회원 1급 수신자. Laravel `Notifiable` 트레잇 + `HasLocalePreference` 구현 (User 와 동일 계약). email/name/locale 보유 |
| `GuestRecipientInterface` | `app/Contracts/Notifications/GuestRecipientInterface.php` | 게스트 판별 코어 계약 (`isGuest()`). 게이트가 구체 타입(User) 검사 대신 이 계약 사용 |
| 표준 context 키 `guest_recipient` | `{email, name, locale}` | resolver 의 `trigger_user` 규칙이 user_id 없을 때 이 키로 GuestNotifiable 생성 |
| 표준 data 키 `GenericNotification::RECIPIENT_PHONE_KEY` | `'_recipient_phone'` | 수신자 객체에 전화번호가 없을 때 도메인이 extract_data 의 data 에 싣는 수신 번호. 문자·알림톡 채널은 이 키만 읽는다(번호 없으면 키 생략) |

### 수신자 해석 (NotificationRecipientResolver)

`trigger_user` 규칙은 회원/비회원 분기 없이 동작합니다.

```php
// extract_data 필터에서 비회원 컨텍스트 제공
'context' => [
    'trigger_user_id' => null,                    // 비회원이므로 null
    'guest_recipient' => [                         // 코어 표준 키
        'email' => $ordererEmail,
        'name' => $ordererName,
        'locale' => $ordererLocale,               // 없으면 null → app locale 폴백
    ],
]
```

- `trigger_user_id` 가 있으면 User, 없고 `guest_recipient` 가 있으면 GuestNotifiable 을 수신자로 반환합니다.
- **삭제 회원은 폴백하지 않습니다.** `trigger_user_id` 가 있는데 그 계정이 삭제되어 없으면 `guest_recipient` 가 함께 실려 있어도 그 주소(주문서 연락처 등)로 대신 보내지 않고 0명이 되며, 발송 이력에 `notification.skipped.member_account_missing` 사유로 남습니다. 탈퇴 회원(행은 남고 연락처가 익명화됨)은 그 회원 자신으로 해석되어 알림함(`database`)은 받고 바깥 채널만 건너뜁니다(`withdrawn_recipient`). 두 판정은 `NotificationWithdrawnRecipientPolicy` 한 곳이 소유합니다 — 수신자 해석과 발송 루프가 같은 객체를 씁니다.
- 중복 제거 키는 네임스페이스 분리: 회원 `user:{id}` / 비회원 `guest:{이메일 해시}` — null-id 게스트가 하나로 뭉개지지 않습니다.
- `exclude_trigger_user` 는 회원(user_id) 기준이라 게스트 수신자를 깨뜨리지 않습니다.

### 채널 메타의 화면 표시 키 (hidden_tab · tab_channels · tab_label_key · hidden_template_editor)

확장이 `core.notification.filter_available_channels` 훅으로 등록하는 채널 메타는 임의 필드를 보존해 프론트(`availableChannels` 데이터소스)까지 그대로 도달한다. 관리자 알림 설정 화면(코어 `admin_settings` · 게시판 `admin_board_settings` · 이커머스 `admin_ecommerce_settings`)은 다음 범용 키를 해석한다. 특정 확장 이름을 알지 못하며, 키가 없으면 종전과 동일하게 동작한다.

| 키 | 타입 | 효과 |
| --- | --- | --- |
| `hidden_tab` | bool | 채널 서브탭만 숨긴다. 채널 토글 카드·발송 축은 그대로 |
| `tab_channels` | string[] | 이 채널의 탭이 대표하는 채널 id 목록. 목록 중 하나라도 활성 저장이면 탭을 노출한다(여러 채널을 하나의 탭으로 묶을 때) |
| `tab_label_key` | string | 탭 라벨용 프론트 lang 키(`$t` 해석). 카드 라벨(`name_key`)과 분리 |
| `hidden_template_editor` | bool | 알림 템플릿 [편집] 모달에서 코어의 언어탭·제목·본문·클릭 URL·변수 안내·[미리보기]·코어 [저장]을 숨긴다. 그 채널의 본문 규격이 코어 템플릿과 달라 확장이 편집기와 저장을 대신할 때 선언한다. 수신자 규칙·[취소]는 남는다 |
| `template_editor` | `'rich'\|'plain'\|'none'` | 본문 편집기의 **종류**를 채널이 정한다. `rich` 는 `extension_point` `html_editor` 슬롯(CKEditor 등이 채운다, 없으면 평문으로 폴백) · `plain` 은 평문 입력 · `none` 은 `hidden_template_editor` 와 같다. 코어 기본값은 **mail=`rich` · database=`plain`** 이고 미선언 채널은 `plain` 이다 |

**`template_editor` 를 반드시 선언해야 하는 이유** — 본문이 실제로 어떻게 그려지는지는 채널마다 다르다. 사이트내 알림(database)은 사용자 알림센터가 본문을 **평문으로** 그리므로, 편집기만 서식 편집기로 열어 두면 운영자가 넣은 태그가 사용자 화면에 **글자 그대로** 보인다. 관리자 [미리보기]도 이 값을 따르므로, 값을 잘못 선언하면 운영자가 본 것과 사용자가 받는 것이 갈린다.

`hidden_template_editor: true` 는 `template_editor: 'none'` 으로 해석되어 **하위호환이 유지된다** — 기존 확장은 고치지 않아도 된다.

`none`(또는 `hidden_template_editor`)을 선언한 확장은 편집 모달의 확장 지점 두 곳에 자기 UI 를 주입한다. 이름이 공통이라 extension_point 파일 1본이 그 모달을 둔 모든 화면(코어·게시판·쇼핑몰 설정, 같은 모달을 둔 플러그인 설정 화면 — GDPR·환율)에 주입된다. 주입 UI 가 읽는 데이터소스는 화면별 오버레이가 아니라 확장 지점 조각의 `data_sources` 에 둔다 — 그래야 확장이 모르는 화면(다른 플러그인 설정)에도 함께 실린다. 같은 id 를 오버레이에서 다시 선언하지 않는다(한 레이아웃 안 id 중복).

| extension_point | 위치 | props |
| --- | --- | --- |
| `notification_template_form_sections` | 수신자 규칙 다음, 코어 언어탭 앞 | `definition` · `template` · `channel` · `modalId` · `stateKey`(면별 코어 모달 상태 키) · `saveEndpoint`(코어 채널 템플릿 PUT) · `refetchDataSourceId`(면별 알림 정의 목록) |
| `notification_template_form_footer_actions` | [취소] 와 코어 [저장] 사이 | 위와 동일 |

확장은 이 props 만으로 코어 저장 계약을 이행한다 — 코어 엔드포인트·상태 키를 리터럴로 적지 않는다. 코어 채널 템플릿의 수신자 규칙은 `_global?.[extensionPointProps.stateKey]?.recipients` 로 읽어 바뀐 경우에만 `saveEndpoint` 로 PUT 한다(매번 PUT 하면 코어가 행을 "사용자 수정" 으로 표시한다). 선례: `plugins/_bundled/sirsoft-message_bizppurio/resources/extensions/notification_template_form_*.json`. 행 하단 확장 지점 `notification_definition_row_footer`(props `definition` · `activeChannel`)는 상태 요약 같은 읽기 전용 UI 용이다.

### 정의 타입 라벨의 공급처

관리자 알림 설정 화면의 정의 목록 제목은 DB `name` 컬럼이 아니라 프론트 다국어 키
`admin.settings.notification_definitions.types.{type}` 로 렌더된다. 이 키의 공급처는 정의 소유자를 따른다.

| 정의 소유 | 라벨 위치 |
| --- | --- |
| 코어 (`config/core.php` `notification_definitions`) | admin 템플릿 `lang/partial/{ko,en}/admin.json` 의 `settings.notification_definitions.types` (ja 는 템플릿 언어팩) |
| 모듈 | 각 모듈의 알림 설정 화면과 lang 이 담당 |

키가 없으면 예외도 경고도 없이 원시 키 문자열이 화면 제목으로 그대로 노출된다. 코어 정의를
추가하는 변경은 반드시 admin 템플릿 라벨(ko·en)과 템플릿 언어팩(ja)을 함께 추가해야 하며,
코어 정의 전수 ↔ 템플릿 라벨 패리티는 테스트가 자동 대조한다.

## 채널 템플릿을 시드하는 확장의 [기본값 복원] 의무

확장이 공통 시딩 훅(`core.notification.filter_seed_definitions`)으로 자기 채널의 템플릿 행을 알림 정의에 끼워 넣으면, 같은 증강을
`core.notification.filter_default_definitions` 에도 걸어야 한다. 관리자 화면의 [기본값 복원]은
`NotificationTemplateService::getDefaultTemplateData()` 가 이 필터를 통과시킨 정의 배열에서 해당 채널의 template 을 찾아
복원하므로, 시드 출처와 복원 출처가 같은 함수가 아니면 그 채널 행의 복원은 "기본 템플릿 데이터를 찾을 수 없습니다" 로 끝난다
(시드는 되는데 복원만 안 되는 상태라 오류 로그도 남지 않는다). 모듈이 자기 정의를 이 필터에 보태는 priority(20) 뒤에서 돌아야
모듈 정의도 증강된다. 선례: `plugins/_bundled/sirsoft-message_bizppurio/src/Listeners/SeedChannelTemplatesListener.php`.

### 채널별 비회원 발송 허용 (allow_guest)

채널이 비회원 발송을 허용하는지는 `config/notification.php` 채널 메타의 `allow_guest` 로 선언합니다.

```php
['id' => 'mail',     ..., 'allow_guest' => true ],   // 비회원 가능
['id' => 'database', ..., 'allow_guest' => false],   // 비회원 불가 (사이트내 알림)
```

- **미선언 채널은 기본 차단(false)** — 비회원 개인정보(이메일 등)가 의도치 않게 새 프로바이더로 노출되는 것을 막는 opt-in 정책입니다. (확장 단위 채널 활성 `isChannelEnabledForExtension` 의 "미선언=활성" 과 반대 방향)
- 모듈/플러그인이 `core.notification.filter_available_channels` 훅으로 추가하는 신규 채널(SMS·알림톡·앱푸시 등)도 동일하게 `allow_guest` 를 명시해야 비회원 발송이 허용됩니다.
- `NotificationChannelService::isChannelGuestAllowed($channelId)` 가 단일 진입점이며, `core.notification.channel_guest_allowed` 필터 훅으로 동적 재정의 가능합니다.

### 게이트 적용 지점

`GenericNotification::via()` 의 게이트 체인에 게스트 게이트가 합류합니다 (회원은 무영향).

```text
via()
  ├→ [게스트 게이트] notifiable 이 게스트 + 채널 allow_guest=false → 제외(skipped)
  ├→ isChannelEnabledForExtension (확장 채널 토글)
  └→ ChannelReadinessChecker (채널 설정 완료)
```

게스트 + database → 게이트 제외 → 발송 안 됨. database 가 차단되므로 `notifications` morph 테이블에 null-id 행이 생기지 않습니다.

### 발송 로깅

비회원 발송도 기존 `notification_logs` 에 정상 기록됩니다 (`NotificationDispatcher::buildContext`).

- 게스트는 `recipient_user_id = null` + `recipient_identifier = 이메일` 로 기록됩니다. 게스트의 합성 키(`guest:...`)는 정수 FK 컬럼에 넣지 않습니다.
- 관리자 발송 이력 조회(`GET /api/admin/notification-logs?search={이메일}`)에서 `recipient_identifier` LIKE 검색으로 비회원 발송을 찾을 수 있습니다 (전체 접근 권한 기준).

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
메일 발송 공통 훅 `core.mail.after_send`·`core.mail.send_failed` 는 로그 리스너가 구독하지 않습니다 — 알림 경유 메일은 위
`after_channel_send`·`channel_send_failed` 로 이미 기록되므로 둘 다 구독하면 한 건이 두 번 남습니다(알림을 거치지 않는 예외인
테스트 메일은 기록하지 않습니다).

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
- 코어 `mail_templates`·`mail_send_logs` 는 생성 마이그레이션이 남아 신규 설치에 빈 테이블이 생기던 것을 7.1.0 에서 정리했다 — 생성 마이그레이션을 지우고 `08_DropLegacyMailTables` 스텝이 기존 설치의 빈 테이블을 지운다
- 본인인증 메시지 테이블(`identity_message_definitions`·`identity_message_templates`)은 7.1.0 에서 알림 정의(`category=identity`)로 통합됨 — 위 「본인인증 알림」 참조
- 운영 환경 데이터는 `Upgrade_7_0_0_beta_2` (코어) + `Upgrade_1_0_0_beta_2` (보드/이커머스) 가 `notification_definitions` + `notification_templates` 로 이관
- `drop_*_mail_templates_table` 마이그레이션이 레거시 테이블을 제거 (down 시 스키마 복원)
- 다국어 헬퍼 + 변수 치환 trait 은 `NotificationContentBehavior` 로 리네임되어 NotificationTemplate 전용으로 사용됨 (구 `MailTemplateBehavior`)
