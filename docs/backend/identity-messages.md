# 본인인증 메시지 템플릿 시스템 (Identity Messages)

> 알림 시스템과 분리된 IDV 전용 메시지 템플릿 시스템

## TL;DR (5초 요약)

```text
1. 알림 시스템(notification_*)과 완전 분리된 IDV 전용 템플릿 인프라
2. (provider_id, scope_type, scope_value) 매트릭스 키로 정의 식별 — scope_type: provider_default | purpose | policy
3. 발송 시 fallback 체인: policy:{key} → purpose:{key} → provider_default
4. 코어 시드 5건 (g7:core.mail × provider_default + 4 purposes) — 다국어 ko/en
5. MailIdentityProvider → IdentityMessageDispatcher → DbTemplateMail (메일 발송 인프라 재사용)
6. 운영자 편집 UI: 환경설정 > 본인인증 > 메시지 템플릿 (subject/body 다국어 + reset)
```

---

## 아키텍처 개요

```text
MailIdentityProvider::requestChallenge()
  ├ identity_verification_logs 레코드 생성 (status=requested)
  └ IdentityMessageDispatcher::dispatch()
        ├ IdentityMessageResolver::resolve(provider, purpose, policy_key)
        │     ├ try (provider, policy:{key})       — 가장 구체적
        │     ├ try (provider, purpose:{key})       — 목적 단위
        │     └ try (provider, provider_default)    — fallback
        ├ template.replaceVariables(data, locale)
        ├ Mail::send(DbTemplateMail) — 알림과 동일한 메일 wrapper, source='identity_message'
        └ 성공/실패 → identity_verification_logs.status 갱신
```

알림 시스템과의 분리 지점:
- `notification_definitions` / `notification_templates` 미사용
- `GenericNotification` 미사용
- 별도 모델 / 서비스 / 시더 / 관리자 API / 관리자 UI

알림 시스템과의 공유 지점:
- `DbTemplateMail` (메일 발송 wrapper) — `source='identity_message'`로 구분
- `HasUserOverrides` trait
- `LocaleRequiredTranslatable` / `TranslatableField` 검증 규칙
- `BaseApiResource` / `BaseApiCollection`

---

## 핵심 테이블

### identity_message_definitions

| 컬럼 | 설명 |
|------|------|
| `provider_id` | IDV 프로바이더 ID (예: `g7:core.mail`, `kcp`) |
| `scope_type` | `provider_default` / `purpose` / `policy` |
| `scope_value` | scope_type 별 식별자 (provider_default = 빈 문자열) |
| `name` | 다국어 표시명 (운영자 식별용 JSON) |
| `description` | 다국어 설명 |
| `channels` | 활성 채널 (현재 `["mail"]`) |
| `variables` | 사용 가능 변수 메타데이터 |
| `extension_type` / `extension_identifier` | 출처 추적 |
| `is_active` / `is_default` | 활성/시드기본 여부 |
| `user_overrides` | 운영자 수정 필드 보존 |

**유니크**: `(provider_id, scope_type, scope_value)`

### identity_message_templates

| 컬럼 | 설명 |
|------|------|
| `definition_id` | FK → `identity_message_definitions` (CASCADE) |
| `channel` | 발송 채널 (`mail`) |
| `subject` | 다국어 제목 JSON (mail 채널만 의미) |
| `body` | 다국어 본문 JSON (필수) |
| `is_active` / `is_default` | 활성/시드기본 |
| `user_overrides` | 운영자 수정 필드 보존 (subject/body/is_active) |
| `updated_by` | 수정자 FK |

**유니크**: `(definition_id, channel)`

> 알림 시스템과 달리 `recipients` 컬럼 없음 — IDV 메시지는 challenge target(이메일/전화)이 발송 대상이며, 정책별 수신자 분기 개념 없음.

### identity_message_logs (Phase 2 — 미작업)

별도 발송 로그 테이블은 만들지 않음. `identity_verification_logs.status` (sent/failed)로 충분. 필요 시 향후 추가.

---

## scope 해석 우선순위

```php
IdentityMessageResolver::resolve(
    string $providerId,
    string $purpose,
    ?string $policyKey,
    string $channel = 'mail',
): ?array  // ['definition' => ..., 'template' => ...]
```

해석 순서:

