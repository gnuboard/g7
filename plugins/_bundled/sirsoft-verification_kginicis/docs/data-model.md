# KG이니시스 본인인증 — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 모델 | 테이블 | fillable | 관계 | 특성 |
|---|---|---|---|---|
| `InicisChallengeMapping` | `inicis_challenge_mappings` | 2 | challenge→IdentityVerificationLog | - |
| `InicisIdentityRecord` | `inicis_identity_records` | 17 | user→User | - |
<!-- @generated:models END -->

<!-- @intent START -->
`InicisIdentityRecord`가 PII(이름·생년월일·성별·CI/DI 등)를 직접 보관하는 것이 결제
플러그인들과의 근본적 차이입니다(§AGENTS.md "1. 이 확장은 무엇인가") — 이 레코드가 없으면
"이 사용자가 본인확인을 완료했는가"를 매번 이니시스에 재조회해야 합니다.
`InicisChallengeMapping`은 별도 모델입니다 — 진행 중인 인증 시도(mTxId ↔ challenge_id)와
완료된 확인 결과(PII record)는 생명주기가 다르기 때문입니다(전자는 인증 세션 하나,
후자는 사용자당 최신 1건).
<!-- @intent END -->

## 소유 테이블

<!-- @generated:tables START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 테이블 | 모델 |
|---|---|
| `inicis_challenge_mappings` | `InicisChallengeMapping` |
| `inicis_identity_records` | `InicisIdentityRecord` |
<!-- @generated:tables END -->

<!-- @intent START -->
`inicis_identity_records.user_id`는 `unique` 제약을 갖습니다 — 한 사용자는 본인확인 결과를
1건만 보유하며, 재인증 시 기존 레코드를 갱신합니다(새 레코드를 추가하지 않습니다). 이
설계 덕분에 "이 사용자가 본인확인을 완료했는가"는 단순 존재 조회 하나로 판정됩니다.
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
마이그레이션 3개.

| 파일 | 생성 테이블 | 변경 테이블 | down() |
|---|---|---|---|
| `2026_05_08_000001_create_inicis_identity_records_table.php` | `inicis_identity_records` | `inicis_identity_records` | ✅ |
| `2026_05_08_000002_create_inicis_challenge_mappings_table.php` | `inicis_challenge_mappings` | `inicis_challenge_mappings` | ✅ |
| `2026_06_22_000001_make_inicis_record_aux_fields_nullable.php` | - | `inicis_identity_records` | ✅ |
<!-- @generated:migrations END -->

<!-- @intent START -->
세 번째 마이그레이션이 `name`/`phone`/`birthday` 암호화 컬럼을 nullable 로 완화한 이유는
누락 값을 `null`로 저장하기 위함입니다 — `Crypt::encryptString('')`로 "암호화된 빈
문자열"을 저장하면 복호화 시 빈 칸이 나오는 오염 레코드가 됩니다. 정상 경로에서는
`verify()` 가드(`INCOMPLETE_IDENTITY`)가 이 신원 핵심값의 누락을 이미 차단하므로 항상
채워지며, 이 nullable 화는 가드를 우회한 비정상 입력이 암호화된 빈 문자열로 오염
저장되는 것을 막는 방어적 통일입니다.
<!-- @intent END -->

## Enum

<!-- @generated:enums START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| Enum | backing | case 수 | case |
|---|---|---|---|
| `InicisDuplicateField` | `string` | 2 | `di`, `ci` |
<!-- @generated:enums END -->

<!-- @intent START -->
`di`(연계정보)와 `ci`(연계정보의 상위 개념 — 사이트 간 동일인 식별용) 중 어느 필드로 중복
가입을 판정할지는 운영자가 설정으로 고릅니다(§settings.md `duplicate_field`). Enum 으로
닫힌 것은 이 값이 `AssertNoDuplicateInicisIdentity`의 조회 조건과
`InicisIdentityLogQueryRepository`의 컬럼 화이트리스트 양쪽에서 타입 안전하게 재사용되기
때문입니다 — 문자열 리터럴이었다면 오타가 조용히 "중복 없음"으로 판정될 위험이 있습니다.
<!-- @intent END -->

## Repository

<!-- @generated:repositories START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 클래스 | 종류 | 설명 |
|---|---|---|
| `InicisChallengeMappingRepository` | 구현 | `inicis_challenge_mappings` 테이블 Repository 구현체. |
| `InicisChallengeMappingRepositoryInterface` | 인터페이스 | 이니시스 mTxId ↔ challenge_id 매핑 Repository 인터페이스. |
| `InicisIdentityLogQueryRepository` | 구현 | InicisIdentityLogQueryRepositoryInterface 구현체. |
| `InicisIdentityLogQueryRepositoryInterface` | 인터페이스 | 본 plugin 의 동일인 검증 listener 전용 IdentityVerificationLog 조회 Repository. |
| `InicisIdentityRecordRepository` | 구현 | `inicis_identity_records` 테이블 Repository 구현체. |
| `InicisIdentityRecordRepositoryInterface` | 인터페이스 | KG이니시스 본인확인 PII 레코드 Repository 인터페이스. |
<!-- @generated:repositories END -->

<!-- @intent START -->
3쌍(인터페이스+구현체)으로 나뉜 것은 각자 다른 데이터를 다루기 때문입니다 —
`InicisChallengeMappingRepository`(진행 중인 인증 세션), `InicisIdentityRecordRepository`
(완료된 PII), `InicisIdentityLogQueryRepository`(동일인 검증 전용 `IdentityVerificationLog`
조회, 코어 로그 테이블을 이 플러그인 관점으로 좁혀 읽는 어댑터). 결제 플러그인들이
Repository 를 하나도 두지 않는 것과 대조적으로, 이 플러그인은 실제 PII 를 소유하므로
Repository 인터페이스 주입 원칙(§CLAUDE.md "Service-Repository 패턴")이 그대로 적용됩니다.
<!-- @intent END -->
