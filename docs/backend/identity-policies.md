# 본인인증 정책 시스템 (Identity Policies)

> **버전**: 7.0.0-beta.4 신설
> **위치**: `app/Services/IdentityPolicyService.php`, `app/Http/Middleware/EnforceIdentityPolicy.php`, `app/Listeners/Identity/EnforceIdentityPolicyListener.php`, `config/core.php` (`identity_policies` 블록)

## TL;DR (5초 요약)

```text
회원가입·비밀번호 재설정·민감 작업 등 모든 IDV 강제 시점은 IdentityPolicy 단일 진실로 통합
정책 = (scope, target, purpose, applies_to, conditions, enabled, grace, fail_mode) 9개 필드
미들웨어 `identity.policy:KEY` 부착 또는 `EnforceIdentityPolicyListener` 의 hook scope 매칭으로 자동 enforce
applies_to 는 permission 기반 admin 판정 (User::isAdmin() — type='admin' 권한 보유 역할 검사)
회원가입 단계 분기는 `conditions.signup_stage` 키로 표현 (before_submit / after_create)
```

## 1. 정책의 구성

`identity_policies` 테이블의 한 행 = 한 정책. 핵심 필드:

| 필드 | 의미 | 값 |
|------|------|---|
| `key` | 고유 식별자 (시드 기준) | `core.auth.signup_before_submit` |
| `scope` | 매칭 단위 | `route` (라우트 미들웨어) / `hook` (action 훅) |
| `target` | scope 별 대상 식별자 | route name 또는 hook name |
| `purpose` | IDV 목적 | `signup` / `password_reset` / `self_update` / `sensitive_action` 등 |
| `provider_id` | 강제할 IDV provider | `null` 이면 매니저 default |
| `applies_to` | 강제 대상 사용자군 | `self` (관리자 제외) / `admin` (관리자만) / `both` |
| `enabled` | 활성 여부 | bool |
| `grace_minutes` | 통과 유예 시간 | 0 = 매번 요구, n = n분 내 verified 있으면 통과 |
| `fail_mode` | 실패 시 동작 | `block` (예외) / `log_only` (감사 로그만) |
| `priority` | 매칭 우선순위 | 높을수록 먼저 평가 |
| `conditions` | 추가 매칭 조건 (JSON) | `http_method` / `changed_fields` / `user_role` / `signup_stage` |

## 2. 두 가지 enforce 경로 (모두 동적 — 정책 DB toggle 만으로 즉시 효과)

### 2.1 라우트 자동 매핑 (scope=route)

`EnforceIdentityPolicy` 미들웨어가 [bootstrap/app.php](../../bootstrap/app.php) 에서 `appendToGroup('api', ...)` 로 등록되어 모든 API 요청에 적용됩니다. 매 요청마다 `IdentityPolicyRepository::getRouteScopeIndex()` 가 캐시된 `[route_name => Collection<IdentityPolicy>]` 맵에서 현재 라우트명으로 매칭 정책을 찾아 `enforce()` 호출. 무매칭 라우트는 즉시 통과 (O(1)).

```text
정책 DB 에서 enable 토글 → IdentityPolicy 모델 saved 이벤트 → CacheInterface::flushTags(['identity_policy']) → 다음 요청부터 즉시 반영
```

**라우트 코드 수정 불필요**:

```php
// routes/api.php — 미들웨어 명시 등록 없음
Route::post('register', [UserAuthController::class, 'register'])
    ->name('api.auth.register');  // ← 자동 매핑이 'api.auth.register' → core.auth.signup_before_submit 정책 매칭
```

**명시 형태도 backward compat 으로 보존** — 외부 모듈/플러그인이 자기 정책 키를 강제하고 싶을 때:

```php
Route::put('/sensitive', [...])->middleware('identity.policy:vendor.module.sensitive_action');
```

자동 매핑은 정책의 `target` 컬럼을 라우트 이름과 1:1 매칭. brace expansion 지원: `target='api.admin.{modules,plugins}.uninstall'` → 두 라우트명 모두 매핑.

### 2.2 훅 리스너 (scope=hook)

`EnforceIdentityPolicyListener::loadDynamicHookTargets()` 가 부팅 시 DB 의 `scope='hook'` 정책 target 을 자동 구독. 훅 이름이 매칭하면 `enforce()` 호출. Service/잡/Artisan 등 라우트 외 진입점도 일괄 보호.

scope=route 가 자동 매핑으로 동일한 "DB toggle = 즉시 효과" 모델을 따르므로, 두 경로 모두 운영자가 admin UI 에서 정책을 토글하는 것만으로 즉시 보안 정책이 변경됩니다 — 라우트/Service 코드 수정 불필요.

