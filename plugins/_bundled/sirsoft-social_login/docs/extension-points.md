# 소셜 로그인 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 18종 / 호출 지점 20곳. 이 중 1종은 `getHooks()` 선언에 없어 소스에서 자동 감지한 것입니다 — 선언에 추가하면 유형과 설명이 함께 실립니다. 훅 이름이 상수·변수로 조립된 호출이 19곳 있어 호출 위치가 표에 다 실리지 않을 수 있습니다.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `core.auth.register_validation_rules` | filter | — | `src/Http/Requests/CompleteSignupRequest.php:66` |
| `sirsoft-social_login.account.after_link` | action | 회원에게 소셜 계정 연결을 새로 만든 직후 발행됩니다. 인자: (SocialAccount $account, User $user, array $context — method: link·existing·auto·signup) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.account.after_unlink` | action | 소셜 계정 연결을 지운 직후 발행됩니다. 인자: (SocialAccount $account, User $user, string $reason) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.account.before_unlink` | action | 소셜 계정 연결을 지우기 직전에 발행됩니다. 인자: (SocialAccount $account, User $user, string $reason — user·admin·webhook·withdraw·delete) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.account.unlinked_by_admin` | action | 관리자가 회원의 소셜 계정 연결을 강제로 해제한 직후 발행됩니다(회원 알림). 인자: (User $user, string $providerKey) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.account.unlinked_last_method` | action | 제공자 웹훅으로 연결이 지워져 회원에게 비밀번호도 다른 연결도 남지 않았을 때 발행됩니다(메일을 받을 수 있는 회원만, 가입 미완료 알림 제외). 인자: (User $user, string $providerKey) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.auth.after_social_login` | action | 연결된 회원에게 로그인 인계 코드를 발급한 직후 발행됩니다. 2단계 인증·비밀번호 변경 같은 코어 관문은 그 뒤 로그인 화면에서 진행됩니다. 인자: (User $user, SocialAccount $account) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.auth.after_social_signup` | action | 소셜 계정으로 회원 가입을 마친 직후(연결 생성 뒤) 발행됩니다. 인자: (User $user, SocialAccount $account) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.profile.filter_normalized` | filter | 제공자 프로필을 정규화한 직후 걸러 냅니다. 인자: (NormalizedProfile $profile, string $providerKey) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.provider.after_create` | action | 관리자가 일반 OIDC 제공자를 추가한 직후 발행됩니다. 인자: (array $summary — key·driver·type·enabled·label, 비밀값 없음) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.provider.after_delete` | action | 관리자가 일반 OIDC 제공자를 삭제한 직후 발행됩니다. 인자: (array $summary) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.provider.after_update` | action | 관리자가 제공자 설정을 바꾼 직후 발행됩니다(바뀐 칸이 있을 때만). 인자: (array $summary, array $changed — 바뀐 칸 이름, 값은 싣지 않음) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.provider.failing` | action | 한 제공자의 로그인 왕복이 10분 안에 설정 기준 이상 실패하면 하루 한 번 발행됩니다(회원의 동의 취소·관리자 연결 테스트는 세지 않음). 인자: (string $providerKey, int $count, string $lastErrorCode) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.provider.filter_registered` | filter | 제공자 드라이버 목록을 걸러 냅니다. 확장이 새 키로 ProviderDriverInterface 구현을 더할 수 있습니다(내장 키 덮어쓰기는 무시). 인자: (array<string, ProviderDriverInterface> $drivers) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.signup.filter_registration_data` | filter | 소셜 가입으로 코어 가입에 넘기기 직전의 가입 데이터를 걸러 냅니다. 필수 키(name, email, 동의)는 지워도 되살립니다. 인자: (array $data, NormalizedProfile $profile) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.signup.surge` | action | 한 제공자의 시간당 소셜 가입 수가 설정 기준에 닿으면 하루 한 번 발행됩니다. 인자: (string $providerKey, int $count, int $threshold) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.webhook.after_receive` | action | 제공자 웹훅을 검증해 처음 저장한 직후(처리 잡 예약 전) 발행됩니다. 인자: (SocialLoginWebhookEvent $event — 서명·암호문을 뺀 요약 본문) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.webhook.failed` | action | 웹훅 처리가 다시 시도해도 실패했거나 정책상 거절됐을 때(관리자 탈퇴 거부) 발행됩니다. 인자: (array $summary — event_id·provider_key·event_type·error) | 선언 (호출 위치 미확인) |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
`getHooks()` 에 17개(필터 3, 액션 14)를 선언했습니다. 발화는 모두 클래스 상수로 합니다 — 필터는 `ProviderRegistry::all()`(`provider.filter_registered`), 콜백의 프로필 정규화 직후(`profile.filter_normalized`), 가입 완료 직전(`signup.filter_registration_data`), 액션은 연결·해제와 관리자 강제 해제(`AccountLinkService`), 로그인 인계(`SocialLoginService`, 기존 계정 연결·자동 연결 경로도 같은 상수), 가입 완료(`SignupFlowService`), 가입 급증(`SignupSurgeMonitor`), 제공자 연속 실패(`FailureStreakMonitor`), 제공자 추가·수정·삭제(`ProviderAdminService`), 웹훅 수신(`WebhookReceiver`)과 처리 실패·마지막 로그인 수단 해제(`WebhookProcessor`)입니다. 제공자 변경 훅 인자는 비밀값 없는 요약 배열이고, 수정 훅은 바뀐 칸 이름 목록을 함께 받습니다. 웹훅 수신 훅은 기록 행 모델을 받는데 본문은 서명과 암호문을 뺀 요약입니다. 웹훅 실패 훅은 `event_id` `provider_key` `event_type` `error` 요약 배열을 받습니다. 생성기가 상수 경유 발화를 읽지 못해 표의 발행 위치는 「선언」으로 보입니다.

