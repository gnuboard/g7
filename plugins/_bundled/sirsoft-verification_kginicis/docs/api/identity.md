# Identity API 레퍼런스

> **소유**: plugin `sirsoft-verification_kginicis` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Identity 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-verification_kginicis/me/identity/inicis
<!-- @generated:start:api.plugins.sirsoft-verification_kginicis.me.identity.inicis.show -->
- **라우트명**: `api.plugins.sirsoft-verification_kginicis.me.identity.inicis.show`
- **컨트롤러**: `\Plugins\Sirsoft\VerificationKginicis\Http\Controllers\MyInicisIdentityShowController@show`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-verification_kginicis/me/identity/inicis HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드. 본인확인 record 가 없는 사용자는 `data` 가 `null` 이다(컨트롤러가 `ResponseHelper::success('messages.success', null)` 로 분기)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| method | string | `"KG이니시스 본인확인"` | 본인확인 수단 표시 라벨 (Resource 고정 문자열) |
| verified_at | string\|null | `"2026-07-01 14:22:10"` | 최종 본인확인 시각 (`Y-m-d H:i:s`). 재인증 이력이 있으면 `re_verified_at`, 없으면 최초 `verified_at` |
| name_masked | string | `"홍**"` | 마스킹된 실명 (첫 글자 + 나머지 `*`) |
| birthday_masked | string | `"1990-**-**"` | 마스킹된 생년월일 (연도 4자리만 노출) |
| phone_masked | string | `"010-****-5678"` | 마스킹된 휴대폰 번호 (앞 3자리 + 뒤 4자리) |
| is_adult | boolean | `true` | 성인 여부 (생년월일 기반 만 19세 이상 자동 계산값) |
| is_foreigner | boolean | `false` | 외국인 여부 (이니시스 `isForeign`) |

`di`/`ci`/`ci2` 등 동일인 식별값과 평문 PII 는 응답에 포함하지 않는다. `InicisIdentityResource` 는 `resourceMeta()` 를 spread 하지 않으므로 `is_owner`/`abilities` 메타도 포함되지 않는다.

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명**

로그인한 사용자가 마이페이지 본인인증 카드에서 자신의 KG이니시스 본인확인 정보(마스킹)를 조회하는 엔드포인트다. `AuthBaseController` 를 상속하므로 `auth:sanctum` 인증이 필수이며, 라우트에 `check.user_status:active` 미들웨어가 걸려 **활성 상태 사용자만** 호출할 수 있다(정지/탈퇴 대기 사용자는 차단). 조회 전용이며 부수 효과는 없다.

- **요청 파라미터 없음**: 대상은 항상 인증된 본인(`Auth::id()`)이며, 다른 사용자의 정보를 조회할 수 없다. 컨트롤러가 `InicisIdentityCardService::findForUser(Auth::id())` 로 본인 record 만 조회한다.
- **미인증 사용자(record 없음)**: 아직 본인인증을 하지 않은 사용자는 `data: null` 로 응답한다(코어 표준 null 처리 — HTTP 200, `success: true`). 프론트는 `data` 가 `null` 인 경우 「본인인증 하기」 유도 UI 를, 값이 있으면 본인확인 카드를 렌더한다.
- **PII 마스킹**: 평문 개인정보는 서버에서 마스킹 후 노출한다(PIPC 사용자 본인 PII 열람권 충족). `di`/`ci` 등 식별값은 **일체 노출하지 않는다**. 이름은 첫 글자만(`홍**`), 생년월일은 연도만(`1990-**-**`), 휴대폰은 앞 3자리+뒤 4자리(`010-****-5678`)로 마스킹된다.
- **응답 필드** (`data` 내부, record 존재 시 — `InicisIdentityResource`):

  | 필드 | 타입 | 예시값 | 용도/설명 |
  | --- | --- | --- | --- |
  | method | string | `"KG이니시스 본인확인"` | 본인확인 수단 표시 라벨(고정 문자열). |
  | verified_at | string\|null | `"2026-07-01 14:22:10"` | 최종 본인확인 시각(`Y-m-d H:i:s`). 재인증이 있었으면 `re_verified_at`, 없으면 최초 `verified_at`. |
  | name_masked | string | `"홍**"` | 마스킹된 실명(첫 글자 + 나머지 `*`). |
  | birthday_masked | string | `"1990-**-**"` | 마스킹된 생년월일(연도만 노출). |
  | phone_masked | string | `"010-****-5678"` | 마스킹된 휴대폰 번호(앞 3 + 뒤 4자리). |
  | is_adult | boolean | `true` | 성인 여부(연령 게이팅 판정에 사용). |
  | is_foreigner | boolean | `false` | 외국인 여부. |

  `InicisIdentityResource` 는 `BaseApiResource` 를 상속하지만 `resourceMeta()` 를 spread 하지 않으므로 `is_owner`/`abilities` 메타 필드는 응답에 포함되지 않는다(대상이 항상 본인이며 조회 전용이라 불필요).
