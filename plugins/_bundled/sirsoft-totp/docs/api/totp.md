# TOTP API 레퍼런스

> **소유**: plugin `sirsoft-totp`, **생성**: 손 작성 개요. 엔드포인트별 절은 `php artisan api:docgen` 이 아래 「엔드포인트 문서」 표의 도메인 문서에 만들며, 생성기는 이 문서를 다시 쓰지 않습니다.

---

## TL;DR (5초 요약)

```text
1. 본인의 인증 앱 등록을 다루는 API 5개 + 관리자 강제 해제 1개
2. 본인 경로에는 사용자 식별자가 없다 — 대상은 항상 호출자 본인이다
3. 평문 시크릿·백업 코드는 발급·확정·재발급 응답에 단 한 번만 실린다 (조회로 다시 내려가지 않는다)
4. 로그인 2단계 인증의 코드 확인은 코어 API(/api/auth/login/two-factor)가 담당한다 — 여기에 없다
5. 코드 입력은 인증 앱의 6자리 코드와 백업 코드(`xxxxx-xxxxx`) 둘 다 받는다
6. 엔드포인트별 파라미터, 응답, 에러 표는 도메인 문서 3개에 있다 (아래 「엔드포인트 문서」)
```

---

## 공통 사항

**URL prefix**: `/api/plugins/sirsoft-totp/` · **라우트명 prefix**: `api.plugins.sirsoft-totp.`
(코어가 자동 부여하므로 `src/routes/api.php` 에는 꼬리만 적혀 있습니다.)

**응답 봉투**는 코어 `ResponseHelper` 규격입니다.

```json
{ "success": true,  "message": "...", "data": { } }
{ "success": false, "message": "..." }
```

**공통 에러**

| 상태 | 조건 | 비고 |
| --- | --- | --- |
| 401 | 비로그인 요청 | `auth:sanctum` 미들웨어, 또는 컨트롤러의 `getCurrentUser()` 가 null 일 때 |
| 403 | 권한 부족 | 관리자 강제 해제 1건에만 해당 (`permission` 미들웨어·슈퍼 관리자 등급 상한) |
| 409 | 상태 충돌 | 등록 시작(이미 등록됨)·확정(다른 화면이 먼저 확정) |
| 422 | `code`·`current_password` 검증 실패 또는 코드·비밀번호 불일치 | 엔드포인트별 조건은 각 도메인 문서의 에러 표 참조 |
| 429 | 회원 단위 시도 제한 초과 (1.0.3) | 등록 시작의 비밀번호 확인, 해제·재발급의 코드 확인. `Retry-After` 헤더 |

**`code` 입력 규칙** (확정·해제·재발급 공통, `TotpCodeRequest`)

- 검증 규칙: `required|string|min:6|max:32`. 위반 시 Laravel 표준 검증 422 응답
  (`errors.code` 포함).
- 검증을 통과한 값은 하이픈·공백을 걷어낸 영숫자로 정규화한 뒤 판별합니다 — **6자리 숫자면
  인증 앱 코드**, 그 밖이면 백업 코드로 봅니다. 백업 코드는 영숫자 8자 이상이어야 하며
  (`TotpIdentityProvider::BACKUP_CODE_MIN_LENGTH`), 대소문자를 가리지 않습니다.
  `abcde-fghij` 와 `ABCDEFGHIJ` 는 같은 값으로 인정됩니다.
- 실패 사유는 "코드 오답"과 "백업 코드 오답"을 구분하지 않고 모두 같은 422 로 답합니다 —
  구분해 주면 어떤 입력이 "형식은 맞다" 인지 흘러 나갑니다.
- 인증 앱 코드는 **재사용되지 않습니다**. 한 번 통과한 timestep 이하의 코드는 유효 시간이
  남아 있어도 거부됩니다(`last_used_timestep` 리플레이 가드). 백업 코드도 1회용이라
  통과하는 즉시 소진됩니다. 같은 코드를 **동시에** 여러 번 보내도 하나만 통과합니다(1.0.3) —
  timestep 전진은 「저장된 값보다 클 때만」 조건부 갱신, 백업 코드 소진은 행 잠금 안에서 대조·제거합니다.

---

## 엔드포인트 문서

경로는 모두 `/api/plugins/sirsoft-totp/` 아래입니다.

| 메서드 | 경로 | 하는 일 | 문서 |
| --- | --- | --- | --- |
| GET | `enrollment` | 본인 등록 상태 조회 | [enrollment.md](enrollment.md) |
| POST | `enrollment` | 시크릿 발급 (확정 전) | [enrollment.md](enrollment.md) |
| POST | `enrollment/confirm` | 첫 코드로 등록 확정, 백업 코드 발급 | [enrollment.md](enrollment.md) |
| DELETE | `enrollment` | 본인 등록 해제 (코드 필요) | [enrollment.md](enrollment.md) |
| POST | `backup-codes/regenerate` | 백업 코드 재발급 (코드 필요) | [backup-codes.md](backup-codes.md) |
| DELETE | `admin/credentials/{user}` | 관리자의 회원 등록 강제 해제 (코드 없음, 전용 권한) | [credentials.md](credentials.md) |

관리자 API 를 뺀 본인 경로 5개는 경로에 사용자 식별자를 두지 않습니다 — 대상은 언제나
`auth:sanctum` 이 해석한 호출자 본인이며, 남의 자격증명을 가리킬 수 있는 표면 자체가 없습니다.

---

## 여기에 없는 것

**로그인 2단계 인증의 코드 확인**은 이 플러그인이 열지 않는다. 코어 엔드포인트
(`POST /api/auth/login/two-factor`, `POST /api/admin/login/two-factor`)가 challenge id 와
코드를 받아 처리하며, 그 안에서 `TotpIdentityProvider::verify()` 가 호출된다. 로그인 경로를
두 벌로 만들면 잠금·시도 횟수 같은 코어 통제가 한쪽에만 걸리기 때문이다.

그 경로에서 이 플러그인의 검증이 돌려주는 실패 코드는 메일 프로바이더와 같은 계약을
따른다 — `NOT_FOUND` · `WRONG_PROVIDER` · `INVALID_STATE` · `EXPIRED` · `MAX_ATTEMPTS` ·
`INVALID_CODE`. 프론트와 코어가 두 수단을 같은 코드로 다룰 수 있게 하기 위해서다. 허용
시도 횟수(`settings.identity.max_attempts`, 기본 5)와 challenge 유효 시간
(`settings.identity.challenge_ttl_minutes`, 기본 15)도 코어 설정을 그대로 쓴다.