### 2.3 catch-all 안전망 — IdentityVerificationRequiredException 의 \Error 상속

코어/모듈/플러그인 컨트롤러 다수가 `try { ... } catch (\Exception $e) { ... }` 로 자체 응답 변환을 합니다. IDV 예외가 `\Exception` 자식이면 그 catch-all 에 포획되어 422 일반 에러로 강등 → 프론트가 모달을 띄우지 못합니다.

[IdentityVerificationRequiredException](../../app/Exceptions/IdentityVerificationRequiredException.php) 은 의도적으로 `\Error` 를 상속하여 `catch (\Exception)` 을 우회합니다. PHP 의 `\Error` 와 `\Exception` 은 별도 계층이며 `\Throwable` 만 공통 부모. Laravel 글로벌 핸들러의 `render(Throwable)` 콜백은 정상 매칭되어 428 응답 발급.

**라우트 작성 시 별도 안전망 코드 작성 불필요** — 어떤 catch-all 패턴을 쓰더라도 IDV 흐름은 항상 글로벌 핸들러까지 도달.

## 3. `applies_to` 분기 (permission 기반)

`IdentityPolicyService::isAdminContext()` 가 admin 여부 판정:

```text
1. context['user_is_admin'] 가 명시되어 있으면 그 값 (미들웨어 fast path)
2. User::isAdmin() — type='admin' 권한을 보유한 역할이 1개라도 있으면 true
```

- `applies_to='self'` + admin → enforce **하지 않음** (관리자 제외)
- `applies_to='admin'` + 일반 사용자 → enforce **하지 않음** (관리자만 강제)
- `applies_to='both'` → 모두 enforce

> ⚠️ `context['user_roles']` 는 `conditions.user_role` 매칭 전용이며 admin 판정 입력으로는 사용하지 않습니다 (role identifier 와 권한 보유는 별개의 개념).

## 4. `conditions` 매칭 키

`policyMatchesContext()` 가 검사하는 4종 키 — 모두 명시된 조건이 통과해야 정책이 매칭됨:

| 키 | 컨텍스트 키 | 예시 |
|---|------------|------|
| `http_method` | `context['http_method']` | `['POST']` |
| `changed_fields` | `context['changed_fields']` | `['email', 'phone']` (교집합 1개 이상) |
| `user_role` | `context['user_roles']` | `['admin']` (교집합 1개 이상) |
| `signup_stage` | `context['signup_stage']` | `'before_submit'` 또는 `'after_create'` |

### 4.1 `signup_stage` 사용 사례 — 회원가입 단계 분기

| 단계 | 정책 KEY | scope/target | enabled 시 동작 |
|------|----------|--------------|---------------|
| 가입 제출 전 | `core.auth.signup_before_submit` | `route` / `api.auth.register` | `verification_token` 검증 룰 자동 주입 + 미들웨어 enforce |
| 가입 후 활성화 전 | `core.auth.signup_after_create` | `hook` / `core.auth.after_register` | 사용자를 `PendingVerification` 으로 생성 + challenge 자동 발행 |

두 정책 모두 비활성이면 일반 가입 흐름 (Active 사용자 즉시 생성, IDV 없음).

## 5. filter 훅 — `core.identity.resolve_policy`

플러그인이 정책 해석을 가로채기 위한 훅:

```php
HookManager::addFilter(
    'core.identity.resolve_policy',
    fn (?IdentityPolicy $policy, string $scope, string $target, array $context): ?IdentityPolicy
        => /* 다른 IdentityPolicy 인스턴스 또는 그대로 반환 */,
);
```

**보안**: 훅이 `IdentityPolicy` 외 타입(`null` 등)을 반환하면 원본 정책이 그대로 유지됩니다 (silent retention). 의도적으로 정책을 변경하려면 반드시 `IdentityPolicy` 인스턴스를 반환할 것.

## 6. 시드 정책 (코어 기본 9종, 모두 `core.*` namespace)

`config/core.php` `identity_policies` 블록에 선언. `IdentityPolicySeeder` 가 동기화하며 `HasUserOverrides` 트레이트가 운영자 수정값을 보존합니다.

