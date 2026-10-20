# 소셜 로그인 — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 모델 | 테이블 | fillable | 관계 | 특성 |
|---|---|---|---|---|
| `SocialAccount` | `social_accounts` | 15 | user→User | - |
| `SocialLoginAttempt` | `social_login_attempts` | 9 | - | - |
| `SocialLoginProvider` | `social_login_providers` | 25 | - | - |
| `SocialLoginWebhookEvent` | `social_login_webhook_events` | 11 | - | - |
<!-- @generated:models END -->

<!-- @intent START -->
모델은 비밀 컬럼(클라이언트 비밀값, 어드민 키, 토큰, 원본 프로필)을 encrypted 캐스트로 두고 `$hidden` 으로 직렬화에서 뺍니다 — 응답이나 훅 인자에 실리지 않게 하려는 것입니다.
<!-- @intent END -->

## 소유 테이블

<!-- @generated:tables START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 테이블 | 모델 |
|---|---|
| `social_accounts` | `SocialAccount` |
| `social_login_attempts` | `SocialLoginAttempt` |
| `social_login_providers` | `SocialLoginProvider` |
| `social_login_webhook_events` | `SocialLoginWebhookEvent` |
<!-- @generated:tables END -->

<!-- @intent START -->
`social_accounts.provider_user_id` 는 utf8mb4_bin 입니다. 네이버처럼 대소문자로 다른 계정을 구분하는 제공자가 있어, 기본 collation 이면 서로 다른 두 계정이 한 회원으로 묶입니다. 시도 기록은 통계 보존을 위해 회원 FK 를 두지 않고, 연결 테이블의 회원 FK 에도 CASCADE 를 걸지 않습니다 — 회원 삭제(`core.user.before_delete`)와 탈퇴(`core.user.after_withdraw`) 때 리스너 `CleanAccountsOnUserDelete`·`CleanAccountsOnUserWithdraw` 가 연결을 명시 삭제하고 제공자 권한 회수를 시도합니다.
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
마이그레이션 4개.

| 파일 | 생성 테이블 | 변경 테이블 | down() |
|---|---|---|---|
| `2026_10_19_000001_create_social_login_providers_table.php` | `social_login_providers` | `social_login_providers` | ✅ |
| `2026_10_19_000002_create_social_accounts_table.php` | `social_accounts` | `social_accounts` | ✅ |
| `2026_10_19_000003_create_social_login_attempts_table.php` | `social_login_attempts` | `social_login_attempts` | ✅ |
| `2026_10_19_000004_create_social_login_webhook_events_table.php` | `social_login_webhook_events` | `social_login_webhook_events` | ✅ |
<!-- @generated:migrations END -->

<!-- @intent START -->
마이그레이션 4개는 모두 새 테이블이라 업그레이드 스텝이 없습니다. 언인스톨은 `getDynamicTables()` 가 자식 테이블부터 지웁니다.
<!-- @intent END -->

## Enum

<!-- @generated:enums START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| Enum | backing | case 수 | case |
|---|---|---|---|
| `AttemptIntent` | `string` | 4 | `login`, `link`, `test`, `reauth` |
| `AttemptOutcome` | `string` | 7 | `login`, `signup`, `link`, `pending`, `handoff`, `denied`, `failed` |
| `EmailConflictPolicy` | `string` | 3 | `verify_link`, `auto_link_verified`, `never_link` |
| `EmailMissingPolicy` | `string` | 3 | `ask`, `deny`, `placeholder` |
| `EmailTrust` | `string` | 3 | `flag`, `trust`, `verify` |
| `ExtraInfoMode` | `string` | 2 | `when_missing`, `always` |
| `ProviderDriver` | `string` | 5 | `kakao`, `naver`, `google`, `facebook`, `oidc` |
| `ProviderType` | `string` | 2 | `builtin`, `custom` |
| `UnlinkReason` | `string` | 5 | `user`, `admin`, `webhook`, `withdraw`, `delete` |
| `WebhookEventType` | `string` | 5 | `unlink`, `account_delete`, `deauthorize`, `data_deletion`, `incomplete_signup` |
| `WebhookStatus` | `string` | 4 | `received`, `processed`, `ignored`, `failed` |
| `WebhookUnlinkPolicy` | `string` | 2 | `unlink_only`, `withdraw_user` |
<!-- @generated:enums END -->

<!-- @intent START -->
Enum 은 컬럼 값과 설정 값의 SSoT 입니다. 설정 검증(`ValidateSettingsListener`)도 같은 Enum 으로 `Rule::enum` 을 겁니다.
<!-- @intent END -->

## Repository

<!-- @generated:repositories START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 클래스 | 종류 | 설명 |
|---|---|---|
| `AttemptRepository` | 구현 | 소셜 로그인 시도 기록 저장소 구현. |
| `AttemptRepositoryInterface` | 인터페이스 | 소셜 로그인 시도 기록 저장소. |
| `ProviderRepository` | 구현 | 소셜 로그인 제공자 설정 저장소 구현. |
| `ProviderRepositoryInterface` | 인터페이스 | 소셜 로그인 제공자 설정 저장소. |
| `SocialAccountRepository` | 구현 | 회원-소셜 계정 연결 저장소 구현. |
| `SocialAccountRepositoryInterface` | 인터페이스 | 회원-소셜 계정 연결 저장소. |
| `WebhookEventRepository` | 구현 | 제공자 웹훅 수신 기록 저장소 구현. |
| `WebhookEventRepositoryInterface` | 인터페이스 | 제공자 웹훅 수신 기록 저장소. |
<!-- @generated:repositories END -->

<!-- @intent START -->
`WebhookEventRepository::recordIfNew()` 는 먼저 조회하지 않고 UNIQUE 제약 위반을 중복으로 읽습니다 — 동시에 온 같은 알림이 둘 다 통과하지 않게. `ProviderRepository::seedIfMissing()` 은 비어 있는 값만 채워 재시딩이 운영자 설정을 되돌리지 않습니다.
<!-- @intent END -->
