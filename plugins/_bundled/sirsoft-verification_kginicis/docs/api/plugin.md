# Plugin API 레퍼런스

> **소유**: plugin `sirsoft-verification_kginicis` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Plugin 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /plugins/sirsoft-verification_kginicis/plugin/inicis/callback
<!-- @generated:start:web.plugins.sirsoft-verification_kginicis.plugin.verification_kginicis.callback -->
- **라우트명**: `web.plugins.sirsoft-verification_kginicis.plugin.verification_kginicis.callback`
- **컨트롤러**: `Plugins\Sirsoft\VerificationKginicis\Http\Controllers\InicisCallbackController@handle`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /plugins/sirsoft-verification_kginicis/plugin/inicis/callback HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON 봉투를 반환하지 않습니다. 이니시스 STEP2 콜백을 처리한 뒤 `302 Found` 로 popup-bridge 페이지(`GET /plugins/sirsoft-verification_kginicis/plugin/inicis/popup-bridge`)로 redirect 하며, 처리 결과는 아래 query string 으로 전달됩니다 (`InicisCallbackOutcome::toBridgeQuery()`)._

| 필드 (query) | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| verification_token | string | `9f1c...` (1회용 토큰) | 성공 시에만 부착. 코어 verify 결과 claims 의 `verification_token` — 후속 폼 제출에 첨부해 본인인증 완료를 증명 |
| challenge_id | string (uuid) | `1f2a3b4c-...` | 성공 시에만 부착. mTxId 로 매칭된 본인인증 challenge UUID |
| identity_error | string | `NOT_FOUND` | 실패 시에만 부착. 실패 코드 (아래 에러 응답 표 참조) |

**응답 예시**

```http
HTTP/1.1 302 Found
Location: https://api.example.com/plugins/sirsoft-verification_kginicis/plugin/inicis/popup-bridge?verification_token=9f1c8a2e6b5d4c3f&challenge_id=1f2a3b4c-5d6e-7f80-9a1b-2c3d4e5f6a7b
```

실패 시:

```http
HTTP/1.1 302 Found
Location: https://api.example.com/plugins/sirsoft-verification_kginicis/plugin/inicis/popup-bridge?identity_error=NOT_FOUND
```

**에러 응답**

이 엔드포인트는 HTTP 에러 상태코드를 반환하지 않고, 항상 `302` 로 redirect 하면서 `identity_error` query 에 실패 코드를 실어 보냅니다 (`InicisCallbackResolver::resolve()`).

| identity_error | 발생 조건 |
| --- | --- |
| 이니시스 `resultCode` 원문 (예: `9999`) | 콜백의 `resultCode` 가 `0000` 이 아닌 경우 — 이니시스가 보고한 실패 코드를 그대로 전달 |
| `PROVIDER_ERROR` | `resultCode` 가 `0000` 이 아니고 값도 비어 있는 경우 |
| `INVALID_AUTH_URL` | `authRequestUrl` 이 허용된 이니시스 도메인이 아닌 경우 (위조 도메인 차단) |
| `REMOTE_CALL_FAILED` | STEP3(`authRequestUrl`) 호출이 통신 오류/비정상 응답으로 실패한 경우 |
| `DECRYPT_FAILED` | STEP3 응답의 SEED 암호화 필드 복호화에 실패한 경우 |
| `NOT_FOUND` | STEP3 응답의 `mTxId` 로 challenge 매핑을 찾지 못한 경우 |
| `ALREADY_CONSUMED` | 해당 challenge 가 이미 소비(사용)된 경우 |
| 코어 verify 실패 코드 / `UNKNOWN` | 코어 `IdentityVerificationService::handleProviderCallback()` 이 실패를 반환한 경우 (`failureCode` 그대로, 없으면 `UNKNOWN`) |

<!-- @generated:end -->

**설명**

이니시스 본인인증 매뉴얼 STEP2 콜백 수신 엔드포인트입니다. 이니시스 인증창이 인증을 마치면 이 URL 로 form POST 를 보내며(`resultCode`, `authRequestUrl`, `txId`, `token` 등), 컨트롤러는 이를 `InicisCallbackResolver` 에 그대로 위임합니다. Resolver 는 STEP3(`authRequestUrl`) 를 호출해 인증 결과를 받아 SEED 복호화하고, 응답의 `mTxId` 로 challenge 를 매칭한 뒤 코어 IDV Service 에 verify 를 위임합니다.

주의사항:

