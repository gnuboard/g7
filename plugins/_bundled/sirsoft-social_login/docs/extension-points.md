# 소셜 로그인 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 17종 / 호출 지점 19곳. 훅 이름이 상수·변수로 조립된 호출이 19곳 있어 호출 위치가 표에 다 실리지 않을 수 있습니다.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
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
| `sirsoft-social_login.provider.failing` | action | 한 제공자의 로그인 왕복이 설정 기준만큼 잇달아 실패하면 하루 한 번 발행됩니다(회원의 동의 취소·관리자 연결 테스트는 세지 않음). 인자: (string $providerKey, int $count, string $lastErrorCode) | 선언 (호출 위치 미확인) |
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
| `core.identity.registered_providers` | filter | `RegisterSocialReauthProviderListener` | `register` | 30 |
| `core.notification.filter_default_definitions` | filter | `NotificationDataListener` | `defaults` | 20 |
| `core.plugin_settings.after_save` | action (미선언) | `ActivityLogListener` | `onSettingsSaved` | 50 |
| `core.plugin_settings.update_validation_rules` | filter | `ValidateSettingsListener` | `rules` | 10 |
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

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `ActivityLogListener` | 7개 | 명시 등록 | ✅ | `src/Listeners/ActivityLogListener.php` |
| `CleanAccountsOnUserDelete` | 1개 | 명시 등록 | ✅ | `src/Listeners/CleanAccountsOnUserDelete.php` |
| `CleanAccountsOnUserWithdraw` | 1개 | 명시 등록 | ✅ | `src/Listeners/CleanAccountsOnUserWithdraw.php` |
| `InjectSocialProvidersIntoUserResource` | 1개 | 명시 등록 | ✅ | `src/Listeners/InjectSocialProvidersIntoUserResource.php` |
| `NotificationDataListener` | 2개 | 명시 등록 | ✅ | `src/Listeners/NotificationDataListener.php` |
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
<!-- @intent END -->

## 미들웨어

<!-- @generated:middleware START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 미들웨어가 없습니다._
<!-- @generated:middleware END -->

<!-- @intent START -->
미들웨어를 선언하지 않습니다.
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
| `sirsoft-social_login:prune` | `daily` | 소셜 로그인 보존 기간 정리 (원본 프로필 · 시도 기록 · 웹훅 수신 이력 · 모의 제공자 표지) |
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
