# Links API 레퍼런스

> **소유**: plugin `sirsoft-sso` · **생성**: 손 작성(1.0.0, 코드 근거). @generated 블록은 다음 `php artisan api:docgen` 재생성 때 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 마이페이지에서 회사 계정 연결을 시작한다. 로그인 회원 본인만
2. 응답은 1회용 시작 주소(start_url = /plugins/sirsoft-sso/login/{키}?ticket={48자})다. 화면은 그 주소로 이동한다
3. 티켓은 60초, 한 번만, 이 응답을 받은 브라우저(묶음 쿠키 g7_sso_bind)에서만 쓸 수 있다
4. 연결이 끝나면 /mypage/profile?sso_linked={키}, 실패하면 /mypage/profile?sso_error={코드} 로 돌아온다
5. 응답은 미실측 — 실측 예시값 열은 —, 응답 예시는 코드에서 옮긴 모양(… 는 실제 값 자리)
```

---


### POST /api/plugins/sirsoft-sso/me/links/start
<!-- @generated:start:api.plugins.sirsoft-sso.me.links.start -->
- **라우트명**: `api.plugins.sirsoft-sso.me.links.start`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\MeController@start`
- **인증/권한**: `auth:sanctum`
- **요청 제한**: `throttle:60,1,sirsoft-sso.me`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | body | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | 연결할 IdP 키 |

**요청 예시**

```http
POST /api/plugins/sirsoft-sso/me/links/start HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "idp": "corp_oidc"
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| start_url | string | — | 1회용 시작 주소 `/plugins/sirsoft-sso/login/{키}?ticket={48자}`(같은 출처 경로) |

브라우저에 묶음 쿠키 `g7_sso_bind` 가 없으면 이 응답이 새로 굽는다(`Set-Cookie`, HttpOnly, 30분).

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "회사 계정 로그인으로 이동합니다.",
    "data": {
        "start_url": "/plugins/sirsoft-sso/login/corp_oidc?ticket={TICKET}"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 409 | Conflict | `errors.code` = `already_linked` — 이 IdP 에 이미 연결돼 있음 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우, 또는 `errors.code` = `idp_disabled`(없거나 꺼진 IdP, LDAP IdP) |
| 429 | Too Many Requests | 이 버킷(`sirsoft-sso.me`)에서 분당 60회를 넘은 경우 |

<!-- @generated:end -->

**설명**

브라우저 이동에는 Bearer 토큰이 실리지 않으므로, 회원을 아는 이 API 가 회원 ID 와 의도(`link`)를 담은 티켓을 발급하고 웹 라우트 `GET /plugins/sirsoft-sso/login/{idp}`(login.md)가 그 티켓으로 왕복을 시작한다. 연결 왕복이 끝나면 콜백이 연결(`via = verify` — 본인이 IdP 로그인으로 증명한 연결)을 만들고 `{사이트 주소}/mypage/profile?sso_linked={키}` 로 보낸다. 다른 회원에 이미 연결된 회사 계정이면 `/mypage/profile?sso_error=already_linked` 다. 티켓이 만료됐거나 다른 브라우저에서 쓰면 로그인 화면 `/login?sso_error=ticket_invalid` 로 간다.
