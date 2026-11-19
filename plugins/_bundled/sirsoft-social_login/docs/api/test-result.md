# Test Result API 레퍼런스

> **소유**: plugin `sirsoft-social_login` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Test Result 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /plugins/sirsoft-social_login/test-result/{code}
<!-- @generated:start:web.plugins.sirsoft-social_login.test-result -->
- **라우트명**: `web.plugins.sirsoft-social_login.test-result`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\OAuthController@testResult`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| code | path | string | 예 | 영숫자 40자 | 연결 테스트 왕복이 끝난 뒤 콜백이 발급한 일회용 결과 코드. 이 주소가 곧 열람 권한이다 |

**요청 예시**

```http
GET /plugins/sirsoft-social_login/test-result/{code} HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

JSON 이 아니라 HTML 화면(`text/html`)이다. `data` 봉투는 없다. 응답 헤더는 `Cache-Control: no-store, private`, `X-Robots-Tag: noindex, nofollow` 다.

| 화면 항목 | 나오는 조건 | 내용 |
| --- | --- | --- |
| 결과 문구 | 늘 | 성공이면 「연결에 성공했습니다. 제공자가 아래 정보를 보냈습니다.」, 실패면 「연결에 실패했습니다. 오류 코드: {code}」 |
| 제공자 | 늘 | 제공자 키 |
| 제공자 사용자 ID | 성공 | 앞 2자와 뒤 2자만 보이게 가린 값(4자 이하는 전부 가림) |
| 이메일 | 성공 | 로컬 부분 앞 2자만 보이게 가린 주소 |
| 이메일 검증 | 성공 | 「검증됨」, 「검증 안 됨」, 「제공자가 알려 주지 않음」 |
| 이름, 별명 | 성공 | 제공자가 보낸 값 |
| 받은 항목 | 성공 | 제공자가 값을 보낸 항목 이름(값은 싣지 않는다) |
| 가입에 빠진 항목 | 성공 | 가입에 필요한데 오지 않은 항목, 없으면 「없음」 |
| 제공자 원본 키 | 성공 | 제공자 원본 응답의 키 이름 |
| 테스트 시각 | 늘 | 보는 사람 시간대 `YYYY-MM-DD HH:mm:ss` |

**응답 예시**

```http
HTTP/1.1 200
Content-Type: text/html; charset=UTF-8
Cache-Control: no-store, private
X-Robots-Tag: noindex, nofollow
```

```html
<!doctype html>
<html lang="ko">
<head><meta charset="utf-8">…<title>소셜 로그인 연결 테스트 결과</title><style>…</style></head>
<body><main><h1>소셜 로그인 연결 테스트 결과</h1>
<p class="ok">연결에 성공했습니다. 제공자가 아래 정보를 보냈습니다.</p>
<dl>
  <dt>제공자</dt><dd>kakao</dd>
  <dt>제공자 사용자 ID</dt><dd>12******89</dd>
  <dt>이메일</dt><dd>us**@example.com</dd>
  …
</dl>
<p class="note">테스트는 로그인하지 않습니다. 이 창을 닫아도 됩니다.</p>
</main></body>
</html>
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 결과가 없거나 이미 열어 본 코드, 보관 시간(60초)이 지난 코드인 경우. HTML 화면으로 「결과가 없거나 이미 확인했습니다. 결과는 한 번만 볼 수 있습니다. 관리자 화면에서 테스트를 다시 시작해 주세요.」를 보인다 |

<!-- @generated:end -->

**설명**

관리자 [브라우저로 로그인 테스트](`POST /api/plugins/sirsoft-social_login/admin/providers/{provider}/test-login`, [providers.md](providers.md)) 왕복의 끝 화면이다. 콜백은 테스트 왕복이면 로그인하지 않고 결과를 일회용 코드로 캐시에 넣은 뒤 이 주소로 보낸다.

- 결과는 한 번만 볼 수 있다. 열면 곧바로 지워지고, 열지 않아도 60초 뒤 사라진다. 주소 자체가 열람 권한이라 인증을 요구하지 않는 대신 짧게 둔다.
- 결과에는 토큰을 싣지 않고, 사용자 ID 와 이메일은 가린 값만 담는다.
- 경로 값이 영숫자 40자가 아니면 라우트 조건에 걸려 이 화면이 아니라 일반 404 가 난다.