1. `policy:{policyKey}` — 가장 구체적
2. `purpose:{purpose}` — 목적 단위
3. `provider_default` — 프로바이더 fallback
4. 없으면 `null` 반환 → dispatcher가 skip

각 단계에서 `definition.is_active=true` AND `template.channel=mail` AND `template.is_active=true` 모두 통과해야 함.

---

## 발송 변수 (placeholder)

기본 정의가 노출하는 변수 메타:

| 변수 | 설명 | 적용 |
|------|------|------|
| `{code}` | 인증 코드 (text_code 흐름) | signup / self_update / sensitive_action |
| `{action_url}` | 검증 링크 URL (link 흐름) | password_reset |
| `{expire_minutes}` | 만료까지 남은 분 | 모든 정의 |
| `{purpose_label}` | 인증 목적 라벨 (다국어 해석) | 모든 정의 |
| `{app_name}` | 사이트명 | 모든 정의 |
| `{site_url}` | 사이트 URL | 모든 정의 |
| `{recipient_email}` | 수신자 이메일 | 모든 정의 |

**보안**: 평문 코드는 메일 본문 외 어디에도 저장되지 않음 — `identity_verification_logs.metadata`는 `code_hash`만 보관. `identity_message_logs` 미작업이므로 DB 평문 노출 위험 없음.

---

## 코어 시드 5종

| provider_id | scope_type | scope_value | 흐름 |
|-------------|------------|-------------|------|
| g7:core.mail | provider_default | `''` | text_code |
| g7:core.mail | purpose | signup | text_code |
| g7:core.mail | purpose | password_reset | link |
| g7:core.mail | purpose | self_update | text_code |
| g7:core.mail | purpose | sensitive_action | text_code |

**시드 위치**: `database/seeders/IdentityMessageDefinitionSeeder.php`

---

## 클래스 계층

| 클래스 | 책임 |
|--------|------|
| `App\Models\IdentityMessageDefinition` | 정의 모델 — HasUserOverrides + 캐시 무효화 |
| `App\Models\IdentityMessageTemplate` | 템플릿 모델 + IdentityMessageContentBehavior |
| `App\Models\Concerns\IdentityMessageContentBehavior` | 다국어 fallback + 변수 치환 |
| `App\Repositories\IdentityMessageDefinitionRepository` | 정의 쿼리 (인터페이스 의존) |
| `App\Repositories\IdentityMessageTemplateRepository` | 템플릿 쿼리 |
| `App\Services\IdentityMessageDefinitionService` | 캐시 + CRUD + 훅 |
| `App\Services\IdentityMessageTemplateService` | 캐시 + 편집 + reset + preview |
| `App\Services\IdentityMessageResolver` | scope fallback 체인 해석 |
| `App\Services\IdentityMessageDispatcher` | 변수 치환 + 메일 발송 + 훅 |
| `App\Extension\Helpers\IdentityMessageSyncHelper` | 시더 동기화 + cleanup |
| `App\Mail\DbTemplateMail` | 메일 wrapper (재사용) |

---

## 관리자 API

| 메서드 | URL | 권한 |
|--------|-----|------|
| GET | `/api/admin/identity/messages/definitions` | `core.admin.identity.messages.read` |
| GET | `/api/admin/identity/messages/definitions/{id}` | `read` |
| PATCH | `/api/admin/identity/messages/definitions/{id}` | `update` |
| PATCH | `/api/admin/identity/messages/definitions/{id}/toggle-active` | `update` |
| POST | `/api/admin/identity/messages/definitions/{id}/reset` | `update` |
| PATCH | `/api/admin/identity/messages/templates/{id}` | `update` |
| PATCH | `/api/admin/identity/messages/templates/{id}/toggle-active` | `update` |
| POST | `/api/admin/identity/messages/templates/{id}/reset` | `update` |
| POST | `/api/admin/identity/messages/templates/preview` | `read` |

**권한 카테고리**: `core.admin.identity.messages.{read,update}` (config/core.php).

---

## 캐시 전략

| 키 | TTL | 무효화 |
|----|-----|--------|
| `identity_message.definition.{provider}.{scope_type}.{scope_value}` | `g7_core_settings('cache.notification_ttl', 3600)` | 모델 saved/deleted (booted) |
| `identity_message.definition.all_active` | 동일 | 동일 |
| `identity_message.template.{definition_id}.{channel}` | 동일 | 동일 |

