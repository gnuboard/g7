# Saml API 레퍼런스

> **소유**: plugin `sirsoft-sso` · **생성**: 손 작성(1.0.0, 코드 근거). @generated 블록은 다음 `php artisan api:docgen` 재생성 때 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 웹 라우트다(JSON API 아님). SAML SP 주소 셋 — 메타데이터(XML), ACS(로그인 응답 수신), SLS(단일 로그아웃)
2. ACS 와 SLS 는 IdP 가 보내는 교차 사이트 요청이라 CSRF 면제. 위조 방어는 RelayState(state)와 묶음 쿠키, IdP-initiated 허용 설정, 응답 1회 사용, 서명
3. ACS 결과는 OIDC 콜백과 같은 302 이동이다(/login?login_handoff=, ?sso_pending=, ?sso_link=, ?sso_error=)
4. SLS 는 서명된 요청만 받는다. 형식이 틀리면 빈 본문 400
5. SAML 이 아닌 IdP 키, 꺼진 모의 IdP 는 메타데이터와 SLS 가 404
```

---


### POST /plugins/sirsoft-sso/saml/{idp}/acs
<!-- @generated:start:web.plugins.sirsoft-sso.saml.acs -->
- **라우트명**: `web.plugins.sirsoft-sso.saml.acs`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\SamlController@acs`
- **인증/권한**: 공개 (인증 불필요) — 응답 서명 + state 와 묶음 쿠키(SP-initiated) 또는 IdP-initiated 허용, CSRF 면제
- **요청 제한**: `throttle:60,1,sirsoft-sso.saml-acs`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | IdP 키 |
| SAMLResponse | body | string | 예 | base64, 크기 상한 있음 | IdP 의 서명된 응답 |
| RelayState | body | string | 아니오 | 512자 | SP 가 시작한 로그인이면 state. IdP-initiated 면 돌아갈 같은 출처 경로(아니면 `/`) |
| g7_sso_bind | cookie | string | 조건부 | — | SP 가 시작한 로그인이면 시작한 브라우저의 묶음 쿠키 |

`SAMLResponse` 가 없거나 너무 크거나 `RelayState` 가 512자를 넘으면 응답을 읽지 않고 `saml_invalid` 로 보낸다.

**요청 예시**

```http
POST /plugins/sirsoft-sso/saml/{idp}/acs HTTP/1.1
Host: api.example.com
Content-Type: application/x-www-form-urlencoded
Cookie: g7_sso_bind={BIND}

SAMLResponse={BASE64_RESPONSE}&RelayState={STATE}
```

**응답 필드** (`data` 내부)

이 엔드포인트는 JSON 을 반환하지 않는다. 언제나 **302 리다이렉트**이고 이동 주소는 OIDC 콜백(oidc.md)의 결과 표와 같다. IdP-initiated 로그인은 사용자 로그인 화면(`/login`)으로만 간다.

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 302 Found
Location: https://www.example.com/login?login_handoff={MASKED}
Set-Cookie: g7_login_bind={MASKED}; path=/api/auth; httponly; samesite=lax
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 302 | Found (실패 이동) | `sso_error` 코드 — `saml_invalid`(서명, 대상, 수신 주소, 유효 기간, InResponseTo, NameID 검증 실패, 형식 오류), `saml_unsolicited`(IdP-initiated 허용이 꺼졌는데 RelayState 가 state 가 아님), `response_replayed`(이미 처리한 응답), `access_denied`(IdP 가 RequestDenied 로 답함), `state_expired`, `bind_mismatch`, `idp_disabled`, 회원 결정 거절(`not_registered`, `email_in_use`, `already_linked`, `deprovisioned`, `admin_target_manual_only`) |
| 404 | Not Found | `idp` 가 키 형식이 아닌 경우(라우트 제약) |
| 429 | Too Many Requests | 분당 60회를 넘은 경우 |

<!-- @generated:end -->

**설명**

