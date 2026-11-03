# Data Deletion API 레퍼런스

> **소유**: plugin `sirsoft-social_login` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Data Deletion 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /plugins/sirsoft-social_login/data-deletion/{code}
<!-- @generated:start:web.plugins.sirsoft-social_login.data-deletion.status -->
- **라우트명**: `web.plugins.sirsoft-social_login.data-deletion.status`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\WebhookController@dataDeletionStatus`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| code | path | string | 예 | — | 데이터 삭제 확인 코드(20자 영숫자). 페이스북 데이터 삭제 웹훅 응답의 `confirmation_code`. 형식이 맞지 않으면 라우트가 맞지 않아 404 |

**요청 예시**

```http
GET /plugins/sirsoft-social_login/data-deletion/{code} HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_JSON 응답이 아니다. `text/html` 상태 화면이며 본문 필드가 없다. 화면에 상태 문구, 확인 코드, 요청 받은 시각, 처리한 시각(보는 사람 시간대, 처리 전이면 `-`), 탈퇴 안내가 나온다._

**응답 예시**

```http
HTTP/1.1 200 OK
Content-Type: text/html; charset=UTF-8
Cache-Control: no-store, private
```

상태 문구는 수신 이력의 처리 상태로 정해진다.

- `processed`, `ignored`: 이 사이트에 저장된 페이스북 연결 정보를 지웠습니다.
- `failed`: 처리하지 못했습니다. 사이트 관리자에게 문의해 주세요.
- 그 밖(`received`): 삭제 요청을 받았고 처리하고 있습니다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 확인 코드에 해당하는 데이터 삭제 요청이 없다(HTML 화면 「확인 코드에 해당하는 삭제 요청이 없습니다.」). 코드 형식이 20자 영숫자가 아니어도 404 |
| 429 | Too Many Requests | 요청 제한 초과 (`throttle:30,1,sirsoft-social_login.data-deletion`) |

<!-- @generated:end -->

**설명**

페이스북 데이터 삭제 요청 웹훅(`POST /plugins/sirsoft-social_login/webhooks/facebook/data-deletion`)이 응답 `url` 로 돌려준 상태 확인 주소다. 페이스북은 회원에게 이 주소와 확인 코드를 보여 주고, 회원은 로그인 없이 진행 상태를 확인한다.

확인 코드로 찾은 수신 이력이 데이터 삭제 종류일 때만 보여 준다. 이 화면은 페이스북 연결 정보 삭제의 진행만 알린다. 회원 계정 자체의 탈퇴는 사이트 정책을 따른다.