| 정책 KEY | 기본 enabled | 용도 |
|----------|--------------|------|
| `core.auth.signup_before_submit` | `false` | 가입 제출 전 IDV (운영자 opt-in) |
| `core.auth.signup_after_create` | `false` | 가입 후 활성화 전 IDV (운영자 opt-in) |
| `core.auth.password_reset` | `false` | 비밀번호 재설정 IDV (운영자 opt-in) |
| `core.profile.password_change` | `true` | 로그인 상태 비밀번호 변경 IDV |
| `core.profile.contact_change` | `true` | 이메일/전화 변경 IDV (`changed_fields` 매칭) |
| `core.account.withdraw` | `true` | 계정 탈퇴 IDV |
| `core.admin.app_key_regenerate` | `false` | App Key 재생성 (관리자 한정) |
| `core.admin.user_delete` | `false` | 사용자 삭제 (관리자 한정) |
| `core.admin.extension_uninstall` | `false` | 모듈/플러그인 제거 (관리자 한정) |

## 7. 모듈/플러그인이 정책 추가하는 방법

```php
// modules/{vendor}-{name}/module.php
public function getIdentityPolicies(): array
{
    return [
        [
            'key' => 'sirsoft-ecommerce.checkout.high_value',
            'scope' => 'hook',
            'target' => 'sirsoft-ecommerce.order.before_create',
            'purpose' => 'sensitive_action',
            'enabled' => false,
            'applies_to' => 'self',
            'fail_mode' => 'block',
            'grace_minutes' => 5,
        ],
    ];
}
```

확장 활성화 시 `IdentityPolicySyncHelper` 가 자동으로 DB 에 동기화. `cleanupStalePolicies` 가 미선언 정책을 자동 제거합니다 (운영자가 만든 `source_type='admin'` 정책은 영향 없음).

## 8. 잘못된 패턴 (DO NOT)

```text
❌ config('settings.identity.enabled') / 'signup.mode' 직접 read — 정책 시스템으로 통합되어 더 이상 존재하지 않음
❌ 'admin' role identifier 직접 가정한 admin 판정 — User::isAdmin() / context['user_is_admin'] 사용
❌ filter 훅 'core.identity.resolve_policy' 에서 null 반환으로 정책 우회 — 차단됨, 원본 정책 유지
❌ 정책 KEY 를 코드에 하드코딩 — config/core.php 또는 확장의 getIdentityPolicies() 에 선언
❌ DB 시뱀 직접 변경으로 정책 추가 — Seeder 재실행 시 cleanup 으로 삭제됨
```

## 8.1 언어팩 적용 비대상 (의도된 설계)

`identity_policies` 는 **lang pack seed 대상이 아니다**.

근거:
- `IdentityPolicy` 모델 fillable 에 `name` / `description` 등 **다국어 필드 부재** (key/scope/target/purpose 등 시스템 식별자만 보유)
- config/core.php 의 각 정책 entry 도 다국어 데이터 직접 보유하지 않음 (정책 키 자체가 식별자)
- 운영자 가시 라벨은 `templates/_bundled/sirsoft-admin_basic/lang/partial/{locale}/admin.json::identity.policy.*` i18n 키로 처리 → 언어팩 활성화 시 템플릿 lang pack 으로 자동 ja 표시

따라서 별도 lang pack seed 인프라(`seed/identity_policies.json` 등) 는 추가하지 않는다 (config 자체가 식별자 SSoT 이므로 번역 대상 없음).

## 9. 관련 코드 진입점

- `app/Services/IdentityPolicyService.php` — `resolve()` / `enforce()` / `isAdminContext()` / `policyMatchesContext()`
- `app/Http/Middleware/EnforceIdentityPolicy.php` — 라우트 단계 enforce + `user_is_admin` / `target_email` 컨텍스트 주입
- `app/Listeners/Identity/EnforceIdentityPolicyListener.php` — 훅 단계 enforce
- `app/Extension/Helpers/IdentityPolicySyncHelper.php` — Seeder 가 사용하는 upsert + cleanup
- `app/Models/IdentityPolicy.php` — `HasUserOverrides` 트레이트로 운영자 수정값 보존
- `config/core.php` `identity_policies` — 코어 기본 정책 선언

## 9.1 정책 ↔ 메시지 템플릿 연계

정책이 트리거하는 본인인증 메일 문구는 별도의 IDV 메시지 템플릿 시스템에서 관리합니다 (알림 시스템과 분리). `IdentityMessageDispatcher` 가 정책 컨텍스트를 받아 가장 구체적인 템플릿을 fallback 체인으로 해석합니다.

```text
policy:{policy_key}  →  purpose:{purpose}  →  provider_default
```

- 정책별 커스텀 문구가 필요하면 `(provider_id, scope_type='policy', scope_value=$policy->key)` 정의를 추가
- 그 외에는 기본 5종(provider_default + 4 purposes)이 자동 적용
- 운영자 편집 UI: 환경설정 → 본인인증 → "메시지 템플릿" 서브탭

