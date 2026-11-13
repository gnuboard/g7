# SSO 연동 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 2종 / 호출 지점 32곳. 이 중 2종은 `getHooks()` 선언에 없어 소스에서 자동 감지한 것입니다 — 선언에 추가하면 유형과 설명이 함께 실립니다. 훅 이름이 상수·변수로 조립된 호출이 30곳 있어 호출 위치가 표에 다 실리지 않을 수 있습니다.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `core.auth.login_failed` | action | — | `src/Services/LinkVerificationService.php:168` |
| `core.auth.register_validation_rules` | filter | — | `src/Http/Requests/ConsentCompleteRequest.php:76` |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
위 표는 소스에서 훅 이름이 문자열로 바로 보이는 호출만 셉니다. 이 플러그인은 훅 이름을 클래스 상수로 두고 발행하므로, 공개 계약은 `plugin.php` 의 `getHooks()` 선언(ko, en, ja 설명 포함)입니다. 표의 `core.auth.login_failed` 는 코어 훅으로, 연결 확인 창에서 비밀번호를 틀린 시도를 비밀번호 로그인 실패와 같게 세려고 발행합니다.

| 훅 | 유형 | 인자 | 언제 |
|---|---|---|---|
| `sirsoft-sso.protocol.filter_registered` | filter | `(array $drivers)` | 드라이버 목록을 만들 때 — 새 프로토콜 추가(내장 키 덮어쓰기는 무시) |
| `sirsoft-sso.identity.filter_resolved` | filter | `(ExternalIdentity $identity, IdentityProvider $idp)` | IdP 응답 검증 뒤, 회원 결정 전 |
| `sirsoft-sso.jit.filter_registration_data` | filter | `(array $data, ExternalIdentity, IdentityProvider)` | 자동 가입 데이터 |
| `sirsoft-sso.handoff.filter_context` | filter | `(array $context, User, SsoAccount, ExternalIdentity, IdentityProvider)` | 코어 인계 코드 발급 직전 |
| `sirsoft-sso.user.filter_attributes` | filter | `(array $attributes, User $user)` | `AttributeQuery::forUser()` 가 추가 속성을 돌려주기 전 |
| `sirsoft-sso.account.after_resolve` | action | `(User, SsoAccount, ExternalIdentity, string $outcome)` | 회원이 정해진 직후(outcome: login, jit, domain, verify, link) |
| `sirsoft-sso.account.after_link` | action | `(SsoAccount, User, string $via)` | 연결 직후(via: jit, domain, verify, admin, csv, scim) |
| `sirsoft-sso.account.before_unlink`, `after_unlink` | action | `(SsoAccount, User, string $reason)` | 연결 해제 전후 |
| `sirsoft-sso.account.after_deprovision` | action | `(SsoAccount, User, string $source)` | 퇴사 처리 직후(source: scim, periodic, login, admin) |
| `sirsoft-sso.account.after_reactivate` | action | `(SsoAccount, User, string $source)` | 비활성 연결이 다시 켜진 직후 |
| `sirsoft-sso.account.email_sync_conflict` | action | `(User, SsoAccount, string $idpEmail)` | IdP 이메일을 다른 회원이 써서 건너뛴 직후 |
| `sirsoft-sso.account.super_admin_deprovisioned` | action | `(User, SsoAccount, string $source)` | 최고관리자 퇴사(차단 대신 토큰만 회수) |
| `sirsoft-sso.account.admin_withdraw_skipped` | action | `(User, SsoAccount)` | 퇴사 뒤 탈퇴 기한이 지났지만 관리자 계정이라 자동 탈퇴를 건너뜀(연결마다 한 번) |
| `sirsoft-sso.roles.after_sync` | action | `(User, array $added, array $removed, IdentityProvider $idp, ?string $skipped)` | 그룹 → 역할 매핑 반영 직후, 또는 바꾸지 않고 건너뛴 직후(`$skipped`: `super_admin` 최고관리자 / `core_guard` 코어 등급 검사, 바뀐 것만 있으면 null). 활동 로그 `sso.roles_synced` 의 IdP 키와 건너뛴 사유도 이 인자에서 |
| `sirsoft-sso.auth.after_sso_login` | action | `(User, SsoAccount)` | 인계 코드 발급 직후 |
| `sirsoft-sso.auth.login_failed` | action | `(?IdentityProvider, string $errorCode, string $intent, string $guard, string $outcome)` | 왕복 실패(outcome: failed 는 IdP, 검증 실패 / denied 는 정책 거절) |
| `sirsoft-sso.auth.emergency_login` | action | `(User)` | 강제 중 비상 계정의 비밀번호 로그인 |
| `sirsoft-sso.auth.password_reset_redirected` | action | `(User, ?IdentityProvider)` | 강제 대상 회원의 비밀번호 찾기를 막고 안내 메일을 보내기 직전 |
| `sirsoft-sso.idp.failing` | action | `(IdentityProvider, int $failureCount, string $lastErrorCode)` | IdP 연속 실패가 기준에 닿음(IdP 마다 하루 1번) |
| `sirsoft-sso.idp.cert_expiring` | action | `(IdentityProvider, int $daysLeft)` | IdP 서명 인증서 만료 임박 |

