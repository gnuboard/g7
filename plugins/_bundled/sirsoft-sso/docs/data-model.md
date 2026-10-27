# SSO 연동 — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 모델 | 테이블 | fillable | 관계 | 특성 |
|---|---|---|---|---|
| `IdentityProvider` | `sso_identity_providers` | 32 | accounts→SsoAccount | - |
| `SsoAccount` | `sso_accounts` | 15 | idp→IdentityProvider, user→User | - |
| `SsoLoginAttempt` | `sso_login_attempts` | 10 | - | - |
| `SsoRoleGrant` | `sso_role_grants` | 4 | - | - |
| `SsoScimEvent` | `sso_scim_events` | 6 | - | - |
| `SsoScimGroup` | `sso_scim_groups` | 3 | members→SsoAccount | - |
| `SsoSession` | `sso_sessions` | 7 | idp→IdentityProvider | - |
| `SsoSitePolicy` | `sso_site_policies` | 2 | - | - |
| `SsoUserAttribute` | `sso_user_attributes` | 5 | - | - |
<!-- @generated:models END -->

<!-- @intent START -->
`IdentityProvider` 는 `driver()` 로 프로토콜 드라이버를 돌려줍니다. 비밀값(`secrets` — OIDC 클라이언트 비밀값, SAML SP 개인키와 인증서, LDAP 서비스 계정 비밀번호)은 `encrypted:array` 로 두고 직렬화에서 숨깁니다. 프로토콜 설정(`config`, 비밀 아님), 연결 방식(`link_methods`), 속성 규칙(`attribute_map`), 역할 규칙(`role_mapping`), 퇴사 설정(`deprovision` — SCIM 사용과 대조 키, 주기 점검과 간격, 퇴사 뒤 탈퇴 날 수)은 json 칸입니다. SCIM 토큰은 원문이 아니라 sha256(`scim_token_hash`)만 둡니다. 키는 `^[a-z][a-z0-9_]{1,49}$` 이고 `mock_` 접두는 모의 IdP 용으로 예약돼 있습니다.