인자 계약: 액션 인자는 `SocialAccount`·`User` 모델입니다. 토큰·원본 프로필 열은 모델에서 숨김(`hidden`)이라 배열·JSON 으로는 나가지 않지만, **큐로 도는 구독(`sync` 없음)은 코어가 모델 속성을 그대로 큐 표에 직렬화하므로 암호화된 토큰 값이 함께 저장됩니다** — 토큰을 다룰 일이 없는 구독도 `sync` 로 두기를 권합니다. 프로필 필터 2개의 `NormalizedProfile` 은 원본 응답(`raw`)을 들고 있는 동기 필터입니다. `provider.filter_registered` 는 확장이 드라이버를 등록하는 통로라 인자가 자격증명을 가진 드라이버 객체입니다(같은 요청 안의 동기 필터 — 큐·로그로 복제되지 않는다).
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.field.filter_definitions` | filter | `PrefillSignupFieldsListener` | `prefill` | 20 |
| `core.identity.registered_providers` | filter | `RegisterSocialReauthProviderListener` | `register` | 30 |
| `core.notification.filter_default_definitions` | filter | `NotificationDataListener` | `defaults` | 20 |
| `core.plugin_settings.after_save` | action (미선언) | `ActivityLogListener` | `onSettingsSaved` | 50 |
| `core.plugin_settings.update_validation_rules` | filter | `ValidateSettingsListener` | `rules` | 10 |
| `core.required_fields.filter_exempt_routes` | filter | `ExemptRequiredFieldsRoutesListener` | `exemptRoutes` | 10 |
| `core.user.after_withdraw` | action (미선언) | `CleanAccountsOnUserWithdraw` | `handle` | 50 |
| `core.user.before_delete` | action (미선언) | `CleanAccountsOnUserDelete` | `handle` | 50 |
| `core.user.filter_list_rows` | filter | `UserListSocialProvidersColumn` | `addColumn` | 30 |
| `core.user.filter_resource_data` | filter | `InjectSocialProvidersIntoUserResource` | `inject` | 60 |
| `core.user.list_query_extensions` | filter | `UserListSocialProviderFilter` | `addQueryExtension` | 30 |
| `core.user.list_validation_attributes` | filter | `UserListSocialProviderFilter` | `addValidationAttributes` | 30 |
| `core.user.list_validation_rules` | filter | `UserListSocialProviderFilter` | `addValidationRules` | 30 |
| `sirsoft-social_login.account.after_link` | action (미선언) | `ActivityLogListener` | `onLinked` | 50 |
| `sirsoft-social_login.account.after_unlink` | action (미선언) | `ActivityLogListener` | `onUnlinked` | 50 |
| `sirsoft-social_login.auth.after_social_signup` | action (미선언) | `ActivityLogListener` | `onSignup` | 50 |
| `sirsoft-social_login.notification.extract_data` | filter | `NotificationDataListener` | `extract` | 10 |
| `sirsoft-social_login.provider.after_create` | action (미선언) | `ActivityLogListener` | `onProviderCreated` | 50 |
| `sirsoft-social_login.provider.after_delete` | action (미선언) | `ActivityLogListener` | `onProviderDeleted` | 50 |
| `sirsoft-social_login.provider.after_update` | action (미선언) | `ActivityLogListener` | `onProviderUpdated` | 50 |
| `sirsoft-social_login.provider.filter_registered` | filter | `RegisterMockProvidersListener` | `register` | 10 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
코어 `core.plugin_settings.update_validation_rules` 를 구독해 이 플러그인 식별자일 때만 설정 검증 규칙을 더합니다. 자기 필터 `provider.filter_registered` 도 구독합니다 — 모의 게이트가 켜졌을 때만 모의 제공자 5종을 더해, 다른 확장이 제공자를 등록하는 경로를 그대로 실증합니다.
<!-- @intent END -->

## 활동 로그 훅

> 이 확장이 코어 활동 로그(`activity_logs`)에 기록을 남기기 위해 구독하는 훅 7개입니다.
> 위 「구독 훅」 절이 이 확장의 구독 전량을 싣고, 이 절은 그중 **기록을 남기는 것**만 추려 훅마다 남기는 action 을 적습니다.

> 새 항목을 추가하면 이 플러그인의 `lang/{ko,en}/activity_log.php` 에 action 라벨(`action.*`)과 description 본문(`description.*`)을,
> 번들 일본어 팩 `backend/ja/activity_log.php` 에 같은 키를 함께 정의합니다. 이 리스너는 대상(loggable)이 없는 관리자 행위에도
> 기록 주체 확장(`extension_origin`)을 남기므로 action 라벨을 이 플러그인 lang 에서 찾습니다.

### 소셜 로그인 훅 (ActivityLogListener)

**파일**: `plugins/_bundled/sirsoft-social_login/src/Listeners/ActivityLogListener.php`
**총 7훅**

| 훅 이름 | Listener 메서드 | Action (DB) | LogType | Loggable |
|---------|----------------|-------------|---------|----------|
| `sirsoft-social_login.account.after_link` | `onLinked` | `account_linked`, `account_auto_linked`(자동 연결) — 가입과 함께 생긴 연결은 남기지 않음 | `ResolvesActivityLogType` 로 해석 | User |
| `sirsoft-social_login.account.after_unlink` | `onUnlinked` | `account_unlinked`, `account_unlinked_by_admin`, `account_unlinked_by_webhook`(사유별) | `ResolvesActivityLogType` 로 해석 | User |
| `sirsoft-social_login.auth.after_social_signup` | `onSignup` | `signup` | `ResolvesActivityLogType` 로 해석 | User |
| `sirsoft-social_login.provider.after_create` | `onProviderCreated` | `provider_created` | `ResolvesActivityLogType` 로 해석 | - |
| `sirsoft-social_login.provider.after_update` | `onProviderUpdated` | `provider_updated`(바뀐 칸 이름만, 값은 남기지 않음) | `ResolvesActivityLogType` 로 해석 | - |
| `sirsoft-social_login.provider.after_delete` | `onProviderDeleted` | `provider_deleted` | `ResolvesActivityLogType` 로 해석 | - |
| `core.plugin_settings.after_save` | `onSettingsSaved` | `settings_updated`(이 플러그인 저장 성공만, 위험 정책 6종은 값까지) | `ResolvesActivityLogType` 로 해석 | - |

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `ActivityLogListener` | 7개 | 명시 등록 | ✅ | `src/Listeners/ActivityLogListener.php` |
| `CleanAccountsOnUserDelete` | 1개 | 명시 등록 | ✅ | `src/Listeners/CleanAccountsOnUserDelete.php` |
| `CleanAccountsOnUserWithdraw` | 1개 | 명시 등록 | ✅ | `src/Listeners/CleanAccountsOnUserWithdraw.php` |
| `ExemptRequiredFieldsRoutesListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ExemptRequiredFieldsRoutesListener.php` |
| `InjectSocialProvidersIntoUserResource` | 1개 | 명시 등록 | ✅ | `src/Listeners/InjectSocialProvidersIntoUserResource.php` |
| `NotificationDataListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/NotificationDataListener.php` |
| `PrefillSignupFieldsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/PrefillSignupFieldsListener.php` |
| `RegisterMockProvidersListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RegisterMockProvidersListener.php` |
| `RegisterSocialReauthProviderListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RegisterSocialReauthProviderListener.php` |
| `UserListSocialProviderFilter` | 3개 | 명시 등록 | ✅ | `src/Listeners/UserListSocialProviderFilter.php` |
| `UserListSocialProvidersColumn` | 1개 | 명시 등록 | ✅ | `src/Listeners/UserListSocialProvidersColumn.php` |
| `ValidateSettingsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ValidateSettingsListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
`RegisterMockProvidersListener` 는 모의 게이트가 켜졌을 때만 모의 제공자 5종을 더하고, `ValidateSettingsListener` 는 설정 저장 규칙을 더합니다. 관리자 회원 목록에는 둘을 붙입니다 — `UserListSocialProviderFilter` 가 코어 목록 검증 규칙·라벨·쿼리 훅으로 「가입경로」 조건(`ext_filters[]=sirsoft-social_login.provider=…`)을, `UserListSocialProvidersColumn` 이 `core.user.filter_list_rows` 로 페이지 회원의 연결 제공자를 한 번에 조회해 행마다 붙입니다. 둘 다 회원 연결 열람 권한이 없으면 아무것도 하지 않습니다. `NotificationDataListener` 는 알림 5종의 치환 값과 수신 문맥을 채우고 기본 정의 복원 필터에 정의를 돌려줍니다. 리스너는 컨테이너로 만들어지므로 생성자 주입을 씁니다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
| `resources/extensions/admin_user_detail_social_tab.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `admin_user_detail` |
| `resources/extensions/admin_user_list_social_column.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `admin_user_list` |
| `resources/extensions/admin_user_list_social_filter.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `admin_user_list_filters` |
| `resources/extensions/auth_login_buttons.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `auth_login_extensions` |
| `resources/extensions/auth_login_notices.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `auth/login` |
| `resources/extensions/auth_register_buttons.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `auth_register_extensions` |
| `resources/extensions/auth_register_social_modals.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `auth/register` |
| `resources/extensions/mypage_change_password_reauth.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `mypage_change_password_reauth` |
| `resources/extensions/mypage_profile_edit_reauth.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `mypage/profile-edit` |
| `resources/extensions/mypage_reauth_methods.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 확장점 `mypage_reauth_methods` |
| `resources/extensions/mypage_social_accounts_card.g7.html` | 다른 확장/템플릿 레이아웃에 주입되는 조각 — 대상 레이아웃 `mypage/profile` |
<!-- @generated:layout-extensions END -->

