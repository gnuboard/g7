# GDPR (일반 데이터 보호 규정) — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 모델 | 테이블 | fillable | 관계 | 특성 |
|---|---|---|---|---|
| `GdprPolicyVersion` | `gdpr_policy_versions` | 5 | createdBy→User | - |
| `GdprUserConsent` | `gdpr_user_consents` | 11 | user→User | - |
| `GdprUserConsentHistory` | `gdpr_user_consent_histories` | 9 | user→User | - |
<!-- @generated:models END -->

<!-- @intent START -->
`GdprUserConsent`(mutable, "지금 동의 상태")와 `GdprUserConsentHistory`(immutable append-only,
"동의 변경 이력")를 별도 모델·테이블로 분리한 것이 이 도메인의 핵심 결정입니다. 게이팅
판정(`CookieConsentMiddleware`)은 상태만 읽고, 감사 대응(Art.7(1))은 이력만 봅니다. 하나로
합쳤다면 상태를 UPDATE 할 때마다 과거 값을 별도 보존하는 로직을 매번 다시 구현해야 했을
것입니다. `GdprPolicyVersion` 은 정책 발행 이력이며 이 역시 immutable — 발행된 버전은 그
시점의 정책 내용을 그대로 유지해야 "그 버전에 동의했다"는 이력이 의미를 가집니다.
<!-- @intent END -->

## 소유 테이블

<!-- @generated:tables START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 테이블 | 모델 |
|---|---|
| `gdpr_policy_versions` | `GdprPolicyVersion` |
| `gdpr_user_consent_histories` | `GdprUserConsentHistory` |
| `gdpr_user_consents` | `GdprUserConsent` |
<!-- @generated:tables END -->

<!-- @intent START -->
3개 테이블이 전부입니다 — 쿠키 카테고리 정의(`blocked_domains`/`cookie_categories`)는 별도
테이블이 아니라 `getSettingsSchema()` 의 JSON 설정 값으로 저장됩니다(§settings.md). 별도
테이블로 만들지 않은 이유는 그 값이 "운영자가 조정하는 설정"이지 "사용자별로 쌓이는 데이터"가
아니기 때문입니다 — 전자는 설정 스키마, 후자만 전용 테이블을 둡니다.
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
마이그레이션 4개.

| 파일 | 생성 테이블 | 변경 테이블 | down() |
|---|---|---|---|
| `2026_04_27_000001_create_gdpr_user_consents_table.php` | `gdpr_user_consents` | `gdpr_user_consents` | ✅ |
| `2026_04_27_000002_create_gdpr_user_consent_histories_table.php` | `gdpr_user_consent_histories` | `gdpr_user_consent_histories` | ✅ |
| `2026_05_12_000003_create_gdpr_policy_versions_table.php` | `gdpr_policy_versions` | `gdpr_policy_versions` | ✅ |
| `2026_07_14_000001_add_rejection_to_gdpr_user_consents.php` | - | `gdpr_user_consents` | ✅ |
<!-- @generated:migrations END -->

<!-- @intent START -->
`add_rejection_to_gdpr_user_consents`(2026-07-14)는 "동의 안 함"을 명시적으로 기록하기 위한
추가입니다 — 그전에는 동의 행이 없으면 "아직 응답 안 함"과 "거부함"을 구분할 수 없었습니다.
`ConsentAction::Rejected` 케이스와 짝을 이루는 마이그레이션입니다.
<!-- @intent END -->

## Enum

<!-- @generated:enums START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| Enum | backing | case 수 | case |
|---|---|---|---|
| `ConsentAction` | `string` | 3 | `granted`, `revoked`, `rejected` |
| `ConsentSource` | `string` | 6 | `banner`, `preference_center`, `register`, `mypage`, `mypage_renew_all`, `withdraw` |
| `CookieCategory` | `string` | 4 | `cookie_necessary`, `cookie_functional`, `cookie_analytics`, `cookie_marketing` |
| `GdprPolicyChangeType` | `string` | 3 | `material`, `non_material`, `initial` |
<!-- @generated:enums END -->

