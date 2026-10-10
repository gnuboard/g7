# 비밀번호 정책 (작성 규칙·재사용 금지·만료)

> **관련 문서**: [authentication.md](authentication.md) | [identity-policies.md](identity-policies.md) | [privacy-retention.md](privacy-retention.md) | [notification-system.md](notification-system.md) | [api/auth.md](api/auth.md)

## TL;DR (5초 요약)

```text
1. 작성 규칙(길이·특수문자·조합·아이디 포함·반복·연속)은 PasswordPolicy::rules() 하나 — 가입·재설정·변경·관리자 등록/수정·로그인 변경 단계가 같이 쓴다
2. 재사용 금지·현재값 비교는 본인 증명(재설정 토큰·현재 비밀번호·변경 단계 식별자) 뒤에만 평가한다 — 앞에 두면 추측 오라클이 된다
3. 모든 비밀번호 쓰기는 PasswordChangeService 한 곳에서 변경일·이력(최대 9)·표지·다른 기기 로그아웃을 기록한다 — 모델 옵저버로 기록하지 않는다
4. 만료 기준일 = max(마지막 변경일 ?? 시작일, 시작일), 시작일 = 운영자가 만료를 켠 순간(서버 전용 값)
5. 로그인 관문 순서: 자격증명 → 상태 → 잠금 → 2단계 인증 → 비밀번호 변경 관문 → (휴면 해제 + 토큰 발급)
6. 배치 auth:process-password-expiry(매일 05:15) · 직권 실행 password_expiry:{notice|expire} · 건수/명단 API 가 같은 쿼리를 쓴다
```

---

## 목차

