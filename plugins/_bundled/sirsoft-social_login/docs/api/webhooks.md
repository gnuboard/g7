# Webhooks API 레퍼런스

> **소유**: plugin `sirsoft-social_login` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Webhooks 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /plugins/sirsoft-social_login/webhooks/facebook/data-deletion
<!-- @generated:start:web.plugins.sirsoft-social_login.webhooks.facebook.data-deletion -->
- **라우트명**: `web.plugins.sirsoft-social_login.webhooks.facebook.data-deletion`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\WebhookController@facebookDataDeletion`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| signed_request | body | string | 아니오 | max 8192 | 페이스북이 보낸 서명된 요청. `{base64url 서명}.{base64url JSON 본문}` 형식이고 본문에 `algorithm`(`HMAC-SHA256`), `user_id`, `issued_at` 이 있다. 서명은 앱 비밀값으로 대조한다 |

**요청 예시**

```http
POST /plugins/sirsoft-social_login/webhooks/facebook/data-deletion HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "signed_request": "예시값"
}
```

**응답 필드** (`data` 내부)

_응답 봉투를 쓰지 않는다: 페이스북 데이터 삭제 콜백 규약 본문의 최상위 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| url | string | `https://example.com/plugins/sirsoft-social_login/data-deletion/Ab3dE5fG7hJ9kL1mN2pQ` | 회원이 삭제 진행 상태를 확인할 주소. 사이트 주소(`APP_URL`) 기준 `/plugins/sirsoft-social_login/data-deletion/{확인 코드}` |
| confirmation_code | string | `Ab3dE5fG7hJ9kL1mN2pQ` | 확인 코드(20자 영숫자). 같은 요청이 다시 오면 처음 발급한 코드를 그대로 돌려준다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "url": "https://example.com/plugins/sirsoft-social_login/data-deletion/Ab3dE5fG7hJ9kL1mN2pQ",
    "confirmation_code": "Ab3dE5fG7hJ9kL1mN2pQ"
}
```

검증 실패(400) 본문:

```json
{ "error": "webhook_signature_invalid" }
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 검증 실패. 본문 `{"error": "{사유}"}`. 사유는 `webhook_signature_invalid`(앱 비밀값이 없거나 `signed_request` 형식, HMAC-SHA256 서명이 틀림), `webhook_payload_invalid`(입력 검증 실패, 알고리즘이 `HMAC-SHA256` 이 아님, `user_id` 없음, 저장된 확인 코드가 없음), `provider_unavailable`(페이스북 제공자 행이 없음) |
| 422 | (반환하지 않음) | 입력 검증 실패(`signed_request` 가 문자열이 아니거나 8192자 초과)도 422 가 아니라 400 `{"error":"webhook_payload_invalid"}` 로 답한다 |
| 429 | Too Many Requests | `throttle:120,1,sirsoft-social_login.webhooks` 초과 |

<!-- @generated:end -->

**설명**

페이스북 앱 설정의 「데이터 삭제 요청 콜백 URL」에 등록하는 주소다. 회원이 페이스북에서 이 앱의 데이터 삭제를 요청하면 페이스북 서버가 `signed_request` 를 보낸다.

검증은 페이스북 드라이버가 한다: `signed_request` 를 `{서명}.{본문}` 으로 나눠 앱 비밀값(`client_secret`)의 HMAC-SHA256 으로 서명을 대조하고, 본문(base64url JSON)의 `algorithm` 이 `HMAC-SHA256` 이고 `user_id` 가 있어야 한다. 사건 키는 「삭제, 사용자 ID, `issued_at`」이다.

응답은 페이스북 규약대로 봉투 없이 `url` 과 `confirmation_code` 를 싣는다. 회원은 그 주소(`GET /plugins/sirsoft-social_login/data-deletion/{code}`, [data-deletion.md](data-deletion.md))에서 진행 상태를 본다.

검증을 통과하면 사건 키로 한 번만 저장하고(같은 알림이 다시 오면 저장하지 않고 같은 응답) 처리 작업 `ProcessWebhookEventJob` 을 큐에 넣는다. 연결 해제와 회원 탈퇴는 큐 작업이 맡는다: 연결된 계정이 없으면 `ignored`(`not_linked`), 있으면 연결을 해제하고 플러그인 설정 `webhook_unlink_policy` 가 `withdraw_user` 이면 회원을 탈퇴시킨다(탈퇴가 거절되면 `failed`). 큐에 넣지 못해도 제공자에게는 받았다고 답하고 행은 `received` 로 남는다. 제공자가 꺼져 있어도 수신은 한다.

