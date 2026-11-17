# 그누보드7 SSO 연동 플러그인 — 에이전트 가이드

> 이 문서는 이 플러그인을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.

## TL;DR (5초 요약)

```text
1. 유형: 플러그인 (sirsoft-sso) — 회사 IdP(SAML 2.0, OpenID Connect, LDAP/AD) 로그인 왕복, 회원-IdP 연결, SCIM 2.0 프로비저닝, 비밀번호 로그인 강제
2. 확장 방식: 필터 `sirsoft-sso.protocol.filter_registered` 로 프로토콜 드라이버를 더하고, `sirsoft-sso.*` 액션과 필터 훅으로 동작을 덧붙인다
3. 건드리면 안 되는 것: 토큰 직접 발급(`createToken`, `Auth::login`) 금지 — 로그인은 코어 로그인 인계와 외부 자격 증명 검증자로만. SAML XML 직접 처리 금지
4. 작업 위치: `plugins/_bundled/sirsoft-sso` — 활성 디렉토리 직접 수정 금지
5. 반영: `php artisan plugin:update sirsoft-sso --force`
```

## 1. 이 확장은 무엇인가

<!-- @intent START -->
**해결하는 문제**: 회사가 이미 운영하는 IdP(Keycloak, Entra ID, Okta, Google Workspace, AD FS, Active Directory 등)의 계정으로 G7 에 로그인하게 하고, 입사와 퇴사를 IdP 쪽 변경만으로 G7 회원에 반영한다. 직원 전용 사이트, 고객 사이트에서 직원만 SSO, 여러 회사가 각자 IdP 를 쓰는 B2B 사이트를 모두 대상으로 한다(IdP 는 최대 20개).

**설계 원칙**

- 본인 확인은 IdP 가, 로그인은 코어가 한다. 리디렉션 프로토콜(OIDC, SAML)은 회원을 정한 뒤 코어 `LoginHandoffService` 로 인계 코드를 받아 `/login?login_handoff=` 로 보내고, LDAP 은 코어 외부 자격 증명 검증자(`SsoCredentialVerifier`)로 코어 `login()` 에 합류한다. 잠금, 회원 상태, 관리자 경로, 2단계 인증, 비밀번호 변경, 휴면 해제는 비밀번호 로그인과 같게 코어가 처리한다.
- 연결 키는 (IdP, IdP 안의 고유 식별자)다. 이메일로 회원을 찾는 곳은 소유 도메인 자동 연결(OIDC 는 `email_verified === true` 일 때만)과 연결 확인 화면의 후보 찾기 두 곳뿐이다. subject 칸은 `utf8mb4_bin` 이다.
- 서명 검증은 검증된 라이브러리만 쓴다 — SAML 은 `onelogin/php-saml`(내부에서 `robrichards/xmlseclibs`), id_token 과 back-channel `logout_token` 은 `firebase/php-jwt`. 허용 알고리즘은 고정 목록이다.
- 1회용 값(state, 시작 티켓, 동의 대기, 연결 확인 대기)은 시작한 브라우저의 묶음 쿠키 `g7_sso_bind` 에 묶는다. 세션에 두지 않는다.
- 강제 판정은 진입 경로가 아니라 회원 기준이다. 식별자 기준(도메인, 사이트 전체)은 비밀번호 시도 전, 회원 기준(역할, 연결 회원)은 비밀번호가 맞은 뒤에 본다(계정 열거 방지).
- 정책과 IdP 설정은 플러그인 표(`sso_site_policies`, `sso_identity_providers`)와 플러그인 API 로 저장한다. 코어 설정 저장 경로로 최고관리자 전용 칸을 우회 저장할 수 없게 `getSettingsSchema()` 는 비워 둔다.
- 행위자 없는 경로(SCIM, 주기 점검, 퇴사 뒤 탈퇴, 로그인 중 동기화)는 코어 `SystemOperation::run()` 안에서 돈다.
- 첫 로그인 동의 창이 받는 회원 항목은 요청의 `fields_version` 으로 갈린다. 동의 창이 코어 가입 화면과 같은 항목(환경설정 > 회원 > 가입 항목)을 그렸으면 받은 정의 버전을 싣는다 — `ConsentCompleteRequest` 가 규칙을 「약관 + `FieldRuleFactory`(가입 표면)」로 만들고(코어 가입 필터 `core.auth.register_validation_rules` 를 태우되 `verification_token` 규칙은 뺀다 — 이 라우트에는 본인인증 미들웨어가 없다), 버전이 다르면 409(`fields_changed`)로 답하며, `AccountResolver::register()` 가 검증된 항목 값(`custom_fields.*`, 확장이 선언한 항목 포함)을 가입 데이터에 실어 `defer` 없이 만든다. 창은 항목을 코어 `GET /api/auth/register/fields` 에서 받으며 `pending_source=sirsoft-sso`, `pending_token` 을 넘긴다 — `PrefillConsentFieldsListener`(필터 `core.field.filter_definitions`)가 대기의 신원으로 초기값과 잠금(`locked_reason = sso`)을 채운다. 잠그는 칸은 IdP 속성 규칙이 값을 준 칸 전부(`sync_lock`, `sync`, `first_only`)다: 생성 직후 동기화가 세 모드 모두 IdP 값으로 덮어쓰므로 고치게 두면 입력이 말없이 버려진다. 잠긴 칸은 규칙에서 빠지고 값을 보내도 IdP 값이 저장된다. 대기를 읽는 곁가지 조회는 `PendingStore::peek()` 을 쓴다(묶음이 맞지 않아도 대기를 폐기하지 않는다). `fields_version` 이 없는 요청(이 플러그인에 든 원본 창, 관리자 로그인 화면의 동의 창)은 약관만 받고 `defer` 로 만든다.
- 회원을 만드는 세 경로(로그인 시 자동 생성, SCIM 생성, 관리자 사전 등록과 CSV 가져오기)는 코어 `AuthService::register()` 에 `required_fields => 'defer'` 를 넘긴다(로그인 시 자동 생성은 위의 항목을 받은 동의 창을 뺀 나머지). 운영자가 환경설정 > 회원에서 필수로 둔 회원 항목 때문에 생성이 실패하지 않고, 코어가 `users.required_fields_deferred_at` 을 채워 그 회원이 처음 로그인하면 추가 정보 입력 화면으로 보낸다. 같은 세 경로와 회원을 고치는 두 경로(`AttributeSyncService::sync`, `ScimUserService::apply`)는 `nickname_policy => 'skip_field'` 도 넘긴다 — 회사 계정이 준 닉네임이 사이트의 닉네임 정책(중복 금지, 금지어)에 걸리면 코어가 닉네임만 빼고 저장하고 액션 `core.user.nickname_rejected` 를 발화한다. `RecordNicknameRejectionListener` 가 그 사유를 장부(`NicknameRejectionLedger`)에 적고 `AttributeSyncService` 가 꺼내 결과의 `skipped` 와 활동 로그 `sso.attributes_synced` 의 `properties.skipped` 에 싣는다. 회원을 고치는 호출을 새로 더할 때는 이 옵션을 함께 넘기고 `NicknameRejectionLedger::pull()` 로 사유를 꺼낸다(꺼내지 않으면 같은 요청의 다음 호출이 낡은 사유를 읽는다).