- **응답 예시 주의**: 실측 시 샘플 사용자에게 본인인증 record 가 없어 `data: null`(record 없음) 응답만 관측된다. 아래 두 응답 예시 중 "record 존재 시" 는 `InicisIdentityResource` 구조 기준 정적 작성이다. 실제 record 가 있는 사용자로 실측하면 마스킹된 본인확인 정보가 채워진다.

**응답 예시** (record 존재 시 — 정적, `InicisIdentityResource` 구조 기준)

```json
{
  "success": true,
  "message": "성공적으로 처리되었습니다.",
  "data": {
    "method": "KG이니시스 본인확인",
    "verified_at": "2026-07-01 14:22:10",
    "name_masked": "홍**",
    "birthday_masked": "1990-**-**",
    "phone_masked": "010-****-5678",
    "is_adult": true,
    "is_foreigner": false
  }
}
```

**응답 예시** (본인인증 이력 없음 — record 없음)

```json
{
  "success": true,
  "message": "성공적으로 처리되었습니다.",
  "data": null
}
```



---

## PG 연동 web 엔드포인트 (docgen 수집 범위 밖)

아래 두 경로는 `src/routes/web.php` 에 선언된 브라우저 왕복 경로라 `api:docgen` 의 라우트 수집
대상(`/api/plugins/...`)에 포함되지 않는다. JSON API 가 아니므로 응답 필드 표 대신 요청 계약과
분기 동작만 기재한다.

### POST /plugin/inicis/callback

- **라우트명**: `plugin.verification_kginicis.callback`
- **호출자**: KG이니시스 (매뉴얼 STEP2 — 외부 form POST)
- **미들웨어**: `web` (CSRF 검증은 `ValidateCsrfToken` 제외 — 외부 호출이라 토큰이 없다)
- **FormRequest**: `InicisCallbackRequest` — **검증 규칙 없음(의도된 결정)**

이니시스는 매뉴얼에 명시되지 않은 필드를 가맹점 설정·인증 수단에 따라 함께 보낸다. 요청
파라미터를 화이트리스트로 닫으면 정상 콜백이 422 로 거부되어 인증이 끊기므로, 이 엔드포인트는
본문을 그대로 통과시키고 의미 검증(서명 대조·거래 매칭·challenge 해석)은 전적으로
`InicisCallbackResolver` 가 담당한다. 빈 규칙의 FormRequest 를 두는 이유는 "검증하지 않는다" 가
누락이 아니라 결정임을 코드에 남기기 위해서다.

**응답**: 항상 `302` — `/plugin/inicis/popup-bridge` 로 결과 query 를 붙여 redirect 한다.

### GET /plugin/inicis/popup-bridge

- **라우트명**: `plugin.verification_kginicis.popup-bridge`
- **호출자**: 사용자 브라우저 (callback 이 redirect)
- **FormRequest**: `InicisPopupBridgeRequest`

**요청 파라미터**

| 이름 | 타입 | 필수 | 설명 |
| --- | --- | --- | --- |
| verification_token | string | 아니오 | 인증 성공 시 코어가 발급한 검증 토큰 |
| challenge_id | string | 아니오 | 인증 시도 식별자 |
| identity_error | string | 아니오 | 인증 실패 사유 키 |

길이 상한을 두지 않는다 — 토큰 길이는 코어 발급 정책에 종속되므로, 여기서 상한을 정하면 정책이
바뀔 때 사용자가 팝업 안에서 오류 화면을 보게 된다.

**응답**: `200 text/html` — 데스크톱(`window.opener` 존재)은 부모창에 `postMessage` 후 창을 닫고,
모바일은 `sessionStorage` 의 redirectStash 를 복원해 원래 페이지로 이동한다.
