# Extension Trust Keys API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 확장 신뢰 키(환경설정 > 보안 「확장 신뢰 키」, 7.2.0) — 패키지 서명을 확인할 때 믿는 공개키 목록
2. GET 은 코어 내장 키(읽기 전용)·제작자 키·차단 스위치 상태·sodium 유무를 함께 돌려준다
3. POST(추가)·DELETE(삭제)는 탭 일괄 저장과 따로 즉시 반영되고, 같은 요청에 password(또는 verification_token)로 본인을 다시 확인한다
4. 제작자 키는 vendor 범위 1개 이상 필수, sirsoft vendor·sirsoft 로 시작하는 서명자 이름은 불가
5. 서명 형식·신뢰 모델: docs/extension/package-signing.md
```

---


### GET /api/admin/extension-trust-keys
<!-- @generated:start:api.admin.extension-trust-keys.index -->
- **라우트명**: `api.admin.extension-trust-keys.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ExtensionTrustKeyController@index`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/extension-trust-keys HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| builtin | array | `[{"key_id":"sirsoft:…","label":"sirsoft","status":"active","public_key":"…"}]` | 코어 내장 키 목록 (읽기 전용 — 코어와 함께 배포되며 이 API 로 바꿀 수 없다). 각 원소 `key_id`·`label`·`status`(`active`\|`retired`\|`revoked`)·`public_key`(raw 32바이트 표준 base64) |
| keys | array | `[]` | 운영자가 등록한 제작자 키 목록. 각 원소는 아래 `POST` 응답과 같은 필드(`id`·`key_id`·`label`·`public_key`·`vendor_scope`·`created_at`) |
| block_unsigned | boolean | `false` | 환경설정 「미서명 패키지 차단」(`security.extension_block_unsigned`) 상태. 바꾸는 것은 `POST /api/admin/settings` 다 |
| sodium_available | boolean | `true` | 이 서버에 PHP sodium 확장이 있어 Ed25519 서명을 확인할 수 있는지. `false` 면 서명된 패키지도 `unverifiable` 로 다뤄 미서명 정책을 따른다 |

**응답 예시**

```json
{
    "success": true,
    "message": "확장 신뢰 키 목록을 불러왔습니다.",
    "data": {
        "builtin": [
            {
                "key_id": "sirsoft:{16 hex}",
                "label": "sirsoft",
                "status": "active",
                "public_key": "{BASE64_PUBLIC_KEY}"
            }
        ],
        "keys": [
            {
                "id": 1,
                "key_id": "acme:1a2b3c4d5e6f7a8b",
                "label": "acme",
                "public_key": "{BASE64_PUBLIC_KEY}",
                "vendor_scope": ["acme"],
                "created_at": "2026-11-16T10:00:00+09:00"
            }
        ],
        "block_unsigned": false,
        "sodium_available": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`admin,core.settings.read`)이 없는 경우 |
| 500 | Internal Server Error | 목록을 읽지 못한 경우 (`extension_integrity.trust_keys.fetch_failed`) |

<!-- @generated:end -->

**설명** 환경설정 > 보안 「확장 신뢰 키」 화면이 읽는다. 내장 키와 제작자 키를 한 응답에 담아, 화면이 내장 키는 읽기 전용 줄로,
제작자 키는 삭제 단추가 있는 줄로 그린다. `sodium_available` 이 `false` 면 화면이 「서명을 확인할 수 없음」 경고를 보인다.


### POST /api/admin/extension-trust-keys
<!-- @generated:start:api.admin.extension-trust-keys.store -->
- **라우트명**: `api.admin.extension-trust-keys.store`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ExtensionTrustKeyController@store`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| public_key | body | string | 예 | max 64, Ed25519 raw 32바이트의 표준 base64 | 제작자 공개키. 형식이 틀리면 422 `extension_integrity.validation.public_key_invalid` |
| label | body | string | 예 | 영문·숫자·`_`·`-` 32자 이내(첫 글자 영문·숫자), `sirsoft` 로 시작 불가 | 서명자 이름. key_id 앞부분이 된다 (`{label 소문자}:{sha256(raw 공개키) 앞 16 hex}`) |
| vendor_scope | body | array | 예 | min 1, max 50 | 이 키를 믿을 vendor 목록. 쉼표로 이은 문자열도 받는다(`"acme,acmelabs"`) |
| vendor_scope.* | body | string | 예 | max 50, `^[a-z0-9][a-z0-9_]*$`, `sirsoft` 불가 | vendor — 확장 식별자의 첫 `-` 앞부분, 언어팩은 manifest `vendor` 칸과 대조한다 |
| password | body | string | verification_token 이 없으면 예(비밀번호 없는 관리자는 아니오) | — | 요청 관리자 본인의 비밀번호. 틀리면 422 `errors.password`. 비밀번호가 없는 관리자가 보내면 428 본인인증 요구 |
| verification_token | body | string | 아니오 | max 128 | 비밀번호가 없는 관리자의 대체 확인 — 코어 본인인증으로 받은 토큰. 무효·만료면 422 `errors.verification_token` |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.extension_trust_keys.store_validation_rules`).

**요청 예시**