IdP 콘솔의 ACS URL 이다(IdP 편집 화면 `sp_info` 의 `acs_url`). `RelayState` 가 state 형식이면 SP 가 시작한 로그인으로 보고 state 를 꺼내 OIDC 콜백과 같은 길로 처리한다. 그렇지 않으면 IdP-initiated 로 보고, IdP 설정 `idp_initiated` 가 켜져 있을 때만 받는다(만료 시각이 없는 응답은 거절, 같은 응답 재사용 거절). IdP-initiated 는 로그인 의도로만 처리한다(연결, 재확인, 테스트는 SP 가 시작해야 한다).

HTTPS 사이트의 묶음 쿠키는 SameSite=None 이라 IdP 의 교차 사이트 POST 에도 실린다. HTTP 개발 사이트에서는 Lax 라 SP-initiated SAML 이 `bind_mismatch` 로 끝날 수 있다.


### GET /plugins/sirsoft-sso/saml/{idp}/metadata
<!-- @generated:start:web.plugins.sirsoft-sso.saml.metadata -->
- **라우트명**: `web.plugins.sirsoft-sso.saml.metadata`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\SamlController@metadata`
- **인증/권한**: 공개 (인증 불필요)
- **요청 제한**: `throttle:60,1,sirsoft-sso.saml-metadata`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | SAML IdP 키 |

**요청 예시**

```http
GET /plugins/sirsoft-sso/saml/{idp}/metadata HTTP/1.1
Host: api.example.com
Accept: application/samlmetadata+xml
```

**응답 필드** (`data` 내부)

이 엔드포인트는 JSON 을 반환하지 않는다. SP 메타데이터 XML(`Content-Type: application/samlmetadata+xml; charset=utf-8`, `Cache-Control: no-store`)을 돌려준다. EntityDescriptor 에 SP Entity ID, ACS(HTTP-POST), SLS, NameID 형식, SP 서명 인증서(만들었을 때)가 들어 있다. 메타데이터 자체는 서명하지 않는다.

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Content-Type: application/samlmetadata+xml; charset=utf-8
Cache-Control: no-store
```

```xml
<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="…">
    <md:SPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
        <md:SingleLogoutService Binding="…" Location="https://www.example.com/plugins/sirsoft-sso/saml/…/sls"/>
        <md:NameIDFormat>…</md:NameIDFormat>
        <md:AssertionConsumerService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" Location="https://www.example.com/plugins/sirsoft-sso/saml/…/acs" index="1"/>
    </md:SPSSODescriptor>
</md:EntityDescriptor>
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 없는 키, SAML 이 아닌 IdP, 모의 게이트가 꺼진 모의 IdP, 메타데이터를 만들지 못함 |
| 429 | Too Many Requests | 분당 60회를 넘은 경우 |

<!-- @generated:end -->

**설명**

IdP 콘솔에 SP 를 등록할 때 이 주소(`sp_info` 의 `metadata_url`)를 주거나 내려받은 XML 을 올린다. SP 키를 새로 만들면(`POST admin/idps/{id}/sp-keys`) 내용이 바뀌므로 IdP 에 다시 등록한다. 꺼진 IdP 도 메타데이터는 내준다(켜기 전에 등록하기 위해).


### GET /plugins/sirsoft-sso/saml/{idp}/sls
<!-- @generated:start:web.plugins.sirsoft-sso.saml.sls::get -->
- **라우트명**: `web.plugins.sirsoft-sso.saml.sls`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\SamlController@sls`
- **인증/권한**: 공개 (인증 불필요) — 메시지 서명 검증, CSRF 면제
- **요청 제한**: `throttle:60,1,sirsoft-sso.saml-sls`

**요청 파라미터**

`SAMLRequest` 와 `SAMLResponse` 중 정확히 하나가 있어야 한다.

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | SAML IdP 키 |
| SAMLRequest | query | string | 조건부 | deflate + base64, 크기 상한 있음 | IdP 가 시작한 로그아웃 요청(LogoutRequest, 서명 필수) |
| SAMLResponse | query | string | 조건부 | deflate + base64 | 이 사이트가 보낸 로그아웃 요청의 답(LogoutResponse) |
| RelayState | query | string | 아니오 | 512자 | 돌아갈 주소 |
| SigAlg | query | string | 아니오 | 200자 | Redirect 바인딩 서명 알고리즘 |
| Signature | query | string | 아니오 | 2048자 | Redirect 바인딩 서명 |