<!-- @intent START -->
`ConsentSource` 는 자기 docblock에 "어휘를 이 enum 밖(서비스/리스너 리터럴)에 흩어 두면 화면
필터가 실제 기록 어휘의 부분집합이 되어 일부 행이 어떤 필터로도 도달하지 못한다"고 명시합니다
(#492 과거 결함). `withdraw`(회원탈퇴 시 일괄 철회, `GdprConsentService::revokeAllOnWithdraw()`
/ `GdprUserConsentRepository::revokeAllForUser()`)가 정확히 이 결함군으로 한 번 더 발생했던
case입니다 — 두 지점 모두 enum이 아닌 `'withdraw'` 리터럴을 직접 기록해, 그렇게 기록된 행이
관리자 동의 이력 화면의 어떤 출처 필터로도 걸러지지 않고 라벨도 원시 문자열로 노출됐습니다.
`ConsentSourceVocabularyParityTest`(기록 경로가 enum 을 참조하는지 검사)가 이미 있었는데도
놓친 이유는 두 가지입니다 — 검사 대상 파일 목록에 `GdprUserConsentRepository.php` 가
빠져 있었고, 정규식이 `'source' =>`/`'last_source' =>` 형태만 잡아 `updateConsent(...,
'withdraw')` 같은 **위치 인자** 형태는 못 봤습니다. `Withdraw` case 추가 + 두 지점을
`ConsentSource::Withdraw->value` 참조로 교체 + 테스트의 스캔 대상 파일 목록에 Repository
추가로 정정했습니다. 새 기록 지점을 추가할 때 위치 인자로 리터럴을 넘기면 이 가드가 여전히
못 볼 수 있다는 점을 유의하세요 — 가능하면 `'source' =>`/`'last_source' =>` 형태(배열 키)를
쓰거나 이 테스트의 정규식을 함께 넓힙니다.

전체 어휘(`ConsentSource::allValues()`)와 **공개 요청이 지정할 수 있는 부분집합**
(`ConsentSource::requestSelectableValues()`)은 다릅니다. `register`(회원가입 시 동의) ·
`mypage_renew_all`(정책 개정 후 일괄 재동의) · `withdraw`(회원탈퇴 시 일괄 철회)는 서버가
스스로 기록하는 경로이므로 `StoreCookieConsentRequest` 의 `Rule::in` 에서 제외됩니다 —
이 엔드포인트는 `optional.sanctum` 이라 비인증 방문자도 도달하므로, 공개 요청이 이 값을
실을 수 있으면 가입하지도 탈퇴하지도 재동의하지도 않은 사람의 이력이 그렇게 기록됩니다.
동의 이력은 출처가 존재 이유이고, 그렇게 기록되어도 오류도 로그도 남지 않습니다. 반대로 관리자
동의 이력 화면의 출처 필터(`IndexConsentLogRequest`)는 `allValues()` 를 씁니다: 기록된
어휘 전부가 필터로 도달 가능해야 하기 때문입니다. 새 case 를 추가할 때 어느 쪽에 속하는지
판단하고, 제외한다면 `ConsentSourceVocabularyParityTest` 에 제외 단언을 함께 남기세요 —
목록에서 빠뜨려도 단언이 없으면 아무 테스트도 red 가 되지 않습니다.
<!-- @intent END -->

## Repository

<!-- @generated:repositories START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 클래스 | 종류 | 설명 |
|---|---|---|
| `GdprPolicyVersionRepository` | 구현 | GDPR 정책 버전 Repository 구현체 (immutable append-only) |
| `GdprPolicyVersionRepositoryInterface` | 인터페이스 | GDPR 정책 버전 Repository 인터페이스 (immutable append-only) |
| `GdprUserConsentHistoryRepository` | 구현 | GDPR 동의 변경 이력 Repository 구현체 (immutable append-only) |
| `GdprUserConsentHistoryRepositoryInterface` | 인터페이스 | GDPR 동의 변경 이력 Repository 인터페이스 (immutable append-only) |
| `GdprUserConsentRepository` | 구현 | GDPR 사용자 현재 동의 상태 Repository 구현체 |
| `GdprUserConsentRepositoryInterface` | 인터페이스 | GDPR 사용자 현재 동의 상태 Repository 인터페이스 |
<!-- @generated:repositories END -->

<!-- @intent START -->
`GdprPolicyVersionRepository`·`GdprUserConsentHistoryRepository` 설명에 "immutable
append-only"가 반복 명시된 것은 우연이 아닙니다 — 이 두 Repository 에는 `update()`/`delete()`
류 메서드를 추가하지 않습니다(§AGENTS.md 금지 패턴). 상태를 고치는 메서드가 필요하다면 그것은
`GdprUserConsentRepository`(mutable) 의 몫이며, 두 종류를 같은 Repository 에 섞으면 "이
메서드가 이력을 고치는지 상태를 고치는지"를 매 호출부에서 다시 확인해야 합니다.
<!-- @intent END -->