1. [설정 키](#1-설정-키)
2. [작성 규칙](#2-작성-규칙)
3. [재사용 금지와 본인 증명](#3-재사용-금지와-본인-증명)
4. [변경 기록과 다른 기기 로그아웃](#4-변경-기록과-다른-기기-로그아웃)
5. [만료 적용 판정](#5-만료-적용-판정)
6. [로그인 관문](#6-로그인-관문)
7. [배치·알림·직권 실행](#7-배치알림직권-실행)
8. [운영 전제와 한계](#8-운영-전제와-한계)
9. [잘못된 패턴](#9-잘못된-패턴)

---

## 1. 설정 키

모두 보안 카테고리(`security.*`)이며 기본값은 꺼짐이다. 정수 키의 경계는 `config('core.settings_limits.security_{key}_min|_max')` 가 단일 출처이고, 저장 검증·설정 화면 입력칸·판독부 클램프가 같은 값을 읽는다(단건 저장 경로는 범위를 검사하지 않으므로 판독부가 최종 방어선이다).

| 키 | 기본 | 범위·값 | 화면 노출 |
| --- | --- | --- | --- |
| `password_min_length` | 8 | 6~64 | ✓ |
| `require_password_special_char` | false | | ✓ |
| `password_require_char_types` | false | 영문·숫자·특수문자 중 2종류 이상 | ✓ |
| `password_block_identifier` | false | 이메일 앞부분(3자 이상) 포함 금지 | ✓ |
| `password_block_repeated_chars` · `password_repeated_chars_length` | false · 4 | 3~6 | ✓ |
| `password_block_sequential_chars` · `password_sequential_chars_length` | false · 4 | 3~6 | ✓ |
| `password_reuse_prevention_enabled` · `password_reuse_count` | false · 3 | 1~10 (현재 + 이전 N-1개) | ✓ |
| `password_expiry_enabled` | false | | ✗ |
| `password_expiry_days` | 90 | 30~730 | ✗ |
| `password_expiry_notice_days` | 7 | 1~30, 켜져 있으면 기간보다 짧아야 한다 | ✗ |
| `password_expiry_scope` | `all` | `all` 전체 · `admin` 관리자만 · `selected` 지정한 역할·회원만 | ✗ |
| `password_expiry_action` | `force` | `force` 변경해야 로그인 · `remind` 다음에 변경 허용 | ✗ |
| `password_expiry_remind_days` | 30 | 1~90 | ✗ |
| `password_expiry_batch_size` | 1000 | 0~100000 (0 = 무제한) | ✗ |
| `password_expiry_started_at` | null | ISO8601 — **서버 전용** | ✗ |

- 「화면 노출」은 `frontend_schema.security.fields` 에 선언한 10키다. 가입·변경 화면이 실제 정책대로 안내하도록 비밀번호 작성·재사용 규칙만 내보내고, 만료·IP·2단계 인증·토큰 키는 선언하지 않아 내보내지 않는다.
- `password_expiry_started_at` 은 요청으로 저장할 수 없다 — 탭 저장은 규칙이 없어 버려지고 단건 저장(`PUT /api/admin/settings/security.password_expiry_started_at`)은 값과 무관하게 422 다. 만료 토글이 꺼짐→켜짐으로 바뀌는 순간 `RecordPasswordExpiryStartListener` 가 기록한다(탭 저장 = `core.settings.filter_save_data` 필터, 단건 저장 = `before_set`/`after_set`). 껐다 다시 켜면 그날부터 다시 계산한다. 켜져 있는데 비어 있으면 판독할 때 지금으로 채운다.

## 2. 작성 규칙

`App\Rules\PasswordPolicy::rules(?string $identifier)` = `[rule(), PasswordComposition, PasswordNotGuessable($identifier)]`. `rule()` 은 확장 호환을 위해 반환 타입(`Password`)을 그대로 두었다 — 확장이 `*_validation_rules` 필터로 비밀번호 규칙을 덧붙일 때 계속 쓸 수 있다.

| 규칙 | 판정 | 문구 키 |
| --- | --- | --- |
| `PasswordComposition` | 영문(a-zA-Z)·숫자·특수문자(`\p{Z}\p{S}\p{P}` — `Password::symbols()` 와 같은 집합) 중 2종류 이상 | `validation.password_policy.char_types` |
| `PasswordNotGuessable` | ① 이메일 `@` 앞부분(3자 이상, 대소문자 무시) 포함 ② 같은 문자 N회 이상 연속 ③ 오름·내림 연속 N자 — 숫자, 영문, 자판 행(qwertyuiop·asdfghjkl·zxcvbnm) | `contains_identifier` · `repeated_chars`(:count) · `sequential_chars`(:count) |
| `PasswordNotReused` | 재사용 금지가 켜져 있으면 현재 비밀번호 또는 최근 (N-1)개 이력과 같으면 거부 | `reused`(:count) |
| `PasswordDifferentFromCurrent` | 로그인 변경 단계 전용 — 현재 비밀번호와 같으면 항상 거부 | `same_as_current` |

로그인(`LoginRequest`)에는 작성 규칙을 걸지 않는다 — 로그인은 기존 비밀번호를 대조하는 자리다.

## 3. 재사용 금지와 본인 증명

재사용·현재값 비교는 저장된 비밀번호와 대조한다. 본인 증명이 통과하기 **전에** 돌리면 "최근에 사용한 비밀번호입니다" 오류의 유무로 남의 현재·이전 비밀번호를 맞혀 볼 수 있다. 그래서 두 규칙은 `rules()` 에 넣지 않고 `withValidator()->after()`(공용 동작 `ChecksPasswordReuse`)에서 증명 필드에 오류가 없을 때만 평가한다.

| 요청 | 재사용 | 선행 조건 |
| --- | --- | --- |
| `RegisterRequest` · `CreateUserRequest` | — | — (관리자가 정해 준 비밀번호는 검사하지 않고 다음 로그인 때 바꾸게 한다) |
| `ResetPasswordRequest` | ✓ | 서비스와 같은 조건으로 재설정 토큰 확인(기록 존재·해시 일치·유효시간) |
| `ChangePasswordRequest` · `UpdateProfileRequest` | ✓ | 현재 비밀번호 오류 없음 |
| `UpdateUserRequest` | **대상 = 본인일 때만** ✓ | 본인이면 `current_password` 필수 + 오류 없음 |
| `CompletePasswordChangeRequest` | ✓ + 현재값 비교 | 변경 단계 식별자가 이 경로·이 회원에 유효 |

이력은 이전 해시만 보관하고(현재 비밀번호는 `users.password` 와 직접 비교) 회원당 최대 9건이다. 재사용 금지가 꺼져 있어도 기록한다 — 켜는 즉시 효력이 있다. bcrypt 대조는 최대 10회이고 증명 뒤에만 돈다.

## 4. 변경 기록과 다른 기기 로그아웃

`PasswordChangeService` 가 **모든 비밀번호 쓰기의 단일 기록 지점**이다. 모델 옵저버(`isDirty('password')`)로 기록하지 않는 이유: 로그인 재해시(`rehashPasswordIfRequired`)가 평문은 그대로인 채 컬럼을 다시 써서, 옵저버로 세면 로그인할 때마다 "변경" 이 되어 만료가 무력화된다.

| 메서드 | 동작 |
| --- | --- |
| `recordChange($user, $oldHash, $source, $actor, $keepTokenId)` | 비밀번호 update 와 같은 트랜잭션에서: 이력 push(9건 초과분 삭제) · `password_changed_at=now` · 표지·연기 초기화 · `password_change_required = (source==admin)` · 토큰 폐기(`keepTokenId` 하나만 남김) · 커밋 뒤 `core.auth.password_change_recorded` |
| `initializeNewUser($user, $source)` | 가입·관리자 등록·설치: 변경일 기록, 관리자 등록이면 변경 요구 |
| `purgeHistory($user)` | 탈퇴·삭제 경로에서 명시 삭제 (FK CASCADE 는 안전망) |

| 경로 | 출처 | 남기는 토큰 | 변경 요구 |
| --- | --- | --- | --- |
| 본인 변경(마이페이지·정보수정·관리자 본인) | `self` | 현재 기기 | 해제 |
| 메일 재설정 | `reset` | 없음(전부 폐기) | 해제 |
| 관리자가 다른 회원 지정 | `admin` | 없음(그 회원 전부) | **설정** |
| 로그인 변경 단계 | `login_required` | 없음 → 새 토큰 1개 | 해제 |

기존 `core.auth.after_password_changed($user)` 는 인자를 바꾸지 않고 그대로 발행한다(알림 `password_changed` 가 구독). 활동 로그는 경로를 아는 `password_change_recorded` 에서 남긴다 — 재설정은 `auth.reset_password`, 그 밖은 `auth.password_change`.

정보수정 API(`PUT /api/me`·`PUT /api/user/profile`)로 비밀번호를 함께 바꾸면 전용 경로와 같은 본인인증 정책(`core.profile.password_change`)을 `IdentityPolicyService::enforceForRequest()` 로 건다 — 라우트 미들웨어와 같은 판정 한 곳이다. 비밀번호 3종(새 값·확인·현재 값)은 서버 로그와 수정 완료 훅 인자(큐 작업 표)에 남기지 않는다.

## 5. 만료 적용 판정

`PasswordExpiryService::appliesTo()`(PHP)와 `applyScope()`(SQL — 배치·건수·명단)가 같은 판정을 한다.

1. 기능이 꺼져 있으면 적용하지 않는다. 건수·명단 쿼리(`applyScope()`)는 토글을 보지 않아 꺼져 있어도 센다 — 처리(배치·직권 실행)는 꺼져 있으면 하지 않는다([7절](#7-배치알림직권-실행)).
2. 회원 개별 `apply` → 적용, `exempt` → 제외.
3. 회원이 `inherit` 이면 역할 중 `apply` 가 하나라도 있으면 적용(역할끼리 부딪치면 적용 — 보안 우선), 없고 `exempt` 가 있으면 제외.
4. 그 밖은 사이트 범위: `all` 적용, `admin` 관리자 유형 권한을 가진 회원만, `selected` 미적용.

확장 역할 동기화(`syncRole`)는 역할의 `password_expiry_policy` 를 덮지 않는다(운영자 값, 확장 선언에 없음).

만료 일시 = 기준일 + `password_expiry_days`, 기준일 = max(`password_changed_at` ?? 시작일, 시작일). 기능 도입 전 회원(변경일 NULL)과 켜기 전에 바꾼 회원은 시작일부터 센다 — 켜자마자 전원이 만료되지 않는다.

## 6. 로그인 관문

순서 불변식: **자격증명 → 상태 → 잠금 → 2단계 인증 → 비밀번호 변경 관문 → (휴면 해제 + 토큰 발급)**. 변경 단계가 2단계 인증보다 앞에 오면 비밀번호만 훔친 공격자가 2단계 인증 없이 비밀번호를 바꿔 계정을 가져간다.

- 관문은 `loginRequirement()` 로 판정한다: 관리자가 정해 준 비밀번호(`password_change_required`)면 만료 기능이 꺼져 있어도 요구(연기 불가), 아니면 만료 적용·만료됨이면 요구 — `force` 는 연기 불가, `remind` 는 연기 기간 중 통과·그 밖에는 연기 가능.
- 요구가 있으면 토큰 대신 변경 단계 식별자를 준다(`password_change_required: true` 응답 — [api/auth.md](api/auth.md)). 식별자는 `CacheInterface` 에 10분, 회원당 1개(재발급 시 이전 것 무효), 발급 경로(user·admin)와 발급 시점의 비밀번호 해시 지문에 묶인다. 캐시에 쓰지 못하면 토큰을 주지 않고 503 이다.
- 관리자 로그인에 비관리자가 오면 관문을 건너뛴다 — 종전대로 토큰 발급 → 회수 + 403 이라 비관리자의 비밀번호가 관리자 경로로 바뀌지 않는다. 관리자 완료·연기 엔드포인트는 비밀번호를 쓰기 **전에** 관리자 여부를 확인한다.
- 완료(`POST .../login/password-change`)는 같은 식별자의 동시 제출을 잠금으로 하나만 통과시키고, 상태·잠금을 다시 본 뒤 비밀번호를 바꾸고 모든 기기를 로그아웃시킨 다음 새 토큰을 발급한다. 휴면 회원은 이 자리에서 휴면이 풀린다(`dormancy_restored`).
- 2단계 인증 코드는 관문 앞에서 이미 소비되므로 식별자가 만료되면 처음부터 다시 로그인해야 한다.
- 이미 로그인 중인 세션은 관문을 다시 지나지 않는다. 만료일이 지난 세션은 다음 배치('변경해야 로그인')가 토큰을 지워 다음 요청이 401 → 로그인 화면 → 관문으로 이어진다. 배치 전까지(최대 하루) 세션이 남는 것은 의도다.

## 7. 배치·알림·직권 실행

`auth:process-password-expiry {--dry-run} {--scheduled}` — 매일 05:15(`routes/console.php` 정리 예약 블록, `onOneServer`). 토글을 먼저 확인하고(꺼져 있으면 "실행하지 않음"), 직권 실행과 같은 락(`password-expiry-run`)을 잡는다.

| 단계 | 대상 | 처리 | 표지 |
| --- | --- | --- | --- |
| 사전 안내 | 기준일 + 기간 − 안내일 ≤ 지금 < 만료, 이번 주기 미안내 | `core.auth.password_expiry_notice` → 알림 `password_expiry_notice` | `password_expiry_notified_at` |
| 만료일 처리 | 만료됨, 이번 주기 미처리 | ('변경해야 로그인'이면) 토큰 전부 폐기 + 활동 로그 `auth.password_expiry_logout` → `core.auth.password_expired` → 알림 `password_expired` | `password_expired_processed_at` |

- 공통 대상: 활성 회원, 관리자 지정 변경 대기자 제외, 적용 범위. 휴면·차단·탈퇴·인증대기는 로그인 관문에서 처리한다.
- 1회 실행당 상한(`password_expiry_batch_size`)을 두 단계에 각각 적용하고, **만료가 가까운 순**(기준일 오름차순, 같으면 ID)으로 처리한다. 남은 인원은 다음 실행이 처리한다.
- 표지를 **먼저** 남기고 훅을 발행하며, 발행이 던지면 표지를 되돌린다(휴면 사전 안내와 같은 규칙). 비밀번호를 바꾸면 표지가 초기화되고, 기능을 다시 켜면(시작일 갱신) 새 주기로 다시 안내한다.
- 보안 탭 「지금 실행」은 직권 실행 대상 `password_expiry:notice`·`password_expiry:expire`(실행 권한 `core.settings.update` + `core.users.update`)로 개인정보 직권 실행 장치를 그대로 쓴다. 이번 실행 건수 = min(대상, 상한), 결과에 남은 수를 싣는다. 휴면·보존기간과 달리 **기능이 꺼져 있으면 접수를 409 로 거절**한다 — 꺼져 있으면 로그인 관문이 변경을 요구하지 않으므로, 처리하면 회원은 "다음 로그인 때 새 비밀번호 설정" 안내와 함께 로그아웃되지만 다시 로그인하면 그냥 들어간다. 접수 뒤 실행 전에 꺼지면 큐 작업도 처리하지 않는다. 확인 창 건수(`preview`)는 꺼져 있어도 저장값으로 센다.
- 건수 `GET /api/admin/settings/password-expiry-preview`, 명단 `GET /api/admin/privacy/password-expiry-targets/{stage}`(명단 권한 `core.settings.read` + `core.users.read`, 전체 범위) — 배치와 같은 쿼리다.

## 8. 운영 전제와 한계

- **다중 서버**: 변경 단계 식별자와 잠금은 공유 캐시를 전제한다 — `onOneServer` 예약과 같은 전제라 새 전제가 아니다. 서버마다 로컬 캐시를 쓰면 다른 서버로 간 완료 요청이 "식별자 없음" 이 된다.
- **큐 안 발송 실패**: 알림은 큐로 나간다. 표지는 발행 직전에 남기므로, 큐 워커 안에서 발송이 실패해도 표지는 되돌리지 못한다(휴면 사전 안내와 같은 한계). 실패는 알림 로그에 남는다.
- **기능을 켠 지 (기간 − 안내일)일째 대량 발송**: 켠 날부터 계산하므로 기존 회원이 같은 날 한꺼번에 안내·만료 대상이 된다. 상한이 하루 처리량을 나눈다. 처리방침에 변경 주기를 미리 안내한다.
- **메일 설정**: 메일 채널이 준비되지 않았으면 사이트 알림만 도달한다.

## 9. 잘못된 패턴

| ❌ 잘못된 패턴 | ✅ 올바른 패턴 |
| --- | --- |
| 재사용 금지 규칙을 `rules()` 에 넣는다 | 증명 뒤 `withValidator()->after()` 에서만 평가한다(추측 오라클) |
| `User` 옵저버·`isDirty('password')` 로 변경일을 기록한다 | 비밀번호를 쓰는 서비스가 `PasswordChangeService::recordChange()` 를 명시 호출한다 |
| 변경 단계를 2단계 인증보다 앞에 둔다 | 2단계 인증 뒤, 토큰 발급 앞 |
| 관리자 경로 완료에서 비밀번호를 쓴 뒤 관리자 여부를 본다 | 쓰기 **전에** `isAdmin()` |
| 만료 기준일을 `password_changed_at` 만으로 계산한다 | max(변경일 ?? 시작일, 시작일) — 켜자마자 전원 만료 방지 |
| 시작일을 설정 저장 요청으로 받는다 | 서버가 꺼짐→켜짐에서만 기록하고 요청 저장은 막는다 |
| 원시 SQL 에 테이블명을 쓴다 | 관계·컬럼명만 쓰는 식 (테이블 접두사 설치본) |
| 탈퇴·삭제 시 이력을 CASCADE 에 맡긴다 | `purgeHistory()` 로 명시 삭제 |