- 외부 PG 가 호출하므로 CSRF 검증이 면제되어 있고, 인증(로그인)도 필요하지 않습니다.
- 이니시스가 challenge_id 를 콜백에 echo 하지 않기 때문에 코어 표준 콜백 라우트(`POST /api/identity/callback/{providerId}`)를 사용하지 않고 이 전용 라우트를 둡니다. challenge 매칭은 `mTxId` ↔ 매핑 테이블로 수행합니다.
- 외부 PG 가 보내는 임의 필드를 차단하지 않기 위해 FormRequest 검증을 두지 않습니다 (raw form POST 를 그대로 전달).
- 사용자 브라우저가 직접 따라가는 redirect 이므로 응답은 항상 302 이며, 실패도 HTTP 에러가 아닌 `identity_error` query 로 표현됩니다.


### GET /plugins/sirsoft-verification_kginicis/plugin/inicis/popup-bridge
<!-- @generated:start:web.plugins.sirsoft-verification_kginicis.plugin.verification_kginicis.popup-bridge -->
- **라우트명**: `web.plugins.sirsoft-verification_kginicis.plugin.verification_kginicis.popup-bridge`
- **컨트롤러**: `Plugins\Sirsoft\VerificationKginicis\Http\Controllers\InicisPopupBridgeController@show`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /plugins/sirsoft-verification_kginicis/plugin/inicis/popup-bridge HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON 봉투(`data`)를 반환하지 않습니다. `200 OK` + `Content-Type: text/html; charset=UTF-8` 로 브라우저에서 실행되는 bridge HTML 페이지를 반환합니다. 페이지 스크립트에 주입되는 payload 필드는 다음과 같습니다._

| 필드 (script payload) | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| verification_token | string | `9f1c8a2e6b5d4c3f` | 성공 토큰. 값이 없으면 빈 문자열(`""`) |
| challenge_id | string (uuid) | `1f2a3b4c-5d6e-7f80-9a1b-2c3d4e5f6a7b` | challenge UUID. 값이 없으면 빈 문자열(`""`) |
| identity_error | string | `NOT_FOUND` | 실패 코드. 값이 없으면 빈 문자열(`""`) |

**응답 예시**

```http
HTTP/1.1 200 OK
Content-Type: text/html; charset=UTF-8
```

```html
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>본인인증 결과</title>
</head>
<body>
<script>
(function () {
    var REDIRECT_STASH_KEY = 'g7.identity.redirectStash';
    var payload = {"verification_token":"9f1c8a2e6b5d4c3f","challenge_id":"1f2a3b4c-5d6e-7f80-9a1b-2c3d4e5f6a7b","identity_error":""};

    // 데스크톱: 부모창 postMessage({ type: 'identity_result', ... }) + window.close()
    // 모바일: sessionStorage 의 redirectStash 복원 후 return_url 로 이동
})();
</script>
</body>
</html>
```

**에러 응답**

_에러 응답 없음 — 인증/검증이 없고 query 파라미터가 모두 선택이므로, 어떤 요청에도 `200 OK` + bridge HTML 을 반환합니다. 본인인증 실패는 HTTP 에러가 아니라 `identity_error` query 로 전달되어 페이지 스크립트가 처리합니다._

<!-- @generated:end -->

**설명**

이니시스 본인인증 매뉴얼 STEP4 결과 전달 페이지입니다. callback 컨트롤러가 302 redirect 로 넘긴 query(`verification_token` / `challenge_id` / `identity_error`)를 받아, 사용자 브라우저 환경에 따라 두 가지로 분기하는 HTML 을 반환합니다.

- 데스크톱(팝업, `window.opener` 존재): 부모창에 `postMessage({ type: 'identity_result', verification_token, challenge_id, identity_error })` 를 same-origin 으로 전송한 뒤 팝업을 닫습니다.
- 모바일(`window.opener` 부재): `sessionStorage` 의 `g7.identity.redirectStash` 에 저장된 `return_url` 을 복원하고, 성공이면 `verification_token`, 실패면 `identity_error` 를 query 로 붙여 원래 페이지로 이동합니다. stash 가 없으면 `/` 로 이동합니다.

주의사항:

- 페이지에 주입되는 payload 는 `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT` 로 인코딩되어 script 컨텍스트에서 안전합니다.
- `sessionStorage` 키는 코어 IDV launcher 의 `IDENTITY_REDIRECT_STASH_KEY` 와 동일해야 복원이 동작합니다 (폼 값 복원인 formStash 처리는 launcher 책임).
- 이 URL 은 사용자가 직접 호출하는 API 가 아니라 callback 의 redirect 목적지입니다.


