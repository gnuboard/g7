# 모듈/플러그인 본인인증(IDV) 설정 통합 가이드

## TL;DR (5초 요약)

```text
1. 정책/목적/메시지: module.php::getIdentity{Policies,Purposes,Messages}() 로 declarative 선언 — Manager 가 install/update 시 자동 동기화
2. UI 탭: 모듈 환경설정에 _tab_identity_policies.json partial + 코어 API source_type=module&source_identifier=... 호출
3. 권한: {vendor-module}.identity.policies.{read,update} 모듈 자체 네임스페이스 신설 (최소 권한 원칙)
4. 편집 권한: 모듈 source 정책은 enabled/grace_minutes/provider_id/fail_mode/conditions 5 필드만 (코어가 강제), admin source 는 자유
5. 결제 등 도메인 액션 가드: scope=hook 정책 + EnforceIdentityPolicyListener 가 자동 구독 → IdentityVerificationRequiredException → HTTP 428
6. 다국어: purpose label/description, 메시지 정의 등은 모듈 i18n 표준(`{vendor-module}::identity.purposes.*`) lang 키 사용 — `__()` 자동 해석
```

## 개요

모듈/플러그인이 자기 컨텍스트의 본인인증 정책과 목적(purpose) 을 코어 IDV 인프라에 등록하고, 모듈 자체 환경설정 페이지에 정책 관리 탭을 노출하는 방법을 설명합니다. 이슈 #297 후속 작업으로 도입되었습니다.

코어 IDV 인프라의 전체 설계는 [docs/backend/identity-policies.md](../backend/identity-policies.md) 를 참조하세요.

## 백엔드 — 정책 / 목적 선언

### 정책 declarative 등록

`module.php` 또는 `plugin.php` 의 `getIdentityPolicies()` 메서드에서 정책 배열을 반환합니다. `ModuleManager::installModule` / `updateModule(--force)` 트랜잭션 내부에서 `IdentityPolicySyncHelper::syncPolicy()` 가 자동 호출되어 `identity_policies` 테이블에 upsert 됩니다.

```php
public function getIdentityPolicies(): array
{
    return [
        [
            'key' => 'sirsoft-board.post.delete',
            'scope' => 'hook',
            'target' => 'sirsoft-board.post.before_delete',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 5,
            'enabled' => false,
            'applies_to' => 'admin',
            'fail_mode' => 'block',
        ],
    ];
}
```

| 필드 | 설명 |
| ---- | ---- |
| `key` | 정책 식별자(고유). 권장 형식 `{vendor-module}.{도메인}.{액션}` |
| `scope` | `route` / `hook` / `custom` 중 하나. hook 이 가장 일반적 |
| `target` | scope 별 대상 (라우트명, 훅 이름, 커스텀 키) |
| `purpose` | IDV purpose. 코어 4 종 또는 모듈/플러그인이 등록한 신규 purpose |
| `grace_minutes` | 최근 N 분 이내 verified 재사용 허용. 0 = 매번 요구 |
| `enabled` | 기본값. 모듈 정책은 보수적으로 `false` 권장 |
| `applies_to` | `self` / `admin` / `both` |
| `fail_mode` | `block` (HTTP 428) / `log_only` (감사 기록만, 요청 통과) |

`source_type` / `source_identifier` 는 Manager 가 자동 주입하므로 선언 불필요.

### user_overrides 보존

운영자가 환경설정에서 `enabled` / `grace_minutes` / `provider_id` / `fail_mode` / `conditions` 를 수정하면 `user_overrides` JSON 컬럼에 필드명이 기록됩니다. 모듈 재설치/업데이트 시 declarative 기본값이 다시 들어와도 user_overrides 에 등록된 필드는 덮어쓰지 않습니다 — 알림 시스템의 `HasUserOverrides` 와 동형 패턴.

### 신규 purpose 등록