**의도적으로 하지 않는 것**

- 토큰과 세션을 직접 만들지 않는다.
- G7 은 SP 만 한다. IdP 역할은 하지 않는다.
- SCIM 삭제와 퇴사로 회원을 지우지 않는다 — 차단하고 토큰을 회수한다(게시글, 주문 기록 보존). 탈퇴(익명화)는 운영자가 「퇴사 N일 뒤 탈퇴」를 켰을 때만 한다.
- 코어 `users` 스키마를 바꾸지 않는다. 부서, 직급, 사번 같은 값은 `sso_user_attributes` 에 둔다.
- 비밀값(클라이언트 비밀값, SP 개인키, LDAP 서비스 계정 비밀번호, SCIM 토큰 원문), 토큰, 어설션 XML, id_token 원문을 응답이나 훅 인자에 싣지 않는다.
- 회원 단위 「강제 면제」는 두지 않는다. 그 역할은 비상 계정이 한다.
<!-- @intent END -->

## 2. 디렉토리 지도

<!-- @generated:directory-map START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 경로 | 역할 | 수정 시 필요한 절차 |
|---|---|---|
| `plugin.json` | manifest (버전 SSoT) | version 변경 시 package.json·package-lock.json·composer.json 동기화 |
| `plugin.php` | 진입 클래스 (선언형 표면 SSoT) | 표면 변경 시 `ext:docgen` 재실행 + 코어 최소 버전 검토 |
| `src/Http/Controllers/` | 컨트롤러 | API 표면 변경 시 `api:docgen` 재실행 |
| `src/Http/Requests/` | FormRequest (검증 SSoT) | 검증 규칙은 Service 가 아니라 여기에 둔다 |
| `src/Services/` | 비즈니스 로직 | Repository 인터페이스 주입 (구체 클래스 금지) |
| `src/Repositories/` | 데이터 접근 | 목록 쿼리는 컬럼 프루닝·정렬 화이트리스트 확인 |
| `src/Models/` | Eloquent 모델 | 스키마 변경 시 마이그레이션 + 업그레이드 스텝 동반 |
| `src/Listeners/` | 훅 리스너 | Repository 경유 (Model·DB 파사드 직접 접근 금지) |
| `src/routes/` | 라우트 | 모든 라우트에 `name()` 필수 |
| `database/migrations/` | 마이그레이션 | 한국어 comment + `down()` 필수, 기설치본은 업그레이드 스텝으로 백필 |
| `resources/layouts/` | 레이아웃 소스 `.g7.html` (짝 `.json` 은 굽기 산출. 컴포넌트 종류는 `data-type`, 컴파일은 템플릿과 무관) | `php artisan plugin:update sirsoft-sso --force` (빌드 불필요) |
| `resources/routes.json` | 라우트 → 레이아웃 매핑 | `php artisan plugin:update sirsoft-sso --force` |
| `resources/js/` | 프론트 엔트리·핸들러 | `php artisan plugin:build` → `php artisan plugin:update sirsoft-sso --force` |
| `resources/extensions/` | 다른 확장 레이아웃에 주입하는 조각 | `php artisan plugin:update sirsoft-sso --force` |
| `editor-spec.json` | 레이아웃 편집기 스펙 | `php artisan plugin:update sirsoft-sso --force` |
| `dist/` | 커밋되는 빌드 산출물 | `--production` 으로 재빌드 (sourceMappingURL 잔존 금지) |
| `tests/` | 테스트 | 변경 범위만 필터 실행 |
| `CHANGELOG.md` | 변경 이력 | 버전 상향 시 항목 추가 (미기재 시 버전 상향 불가) |
| `components.json` | 편집기 컴포넌트 선언 (레이아웃 작성자가 읽는 props 계약) | `php artisan plugin:update sirsoft-sso --force` |
| `assets/icon.svg` | 확장 아이콘 (관리자 목록·설치 마법사 카드) | 정사각·100KB 이하·스크립트 없는 SVG — 교체 후 `php artisan plugin:update sirsoft-sso --force` |
| `docs/` | 개발자 문서 | 표면 변경 시 `php artisan ext:docgen` 재실행 |
| `lang/` | 다국어 | 키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화 |
<!-- @generated:directory-map END -->