검증에 실패한 수신도 웹훅 수신 이력에 `status: ignored` 행으로 남고 `error` 에 사유가 실린다(관리자 「웹훅 수신 이력」과 진단 화면에서 본다). 행의 본문은 발신 IP 만 담는다.


### POST /plugins/sirsoft-social_login/webhooks/facebook/deauthorize
<!-- @generated:start:web.plugins.sirsoft-social_login.webhooks.facebook.deauthorize -->
- **라우트명**: `web.plugins.sirsoft-social_login.webhooks.facebook.deauthorize`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\WebhookController@facebookDeauthorize`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| signed_request | body | string | 아니오 | max 8192 | 페이스북이 보낸 서명된 요청. `{base64url 서명}.{base64url JSON 본문}` 형식이고 본문에 `algorithm`(`HMAC-SHA256`), `user_id`, `issued_at` 이 있다. 서명은 앱 비밀값으로 대조한다 |

**요청 예시**

```http
POST /plugins/sirsoft-social_login/webhooks/facebook/deauthorize HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "signed_request": "예시값"
}
```

**응답 필드** (`data` 내부)

_응답 봉투를 쓰지 않는다: 본문 최상위 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| success | boolean | `true` | 받았다는 표시. 검증 실패면 이 필드 대신 `error` |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true
}
```

검증 실패(400) 본문:

```json
{ "error": "webhook_signature_invalid" }
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 검증 실패. 본문 `{"error": "{사유}"}`. 사유는 `webhook_signature_invalid`(앱 비밀값이 없거나 `signed_request` 형식, HMAC-SHA256 서명이 틀림), `webhook_payload_invalid`(입력 검증 실패, 알고리즘이 `HMAC-SHA256` 이 아님, `user_id` 없음), `provider_unavailable`(페이스북 제공자 행이 없음) |
| 422 | (반환하지 않음) | 입력 검증 실패(`signed_request` 가 문자열이 아니거나 8192자 초과)도 422 가 아니라 400 `{"error":"webhook_payload_invalid"}` 로 답한다 |
| 429 | Too Many Requests | `throttle:120,1,sirsoft-social_login.webhooks` 초과 |

<!-- @generated:end -->

**설명**

페이스북 앱 설정의 「앱 권한 해제 콜백 URL」에 등록하는 주소다. 회원이 페이스북에서 이 앱의 권한을 지우면 페이스북 서버가 `signed_request` 를 보낸다.

검증은 페이스북 드라이버가 한다: `signed_request` 를 `{서명}.{본문}` 으로 나눠 앱 비밀값(`client_secret`)의 HMAC-SHA256 으로 서명을 대조하고, 본문(base64url JSON)의 `algorithm` 이 `HMAC-SHA256` 이고 `user_id` 가 있어야 한다. 사건 키는 「권한 해제, 사용자 ID, `issued_at`」이다. 성공하면 200 `{"success": true}`, 검증 실패는 400 이다.

검증을 통과하면 사건 키로 한 번만 저장하고(같은 알림이 다시 오면 저장하지 않고 같은 응답) 처리 작업 `ProcessWebhookEventJob` 을 큐에 넣는다. 연결 해제와 회원 탈퇴는 큐 작업이 맡는다: 연결된 계정이 없으면 `ignored`(`not_linked`), 있으면 연결을 해제하고 플러그인 설정 `webhook_unlink_policy` 가 `withdraw_user` 이면 회원을 탈퇴시킨다(탈퇴가 거절되면 `failed`). 큐에 넣지 못해도 제공자에게는 받았다고 답하고 행은 `received` 로 남는다. 제공자가 꺼져 있어도 수신은 한다.

검증에 실패한 수신도 웹훅 수신 이력에 `status: ignored` 행으로 남고 `error` 에 사유가 실린다(관리자 「웹훅 수신 이력」과 진단 화면에서 본다). 행의 본문은 발신 IP 만 담는다.


### GET /plugins/sirsoft-social_login/webhooks/kakao
<!-- @generated:start:web.plugins.sirsoft-social_login.webhooks.kakao::get -->
- **라우트명**: `web.plugins.sirsoft-social_login.webhooks.kakao`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\WebhookController@kakao`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| app_id | query | string | 아니오 | max 50 | app 식별자 |
| user_id | query | string | 아니오 | max 191 | user 식별자 |
| referrer_type | query | string | 아니오 | max 40 | 연결 끊기 사유. `ACCOUNT_DELETE`, `FORCED_ACCOUNT_DELETE`(카카오 계정 삭제) 이면 사건 종류 `account_delete`, `INCOMPLETE_SIGN_UP` 이면 `incomplete_signup`, 그 밖이나 생략은 `unlink` |