코어가 제공하는 4 종(`signup` / `password_reset` / `self_update` / `sensitive_action`) 외에 도메인 특화 purpose 를 도입하려면 `getIdentityPurposes()` 를 오버라이드합니다.

```php
public function getIdentityPurposes(): array
{
    return [
        'checkout_verification' => [
            // 모듈 i18n 표준 (docs/extension/module-i18n.md) 준수
            // — IdentityVerificationController::resolvePurposeText() 가 __() 로 자동 해석
            'label' => 'sirsoft-ecommerce::identity.purposes.checkout_verification.label',
            'description' => 'sirsoft-ecommerce::identity.purposes.checkout_verification.description',
            'default_provider' => null,
            'allowed_channels' => ['email', 'sms', 'ipin'],
        ],
    ];
}
```

대응되는 lang 파일: `modules/_bundled/{vendor-module}/src/lang/{ko,en}/identity.php`

```php
return [
    'purposes' => [
        'checkout_verification' => [
            'label' => '결제 시 본인 확인',
            'description' => '결제 진행 전 성인/본인 확인이 필요한 경우 사용됩니다.',
        ],
    ],
];
```

`CoreServiceProvider` 부팅 시 활성 모듈/플러그인의 결과가 `IdentityVerificationManager::registerDeclaredPurposes()` 로 병합되어 런타임 레지스트리에 등록됩니다 (DB 저장 X — 매 부팅 새로 등록되는 코드 계약).

**다국어 키 형식 권장**: 코어 4 종이 `'identity.purposes.signup.label'` 같은 lang 키 문자열을 사용하므로, 모듈도 `'{vendor-module}::identity.purposes.{key}.label'` 형태로 일관되게 작성하세요. 인라인 `['ko' => ..., 'en' => ...]` 배열도 동작하지만 번역가가 lang 파일만으로 라벨을 수정할 수 없어 비권장입니다.

**필드 명명 — `label` / `description` 만 인식**: meta 의 키는 반드시 `'label'` / `'description'` 이어야 합니다. `'label_key'` / `'description_key'` 같은 변형 명명은 controller 의 `resolvePurposeText` 가 인식하지 못해 응답에서 라벨이 raw 키로 노출되는 결함이 발생합니다. `IdentityVerificationManager::registerDeclaredPurposes` 가 legacy `label_key` / `description_key` 입력을 자동 정규화하지만 안전망일 뿐 — 신규 작성 시 표준 명명을 사용하세요.

### IDV 메시지 정의 declarative 등록 (engine-v1.46+)

정책 트리거 시 발송되는 본인인증 메일 문구를 모듈이 자기 도메인에 맞춰 등록할 수 있습니다. `getIdentityMessages()` 메서드를 오버라이드하면 `ModuleManager` / `PluginManager` 가 install/update 시 `IdentityMessageSyncHelper` 를 통해 `identity_message_definitions` / `identity_message_templates` 테이블에 자동 동기화합니다 (uninstall + deleteData=true 시 자동 정리).

```php
public function getIdentityMessages(): array
{
    return [
        [
            'provider_id' => 'g7:core.mail',
            'scope_type' => \App\Models\IdentityMessageDefinition::SCOPE_PURPOSE,
            'scope_value' => 'checkout_verification',
            'name' => ['ko' => '결제 시 본인 확인', 'en' => 'Checkout Verification'],
            'description' => ['ko' => '결제 진행 전 본인/성인 확인 인증 코드 메일', 'en' => 'Identity/adult verification code mail before checkout'],
            'channels' => ['mail'],
            'variables' => [
                ['key' => 'code', 'description' => '인증 코드'],
                ['key' => 'expire_minutes', 'description' => '만료까지 남은 분'],
                ['key' => 'app_name', 'description' => '사이트명'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'subject' => ['ko' => '[{app_name}] 결제 본인 확인', 'en' => '[{app_name}] Checkout Verification'],
                    'body' => ['ko' => '<p>인증 코드: {code}</p>', 'en' => '<p>Code: {code}</p>'],
                ],
            ],
        ],
    ];
}
```