상세: [identity-messages.md](identity-messages.md)

## 10. 프론트엔드 통합 (engine-v1.46.0+)

백엔드 정책 enforce → 428 응답을 프론트엔드가 가로채 모달/풀페이지로 본인 확인 흐름을 진입시키는 인프라:

- 코어 인터셉터: [`resources/js/core/identity/IdentityGuardInterceptor.ts`](../frontend/identity-guard-interceptor.md)
- 모달 UI 표준 + Extension Point 슬롯: [identity-verification-ui.md](../frontend/identity-verification-ui.md)
- 외부 템플릿 launcher 등록 가이드: [template-idv-bootstrap.md](../extension/template-idv-bootstrap.md)
- 모듈/플러그인 IDV 정책/목적 등록 + 외부 provider 통합: [module-identity-settings.md](../extension/module-identity-settings.md)

핵심 흐름:

1. 백엔드 미들웨어/리스너가 정책 위반 감지 시 `IdentityVerificationRequiredException` throw
2. `Handler` → `ResponseHelper::identityRequired()` 가 HTTP 428 + `verification` payload 응답
3. 프론트엔드 `IdentityGuardInterceptor.handle` 이 launcher 호출 → 모달 → verify → return_request 재실행
4. 재실행 요청은 `?verification_token=...` query 자동 부착 → `IdvTokenRule` 통과

## 11. 비동기·외부 redirect 플러그인 통합 (engine-v1.46.0+)

Stripe Identity / 토스인증 push / 외부 redirect provider 등 클라이언트가 verify 즉시 응답을 받지 못하는 흐름을 위한 백엔드 인프라:

### 11.1 `IdentityVerificationStatus.Processing` 상태값

비동기 검증 진행 중 상태. `Sent` → `Processing` → `Verified|Failed|Expired` 전이.

### 11.2 `GET /api/identity/challenges/{id}` — 폴링 엔드포인트

클라이언트가 challenge 의 공개 상태를 조회. 시도 횟수·코드 본체·내부 metadata 는 노출하지 않고 다음 필드만:

```json
{
  "success": true,
  "data": {
    "id": "uuid",
    "status": "pending|processing|verified|failed|expired|cancelled",
    "render_hint": "text_code|link|external_redirect|...",
    "expires_at": "2026-04-27T10:15:00+00:00",
    "public_payload": { "code_length": 6 }
  }
}
```

- 라우트: `api.identity.challenges.show`
- throttle: 30/1min
- optional.sanctum (비로그인 가입 흐름도 폴링 가능)

### 11.3 `POST /api/identity/callback/{providerId}` — 외부 redirect 콜백 수신

외부 IDV provider 가 사용자 브라우저를 우리 서버로 다시 보내는 진입점. 처리 흐름:

1. body/query 에서 `challenge_id` 추출 (FormRequest `IdentityCallbackRequest`)
2. `IdentityVerificationService::handleProviderCallback($providerId, $challengeId, $input)` 위임
3. provider 식별자 일치 검증 — 불일치 시 `WRONG_PROVIDER` failure
4. provider 의 `verify($challengeId, $input, $context)` 위임 — 일반 verify 와 동일 경로 (after_verify 훅 발화 등)
5. 성공 + `?return=` 안전한 same-origin URL 있음 → 302 → `{return}?verification_token=...`
6. 성공 + return 없음 → 200 JSON `{ verification_token }`
7. 실패 + return 안전 → 302 → `{return}?identity_error={failure_code}`
8. 실패 + return 없음 → 422 JSON

Open redirect 차단: `isSafeReturnUrl()` 가 절대 URL 의 host 가 앱 host 와 일치하는지 검증. 프로토콜 상대 URL(`//evil.example.com`) 도 차단.

- 라우트: `api.identity.callback`
- throttle: 30/1min
- optional.sanctum (외부 redirect 시 세션이 손실되었을 수 있음)

### 11.4 외부 IDV provider 가 비동기 인프라를 사용하는 시퀀스

```text
[클라이언트] launcher 호출 → POST /api/identity/challenges
  ↓ 응답 redirect_url 포함
[클라이언트] sessionStorage stash + window.location = redirect_url
  ↓
[provider 도메인] 사용자 인증 진행
  ↓
[provider → 우리 서버] POST /api/identity/callback/{providerId}?return=https://...
  ↓
[handleProviderCallback] verify 위임 → verification_token 발급
  ↓ 302 redirect to return URL with verification_token query
[클라이언트 원 페이지] sessionStorage stash 복원 + 원 요청 재실행 (token 자동 동봉)
```
