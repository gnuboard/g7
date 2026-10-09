# TOTP API 레퍼런스

> **소유**: plugin `sirsoft-totp` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 본인의 인증 앱 등록을 다루는 API 5개 + 관리자 강제 해제 1개
2. 본인 경로에는 사용자 식별자가 없다 — 대상은 항상 호출자 본인이다
3. 평문 시크릿·백업 코드는 발급·확정·재발급 응답에 단 한 번만 실린다 (조회로 다시 내려가지 않는다)
4. 로그인 2단계 인증의 코드 확인은 코어 API(/api/auth/login/two-factor)가 담당한다 — 여기에 없다
5. 코드 입력은 인증 앱의 6자리 코드와 백업 코드(`xxxxx-xxxxx`) 둘 다 받는다
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
| 403 | 권한 부족 | 관리자 강제 해제 1건에만 해당 (`permission` 미들웨어) |
| 422 | `code` 검증 실패 또는 코드 불일치 | 아래 각 엔드포인트 참조 |

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
  통과하는 즉시 소진됩니다.

---

## 본인 등록 관리

경로에 사용자 식별자를 두지 않습니다 — 대상은 언제나 `auth:sanctum` 이 해석한 호출자
본인이며, 남의 자격증명을 가리킬 수 있는 표면 자체가 없습니다.

### GET /api/plugins/sirsoft-totp/enrollment

<!-- @generated:start:api.plugins.sirsoft-totp.enrollment.show -->
- **라우트명**: `api.plugins.sirsoft-totp.enrollment.show`
- **컨트롤러**: `Plugins\Sirsoft\Totp\Http\Controllers\TotpEnrollmentController@show`
- **인증/권한**: `auth:sanctum` (로그인 필수)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-totp/enrollment HTTP/1.1
Accept: application/json
Authorization: Bearer {token}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시 | 설명 |
| --- | --- | --- | --- |
| `enrolled` | boolean | `true` | 확정된 자격증명 보유 여부 (`confirmed_at` 이 있음). 이 값이 참일 때만 로그인 2단계 인증에 쓰인다 |
| `pending` | boolean | `false` | 발급만 하고 첫 코드 확인을 마치지 않은 상태. `enrolled` 와 동시에 참일 수 없다 |
| `confirmed_at` | string\|null | `2026-09-17T14:05:31+09:00` | 등록 확정 시각 (ISO 8601). 미확정·미등록이면 `null` |
| `backup_codes_remaining` | integer | `10` | 아직 쓰지 않은 백업 코드 개수. 확정 시 10개가 발급되고 사용할 때마다 줄어든다 |

시크릿·백업 코드의 평문은 **이 응답에 실리지 않습니다**. 조회로는 어떤 형태로도 다시
내려가지 않으며, 저장된 값도 시크릿은 암호화, 백업 코드는 bcrypt 해시라 복원할 수
없습니다.

**응답 예시**

확정 상태:

```json
{
  "success": true,
  "message": "성공적으로 처리되었습니다.",
  "data": {
    "enrolled": true,
    "pending": false,
    "confirmed_at": "2026-09-17T14:05:31+09:00",
    "backup_codes_remaining": 8
  }
}
```

아직 등록하지 않은 상태:

```json
{
  "success": true,
  "message": "성공적으로 처리되었습니다.",
  "data": {
    "enrolled": false,
    "pending": false,
    "confirmed_at": null,
    "backup_codes_remaining": 0
  }
}
```

**에러 응답**

| 상태 | 조건 | 비고 |
| --- | --- | --- |
| 401 | 비로그인 요청 | `auth:sanctum` |

<!-- @generated:end -->

**설명**

등록 화면이 어떤 상태를 그릴지 정하는 조회 API 다. `enrolled` · `pending` 두 boolean 의
조합이 화면의 세 상태(미등록 / 발급 후 미확정 / 확정)와 그대로 대응한다.
`backup_codes_remaining` 이 줄어드는 것은 백업 코드가 실제로 소진됐다는 뜻이므로, 이
값이 작아지면 재발급을 안내한다.

---

### POST /api/plugins/sirsoft-totp/enrollment

<!-- @generated:start:api.plugins.sirsoft-totp.enrollment.store -->
- **라우트명**: `api.plugins.sirsoft-totp.enrollment.store`
- **컨트롤러**: `Plugins\Sirsoft\Totp\Http\Controllers\TotpEnrollmentController@store`
- **인증/권한**: `auth:sanctum` (로그인 필수)

**요청 파라미터**

_요청 파라미터 없음._ 시크릿은 서버가 생성하며 클라이언트가 값을 제시할 수 없다.

**요청 예시**