| scope_type | 사용 시점 |
| ---------- | -------- |
| `SCOPE_PURPOSE` | purpose 단위 메시지. 신규 purpose(`checkout_verification` 등) 도입 시 필수 — 코어에 해당 purpose fallback 이 없으므로 `provider_default` 로 떨어지는 것을 방지 |
| `SCOPE_POLICY` | 특정 `policy_key` 전용 메시지. purpose 보다 우선 — 같은 purpose 라도 정책별 차별화된 문구가 필요할 때 |
| `SCOPE_PROVIDER_DEFAULT` | provider 기본 fallback. 외부 IDV provider 플러그인이 자기 default 를 등록할 때 사용 |

**언제 등록해야 하나**:

- 신규 purpose 를 도입했다면 **반드시** 그 purpose 의 메시지 정의 1건 이상 등록 (안 하면 결제 등 도메인 정보 없는 일반 fallback 발송)
- 기존 purpose(`sensitive_action` 등) 만 사용한다면 빈 배열 반환 + 의도 주석 권장 (코어 fallback 그대로 사용)
- 같은 purpose 라도 정책별로 도메인 특화 문구가 필요하면 `SCOPE_POLICY` 로 정책별 메시지 추가

`extension_type='module'`, `extension_identifier=$this->getIdentifier()` 는 Manager 가 자동 주입하므로 반환 배열에 포함하지 않습니다. 운영자가 관리자 UI(환경설정 → 본인인증 → 메시지 템플릿) 에서 편집한 필드는 `user_overrides` JSON 으로 보존되어 모듈 update 재시딩 시에도 덮어쓰이지 않습니다.

### 도메인 액션 가드 (결제·삭제 등)

`scope='hook'` 정책의 `target` 으로 지정된 훅이 발동되면 코어 `EnforceIdentityPolicyListener` 가 자동 구독해 `IdentityPolicyService::enforce()` 를 호출합니다. 미인증 + 정책 활성 시 `IdentityVerificationRequiredException` 이 throw 되며 코어 Handler 가 HTTP 428 + verification payload 응답으로 변환합니다 — 프론트 `IdentityGuardInterceptor` 가 자동으로 모달을 열어 verify 후 원 요청을 재실행합니다.

확장 개발자가 추가 작업할 것은 **자기 Service 의 결제/삭제 등 진입부에 `HookManager::doAction({target_hook_name}, ...)` 한 줄을 두는 것** 뿐입니다.

```php
public function requestPayment(Order $order, array $paymentData): array
{
    HookManager::doAction('sirsoft-ecommerce.checkout.before_payment', $order, $paymentData);
    // ... 결제 처리 ...
}
```

Listener 의 모듈 hook 동적 구독은 [app/Listeners/Identity/EnforceIdentityPolicyListener.php](../../app/Listeners/Identity/EnforceIdentityPolicyListener.php) 의 `loadDynamicHookTargets()` 가 부팅 시 `identity_policies` 테이블에서 `scope=hook` 정책의 distinct target 을 읽어 자동 처리합니다.

## 프론트 — 환경설정 탭 추가

### 탭 추가 (admin layout)

`modules/_bundled/{vendor-module}/resources/layouts/admin/admin_*_settings.json` 에 다음을 추가:

1. `data_sources` 배열에 `{vendor}IdentityPolicies` 추가 — 코어 API `/api/admin/identity/policies` 를 `source_type=module` + `source_identifier={vendor-module}` 로 호출
2. `TabNavigation.tabs` 배열에 `{ "id": "identity_policies", "label": "$t:..." }` 추가
3. `partials` 배열에 `{ "partial": "partials/.../_tab_identity_policies.json" }` 추가

### partial 골격

