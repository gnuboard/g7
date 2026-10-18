# NHN KCP 휴대폰 본인확인 — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 모델 | 테이블 | fillable | 관계 | 특성 |
|---|---|---|---|---|
| `KcpCertTransaction` | `nhnkcp_cert_transactions` | 5 | challenge→IdentityVerificationLog | - |
| `KcpIdentityRecord` | `nhnkcp_identity_records` | 15 | user→User | - |
<!-- @generated:models END -->

<!-- @intent START -->
`sirsoft-verification_kginicis`의 `InicisIdentityRecord`/`InicisChallengeMapping`과 대칭인
구조입니다 — `KcpIdentityRecord`가 PII 를 직접 보관하고(결제 플러그인들과의 근본적 차이),
`KcpCertTransaction`은 진행 중인 인증 시도만 다룹니다. 두 모델을 분리한 이유도 동일합니다
— 인증 세션(거래 하나)과 확인 결과(사용자당 최신 1건)는 생명주기가 다릅니다.
<!-- @intent END -->

## 소유 테이블

<!-- @generated:tables START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 테이블 | 모델 |
|---|---|
| `nhnkcp_cert_transactions` | `KcpCertTransaction` |
| `nhnkcp_identity_records` | `KcpIdentityRecord` |
<!-- @generated:tables END -->

<!-- @intent START -->
`nhnkcp_identity_records.user_id`는 `unique` 제약을 갖습니다 — 한 사용자는 본인확인 결과를
1건만 보유하며, 재인증 시 기존 레코드를 갱신합니다. `sirsoft-verification_kginicis`와
동일한 설계로, "이 사용자가 본인확인을 완료했는가"를 단순 존재 조회 하나로 판정합니다.
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
마이그레이션 2개.

| 파일 | 생성 테이블 | 변경 테이블 | down() |
|---|---|---|---|
| `2026_07_28_000001_create_nhnkcp_identity_records_table.php` | `nhnkcp_identity_records` | `nhnkcp_identity_records` | ✅ |
| `2026_07_28_000002_create_nhnkcp_cert_transactions_table.php` | `nhnkcp_cert_transactions` | `nhnkcp_cert_transactions` | ✅ |
<!-- @generated:migrations END -->

<!-- @intent START -->
`sirsoft-verification_kginicis`가 세 번째 마이그레이션으로 부가 필드를 nullable 완화한
것과 달리, 이 플러그인은 두 테이블 생성만으로 끝났습니다 — 아직 같은 종류의 스키마 보정이
필요했던 적이 없습니다. 향후 유사한 nullable 완화가 필요해지면 kginicis 의 사례(§코어 AGENTS.md
"소스 교정만으로는 기설치본이 낫지 않는다")를 참고합니다.
<!-- @intent END -->

## Enum

<!-- @generated:enums START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| Enum | backing | case 수 | case |
|---|---|---|---|
| `KcpDuplicateField` | `string` | 2 | `di`, `ci` |
<!-- @generated:enums END -->

<!-- @intent START -->
`sirsoft-verification_kginicis`의 `InicisDuplicateField`와 case 구성이 동일합니다(`di`/`ci`)
— 두 벤더 모두 같은 개념(연계정보/연계정보 상위값)을 제공하기 때문입니다. `duplicate_field`
설정값과 `KcpDuplicateIdentityChecker`의 판정 컬럼 화이트리스트 양쪽에서 재사용되므로 Enum
으로 닫아 오타로 인한 "중복 없음" 오판정을 방지합니다.
<!-- @intent END -->

## Repository

<!-- @generated:repositories START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 클래스 | 종류 | 설명 |
|---|---|---|
| `KcpCertTransactionRepository` | 구현 | `nhnkcp_cert_transactions` 테이블 Repository 구현체. |
| `KcpCertTransactionRepositoryInterface` | 인터페이스 | `nhnkcp_cert_transactions` 테이블 Repository 계약. |
| `KcpIdentityLogQueryRepository` | 구현 | KcpIdentityLogQueryRepositoryInterface 구현체. |
| `KcpIdentityLogQueryRepositoryInterface` | 인터페이스 | 본 plugin 이 발행한 코어 IDV 로그의 보조 조회/갱신 계약. |
| `KcpIdentityRecordRepository` | 구현 | `nhnkcp_identity_records` 테이블 Repository 구현체. |
| `KcpIdentityRecordRepositoryInterface` | 인터페이스 | `nhnkcp_identity_records` 테이블 Repository 계약. |
<!-- @generated:repositories END -->

<!-- @intent START -->
3쌍으로 나뉜 것은 `sirsoft-verification_kginicis`와 같은 이유입니다 —
`KcpCertTransactionRepository`(진행 중인 인증 거래), `KcpIdentityRecordRepository`(완료된
PII), `KcpIdentityLogQueryRepository`(코어 IDV 로그를 이 플러그인 관점으로 좁혀 읽는
어댑터)가 각자 다른 데이터를 다룹니다. `KcpDuplicateIdentityChecker`(§architecture.md)는
Repository 가 아니라 판정 로직 클래스입니다 — 두 Repository 를 조합해 판정만 하고
데이터를 소유하지 않습니다.
<!-- @intent END -->
