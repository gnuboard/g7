# Identity API 레퍼런스

> **소유**: plugin `sirsoft-verification_nhnkcp` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 마이페이지 본인확인 카드 조회 API 1개 + 인증기관 콜백/결과 전달용 웹 엔드포인트 2개
2. 카드 API 는 로그인 사용자 본인의 정보만, 그것도 마스킹된 형태로만 반환한다
3. CI/DI 등 동일인 식별값은 어떤 응답에도 포함되지 않는다
4. 콜백/브리지는 브라우저·인증기관이 호출하는 화면 흐름용이라 JSON API 가 아니다
5. 본인확인 시작(challenge)·검증은 코어 IDV API(/api/identity/*)를 그대로 사용한다
```

---


### GET /api/plugins/sirsoft-verification_nhnkcp/me/identity/nhnkcp
<!-- @generated:start:api.plugins.sirsoft-verification_nhnkcp.me.identity.nhnkcp.show -->
- **라우트명**: `api.plugins.sirsoft-verification_nhnkcp.me.identity.nhnkcp.show`
- **컨트롤러**: `Plugins\Sirsoft\VerificationNhnkcp\Http\Controllers\MyKcpIdentityShowController@show`
- **인증/권한**: `auth:sanctum` + `check.user_status:active` (로그인 + 활성 상태 필수)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-verification_nhnkcp/me/identity/nhnkcp HTTP/1.1
Host: g7_3.dev
Accept: application/json
Authorization: Bearer {token}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시 | 설명 |
| --- | --- | --- | --- |
| `method` | string | `NHN KCP 휴대폰 본인확인` | 인증 수단 표시명 (요청 로케일로 번역) |
| `verified_at` | string\|null | `2026-07-28 14:32:09` | 최종 인증 시각. 재인증 시 그 시각으로 갱신 |
| `name_masked` | string | `홍*동` | 실명 마스킹 (성과 끝 글자만 노출). 두 글자 이름은 `김*`, 한 글자 이름은 가릴 자리가 없어 원문 그대로 |
| `birthday_masked` | string | `1990-**-**` | 생년월일 마스킹 (연도만 노출). 생년월일 미수신 시 빈 문자열 |
| `phone_masked` | string | `010-****-5678` | 휴대폰 마스킹 (앞 3자리 + 뒤 4자리) |
| `is_adult` | boolean | `true` | 생년월일 기준 만 19세 이상 여부 |
| `is_adult_known` | boolean | `true` | 성인 여부를 판정할 수 있었는지. `false` 면 `is_adult` 는 "미성년"이 아니라 "확인 불가"를 뜻한다 |
| `is_foreigner` | boolean | `false` | 외국인 여부 (KCP `local_code` 기준) |

인증 내역이 없으면 `data` 는 `null` 이다 (화면은 빈 상태 카드를 표시).
CI/DI 및 그 해시, 평문 이름·휴대폰·생년월일은 응답에 **포함되지 않는다**.

`is_adult_known` 이 `false` 인 경우는 인증기관 응답에 생년월일이 없었던 때다. 이때 화면은
생년월일을 「제공되지 않음」, 성인 여부를 「확인 불가」로 표시해야 하며, 성인 확인이 필요한
기능은 계속 차단된다(`is_adult` 는 `false` 로 내려간다).

**응답 예시**

```json
{
  "success": true,
  "message": "요청이 성공적으로 처리되었습니다.",
  "data": {
    "method": "NHN KCP 휴대폰 본인확인",
    "verified_at": "2026-07-28 14:32:09",
    "name_masked": "홍*동",
    "birthday_masked": "1990-**-**",
    "phone_masked": "010-****-5678",
    "is_adult": true,
    "is_adult_known": true,
    "is_foreigner": false
  }
}
```

생년월일을 받지 못한 경우:

```json
{
  "success": true,
  "message": "요청이 성공적으로 처리되었습니다.",
  "data": {
    "method": "NHN KCP 휴대폰 본인확인",
    "verified_at": "2026-07-29 01:45:36",
    "name_masked": "홍*동",
    "birthday_masked": "",
    "phone_masked": "010-****-5678",
    "is_adult": false,
    "is_adult_known": false,
    "is_foreigner": false
  }
}
```

인증 내역이 없는 경우:

```json
{
  "success": true,
  "message": "요청이 성공적으로 처리되었습니다.",
  "data": null
}
```

**에러 응답**

| 상태 | 조건 | 비고 |
| --- | --- | --- |
| 401 | 비로그인 요청 | 코어 인증 미들웨어가 차단 |
| 403 | 정지·탈퇴 등 비활성 사용자 | `check.user_status:active` |

<!-- @generated:end -->

**설명**

마이페이지 본인확인 카드가 사용하는 조회 API 다. 사용자가 자기 본인확인 정보를 확인할 수 있게 하되
(개인정보 열람권), 화면에 필요한 최소 정보만 마스킹해 내려준다. 다른 사용자의 정보를 조회할 수 있는
경로는 없다 — 대상은 항상 요청자 본인이다.

---

## 인증 흐름용 웹 엔드포인트

아래 두 경로는 JSON API 가 아니라 브라우저·인증기관이 직접 호출하는 화면 흐름의 일부다.
클라이언트가 임의로 호출하는 용도가 아니므로 요청 규격은 KCP 표준창 규약을 따른다.

### POST /plugins/sirsoft-verification_nhnkcp/plugin/nhnkcp/callback

- **라우트명**: `web.plugins.sirsoft-verification_nhnkcp.plugin.verification_nhnkcp.callback`
- **호출 주체**: KCP 표준창 (거래등록 시 지정한 `Ret_URL`)
- **인증/권한**: 없음. 외부 form POST 이므로 CSRF 검사에서 면제된다.

| 파라미터 | 타입 | 설명 |
| --- | --- | --- |
| `res_cd` | string | 표준창 결과 코드. `0000` 정상 / `9999` 사용자 취소 / 그 외 실패 |
| `res_msg` | string | 결과 메시지 (URL 인코딩되어 올 수 있어 서버가 디코딩) |
| `reg_cert_key` | string | 거래등록 시 발급된 거래키. 거래 역조회 1순위 |
| `param_opt_1` | string | 거래등록 시 우리가 실어 보낸 challenge 식별자. 거래키 부재 시 역조회 폴백 |

응답은 항상 302 redirect 이며, 결과는 아래 브리지 경로로 전달된다. 개인정보는 주소에 싣지 않는다
(성공 시 `verification_token` + `challenge_id`, 실패 시 `identity_error` 만 전달).

### GET /plugins/sirsoft-verification_nhnkcp/plugin/nhnkcp/bridge

- **라우트명**: `web.plugins.sirsoft-verification_nhnkcp.plugin.verification_nhnkcp.bridge`
- **호출 주체**: 콜백 처리 후의 브라우저 (표준창 팝업 또는 전환된 페이지)
- **인증/권한**: 없음 (전달값만 사용)

| 파라미터 | 타입 | 설명 |
| --- | --- | --- |
| `verification_token` | string | 인증 성공 시 발급된 1회용 토큰 |
| `challenge_id` | string | 대응하는 본인확인 요청 식별자 |
| `identity_error` | string | 실패 시 사유 코드 (`9999`, `NOT_ADULT`, `DUPLICATE`, `REMOTE_CALL_FAILED` 등) |
| `identity_error_message` | string | 실패 시 사유 메시지 (콜백 Resolver 가 발신 — kginicis 브리지에는 없는 본 플러그인 고유 필드) |

네 쿼리 파라미터는 모두 `KcpBridgeRequest` 가 string 형식으로 검증한다 — 배열 주입
(`?verification_token[]=x`)은 422 로 차단된다. 종전에는 `(string)` 캐스팅이 배열을 `"Array"`
문자열로 바꿔 브리지 payload 에 유입시킬 수 있었다. 길이 상한은 두지 않는다 (토큰 길이가
코어 발급 정책에 종속되기 때문).

응답은 HTML 이며 두 갈래로 동작한다. 부모창이 있으면(PC 팝업) 동일 origin 으로 결과를 전달하고 창을
닫는다. 부모창이 없으면(모바일 페이지 전환) 보관해 둔 원래 주소로 결과를 붙여 되돌아간다.