게시판/이커머스의 `_tab_identity_policies.json` 파일이 참고용 레퍼런스 — 안내 카드 + Desktop/Tablet 테이블(`responsive.mobile.if = false`) + Mobile 카드(`responsive.mobile.if = true`) + 인라인 enabled 토글 + [+ 정책 추가] 버튼(코어 페이지로 navigate)으로 구성됩니다.

### 인라인 토글 동작

코어 정책 관리 UI 와 동일한 패턴(commit 97655c50c):

- 컴포넌트: `Toggle` (composite, `size="sm"`)
- API: `PUT /api/admin/identity/policies/{id}` body `{ enabled }`
- `onSuccess`: `parallel(toast + refetchDataSource)`
- `onError`: `parallel(toast + refetchDataSource)` (refetch 가 실패 시 토글 원복 역할)

### 정책 추가 / 편집

운영자 자유 정책의 신규 추가/편집은 모듈 탭에서 직접 폼 모달을 열지 않고, 코어 환경설정 페이지(`/admin/settings?tab=identity&sub_tab=policies`)로 navigate 하여 코어 모달을 사용합니다. `[+ 정책 추가]` 버튼은 query 에 `source_identifier=module:{vendor-module}` 를 포함시켜 모듈 컨텍스트 귀속을 보장합니다.

```json
{
  "type": "click",
  "handler": "navigate",
  "params": {
    "path": "/admin/settings",
    "query": {
      "tab": "identity",
      "sub_tab": "policies",
      "source_identifier": "module:sirsoft-board",
      "action": "add"
    }
  }
}
```

## 권한

모듈 자체 권한 네임스페이스에 IDV 정책 관리 권한 2 종 신설 — 알림 시스템 패턴(`{vendor}.settings.read|update`) 동형.

```php
// module.php::getPermissions() 의 categories 배열에 추가
[
    'identifier' => 'identity.policies',
    'name' => ['ko' => '... 본인인증 정책', 'en' => '... Identity Policies'],
    'permissions' => [
        ['action' => 'read', 'type' => 'admin', 'roles' => ['admin'], ...],
        ['action' => 'update', 'type' => 'admin', 'roles' => ['admin'], ...],
    ],
],
```

광역 권한 `core.identity.policies.manage` 재사용은 비권장 — 게시판 운영자에게 부여하면 다른 모듈 정책까지 만질 수 있어 최소 권한 원칙 위배.

## i18n

각 모듈의 `resources/lang/partial/{ko,en}/admin/settings.json` 의 `tabs` 에 `identity_policies` 키와, root 에 `identity` 섹션을 추가합니다. 키 구성은 게시판/이커머스 partial 을 참고하세요 — `intro_title`, `intro`, `list_title`, `add_policy`, `empty`, `edit`, `grace_minutes`, `enable_success`, `disable_success`, `toggle_failed`, `col.*`, `scope.*`, `source.*` (이커머스만 `purposes_section_title`, `allowed_channels` 추가).

## 테스트

### 백엔드 — 정책 declaration 검증

`tests/Feature/Identity/{Vendor}IdentityPolicyDeclarationTest.php`:

- `module.php::getIdentityPolicies()` 결과가 `IdentityPolicySyncHelper` 를 통해 source_type=module 컨텍스트로 적재
- 운영자가 enabled 토글 후 재동기화해도 user_overrides 가 보존
- `cleanupStalePolicies()` 가 제거된 정책을 정리

### 백엔드 — 결제 등 가드 검증 (해당 시)

- Service 진입 시 정책 hook 이 발동
- `IdentityPolicyService::enforce()` 가 정책 활성+미인증 시 `IdentityVerificationRequiredException` throw
- `EnforceIdentityPolicyListener::getSubscribedHooks()` 가 모듈 hook target 을 동적 구독

## 외부 IDV provider 플러그인 — 메시지 정의 기여

외부 IDV provider 플러그인(KCP / PortOne / 토스인증 / 자체 메일 provider 등)이 자기 메일/SMS 문구의 기본값을 코어에 기여하는 패턴.

