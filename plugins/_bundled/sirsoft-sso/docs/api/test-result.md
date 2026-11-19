# Test Result API 레퍼런스

> **소유**: plugin `sirsoft-sso` · **생성**: 손 작성(1.0.0, 코드 근거). @generated 블록은 다음 `php artisan api:docgen` 재생성 때 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 웹 라우트다(JSON API 아님). 관리자 「브라우저로 로그인 테스트」 결과를 단독 HTML 로 한 번 보여 준다
2. 결과 주소의 40자 코드가 곧 열람 권한이다. 60초 안에 한 번만 열리고 열면 지워진다(두 번째는 404)
3. 결과에는 가린 값만 싣는다(고유 식별자와 이메일은 가림, 토큰과 어설션 원문 없음)
```

---


### GET /plugins/sirsoft-sso/test-result/{code}
<!-- @generated:start:web.plugins.sirsoft-sso.test-result -->
- **라우트명**: `web.plugins.sirsoft-sso.test-result`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\LoginController@testResult`
- **인증/권한**: 공개 (인증 불필요) — 1회용 결과 코드
- **요청 제한**: `throttle:30,1,sirsoft-sso.test-result`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| code | path | string | 예 | 영숫자 40자 | 테스트 왕복이 끝날 때 발급한 결과 코드 |

**요청 예시**

```http
GET /plugins/sirsoft-sso/test-result/{code} HTTP/1.1
Host: api.example.com
Accept: text/html
```

**응답 필드** (`data` 내부)

이 엔드포인트는 JSON 을 반환하지 않는다. `text/html` 단독 화면(밝기 모드 대응)을 돌려준다. 헤더는 `Cache-Control: no-store`, `Referrer-Policy: no-referrer`.

화면에 보이는 항목:

| 항목 | 내용 |
| --- | --- |
| 결과 | 성공, 또는 실패와 오류 코드, 그 코드의 화면 문구 |
| IdP | IdP 이름(없으면 키) |
| 고유 식별자 | 앞뒤 2자만 남기고 가린 값(성공일 때) |
| 이메일 | 로컬 부분을 가린 값(`h***@example.com`, 성공일 때) |
| 이메일 확인 여부, 다중 인증 | 예, 아니오, 알 수 없음(성공일 때) |
| 이름, 그룹, 받은 속성 이름 | IdP 가 준 값(속성은 이름만, 성공일 때) |
| 테스트 시각 | ISO 8601 |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Content-Type: text/html; charset=utf-8
Cache-Control: no-store
Referrer-Policy: no-referrer
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 코드가 없거나, 60초가 지났거나, 이미 열어 본 경우 — 「결과가 없거나 이미 확인했습니다. 결과는 60초 동안 한 번만 볼 수 있습니다.」 HTML. 40자 영숫자가 아니면 라우트가 404 |
| 429 | Too Many Requests | 분당 30회를 넘은 경우 |

<!-- @generated:end -->

**설명**

관리자 IdP 편집 화면의 「브라우저로 로그인 테스트」(`POST /api/plugins/sirsoft-sso/admin/idps/{id}/test`, `browser: true`)가 연 새 창이 IdP 로그인을 마치면 콜백이 결과를 캐시에 60초 저장하고 이 주소로 보낸다. 테스트는 회원을 만들거나 연결하지 않는다. 시작이나 콜백에서 실패해도 같은 결과 화면으로 오고, 실패 코드는 README 「로그인 흐름 오류 코드」와 같다. 시도 기록에는 성공이면 결과 `test`, 실패면 `failed` 로 남는다.
