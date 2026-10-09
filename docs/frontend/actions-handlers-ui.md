# 액션 핸들러 - UI 인터랙션

> **메인 문서**: [actions-handlers.md](actions-handlers.md)

---

## 목차

1. [login / logout](#login--logout)
2. [openModal / closeModal](#openmodal--closemodal)
3. [showAlert / toast](#showalert--toast)
4. [confirm (액션 속성)](#confirm-액션-속성)
5. [switch](#switch)
6. [conditions](#conditions) ⭐ NEW (engine-v1.10.0+)
7. [sequence / parallel](#sequence--parallel)
8. [reloadTranslations](#reloadtranslations)
9. [showErrorPage](#showerrorpage)
10. [scrollIntoView](#scrollintoview) ⭐ NEW (engine-v1.11.0+)
11. [loadScript](#loadscript)
12. [callExternal](#callexternal)
13. [startInterval / stopInterval](#startinterval--stopinterval)
14. [ensureIdentityVerified / resolveIdentityChallenge](#ensureidentityverified--resolveidentitychallenge)
15. [실전 예시](#실전-예시)

> `login` 절에 `loginTwoFactor` · `loginTwoFactorResend` · `loginPasswordChange` · `loginPasswordChangePostpone` 가 함께 설명되어 있습니다.

---

## login / logout

### login

로그인을 처리하고 토큰을 저장합니다.

```html
<partial name="doc_example">
<json>{"version":"1.0.0","type":"submit","handler":"login","target":"admin","params":{"body":{"email":"{{form.email}}","password":"{{form.password}}"}},"onSuccess":[{"handler":"navigate","params":{"path":"/admin/dashboard"}}],"onError":[{"handler":"setError","target":"{{error.response.message}}"}],"components":[{"type":"basic","name":"Div","props":{"className":"content"}}]}</json>
</partial>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "submit",
  "handler": "login",
  "target": "admin",
  "params": {
    "body": {
      "email": "{{form.email}}",
      "password": "{{form.password}}"
    }
  },
  "onSuccess": [
    {
      "handler": "navigate",
      "params": { "path": "/admin/dashboard" }
    }
  ],
  "onError": [
    {
      "handler": "setError",
      "target": "{{error.response.message}}"
    }
  ]
}
```

### target 값

| 값 | 설명 |
|----|------|
| `admin` | 관리자 인증 |
| `user` | 사용자 인증 |

### login 의 반환값 — 2단계 인증·비밀번호 변경 단계가 있는 사이트

서버는 보안 환경설정과 회원 상태에 따라 **세 가지 형태의 200**(로그인 완료 · 2단계 인증 · 비밀번호 변경 단계)을
돌려줍니다. `onSuccess` 는 세 형태 모두에서 실행되므로, 후속 액션은 `response.two_factor_required` 와
`response.password_change_required` 로 분기해야 합니다.

| 필드 | 타입 | 설명 |
|------|------|------|
| `user` | object \| null | 로그인한 사용자. 인증번호 확인이 남았으면 `null` |
| `two_factor_required` | boolean | `true` 면 아직 로그인이 끝나지 않았습니다 |
| `challenge_id` | string | 인증번호 확인에 그대로 전달할 식별자 |
| `provider_id` | string | 인증번호를 보낸 프로바이더 |
| `expires_at` | string \| null | 인증 요청 만료 시각 (ISO8601) |
| `password_change_required` | boolean | `true` 면 비밀번호 변경 단계입니다(engine-v1.77.0+) — 필드는 [loginPasswordChange](#loginpasswordchange) 참조 |
| `dormancy_restored` | boolean | 휴면 계정이 이 로그인으로 풀렸으면 `true` (서버가 실었을 때만 있음, engine-v1.76.0+). `loginTwoFactor`·`loginPasswordChange`·`loginPasswordChangePostpone` 의 결과에도 같은 필드가 실린다 |

분기를 두지 않으면 인증이 끝나기 전에 홈으로 이동하거나, 빈 사용자 정보가 상태에 실립니다.
「2단계 인증이 아니면 성공」 으로 판정하지 말고 `password_change_required` 도 함께 봅니다 — 변경 단계 응답에는 토큰이 없습니다.

```html
<on type="submit">
  <login target="user">
    <success>
      <set-state if="{{response.two_factor_required}}" target="global">
        <field name="twoFactor">
          <field name="required" :value="true"/>
          <field name="challenge_id" value="{{response.challenge_id}}"/>
          <field name="expires_at" value="{{response.expires_at}}"/>
          <field name="code" value=""/>
        </field>
      </set-state>
      <navigate if="{{!response.two_factor_required &amp;&amp; !response.password_change_required}}" path="/"/>
    </success>
    <body email="{{form.email}}" password="{{form.password}}"/>
  </login>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "handler": "login",
  "target": "user",
  "params": { "body": { "email": "{{form.email}}", "password": "{{form.password}}" } },
  "onSuccess": [
    {
      "handler": "setState",
      "if": "{{response.two_factor_required}}",
      "params": {
        "target": "global",
        "twoFactor": {
          "required": true,
          "challenge_id": "{{response.challenge_id}}",
          "expires_at": "{{response.expires_at}}",
          "code": ""
        }
      }
    },
    {
      "handler": "navigate",
      "if": "{{!response.two_factor_required && !response.password_change_required}}",
      "params": { "path": "/" }
    }
  ]
}
```

같은 시퀀스·`onSuccess` 안에서는 방금 저장한 상태(`_global.twoFactor.*`)를 다시 읽지 않습니다 —
그 자리의 상태는 아직 갱신 전이므로 `{{response.*}}` 만 사용합니다.

### loginTwoFactor

인증번호를 확인해 로그인을 완료합니다. 성공 시 토큰이 발급되고 `{ user, password_change_required: false, dormancy_restored? }` 를 돌려줍니다.
코드가 맞아도 비밀번호 변경 단계가 남았으면 토큰 없이 `{ user: null, password_change_required: true, challenge_id, … }` 를 돌려줍니다(engine-v1.77.0+) —
성공 분기에서 `response.password_change_required` 를 먼저 봅니다.

```html
<on type="submit">
  <action handler="loginTwoFactor" target="user">
    <success>
      <set-state if="{{!response.password_change_required}}" target="global" currentUser="{{response.user}}" :twoFactor="null"/>
      <navigate if="{{!response.password_change_required}}" path="/"/>
    </success>
    <fail>
      <set-state target="global" twoFactor.error="{{error.message}}"/>
    </fail>
    <body challenge_id="{{_global.twoFactor?.challenge_id}}" code="{{_global.twoFactor?.code}}"/>
  </action>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "handler": "loginTwoFactor",
  "target": "user",
  "params": {
    "body": {
      "challenge_id": "{{_global.twoFactor?.challenge_id}}",
      "code": "{{_global.twoFactor?.code}}"
    }
  },
  "onSuccess": [
    { "handler": "setState", "if": "{{!response.password_change_required}}", "params": { "target": "global", "currentUser": "{{response.user}}", "twoFactor": null } },
    { "handler": "navigate", "if": "{{!response.password_change_required}}", "params": { "path": "/" } }
  ],
  "onError": [
    { "handler": "setState", "params": { "target": "global", "twoFactor.error": "{{error.message}}" } }
  ]
}
```

`params.body` 의 `challenge_id` 와 `code` 는 필수입니다. `target` 은 `login` 과 같은 값을 씁니다
(`user` / `admin`) — 관리자 대상은 관리자 전용 엔드포인트를 호출하고, 관리자가 아니면 `403` 이 됩니다.

### loginTwoFactorResend

인증번호를 다시 보냅니다. 서버가 **기존 인증 요청을 취소하고 새로 발행**하므로, 반환된
`challenge_id` 로 반드시 교체하고 입력란을 비워야 합니다 — 앞서 받은 번호는 더 이상 통하지 않습니다.

```json
{
  "handler": "loginTwoFactorResend",
  "target": "user",
  "params": { "body": { "challenge_id": "{{_global.twoFactor?.challenge_id}}" } },
  "onSuccess": [
    {
      "handler": "setState",
      "params": {
        "target": "global",
        "twoFactor": {
          "required": true,
          "challenge_id": "{{response.challenge_id}}",
          "expires_at": "{{response.expires_at}}",
          "code": "",
          "error": null,
          "resent": true
        }
      }
    }
  ]
}
```

반환값은 `{ two_factor_required, challenge_id, provider_id, expires_at }` 입니다.

### loginPasswordChange

로그인 비밀번호 변경 단계에서 새 비밀번호를 정해 로그인을 완료합니다(engine-v1.77.0+). 비밀번호가 만료됐거나
관리자가 정해 준 비밀번호면 `login`·`loginTwoFactor` 가 토큰 대신 이 단계를 돌려줍니다.

| 단계 응답 필드 (`login`·`loginTwoFactor`) | 타입 | 설명 |
|------|------|------|
| `password_change_required` | boolean | `true` |
| `challenge_id` | string | 이 핸들러에 그대로 전달할 식별자 (발급 후 10분) |
| `reason` | `expired` \| `admin_assigned` | 사용 기간 만료 / 관리자가 정해 준 비밀번호 |
| `can_postpone` | boolean | 「다음에 변경」 가능 여부 |
| `postpone_days` | number \| null | 미루면 다시 안내할 때까지의 일수 |
| `expired_at` | string \| null | 만료 시각 (관리자 지정이면 null) |
| `expires_at` | string \| null | 식별자 만료 시각 |

단계 정보는 `_global.passwordChange` 에 두어도 되지만, 새 비밀번호·확인 **값**은 `_local`(아래 예시는 `_local.passwordChangeInput`)에
둡니다 — `_global` 에는 민감 정보를 두지 않습니다([security.md](security.md)).

```html
<on type="submit">
  <login-password-change target="user" if="{{_global.passwordChange?.required}}">
    <success>
      <set-state target="global" currentUser="{{response.user}}" :passwordChange="null"/>
      <toast if="{{response.dormancy_restored}}" type="info" message="$t:auth.dormancy_restored"/>
      <navigate path="/"/>
    </success>
    <fail>
      <set-state target="global" passwordChange.error="{{error.message}}" passwordChange.errors="{{error.errors}}"/>
    </fail>
    <body challenge_id="{{_global.passwordChange?.challenge_id}}" password="{{_local.passwordChangeInput?.password}}" password_confirmation="{{_local.passwordChangeInput?.password_confirmation}}"/>
  </login-password-change>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "handler": "loginPasswordChange",
  "target": "user",
  "if": "{{_global.passwordChange?.required}}",
  "params": {
    "body": {
      "challenge_id": "{{_global.passwordChange?.challenge_id}}",
      "password": "{{_local.passwordChangeInput?.password}}",
      "password_confirmation": "{{_local.passwordChangeInput?.password_confirmation}}"
    }
  },
  "onSuccess": [
    { "handler": "setState", "params": { "target": "global", "currentUser": "{{response.user}}", "passwordChange": null } },
    { "handler": "toast", "if": "{{response.dormancy_restored}}", "params": { "type": "info", "message": "$t:auth.dormancy_restored" } },
    { "handler": "navigate", "params": { "path": "/" } }
  ],
  "onError": [
    { "handler": "setState", "params": { "target": "global", "passwordChange.error": "{{error.message}}", "passwordChange.errors": "{{error.errors}}" } }
  ]
}
```

- `params.body.challenge_id` 는 필수입니다. 비밀번호는 비어 있어도 서버로 보내 규칙 문구를 서버가 정합니다.
- 성공하면 서버가 **모든 기기를 로그아웃**시키고 새 토큰을 하나 발급합니다. 반환은 `{ user, password_change_required: false, dormancy_restored? }`.
- 422 의 `error.errors.password` 는 규칙 위반·현재 비밀번호와 같은 값·최근 사용 비밀번호, `error.errors.challenge_id` 는 식별자 만료·무효입니다 —
  후자는 처음부터 다시 로그인해야 하므로 단계 상태를 비우고 자격증명 단계로 돌아갑니다.
- `target` 은 `login` 과 같은 값(`user` / `admin`) — 다른 경로에서 받은 식별자는 422 입니다.

### loginPasswordChangePostpone

비밀번호 변경을 미루고(「다음에 변경」) 로그인을 완료합니다(engine-v1.77.0+). 단계 응답의 `can_postpone` 이 `true` 일 때만 서버가 받아 줍니다.

```json
{
  "handler": "loginPasswordChangePostpone",
  "target": "user",
  "params": { "body": { "challenge_id": "{{_global.passwordChange?.challenge_id}}" } },
  "onSuccess": [
    { "handler": "setState", "params": { "target": "global", "currentUser": "{{response.user}}", "passwordChange": null } },
    { "handler": "navigate", "params": { "path": "/" } }
  ]
}
```

반환값은 `{ user, password_change_required: false, postponed_until, dormancy_restored? }` 입니다. 마크업 태그는 `<login-password-change-postpone>` 입니다.

### logout

로그아웃하고 토큰을 삭제합니다.

```html
<on type="click">
  <logout>
    <success>
      <navigate path="/admin/login"/>
    </success>
  </logout>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "click",
  "handler": "logout",
  "onSuccess": [
    {
      "handler": "navigate",
      "params": { "path": "/admin/login" }
    }
  ]
}
```

---

## openModal / closeModal

모달을 열거나 닫습니다. **모달 스택**을 사용하여 중첩 모달을 지원합니다 (engine-v1.2.0+).

```html
<on type="click">
  <open-modal target="bulk_activate_confirm_modal"/>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "click",
  "handler": "openModal",
  "target": "bulk_activate_confirm_modal"
}
```

```html
<on type="click">
  <close-modal/>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "click",
  "handler": "closeModal"
}
```

**작동 원리**:
- `openModal`: `_global.modalStack`에 모달 ID를 push, `_global.activeModal`도 동시 설정
- `closeModal`: `_global.modalStack`에서 최상위 모달만 제거, 이전 모달 유지

**멀티 모달 지원** (engine-v1.2.0+):

```text
✅ 확인 모달 위에 에러 모달 중첩 표시 가능
✅ closeModal 시 최상위 모달만 닫히고 이전 모달 유지
이미 열려있는 모달은 스택에 중복 추가되지 않음
```

→ Modal 컴포넌트 JSON 구조: [modal-usage.md](./modal-usage.md)

---

## showAlert / toast

### showAlert

브라우저 기본 알림(alert)을 표시합니다.

```html
<partial name="doc_example">
<json>{"version":"1.0.0","type":"click","handler":"showAlert","target":"$t:messages.confirm_delete","components":[{"type":"basic","name":"Div","props":{"className":"content"}}]}</json>
</partial>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "click",
  "handler": "showAlert",
  "target": "$t:messages.confirm_delete"
}
```

### toast

토스트 알림을 스택 형태로 표시합니다.

```html
<on type="click">
  <toast type="success" message="$t:admin.users.modals.bulk_activate_success"/>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "handler": "toast",
  "params": {
    "type": "success",
    "message": "$t:admin.users.modals.bulk_activate_success"
  }
}
```

### toast params 구조

| 필드 | 타입 | 기본값 | 설명 |
|------|------|--------|------|
| `type` | string | "info" | 토스트 타입 (success, error, warning, info) |
| `message` | string | - | 표시할 메시지 ($t: 다국어 지원) |
| `icon` | string | 타입별 기본값 | 커스텀 아이콘 이름 |
| `duration` | number | 3000 | 자동 닫힘 시간 (ms), 0이면 자동 닫힘 비활성화 |

같은 `type`·같은 문구의 토스트가 3초 안에 이미 쌓였으면 새로 쌓지 않는다(engine-v1.78.5) — 화면 공통 데이터소스 여러 개가 같은 오류로 한꺼번에 실패할 때 한 장만 뜬다. 문구가 다르거나 3초가 지나면 종전대로 쌓인다.

---

## confirm (액션 속성)

> **주의**: `confirm`은 핸들러가 아니라 **액션 속성**입니다. `"handler": "confirm"`으로 사용하면 "Unknown action handler" 오류가 발생합니다.

액션 실행 전 브라우저 기본 확인 대화상자(`window.confirm()`)를 표시합니다. 사용자가 "확인"을 클릭하면 핸들러가 실행되고, "취소"를 클릭하면 실행을 중단합니다.

### 기본 사용법

```html
<on type="click">
  <api-call target="/api/admin/items/{{row.id}}" confirm="$t:common.confirm_delete" method="DELETE"/>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "click",
  "handler": "apiCall",
  "target": "/api/admin/items/{{row.id}}",
  "params": { "method": "DELETE" },
  "confirm": "$t:common.confirm_delete"
}
```

### 커스텀 핸들러와 함께 사용

```html
<on type="click">
  <action handler="sirsoft-ecommerce.removeCountrySetting" confirm="$t:sirsoft-ecommerce.admin.shipping_policy.form.country_tab_remove_confirm" index="{{_local.activeCountryTab ?? 0}}"/>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "click",
  "handler": "sirsoft-ecommerce.removeCountrySetting",
  "confirm": "$t:sirsoft-ecommerce.admin.shipping_policy.form.country_tab_remove_confirm",
  "params": {
    "index": "{{_local.activeCountryTab ?? 0}}"
  }
}
```

### 필드 설명

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `confirm` | string | ❌ | 확인 대화상자에 표시할 메시지 (`$t:` 다국어 지원) |

### 작동 원리

1. 액션이 트리거되면 `confirm` 속성이 있는지 확인
2. `$t:` 접두사가 있으면 다국어 번역 수행
3. `window.confirm(message)` 호출
4. 사용자가 "확인" → 핸들러 실행
5. 사용자가 "취소" → 핸들러 실행 중단 (아무 동작 없음)

### 주의사항

```text
❌ 잘못됨: "handler": "confirm" → Unknown action handler 오류
✅ 올바름: "handler": "실제핸들러", "confirm": "메시지"

❌ 잘못됨: params.onConfirm 콜백 구조
✅ 올바름: confirm 속성 + handler에 실제 실행할 핸들러 지정
```

---

## switch

조건에 따라 다른 액션을 실행합니다.

### 기본 사용법 ($args[0] 기반)

이벤트 핸들러에서 전달된 인자를 케이스 키로 사용합니다.

```html
<partial name="doc_example">
<json>{"version":"1.0.0","event":"onRowAction","type":"action","handler":"switch","cases":{"view":{"type":"click","handler":"navigate","params":{"path":"/admin/users/{{$args[1].id}}"}},"edit":{"type":"click","handler":"navigate","params":{"path":"/admin/users/{{$args[1].id}}/edit"}},"delete":{"type":"click","handler":"openModal","target":"delete_confirm_modal"}},"components":[{"type":"basic","name":"Div","props":{"className":"content"}}]}</json>
</partial>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "event": "onRowAction",
  "type": "action",
  "handler": "switch",
  "cases": {
    "view": {
      "type": "click",
      "handler": "navigate",
      "params": { "path": "/admin/users/{{$args[1].id}}" }
    },
    "edit": {
      "type": "click",
      "handler": "navigate",
      "params": { "path": "/admin/users/{{$args[1].id}}/edit" }
    },
    "delete": {
      "type": "click",
      "handler": "openModal",
      "target": "delete_confirm_modal"
    }
  }
}
```

### params.value 지원 (engine-v1.9.0+)

`params.value`를 통해 동적으로 케이스 키를 지정할 수 있습니다. 데이터 바인딩을 사용하여 플러그인/모듈 환경설정 값을 케이스 키로 활용할 수 있습니다.

```html
<on type="click">
  <switch on="{{_global.plugins['sirsoft-daum_postcode']?.display_mode ?? 'layer'}}">
    <case when="popup">
      <call-external constructor="daum.Postcode" method="open"/>
    </case>
    <case when="layer">
      <call-external-embed constructor="daum.Postcode"/>
    </case>
    <default>
      <call-external-embed constructor="daum.Postcode"/>
    </default>
  </switch>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "click",
  "handler": "switch",
  "params": {
    "value": "{{_global.plugins['sirsoft-daum_postcode']?.display_mode ?? 'layer'}}"
  },
  "cases": {
    "popup": {
      "handler": "callExternal",
      "params": { "constructor": "daum.Postcode", "method": "open" }
    },
    "layer": {
      "handler": "callExternalEmbed",
      "params": { "constructor": "daum.Postcode" }
    },
    "default": {
      "handler": "callExternalEmbed",
      "params": { "constructor": "daum.Postcode" }
    }
  }
}
```

### default 케이스 지원 (engine-v1.9.0+)

매칭되는 케이스가 없을 경우 `default` 케이스가 실행됩니다.

```html
<on type="click">
  <switch on="{{_global.theme}}">
    <case when="dark">
      <set-state colorScheme="dark"/>
    </case>
    <case when="light">
      <set-state colorScheme="light"/>
    </case>
    <default>
      <set-state colorScheme="auto"/>
    </default>
  </switch>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "handler": "switch",
  "params": { "value": "{{_global.theme}}" },
  "cases": {
    "dark": { "handler": "setState", "params": { "colorScheme": "dark" } },
    "light": { "handler": "setState", "params": { "colorScheme": "light" } },
    "default": { "handler": "setState", "params": { "colorScheme": "auto" } }
  }
}
```

### 작동 원리

1. 케이스 키 결정 (우선순위)
   - `params.value` 값 사용 (데이터 바인딩 지원)
   - `params.value`가 없으면 `$args[0]` 값 사용
2. `cases`에서 해당 키에 매칭되는 액션 정의를 찾음
3. 매칭되는 케이스가 없으면 `default` 케이스 실행
4. `default` 케이스도 없으면 아무 동작 없음

### 필드 설명

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `params.value` | string | ❌ | 케이스 키 값 (데이터 바인딩 지원, engine-v1.9.0+) |
| `cases` | object | ✅ | 케이스별 액션 정의 |
| `cases.default` | object | ❌ | 기본 케이스 (매칭 없을 때 실행, engine-v1.9.0+) |

---

## conditions

> **버전**: engine-v1.10.0+

조건에 따라 다른 액션을 실행합니다. `switch`의 확장판으로, AND/OR 그룹과 if-else 체인을 지원합니다.

### 기본 사용법 (if-else 체인)

```html
<on type="click">
  <conditions>
    <when if="{{$args[0] === 'edit'}}">
      <navigate path="/edit/{{row.id}}"/>
    </when>
    <when if="{{$args[0] === 'delete'}}">
      <open-modal target="delete_confirm_modal"/>
    </when>
    <else>
      <toast message="알 수 없는 액션"/>
    </else>
  </conditions>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "click",
  "handler": "conditions",
  "conditions": [
    {
      "if": "{{$args[0] === 'edit'}}",
      "then": { "handler": "navigate", "params": { "path": "/edit/{{row.id}}" } }
    },
    {
      "if": "{{$args[0] === 'delete'}}",
      "then": { "handler": "openModal", "target": "delete_confirm_modal" }
    },
    {
      "then": { "handler": "toast", "params": { "message": "알 수 없는 액션" } }
    }
  ]
}
```

### 타입 정의

```typescript
type ConditionExpression =
  | string                              // 단순 표현식: "{{user.isAdmin}}"
  | { and: ConditionExpression[] }      // AND 그룹: 모든 조건 true → true
  | { or: ConditionExpression[] };      // OR 그룹: 하나라도 true → true

interface ConditionBranch {
  if?: ConditionExpression;             // 조건 (없으면 else 브랜치)
  then?: ActionDefinition | ActionDefinition[];  // 실행할 액션
}
```

### 필드 설명

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `conditions` | ConditionBranch[] | ✅ | if-else 브랜치 배열 |
| `conditions[].if` | ConditionExpression | ❌ | 조건 표현식 (없으면 else 브랜치) |
| `conditions[].then` | ActionDefinition \| ActionDefinition[] | ✅ | 조건 충족 시 실행할 액션 |

### AND 조건으로 액션 실행

모든 조건이 true일 때만 해당 브랜치가 실행됩니다.

```html
<partial name="doc_example">
<json>{"version":"1.0.0","type":"click","handler":"conditions","conditions":[{"if":{"and":["{{user.isLoggedIn}}","{{user.hasPermission}}"]},"then":{"handler":"navigate","params":{"path":"/premium"}}},{"then":{"handler":"toast","params":{"type":"error","message":"접근 권한 없음"}}}],"components":[{"type":"basic","name":"Div","props":{"className":"content"}}]}</json>
</partial>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "click",
  "handler": "conditions",
  "conditions": [
    {
      "if": {
        "and": ["{{user.isLoggedIn}}", "{{user.hasPermission}}"]
      },
      "then": { "handler": "navigate", "params": { "path": "/premium" } }
    },
    {
      "then": { "handler": "toast", "params": { "type": "error", "message": "접근 권한 없음" } }
    }
  ]
}
```

### OR 조건으로 액션 실행

하나라도 true면 해당 브랜치가 실행됩니다.

```html
<partial name="doc_example">
<json>{"version":"1.0.0","type":"click","handler":"conditions","conditions":[{"if":{"or":["{{user.isAdmin}}","{{user.isManager}}"]},"then":{"handler":"navigate","params":{"path":"/admin"}}},{"then":{"handler":"navigate","params":{"path":"/user"}}}],"components":[{"type":"basic","name":"Div","props":{"className":"content"}}]}</json>
</partial>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "click",
  "handler": "conditions",
  "conditions": [
    {
      "if": {
        "or": ["{{user.isAdmin}}", "{{user.isManager}}"]
      },
      "then": { "handler": "navigate", "params": { "path": "/admin" } }
    },
    {
      "then": { "handler": "navigate", "params": { "path": "/user" } }
    }
  ]
}
```

### 중첩 AND/OR 조건

복잡한 조건 로직을 표현할 수 있습니다.

```html
<partial name="doc_example">
<json>{"version":"1.0.0","type":"click","handler":"conditions","conditions":[{"if":{"or":["{{user.isSuperAdmin}}",{"and":["{{user.isAdmin}}","{{user.department === 'sales'}}"]}]},"then":{"handler":"navigate","params":{"path":"/sales-dashboard"}}},{"then":{"handler":"navigate","params":{"path":"/home"}}}],"components":[{"type":"basic","name":"Div","props":{"className":"content"}}]}</json>
</partial>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "click",
  "handler": "conditions",
  "conditions": [
    {
      "if": {
        "or": [
          "{{user.isSuperAdmin}}",
          {
            "and": ["{{user.isAdmin}}", "{{user.department === 'sales'}}"]
          }
        ]
      },
      "then": { "handler": "navigate", "params": { "path": "/sales-dashboard" } }
    },
    {
      "then": { "handler": "navigate", "params": { "path": "/home" } }
    }
  ]
}
```

### then 배열 (순차 실행)

`then`이 배열이면 `sequence`처럼 순차적으로 실행됩니다.

```html
<on type="click">
  <conditions>
    <when if="{{$args[0] === 'delete'}}">
      <set-state target="_local" deleteTargetId="{{row.id}}"/>
      <open-modal target="delete_confirm_modal"/>
    </when>
  </conditions>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "click",
  "handler": "conditions",
  "conditions": [
    {
      "if": "{{$args[0] === 'delete'}}",
      "then": [
        { "handler": "setState", "params": { "target": "_local", "deleteTargetId": "{{row.id}}" } },
        { "handler": "openModal", "target": "delete_confirm_modal" }
      ]
    }
  ]
}
```

### switch vs conditions 비교

| 특성 | switch | conditions |
|------|--------|------------|
| 케이스 매칭 | 값 일치 (`$args[0]` 또는 `params.value`) | 조건 표현식 평가 |
| AND/OR 조건 | ❌ 미지원 | ✅ 지원 |
| 중첩 조건 | ❌ 미지원 | ✅ 지원 |
| else 브랜치 | `default` 케이스 | `if` 없는 브랜치 |
| then 배열 | ❌ 미지원 | ✅ 지원 (순차 실행) |

### switch vs conditions 선택 가이드

| 상황 | 권장 핸들러 |
|------|-------------|
| 단순 값 매칭 (edit/delete/view) | `switch` |
| 복합 조건 (AND/OR) | `conditions` |
| 중첩 조건 | `conditions` |
| 여러 액션 순차 실행 | `conditions` (then 배열) |

### DataGrid onRowAction 실전 예시

```html
<on type="action" event="onRowAction">
  <conditions>
    <when if="{{$args[0] === 'edit'}}">
      <navigate path="/products/edit/{{$args[1].id}}"/>
    </when>
    <when if="{{$args[0] === 'delete'}}">
      <set-state target="_local" deleteTargetId="{{$args[1].id}}"/>
      <open-modal target="delete_confirm_modal"/>
    </when>
    <when if="{{$args[0] === 'view'}}">
      <navigate path="/products/view/{{$args[1].id}}"/>
    </when>
    <else>
      <toast type="warning" message="알 수 없는 액션입니다."/>
    </else>
  </conditions>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "event": "onRowAction",
  "type": "action",
  "handler": "conditions",
  "conditions": [
    {
      "if": "{{$args[0] === 'edit'}}",
      "then": { "handler": "navigate", "params": { "path": "/products/edit/{{$args[1].id}}" } }
    },
    {
      "if": "{{$args[0] === 'delete'}}",
      "then": [
        { "handler": "setState", "params": { "target": "_local", "deleteTargetId": "{{$args[1].id}}" } },
        { "handler": "openModal", "target": "delete_confirm_modal" }
      ]
    },
    {
      "if": "{{$args[0] === 'view'}}",
      "then": { "handler": "navigate", "params": { "path": "/products/view/{{$args[1].id}}" } }
    },
    {
      "then": { "handler": "toast", "params": { "type": "warning", "message": "알 수 없는 액션입니다." } }
    }
  ]
}
```

---

## sequence / parallel

### sequence

여러 액션을 순차적으로 실행합니다.

```html
<on type="click">
  <set-state target="global" selectedModule="{{row}}"/>
  <open-modal target="module_install_modal"/>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "click",
  "handler": "sequence",
  "actions": [
    {
      "handler": "setState",
      "params": { "target": "global", "selectedModule": "{{row}}" }
    },
    {
      "handler": "openModal",
      "target": "module_install_modal"
    }
  ]
}
```

### 결과 전달 컨텍스트

| 변수 | 설명 |
|------|------|
| `$prev` | 직전 액션의 결과 |
| `$results` | 모든 이전 결과 배열 |
| `$results[0]` | 특정 인덱스의 결과 접근 |

### parallel

여러 액션을 **병렬로** 실행합니다.

```html
<on type="click">
  <parallel>
    <toast type="success" message="완료!"/>
    <refetch-data-source dataSourceId="modules"/>
    <refetch-data-source dataSourceId="admin_menu"/>
  </parallel>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "handler": "parallel",
  "actions": [
    { "handler": "toast", "params": { "type": "success", "message": "완료!" } },
    { "handler": "refetchDataSource", "params": { "dataSourceId": "modules" } },
    { "handler": "refetchDataSource", "params": { "dataSourceId": "admin_menu" } }
  ]
}
```

### sequence vs parallel 비교

| 특성 | sequence | parallel |
|------|----------|----------|
| 실행 방식 | 순차 | 병렬 |
| 에러 처리 | 중간 실패 시 중단 | 일부 실패해도 계속 |
| 결과 전달 | `$prev`, `$results` 사용 | 결과 전달 불가 |

### sequence 고급 동작 (engine-v1.11.0+)

#### 스텝 간 상태 동기화

sequence 내에서 `setState` 실행 후 다음 스텝은 **갱신된 상태**를 참조합니다. 이는 `_computed` 속성도 포함합니다:

```text
1. setState로 _local.price = 500 설정
2. → _computed.totalPrice 자동 재계산 (skipCache: true)
3. → 다음 스텝에서 {{_computed.totalPrice}} 참조 시 최신값 반영
```

sequence 안의 재계산도 화면 렌더와 같은 규칙을 따릅니다(engine-v1.74.0+): 정의 순서대로 계산해 앞의 computed 를 뒤의 computed 가 최신값으로 읽고, `$switch`·파이프·리터럴 computed 도 재계산 뒤 키가 사라지지 않습니다. 계산에 실패한 키는 직전 값을 유지합니다.

#### $prev와 $results 상세

| 변수 | 타입 | 설명 |
|------|------|------|
| `$prev` | any | **직전** 액션의 결과값 (직접 참조, 배열 아님) |
| `$results` | array | **모든** 이전 액션 결과의 배열 |
| `$results[0]` | any | 첫 번째 액션의 결과 |

```html
<on type="click">
  <api-call target="/api/modules/sirsoft-ecommerce/products/{{row.id}}" method="GET"/>
  <set-state target="local" productName="{{$prev.data.name}}" firstResult="{{$results[0].data.id}}"/>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "handler": "sequence",
  "actions": [
    {
      "handler": "apiCall",
      "target": "/api/modules/sirsoft-ecommerce/products/{{row.id}}",
      "params": { "method": "GET" }
    },
    {
      "handler": "setState",
      "params": {
        "target": "local",
        "productName": "{{$prev.data.name}}",
        "firstResult": "{{$results[0].data.id}}"
      }
    }
  ]
}
```

#### 커스텀 핸들러와 __g7SequenceLocalSync

커스텀 핸들러(React 컴포넌트 내부에서 등록한 핸들러)가 sequence 내에서 `_local` 상태를 변경하면, 다음 스텝에서 해당 변경이 반영되지 않을 수 있습니다. 이를 해결하기 위해 `__g7SequenceLocalSync` 메커니즘이 사용됩니다:

```text
✅ 내장 핸들러 (setState, apiCall 등): 자동으로 상태 동기화
커스텀 핸들러: __g7SequenceLocalSync 콜백을 통해 명시적으로 상태 동기화 필요
✅ 상세: g7core-api-advanced.md 참조
```

#### _isolated 상태 추적

sequence 내에서 `target: "isolated"` setState가 실행되면, 이후 스텝에서 `_isolated` 상태도 최신값으로 추적됩니다.

---

## reloadTranslations

> **Deprecated** (engine-v1.38.0+): 모듈/플러그인/템플릿 라이프사이클 onSuccess 에서는 `reloadExtensions` 사용을 권장합니다. 하위 호환을 위해 유지되며 내부적으로 `TemplateApp.reloadExtensionState()` 로 위임되어 routes/translations/layouts 가 함께 재동기화됩니다. 자세한 내용은 [`reloadExtensions`](actions-handlers-navigation.md#reloadextensions) 참조.

다국어 파일을 다시 로드합니다.

```html
<on type="click">
  <reload-translations/>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "handler": "reloadTranslations"
}
```

### 사용 사례

- 언어 설정 변경 후 번역 적용 (단독 사용)
- (deprecated) 모듈/플러그인 설치 후 새 번역 적용 → `reloadExtensions` 사용

---

## showErrorPage

> **버전**: engine-v1.6.0+

에러 페이지를 렌더링합니다. URL 변경 없이 현재 페이지에서 에러 페이지를 표시합니다.

```html
<on type="click">
  <show-error-page :errorCode="403" target="content"/>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "handler": "showErrorPage",
  "params": {
    "errorCode": 403,
    "target": "content"
  }
}
```

### showErrorPage params 구조

| 필드 | 타입 | 필수 | 기본값 | 설명 |
|------|------|------|--------|------|
| `errorCode` | number | ❌ | errorHandling 키값 | 에러 코드 |
| `target` | string | ❌ | `"content"` | `"full"` 또는 `"content"` |
| `containerId` | string | ❌ | - | 렌더링할 컨테이너 ID |
| `layout` | string | ❌ | - | 사용할 레이아웃 경로 |

---

## scrollIntoView

> **버전**: engine-v1.11.0+

특정 요소를 화면에 보이도록 스크롤합니다. MutationObserver를 통한 요소 대기와 컨테이너 내 스크롤을 지원합니다.

### 기본 사용법

```html
<on type="click">
  <scroll-into-view selector="#target_element" behavior="smooth" block="center"/>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "click",
  "handler": "scrollIntoView",
  "params": {
    "selector": "#target_element",
    "behavior": "smooth",
    "block": "center"
  }
}
```

### scrollIntoView params 구조

| 필드 | 타입 | 기본값 | 설명 |
|------|------|--------|------|
| `selector` | string | - | 스크롤할 대상 요소 CSS 선택자 (필수) |
| `scrollContainer` | string | - | 스크롤 컨테이너 선택자 (지정 시 해당 컨테이너만 스크롤) |
| `behavior` | string | `"smooth"` | 스크롤 동작 (`smooth`, `instant`, `auto`) |
| `block` | string | `"nearest"` | 수직 정렬 (`start`, `center`, `end`, `nearest`) |
| `inline` | string | `"nearest"` | 수평 정렬 (`start`, `center`, `end`, `nearest`) |
| `waitForElement` | boolean | `false` | MutationObserver로 요소 대기 |
| `timeout` | number | `2000` | waitForElement 타임아웃 (ms) |
| `delay` | number | `0` | 실행 전 지연 (ms) |
| `retryCount` | number | `0` | 재시도 횟수 |
| `retryInterval` | number | `50` | 재시도 간격 (ms) |

### 요소 대기 (waitForElement)

조건부 렌더링(`if`)으로 나타나는 요소를 대기할 때 사용합니다. MutationObserver를 사용하여 DOM에 요소가 추가될 때까지 대기합니다.

```html
<on type="click">
  <scroll-into-view selector="#loading_indicator" :waitForElement="true" :timeout="2000" block="end"/>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "handler": "scrollIntoView",
  "params": {
    "selector": "#loading_indicator",
    "waitForElement": true,
    "timeout": 2000,
    "block": "end"
  }
}
```

### 컨테이너 내 스크롤 (scrollContainer)

특정 스크롤 컨테이너 내에서만 스크롤하고 브라우저 전체 스크롤에 영향을 주지 않으려면 `scrollContainer`를 지정합니다.

```html
<on type="click">
  <scroll-into-view selector="#loading_indicator" scrollContainer="#template_list" behavior="smooth" block="end"/>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "handler": "scrollIntoView",
  "params": {
    "selector": "#loading_indicator",
    "scrollContainer": "#template_list",
    "behavior": "smooth",
    "block": "end"
  }
}
```

**작동 원리**:
- `scrollContainer` 미지정: 네이티브 `Element.scrollIntoView()` 사용 (모든 조상 스크롤 영향)
- `scrollContainer` 지정: 해당 컨테이너의 `scrollTop`만 직접 조정 (브라우저 스크롤 유지)

### 무한스크롤 로딩 인디케이터 예시

```html
<div data-ref="template_list" class="max-h-[calc(90vh-140px)] overflow-y-auto">
  <on type="scroll">
    <sequence :debounce="200">
      <switch on="{{$event.target.scrollHeight - $event.target.scrollTop <= $event.target.clientHeight + 100 &amp;&amp; _global.infiniteScroll.hasMore &amp;&amp; !_global.infiniteScroll.isLoadingMore}}">
        <case when="true">
          <set-state target="global" :infiniteScroll.isLoadingMore="true"/>
          <scroll-into-view selector="#loading_indicator" scrollContainer="#template_list" behavior="smooth" block="end" :waitForElement="true" :timeout="2000"/>
          <api-call target="/api/modules/sirsoft-ecommerce/items" method="GET">
            <success>
              <append-data-source dataSourceId="items" dataPath="data" newData="{{response.data}}"/>
            </success>
            <query page="{{_global.infiniteScroll.currentPage + 1}}"/>
          </api-call>
        </case>
      </switch>
    </sequence>
  </on>
  <loading-spinner data-ref="loading_indicator" data-if="{{_global.infiniteScroll.isLoadingMore}}" size="sm" text="$t:common.loading"/>
</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "id": "template_list",
  "type": "basic",
  "name": "Div",
  "props": {
    "className": "max-h-[calc(90vh-140px)] overflow-y-auto"
  },
  "actions": [
    {
      "type": "scroll",
      "debounce": 200,
      "handler": "sequence",
      "actions": [
        {
          "handler": "switch",
          "params": {
            "value": "{{$event.target.scrollHeight - $event.target.scrollTop <= $event.target.clientHeight + 100 && _global.infiniteScroll.hasMore && !_global.infiniteScroll.isLoadingMore}}"
          },
          "cases": {
            "true": {
              "handler": "sequence",
              "actions": [
                {
                  "handler": "setState",
                  "params": {
                    "target": "global",
                    "infiniteScroll.isLoadingMore": true
                  }
                },
                {
                  "handler": "scrollIntoView",
                  "params": {
                    "selector": "#loading_indicator",
                    "scrollContainer": "#template_list",
                    "behavior": "smooth",
                    "block": "end",
                    "waitForElement": true,
                    "timeout": 2000
                  }
                },
                {
                  "handler": "apiCall",
                  "target": "/api/modules/sirsoft-ecommerce/items",
                  "params": {
                    "method": "GET",
                    "query": { "page": "{{_global.infiniteScroll.currentPage + 1}}" }
                  },
                  "onSuccess": [
                    {
                      "handler": "appendDataSource",
                      "params": {
                        "dataSourceId": "items",
                        "dataPath": "data",
                        "newData": "{{response.data}}"
                      }
                    }
                  ]
                }
              ]
            }
          }
        }
      ]
    }
  ],
  "children": [
    {
      "id": "loading_indicator",
      "type": "composite",
      "name": "LoadingSpinner",
      "if": "{{_global.infiniteScroll.isLoadingMore}}",
      "props": {
        "size": "sm",
        "text": "$t:common.loading"
      }
    }
  ]
}
```

### block 옵션 비교

| 값 | 설명 |
|----|------|
| `start` | 요소가 컨테이너 상단에 오도록 스크롤 |
| `center` | 요소가 컨테이너 중앙에 오도록 스크롤 |
| `end` | 요소가 컨테이너 하단에 오도록 스크롤 |
| `nearest` | 가장 가까운 방향으로 최소 스크롤 (이미 보이면 스크롤 안 함) |

---

## loadScript

> **버전**: engine-v1.7.0+

외부 스크립트를 동적으로 로드합니다. 외부 서비스(Daum 우편번호, 결제 SDK 등) 연동 시 사용합니다.

`src` 는 레이아웃 `scripts[]` 와 **같은 출처 정책**을 받습니다 — same-origin 절대 경로(`/` 로 시작)이거나, 확장이 manifest(`trusted_script_hosts`)로 선언한 신뢰 호스트여야 합니다. 그 밖의 원격 URL 은 로드 전에 차단되고 액션이 실패합니다(`onError`·`errorHandling` 오류 채널로 전달). 상세: [security.md](security.md#외부-스크립트-신뢰-출처-허용목록)

```html
<on type="click">
  <load-script src="//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js" id="daum_postcode_script">
    <field name="onLoad">
      <field name="handler" value="setState"/>
      <field name="params">
        <field name="daumPostcodeLoaded" :value="true"/>
      </field>
    </field>
  </load-script>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "click",
  "handler": "loadScript",
  "params": {
    "src": "//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js",
    "id": "daum_postcode_script"
  },
  "onLoad": {
    "handler": "setState",
    "params": { "daumPostcodeLoaded": true }
  }
}
```

### loadScript params 구조

| 필드 | 타입 | 기본값 | 설명 |
|------|------|--------|------|
| `src` | string | - | 스크립트 URL (필수) |
| `id` | string | 자동 생성 | 스크립트 요소 ID |
| `async` | boolean | true | 비동기 로드 여부 |
| `defer` | boolean | false | defer 속성 |

### onLoad 콜백

스크립트 로드 완료 시 실행할 액션을 `onLoad`에 정의합니다.

```html
<on type="click">
  <load-script src="https://example.com/sdk.js">
    <field name="onLoad">
      <field name="handler" value="callExternal"/>
      <field name="params">
        <field name="constructor" value="ExampleSDK"/>
        <field name="method" value="init"/>
      </field>
    </field>
  </load-script>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "handler": "loadScript",
  "params": {
    "src": "/api/plugins/assets/vendor-plugin/dist/vendor/example-sdk/1.0.0/sdk.js"
  },
  "onLoad": {
    "handler": "callExternal",
    "params": {
      "constructor": "ExampleSDK",
      "method": "init"
    }
  }
}
```

### 중복 로드 방지

동일한 ID의 스크립트가 이미 로드되었거나 DOM에 존재하면 다시 로드하지 않고 `onLoad`만 즉시 실행합니다.

같은 스크립트를 **동시에** 요청하면 태그는 하나만 만들어지고, 두 호출자 모두 그 태그의 로드가 끝난 뒤에 완료됩니다. 로드 중인 스크립트를 "이미 있다"는 이유로 먼저 완료 처리하면, 그 호출자의 `onLoad` 가 SDK 전역이 아직 없는 시점에 실행되어 아무 일도 일어나지 않습니다.

```text
✅ 스크립트 중복 로드 자동 방지
✅ 이미 로드된 경우 onLoad 즉시 실행
✅ 동시 요청은 하나의 태그를 공유하고 각자 onLoad 실행
```

### 동의 관리(개인정보 배너)와의 관계

동의 관리 플러그인이 아직 동의받지 않은 스크립트를 차단하고 있으면, 이 액션은 스크립트를 붙이지 않고 **미완료 상태로 끝납니다**(오류가 아니라 "동의 전"이라는 상태이므로 실패로 취급하지 않습니다). `onLoad` 는 실행되지 않고, 캐시에도 기록하지 않으므로 동의 후 다시 호출하면 정상적으로 로드됩니다.

---

## callExternal

> **버전**: engine-v1.7.0+

외부 스크립트의 생성자나 메서드를 호출합니다. `loadScript`로 로드한 외부 라이브러리를 호출할 때 사용합니다.

```html
<on type="click">
  <call-external constructor="daum.Postcode" callbackEvent="postcode:complete" method="open">
    <field name="args">
      <field name="oncomplete" :value="true"/>
    </field>
  </call-external>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "type": "click",
  "handler": "callExternal",
  "params": {
    "constructor": "daum.Postcode",
    "args": { "oncomplete": true },
    "callbackEvent": "postcode:complete",
    "method": "open"
  }
}
```

### callExternal params 구조

| 필드 | 타입 | 기본값 | 설명 |
|------|------|--------|------|
| `constructor` | string | - | 호출할 생성자 경로 (필수, 예: "daum.Postcode") |
| `args` | object | {} | 생성자에 전달할 인자 객체 |
| `method` | string | - | 인스턴스 생성 후 호출할 메서드 (예: "open") |
| `methodArgs` | array | [] | 메서드에 전달할 인자 배열 |
| `callbackEvent` | string | - | 콜백 결과를 전달할 이벤트명 |
| `embedTarget` | string | - | embed 메서드 사용 시 대상 요소 선택자 |

### 콜백 처리

`args`에서 `true`로 설정된 속성은 콜백 함수로 변환됩니다. 콜백 호출 시 `callbackEvent`로 지정한 이벤트가 발생합니다.

```html
<on type="click">
  <call-external constructor="daum.Postcode" callbackEvent="postcode:complete">
    <field name="args">
      <field name="oncomplete" :value="true"/>
    </field>
  </call-external>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "handler": "callExternal",
  "params": {
    "constructor": "daum.Postcode",
    "args": {
      "oncomplete": true
    },
    "callbackEvent": "postcode:complete"
  }
}
```

위 설정은 다음과 동일합니다:

```javascript
new daum.Postcode({
  oncomplete: (data) => {
    G7Core.componentEvent.emit('postcode:complete', data);
  }
}).open();
```

### 이벤트 구독

콜백 결과는 `G7Core.componentEvent`로 전달됩니다. 컴포넌트에서 `onEvent`로 구독할 수 있습니다:

```html
<div>
  <on type="action" event="postcode:complete">
    <set-state zonecode="{{$args[0].zonecode}}" address="{{$args[0].address}}"/>
  </on>
  본문
</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "actions": [
    {
      "event": "postcode:complete",
      "type": "action",
      "handler": "setState",
      "params": {
        "zonecode": "{{$args[0].zonecode}}",
        "address": "{{$args[0].address}}"
      }
    }
  ]
}
```

### embed 모드

팝업 대신 특정 요소에 임베드하려면 `embedTarget`을 사용합니다:

```html
<on type="click">
  <call-external constructor="daum.Postcode" callbackEvent="postcode:complete" method="embed" embedTarget="#postcode_container">
    <field name="args">
      <field name="oncomplete" :value="true"/>
    </field>
  </call-external>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "handler": "callExternal",
  "params": {
    "constructor": "daum.Postcode",
    "args": { "oncomplete": true },
    "callbackEvent": "postcode:complete",
    "method": "embed",
    "embedTarget": "#postcode_container"
  }
}
```

### Daum 우편번호 연동 전체 예시

```html
<button data-ref="search_address_button">
  <on type="click">
    <load-script src="//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js" id="daum_postcode"/>
    <call-external constructor="daum.Postcode" callbackEvent="postcode:complete" method="open">
      <field name="args">
        <field name="oncomplete" :value="true"/>
      </field>
    </call-external>
  </on>
  $t:common.search_address
</button>
<div data-ref="address_form">
  <on type="action" event="postcode:complete">
    <set-state zonecode="{{$args[0].zonecode}}" address="{{$args[0].address}}" roadAddress="{{$args[0].roadAddress}}"/>
  </on>
</div>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "id": "search_address_button",
  "type": "basic",
  "name": "Button",
  "text": "$t:common.search_address",
  "actions": [
    {
      "type": "click",
      "handler": "sequence",
      "actions": [
        {
          "handler": "loadScript",
          "params": {
            "src": "//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js",
            "id": "daum_postcode"
          }
        },
        {
          "handler": "callExternal",
          "params": {
            "constructor": "daum.Postcode",
            "args": { "oncomplete": true },
            "callbackEvent": "postcode:complete",
            "method": "open"
          }
        }
      ]
    }
  ]
},
{
  "id": "address_form",
  "type": "basic",
  "name": "Div",
  "actions": [
    {
      "event": "postcode:complete",
      "type": "action",
      "handler": "setState",
      "params": {
        "zonecode": "{{$args[0].zonecode}}",
        "address": "{{$args[0].address}}",
        "roadAddress": "{{$args[0].roadAddress}}"
      }
    }
  ]
}
```

---

## startInterval / stopInterval

주기 실행 액션. `startInterval` 은 `params.actions` 목록을 `params.interval` 밀리초마다 실행하고,
`stopInterval` 은 같은 `id` 로 등록된 타이머를 해제한다. 작성에서는 실행 목록을 `<actions>` 자식
블록으로 적는다(문법 G10 — [layout-markup.md](layout-markup.md)).

```html
<on type="click">
  <start-interval id="order_poll" :interval="5000">
    <actions>
      <refetch-data-source dataSourceId="orders"/>
    </actions>
  </start-interval>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "handler": "startInterval",
  "params": {
    "id": "order_poll",
    "interval": 5000,
    "actions": [
      { "handler": "refetchDataSource", "params": { "dataSourceId": "orders" } }
    ]
  }
}
```

```html
<on type="click">
  <stop-interval id="order_poll"/>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{ "handler": "stopInterval", "params": { "id": "order_poll" } }
```

### 필드 설명

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `id` | `string` | ✅ | 타이머 식별자. `stopInterval` 이 같은 값을 받아 해제한다 (미지정 시 경고 후 무동작) |
| `interval` | `number` | ✅ | 실행 주기 (ms) — `startInterval` 전용 |
| `actions` | `Action[]` | ✅ | 매 tick 실행할 액션 목록 — `startInterval` 전용 |

### 주의사항

- **stale closure** — `startInterval` 은 등록 시점의 dispatch 컨텍스트를 클로저로 캡처한다.
  직전 `G7Core.state.set` 이 아직 commit 되지 않은 상태로 매 tick 평가될 수 있으므로, 카운트다운처럼
  최신 상태가 필요한 흐름은 `window.setInterval` 을 직접 쓰는 것이 권장된다
  ([identity-verification-ui.md](identity-verification-ui.md)).
- 페이지 전환 시 코어가 `stopAllIntervals()` 로 일괄 해제하므로 누수는 발생하지 않는다.

---

## ensureIdentityVerified / resolveIdentityChallenge

본인인증(IDV) 선제 가드. 서버가 428 을 돌려주기 전에 흐름 쪽에서 먼저 인증 여부를 확인하고,
미인증이면 인증 UI 를 띄운 뒤 통과 여부를 boolean 으로 돌려준다
(응답 후처리 경로는 [identity-guard-interceptor.md](identity-guard-interceptor.md)).

```html
<on type="click">
  <ensure-identity-verified purpose="sensitive_action" policy_key="core.account.delete">
    <success>
      <api-call target="/api/me/account/delete" method="DELETE"/>
    </success>
    <field name="target">
      <field name="email" value="{{form.email}}"/>
    </field>
  </ensure-identity-verified>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "handler": "ensureIdentityVerified",
  "params": {
    "purpose": "sensitive_action",
    "policy_key": "core.account.delete",
    "target": { "email": "{{form.email}}" }
  },
  "onSuccess": [{ "handler": "apiCall", "target": "/api/me/account/delete", "params": { "method": "DELETE" } }]
}
```

### 필드 설명

| 필드 | 타입 | 필수 | 설명 |
|------|------|------|------|
| `purpose` | `string` | ❌ | 인증 목적 (기본 `sensitive_action`) |
| `policy_key` | `string` | ❌ | 적용할 정책 키 |
| `provider_id` | `string` | ❌ | 인증 수단 지정 (미지정 시 정책 기본값) |
| `render_hint` | `string` | ❌ | 인증 UI 렌더 힌트 |
| `target` | `object` | ❌ | 인증 대상 (`{ email, phone }`) — `apiCall` 의 `identity_target` 과 같은 채널 |

`resolveIdentityChallenge` 는 외부 redirect 콜백 이후 challenge 를 해소하는 짝 핸들러다.

---

## 실전 예시

### 사용자 일괄 활성화 모달

```html
<json key="components">[{"id":"bulk_activate_confirm_modal","type":"composite","name":"Modal","props":{"title":"$t:admin.users.modals.bulk_activate_title","size":"small"},"children":[{"type":"basic","name":"P","text":"$t:admin.users.modals.bulk_activate_confirm|count={{(_global.selectedIds || []).length}}"},{"type":"basic","name":"Div","props":{"className":"flex justify-center gap-4 mt-4"},"children":[{"type":"basic","name":"Button","props":{"variant":"secondary"},"text":"$t:common.cancel","actions":[{"type":"click","handler":"closeModal"}]},{"type":"basic","name":"Button","props":{"variant":"primary"},"text":"$t:common.confirm","actions":[{"type":"click","handler":"apiCall","target":"/api/admin/users/bulk-status","params":{"method":"PATCH","body":{"ids":"{{_global.selectedIds}}","status":"active"}},"onSuccess":[{"handler":"closeModal"},{"handler":"setState","params":{"target":"global","selectedIds":[]}},{"handler":"navigate","params":{"path":"/admin/users","mergeQuery":true,"query":[]}},{"handler":"toast","params":{"type":"success","message":"$t:admin.users.modals.bulk_activate_success"}}]}]}]}]}]</json>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "id": "bulk_activate_confirm_modal",
  "type": "composite",
  "name": "Modal",
  "props": {
    "title": "$t:admin.users.modals.bulk_activate_title",
    "size": "small"
  },
  "children": [
    {
      "type": "basic",
      "name": "P",
      "text": "$t:admin.users.modals.bulk_activate_confirm|count={{(_global.selectedIds || []).length}}"
    },
    {
      "type": "basic",
      "name": "Div",
      "props": { "className": "flex justify-center gap-4 mt-4" },
      "children": [
        {
          "type": "basic",
          "name": "Button",
          "props": { "variant": "secondary" },
          "text": "$t:common.cancel",
          "actions": [
            { "type": "click", "handler": "closeModal" }
          ]
        },
        {
          "type": "basic",
          "name": "Button",
          "props": { "variant": "primary" },
          "text": "$t:common.confirm",
          "actions": [
            {
              "type": "click",
              "handler": "apiCall",
              "target": "/api/admin/users/bulk-status",
              "params": {
                "method": "PATCH",
                "body": {
                  "ids": "{{_global.selectedIds}}",
                  "status": "active"
                }
              },
              "onSuccess": [
                { "handler": "closeModal" },
                { "handler": "setState", "params": { "target": "global", "selectedIds": [] } },
                { "handler": "navigate", "params": { "path": "/admin/users", "mergeQuery": true, "query": {} } },
                { "handler": "toast", "params": { "type": "success", "message": "$t:admin.users.modals.bulk_activate_success" } }
              ]
            }
          ]
        }
      ]
    }
  ]
}
```

### 검색 필드 (Enter 키 검색)

```html
<input data-ref="search_input" type="text" placeholder="$t:admin.users.search_placeholder" value="{{_global.searchQuery || query.search || ''}}">
  <on type="change">
    <set-state target="global" searchQuery="{{$event.target.value}}"/>
  </on>
  <on type="keypress" key="Enter">
    <navigate path="/admin/users" :mergeQuery="true">
      <query search="{{_global.searchQuery}}"/>
    </navigate>
  </on>
</input>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
{
  "id": "search_input",
  "type": "basic",
  "name": "Input",
  "props": {
    "type": "text",
    "placeholder": "$t:admin.users.search_placeholder",
    "value": "{{_global.searchQuery || query.search || ''}}"
  },
  "actions": [
    {
      "type": "change",
      "handler": "setState",
      "params": {
        "target": "global",
        "searchQuery": "{{$event.target.value}}"
      }
    },
    {
      "type": "keypress",
      "key": "Enter",
      "handler": "navigate",
      "params": {
        "path": "/admin/users",
        "mergeQuery": true,
        "query": {
          "search": "{{_global.searchQuery}}"
        }
      }
    }
  ]
}
```

---

## 관련 문서

- [액션 핸들러 인덱스](actions-handlers.md)
- [네비게이션 핸들러](actions-handlers-navigation.md)
- [상태 핸들러](actions-handlers-state.md)
- [컴포넌트 이벤트](components.md#컴포넌트-간-이벤트-통신)
