# 마케팅 동의 — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 모델 | 테이블 | fillable | 관계 | 특성 |
|---|---|---|---|---|
| `MarketingConsent` | `user_marketing_consents` | 7 | user→User | - |
| `MarketingConsentHistory` | `user_marketing_consent_histories` | 5 | user→User | - |
<!-- @generated:models END -->

<!-- @intent START -->
두 모델의 역할이 **상태와 이력**으로 갈립니다.

- **`MarketingConsent`** — "지금 어떤가". 회원 × 동의 항목(`consent_key`) 하나가 한 행이며,
  현재 동의 여부(`is_consented`) · 동의/철회 시각 · 누적 동의 횟수(`consent_count`) · 마지막
  변경 출처(`last_source`)를 갖습니다. **EAV 구조**이므로 항목이 늘어도 스키마는 그대로입니다.
- **`MarketingConsentHistory`** — "어떻게 여기까지 왔는가". 변경 한 건이 한 행이며 행위
  (`action`) · 출처(`source`) · IP(`ip_address`)를 남깁니다.

둘을 나눈 이유는 조회 성질이 다르기 때문입니다. 현재 상태는 화면을 그릴 때마다 읽히므로 회원당
항목 수만큼만 있어야 하고, 이력은 계속 쌓이지만 평소에는 읽히지 않습니다. 한 테이블에 두면
"현재 상태" 조회가 이력 전체를 훑게 됩니다.

`consent_count` 는 이력에서 세도 되는 값이지만 상태에 함께 둡니다 — 이 값을 보려고 이력
테이블을 조회하게 하면 화면 조회가 이력 크기에 묶입니다. 대신 **상태와 이력을 같은 트랜잭션에서
갱신**해야 둘이 어긋나지 않습니다.
<!-- @intent END -->

## 소유 테이블

<!-- @generated:tables START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 테이블 | 모델 |
|---|---|
| `user_marketing_consent_histories` | `MarketingConsentHistory` |
| `user_marketing_consents` | `MarketingConsent` |
<!-- @generated:tables END -->

<!-- @intent START -->
두 테이블 모두 `user_` 로 시작합니다 — 이 플러그인의 데이터가 회원에 종속된다는 뜻이며,
회원이 사라지면 함께 사라져야 합니다.

그 정리는 **DB CASCADE 가 아니라 `core.user.before_delete` 훅**이 합니다. 코어 회원 삭제는
이 플러그인의 테이블을 알지 못하므로, 이 구독이 빠지면 고아 행이 조용히 쌓입니다. 반대로
CASCADE 로 처리하면 훅 발행과 이력 처리가 통째로 건너뛰어집니다.

이력 테이블에는 인덱스 추가 마이그레이션이 따로 있습니다(`2026_04_01_000003`). 이력은 계속
쌓이는 테이블이라 회원별·채널별 조회가 인덱스를 타야 합니다.
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
마이그레이션 3개.

| 파일 | 생성 테이블 | 변경 테이블 | down() |
|---|---|---|---|
| `2026_04_01_000001_create_user_marketing_consents_table.php` | `user_marketing_consents` | `user_marketing_consents` | ✅ |
| `2026_04_01_000002_create_user_marketing_consent_histories_table.php` | `user_marketing_consent_histories` | `user_marketing_consent_histories` | ✅ |
| `2026_04_01_000003_add_indexes_to_user_marketing_consent_histories_table.php` | - | `user_marketing_consent_histories` | ✅ |
<!-- @generated:migrations END -->

<!-- @intent START -->
3개입니다 — 상태 테이블 · 이력 테이블 · 이력 인덱스.

**항목이 늘어도 마이그레이션이 필요 없는 것**이 이 설계의 목표입니다. 채널을 추가하려는데
마이그레이션을 쓰고 있다면 EAV 구조를 벗어나고 있다는 신호이므로, 그 변경을 설정으로 표현할
수 없는지 먼저 검토합니다.

새 컬럼을 더할 때 초기 `create_*` 파일을 고치지 않습니다 — 이미 설치된 사이트는 그 파일을
다시 실행하지 않으므로 반영되지 않으며, 기존 행을 손봐야 하는 변경은 `upgrades/` 의 업그레이드
스텝 백필이 함께 필요합니다. 한국어 `comment` 와 `down()` 은 필수입니다.
<!-- @intent END -->

## Enum

<!-- @generated:enums START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_Enum 이 없습니다._
<!-- @generated:enums END -->

<!-- @intent START -->
없습니다. 동의는 참/거짓 하나이고 항목 목록은 **설정 데이터**라 코드의 닫힌 어휘가 아닙니다 —
Enum 으로 만들면 채널 추가가 다시 배포 작업이 됩니다.

닫힌 어휘가 하나 있긴 합니다: 이력의 `source`(`admin` / `profile`)와 `action`. 이 값들은
`detectSource()` 와 서비스가 문자열로 다루는데, 새 변경 경로가 늘어 분기가 생기기 시작하면
그때 Enum 으로 올리는 것이 맞습니다. 지금은 판정 지점이 한 곳뿐이라 어휘가 갈라질 여지가
없습니다.
<!-- @intent END -->

## Repository

<!-- @generated:repositories START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 클래스 | 종류 | 설명 |
|---|---|---|
| `MarketingConsentRepository` | 구현 | 마케팅 동의 Repository 구현체 |
| `MarketingConsentRepositoryInterface` | 인터페이스 | 마케팅 동의 Repository 인터페이스 |
<!-- @generated:repositories END -->

<!-- @intent START -->
`MarketingConsentRepository` 하나이며 인터페이스를 통해 주입됩니다(구체 클래스 타입힌트 금지).

상태와 이력을 **한 Repository 가 함께** 다룹니다. 둘이 같은 트랜잭션에서 갱신되어야 하는데
Repository 를 나누면 그 원자성을 호출부가 조립하게 되고, 조립을 빠뜨린 경로에서 상태만 바뀌고
이력이 없는 행이 생깁니다.

회원 삭제 정리(`deleteByUserId`)도 여기 있습니다. 이 메서드는 `core.user.before_delete` 에서만
호출되며, 두 테이블을 함께 지웁니다.
<!-- @intent END -->