<!-- @intent START -->
사용자 화면 8조각과 관리자 화면 3조각입니다. 사용자 쪽은 확장점 주입 4개(로그인 버튼 `auth_login_extensions`, 가입 버튼 `auth_register_extensions`, 재확인 수단 `mypage_reauth_methods`, 비밀번호 첫 설정 재확인 `mypage_change_password_reauth`)와 대상 레이아웃 overlay 4개(로그인 안내 토스트 `auth/login`, 가입 모달 `auth/register`, 정보 수정 관문 수령 `mypage/profile-edit`, 마이페이지 연결 카드 `mypage/profile` 의 `profile_view_bio_card` 뒤)입니다. 관리자 쪽은 회원 상세 overlay(`admin_user_detail`, 탭 버튼은 `user_detail_tabs` 속성 주입, 본문은 `extension_tab_content`), 회원 목록 상세검색 확장점 `admin_user_list_filters` 의 가입경로 행, 회원 목록 표 `users_data_grid` 와 열 선택기에 「소셜 연결」 열을 덧붙이는 속성 주입(기본 열에는 없다)입니다. 플러그인이 꺼지면 주입이 0 이 되어 각 화면은 기본 모습으로 돌아갑니다.

재확인 두 조각(`mypage_reauth_methods`, `mypage_change_password_reauth`)은 템플릿 기본 안내를 통째로 바꾸므로(`replace`) 소셜 계정이 없는 회원(회사 계정으로만 가입한 회원 등)에게도 그려집니다. 그래서 내 연결 목록의 `reauth_via_social` 이 참일 때만 소셜 안내와 제공자 버튼을 보이고, 아니면 템플릿 기본 안내와 같은 문구(코어 키 `core.compat.reauth.unavailable`, 비밀번호 첫 설정은 코어 회원 응답의 `reauth_provider` 가 있으면 `core.compat.change_password.reauth_other`, 없으면 `core.compat.change_password.reauth_unavailable`)를 보입니다. 템플릿 키가 아니라 어느 템플릿에서나 보이는 코어 키를 써서, 이 문구가 없는 7.1.x 옛 템플릿에서도 키 그대로 보이지 않습니다.