태그 기반 일괄 무효화: `Cache::flushTags(['identity_message'])`.

---

## 발송 훅

`IdentityMessageDispatcher`가 발화하는 훅 (플러그인이 가로채기/로깅/추가 발송에 활용):

| 훅 | 시점 |
|---|------|
| `core.identity.message.before_send` | 발송 직전 |
| `core.identity.message.after_send` | 발송 성공 |
| `core.identity.message.send_failed` | 발송 실패 |
| `core.identity.message.resolve_failed` | 정의/템플릿 미해석으로 skip |

---

## 모듈/플러그인 확장

### Filter 훅: `core.identity.filter_default_message_definitions`

외부 IDV provider 플러그인(KCP/PortOne 등)이 자기 메시지 정의 기본값을 코어 reset 로직에 기여:

```php
// 플러그인 Listener
public static function getSubscribedHooks(): array
{
    return [
        'core.identity.filter_default_message_definitions' => [
            'method' => 'contributeDefinitions',
            'priority' => 20,
            'type' => 'filter',
        ],
    ];
}

public function contributeDefinitions(array $definitions, array $context = []): array
{
    return array_merge($definitions, [
        [
            'provider_id' => 'kcp',
            'scope_type' => IdentityMessageDefinition::SCOPE_PROVIDER_DEFAULT,
            'scope_value' => '',
            // ...
        ],
    ]);
}
```

알림 시스템의 `core.notification.filter_default_definitions`와 동형 패턴.

---

## 운영자 UI

코어 환경설정 → "본인인증" 탭 → **"메시지 템플릿" 서브탭**.

| 위치 | 파일 |
|------|------|
| 서브탭 partial | `templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_identity_messages.json` |
| 편집 모달 | `_modal_identity_message_template_form.json` |
| i18n 키 | `templates/_bundled/sirsoft-admin_basic/lang/partial/{ko,en}/admin.json` `admin.settings.identity.messages.*` |

UI 구성:

1. 정의 목록 카드 (provider, scope, name, is_active, is_default 배지)
2. 인라인 토글 (is_active)
3. 편집 모달 — 다국어 subject/body (HtmlEditor) + 변수 가이드 + 저장/reset

---

## 언어팩 다국어 보강

`identity_messages` 는 **다국어 데이터 직접 보유 SSoT** (config/core.php 의 각 entry 가 `name`/`description`/`templates.subject`/`templates.body` 의 ko/en 배열) — lang pack seed 대상.

흐름:

```text
IdentityMessageDefinitionSeeder 실행 (코어)
    ↓
config('core.identity_messages') 로드 (getDefaultDefinitions 가 __common__ variables expand)
    ↓
applyFilters('seed.identity_messages.translations', $definitions)
    ↓
LanguagePackSeedInjector::injectIdentityMessages($definitions)
    ↓ 활성 코어 ja 언어팩의 seed/identity_messages.json 로드
    ↓ 복합 키 ({channel}.{scope_type}.{scope_value}) 매칭하여 ja 키 병합
    ↓
IdentityMessageSyncHelper::syncDefinition() — DB upsert
    ↑ user_overrides 마킹된 필드는 운영자 수정값 보존
```

모듈/플러그인 측: `ModuleManager::syncModuleIdentityMessages` / `PluginManager::syncPluginIdentityMessages` 가 동일 패턴 (`seed.{id}.identity_messages.translations` 필터 발화).

언어팩 패키지의 `seed/identity_messages.json` 은 IdentityMessageDefinitionSeeder 의 ko 데이터와 동일한 복합 키 (`{channel}.{scope_type}.{scope_value}`) 구조로 작성한다. 로케일 추가 시 동일 키 집합에 대해 해당 로케일 번역만 채워 넣으면 시더 재실행 시 자동 병합된다.

---

## 참고

- [docs/backend/identity-policies.md](identity-policies.md) — IDV 정책 시스템 (정책별 메시지는 `policy_key` scope 사용)
- [docs/backend/notification-system.md](notification-system.md) — 알림 시스템 (별개 — IDV 메시지는 본 문서 시스템 사용)
- [docs/extension/module-identity-settings.md](../extension/module-identity-settings.md) — 모듈/플러그인 IDV 설정 통합