```http
POST /api/plugins/sirsoft-totp/enrollment HTTP/1.1
Accept: application/json
Authorization: Bearer {token}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시 | 설명 |
| --- | --- | --- | --- |
| `secret` | string | `JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP` | **평문** Base32 시크릿 (160비트, 패딩 없음). 인증 앱에 직접 입력하는 키 |
| `otpauth_uri` | string | `otpauth://totp/G7%20Site:user%40example.com?secret=...&issuer=G7%20Site&algorithm=SHA1&digits=6&period=30` | 인증 앱이 QR 로 읽는 Key Uri. QR 이미지 렌더는 클라이언트 몫 |

`secret` 과 `otpauth_uri` 에 실린 평문 시크릿은 **이 응답에서만 한 번** 노출됩니다. 저장은
`encrypted` cast 로만 이뤄지고 조회 API 로는 다시 내려가지 않으므로, 사용자가 앱에 넣기
전에 화면을 벗어나면 이 시크릿은 복구할 수 없습니다 — 다시 시작하려면 해제 후 재발급해야
합니다.

`otpauth_uri` 의 발급자 표기는 플러그인 설정 `issuer` 이고, 비어 있으면 `config('app.name')`
으로 폴백합니다. 계정 표기는 `email → nickname → user-{id}` 순으로 결정됩니다.
`algorithm=SHA1` · `digits=6` · `period=30` 은 고정값이라 설정으로 바꿀 수 없습니다.

**응답 예시**

```json
{
  "success": true,
  "message": "인증 앱에 등록할 정보를 만들었습니다. 앱으로 QR 코드를 읽거나 키를 직접 입력해주세요.",
  "data": {
    "secret": "JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP",
    "otpauth_uri": "otpauth://totp/G7%20Site:user%40example.com?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=G7%20Site&algorithm=SHA1&digits=6&period=30"
  }
}
```

**에러 응답**

| 상태 | 조건 | 비고 |
| --- | --- | --- |
| 401 | 비로그인 요청 | `auth:sanctum` |
| 409 | 이미 확정된 자격증명이 있음 | `AlreadyEnrolledException` → `sirsoft-totp::messages.errors.already_enrolled` |

409 예시:

```json
{
  "success": false,
  "message": "이미 인증 앱이 등록되어 있습니다. 먼저 해제한 뒤 다시 등록해주세요."
}
```

<!-- @generated:end -->

**설명**

등록의 시작점이다. 확정된 자격증명 위에는 덮어쓰지 않고 409 로 거부한다 — 덮어쓰면 기존
앱이 조용히 무효가 되고 사용자는 다음 로그인에서야 그 사실을 안다. 바꾸려면 해제가
먼저다.

반면 **미확정 자격증명은 덮어쓴다**. 등록을 하다 만 상태라 보호할 것이 없고, 남겨 두면
QR 을 다시 띄울 방법이 없어진다. 이때 `confirmed_at` · `last_used_timestep` ·
`backup_codes` 가 함께 비워지므로 이전 시크릿으로 만든 코드나 옛 백업 코드는 살아남지
않는다.

이 시점의 자격증명은 아직 로그인에 쓰이지 않는다 — `canChallengeUser()` 가 확정본만 보기
때문이며, 그래서 발급만 하고 확정하지 않아도 계정이 잠기지 않는다.

---

### POST /api/plugins/sirsoft-totp/enrollment/confirm

<!-- @generated:start:api.plugins.sirsoft-totp.enrollment.confirm -->
- **라우트명**: `api.plugins.sirsoft-totp.enrollment.confirm`
- **컨트롤러**: `Plugins\Sirsoft\Totp\Http\Controllers\TotpEnrollmentController@confirm`
- **인증/권한**: `auth:sanctum` (로그인 필수)
- **FormRequest**: `Plugins\Sirsoft\Totp\Http\Requests\TotpCodeRequest`

**요청 파라미터**

| 파라미터 | 타입 | 필수 | 규칙 | 설명 |
| --- | --- | --- | --- | --- |
| `code` | string | ✅ | `min:6` `max:32` | 인증 앱이 만든 6자리 코드. 확정 단계이므로 백업 코드는 아직 존재하지 않는다 |

**요청 예시**