7.1.x 기본 템플릿을 복사한 옛 템플릿에는 위 확장점 4개가 없습니다. 코어가 서빙 때 그 화면에 자리를 채우고(로그인 화면은 로그인 인계 교환까지), 조각 등록도 호스트 화면이 있으면 받습니다 — 코어 `docs/extension/layout-extensions.md` 「옛 템플릿 호환 심」. `reauth_via_social` 은 코어가 이 회원의 민감 작업 재확인에 실제로 소셜 재확인을 고를 때만 참입니다(`IdentityVerificationManager::resolveUsableForUser`) — 회사 계정과 소셜을 둘 다 연결한 회원은 「본인 확인 계속하기」가 회사 계정으로 가므로 거짓입니다.
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 미들웨어 | 부착 대상(targets) | 우선순위 |
|---|---|---|
| `MockGateMiddleware` | `web.plugins.sirsoft-social_login.mock.*` | - |
<!-- @generated:middleware END -->

<!-- @intent START -->
모의 제공자 라우트군(`web.plugins.sirsoft-social_login.mock.*` — authorize·token·userinfo·discovery·jwks·revoke)에만 `MockGateMiddleware` 를 붙입니다. 모의 제공자가 서버 명령(`sirsoft-social_login:mock --enable`)으로 켜져 있지 않으면 이 라우트군 전부가 404 를 돌려줍니다. 핸들러마다 따로 막지 않고 그룹 한 곳에서 막으므로, 모의 라우트를 더할 때는 같은 이름 접두(`mock.`) 아래에 두면 됩니다(`MockProviderTest` 가 모든 모의 라우트가 이 대상에 드는지 잠급니다). 그 밖의 라우트에는 미들웨어를 선언하지 않습니다.
<!-- @intent END -->

