# Identity API 레퍼런스

> **소유**: plugin `sirsoft-verification_kginicis` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Identity 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
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



<!-- 실측 응답에 필드 없음(빈 목록 등) — 데이터가 있는 상태로 재실측하거나 사람이 작성. -->

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

  이 외에 `BaseApiResource` 공통 메타 `is_owner`(항상 본인이므로 `true`) + `abilities` 가 함께 붙는다.
- **응답 예시 주의**: 실측 시 샘플 사용자에게 본인인증 record 가 없어 `data: null`(record 없음) 응답만 관측된다. 아래 두 응답 예시 중 "record 존재 시" 는 `InicisIdentityResource` 구조 기준 정적 작성이다. 실제 record 가 있는 사용자로 실측하면 마스킹된 본인확인 정보가 채워진다.

**응답 예시** (record 존재 시 — 정적, `InicisIdentityResource` 구조 기준)

```json
{
  "success": true,
  "data": {
    "method": "KG이니시스 본인확인",
    "verified_at": "2026-07-01 14:22:10",
    "name_masked": "홍**",
    "birthday_masked": "1990-**-**",
    "phone_masked": "010-****-5678",
    "is_adult": true,
    "is_foreigner": false,
    "is_owner": true,
    "abilities": {}
  },
  "message": "성공적으로 처리되었습니다.",
  "error": null
}
```

**응답 예시** (본인인증 이력 없음 — record 없음)

```json
{
  "success": true,
  "data": null,
  "message": "성공적으로 처리되었습니다.",
  "error": null
}
```