```http
POST /api/plugins/sirsoft-totp/enrollment/confirm HTTP/1.1
Content-Type: application/json
Accept: application/json
Authorization: Bearer {token}

{ "code": "492013" }
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시 | 설명 |
| --- | --- | --- | --- |
| `backup_codes` | string[] | `["a3f9k-2mq7z", ...]` | **평문** 백업 코드 10개. 표기는 `xxxxx-xxxxx` (소문자 영숫자) |

평문 백업 코드는 **이 응답에서만 한 번** 노출됩니다. 저장은 bcrypt 해시 배열로만
이뤄지므로 조회 API 로 다시 볼 수 없고, 잃어버리면 재발급(기존 코드 전부 무효)이 유일한
복구 수단입니다.

**응답 예시**

```json
{
  "success": true,
  "message": "인증 앱 등록이 완료됐습니다. 백업 코드를 안전한 곳에 보관해주세요.",
  "data": {
    "backup_codes": [
      "a3f9k-2mq7z", "h7x2p-9wrt4", "k4m8b-3zqv6", "p9d1n-7htc2", "s2g6r-4kxw8",
      "t5j3v-8bnm1", "w8q7c-2fdl5", "x1z4h-6prk9", "y6b9t-5vgs3", "z3n5m-1qwj7"
    ]
  }
}
```

**에러 응답**

| 상태 | 조건 | 비고 |
| --- | --- | --- |
| 401 | 비로그인 요청 | `auth:sanctum` |
| 422 | `code` 검증 실패 | Laravel 표준 검증 응답 (`errors.code`) |
| 422 | 코드 불일치 · 자격증명 없음 · 이미 확정됨 | 세 경우 모두 같은 메시지 `sirsoft-totp::messages.errors.invalid_code` |

422 예시:

```json
{
  "success": false,
  "message": "코드가 올바르지 않습니다. 인증 앱의 현재 코드나 백업 코드를 확인해주세요."
}
```

<!-- @generated:end -->

**설명**

앱이 실제로 코드를 만들 수 있는지 확인해 등록을 확정하고, 그 대가로 백업 코드를 발급한다.
확정 없이는 이 자격증명이 로그인에 쓰이지 않으므로, 이 호출이 성공해야 비로소 2단계 인증
수단이 된다.

확정에 쓴 코드의 timestep 은 `last_used_timestep` 에 새겨진다 — 방금 확정에 쓴 코드를
곧바로 로그인에 재사용하지 못하게 하기 위해서다.

실패 사유 세 가지(코드 불일치 / 발급받은 자격증명이 없음 / 이미 확정됨)를 422 한 종류로
뭉뚱그리는 것은 의도다. 구분해 주면 자격증명 존재 여부가 코드 없이 흘러 나간다. 다만
`enrolled` 여부 자체는 본인이 조회 API 로 확인할 수 있다.

---

### DELETE /api/plugins/sirsoft-totp/enrollment

<!-- @generated:start:api.plugins.sirsoft-totp.enrollment.destroy -->
- **라우트명**: `api.plugins.sirsoft-totp.enrollment.destroy`
- **컨트롤러**: `Plugins\Sirsoft\Totp\Http\Controllers\TotpEnrollmentController@destroy`
- **인증/권한**: `auth:sanctum` (로그인 필수)
- **FormRequest**: `Plugins\Sirsoft\Totp\Http\Requests\TotpCodeRequest`

**요청 파라미터**

| 파라미터 | 타입 | 필수 | 규칙 | 설명 |
| --- | --- | --- | --- | --- |
| `code` | string | ✅ | `min:6` `max:32` | 인증 앱의 현재 6자리 코드 **또는** 남은 백업 코드 |

**요청 예시**

```http
DELETE /api/plugins/sirsoft-totp/enrollment HTTP/1.1
Content-Type: application/json
Accept: application/json
Authorization: Bearer {token}