## 브로드캐스트 채널

<!-- @generated:channels START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 브로드캐스트 채널이 없습니다._
<!-- @generated:channels END -->

<!-- @intent START -->
브로드캐스트 채널을 쓰지 않습니다.
<!-- @intent END -->

## 스케줄

<!-- @generated:schedules START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 스케줄 | 주기 | 설명 |
|---|---|---|
| `prune` | `0 0 * * *` | 소셜 로그인 보존 기간 정리 |
<!-- @generated:schedules END -->

<!-- @intent START -->
보존 기간 정리 배치(`sirsoft-social_login:prune`, `--dry-run` 은 건수만) 하나를 매일 돕니다. 대상은 원본 프로필 사본(`profile_raw_retention_days`), 시도 기록(`attempt_log_retention_days`), 웹훅 수신 이력(`webhook_event_retention_days`), 만료된 모의 제공자 표지이고, 큰 표는 500행씩 지웁니다.
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 알림 키 | 채널 |
|---|---|
| `social_login_last_login_method_removed` | `mail` |
| `social_login_link_removed_by_admin` | `database`, `mail` |
| `social_login_provider_failing` | `mail`, `database` |
| `social_login_signup_surge` | `mail`, `database` |
| `social_login_webhook_failed` | `database` |
<!-- @generated:notifications END -->

<!-- @intent START -->
알림 정의 5종입니다 — 회원에게 가는 「마지막 로그인 수단 해제」 「관리자가 연결 해제」(메일 템플릿은 기본 꺼짐), 관리자 역할에게 가는 「제공자 연속 실패」 「가입 급증」 「웹훅 처리 실패」. 치환 값은 `NotificationDataListener` 가 채우고, 회원 알림의 수신자는 훅이 넘긴 회원(`trigger_user`)입니다.
<!-- @intent END -->