`SsoAccount` 의 갱신 토큰(`refresh_token`)도 encrypted 이고 주기 점검을 켠 OIDC IdP 에서만 저장합니다. 암호화 키가 사이트 앱 키(`APP_KEY`)라, 앱 키를 바꾸면 이 값들을 풀 수 없습니다([운영자 가이드](operator-guide.md#4-비밀값과-앱-키app_key)).

사이트 정책은 `SsoSitePolicy` 1행의 `policy` json 입니다. 행이 없으면 `PolicyService::current()` 가 처음 읽을 때 비상 계정을 가장 먼저 만든 최고관리자 1명으로 둔 기본 행을 만듭니다 — 설치 단계(`install()`)에서 만들지 않습니다. 회원 모델에는 `ssoAccounts` 동적 관계가 붙습니다.
<!-- @intent END -->

## 소유 테이블

<!-- @generated:tables START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 테이블 | 모델 |
|---|---|
| `sso_accounts` | `SsoAccount` |
| `sso_identity_providers` | `IdentityProvider` |
| `sso_login_attempts` | `SsoLoginAttempt` |
| `sso_role_grants` | `SsoRoleGrant` |
| `sso_scim_events` | `SsoScimEvent` |
| `sso_scim_group_members` | - |
| `sso_scim_groups` | `SsoScimGroup` |
| `sso_sessions` | `SsoSession` |
| `sso_site_policies` | `SsoSitePolicy` |
| `sso_user_attributes` | `SsoUserAttribute` |
<!-- @generated:tables END -->

<!-- @intent START -->
연결 키는 `sso_accounts` 의 (`idp_id`, `subject`) UNIQUE 이고, 한 회원은 IdP 마다 연결 하나(`idp_id`, `user_id` UNIQUE)입니다. `subject`, `scim_external_id`, `sso_scim_groups.external_id` 는 `utf8mb4_bin` 입니다 — 대소문자만 다른 두 신원이 한 회원에 묶이지 않게.

회원 FK 와 연결 FK 에 CASCADE 를 두지 않습니다. 회원 탈퇴(`core.user.after_withdraw`, 익명화 뒤)와 삭제(`core.user.before_delete`) 때 `CleanProvisioningDataOnUserLeave`(우선순위 40)가 속성, 역할 기록, 세션, 그룹 소속을 먼저 지우고 `CleanSsoAccountsOnUserLeave`(50)가 연결을 지웁니다. `sso_login_attempts`, `sso_scim_events` 는 이력이라 회원 FK 가 없고, 보존일이 지나면 `sirsoft-sso:prune` 이 지웁니다. `sso_scim_events` 는 요청 본문을 저장하지 않습니다(메서드, 자원, 대상 id, 상태, 오류 코드만).

퇴사 처리는 행을 지우지 않고 `active=false`, `deprovisioned_at`, `deprovision_reason`(scim, periodic, login, admin), `blocked_user_at` 을 남깁니다. `blocked_user_at` 은 「이 연결 때문에 회원을 차단한 시각」이라 재활성 때 `users.blocked_at` 과 같을 때만 차단을 풉니다. 퇴사 전에 이미 다른 이유로 차단돼 있던 회원은 이 값을 남기지 않습니다.

`sso_role_grants` 에는 매핑이 실제로 붙인 역할만 기록합니다. 회원이 이미 수동으로 갖고 있던 역할은 기록하지 않으므로 「매핑 역할만 회수」 모드가 떼지 않습니다. `scim_match=externalId` 인 IdP 는 SCIM `userName` 을 추가 속성 `scim_user_name` 에 두어 목록 필터로 찾습니다. `sso_sessions` 는 G7 토큰(`token_id`)과 IdP 세션(SAML SessionIndex, OIDC `sid`)을 잇는 표로, 끝난 세션은 `ended_at`, `end_reason`(logout, idp_logout, deprovisioned, withdrawn)을 남깁니다.
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
마이그레이션 12개.

| 파일 | 생성 테이블 | 변경 테이블 | down() |
|---|---|---|---|
| `2026_10_22_000001_create_sso_identity_providers_table.php` | `sso_identity_providers` | `sso_identity_providers` | ✅ |
| `2026_10_22_000002_create_sso_accounts_table.php` | `sso_accounts` | `sso_accounts` | ✅ |
| `2026_10_22_000003_create_sso_login_attempts_table.php` | `sso_login_attempts` | `sso_login_attempts` | ✅ |
| `2026_10_22_000004_add_response_summary_to_sso_login_attempts_table.php` | - | `sso_login_attempts` | ✅ |
| `2026_10_22_000020_create_sso_user_attributes_table.php` | `sso_user_attributes` | `sso_user_attributes` | ✅ |
| `2026_10_22_000021_create_sso_role_grants_table.php` | `sso_role_grants` | `sso_role_grants` | ✅ |
| `2026_10_22_000022_create_sso_scim_groups_table.php` | `sso_scim_groups` | `sso_scim_groups` | ✅ |
| `2026_10_22_000023_create_sso_scim_group_members_table.php` | `sso_scim_group_members` | `sso_scim_group_members` | ✅ |
| `2026_10_22_000024_create_sso_scim_events_table.php` | `sso_scim_events` | `sso_scim_events` | ✅ |
| `2026_10_22_000025_create_sso_sessions_table.php` | `sso_sessions` | `sso_sessions` | ✅ |
| `2026_10_22_000026_add_periodic_check_columns_to_sso_tables.php` | - | `sso_identity_providers`, `sso_accounts` | ✅ |
| `2026_10_22_000030_create_sso_site_policies_table.php` | `sso_site_policies` | `sso_site_policies` | ✅ |
<!-- @generated:migrations END -->

<!-- @intent START -->
모두 새 표이고 1.0.0 에 함께 들어가므로 업그레이드 스텝이 없습니다. `000026` 은 주기 점검 회차 나누기와 진단의 「마지막 점검 시각」에 쓰는 `periodic_checked_at` 을 `sso_identity_providers`, `sso_accounts` 에 더합니다. 언인스톨은 `getDynamicTables()`(`Bootstrap\Tables`)가 자식 표부터 지웁니다.
<!-- @intent END -->

## Enum

<!-- @generated:enums START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_Enum 이 없습니다._
<!-- @generated:enums END -->

<!-- @intent START -->
Enum 클래스를 두지 않습니다. 프로토콜 키는 필터로 늘어날 수 있어 Enum 으로 닫지 않고, 칸 값은 모델 상수와 FormRequest 의 `in:` 규칙이 정합니다 — `IdentityProvider::PROVISIONING_*`, `DEFAULT_LINK_METHODS`, `SsoAccount::VIA`, `REACTIVATABLE_ON_LOGIN`(`periodic`, `login` — IdP 로그인 성공으로 다시 켤 수 있는 퇴사 사유), `SsoLoginAttempt::OUTCOME_*`, `SsoSession::END_*`. 드라이버 설정 칸의 선택지(`subject_claim`, `name_id_format`, `encryption`, `disabled_rule` 등)는 드라이버 `configSchema()` 가 정합니다.
<!-- @intent END -->

## Repository

<!-- @generated:repositories START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 클래스 | 종류 | 설명 |
|---|---|---|
| `AdminAccountRepository` | 구현 | 관리자 「회원 연결」 화면과 회원 화면 주입의 연결 조회 저장소. |
| `AdminAccountRepositoryInterface` | 인터페이스 | 관리자 「회원 연결」 화면, 회원 상세·목록 주입, 마이페이지 카드의 연결 조회 저장소 계약. |
| `AdminIdpRepository` | 구현 | 관리자 IdP 화면 저장소. |
| `AdminIdpRepositoryInterface` | 인터페이스 | 관리자 IdP 화면과 강제 해제 명령의 IdP 저장소 계약 (로그인 흐름 저장소와 분리 — 관리자 화면 전용 조회와 쓰기). |
| `AdminLogRepository` | 구현 | 관리자 이력 화면 저장소. |
| `AdminLogRepositoryInterface` | 인터페이스 | 관리자 이력 화면 저장소 계약 — 로그인 시도, SCIM 수신. |
| `IdentityProviderRepository` | 구현 | IdP 저장소. |
| `IdentityProviderRepositoryInterface` | 인터페이스 | IdP 저장소 계약. |
| `LoginAttemptRepository` | 구현 | SSO 로그인 시도 이력 저장소. |
| `LoginAttemptRepositoryInterface` | 인터페이스 | SSO 로그인 시도 이력 저장소 계약. |
| `ProvisioningAccountRepository` | 구현 | 프로비저닝(SCIM, 주기 점검, 퇴사)이 쓰는 회원 연결 조회. |
| `ProvisioningAccountRepositoryInterface` | 인터페이스 | 프로비저닝(SCIM, 주기 점검, 퇴사)이 쓰는 회원 연결 조회 계약. |
| `ScimEventRepository` | 구현 | SCIM 수신 이력 저장소. |
| `ScimEventRepositoryInterface` | 인터페이스 | SCIM 수신 이력 저장소 계약. |
| `ScimGroupRepository` | 구현 | SCIM 그룹과 소속 저장소. |
| `ScimGroupRepositoryInterface` | 인터페이스 | SCIM 그룹과 소속 저장소 계약. |
| `SitePolicyRepository` | 구현 | SSO 사이트 정책과 강제 판정 조회 저장소. |
| `SitePolicyRepositoryInterface` | 인터페이스 | SSO 사이트 정책과 강제 판정에 필요한 조회 저장소 계약. |
| `SsoAccountRepository` | 구현 | 회원-IdP 신원 연결 저장소. |
| `SsoAccountRepositoryInterface` | 인터페이스 | 회원-IdP 신원 연결 저장소 계약. |
| `SsoRoleGrantRepository` | 구현 | 역할 매핑 기록 저장소. |
| `SsoRoleGrantRepositoryInterface` | 인터페이스 | 역할 매핑 기록 저장소 계약. |
| `SsoSessionRepository` | 구현 | SSO 세션(G7 토큰 ↔ IdP 세션) 저장소. |
| `SsoSessionRepositoryInterface` | 인터페이스 | SSO 세션(G7 토큰 ↔ IdP 세션) 저장소 계약. |
| `SsoUserAttributeRepository` | 구현 | 회원 IdP 추가 속성 저장소. |
| `SsoUserAttributeRepositoryInterface` | 인터페이스 | 회원 IdP 추가 속성 저장소 계약. |
<!-- @generated:repositories END -->

<!-- @intent START -->
로그인 흐름 저장소(`Repositories\*`)와 관리자 화면 조회 저장소(`Repositories\Policy\*`)를 나눕니다. 관리자 화면은 검색, 집계, 영향 미리보기처럼 흐름과 다른 조회가 많고, 흐름 저장소를 넓히지 않으려는 것입니다. 모든 저장소는 인터페이스로 주입하고 바인딩은 `Bootstrap\Container\*` 의 `repositories()` 에 둡니다.

- `SitePolicyRepository::revokeNewlyEnforcedPasswordTokens()` 는 정책 저장 전후의 강제 범위를 비교해 새로 대상이 된 회원(비상 계정 제외)의 `login_method=password` 토큰만 지웁니다.
- `ProvisioningAccountRepository` 는 SCIM, 주기 점검, 퇴사 뒤 탈퇴가 쓰는 조회(대조 키 검색, 점검 대상 묶음)를 모읍니다.
- `IdentityProviderRepository::recordOutcome()` 은 IdP 의 마지막 성공, 오류 시각과 코드를 남겨 진단 화면과 IdP 목록이 씁니다.
<!-- @intent END -->
