# Credentials API 레퍼런스

> **소유**: plugin `sirsoft-totp` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Credentials 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### DELETE /api/plugins/sirsoft-totp/admin/credentials/{user}

<!-- @generated:start:api.plugins.sirsoft-totp.admin.credentials.destroy -->
- **라우트명**: `api.plugins.sirsoft-totp.admin.credentials.destroy`
- **컨트롤러**: `Plugins\Sirsoft\Totp\Http\Controllers\TotpAdminCredentialController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:admin,sirsoft-totp.credentials.delete`

**경로 파라미터**

| 파라미터 | 타입 | 제약 | 설명 |
| --- | --- | --- | --- |
| `user` | string(uuid) | 라우트-모델 바인딩 | 등록을 해제할 대상 회원의 **uuid**. `User::getRouteKeyName()` 이 uuid 를 돌려주므로 관리자 화면 주소(`/admin/users/:id`)에 실리는 값과 같다. 없는 회원이면 프레임워크가 404 를 낸다 |

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| user | path | string | 예 | — | 등록을 해제할 대상 회원의 uuid (위 경로 파라미터 표) |

**요청 예시**

```http
DELETE /api/plugins/sirsoft-totp/admin/credentials/a2c4c8b6-85b5-46ef-bf12-c3b006ed8892 HTTP/1.1
Accept: application/json
Authorization: Bearer {token}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시 | 설명 |
| --- | --- | --- | --- |
| `user_id` | integer | `1024` | 대상 회원 ID |
| `removed` | boolean | `true` | 실제로 지운 자격증명이 있었는지. 등록이 없던 회원이면 `false` 이며 이때도 200 이다 — 다만 `message` 는 「해제할 것이 없음」 으로 달라진다. 회원 상세 카드는 관리자 회원 상세 응답의 `totp_enrollment.enrolled`(해제 권한자에게만 실림)로 등록 여부를 보이고 미등록 회원에게는 이 단추를 내지 않는다 |

이 응답에는 시크릿·백업 코드 계열 값이 전혀 실리지 않습니다. 관리자는 회원의 인증 수단을
**없앨** 수 있을 뿐 볼 수는 없습니다.

**응답 예시**

실제로 해제된 경우:

```json
{
  "success": true,
  "message": "이 회원의 인증 앱 등록을 해제했습니다.",
  "data": { "user_id": 1024, "removed": true }
}
```

등록이 없던 회원인 경우 (요청 자체는 성공):

```json
{
  "success": true,
  "message": "이 회원은 등록한 인증 앱이 없어 해제할 것이 없습니다.",
  "data": { "user_id": 1024, "removed": false }
}
```

**에러 응답**

| 상태 | 조건 | 비고 |
| --- | --- | --- |
| 401 | 비로그인 요청 | `auth:sanctum` |
| 403 | `sirsoft-totp.credentials.delete` 권한 없음 | `permission` 미들웨어가 차단 |
| 404 | 대상 회원이 존재하지 않음 | `common.not_found` |

<!-- @generated:end -->

**설명**

단말과 백업 코드를 모두 잃은 회원의 유일한 구제 경로다. 코드를 요구하지 않으므로 전용
권한 `sirsoft-totp.credentials.delete` 를 **라우트 미들웨어에서** 강제한다 — 회원 정보를
볼 수 있다는 것과 그 사람의 2단계 인증을 해제할 수 있다는 것은 다른 신뢰 수준이라, 회원
관리 권한과 분리했다. 위임이 필요하면 역할 「인증 앱 운영자」(`sirsoft-totp.credentials`)
를 부여한다.

본문 파라미터는 없다. 본인 해제와 달리 **코드를 요구하지 않는다** — 단말과 백업 코드를 모두
잃은 회원을 위한 강제 해제이므로, 확인의 책임은 입력이 아니라 권한이 진다.

실제로 지워진 경우에만 `sirsoft-totp.credential.admin_reset` action 훅이 발행된다
(인자 `(User $user, array $context)`, `$context` 는 `actor_id` · `ip_address`). 누가 누구의
2단계 인증을 언제 풀었는지 추적할 수 없으면 이 경로 자체가 권한 상승 수단이 되므로, 감사
기록이 필요한 사이트는 이 훅을 잡는다. 지울 것이 없었던 호출(`removed: false`)에는 훅이
발행되지 않는다 — 존재하지 않는 사건이 기록에 쌓이지 않게 하기 위해서다.
서버 셸의 `php artisan totp:reset {email}` 도 같은 훅을 발행한다(`TotpEnrollmentService::adminReset()`) —
셸에는 요청 사용자·IP 가 없어 `actor_id`·`ip_address` 가 `null` 이고, 활동 로그 행위자는 「시스템(CLI)」로 보인다.

해제된 회원은 다음 로그인부터 인증 앱 코드를 요구받지 않는다. 플러그인 설정
`allow_mail_fallback` 이 켜져 있으면 이메일로 인증번호를 받고, 꺼져 있으면 코어가
그 회원에게 쓸 수 있는 수단이 없다고 판단한다.

**관리자 본인이 잠긴 경우** 이 API 를 쓸 수 없다(관리자 화면에 들어갈 수 없으므로).
그때는 서버에서 `php artisan totp:reset {email}` 을 실행한다 — 웹 권한 체계를 거치지 않지만
이 API 와 같은 `TotpEnrollmentService::adminReset()` 을 호출하므로, 실제로 지운 경우 위 훅도
똑같이 발행된다(앞 문단의 셸 호출 설명과 같다).

404 응답 예시 (`common.not_found`):

```json
{
  "success": false,
  "message": "요청한 리소스를 찾을 수 없습니다."
}
```

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
