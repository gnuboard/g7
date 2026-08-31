# Plugin API 레퍼런스

> **소유**: plugin `sirsoft-verification_nhnkcp` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 KCP 본인확인 V2 흐름의 Plugin(web) 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 응답 필드 표 + 응답 예시
3. 두 라우트 모두 JSON 봉투를 반환하지 않습니다 — 콜백은 302, bridge 는 text/html 입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /plugins/sirsoft-verification_nhnkcp/plugin/nhnkcp/callback
<!-- @generated:start:web.plugins.sirsoft-verification_nhnkcp.plugin.verification_nhnkcp.callback -->
- **라우트명**: `web.plugins.sirsoft-verification_nhnkcp.plugin.verification_nhnkcp.callback`
- **컨트롤러**: `Plugins\Sirsoft\VerificationNhnkcp\Http\Controllers\KcpCallbackController@handle`
- **인증/권한**: 공개 (인증 불필요) · CSRF 면제

**요청 파라미터**

_요청 파라미터 없음 (외부 인증기관이 보내는 raw form POST 를 그대로 전달합니다)._

**요청 예시**

```http
POST /plugins/sirsoft-verification_nhnkcp/plugin/nhnkcp/callback HTTP/1.1
Host: api.example.com
Content-Type: application/x-www-form-urlencoded
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON 봉투를 반환하지 않습니다. KCP 표준창 콜백(흐름 ③)을 처리한 뒤 `302 Found` 로 bridge 페이지(`GET /plugins/sirsoft-verification_nhnkcp/plugin/nhnkcp/bridge`)로 redirect 하며, 처리 결과는 query string 으로 전달됩니다._

**응답 예시**

```http
HTTP/1.1 302 Found
Location: /plugins/sirsoft-verification_nhnkcp/plugin/nhnkcp/bridge?verification_token=9f1c8a2e6b5d4c3f
```

**에러 응답**

_HTTP 에러를 반환하지 않습니다. 사용자 브라우저가 직접 따라가는 redirect 이므로 실패도 302 이며, 사유는 `identity_error`·`identity_error_message` query 로 전달됩니다._

**설명**

KCP 본인확인 V2 흐름 ③ 콜백 수신 엔드포인트입니다. KCP 표준창이 인증을 마치면 가맹점 `Ret_URL` 인 이 주소로 form POST 를 보내며, 컨트롤러는 거래를 매칭해 ④ 결과조회를 호출하고 코어 IDV Service 에 verify 를 위임한 뒤 bridge 로 redirect 합니다.

주의사항:

- 외부 인증기관이 호출하므로 CSRF 검증이 면제되어 있고(`ValidateCsrfToken` 제외), 인증(로그인)도 필요하지 않습니다.
- 외부가 보내는 임의 필드를 차단하지 않기 위해 FormRequest 검증을 두지 않습니다.
- 형제 플러그인(`sirsoft-verification_kginicis`)과 같은 구조이며, 코어 표준 콜백 라우트를 쓰지 않고 전용 라우트를 두는 이유도 같습니다 — 인증기관이 challenge_id 를 콜백에 echo 하지 않기 때문입니다.

<!-- @generated:end -->


### GET /plugins/sirsoft-verification_nhnkcp/plugin/nhnkcp/bridge
<!-- @generated:start:web.plugins.sirsoft-verification_nhnkcp.plugin.verification_nhnkcp.bridge -->
- **라우트명**: `web.plugins.sirsoft-verification_nhnkcp.plugin.verification_nhnkcp.bridge`
- **컨트롤러**: `Plugins\Sirsoft\VerificationNhnkcp\Http\Controllers\KcpBridgeController@show`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_모두 선택입니다 — 콜백이 redirect 하며 실어 보냅니다._

| 파라미터 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| verification_token | string | 아니오 | 성공 토큰 |
| challenge_id | string (uuid) | 아니오 | challenge UUID |
| identity_error | string | 아니오 | 실패 코드 |
| identity_error_message | string | 아니오 | 실패 사유 문구 |

**요청 예시**

```http
GET /plugins/sirsoft-verification_nhnkcp/plugin/nhnkcp/bridge?verification_token=9f1c8a2e6b5d4c3f HTTP/1.1
Host: api.example.com
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
    //         복귀 정보가 없으면 **이 사이트의 루트**로 이동한다 (하위 폴더 배치 대응)
})();
</script>
</body>
</html>
```

**에러 응답**

_에러 응답 없음 — 인증/검증이 없고 query 파라미터가 모두 선택이므로, 어떤 요청에도 `200 OK` + bridge HTML 을 반환합니다. 본인인증 실패는 HTTP 에러가 아니라 `identity_error` query 로 전달되어 페이지 스크립트가 처리합니다._

**설명**

인증 결과를 원래 화면으로 되돌려 주는 중계 페이지입니다. 데스크톱(팝업)에서는 부모 창에 `postMessage` 로 결과를 전달하고 스스로 닫히며, 모바일(리다이렉트)에서는 `sessionStorage` 에 보관된 복귀 정보(`return_url`)를 읽어 원래 페이지로 이동합니다.

복귀 정보가 없을 때의 기본 목적지는 **이 사이트의 루트**입니다. 문서 루트가 프로젝트 루트인 하위 폴더 배치(`https://host/g7/`)에서 리터럴 `/` 로 이동하면 사이트 밖(다른 사이트 또는 404)으로 나가므로, 서버가 마운트 접두사를 계산해 페이지에 주입합니다.

<!-- @generated:end -->
