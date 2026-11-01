# Enrollment API 레퍼런스

> **소유**: plugin `sirsoft-totp` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Enrollment 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

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

409 응답 예시 (`sirsoft-totp::messages.errors.already_enrolled`):

```json
{
  "success": false,
  "message": "이미 인증 앱이 등록되어 있습니다. 먼저 해제한 뒤 다시 등록해주세요."
}
```

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

코드 불일치 422 응답 예시 (`sirsoft-totp::messages.errors.invalid_code`, 해제와 백업 코드 재발급도 같은 본문):

```json
{
  "success": false,
  "message": "코드가 올바르지 않습니다. 인증 앱의 현재 코드나 백업 코드를 확인해주세요."
}
```
