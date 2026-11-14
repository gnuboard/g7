# KG이니시스 본인인증 — 데이터 모델

> 모델·소유 테이블·마이그레이션·Enum · 진입점: [AGENTS.md](../AGENTS.md)

## 모델

<!-- @generated:models START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 모델 | 테이블 | fillable | 관계 | 특성 |
|---|---|---|---|---|
| `InicisChallengeMapping` | `inicis_challenge_mappings` | 2 | challenge→IdentityVerificationLog | - |
| `InicisIdentityClaim` | `inicis_identity_claims` | 4 | - | - |
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
| `inicis_identity_claims` | `InicisIdentityClaim` |
| `inicis_identity_records` | `InicisIdentityRecord` |
<!-- @generated:tables END -->

<!-- @intent START -->
`inicis_identity_records.user_id`는 `unique` 제약을 갖습니다 — 한 사용자는 본인확인 결과를
1건만 보유하며, 재인증 시 기존 레코드를 갱신합니다(새 레코드를 추가하지 않습니다). 이
설계 덕분에 "이 사용자가 본인확인을 완료했는가"는 단순 존재 조회 하나로 판정됩니다.

**`inicis_identity_claims` — 실명 정보 선점표 (1.1.4)**: 같은 사람이 이메일 두 개로 동시에 가입할 때 「1인 1계정」 차단이 뚫리던 문제(KVE-2026-3192)를 막는 표입니다. 열은 `hash_type`(`di`·`ci`)·`hash`(SHA-256 64자)·`user_id`·`created_at` 이고 `unique(hash_type, hash)` 가 두 번째 선점을 거부합니다. `ClaimInicisIdentityOnRegistering` 가 코어 `core.auth.registering`(가입 트랜잭션 안, 동기)에서 중복 판정 키의 해시를 `claim()`(`insertOrIgnore` 뒤 **잠금 읽기**로 주인이 나인지 확인 — REPEATABLE READ 스냅샷에서 일반 SELECT 는 상대가 막 커밋한 행을 못 볼 수 있음)하고, 실패하면 중복가입 예외로 가입 전체를 되돌립니다. 다른 키 해시는 `claimSoft()`(선점만 시도)로 남겨 운영자가 판정 키(DI↔CI)를 바꿔도 이어지게 합니다. `user_id` 에 CASCADE 를 걸지 않고 탈퇴·삭제 리스너가 records 와 같은 트랜잭션에서 `releaseByUserId()` 로 지웁니다(탈퇴 후 재가입 허용 유지). 1.1.4 업그레이드가 기존 records 를 먼저 가입한 회원 순으로 백필하고, 같은 해시에 회원이 둘 이상이면 해시 앞 8자와 회원 ID 를 업그레이드 로그(warning)에 남깁니다 — 기존 중복 계정은 그대로 두고 앞으로의 가입만 막습니다. 판정·해시·수명주기를 플러그인이 소유하므로 KCP 와 이니시스 선점표는 서로 독립입니다(교차 차단 없음).
<!-- @intent END -->

## 마이그레이션

<!-- @generated:migrations START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
마이그레이션 4개.

| 파일 | 생성 테이블 | 변경 테이블 | down() |
|---|---|---|---|
| `2026_05_08_000001_create_inicis_identity_records_table.php` | `inicis_identity_records` | `inicis_identity_records` | ✅ |
| `2026_05_08_000002_create_inicis_challenge_mappings_table.php` | `inicis_challenge_mappings` | `inicis_challenge_mappings` | ✅ |
| `2026_06_22_000001_make_inicis_record_aux_fields_nullable.php` | - | `inicis_identity_records` | ✅ |
| `2026_11_13_000001_create_inicis_identity_claims_table.php` | `inicis_identity_claims` | `inicis_identity_claims` | ✅ |
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
| `InicisIdentityClaimRepository` | 구현 | KG이니시스 동일인 식별값 선점 Repository. |
| `InicisIdentityClaimRepositoryInterface` | 인터페이스 | KG이니시스 동일인 식별값 선점 Repository 계약. |
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
Repository 인터페이스 주입 원칙(§코어 AGENTS.md "Service-Repository 패턴")이 그대로 적용됩니다.
<!-- @intent END -->