{ "code": "492013" }
```

**응답 필드**

`data` 는 `null` 이다 — 해제는 돌려줄 상태가 없다.

**응답 예시**

```json
{
  "success": true,
  "message": "인증 앱 등록을 해제했습니다.",
  "data": null
}
```

**에러 응답**

| 상태 | 조건 | 비고 |
| --- | --- | --- |
| 401 | 비로그인 요청 | `auth:sanctum` |
| 422 | `code` 검증 실패 | Laravel 표준 검증 응답 (`errors.code`) |
| 422 | 코드 불일치 · 확정된 자격증명 없음 | 같은 메시지 `sirsoft-totp::messages.errors.invalid_code` |

<!-- @generated:end -->

**설명**

해제에도 현재 코드를 요구한다 — 사용자가 잠깐 자리를 비운 사이 열린 세션으로 2단계 인증을
꺼 버리는 경로를 막기 위해서다. 비밀번호 재입력이 아니라 코드를 요구하는 것은, 세션을
탈취한 쪽이 갖지 못한 것이 바로 인증 앱이기 때문이다.

백업 코드로도 해제할 수 있다. 단말을 잃은 사용자의 자력 구제 경로이며, 이때 그 백업 코드는
소진된다(해제 자체가 자격증명을 통째로 지우므로 남은 코드도 함께 사라진다).

성공하면 자격증명 행이 삭제된다 — 비활성화 플래그가 아니라 실제 삭제이므로, 다시
쓰려면 처음부터 등록한다.

---

### POST /api/plugins/sirsoft-totp/backup-codes/regenerate

<!-- @generated:start:api.plugins.sirsoft-totp.backup-codes.regenerate -->
- **라우트명**: `api.plugins.sirsoft-totp.backup-codes.regenerate`
- **컨트롤러**: `Plugins\Sirsoft\Totp\Http\Controllers\TotpEnrollmentController@regenerateBackupCodes`
- **인증/권한**: `auth:sanctum` (로그인 필수)
- **FormRequest**: `Plugins\Sirsoft\Totp\Http\Requests\TotpCodeRequest`

**요청 파라미터**

| 파라미터 | 타입 | 필수 | 규칙 | 설명 |
| --- | --- | --- | --- | --- |
| `code` | string | ✅ | `min:6` `max:32` | 인증 앱의 현재 6자리 코드 **또는** 남은 백업 코드 |

**요청 예시**

```http
POST /api/plugins/sirsoft-totp/backup-codes/regenerate HTTP/1.1
Content-Type: application/json
Accept: application/json
Authorization: Bearer {token}

{ "code": "a3f9k-2mq7z" }
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시 | 설명 |
| --- | --- | --- | --- |
| `backup_codes` | string[] | `["b7k2m-4xqp9", ...]` | **평문** 백업 코드 10개 (새로 발급). 이전 목록은 전부 무효 |

확정 때와 마찬가지로 평문은 **이 응답에서만 한 번** 노출됩니다. 저장은 bcrypt 해시로만
이뤄집니다.

**응답 예시**

```json
{
  "success": true,
  "message": "백업 코드를 새로 만들었습니다. 이전 코드는 더 이상 쓸 수 없습니다.",
  "data": {
    "backup_codes": [
      "b7k2m-4xqp9", "c9r5t-1hdw3", "d2v8n-6jzl7", "e5f1q-9smb4", "f8h3c-2tkr6",
      "g1w6p-7ynd5", "j4l9z-3gvx8", "m6t2k-5bqh1", "n9s4d-8cwf2", "r3p7v-6mjt9"
    ]
  }
}
```

**에러 응답**

| 상태 | 조건 | 비고 |
| --- | --- | --- |
| 401 | 비로그인 요청 | `auth:sanctum` |
| 422 | `code` 검증 실패 | Laravel 표준 검증 응답 (`errors.code`) |
| 422 | 코드 불일치 · 확정된 자격증명 없음 | 같은 메시지 `sirsoft-totp::messages.errors.invalid_code` |

<!-- @generated:end -->

**설명**

백업 코드를 다 썼거나 보관 매체가 노출됐을 때 10개를 새로 발급한다. 시크릿은 그대로이므로
인증 앱을 다시 등록할 필요는 없다.

**이전 목록은 전부 무효가 된다.** 새 목록으로 통째로 교체하기 때문이며, 그래서 새 코드를
받아 적기 전에는 옛 목록을 버리면 안 된다.

본인 확인에 남은 백업 코드를 쓸 수도 있는데, 이때 그 코드는 교체 전에 소진된다 — 해제·
재발급도 1회용 규칙을 그대로 따른다.

---

## 관리자 API

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

_본문 파라미터 없음._ **코드를 요구하지 않는다** — 단말과 백업 코드를 모두 잃은 회원을
위한 강제 해제이므로, 확인의 책임은 입력이 아니라 권한이 진다.

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

404 예시:

```json
{
  "success": false,
  "message": "요청한 리소스를 찾을 수 없습니다."
}
```

<!-- @generated:end -->

**설명**

단말과 백업 코드를 모두 잃은 회원의 유일한 구제 경로다. 코드를 요구하지 않으므로 전용
권한 `sirsoft-totp.credentials.delete` 를 **라우트 미들웨어에서** 강제한다 — 회원 정보를
볼 수 있다는 것과 그 사람의 2단계 인증을 해제할 수 있다는 것은 다른 신뢰 수준이라, 회원
관리 권한과 분리했다. 위임이 필요하면 역할 「인증 앱 운영자」(`sirsoft-totp.credentials`)
를 부여한다.

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
그때는 서버에서 `php artisan totp:reset {email}` 을 실행한다 — 같은
`TotpEnrollmentService::reset()` 을 호출하지만 웹 권한 체계를 거치지 않으며, 컨트롤러를
지나지 않으므로 위 훅도 발행하지 않는다.

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