**요청 예시**

```http
GET /plugins/sirsoft-social_login/webhooks/kakao?app_id=%EC%98%88%EC%8B%9C%EA%B0%92&user_id=%EC%98%88%EC%8B%9C%EA%B0%92&referrer_type=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_JSON 이 아니다: 빈 본문 200._

카카오는 실패 응답이 쌓이면 알림을 멈추므로 검증에 실패해도, 입력 형식이 틀려도 언제나 빈 본문 200 으로 답한다. 거절 여부는 응답이 아니라 수신 이력의 `status: ignored` 로 남는다.

**응답 예시**

```http
HTTP/1.1 200
Content-Length: 0
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | (반환하지 않음) | 입력 검증 실패(`app_id` 50자, `user_id` 191자, `referrer_type` 40자 초과 등)도 빈 본문 200 으로 답하고 수신 이력에 `ignored`(`webhook_payload_invalid`)로 남긴다 |
| 429 | Too Many Requests | `throttle:120,1,sirsoft-social_login.webhooks` 초과 |

<!-- @generated:end -->

**설명**

카카오 개발자 콘솔의 「연결 끊기 알림」 주소다(관리자 제공자 설정 응답의 `webhook_urls.unlink`). 카카오 서버가 GET 쿼리로 보낸다. 같은 처리를 GET 과 POST 둘 다 받는다.

검증은 카카오 드라이버가 순서대로 한다.

1. `Authorization` 헤더가 `KakaoAK {어드민 키}`(제공자 설정 `admin_key`)와 같아야 한다. 어드민 키가 비었거나 다르면 `webhook_auth_invalid`.
2. 제공자 설정 `webhook_ip_allowlist_enabled`(기본 켜짐)가 켜져 있으면 발신 IP 가 카카오 발신 대역 안이어야 한다. 아니면 `webhook_ip_not_allowed`. 리버스 프록시 뒤 사이트는 신뢰 프록시를 설정해야 실제 발신 IP 가 보인다(진단 응답 `webhooks.trusted_proxy_configured`).
3. 제공자 설정 `webhook_app_id` 를 넣어 두었으면 `app_id` 가 같아야 한다. 아니면 `webhook_app_mismatch`.
4. `user_id` 가 없으면 `webhook_payload_invalid`.

`referrer_type` 이 `ACCOUNT_DELETE` 또는 `FORCED_ACCOUNT_DELETE` 이면 사건 종류 `account_delete`, `INCOMPLETE_SIGN_UP` 이면 `incomplete_signup`(회원 탈퇴 정책이 켜져 있어도 연결 해제만 한다), 그 밖은 `unlink` 다. 카카오 알림에는 사건 번호가 없어 사건 키는 「사용자 ID, 사유, 수신 분」이다.

검증을 통과하면 사건 키로 한 번만 저장하고(같은 알림이 다시 오면 저장하지 않고 같은 응답) 처리 작업 `ProcessWebhookEventJob` 을 큐에 넣는다. 연결 해제와 회원 탈퇴는 큐 작업이 맡는다: 연결된 계정이 없으면 `ignored`(`not_linked`), 있으면 연결을 해제하고 플러그인 설정 `webhook_unlink_policy` 가 `withdraw_user` 이면 회원을 탈퇴시킨다(탈퇴가 거절되면 `failed`). 큐에 넣지 못해도 제공자에게는 받았다고 답하고 행은 `received` 로 남는다. 제공자가 꺼져 있어도 수신은 한다.

검증에 실패한 수신도 웹훅 수신 이력에 `status: ignored` 행으로 남고 `error` 에 사유가 실린다(관리자 「웹훅 수신 이력」과 진단 화면에서 본다). 행의 본문은 발신 IP 만 담는다.