## 제공자 드라이버 계약

<!-- @intent START -->
다른 확장은 필터 훅 `sirsoft-social_login.provider.filter_registered` 를 구독해 새 키로 `Contracts\ProviderDriverInterface` 구현을 더합니다. 내장 4종(`kakao` `naver` `google` `facebook`)과 제공자 테이블에 이미 있는 키를 다른 인스턴스로 바꾸거나 지우는 반환은 무시하고(내장 키는 경고 로그), 키는 영문 소문자로 시작하는 영문 소문자, 숫자, 밑줄 2~50자만 받습니다. `mock_` 로 시작하는 키는 모의 제공자 몫이라 쓰지 않습니다.

| 메서드 | 계약 |
|---|---|
| `key()` `label($locale)` | 제공자 키와 버튼에 보일 이름 |
| `supports()` | `pkce` `oidc` `refresh` `revoke` `webhook`(bool), `callback_methods`(`['GET']` 또는 `form_post` 제공자는 `['POST']`) |
| `credentialSchema()` `redirectNotes()` | 관리자 카드의 자격증명 칸 정의와 콜백 주소 주의사항 다국어 키 |
| `authorizeUrl($req)` | `AuthorizationRequest`(state, PKCE verifier, nonce, 콜백 주소)로 인가 주소를 만든다 |
| `exchangeCode($code, $req)` | 인가 코드를 `TokenSet` 으로. 실패는 `ProviderException`(오류 코드는 제공자 코드 그대로) |
| `fetchProfile($tokens, $req, $input)` | 사용자 정보 API 를 불러 `NormalizedProfile` 로 맞춘다. 제공자 사용자 ID 가 없으면 `ProviderException`. 이메일 검증 여부는 true, false, null(알 수 없음) 셋이고 마스킹 이메일은 null 로 버린다 |
| `revoke($tokens, $account)` | 제공자 쪽 앱 권한 회수. 실패해도 연결 해제는 진행된다 |
| `verifyWebhook($request)` | 지원하지 않으면 null. 지원하면 서명을 검증해 저장 전 이벤트 모델을 돌려준다 |
| `clientSecret()` | 토큰 요청에 쓸 비밀값. 저장값을 풀 수 없으면 `credentials_unreadable` 로 멈춘다 |
| `withConfig($row 또는 배열)` | 설정을 입힌 새 인스턴스(원본을 바꾸지 않는다) |

OAuth 2.0 왕복이면 `Drivers\AbstractOAuth2Driver` 를 상속해 `driverName()`, `defaultEndpoints()`, `normalizeProfile()` 세 개만 채우면 됩니다. state 와 PKCE, 코드 교환, 오류 매핑, 요청 제한 시간은 부모가 맡고, 제공자가 토큰 요청에 값을 더 요구하면 `extraTokenParams()` 를, 인가 주소에 값을 더 붙이려면 `extraAuthorizeParams()` 를 덮어씁니다. 이메일 제공을 거절한 회원이 로그인 화면의 재동의 단추로 다시 시작하면 부모가 `reconsentParams()` 를 붙입니다 — 기본은 OpenID Connect 의 `prompt=consent` 이고, 동의 화면을 다른 값으로 다시 띄우는 제공자는 이 메서드를 덮어씁니다. OpenID Connect 제공자는 `Drivers\OidcDriver` 를 상속하면 discovery, JWKS 캐시, id_token RS256 검증까지 그대로 씁니다.

확장이 등록한 드라이버는 테이블 행이 없으므로 필터가 돌려주는 동안 켜진 것으로 보고, 끄려면 필터 결과에서 빼면 됩니다. 가입 허용(`signup_allowed`), 이메일 미제공 처리(`email_missing_policy`), 이메일 신뢰(`email_trust`)는 드라이버 설정 배열에서 읽습니다. 관리자 제공자 탭에는 읽기 전용 카드로 보이고, 자격증명은 등록한 확장이 자기 설정에 보관해 `withConfig()` 로 넘깁니다. 버튼은 `custom` 모양(이름 첫 글자 원형)으로 그려집니다.