## 3. 핵심 흐름

<!-- @intent START -->
**리디렉션 로그인(OIDC, SAML)** — `LoginController@start`(`LoginStartRequest`) → `LoginFlowService::start` → `StateStore`(state 10분, 1회용, 묶음 쿠키 `g7_sso_bind` 의 sha256 저장) → 드라이버 `beginLogin`(302, SAML POST 바인딩은 자동 제출 폼) → IdP → `OidcController@callback`(`OidcCallbackRequest`) 또는 `SamlController@acs`(`SamlAcsRequest`) → `SamlSpService::acs` → `LoginFlowService::callback`(SAML 은 state 자리가 `RelayState`) → 드라이버 `completeLogin`(서명, 대상, 시각, nonce, `InResponseTo` 검증) → 필터 `sirsoft-sso.identity.filter_resolved` → `AccountResolver::resolve`: 기존 연결이면 그 회원(비활성 연결은 주기 점검과 로그인이 끈 것만 다시 켜고, SCIM 과 관리자가 끈 것은 `deprovisioned`), 없으면 소유 도메인 자동 → 연결 확인 대기(`PendingStore`, `/login?sso_link=`) → 자동 생성(동의가 필요하면 `/login?sso_pending=`) → `not_registered`. 회원이 정해지면 `issueHandoff`: 액션 `sirsoft-sso.account.after_resolve`(속성과 역할 동기화) → 필터 `sirsoft-sso.handoff.filter_context`(세션 참조, 세션 상한, 2단계 생략) → 코어 `LoginHandoffService::issue` → 302 `/login?login_handoff=`(관리자는 `/admin/login`) → 액션 `sirsoft-sso.auth.after_sso_login`. 실패는 `sso_login_attempts` 1행과 액션 `sirsoft-sso.auth.login_failed` 를 남기고 `/login?sso_error={코드}` 로 돌아간다.

**LDAP/AD 로그인** — 코어 `AuthService::login()` → 등록된 검증자 `SsoCredentialVerifier::match`(이메일이면 그 도메인을 소유한 첫 LDAP IdP, 사내 아이디면 `accept_username` 이 켜진 첫 LDAP IdP) → 코어 잠금 판정 → `verify` → `LdapDriver::authenticate`(`DirectoryClientInterface` — 실제는 `ExtLdapDirectoryClient`) → `AccountResolver` → `ExternalCredentialResult::authenticated($user, LoginFlowService::handoffContext(...))` 면 코어 `loginVerifiedUser()`, 동의나 연결 확인이 필요하면 `stepRequired`, 틀린 비밀번호는 `rejected(ldap_invalid_credentials, 실패로 셈)`, 디렉터리 장애는 `rejected(ldap_unavailable, 세지 않음)`.