### POST /plugins/sirsoft-social_login/webhooks/kakao
<!-- @generated:start:web.plugins.sirsoft-social_login.webhooks.kakao::post -->
- **라우트명**: `web.plugins.sirsoft-social_login.webhooks.kakao`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\WebhookController@kakao`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| app_id | body | string | 아니오 | max 50 | app 식별자 |
| user_id | body | string | 아니오 | max 191 | user 식별자 |
| referrer_type | body | string | 아니오 | max 40 | 연결 끊기 사유. `ACCOUNT_DELETE`, `FORCED_ACCOUNT_DELETE`(카카오 계정 삭제) 이면 사건 종류 `account_delete`, `INCOMPLETE_SIGN_UP` 이면 `incomplete_signup`, 그 밖이나 생략은 `unlink` |

**요청 예시**

```http
POST /plugins/sirsoft-social_login/webhooks/kakao HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "app_id": "예시값",
    "user_id": "예시값",
    "referrer_type": "예시값"
}
```

**응답 필드** (`data` 내부)

_JSON 이 아니다: 빈 본문 200._

카카오는 실패 응답이 쌓이면 알림을 멈추므로 검증에 실패해도, 입력 형식이 틀려도 언제나 빈 본문 200 으로 답한다. 거절 여부는 응답이 아니라 수신 이력의 `status: ignored` 로 남는다.

**응답 예시**

```http
HTTP/1.1 200
Content-Length: 0
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | (반환하지 않음) | 입력 검증 실패(`app_id` 50자, `user_id` 191자, `referrer_type` 40자 초과 등)도 빈 본문 200 으로 답하고 수신 이력에 `ignored`(`webhook_payload_invalid`)로 남긴다 |
| 429 | Too Many Requests | `throttle:120,1,sirsoft-social_login.webhooks` 초과 |

<!-- @generated:end -->

**설명**

카카오 개발자 콘솔의 「연결 끊기 알림」 주소다(관리자 제공자 설정 응답의 `webhook_urls.unlink`). 카카오 서버가 POST 본문으로 보낸다. 같은 처리를 GET 과 POST 둘 다 받는다.

검증은 카카오 드라이버가 순서대로 한다.

1. `Authorization` 헤더가 `KakaoAK {어드민 키}`(제공자 설정 `admin_key`)와 같아야 한다. 어드민 키가 비었거나 다르면 `webhook_auth_invalid`.
2. 제공자 설정 `webhook_ip_allowlist_enabled`(기본 켜짐)가 켜져 있으면 발신 IP 가 카카오 발신 대역 안이어야 한다. 아니면 `webhook_ip_not_allowed`. 리버스 프록시 뒤 사이트는 신뢰 프록시를 설정해야 실제 발신 IP 가 보인다(진단 응답 `webhooks.trusted_proxy_configured`).
3. 제공자 설정 `webhook_app_id` 를 넣어 두었으면 `app_id` 가 같아야 한다. 아니면 `webhook_app_mismatch`.
4. `user_id` 가 없으면 `webhook_payload_invalid`.

`referrer_type` 이 `ACCOUNT_DELETE` 또는 `FORCED_ACCOUNT_DELETE` 이면 사건 종류 `account_delete`, `INCOMPLETE_SIGN_UP` 이면 `incomplete_signup`(회원 탈퇴 정책이 켜져 있어도 연결 해제만 한다), 그 밖은 `unlink` 다. 카카오 알림에는 사건 번호가 없어 사건 키는 「사용자 ID, 사유, 수신 분」이다.

검증을 통과하면 사건 키로 한 번만 저장하고(같은 알림이 다시 오면 저장하지 않고 같은 응답) 처리 작업 `ProcessWebhookEventJob` 을 큐에 넣는다. 연결 해제와 회원 탈퇴는 큐 작업이 맡는다: 연결된 계정이 없으면 `ignored`(`not_linked`), 있으면 연결을 해제하고 플러그인 설정 `webhook_unlink_policy` 가 `withdraw_user` 이면 회원을 탈퇴시킨다(탈퇴가 거절되면 `failed`). 큐에 넣지 못해도 제공자에게는 받았다고 답하고 행은 `received` 로 남는다. 제공자가 꺼져 있어도 수신은 한다.

