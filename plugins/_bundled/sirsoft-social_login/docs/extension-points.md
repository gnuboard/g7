# 소셜 로그인 — 확장점

> 발행/구독 훅·미들웨어·채널·스케줄 · 진입점: [AGENTS.md](../AGENTS.md)

## 발행 훅

<!-- @generated:hooks-published START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
발행 훅 9종 / 호출 지점 11곳. 훅 이름이 상수·변수로 조립된 호출이 11곳 있어 호출 위치가 표에 다 실리지 않을 수 있습니다.

| 훅 이름 | 유형 | 설명 | 발행 위치 |
|---|---|---|---|
| `sirsoft-social_login.account.after_link` | action | 회원에게 소셜 계정 연결을 새로 만든 직후 발행됩니다. 인자: (SocialAccount $account, User $user, array $context — method: link·existing·auto·signup) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.account.after_unlink` | action | 소셜 계정 연결을 지운 직후 발행됩니다. 인자: (SocialAccount $account, User $user, string $reason) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.account.before_unlink` | action | 소셜 계정 연결을 지우기 직전에 발행됩니다. 인자: (SocialAccount $account, User $user, string $reason — user·admin·webhook·withdraw·delete) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.auth.after_social_login` | action | 연결된 회원에게 로그인 인계 코드를 발급한 직후 발행됩니다. 2단계 인증·비밀번호 변경 같은 코어 관문은 그 뒤 로그인 화면에서 진행됩니다. 인자: (User $user, SocialAccount $account) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.auth.after_social_signup` | action | 소셜 계정으로 회원 가입을 마친 직후(연결 생성 뒤) 발행됩니다. 인자: (User $user, SocialAccount $account) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.profile.filter_normalized` | filter | 제공자 프로필을 정규화한 직후 걸러 냅니다. 인자: (NormalizedProfile $profile, string $providerKey) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.provider.filter_registered` | filter | 제공자 드라이버 목록을 걸러 냅니다. 확장이 새 키로 ProviderDriverInterface 구현을 더할 수 있습니다(내장 키 덮어쓰기는 무시). 인자: (array<string, ProviderDriverInterface> $drivers) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.signup.filter_registration_data` | filter | 소셜 가입으로 코어 가입에 넘기기 직전의 가입 데이터를 걸러 냅니다. 필수 키(name, email, 동의)는 지워도 되살립니다. 인자: (array $data, NormalizedProfile $profile) | 선언 (호출 위치 미확인) |
| `sirsoft-social_login.signup.surge` | action | 한 제공자의 시간당 소셜 가입 수가 설정 기준에 닿으면 하루 한 번 발행됩니다. 인자: (string $providerKey, int $count, int $threshold) | 선언 (호출 위치 미확인) |
<!-- @generated:hooks-published END -->

<!-- @intent START -->
`getHooks()` 에 9개(필터 3, 액션 6)를 선언했습니다. 발화는 모두 클래스 상수로 합니다 — 필터는 `ProviderRegistry::all()`(`provider.filter_registered`), 콜백의 프로필 정규화 직후(`profile.filter_normalized`), 가입 완료 직전(`signup.filter_registration_data`), 액션은 연결·해제(`AccountLinkService`), 로그인 인계(`SocialLoginService`, 기존 계정 연결·자동 연결 경로도 같은 상수), 가입 완료(`SignupFlowService`), 가입 급증(`SignupSurgeMonitor`)입니다. 생성기가 상수 경유 발화를 읽지 못해 표의 발행 위치는 「선언」으로 보입니다.

인자 계약: 액션 인자는 `SocialAccount`·`User` 모델입니다. 토큰·원본 프로필 열은 모델에서 숨김(`hidden`)이라 배열·JSON 으로는 나가지 않지만, **큐로 도는 구독(`sync` 없음)은 코어가 모델 속성을 그대로 큐 표에 직렬화하므로 암호화된 토큰 값이 함께 저장됩니다** — 토큰을 다룰 일이 없는 구독도 `sync` 로 두기를 권합니다. 프로필 필터 2개의 `NormalizedProfile` 은 원본 응답(`raw`)을 들고 있는 동기 필터입니다. `provider.filter_registered` 는 확장이 드라이버를 등록하는 통로라 인자가 자격증명을 가진 드라이버 객체입니다(같은 요청 안의 동기 필터 — 큐·로그로 복제되지 않는다).
<!-- @intent END -->

## 구독 훅

<!-- @generated:hooks-subscribed START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 훅 이름 | 유형 | 리스너 | 메서드 | 우선순위 |
|---|---|---|---|---|
| `core.identity.registered_providers` | filter | `RegisterSocialReauthProviderListener` | `register` | 30 |
| `core.plugin_settings.update_validation_rules` | filter | `ValidateSettingsListener` | `rules` | 10 |
| `core.user.after_withdraw` | action (미선언) | `CleanAccountsOnUserWithdraw` | `handle` | 50 |
| `core.user.before_delete` | action (미선언) | `CleanAccountsOnUserDelete` | `handle` | 50 |
| `core.user.filter_resource_data` | filter | `InjectSocialProvidersIntoUserResource` | `inject` | 60 |
| `sirsoft-social_login.account.after_link` | action (미선언) | `ActivityLogListener` | `onLinked` | 50 |
| `sirsoft-social_login.account.after_unlink` | action (미선언) | `ActivityLogListener` | `onUnlinked` | 50 |
| `sirsoft-social_login.auth.after_social_signup` | action (미선언) | `ActivityLogListener` | `onSignup` | 50 |
| `sirsoft-social_login.provider.filter_registered` | filter | `RegisterMockProvidersListener` | `register` | 10 |
<!-- @generated:hooks-subscribed END -->

<!-- @intent START -->
코어 `core.plugin_settings.update_validation_rules` 를 구독해 이 플러그인 식별자일 때만 설정 검증 규칙을 더합니다. 자기 필터 `provider.filter_registered` 도 구독합니다 — 모의 게이트가 켜졌을 때만 모의 제공자 5종을 더해, 다른 확장이 제공자를 등록하는 경로를 그대로 실증합니다.
<!-- @intent END -->

## 훅 리스너

<!-- @generated:listeners START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 리스너 | 구독 훅 | 등록 방식 | HookListenerInterface | 파일 |
|---|---|---|---|---|
| `ActivityLogListener` | 3개 | 명시 등록 | ✅ | `src/Listeners/ActivityLogListener.php` |
| `CleanAccountsOnUserDelete` | 1개 | 명시 등록 | ✅ | `src/Listeners/CleanAccountsOnUserDelete.php` |
| `CleanAccountsOnUserWithdraw` | 1개 | 명시 등록 | ✅ | `src/Listeners/CleanAccountsOnUserWithdraw.php` |
| `InjectSocialProvidersIntoUserResource` | 1개 | 명시 등록 | ✅ | `src/Listeners/InjectSocialProvidersIntoUserResource.php` |
| `RegisterMockProvidersListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RegisterMockProvidersListener.php` |
| `RegisterSocialReauthProviderListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/RegisterSocialReauthProviderListener.php` |
| `ValidateSettingsListener` | 1개 | 명시 등록 | ✅ | `src/Listeners/ValidateSettingsListener.php` |
<!-- @generated:listeners END -->

<!-- @intent START -->
`RegisterMockProvidersListener` 는 모의 게이트가 켜졌을 때만 모의 제공자 5종을 더하고, `ValidateSettingsListener` 는 설정 저장 규칙을 더합니다. 리스너는 컨테이너로 만들어지므로 생성자 주입을 씁니다.
<!-- @intent END -->

## 레이아웃 확장

<!-- @generated:layout-extensions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 대상 | 설명 |
|---|---|
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
사용자 화면 8조각입니다. 확장점 주입 4개(로그인 버튼 `auth_login_extensions`, 가입 버튼 `auth_register_extensions`, 재확인 수단 `mypage_reauth_methods`, 비밀번호 첫 설정 재확인 `mypage_change_password_reauth`)와 대상 레이아웃 overlay 4개(로그인 안내 토스트 `auth/login`, 가입 모달 `auth/register`, 정보 수정 관문 수령 `mypage/profile-edit`, 마이페이지 연결 카드 `mypage/profile` 의 `profile_view_bio_card` 뒤)입니다. 플러그인이 꺼지면 주입이 0 이 되어 각 화면은 기본 모습으로 돌아갑니다.
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
_등록하는 스케줄이 없습니다._
<!-- @generated:schedules END -->

<!-- @intent START -->
1.0.0 에는 스케줄이 없습니다. 보존 기간 정리 배치(`sirsoft-social_login:prune`)는 관리자 진단, 통계와 함께 들어옵니다.
<!-- @intent END -->

## 알림 정의

<!-- @generated:notifications START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_등록하는 알림 정의가 없습니다._
<!-- @generated:notifications END -->

<!-- @intent START -->
1.0.0 에는 알림 정의가 없습니다.
<!-- @intent END -->