### Filter 훅: `core.identity.filter_default_message_definitions`

코어 reset 로직이 운영자가 "기본값으로 복원" 클릭 시 시드 데이터를 모으는데, 플러그인이 자기 정의를 추가할 수 있도록 노출됩니다. 알림 시스템의 `core.notification.filter_default_definitions` 와 동형 패턴.

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
            'scope_type' => \App\Models\IdentityMessageDefinition::SCOPE_PROVIDER_DEFAULT,
            'scope_value' => '',
            'extension_type' => 'plugin',
            'extension_identifier' => 'sirsoft-kcp',
            'name' => ['ko' => 'KCP 본인 확인 (기본)', 'en' => 'KCP Verification (default)'],
            'channels' => ['mail'],
            'variables' => [
                ['key' => 'code', 'description' => '인증 코드'],
                ['key' => 'expire_minutes', 'description' => '만료 분'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'subject' => ['ko' => '[KCP] 본인 확인', 'en' => '[KCP] Verification'],
                    'body' => ['ko' => '<p>코드: {code}</p>', 'en' => '<p>Code: {code}</p>'],
                ],
            ],
        ],
    ]);
}
```

플러그인 자체 시더로도 동일 구조를 등록하면 설치 직후 즉시 발송 가능. 운영자는 환경설정 → 본인인증 → 메시지 템플릿 서브탭에서 다국어 제목/본문을 편집할 수 있습니다.

상세: [docs/backend/identity-messages.md](../backend/identity-messages.md)

## 외부 IDV provider 플러그인 — Extension Point 사용 (engine-v1.46.0+)

KCP / PortOne / 토스인증 / Stripe Identity 등 외부 IDV provider 를 G7 에 붙일 때 — **프론트엔드 코어 변경 없이 G7 표준 Extension Point 패턴**으로 자기 SDK UI 를 주입하면 됩니다. 다음 우편번호 (`sirsoft-daum_postcode`) / CKEditor5 (`sirsoft-ckeditor5`) 와 동일한 방식.

### 모듈/플러그인이 새 정책을 추가할 때 프론트엔드 작업이 필요 없음

코어 모달(`_identity_challenge_modal.json`) 이 `text_code` / `link` 두 render_hint 를 기본 처리하므로, 새 정책의 purpose 가 기존 render_hint 중 하나로 매핑되면 **프론트엔드 추가 작업 없이 동일한 모달이 자동 적용**됩니다.

### 외부 provider 가 자기 launcher 를 작성하는 경우 — SSoT 스키마 준수 의무

provider 가 자체 launcher / 모달 파셜을 추가하는 경우 (PortOne / KCP / 토스인증 등 SDK 가 모달이 아닌 자기 팝업/리다이렉트로 동작하는 케이스), **`_global.identityChallenge` 네임스페이스 스키마를 그대로 준수**해야 코어 모달 / 풀페이지 / 재전송 / 카운트다운과 호환됩니다.

특히 다음 필드는 launcher 가 반드시 채워야 합니다:

- `target` (`{ email?, phone? } | null`) — 첫 challenge 시작 body 에 동봉한 그 target 을 SSoT 에 저장. 누락 시 모달의 재전송 액션이 백엔드에서 422 `missing_target` 반환.
- `expires_at` (ISO8601) — 카운트다운 기준. launcher 가 `window.setInterval` 으로 매 초 `remainingSeconds` 를 직접 갱신 권장 (코어 `startInterval` 핸들러는 stale closure 위험).

상세 SSoT 표 + launcher 작성 체크리스트:

- [identity-verification-ui.md](../frontend/identity-verification-ui.md) "_global.identityChallenge 네임스페이스 스키마 (CONTRACT)" 섹션
- [template-idv-bootstrap.md](template-idv-bootstrap.md) "launcher 작성 필수 항목 체크리스트"

```text
새 정책 추가 → getIdentityPolicies() 선언 → DB 동기화 → 모달 자동 적용
```

### 새 render_hint / 외부 SDK 가 필요한 경우 — Extension Point 슬롯 사용

코어 모달은 다음 슬롯을 노출합니다:

- `identity_provider_ui:text_code` — OTP 코드 입력 슬롯 (코어 default)
- `identity_provider_ui:link` — 링크 안내 슬롯 (코어 default)
- `identity_provider_ui:provider` — provider 별 SDK 주입 슬롯 (비어있음)

플러그인 extension JSON 예시 (`plugins/{id}/resources/extensions/identity-provider.json`):

```json
{
  "extension_point": "identity_provider_ui:provider",
  "scripts": [
    { "src": "https://sdk.example.com/v2.js", "id": "vendor_sdk_v2" }
  ],
  "components": [
    {
      "name": "Button",
      "if": "{{_global.identityChallenge?.provider_id === 'vendor.method'}}",
      "events": {
        "onClick": {
          "actions": [
            {
              "handler": "callExternalEmbed",
              "params": {
                "constructor": "VendorSdk.IdentityVerification",
                "config": { "channelKey": "..." },
                "callbackAction": [
                  {
                    "handler": "resolveIdentityChallenge",
                    "params": { "result": "verified", "token": "{{result.verification_token}}" }
                  }
                ]
              }
            }
          ]
        }
      }
    }
  ]
}
```

| 인프라 | 위치 | 용도 |
| --- | --- | --- |
| 레이아웃 `scripts` 필드 | `LayoutScript` | 외부 SDK URL 자동 로드 (id 기반 dedupe) |
| `extension_point` + scripts 병합 | `LayoutExtensionService` | 슬롯에 컴포넌트 + scripts 동시 주입 |
| `callExternalEmbed` 핸들러 | `ActionDispatcher.handleCallExternalEmbed` | SDK 인스턴스 layer/popup + callbackAction/callbackSetState |
| `resolveIdentityChallenge` 핸들러 | `ActionDispatcher` (engine-v1.46.0+) | 모달/풀페이지/SDK callback 이 launcher 에 결과 통보 |

### 비동기/외부 redirect provider 통합

provider 가 webhook 또는 redirect 콜백으로 결과를 보내는 경우 코어 비동기 인프라 활용:

- `GET /api/identity/challenges/{id}` 폴링 (Processing 상태 추적)
- `POST /api/identity/callback/{providerId}` 외부 redirect 콜백 수신
- 상세: [docs/backend/identity-policies.md](../backend/identity-policies.md) 11. 비동기·외부 redirect 플러그인 통합

## 참고 파일

- 게시판 모듈 구현 예: [modules/_bundled/sirsoft-board/module.php](../../modules/_bundled/sirsoft-board/module.php), [_tab_identity_policies.json](../../modules/_bundled/sirsoft-board/resources/layouts/admin/partials/admin_board_settings/_tab_identity_policies.json)
- 이커머스 모듈 구현 예 (purpose 등록 + 결제 가드): [modules/_bundled/sirsoft-ecommerce/module.php](../../modules/_bundled/sirsoft-ecommerce/module.php)
- 코어 IDV 인프라 가이드: [docs/backend/identity-policies.md](../backend/identity-policies.md)
- 코어 정책 관리 partial 레퍼런스: [_tab_identity_policies.json (코어)](../../templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_identity_policies.json)
- 모달 UI 표준 + Extension Point 슬롯: [../frontend/identity-verification-ui.md](../frontend/identity-verification-ui.md)
- 외부 템플릿 launcher 등록 가이드: [template-idv-bootstrap.md](template-idv-bootstrap.md)
- 외부 SDK 통합 사례: [`sirsoft-daum_postcode/resources/extensions/`](../../plugins/_bundled/sirsoft-daum_postcode/resources/extensions/), [`sirsoft-ckeditor5/resources/extensions/`](../../plugins/_bundled/sirsoft-ckeditor5/resources/extensions/)