검증에 실패한 수신도 웹훅 수신 이력에 `status: ignored` 행으로 남고 `error` 에 사유가 실린다(관리자 「웹훅 수신 이력」과 진단 화면에서 본다). 행의 본문은 발신 IP 만 담는다.


### POST /plugins/sirsoft-social_login/webhooks/naver
<!-- @generated:start:web.plugins.sirsoft-social_login.webhooks.naver -->
- **라우트명**: `web.plugins.sirsoft-social_login.webhooks.naver`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\WebhookController@naver`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| clientId | body | string | 아니오 | max 255 | 네이버 애플리케이션 Client ID. 제공자 설정 `client_id` 와 같아야 한다 |
| encryptUniqueId | body | string | 아니오 | max 1024 | 연동 해제한 회원의 네이버 고유 ID 암호문. 앞 16바이트가 IV 인 AES-128-CBC(키는 Client Secret 의 MD5)를 base64(url) 로 실은 값이며 서버가 복호화한다 |
| timestamp | body | string | 아니오 | max 20 | 알림 생성 시각. 서명 원문과 사건 키에 들어간다 |
| signature | body | string | 아니오 | max 255 | 서명 |

**요청 예시**

```http
POST /plugins/sirsoft-social_login/webhooks/naver HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json

{
    "clientId": "예시값",
    "encryptUniqueId": "예시값",
    "timestamp": "예시값",
    "signature": "예시값"
}
```

**응답 필드** (`data` 내부)

_JSON 이 아니다: 빈 본문 204._

네이버는 알림을 다시 보내지 않으므로 검증과 저장은 요청 안에서 끝내고, 검증에 실패해도 입력 형식이 틀려도 언제나 204 로 답한다. 거절 여부는 수신 이력의 `status: ignored` 로 남는다.

**응답 예시**

```http
HTTP/1.1 204 No Content
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | (반환하지 않음) | 입력 검증 실패(`clientId` 255자, `encryptUniqueId` 1024자, `timestamp` 20자, `signature` 255자 초과 등)도 204 로 답하고 수신 이력에 `ignored`(`webhook_payload_invalid`)로 남긴다 |
| 429 | Too Many Requests | `throttle:120,1,sirsoft-social_login.webhooks` 초과 |

<!-- @generated:end -->

**설명**

네이버 개발자 센터의 「연동 해제 알림」 주소다(관리자 제공자 설정 응답의 `webhook_urls.unlink`). 회원이 네이버에서 이 앱과의 연동을 끊으면 네이버 서버가 보낸다.

검증은 네이버 드라이버가 순서대로 한다.

1. 제공자 설정의 Client ID 와 Client Secret 이 있고 `clientId` 가 Client ID 와 같아야 한다. 아니면 `webhook_app_mismatch`.
2. `signature` 가 `clientId={clientId}&encryptUniqueId={encryptUniqueId}&timestamp={timestamp}` 의 HMAC-SHA256(키 Client Secret) base64 값과 같아야 한다(base64url 표기와 끝의 `=` 는 가리지 않는다). 아니면 `webhook_signature_invalid`.
3. `encryptUniqueId` 를 복호화하지 못하면 `webhook_payload_invalid`.

사건 종류는 언제나 `unlink` 이고 사건 키는 「고유 ID 해시, `timestamp`」다.

검증을 통과하면 사건 키로 한 번만 저장하고(같은 알림이 다시 오면 저장하지 않고 같은 응답) 처리 작업 `ProcessWebhookEventJob` 을 큐에 넣는다. 연결 해제와 회원 탈퇴는 큐 작업이 맡는다: 연결된 계정이 없으면 `ignored`(`not_linked`), 있으면 연결을 해제하고 플러그인 설정 `webhook_unlink_policy` 가 `withdraw_user` 이면 회원을 탈퇴시킨다(탈퇴가 거절되면 `failed`). 큐에 넣지 못해도 제공자에게는 받았다고 답하고 행은 `received` 로 남는다. 제공자가 꺼져 있어도 수신은 한다.

검증에 실패한 수신도 웹훅 수신 이력에 `status: ignored` 행으로 남고 `error` 에 사유가 실린다(관리자 「웹훅 수신 이력」과 진단 화면에서 본다). 행의 본문은 발신 IP 만 담는다.