```php
// 다른 확장의 리스너 — 훅 이름은 문자열로 적는다(확장 문서 생성기가 구독 표를 정적으로 읽는다)
public static function getSubscribedHooks(): array
{
    return [
        'sirsoft-social_login.provider.filter_registered' => ['method' => 'register', 'priority' => 20, 'type' => 'filter', 'sync' => true],
    ];
}

public function register(array $drivers): array
{
    $drivers['acme'] = app(AcmeDriver::class)->withConfig([
        'key' => 'acme',
        'label' => ['ko' => '에이크미', 'en' => 'Acme'],
        'client_id' => plugin_setting('vendor-acme_login', 'client_id'),
        'client_secret' => plugin_setting('vendor-acme_login', 'client_secret'),
        'signup_allowed' => true,
        'email_missing_policy' => 'ask',
        'email_trust' => 'flag',
    ]);

    return $drivers;
}

// AcmeDriver extends AbstractOAuth2Driver
public function driverName(): string { return 'acme'; }

protected function defaultEndpoints(): array
{
    return ['authorize' => 'https://id.acme.example/authorize', 'token' => 'https://id.acme.example/token', 'userinfo' => 'https://id.acme.example/me'];
}

protected function normalizeProfile(array $body, TokenSet $tokens, AuthorizationRequest $req): NormalizedProfile
{
    return new NormalizedProfile(
        providerUserId: $this->requireId($body['id'] ?? null),
        email: NormalizedProfile::email($body['email'] ?? null),
        emailVerified: null,
        name: NormalizedProfile::str($body['name'] ?? null),
        raw: $body,
    );
}
```

드라이버는 로그인을 직접 하지 않습니다. 회원을 정하고 코어 로그인 인계로 넘기는 일은 이 플러그인이 하므로, 드라이버 안에서 토큰 발급이나 세션 생성을 하지 마세요.
<!-- @intent END -->

## 모의 제공자

<!-- @intent START -->
실제 제공자 앱 없이 왕복 전체를 재기 위한 개발, 검수용 제공자 5종(`mock_kakao` `mock_naver` `mock_google` `mock_facebook` `mock_oidc`)입니다. 각 응답 모양은 원 제공자를 흉내 내고, 동의 화면에서 응답 변종(완비 검증, 완비 미검증, 이메일 없음, 마스킹 이메일, 별명만, 문자열 boolean, 기존 회원 이메일, 제공자 오류 본문, 거부)을 고릅니다.

- 켜고 끄기는 서버 명령으로만 합니다: `php artisan sirsoft-social_login:mock --enable --minutes=30`(최대 240분), `--disable`, `--status`. 설정 화면이나 API 로 켜는 길은 없습니다. 꺼져 있으면 모의 주소(`/plugins/sirsoft-social_login/mock/*`)는 404 입니다.
- 켜진 표지는 캐시에 만료 시각과 함께 두고, 시간이 지나면 저절로 꺼집니다. 정리 배치가 만료된 표지를 지웁니다. `--enable` 과 `--status` 가 콘솔에 찍는 만료 시각은 사이트 시간대(환경설정의 시간대) 기준 `Y-m-d H:i:s` 입니다.
- 실패 연속 알림과 가입 급증 알림의 캐시 키에 들어가는 날짜, 시간 버킷(`Ymd`, `YmdH`)은 화면에 보이지 않는 기계값이라 서버 시간대 그대로 둡니다.
- 모의 제공자는 이 플러그인이 자기 필터 훅을 구독해 더합니다(`RegisterMockProvidersListener`). 다른 확장이 드라이버를 더하는 경로를 그대로 쓰므로, 위 계약의 실증이기도 합니다.
- 관리자 제공자 탭에는 읽기 전용 카드로, 진단 화면에는 켜짐 배지와 끝나는 시각으로 보입니다. 모의 키는 관리자 API 로 저장할 수 없습니다.
- 연결을 해제할 때의 권한 회수는 모의 회수 주소(`/mock/revoke`, POST·DELETE)가 늘 성공으로 답합니다. 실제 드라이버의 회수 경로를 끝까지 타고, 모의 해제가 진단과 통계에 가짜 「회수 실패」를 남기지 않습니다.
<!-- @intent END -->