**요청 예시**

```http
GET /plugins/sirsoft-sso/saml/{idp}/sls?SAMLRequest={DEFLATED_BASE64}&SigAlg=http%3A%2F%2Fwww.w3.org%2F2001%2F04%2Fxmldsig-more%23rsa-sha256&Signature={SIGNATURE} HTTP/1.1
Host: api.example.com
```

**응답 필드** (`data` 내부)

이 엔드포인트는 JSON 을 반환하지 않는다.

| 경우 | 응답 |
| --- | --- |
| `SAMLRequest`(IdP 발 로그아웃) 검증 성공 | 그 NameID(와 SessionIndex)의 열린 SSO 세션을 끝내고, IdP 단일 로그아웃 주소로 LogoutResponse 를 실어 302. IdP 에 로그아웃 주소가 없으면 빈 본문 200 |
| `SAMLResponse`(로그아웃 답) | 302 → RelayState 가 `/plugins/sirsoft-sso/logout/return` 으로 시작하면 그 주소, 아니면 `/login`. 답 검증 실패는 서버 로그에만 남긴다 |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 302 Found
Location: https://idp.example.com/saml/slo?SAMLResponse=…&SigAlg=…&Signature=…
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 빈 본문 — 두 메시지가 다 있거나 다 없음, 길이 초과, 요청을 읽지 못함, 서명되지 않은 LogoutRequest, 서명이나 대상 검증 실패 |
| 404 | Not Found | 없는 키, SAML 이 아닌 IdP, 모의 게이트가 꺼진 모의 IdP |
| 429 | Too Many Requests | 분당 60회를 넘은 경우 |

<!-- @generated:end -->

**설명**

IdP 콘솔의 Single Logout URL 이다(`sp_info` 의 `sls_url`). IdP 가 시작한 로그아웃은 IdP 의 `logout_mode` 와 무관하게 서명이 맞으면 처리한다. 응답은 모두 `Cache-Control: no-store`.


### POST /plugins/sirsoft-sso/saml/{idp}/sls
<!-- @generated:start:web.plugins.sirsoft-sso.saml.sls::post -->
- **라우트명**: `web.plugins.sirsoft-sso.saml.sls`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\SamlController@sls`
- **인증/권한**: 공개 (인증 불필요) — 메시지 서명 검증, CSRF 면제
- **요청 제한**: `throttle:60,1,sirsoft-sso.saml-sls`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | SAML IdP 키 |
| SAMLRequest | body | string | 조건부 | base64, 크기 상한 있음 | IdP 가 시작한 로그아웃 요청(POST 바인딩, XML 서명) |
| SAMLResponse | body | string | 조건부 | base64 | 로그아웃 요청의 답 |
| RelayState | body | string | 아니오 | 512자 | 돌아갈 주소 |

**요청 예시**

```http
POST /plugins/sirsoft-sso/saml/{idp}/sls HTTP/1.1
Host: api.example.com
Content-Type: application/x-www-form-urlencoded

SAMLRequest={BASE64_LOGOUT_REQUEST}
```

**응답 필드** (`data` 내부)

GET SLS 와 같다.

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Cache-Control: no-store
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 빈 본문 — GET SLS 와 같은 조건 |
| 404 | Not Found | 없는 키, SAML 이 아닌 IdP, 모의 게이트가 꺼진 모의 IdP |
| 429 | Too Many Requests | 분당 60회를 넘은 경우 |

<!-- @generated:end -->

**설명**

POST 바인딩으로 로그아웃을 보내는 IdP 를 위해 GET 과 같은 처리를 한다. 예시는 IdP 에 단일 로그아웃 주소가 없을 때의 응답이다.