- 인자에는 비밀값, 토큰, 어설션 XML, id_token 원문이 실리지 않습니다.
- `after_sso_login` 은 인계 코드를 발급한 시점이라 코어 관문(잠금, 2단계 인증) 통과를 뜻하지 않습니다. 실제 로그인 완료는 코어 `core.auth.after_login`(문맥 `login_method = sso:{IdP 키}`)을 잡습니다.
- 알림 일곱 개는 아래 「알림 정의」의 발화 훅을 그대로 씁니다.
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.auth.after_login` | action (미선언) | `EmergencyLoginListener` | `onLogin` | 60 |
| `core.auth.after_login` | action (미선언) | `SsoSessionListener` | `afterLogin` | 20 |
| `core.auth.login_auto_redirect` | filter | `AutoRedirectListener` | `redirect` | 20 |
| `core.auth.login_discovery` | filter | `DiscoveryListener` | `discover` | 20 |
| `core.auth.logout` | action (미선언) | `SsoSessionListener` | `afterLogout` | 20 |
| `core.auth.logout_redirect_url` | filter | `SsoSessionListener` | `logoutRedirect` | 20 |
| `core.auth.password_auth_decision` | filter | `EnforcePasswordPolicyListener` | `decide` | 20 |
| `core.auth.password_reset_blocked` | action (미선언) | `PasswordResetBlockedNotifyListener` | `onBlocked` | 20 |
| `core.auth.self_registration_open` | filter | `SelfRegistrationListener` | `open` | 20 |
| `core.field.filter_definitions` | filter | `PrefillConsentFieldsListener` | `prefill` | 20 |
| `core.identity.registered_providers` | filter | `RegisterSsoReauthProviderListener` | `register` | 25 |
| `core.notification.filter_default_definitions` | filter | `NotificationDataListener` | `defaults` | 20 |
| `core.required_fields.filter_exempt_routes` | filter | `ExemptRequiredFieldsRoutesListener` | `exemptRoutes` | 10 |
| `core.user.after_withdraw` | action (미선언) | `CleanSsoAccountsOnUserLeave` | `onWithdraw` | - |
| `core.user.after_withdraw` | action (미선언) | `CleanProvisioningDataOnUserLeave` | `handle` | - |
| `core.user.before_delete` | action (미선언) | `CleanSsoAccountsOnUserLeave` | `onDelete` | - |
| `core.user.before_delete` | action (미선언) | `CleanProvisioningDataOnUserLeave` | `handle` | - |
| `core.user.filter_list_rows` | filter | `MemberListSsoListener` | `addColumns` | 30 |
| `core.user.filter_resource_data` | filter | `MemberDetailSsoListener` | `inject` | 60 |
| `core.user.list_query_extensions` | filter | `MemberListSsoListener` | `addQueryExtension` | 30 |
| `core.user.list_validation_attributes` | filter | `MemberListSsoListener` | `addValidationAttributes` | 30 |
| `core.user.list_validation_rules` | filter | `MemberListSsoListener` | `addValidationRules` | 30 |
| `core.user.locked_field_reasons` | filter | `LockedFieldsListener` | `lockedFieldReasons` | 20 |
| `core.user.locked_fields` | filter | `LockedFieldsListener` | `lockedFields` | 20 |
| `core.user.nickname_rejected` | action (미선언) | `RecordNicknameRejectionListener` | `onRejected` | 20 |
| `sirsoft-gdpr.necessary_storage.filter_provided_keys` | filter | `ProvideNecessaryStorageKeysListener` | `provide` | 10 |
| `sirsoft-sso.account.after_deprovision` | action (미선언) | `ActivityLogListener` | `onDeprovisioned` | 50 |
| `sirsoft-sso.account.after_link` | action (미선언) | `ActivityLogListener` | `onLinked` | 50 |
| `sirsoft-sso.account.after_reactivate` | action (미선언) | `ActivityLogListener` | `onReactivated` | 50 |
| `sirsoft-sso.account.after_resolve` | action (미선언) | `SyncOnAccountResolvedListener` | `handle` | 20 |
| `sirsoft-sso.account.after_unlink` | action (미선언) | `ActivityLogListener` | `onUnlinked` | 50 |
| `sirsoft-sso.auth.after_sso_login` | action (미선언) | `ActivityLogListener` | `onLogin` | 50 |
| `sirsoft-sso.auth.login_failed` | action (미선언) | `ActivityLogListener` | `onLoginFailed` | 50 |
| `sirsoft-sso.auth.login_failed` | action (미선언) | `IdpFailureMonitorListener` | `onFailed` | 60 |
| `sirsoft-sso.handoff.filter_context` | filter | `TwoFactorContextListener` | `apply` | 20 |
| `sirsoft-sso.handoff.filter_context` | filter | `SsoSessionListener` | `handoffContext` | 20 |
| `sirsoft-sso.notification.extract_data` | filter | `NotificationDataListener` | `extract` | 10 |
| `sirsoft-sso.roles.after_sync` | action (미선언) | `ActivityLogListener` | `onRolesSynced` | 50 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
대부분 코어 7.2.0 외부 인증 확장점에 답하는 구독입니다.

- 로그인 관문: `core.auth.password_auth_decision`(강제와 비상 계정, 비밀번호 만들기 차단), `core.auth.login_discovery`(이메일 먼저 입력 — 식별자 도메인만 보고 회원 존재와 무관하게 같은 모양), `core.auth.login_auto_redirect`(사이트 전체 강제 + 자동 이동 IdP 일 때만), `core.auth.self_registration_open`(사이트 전체 강제면 닫음), `core.auth.password_reset_blocked`(재설정 링크 대신 회사 계정 안내 메일).
- LDAP 검증자: 필터 `core.auth.registered_credential_verifiers` 를 `Services\Ldap\RegisterCredentialVerifierListener` 가 구독합니다. 리스너 파일이 `src/Listeners` 밖에 있어 위 표에 실리지 않습니다. 검증자는 늘 등록하고, 실제로 쓰일지는 `SsoCredentialVerifier::isAvailable()`(ext-ldap 과 활성 LDAP IdP)이 정합니다.
- 재확인 수단: `core.identity.registered_providers` 에 「회사 계정으로 재확인」(`sirsoft-sso`)을 더합니다.
- 세션: `core.auth.after_login`(토큰 id 를 SSO 세션에 붙이고, 강제 중 비상 계정 로그인을 기록), `core.auth.logout_redirect_url`(코어가 토큰을 지우기 전에 IdP 로그아웃 티켓 주소), `core.auth.logout`(세션 종료).
- 회원 정리: `core.user.after_withdraw`, `core.user.before_delete` — 프로비저닝 표 정리(`CleanProvisioningDataOnUserLeave`, 우선순위 40)가 연결 정리(`CleanSsoAccountsOnUserLeave`, 50)보다 먼저 돕니다. 표에 CASCADE 가 없어 이 순서가 FK 오류를 막습니다.
- 관리자 회원 화면: `core.user.filter_resource_data`(상세 응답의 `sso` 키), `core.user.filter_list_rows`(부서, 직급 열 — 페이지당 1쿼리), `core.user.list_query_extensions`, `list_validation_rules`, `list_validation_attributes`(IdP 필터 `ext_filters[]=sirsoft-sso.idp={IdP 키}`). 회원 연결 열람 권한이 없으면 아무것도 더하지 않습니다.
- `core.user.locked_fields`: 활성 연결 IdP 들이 「덮어쓰기와 수정 잠금」으로 둔 코어 칸의 합집합.
- 자기 훅 구독: 활동 로그(`ActivityLogListener`), IdP 연속 실패 감시(`IdpFailureMonitorListener` — outcome `failed` 만 센다), 속성과 역할 동기화(`SyncOnAccountResolvedListener`), 인계 문맥(`TwoFactorContextListener`, `SsoSessionListener`). 다른 확장도 같은 훅을 잡아 덧붙일 수 있습니다.
- `sirsoft-gdpr.necessary_storage.filter_provided_keys`: GDPR 플러그인이 동의 전 쿠키를 지울 때 묶음 쿠키 `g7_sso_bind` 를 필수 저장소로 알립니다. GDPR 플러그인이 없으면 아무 일도 하지 않습니다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `ActivityLogListener` | 7개 | 명시 등록 | ✅ | `src/Listeners/Admin/ActivityLogListener.php` |
| `AutoRedirectListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/Policy/AutoRedirectListener.php` |
| `CleanProvisioningDataOnUserLeave` | 2개 | 명시 등록 | ✅ | `src/Listeners/Provisioning/CleanProvisioningDataOnUserLeave.php` |
| `CleanSsoAccountsOnUserLeave` | 2개 | 명시 등록 | ✅ | `src/Listeners/CleanSsoAccountsOnUserLeave.php` |
| `DiscoveryListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/Policy/DiscoveryListener.php` |
| `EmergencyLoginListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/Policy/EmergencyLoginListener.php` |
| `EnforcePasswordPolicyListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/Policy/EnforcePasswordPolicyListener.php` |
| `ExemptRequiredFieldsRoutesListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ExemptRequiredFieldsRoutesListener.php` |
| `IdpFailureMonitorListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/Admin/IdpFailureMonitorListener.php` |
| `LockedFieldsListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/Provisioning/LockedFieldsListener.php` |
| `MemberDetailSsoListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/Admin/MemberDetailSsoListener.php` |
| `MemberListSsoListener` | 4개 | 명시 등록 | ✅ | `src/Listeners/Admin/MemberListSsoListener.php` |
| `NotificationDataListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/Admin/NotificationDataListener.php` |
| `PasswordResetBlockedNotifyListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/Policy/PasswordResetBlockedNotifyListener.php` |
| `PrefillConsentFieldsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/Provisioning/PrefillConsentFieldsListener.php` |
| `ProvideNecessaryStorageKeysListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ProvideNecessaryStorageKeysListener.php` |
| `RecordNicknameRejectionListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RecordNicknameRejectionListener.php` |
| `RegisterIpRestrictionExemptPathsListener` | 0개 | 명시 등록 | ✅ | `src/Listeners/RegisterIpRestrictionExemptPathsListener.php` |
| `RegisterSsoReauthProviderListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RegisterSsoReauthProviderListener.php` |
| `SelfRegistrationListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/Policy/SelfRegistrationListener.php` |
| `SsoSessionListener` | 4개 | 명시 등록 | ✅ | `src/Listeners/Provisioning/SsoSessionListener.php` |
| `SyncOnAccountResolvedListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/Provisioning/SyncOnAccountResolvedListener.php` |
| `TwoFactorContextListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/Policy/TwoFactorContextListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
리스너는 모두 `getHookListeners()` 에서 명시 등록합니다. `Bootstrap\Listeners` 가 영역 파일(`CoreFlow`, `Oidc`, `Saml`, `Ldap`, `Provisioning`, `Policy`, `Admin`)의 목록을 합치며, 위 표에 없는 `Services\Ldap\RegisterCredentialVerifierListener` 도 `Ldap` 영역으로 등록됩니다. 모든 구독이 `sync: true` 라 로그인 판정과 회원 정리가 응답 전에 끝납니다. 리스너는 판정만 하고 일은 서비스(`EnforcementPolicy`, `DiscoveryService`, `SessionService`, `AttributeSyncService`, `RoleSyncService`)에 넘깁니다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `resources/extensions/admin-login-sso-buttons.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `admin_login_extensions` |
| `resources/extensions/admin-login-sso-modals.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `admin_login` |
| `resources/extensions/admin-settings-sso-reauth-result.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `admin_settings` |
| `resources/extensions/admin-user-detail-sso-tab.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `admin_user_detail` |
| `resources/extensions/admin-user-form-sso-reauth-result.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `admin_user_form` |
| `resources/extensions/admin-user-list-sso-columns.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `admin_user_list` |
| `resources/extensions/admin-user-list-sso-filter.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `admin_user_list_filters` |
| `resources/extensions/auth-login-sso-buttons.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `auth_login_extensions` |
| `resources/extensions/auth-login-sso-modals.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `auth/login` |
| `resources/extensions/mypage-profile-edit-sso-reauth-result.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `mypage/profile-edit` |
| `resources/extensions/mypage-sso-accounts-card.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `mypage/profile` |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
사용자 화면과 코어 관리자 회원 화면은 자기 레이아웃이 아니라 템플릿 화면에 주입합니다.

- 로그인 버튼 `auth-login-sso-buttons`(확장점 `auth_login_extensions`), `admin-login-sso-buttons`(`admin_login_extensions`): 공개 IdP 목록(`GET /api/plugins/sirsoft-sso/idps`)으로 「회사 계정으로 로그인」 버튼을 그리고 핸들러 `sirsoft-sso.startLogin` 으로 시작 주소에 전체 페이지 이동합니다. 버튼 표시를 끈 IdP 는 목록에 없습니다.
- 모달 `auth-login-sso-modals`, `admin-login-sso-modals`: 쿼리 `sso_pending`(처음 로그인 약관 동의), `sso_link`(기존 계정 연결 확인), `sso_error`(오류 토스트)를 읽습니다. 완료 응답의 `handoff_code` 로 엔진 `loginHandoff` 를 바로 실행합니다(같은 화면에서 쿼리만 바꾸면 인계 교환이 다시 돌지 않을 수 있다). 화면 주인의 전역 로그인 상태를 쓰지 않고 `_local` 만 씁니다.
- 회원 상세 탭 `admin-user-detail-sso-tab`(`admin_user_detail`, 탭 이름 「SSO」): 연결, 추가 속성, 역할 출처(매핑, 수동). 연결 추가와 해제는 직접 하지 않고 「회원 연결」 화면으로 보냅니다 — 그 화면의 권한과 관리자 대상 제한을 그대로 타게 하려는 것입니다.
- 회원 목록 `admin-user-list-sso-filter`(확장점 `admin_user_list_filters`), `admin-user-list-sso-columns`(`admin_user_list` — 「부서」, 「직급」 열을 데이터 그리드 열과 열 선택기 양쪽에 넣는다). 필터의 IdP 목록은 `GET /api/plugins/sirsoft-sso/admin/accounts` 응답의 `idps` 로 받습니다 — 회원 연결 열람 권한만 있어도 필터를 쓰게 하려는 것입니다.
- 마이페이지 `mypage-sso-accounts-card`(`mypage/profile`): 「연결된 회사 계정」 카드. [연결하기]는 `sirsoft-sso.startWithTicket` 으로 `POST me/links/start` 의 시작 주소로 이동하고, [연결 해제]는 비밀번호 없는 회원의 유일한 로그인 수단이거나 회사 계정 로그인만 허용되는 회원이면 막힙니다.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 미들웨어 | 부착 대상(targets) | 우선순위 |
|---|---|---|
| `MockGateMiddleware` | `web.plugins.sirsoft-sso.mock.*` | - |
| `AddQueuedCookiesToResponse` | `api.plugins.sirsoft-sso.*`, `api.identity.challenges.request` | - |
| `ScimBearerAuth` | `api.plugins.sirsoft-sso.scim.*` | - |
<!-- @generated:middleware END -->

<!-- @intent START -->
- `ScimBearerAuth`: SCIM 주소에만 붙습니다. IdP 마다 발급한 Bearer 토큰의 sha256 을 `hash_equals` 로 대조하고, 그 IdP 의 SCIM 이 꺼져 있으면 토큰이 맞아도 403 입니다. 인증한 IdP 를 요청 속성(`sirsoft-sso.scim_idp`)에 두므로 컨트롤러는 주소의 `{idp}` 를 믿지 않습니다. 거절을 포함한 모든 응답을 `sso_scim_events` 에 남기고 본문은 남기지 않습니다.
- `AddQueuedCookiesToResponse`: api 그룹에는 쿠키 대기열 미들웨어가 없어, 시작 티켓 API(`me/links/start`, `admin/idps/{id}/test`)와 코어 본인인증 요청(`api.identity.challenges.request` — 회사 계정 재확인 시작)이 굽는 묶음 쿠키 `g7_sso_bind` 가 응답에 실리지 않습니다. 그래서 이 라우트들에만 붙입니다.
- `MockGateMiddleware`: 모의 IdP 라우트(`web.plugins.sirsoft-sso.mock.*`)는 `sirsoft-sso:mock --enable` 로 켠 동안만 열리고, 꺼져 있으면 404 입니다. 모의 라우트 이름은 `mock.` 으로 시작해야 이 미들웨어가 붙습니다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
실시간 화면이 없어 채널을 두지 않습니다. 관리자에게 알릴 일은 알림 정의(메일, 사이트 알림)로 보냅니다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 스케줄 | 주기 | 설명 |
|---|---|---|
| `deprovision-check` | `*/5 * * * *` | SSO 주기 점검 |
| `withdraw-deprovisioned` | `10 4 * * *` | SSO 퇴사 후 탈퇴 |
| `metadata-refresh` | `20 4 * * *` | SSO IdP 메타데이터 갱신 |
| `prune` | `40 4 * * *` | SSO 이력 보존 정리 |
<!-- @generated:schedules END -->

<!-- @intent START -->
네 명령 모두 서버 스케줄러가 돌아야 실행됩니다. 각 명령은 실패해도 예외를 밖으로 던지지 않아 다른 확장의 스케줄을 멈추지 않습니다.

- `deprovision-check`(5분마다, 겹침 방지): 주기 점검을 켠 IdP 만 처리합니다. IdP 의 점검 간격(기본 60분)은 연결별 마지막 점검 시각(`periodic_checked_at`)으로 판정하고, 한 회차에 IdP 마다 500 연결까지 봅니다. IdP 장애는 퇴사로 판정하지 않고 다음 회차로 미룹니다.
- `withdraw-deprovisioned`(매일 04:10): 퇴사 처리 뒤 IdP 설정의 날 수가 지났고 여전히 그 연결이 건 차단 상태인 회원을 탈퇴(익명화)시킵니다. 관리자 계정은 건너뛰고 최고관리자에게 알립니다. `--as-of` 는 시험용이고 운영 환경(`APP_ENV=production`)에서 거절합니다.
- `metadata-refresh`(매일 04:20): SAML IdP 메타데이터 주소를 다시 받아 서명 인증서 만료일을 기록하고, 만료가 다가오면(기본 30일, 7일 전) 알립니다. `--idp=` 로 한 IdP 만 돌릴 수 있습니다.
- `prune`(매일 04:40): 보존일이 지난 로그인 시도, SCIM 수신 이력, 끝난 세션 기록을 지웁니다.
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 알림 키 | 채널 |
|---|---|
| `sso_emergency_login` | `mail`, `database` |
| `sso_idp_failures` | `mail`, `database` |
| `sso_cert_expiring` | `mail`, `database` |
| `sso_email_conflict` | `mail`, `database` |
| `sso_super_admin_deprovisioned` | `mail`, `database` |
| `sso_admin_withdraw_skipped` | `mail`, `database` |
| `sso_password_reset_redirect` | `mail` |
<!-- @generated:notifications END -->

<!-- @intent START -->
| 알림 | 발화 훅 | 받는 사람 | 언제 |
|---|---|---|---|
| `sso_emergency_login` | `sirsoft-sso.auth.emergency_login` | `admin` 역할 | 강제 중 비상 계정이 비밀번호로 로그인 |
| `sso_idp_failures` | `sirsoft-sso.idp.failing` | `admin` 역할 | 한 IdP 의 실패(IdP 쪽 `failed` 만)가 기준 시간 안 기준 횟수(기본 10분 안 5회)에 닿음, IdP 마다 하루 1번 |
| `sso_cert_expiring` | `sirsoft-sso.idp.cert_expiring` | `admin` 역할 | IdP 서명 인증서 만료 30일, 7일 전(「보존」 탭에서 바꿈) |
| `sso_email_conflict` | `sirsoft-sso.account.email_sync_conflict` | `admin` 역할 | IdP 가 알려 준 새 이메일을 다른 회원이 써서 반영하지 못함 |
| `sso_super_admin_deprovisioned` | `sirsoft-sso.account.super_admin_deprovisioned` | 최고관리자 전원 | 최고관리자의 회사 계정 퇴사(차단 대신 토큰만 회수) |
| `sso_admin_withdraw_skipped` | `sirsoft-sso.account.admin_withdraw_skipped` | 최고관리자 전원 | 퇴사 처리된 관리자 계정이 탈퇴 기한을 넘겼지만 자동 탈퇴하지 않음(직접 판단) |
| `sso_password_reset_redirect` | `sirsoft-sso.auth.password_reset_redirected` | 요청한 회원(메일만) | 회사 계정으로 로그인해야 하는 회원의 비밀번호 찾기 요청 |

받는 사람 규칙은 운영자가 코어 알림 설정 화면에서 바꿀 수 있습니다. 본문 자리(`{idp_label}` 등) 값은 `NotificationDataListener` 가 필터 `sirsoft-sso.notification.extract_data` 로 채웁니다. IdP 이름, 대체 문구, 퇴사 확인 경로 이름은 언어별 값으로 넘겨 받는 사람 언어로 들어갑니다(IdP 라벨에 그 언어 값이 없으면 사이트 기본 언어). 비상 로그인 알림의 로그인 시각(`{login_at}`)과 인증서 만료 알림의 만료일(`{expires_at}`)은 사이트 시간대(환경설정의 시간대)로 바꿔 넣습니다.
<!-- @intent END -->