**동의와 연결 확인 모달** — `PendingController@complete`(`ConsentCompleteRequest`), `LinkController@verify`(`LinkVerifyRequest`) → `LinkVerificationService::completeConsent`, `verifyPassword`, `verifyEmailCode`(묶음 쿠키 대조, 비밀번호 3회 실패면 대기 폐기 + 코어 실패 횟수 반영) → `AccountResolver` → `issueHandoff(..., forApi: true)` → 응답 `{handoff_code}` + 코어 묶음 쿠키(`issueTicket`) → 모달이 엔진 `loginHandoff` 액션을 실행한다.

**회사 계정으로 재확인** (받을 메일이 없는 SSO 회원의 민감 작업) — 코어 `POST /api/identity/challenges`(`sensitive_action`) → 회원별 수단 선택 `SsoReauthIdentityProvider::canChallengeUser`(활성 연결 보유, 수단 id `sirsoft-sso`, 채널 `sso`, 렌더 힌트 `external_redirect`) → `requestChallenge` 가 `LoginFlowService::issueStartUrl`(1회용 시작 티켓 60초)로 만든 이동 주소를 `publicPayload.redirect_url` 로 준다 → IdP 왕복 → `completeReauth`: `markVerified`(같은 회원의 같은 연결) → 코어 `IdentityVerificationService::verify` 로 코어 확인 토큰 발급 → 요청 맥락 `return_path` 에 `?verification_token=` 을 붙여 302(복귀 경로에 있던 화면 쿼리는 그대로 둔다). 복귀 주소와 이동 주소는 같은 출처 경로만 받는다(`SafeRedirect`, 아니면 `/mypage`). 화면은 돌아오면 본인인증을 다시 verify 하지 않고 그 토큰을 원래 저장 요청에 싣는다 — 토큰은 1회 소비, 짧은 수명, 본인 것만 코어 관문을 통과한다. 코어 확인 API 를 직접 불러도 왕복 증명이 없으면 통과하지 못한다.

**SCIM** — `ScimBearerAuth`(토큰 sha256 대조, SCIM 꺼진 IdP 는 403, 모든 응답을 `sso_scim_events` 에 기록) → `ScimUserController`(`ScimListRequest`, `ScimUserRequest`, `ScimPatchRequest`) → `ScimUserService`(`ScimFilter`, `ScimUserPayload`) → `ProvisioningAccountRepository`. DELETE 와 `active=false` → `DeprovisionService::deprovision` → 연결 비활성 → 다른 활성 연결이 없으면 코어 `UserService::blockUser` + `blocked_user_at` 기록 → `SessionService::endForUserIdp`. 모두 `SystemOperation::run('external:sirsoft-sso:scim', …)` 안에서. DELETE 뒤에도 연결 행이 남으므로 같은 id 의 GET 은 404 가 아니라 `active:false` 자원을 돌려준다(RFC 7644 §3.6 과 다른 점 — 회원과 기록 보존). 목록 `filter` 는 `userName`, `externalId`, `emails.value` 의 `eq "값"` 하나만 받고(그 밖은 400 `invalidFilter`), 정렬, Bulk, ETag, `/Me` 는 없다. Users PATCH 는 IdP 마다 다른 모양(경로 없는 값 객체, 점 경로, `Replace` 대문자, `emails[type eq "work"].value`)을 받고 모르는 속성은 무시하고 로그만 남긴다. Groups PATCH 의 모르는 경로는 400 `invalidPath`.

**주기 점검** — 스케줄 `DeprovisionCheckCommand` → `PeriodicCheckService::run`(IdP 마다 500 연결, 연결의 `periodic_checked_at` 이 간격보다 오래된 것) → LDAP `LdapDriver::lookup` / OIDC `OidcDriver::refresh`(새 갱신 토큰 저장) / SAML 은 지정한 재직 조회 LDAP IdP 의 `lookup` → `DeprovisionService::deprovision` 또는 `reactivate`.

**정책 저장과 판정** — `PolicyController@update`(`PolicyUpdateRequest`) → `PolicyService::update`(최고관리자 전용 칸, 비상 계정 0명, 활성 IdP 없는 사이트 강제를 거절) → `SitePolicyRepository::save` → 새 강제 대상의 비밀번호 토큰 회수. 판정은 코어 필터 `core.auth.password_auth_decision` → `EnforcePasswordPolicyListener` → `EnforcementPolicy::decide`.

