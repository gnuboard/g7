# SSO 연동 — 운영자 가이드

> IdP 콘솔 등록, SCIM, 비상 계정, 운영 중 주의할 것 — 진입점: [README.md](../README.md)

이 문서는 사이트 운영자가 회사 IdP 를 등록하고 켜기까지, 그리고 켠 뒤 운영하며 알아야 할 것을 적습니다. 화면 사용 순서는 [README 의 사용 방법](../README.md#사용-방법) 에, 정책 키 하나하나의 기본값은 [설정](settings.md#설정-스키마) 에 있습니다.

## 1. 등록 전에 확인할 것

- **IdP 콘솔에 넣는 주소는 모두 사이트 주소(`.env` 의 `APP_URL`)에서 만들어집니다.** 플러그인 관리 → SSO 연동 설정 → 「IdP」 → IdP 편집 → 「연결 정보」의 「IdP 콘솔에 넣을 값」 표를 복사해 그대로 붙여 넣으세요. 끝의 `/`, `http`/`https`, `www` 차이만으로도 로그인이 실패합니다. 주소에 들어가는 IdP 키는 만든 뒤 바꿀 수 없습니다.
- **운영 사이트는 `https` 여야 합니다.** 로그인 왕복을 시작한 브라우저를 확인하는 묶음 쿠키가 SAML 의 교차 사이트 POST 에 실리려면 `https` 가 필요합니다. `http` 사이트에서는 SAML 로그인이 `bind_mismatch` 로 끝납니다.
- **사이트 호스트 이름에 밑줄(`_`)이 있으면 SAML 을 쓸 수 없습니다.** SAML 라이브러리(`onelogin/php-saml`)가 ACS, SLS 주소를 URL 형식 검사로 거절해 모든 SAML 시작이 `saml_invalid` 가 됩니다. SAML IdP 의 연결 테스트가 `site_url` 항목으로 알립니다. OIDC 와 LDAP 은 사이트 호스트의 영향을 받지 않습니다. 다만 OIDC 디스커버리 주소 자체의 호스트에 밑줄이 있으면 같은 검사로 IdP 를 사용으로 바꿀 때 저장이 거절됩니다.
- 서버 시계를 맞춰 두세요. id_token 과 SAML 응답의 유효 시각은 허용 오차 120초 안에서만 받습니다.
- **서버 스케줄러가 돌아야 합니다.** 주기 점검(5분마다), 퇴사 뒤 탈퇴(매일), IdP 메타데이터 갱신과 인증서 만료 알림(매일), 이력 정리(매일)가 스케줄로 돕니다. 스케줄러가 멈추면 IdP 에서 퇴사한 직원이 G7 에서 차단되지 않습니다(SCIM 을 쓰는 IdP 는 SCIM 요청이 바로 반영됩니다). 알림 메일은 사이트 큐 설정을 따르므로, 큐 연결(`QUEUE_CONNECTION`)이 `sync` 가 아니면 `php artisan queue:work` 가 늘 떠 있어야 합니다.
- LDAP/Active Directory 를 쓰려면 서버 PHP 에 LDAP 확장(`ext-ldap`)이 있어야 합니다(8절).
- 실제 IdP 없이 화면과 흐름을 먼저 보고 싶으면 개발 환경에서 모의 IdP 를 켭니다(12절).

## 2. IdP 에 넣을 값

「연결 정보」 탭의 표가 IdP 마다 이 값을 보여 줍니다. `{키}` 는 IdP 키, `https://www.example.com` 은 사이트 주소입니다.

| 프로토콜 | 항목 | 값 |
|---|---|---|
| OpenID Connect | Redirect URI | `https://www.example.com/plugins/sirsoft-sso/oidc/{키}/callback` |
| | Post logout redirect URI | `https://www.example.com/plugins/sirsoft-sso/logout/return` |
| | Back-channel logout URI | `https://www.example.com/plugins/sirsoft-sso/oidc/{키}/backchannel-logout` |
| | 범위(scope) | 기본 `openid email profile`. 주기 점검을 켜면 `offline_access` 를 더한다 |
| SAML 2.0 | SP Entity ID | 「연결 정보」에서 정하지 않으면 SP 메타데이터 주소와 같다 |
| | ACS(Assertion Consumer Service) | `https://www.example.com/plugins/sirsoft-sso/saml/{키}/acs` (POST 바인딩) |
| | SLS(Single Logout Service) | `https://www.example.com/plugins/sirsoft-sso/saml/{키}/sls` |
| | SP 메타데이터 | `https://www.example.com/plugins/sirsoft-sso/saml/{키}/metadata` — 메타데이터를 가져오는 IdP 는 이 주소 하나로 위 값을 채운다 |
| | NameID Format | 기본 persistent |
| LDAP/AD | — | IdP 콘솔이 없다. 디렉터리 쪽에는 읽기 권한이 있는 서비스 계정만 준비한다 |
| SCIM 2.0 | 엔드포인트(테넌트 URL) | `https://www.example.com/api/plugins/sirsoft-sso/scim/v2/{키}` — 「프로비저닝」 탭에서 복사 |
| | 인증 | Bearer 토큰 — 「프로비저닝」의 [SCIM 토큰 발급] |

등록한 뒤 「진단」 탭에서 [연결 테스트](메타데이터, 서명 키, 인증서 유효기간, 필수 주소)와 [브라우저로 로그인 테스트](실제 IdP 화면을 거쳐 받은 신원을 보여 주고 로그인하지 않음)로 확인하고, 「기본」 탭에서 켭니다.

## 3. IdP 제품별 콘솔 등록

제품 콘솔의 메뉴 이름은 버전마다 다를 수 있습니다. 넣을 값은 2절 표 그대로입니다.

### Keycloak

| 방식 | 콘솔에서 할 일 |
|---|---|
| OpenID Connect | 렐름의 Clients 에서 OpenID Connect 클라이언트를 만들고 Client authentication 을 켭니다. Valid redirect URIs 에 Redirect URI, Valid post logout redirect URIs 에 Post logout redirect URI, Backchannel logout URL 에 back-channel 주소를 넣습니다. Credentials 탭의 Client secret 을 「연결 정보」의 비밀값에 넣고, discovery 주소는 `https://{Keycloak 호스트}/realms/{렐름}/.well-known/openid-configuration` 입니다. |
| 그룹 | 그룹으로 역할을 매핑하려면 클라이언트 범위에 Group Membership 매퍼를 더해 토큰 클레임 이름을 `groups` 로 둡니다(전체 경로를 끄면 그룹 이름만 온다). |
| 주기 점검 | 클라이언트 범위에 `offline_access` 를 허용하고, 「연결 정보」의 범위에 `offline_access` 를 더합니다. `offline_access` 로 받은 로그인은 Keycloak 에 오프라인 세션으로 남아, 관리 콘솔에서 사용자를 로그아웃해도 back-channel 로그아웃이 오지 않습니다. 이때 퇴사 반영은 주기 점검이 맡습니다. |
| 2단계 인증 | 「G7 2단계 인증」을 「IdP 다중 인증을 알려 줄 때만 생략」으로 두려면 클라이언트에 Authentication Method Reference(AMR) 매퍼를 더하고, 인증 플로의 OTP Form 실행 단계 설정에 참조값 `otp`(필요하면 비밀번호 단계에 `pwd`)를 넣습니다. 참조값이 없으면 `amr` 이 비어 와서 G7 2단계 인증을 거칩니다. |
| SAML 2.0 | SAML 클라이언트를 만들고 Client ID 에 SP Entity ID, Valid redirect URIs 와 Assertion Consumer Service POST Binding URL 에 ACS, Logout Service 주소에 SLS 를 넣습니다. Name ID format 은 persistent 를 권합니다. **Sign assertions 를 켜세요** — 이 플러그인은 서명된 어설션만 받습니다. Keys 탭의 Client signature required 가 켜져 있으면 SP 키를 만들고 「연결 정보」의 AuthnRequest 서명을 켠 뒤 SP 인증서를 등록하거나, 그 설정을 끕니다. IdP 메타데이터 주소는 `https://{Keycloak 호스트}/realms/{렐름}/protocol/saml/descriptor` 입니다. |
| SAML 속성 | Keycloak 기본 클라이언트 범위 `role_list` 는 역할마다 같은 이름 속성(`Role`)을 따로 보냅니다. 이 플러그인은 같은 이름 속성의 값을 합쳐 받으므로 범위를 떼지 않아도 됩니다. 그룹으로 역할을 매핑하려면 Group list 매퍼를 더하고 그 속성 이름을 「연결 정보」의 「그룹 속성」에 넣습니다(기본 `groups`). |
| SCIM | Keycloak 기본 기능에는 SCIM 발신이 없습니다. 퇴사 반영은 OIDC 주기 점검이나 세션 수명 상한을 쓰세요. |

### Microsoft Entra ID

| 방식 | 콘솔에서 할 일 |
|---|---|
| OpenID Connect | 앱 등록에서 플랫폼 「웹」의 리디렉션 URI 에 Redirect URI 를 넣고, 인증서 및 암호에서 클라이언트 암호를 만듭니다. discovery 주소는 `https://login.microsoftonline.com/{테넌트 ID}/v2.0/.well-known/openid-configuration` 입니다. **「연결 정보」의 고유 식별자 클레임은 `oid` 로 고르세요** — Entra ID 의 `sub` 는 앱마다 다른 값이라 SCIM 과 이어지지 않습니다. |
| 그룹 | 토큰 구성에서 그룹 클레임을 더합니다. 기본으로 그룹 이름이 아니라 그룹 객체 ID 가 오므로 역할 매핑 규칙의 그룹 값에 객체 ID 를 넣습니다. |
| 로그아웃 | 앱 등록의 front-channel 로그아웃 주소만 있고 back-channel 주소 칸이 없으면, 로그아웃 범위는 「G7 만」이나 「G7 → IdP」로 둡니다. |
| SAML 2.0 | 엔터프라이즈 애플리케이션 → Single sign-on → SAML 에서 식별자(엔터티 ID)에 SP Entity ID, 회신 URL 에 ACS, 로그아웃 URL 에 SLS 를 넣습니다. 앱 페더레이션 메타데이터 URL 을 「연결 정보」의 IdP 메타데이터 주소에 넣습니다. 기본 Name ID 는 이메일 형식이므로, 이메일이 바뀌어도 연결이 끊기지 않게 하려면 고유 사용자 식별자를 `user.objectid`(형식 persistent)로 바꿉니다. 속성 이름이 URI(`http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress` 등)로 오므로 「연결 정보」의 이메일, 이름, 그룹 속성 칸에 그 URI 를 넣습니다. |
| SCIM | 엔터프라이즈 애플리케이션 → 프로비저닝 → 자동에서 테넌트 URL 에 SCIM 엔드포인트, 비밀 토큰에 SCIM 토큰을 넣고 연결을 테스트합니다. **대조 키는 `externalId` 로 고르고**, 특성 매핑에서 `externalId` 의 원본 특성이 `objectId` 인지 확인하세요(아니면 바꿉니다). 그래야 로그인의 `oid` 와 SCIM 이 같은 회원을 가리킵니다. |

### Okta

| 방식 | 콘솔에서 할 일 |
|---|---|
| OpenID Connect | Applications 에서 OIDC 웹 애플리케이션을 만들고 Sign-in redirect URIs 에 Redirect URI, Sign-out redirect URIs 에 Post logout redirect URI 를 넣습니다. discovery 주소는 기본 권한 서버면 `https://{Okta 도메인}/oauth2/default/.well-known/openid-configuration`, 조직 권한 서버면 `https://{Okta 도메인}/.well-known/openid-configuration` 입니다. 주기 점검을 쓰려면 허용 grant 에 Refresh Token 을 켜고 범위에 `offline_access` 를 더합니다. 그룹은 그룹 클레임 필터를 설정해 `groups` 클레임으로 받습니다. |
| SAML 2.0 | SAML 2.0 앱을 만들고 Single sign-on URL 에 ACS, Audience URI 에 SP Entity ID 를 넣습니다. Name ID format 은 Persistent 를 권합니다. 단일 로그아웃을 켜려면 Single Logout URL 에 SLS, SP Issuer 에 SP Entity ID 를 넣고 SP 인증서(「연결 정보」의 [SP 키 생성])를 올립니다. Sign On 탭의 메타데이터 주소를 「연결 정보」에 넣습니다. |
| SCIM | 앱의 Provisioning 에서 SCIM 을 켜고 SCIM connector base URL 에 SCIM 엔드포인트, 고유 식별자 필드에 `userName`, 인증 방식에 HTTP Header(Bearer 토큰)를 고릅니다. 대조 키 `userName` 이 로그인 식별자(OIDC `sub` 또는 SAML NameID)와 같은 값인지 확인하세요. |

### Google Workspace

| 방식 | 콘솔에서 할 일 |
|---|---|
| SAML 2.0 | 관리 콘솔 → 앱 → 웹 및 모바일 앱 → 맞춤 SAML 앱 추가에서 IdP 메타데이터를 내려받아 「연결 정보」의 IdP 메타데이터 XML 칸에 붙여 넣습니다. ACS URL 에 ACS, 엔티티 ID 에 SP Entity ID 를 넣고 Name ID 는 기본 정보의 기본 이메일로 둡니다. 그룹으로 역할을 매핑하려면 그룹 구성원 정보를 속성으로 내보냅니다. 맞춤 SAML 앱은 단일 로그아웃을 지원하지 않으므로 로그아웃 범위는 「G7 만」으로 둡니다. 맞춤 SAML 앱에는 SCIM 자동 프로비저닝 설정이 없으니 세션 수명 상한을 함께 쓰세요. |
| OpenID Connect | Google Cloud 콘솔에서 OAuth 클라이언트(웹 애플리케이션)를 만들고 승인된 리디렉션 URI 에 Redirect URI 를 넣습니다. discovery 주소는 `https://accounts.google.com/.well-known/openid-configuration` 입니다. Google 은 `offline_access` 범위로 갱신 토큰을 주지 않아 OIDC 주기 점검을 쓸 수 없습니다. |

### AD FS

| 방식 | 콘솔에서 할 일 |
|---|---|
| SAML 2.0 | 신뢰 당사자 트러스트를 추가하면서 SP 메타데이터 주소로 가져오거나, 수동이면 식별자에 SP Entity ID, SAML 2.0 WebSSO 주소에 ACS, 로그아웃 엔드포인트에 SLS 를 넣습니다. 클레임 발급 정책에 Name ID(형식 persistent — 직원마다 바뀌지 않는 값), 이메일, 표시 이름, 그룹을 내보내는 규칙을 더하고, URI 로 오는 속성 이름을 「연결 정보」의 이메일, 이름, 그룹 속성 칸에 맞춥니다. IdP 메타데이터 주소는 `https://{AD FS 호스트}/FederationMetadata/2007-06/FederationMetadata.xml` 입니다. 단일 로그아웃을 쓰려면 SP 키를 만들어 트러스트에 SP 인증서를 등록합니다 — SP 키가 있으면 이 플러그인은 로그아웃 요청과 응답에 서명합니다. |
| 주기 점검 | SAML 에는 재직 여부를 묻는 표준 수단이 없습니다. 같은 회사의 Active Directory 를 LDAP IdP 로 등록하고 「연결 정보」의 재직 조회 디렉터리로 고르세요(6절). |

### Active Directory (LDAP)

IdP 콘솔 등록이 없습니다. 「연결 정보」에서 디렉터리 종류를 Active Directory 로 고르면 포트 636, 사용자 검색 필터 `(&(objectCategory=person)(objectClass=user)(|(mail={identifier})(sAMAccountName={identifier})(userPrincipalName={identifier})))`, 고유 식별자 `objectGUID`, 퇴사 판정 「계정 사용 안 함」 비트(`userAccountControl`)가 채워집니다. 암호화를 LDAPS 로 고르고, 서비스 계정 DN 과 비밀번호, 기준 DN 을 넣고, 내부 인증 기관을 쓰면 CA 인증서를 붙여 넣습니다. 자세한 것은 8절.

## 4. 비밀값과 앱 키(`APP_KEY`)

- OIDC 클라이언트 비밀값, SAML SP 개인키, LDAP 서비스 계정 비밀번호는 IdP 표에 사이트 앱 키(`APP_KEY`)로 암호화해 둡니다. 주기 점검을 켠 OIDC IdP 의 회원별 갱신 토큰도 같습니다. 화면과 응답에는 가린 값으로만 보이고, 그대로 두고 저장하면 유지됩니다.
- **`APP_KEY` 를 바꾸면 저장된 비밀값을 풀 수 없습니다.** 앱 키를 바꿀 때는 이전 키를 `.env` 의 `APP_PREVIOUS_KEYS` 에 남겨 두세요. 그동안 이전 키로 풀리므로, IdP 마다 비밀값을 다시 입력해 저장하면 새 키로 암호화됩니다. 이전 키 없이 바꿨다면 IdP 설정 화면과 그 IdP 로그인이 비밀값을 읽다 실패합니다 — 이전 키를 `APP_PREVIOUS_KEYS` 에 되돌려 넣고 다시 저장하세요.
- SCIM 토큰은 원문을 저장하지 않으므로 앱 키와 무관합니다.
- 비밀값과 SCIM 토큰을 이력, 화면 캡처, 문의 글에 붙이지 마세요. 노출됐다면 IdP 에서 새로 발급해 다시 저장하고, SCIM 토큰은 다시 발급합니다.
- SP 키를 다시 만들면 이전 키를 30일 동안 함께 씁니다. 그 안에 IdP 에 새 SP 인증서를 등록하세요.

## 5. SCIM 프로비저닝

- **토큰 발급**: IdP 편집 → 「프로비저닝」에서 SCIM 사용을 켜고 [SCIM 토큰 발급]을 누릅니다. 토큰은 **그때 한 번만** 보입니다. 다시 발급하면 이전 토큰은 바로 쓸 수 없으므로 IdP 쪽 토큰도 함께 바꿔야 합니다. SCIM 사용을 끄면 토큰이 맞아도 403 으로 거절합니다.
- **대조 키(`userName` 또는 `externalId`)**: SCIM 이 보내는 값 중 로그인 때 IdP 가 보내는 고유 식별자(OIDC `sub` 나 `oid`, SAML NameID)와 같은 값을 고르세요. 다르면 SCIM 으로 만든 회원과 로그인한 회원이 이어지지 않습니다. 예: Entra ID 는 OIDC 고유 식별자 `oid` + SCIM 대조 키 `externalId`(원본 특성 `objectId`).
- **삭제는 퇴사 처리입니다.** SCIM `DELETE /Users/{id}` 와 `active=false` 는 회원을 지우지 않고 연결을 비활성으로 만든 뒤 회원을 차단하고 모든 로그인 토큰을 회수합니다(게시글, 주문 기록 보존). 그래서 DELETE 뒤 같은 자원을 GET 하면 404 가 아니라 `active:false` 로 남아 있습니다 — RFC 7644 §3.6 과 다른 점입니다. IdP 가 DELETE 뒤 404 를 기대하고 오류를 남기면 그 IdP 의 SCIM 은 삭제 대신 비활성화(`active=false`)를 보내도록 설정하세요.
- 회원에게 다른 IdP 의 활성 연결이 있으면 차단하지 않고 그 IdP 로 받은 로그인만 끝냅니다(여러 회사 겸직). 최고관리자는 차단하지 않고 토큰만 회수한 뒤 최고관리자 전원에게 알립니다.
- `active=true` 가 오면 다시 켭니다. 이때 그 퇴사 처리가 건 차단만 풀고, 그 뒤 관리자가 다시 차단했으면 풀지 않습니다. 관리자가 직접 끈 연결은 SCIM 으로 다시 켜지지 않습니다.
- **지원 범위**: Users(목록, 조회, 생성, 교체, 부분 수정, 삭제)와 Groups(역할 매핑 입력으로만 쓴다), ServiceProviderConfig, ResourceTypes, Schemas. 목록 `filter` 는 `userName`, `externalId`, `emails.value` 중 하나의 `eq "값"` 하나만 받고, 정렬, Bulk, ETag, `/Me`, 비밀번호 변경은 지원하지 않습니다. 목록은 한 번에 최대 100건입니다. 부분 수정(PATCH)은 IdP 마다 다른 모양을 받아 주고, 모르는 속성은 무시합니다. Groups 의 모르는 경로는 400 `invalidPath` 입니다.
- 이메일은 그 IdP 의 소유 도메인일 때만 회원 이메일로 씁니다. 아니면 받을 수 없는 대체 주소(`…@sso.invalid`)로 만듭니다. 같은 이메일 회원이 이미 있으면, 그 IdP 가 소유 도메인 자동 연결을 켰고 회원이 관리자가 아닐 때만 연결하고 아니면 409 입니다.
- 수신 기록은 관리자 메뉴 「SSO 연동 → 이력 → SCIM 수신」에 남습니다(요청 본문은 저장하지 않습니다).

## 6. 퇴사 처리와 주기 점검의 한계

퇴사 반영 방식 넷을 IdP 마다 조합합니다(「프로비저닝」, 「보안」 탭).

| 방식 | 동작 | 한계 |
|---|---|---|
| SCIM | IdP 가 퇴사를 바로 알린다 | IdP 가 SCIM 발신을 지원해야 한다 |
| 주기 점검 | 간격(기본 60분)마다 IdP 에 다시 묻는다 | 아래 표 |
| 다음 로그인 차단 | 비활성 연결은 다음 회사 계정 로그인에서 거절 | 이미 받은 로그인은 세션이 끝날 때까지 남는다 |
| 세션 수명 상한 | 회사 계정 로그인의 세션을 정한 시간에 끝낸다(IdP 값과 사이트 값 중 작은 쪽) | 끝난 뒤 다시 로그인할 때 IdP 가 막아 준다 |

| 프로토콜 | 주기 점검 수단 | 주의 |
|---|---|---|
| LDAP/AD | 디렉터리에서 그 계정을 다시 찾는다. 없거나 「계정 사용 안 함」(AD) 또는 정한 속성 값이면 퇴사 | 조회 결과가 2건 이상이면 판정을 보류한다(퇴사로 보지 않음) |
| OpenID Connect | 로그인 때 받아 둔 갱신 토큰으로 토큰 갱신을 시도해 `invalid_grant` 면 퇴사 | 범위에 `offline_access` 를 더하고 IdP 가 갱신 토큰을 줘야 한다. 주기 점검을 켜기 전에 로그인한 회원은 갱신 토큰이 없어 다음 로그인 뒤부터 점검된다 |
| SAML 2.0 | 표준 수단이 없다. 「연결 정보」의 재직 조회 디렉터리(같은 회사의 LDAP IdP)와 찾는 값(이메일 또는 고유 식별자)을 고르면 그 디렉터리로 본다 | 디렉터리를 고르지 않으면 SAML 주기 점검은 켤 수 없다. SCIM 이나 세션 수명 상한을 쓰세요 |

- IdP 장애(디렉터리 연결 실패, 토큰 주소 오류)는 퇴사로 판정하지 않고 다음 회차로 미룹니다.
- SAML 재직 조회의 찾는 값이 이메일이면 연결에 기록된 IdP 이메일로 찾습니다. 관리자가 직접 만든 연결과 CSV 로 미리 등록한 연결은 그 회원이 처음 SAML 로 로그인해 IdP 이메일이 채워진 뒤부터 점검 대상이 되고, 그 전에는 판정 불가로 건너뜁니다. 회원 이메일로 대신 찾지 않습니다(다른 사람을 차단하지 않도록).
- 주기 점검이나 다음 로그인 차단으로 비활성이 된 연결은 그 회원이 IdP 로 다시 로그인에 성공하면 다시 켜집니다. SCIM 이나 관리자가 끈 연결은 로그인으로 풀리지 않습니다.
- **퇴사 N일 뒤 탈퇴**: 「프로비저닝」에서 날 수를 정하면 매일 한 번, 퇴사 처리 뒤 그 날이 지났고 여전히 그 퇴사 처리가 건 차단 상태인 회원을 탈퇴(익명화)시킵니다. 관리자 계정은 탈퇴시키지 않고 건너뛰며 최고관리자에게 알립니다.
- 스케줄러가 멈추면 주기 점검과 퇴사 뒤 탈퇴가 돌지 않습니다(1절).

## 7. 비상 계정과 강제 해제

- 비밀번호 로그인 강제(사이트 전체, 역할, 회사 계정 연결 회원, IdP 소유 도메인)를 켜면 IdP 장애 때 아무도 못 들어올 수 있습니다. 그래서 **비상 계정이 최소 1명** 있어야 하고, 0명으로는 저장할 수 없습니다. 처음에는 가장 먼저 만든 최고관리자가 비상 계정입니다(정책을 처음 읽을 때 정해진다).
- 비상 계정은 비밀번호로 로그인하므로 **비밀번호가 있어야 하고**, 강제가 켜진 동안에는 사이트 설정과 상관없이 **2단계 인증을 반드시 거칩니다**(인증 앱을 등록했으면 인증 앱, 아니면 메일). 2단계 인증 수단이 없으면 로그인이 거절되니 미리 등록해 두세요. 비상 계정이 로그인하면 활동 로그와 관리자 알림이 남습니다.
- 비상 계정과 사이트 전체 강제, 역할 강제는 최고관리자만 바꿉니다. 사이트 전체 강제는 활성 IdP 가 하나도 없으면 저장할 수 없습니다.
- 사이트 전체 강제에 자동 이동 IdP 를 정하면 로그인 화면이 바로 IdP 로 넘어갑니다. 비상 계정은 로그인 주소에 `?local=1` 을 붙여(`/login?local=1`, `/admin/login?local=1`) 비밀번호 화면으로 들어옵니다. 자동 이동이 켜진 로그인 화면 아래에 늘 보이는 링크도 같은 주소입니다.
- **잠겼을 때 서버에서 강제를 푸는 명령**:

```bash
php artisan sirsoft-sso:enforcement --status    # 지금 켜진 강제 확인
php artisan sirsoft-sso:enforcement --disable   # 사이트, 역할, 연결 회원, 모든 IdP 의 도메인 강제를 끄고 이전 값 보관
php artisan sirsoft-sso:enforcement --restore   # --disable 로 보관한 값 되돌리기
```

`--disable` 은 활동 로그를 남깁니다. IdP 가 복구되면 `--restore` 로 되돌리세요.

비상 계정의 비밀번호까지 잊었다면 비밀번호 재설정 명령을 따로 찾지 마세요. `--disable` 로 강제를 풀면 비상 계정을 포함한 모든 회원의 **코어 「비밀번호 찾기」 메일이 다시 동작**합니다(강제 대상인 동안에는 재설정 링크 대신 「회사 계정으로 로그인하세요」 안내 메일이 갑니다). 메일로 비밀번호를 다시 정해 로그인한 뒤 IdP 를 고치고 `--restore` 하세요.

## 8. LDAP/Active Directory

- **PHP LDAP 확장**: 서버 PHP 에 `ext-ldap` 이 없으면 설정 화면에 「서버에 PHP LDAP 확장이 없습니다」가 보이고 LDAP IdP 는 저장만 되고 켤 수 없습니다. 나머지 프로토콜은 그대로 쓸 수 있습니다. 운영체제 패키지(예: `php8.3-ldap`)나 `php.ini` 의 `extension=ldap` 으로 설치하고 웹 서버와 PHP-FPM 을 다시 시작하세요.
- **디렉터리 종류 프리셋**: OpenLDAP(포트 389, 고유 식별자 `entryUUID`, 퇴사 판정 「검색 결과 없음」)과 Active Directory(포트 636, `objectGUID` — 바이너리라 GUID 문자열로 바꿔 저장, 퇴사 판정 「계정 사용 안 함」 비트). 고르면 사용자 검색 필터와 그룹 설정도 채워집니다.
- **암호화**: StartTLS(기본, 보통 포트 389) 또는 LDAPS(보통 포트 636)를 쓰세요. Active Directory 프리셋은 포트를 636 으로 채우므로 암호화를 LDAPS 로 고르거나, StartTLS 를 쓰려면 포트를 389 로 바꿉니다. 암호화 없음은 운영 환경(`APP_ENV=production`)에서 IdP 를 켤 수 없습니다. 인증서 검증은 기본으로 켜져 있고, 내부 인증 기관이면 CA 인증서를 붙여 넣습니다.
- **사용자 검색 필터**의 `{identifier}` 자리에 로그인 칸 값이 들어갑니다. 값은 LDAP 필터 이스케이프를 거치므로 `*`, `(` 같은 문자로 검색을 바꿀 수 없습니다. 결과가 **정확히 1건**일 때만 로그인합니다(0건이나 2건 이상이면 거절). **빈 비밀번호는 디렉터리에 보내지 않고** 바로 거절합니다.
- **로그인 칸**: 이메일이면 그 도메인을 소유한 LDAP IdP 가, 사내 아이디(이메일 아님)면 「사내 아이디 허용」을 켠 LDAP IdP 가 맡습니다. **사내 아이디를 허용한 LDAP IdP 가 여럿이면 정렬 순서 첫째만** 맡습니다. 한 도메인은 IdP 하나만 소유할 수 있어(다른 IdP 가 소유한 도메인을 넣으면 저장이 거절됩니다), 같은 도메인을 LDAP IdP 와 OpenID Connect·SAML IdP 가 나눠 갖지 않습니다. 회사 도메인을 LDAP 에서 OpenID Connect·SAML 로 옮기려면 LDAP IdP 의 소유 도메인에서 먼저 빼고 새 IdP 에 넣으세요.
- **실패 횟수**: 연결된 회원의 디렉터리 비밀번호를 틀리면 G7 로그인 실패 횟수에 들어가 G7 이 먼저 잠급니다(디렉터리 계정 잠금으로 전사 로그인이 막히는 것을 막으려는 것). 디렉터리 서버 장애는 실패 횟수에 넣지 않습니다.
- **이메일 먼저 입력 화면**: LDAP 도메인 이메일을 넣으면 일반 비밀번호 칸이 그대로 나옵니다. 「사내 계정 비밀번호」라는 안내 문구는 코어 진입 판정 응답이 비밀번호 판정의 문구를 싣지 않아 보이지 않습니다. 직원에게 사내 계정 비밀번호를 넣도록 따로 안내하세요.
- **그룹**: `memberOf`(DN 이면 첫 CN 을 그룹 이름으로) 또는 그룹 검색 필터(`{dn}` 자리에 사용자 DN)로 읽습니다.
- **속성 규칙**: 「속성」 탭 규칙의 IdP 속성 이름은 디렉터리 속성 이름 그대로 적습니다(`employeeNumber`, `departmentNumber`, AD `employeeID` 등). LDAP 속성 이름은 대소문자를 가리지 않으므로 `employeenumber` 로 적어도 같습니다. 규칙에 적은 속성은 로그인할 때 디렉터리에 함께 요청하고, 값이 여럿이면 쉼표로 이어 저장합니다. 비밀번호류 속성(`userPassword`, `unicodePwd` 등)은 규칙에 적어도 가져오지 않습니다. OpenID Connect 클레임과 SAML 속성 이름은 IdP 가 보내는 대소문자 그대로 적어야 합니다(OpenID Connect 도 규칙에 적은 클레임은 기본으로 읽는 클레임 밖이어도 받습니다).
- **진단 명령**: 연결, TLS, 서비스 바인드, 기준 DN 을 확인하고, 계정을 주면 실제 인증까지 시험합니다. 계정 시험 결과에는 받은 속성 이름(`attributes=`)과, 속성 규칙에 적었는데 디렉터리가 주지 않은 이름(`missing=`)이 나옵니다(값은 나오지 않습니다). 비밀번호는 명령줄에 쓰지 않고 환경변수 이름으로 넘깁니다.

```bash
php artisan sirsoft-sso:ldap-test corp_ad
LDAP_TEST_PASSWORD='…' php artisan sirsoft-sso:ldap-test corp_ad --user=hong@corp.example --password-env=LDAP_TEST_PASSWORD
```

## 9. IdP 가 시작한 로그인과 로그아웃

- **IdP 가 시작한 SAML 로그인**(IdP 포털의 앱 아이콘에서 바로 들어오기)은 IdP 마다 기본 꺼짐입니다. 사이트가 시작한 요청이 아니라 위조 위험이 더 크므로, 켜도 응답(어설션) ID 는 유효 시간까지 한 번만 받고, 유효 시각(`NotOnOrAfter`)을 엄격하게 보고, 로그인 뒤 갈 주소(RelayState)는 같은 사이트 경로만 받습니다. 꺼져 있으면 사이트가 시작하지 않은 응답은 `saml_unsolicited` 로 거절합니다.
- **로그아웃 범위**(「보안」 탭): 「G7 만」(기본), 「G7 → IdP」(G7 에서 로그아웃하면 IdP 로그아웃으로 보냄), 「양방향」(IdP 에서 로그아웃하면 G7 세션도 끝냄). 드라이버가 지원하지 않는 범위는 고를 수 없습니다.
- **IdP 가 보낸 SAML 로그아웃은 서명된 요청만** 받습니다. 서명이 없으면 NameID 만 알아도 남을 로그아웃시킬 수 있기 때문입니다. OIDC back-channel 로그아웃은 `logout_token` 서명과 1회 사용을 확인합니다. 이 주소는 IdP 서버가 직접 부르므로 사이트의 IP 접근 제한 규칙에서 자동으로 빠집니다(회원 브라우저가 들어오는 콜백·ACS·SLS 는 빠지지 않습니다).
- **2단계 인증**(「보안」 탭): 「IdP 가 다중 인증을 알려 줄 때만 생략」(기본 — OIDC `amr`, `acr`, SAML AuthnContextClassRef 로 판단), 「항상 생략」, 「항상 G7 2단계 인증」.

## 10. 회원 연결과 CSV 가져오기

- 처음 온 사람은 IdP 마다 「자동 가입」 또는 「사전 등록 회원만」으로 받습니다(기본 사전 등록만). 자동 가입은 약관 동의를 받거나(기본) 생략하고, 사이트가 일반 회원가입을 닫아도 열려 있습니다. 회원은 비밀번호 없이 만들어집니다.
- 기존 회원 연결은 IdP 마다 세 방식을 켜고 끕니다. 소유 도메인 자동 연결(기본 꺼짐)은 그 IdP 가 이메일 도메인의 주인이라고 믿고 같은 이메일 회원에 바로 붙이므로, 실제로 그 도메인을 관리하는 IdP 에서만 켜세요. OIDC 는 id_token 의 `email_verified` 가 true 일 때만 붙입니다. 본인 확인 연결은 회원이 비밀번호나 메일 인증번호로 증명해야 붙고, 비밀번호를 3번 틀리면 처음부터 다시 해야 합니다.
- **관리자, 최고관리자 계정은 자동 연결, 본인 확인 연결, SCIM, CSV 로 붙지 않습니다.** 최고관리자가 「회원 연결」에서 한 명씩 직접 연결합니다.
- **CSV**: 첫 줄은 `email,name,idp_key,subject`, 한 번에 최대 1000행(파일 1MB)입니다. 미리보기가 행마다 만들기, 연결, 이미 연결, 충돌, 관리자 계정(건너뜀), 오류를 보여 주고, 실행은 만들기와 연결 행만 처리합니다. 결과는 CSV 로 내려받습니다. `subject` 는 그 IdP 가 로그인 때 보내는 고유 식별자(OIDC `sub`/`oid`, SAML NameID, LDAP `entryUUID`/`objectGUID` 문자열)여야 합니다.
- 연결이 비밀번호 없는 회원의 유일한 로그인 수단이면 해제할 때 한 번 더 확인합니다. 회원 본인은 마이페이지에서 그런 연결과, 강제 대상인 연결을 해제할 수 없습니다.
- 「SSO 운영자」 역할(회원 연결 열람과 관리, 이력 열람)을 준 관리자도 「회원 연결」, 「이력」 화면 주소로는 바로 들어가지만, 관리자 메뉴 「SSO 연동」은 설치할 때 관리자 역할에만 붙습니다. 운영자에게 메뉴를 보이려면 권한 관리 화면에서 그 메뉴를 「SSO 운영자」 역할에 지정하세요.
- 받을 메일이 없는 회원(사번 계정, `…@sso.invalid`)은 정보 수정, 관리자 본인 정보 수정, 앱 키 재생성의 본인 재확인을 메일 인증번호 대신 연결된 회사 계정으로 다시 로그인해 통과합니다. 재확인이 끝나면 출발한 화면으로 돌아와 원래 저장이 이어집니다. 소셜 계정도 함께 연결된 회원도 「본인 확인 계속하기」는 회사 계정 다시 로그인으로 가고, 휴대폰 본인인증 플러그인이 켜져 있어도 운영자가 민감 작업 수단으로 지정하지 않았으면 그쪽으로 가지 않습니다.

## 11. 알림

| 알림 | 받는 사람 | 언제 |
|---|---|---|
| 비상 계정 로그인 | 관리자(`admin` 역할) | 강제가 켜진 동안 비상 계정이 비밀번호로 로그인 |
| IdP 연속 실패 | 관리자 | 한 IdP 의 IdP 쪽 실패가 기준 시간 안 기준 횟수에 닿음(기본 10분 안 5회, IdP 마다 하루 1번). 미등록, 퇴사 같은 정책 거절은 세지 않는다 |
| 인증서 만료 예정 | 관리자 | SAML IdP 서명 인증서 만료 30일, 7일 전 |
| 이메일 동기화 충돌 | 관리자 | IdP 가 알려 준 새 이메일을 다른 회원이 써서 바꾸지 못함 |
| 최고관리자 퇴사 처리 | 최고관리자 전원 | 최고관리자의 회사 계정이 퇴사 처리됨(차단하지 않고 토큰만 회수) |
| 관리자 자동 탈퇴 건너뜀 | 최고관리자 전원 | 퇴사 처리된 관리자 계정이 탈퇴 기한을 넘겼지만 자동으로 탈퇴시키지 않음 — 계정은 차단된 채로 남으니 탈퇴 여부를 직접 정합니다 |
| 비밀번호 찾기 회사 계정 안내 | 요청한 회원(메일) | 회사 계정으로 로그인해야 하는 회원이 비밀번호 찾기를 요청 — 재설정 링크 대신 회사 계정 로그인 안내(OpenID Connect, SAML 은 로그인 화면의 그 IdP 버튼, LDAP 은 로그인 폼에 사내 아이디나 회사 이메일과 회사 비밀번호. 본문 자리 `{login_guide}`) |

기준 횟수와 시간, 인증서 알림 날짜는 설정의 「보존」 탭에서 바꿉니다. 받는 사람은 코어 알림 설정 화면에서 바꿀 수 있습니다.

## 12. 개발용 모의 IdP

실제 IdP 없이 로그인 흐름을 확인하는 모의 OIDC, SAML IdP 가 있습니다. **서버 명령으로만 켜지고, 운영 환경(`APP_ENV=production`)에서는 거절합니다.** 화면이나 API 로 켜는 경로는 없습니다.

```bash
php artisan sirsoft-sso:mock --enable --minutes=30   # 켜기(최대 240분, 지나면 저절로 꺼짐)
php artisan sirsoft-sso:mock --seed-idp              # 모의 OIDC IdP 행(mock_oidc) 만들기
php artisan sirsoft-sso:mock-saml --seed-idp         # 모의 SAML IdP 행(mock_saml_a) 만들기
php artisan sirsoft-sso:mock --disable               # 끄기
```

꺼져 있으면 모의 IdP 주소는 404 이고 로그인 화면에도 모의 IdP 버튼이 보이지 않습니다. 모의 IdP 키는 `mock_` 으로 시작하며 실제 IdP 키로 쓸 수 없습니다. 명령이 콘솔에 찍는 만료 시각은 사이트 시간대(환경설정의 시간대) 기준입니다.

## 13. 플러그인을 끄거나 지울 때

- **플러그인을 비활성화하거나 삭제하면 강제가 풀립니다.** 코어의 비밀번호 로그인 판정에 답하는 플러그인이 없어지므로, 강제 대상이던 회원도 비밀번호가 있으면 비밀번호로 로그인할 수 있습니다. IdP 가 없는데 강제만 남아 모두 잠기는 일을 막으려는 동작입니다.
- **그동안 IdP 가 보내는 요청은 404 로 유실됩니다** — SAML ACS(로그인), SCIM(입사, 퇴사), OIDC back-channel 과 SAML 로그아웃. 그 사이 IdP 에서 퇴사 처리된 직원은 다시 켠 뒤에도 G7 에 반영되지 않으므로, IdP 쪽에서 SCIM 재동기화를 하거나 「회원 연결」에서 확인하세요.
- 회사 계정으로만 가입한 회원(비밀번호 없음)은 그동안 로그인할 수 없습니다. 연결 데이터는 지워지지 않고, 다시 켜면 그대로 로그인됩니다.
- 비활성화해도 메뉴는 남고, 삭제하면 메뉴가 지워집니다. 삭제할 때 데이터 삭제를 고르지 않으면 IdP, 연결, 정책, 이력 표가 남아 다시 설치하면 이어서 씁니다.