```http
POST /api/admin/extension-trust-keys HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "public_key": "{BASE64_PUBLIC_KEY}",
    "label": "acme",
    "vendor_scope": ["acme"],
    "password": "Password123!"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 는 등록한 키의 `ExtensionTrustKeyResource` 객체 (HTTP 201)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 키 ID — 삭제 경로의 `{trustKey}` |
| key_id | string | `acme:1a2b3c4d5e6f7a8b` | 서버가 서명자 이름·공개키로 계산한 key_id. 제작자가 알려 준 값과 대조한다 |
| label | string | `acme` | 서명자 이름 |
| public_key | string | `{BASE64_PUBLIC_KEY}` | 공개키 (표준 base64) |
| vendor_scope | array | `["acme"]` | 이 키를 믿는 vendor 목록 |
| created_at | string\|null | `2026-11-16T10:00:00+09:00` | 등록 일시 (ISO 8601) |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "신뢰 키를 추가했습니다.",
    "data": {
        "id": 1,
        "key_id": "acme:1a2b3c4d5e6f7a8b",
        "label": "acme",
        "public_key": "{BASE64_PUBLIC_KEY}",
        "vendor_scope": ["acme"],
        "created_at": "2026-11-16T10:00:00+09:00"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`admin,core.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) — 공개키 형식, 서명자 이름 규칙·`sirsoft` 접두, vendor 범위 누락·형식·`sirsoft`, 이미 등록된 키(`extension_integrity.validation.duplicate_key`), 코어 내장 키와 같은 키(`extension_integrity.validation.builtin_conflict`). 비밀번호 불일치는 `errors.password`(`extension_integrity.trust_keys.password_invalid`), 무효한 인증 토큰은 `errors.verification_token`(`extension_integrity.trust_keys.reauth_invalid`) |
| 428 | Precondition Required | 비밀번호가 없는 관리자가 `password` 로 확인하려 한 경우(아무 확인 값도 없으면 그 전에 검증이 422 로 막는다) — 본인인증 뒤 `verification_token` 을 붙여 같은 요청을 다시 보낸다 |
| 500 | Internal Server Error | 저장 중 예기치 못한 오류 (`extension_integrity.trust_keys.create_failed`) |

<!-- @generated:end -->

**설명** 제작자 키를 등록한다. 탭의 저장 버튼과 별개로 이 요청 하나로 바로 반영되며, 같은 요청에서 관리자 본인을 다시 확인한다
(화면은 키 추가 창에서 비밀번호를 함께 받는다). 같은 일을 하는 명령은 `php artisan extension:trust-key add {public_key} --label= --vendor=`
이다. 서명 파일 형식·vendor 범위의 의미: [패키지 서명](../../extension/package-signing.md#운영자-절차)


### DELETE /api/admin/extension-trust-keys/{trustKey}
<!-- @generated:start:api.admin.extension-trust-keys.destroy -->
- **라우트명**: `api.admin.extension-trust-keys.destroy`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ExtensionTrustKeyController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| trustKey | path | integer | 예 | 숫자 | 삭제할 제작자 키의 `id` (`key_id` 가 아님) |
| password | body | string | verification_token 이 없으면 예(비밀번호 없는 관리자는 아니오) | — | 요청 관리자 본인의 비밀번호. 틀리면 422 `errors.password`. 비밀번호가 없는 관리자가 보내면 428 본인인증 요구 |
| verification_token | body | string | 아니오 | max 128 | 비밀번호가 없는 관리자의 대체 확인 — 코어 본인인증으로 받은 토큰. 무효·만료면 422 `errors.verification_token` |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.extension_trust_keys.destroy_validation_rules`).

**요청 예시**

```http
DELETE /api/admin/extension-trust-keys/{trustKey} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "password": "Password123!"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 삭제한 키 ID |
| key_id | string | `acme:1a2b3c4d5e6f7a8b` | 삭제한 키의 key_id |

**응답 예시**

```json
{
    "success": true,
    "message": "신뢰 키를 삭제했습니다.",
    "data": {
        "id": 1,
        "key_id": "acme:1a2b3c4d5e6f7a8b"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`admin,core.settings.update`)이 없는 경우 |
| 404 | Not Found | 해당 `id` 의 제작자 키가 없는 경우 (`extension_integrity.trust_keys.not_found`). 확인 값의 형식 검증 뒤, 비밀번호 대조보다 먼저 판정한다. 코어 내장 키는 이 목록에 없으므로 지울 수 없다 |
| 422 | Unprocessable Entity | 확인 값이 없거나(`password` 필수) 비밀번호 불일치(`errors.password`), 무효한 인증 토큰(`errors.verification_token`) |
| 428 | Precondition Required | 비밀번호가 없는 관리자가 `password` 로 확인하려 한 경우(아무 확인 값도 없으면 그 전에 검증이 422 로 막는다) — 본인인증 뒤 `verification_token` 을 붙여 같은 요청을 다시 보낸다 |
| 500 | Internal Server Error | 삭제 중 예기치 못한 오류 (`extension_integrity.trust_keys.delete_failed`) |

<!-- @generated:end -->

**설명** 제작자 키를 지운다. 지운 순간부터 그 키로 서명한 패키지는 「신뢰하지 않는 키」(`untrusted`)로 거절된다. 이미 설치된 확장은
그대로 두며, 다음 설치·업데이트부터 영향을 받는다. 같은 일을 하는 명령은 `php artisan extension:trust-key remove {key_id}` 다(명령은
`id` 가 아니라 key_id 를 받는다).