**관리자 IdP 설정** — `IdpController`(`IdpStoreRequest`, `IdpUpdateRequest`, `IdpTestRequest`) → `IdpAdminService`(비밀값 마스크 규칙, 드라이버 `validateConfig`, `isAvailable` 확인 뒤 활성화) → `AdminIdpRepository`, `IdentityProviderRepository` → `IdentityProvider` 모델(`driver()`). 앱 키가 바뀌어 `secrets` 를 풀 수 없으면 관리 경로는 `readableSecrets()`(빈 배열)로 읽어 편집 화면이 비밀값 미설정으로 열리고, 다시 입력한 값이 옛 암호문을 덮는다(새 값 없이 저장하면 옛 암호문은 그대로 둔다). 두 모델(`IdentityProvider`, `SsoAccount`)은 `Concerns\SavesOverUnreadableEncryptedValues` 로 풀 수 없는 원본을 「바뀐 값」으로 본다 — 저장 때 Eloquent 가 옛 암호문을 풀다 멈추지 않게.
**제거와 데이터(코어 7.2.0)**: `uninstall()` 은 데이터 보존 제거에서도 불린다 — 메뉴는 지우지 않는다. 메뉴·권한·역할·알림 정의·본인확인 정책·스케줄은 「데이터도 함께 삭제」를 고른 제거에서 코어가 지운다(운영자가 만든 자식 메뉴는 최상위로 올리고, 운영자가 고친 항목은 지우기 직전 활동 로그에 남긴다). 플러그인이 코어 행에 더한 데이터는 `uninstallData()` 에서 지운다.

<!-- @intent END -->

## 4. 확장점

<!-- @generated:extension-points-summary START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 확장점 | 수 | 상세 |
|---|---|---|
| 발행 훅 | 2개 | [발행 훅](docs/extension-points.md#발행-훅) |
| 구독 훅 | 38개 | [구독 훅](docs/extension-points.md#구독-훅) |
| 훅 리스너 | 23개 | [훅 리스너](docs/extension-points.md#훅-리스너) |
| 레이아웃 확장 | 11개 | [레이아웃 확장](docs/extension-points.md#레이아웃-확장) |
| 미들웨어 | 3개 | [미들웨어](docs/extension-points.md#미들웨어) |
| 브로드캐스트 채널 | 0개 | [브로드캐스트 채널](docs/extension-points.md#브로드캐스트-채널) |
| 스케줄 | 4개 | [스케줄](docs/extension-points.md#스케줄) |
| 알림 정의 | 7개 | [알림 정의](docs/extension-points.md#알림-정의) |
<!-- @generated:extension-points-summary END -->

<!-- @intent START -->
- 새 프로토콜: `Contracts\ProtocolDriverInterface`(아이디, 비밀번호를 받는 방식이면 `CredentialDriverInterface` 도)를 구현하고 필터 `sirsoft-sso.protocol.filter_registered` 에서 새 키로 더한다. 내장 키(oidc, saml, ldap) 덮어쓰기는 경고 뒤 무시된다. 이 플러그인을 쓰지 않는 외부 인증(인증 프록시 헤더 등)은 코어 외부 인증 확장점만으로 붙는다.
- 신원 가공: 필터 `sirsoft-sso.identity.filter_resolved`(회원 결정 전), 자동 가입 데이터는 `sirsoft-sso.jit.filter_registration_data`.
- 로그인 문맥: 필터 `sirsoft-sso.handoff.filter_context` 로 코어 인계 문맥(`skip_two_factor`, `max_session_minutes`, `external_ref`)을 바꾼다.
- 후속 처리: 액션 `sirsoft-sso.account.after_resolve`, `after_link`, `after_unlink`, `after_deprovision`, `after_reactivate`, `sirsoft-sso.roles.after_sync`, `sirsoft-sso.auth.after_sso_login`, `sirsoft-sso.auth.login_failed`.
- 다른 확장이 부서, 직급, 사번을 읽을 때는 `Services\AttributeQuery::forUser()` 를 부르고, 값을 바꿀 때는 필터 `sirsoft-sso.user.filter_attributes` 를 잡는다. `sso_user_attributes` 를 직접 읽지 않는다.

전체 목록과 인자는 [docs/extension-points.md](docs/extension-points.md) 에 있다. 운영 절차는 [운영자 가이드](docs/operator-guide.md) 에 있다.
<!-- @intent END -->

## 5. 수정 시 동반 의무

- [ ] `_bundled` 에서만 수정하고 `php artisan plugin:update sirsoft-sso --force` 로 반영
- [ ] manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재
- [ ] 스키마 변경 시 마이그레이션(한국어 comment + `down()`) + 기설치본 백필용 업그레이드 스텝
- [ ] 다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화
- [ ] 로그인 화면 모달 조각(`resources/extensions/auth-login-sso-modals.g7.html`)을 고치면 기본 사용자 템플릿의 판본(`templates/_bundled/sirsoft-basic/extensions/sirsoft-sso/auth-login-sso-modals.g7.html`)도 함께 고친다 — 그 템플릿은 이 조각을 통째로 대체해 동의 창에 회원 항목을 그리므로 원본만 고치면 기본 템플릿 사이트에는 반영되지 않는다. 플러그인 버전을 마이너 이상 올리면 판본의 `version-constraint`(지금 `~1.0.0`)도 함께 넓힌다(벗어나면 코어가 원본 창으로 되돌린다). 동의 완료 요청의 규칙을 바꿀 때는 `fields_version` 이 있는 요청과 없는 요청 둘 다 받아들여지는지 `tests/Feature/ConsentFieldSetTest.php` 로 확인한다. 관리자 로그인 화면의 판본(`admin-login-sso-modals`)은 회원 항목을 받지 않는다
- [ ] API 표면 변경 시 `php artisan api:docgen --scope=plugin:sirsoft-sso` 재실행 + `docs/api/**` 갱신 (SCIM 주소는 SCIM 응답 형식 그대로라 G7 응답 봉투를 쓰지 않는다)
- [ ] 로그인한 회원이 부르는 쓰기 라우트(POST, PUT, PATCH, DELETE)를 더할 때 코어 필터 `core.required_fields.filter_exempt_routes` 에 보탤지 판단한다. 코어는 추가 정보를 아직 입력하지 않은 회원의 쓰기 요청을 403 `required_fields_pending` 으로 막는다. 그런 회원도 반드시 할 수 있어야 하는 요청(계정 연결과 해제처럼 로그인 수단을 다루는 요청)이면 `src/Listeners/ExemptRequiredFieldsRoutesListener.php` 의 `ROUTES` 에 라우트 이름을 더하고, 사용자 콘텐츠를 만드는 요청이면 더하지 않는다
- [ ] 레이아웃은 `.g7.html` 을 고치고 `php artisan layout:decompile <경로> --sync` 로 짝 `.json` 을 다시 굽는다 (짝을 굽지 않으면 화면에 반영되지 않음) — 클래스는 자기 앞글자(`sso:` 접두 유틸리티 · `sso-*`)로만 쓰고, 새 클래스를 쓰면 `php artisan plugin:build sirsoft-sso --production` 으로 `dist/css/plugin.css` 를 다시 만든다(템플릿 CSS 에 기대지 않는다)
- [ ] `composer.json` 의존성을 바꾸면 `php artisan plugin:vendor-bundle sirsoft-sso` 로 vendor-bundle 을 다시 만들고 `composer.lock` 과 함께 반영
- [ ] 로그인 관련 변경은 코어 로그인 인계 계약(`docs/backend/authentication.md` 「외부 인증 로그인」, 「외부 인증 확장점」)과 맞는지 확인하고, `grep -rnE "createToken\(|Auth::login\(" src` 가 0 인지 확인
- [ ] IdP 콘솔에 넣는 주소(ACS, SLS, Redirect URI, 메타데이터, back-channel, 로그아웃 복귀)는 `SiteUrl::absolute()` 로 만든다 — `url()`, `route()` 절대 주소 금지
- [ ] 행위자 없는 경로(SCIM, 스케줄, 로그인 중 동기화)에서 회원 수정, 차단, 탈퇴, 역할 변경을 부르면 `SystemOperation::run()` 안에서
- [ ] 회원을 가리키는 새 표를 더하면 `core.user.after_withdraw`, `core.user.before_delete` 를 우선순위 50 보다 작은 수로 구독해 먼저 지우고 `Bootstrap\Tables` 에 자식 표부터 등록
- [ ] 훅 인자와 API 응답에 비밀값, 토큰, 어설션 XML, id_token 원문이 실리지 않는지 확인(`secrets` 는 마스크만)
- [ ] 브라우저가 만드는 주소는 `resources/js/apiUrl.ts` 의 `apiUrl()`, `sitePath()`, `routePath()` 만 쓴다 — 이동, 링크, 요청 주소는 `sitePath()`/`apiUrl()` 로 접두사를 붙이고, `location.pathname` 을 화면 판정에 쓸 때는 `routePath()` 로 벗긴다. 이 파일은 번들 확장 전체가 바이트 동일한 사본으로 갖는다. 어기면 하위 폴더 설치(`https://host/g7/`)에서만 오류 없이 회사 계정 로그인과 계정 연결 시작이 사이트 밖으로 나간다. 서버가 주는 시작 주소는 앱 기준 경로이고, 같은 출처 절대 주소로 오면 `routePath()` 로 벗긴 뒤 허용 접두와 대조한다

## 6. 금지 패턴

<!-- @intent START -->
이 확장에서 실제로 막아 둔 오용입니다.

| 금지 | 올바른 사용 | 이유 |
|---|---|---|
| 플러그인 코드에서 `createToken()`, `Auth::login()` 호출 | 코어 `LoginHandoffService::issue()`(web) / `issueTicket()`(api), LDAP 은 검증자의 `ExternalCredentialResult::authenticated()` | 잠금, 상태, 2단계 인증, 비밀번호 변경, 휴면, `after_login` 이 빠진다 |
| 강제를 관리자 로그인 화면에서만 판정 | 회원 기준 판정(`EnforcementPolicy`) | 사용자 `/login` 으로 받은 토큰도 관리자 API 에서 통한다 |
| 역할, 연결 기준 강제를 비밀번호 시도 전에 판정 | 도메인, 사이트 전체만 시도 전, 역할과 연결은 비밀번호가 맞은 뒤 | 「이 이메일은 관리자」가 드러난다(계정 열거) |
| LDAP 소유 도메인 강제를 검증자 일치보다 먼저 판정 | 코어가 검증자 `match()` 를 먼저 묻고, 강제 판정은 일치하지 않았을 때만 | 디렉터리 비밀번호가 디렉터리에 닿지 못하고 막힌다 |
| 비상 계정을 비밀번호만으로 통과 | 강제 중 비상 계정은 `requireTwoFactor` 로 2단계 인증 필수 | 비밀번호 하나로 강제를 우회한다 |
| SAML 응답을 직접 파싱하거나 `DOMDocument` 로 서명 위치를 찾기 | `OneLogin\Saml2\Response` + `isValid()`, `strict=true` | 서명 감싸기(XSW)로 아무 계정 로그인 |
| `OneLogin\Saml2\Auth::processResponse()`, `login()` 을 그대로 쓰기 | `Utils::setBaseURL(SiteUrl)`, `Response` + `isValid()`, `stay: true` | PHP 전역 값으로 주소를 만들어 사이트 주소와 어긋나고 `exit` 로 응답이 끊긴다 |
| `InResponseTo` 없는 SAML 응답 받기 | IdP-initiated 를 켠 IdP 만, 응답 ID 1회 사용 + 유효 시각 + 같은 출처 RelayState | IdP-initiated 를 끈 IdP 에서 위조 응답 수락 |
| 서명 없는 IdP 발 SAML 로그아웃 요청 받기 | 서명된 LogoutRequest 만 처리 | NameID 만 알면 남을 로그아웃시킨다 |
| id_token 헤더의 `alg` 를 그대로 믿기 | 허용 목록 RS256, RS384, RS512, ES256, ES384 고정 | `none`, HS256 위조 |
| JWKS 를 오래 캐시 | 1시간 캐시 + `kid` 불일치 때 1회 다시 받기 | IdP 키 회전 뒤 전원 로그인 실패 |
| LDAP 필터에 입력을 그대로 붙이기 | `ldap_escape(…, '', LDAP_ESCAPE_FILTER)` | `*)(uid=*` 로 아무 계정 검색 |
| 빈 비밀번호로 LDAP 바인드 | 바인드 전에 거절 | 익명 바인드 성공을 인증으로 오인 |
| LDAP 검색 결과가 2건 이상인데 첫 건으로 진행 | 정확히 1건일 때만(주기 점검 조회는 판정 보류) | 다른 사람 계정으로 로그인, 멀쩡한 직원 퇴사 처리 |
| subject 칸을 기본 collation 으로 만들기, AD `objectGUID` 를 바이너리로 저장 | `utf8mb4_bin`, GUID 문자열 | 대소문자만 다른 신원이 한 회원에 묶이고 비교가 깨진다 |
| 역할 매핑에 `roles()->sync()` | `UserService::bulkUpdateRoles` 로 붙이기와 떼기 + `sso_role_grants` 기록 | 관리자가 직접 준 역할이 지워진다 |
| IdP 이메일로 `users.email` 을 바로 덮기 | 소유 도메인일 때만, 다른 회원이 쓰면 건너뛰고 알림 | UNIQUE 충돌, 남의 계정 이메일 탈취 |
| SCIM DELETE 로 회원 삭제 | `DeprovisionService::deprovision`(차단 + 토큰 회수) | 게시글, 주문 기록 손실 |
| 퇴사 처리 때 다른 IdP 연결을 보지 않기 | 다른 활성 연결이 없을 때만 차단 | 겸직 직원이 한 회사 퇴사로 전부 막힌다 |
| 재활성 때 무조건 차단 해제 | `users.blocked_at == sso_accounts.blocked_user_at` 일 때만 | 관리자가 다른 이유로 차단한 회원이 풀린다 |
| OIDC 갱신 결과를 저장하지 않기 | 새 갱신 토큰으로 교체 | 회전하는 IdP 에서 다음 점검이 `invalid_grant` 로 오판 |
| 동의 대기, 연결 확인 토큰을 주소만으로 받기 | 묶음 쿠키 대조(`StateStore`, `PendingStore`) | 공격자 신원의 연결 주소를 피해자에게 보내 피해자 계정에 붙인다 |
| 마이페이지 연결, 재확인, 관리자 테스트를 `GET login/{idp}?intent=…` 로 시작 | API 가 1회용 시작 티켓 발급(`issueStartUrl`) | 브라우저 이동에는 Bearer 토큰이 없어 누구 계정인지 모른다 |
| 정책 값을 `getSettingsSchema()` 에 넣기 | `sso_site_policies` + 플러그인 API | 코어 설정 저장으로 최고관리자 검사를 우회한다 |
| 비상 계정 0명 저장 허용 | `PolicyService::update` 가 422 `emergency_required` | IdP 장애 때 복구할 길이 없다 |
| 시스템 경로에서 `updateUser`, 탈퇴, 차단을 그냥 부르기 | `SystemOperation::run()` | 운영자가 건 본인인증 정책이 대상 회원으로 판정돼 428 |
| `install()` 에서 `src/` 상수 참조 | 리터럴만 쓰거나 첫 읽기 때 만든다(비상 계정 기본값은 `PolicyService::current()`) | PSR-4 등록 전이라 치명 오류 |
| 화면 이동 주소를 외부 주소로 받기 | 같은 출처 경로만(`SafeRedirect`, 프론트 `safeStartPath`) | 열린 리디렉션 |
<!-- @intent END -->

## 7. 테스트 실행

<!-- @generated:test-commands START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 개수 | 위치 |
|---|---|---|
| PHPUnit | 99개 | `plugins/_bundled/sirsoft-sso/tests` |
| Vitest | 10개 | `vitest.config.ts` |
| Playwright | 5개 | `tests/Playwright` |
| 시나리오 매니페스트 | 37개 | `tests/scenarios` |

기저 TestCase: `tests/PluginTestCase.php` — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).

```bash
# PHPUnit (변경 범위만) (Bash)
php vendor/bin/phpunit plugins/_bundled/sirsoft-sso/tests --filter='<대상클래스>'

# Vitest (확장 디렉토리에서) (PowerShell)
cd plugins/_bundled/sirsoft-sso && powershell -Command "npm run test:run -- <대상>"

# Playwright E2E (확장 디렉토리에서) (Bash)
cd plugins/_bundled/sirsoft-sso && npm run test:e2e -- specs/<대상>.spec.ts

```

무필터 전체 실행은 금지되어 있습니다 — 변경 범위에 걸리는 대상만 지정해 실행합니다.
<!-- @generated:test-commands END -->

## 8. 문서 목차

<!-- @generated:docs-index START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 문서 | 내용 | 상태 |
|---|---|---|
| [docs/README.md](docs/README.md) | 문서 통합 목차와 실측 집계 | ✅ |
| [docs/architecture.md](docs/architecture.md) | 설계 의도·계층 지도·디렉토리 맵 | ✅ |
| [docs/extension-points.md](docs/extension-points.md) | 발행/구독 훅·미들웨어·채널·스케줄 | ✅ |
| [docs/data-model.md](docs/data-model.md) | 모델·소유 테이블·마이그레이션·Enum | ✅ |
| [docs/settings.md](docs/settings.md) | 설정 스키마·권한·메뉴·라우트·의존 관계 | ✅ |
| [docs/frontend.md](docs/frontend.md) | 레이아웃·액션 핸들러·전역 진입점·에셋 | ✅ |
| [docs/editor-spec.md](docs/editor-spec.md) | 레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터 | ✅ |
| [docs/api/](docs/api/README.md) | API 레퍼런스 (엔드포인트별 파라미터·응답 필드) | ✅ |
| [CHANGELOG.md](CHANGELOG.md) | 변경 이력 | ✅ |
<!-- @generated:docs-index END -->
