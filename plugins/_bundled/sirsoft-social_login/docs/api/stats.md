# Stats API 레퍼런스

> **소유**: plugin `sirsoft-social_login` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Stats 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-social_login/admin/stats
<!-- @generated:start:api.plugins.sirsoft-social_login.admin.stats.index -->
- **라우트명**: `api.plugins.sirsoft-social_login.admin.stats.index`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin\StatsController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-social_login.settings.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| days | query | integer | 아니오 | — | 집계 기간(일). `7`, `30`, `90` 가운데 하나. 생략하면 30. 그 밖의 값은 422 |

**요청 예시**

```http
GET /api/plugins/sirsoft-social_login/admin/stats?days=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| days | integer | `30` | 적용한 집계 기간(일) |
| since | string | `2026-10-02 00:00:00` | 집계 시작 경계. 사이트 시간대 기준 오늘에서 `days` 일 전 자정이며, 사이트 시간대 `YYYY-MM-DD HH:mm:ss` 문자열이다 |
| rows | array | `[{"provider_key":"kakao","label":"카카오","signups":2,"login…` | 제공자별 행. 제공자 표 행 순서 뒤에, 표에 없지만 기간 안 시도나 연결이 있는 키를 붙인다. `signups`(가입), `logins`(로그인 인계와 로그인 합), `links`(연결), `failures`(실패와 거부 합)는 기간 안 시도 기록에서 세고, `linked_accounts`(연결 수), `social_only_users`(이 제공자로만 로그인하는 회원 수)는 지금 값이다 |
| totals | object | `{"signups":16,"logins":27,"links":17,"failures":86,"linke…` | `rows` 의 열별 합계(`signups`, `logins`, `links`, `failures`, `linked_accounts`, `social_only_users`) |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "소셜 로그인 통계입니다.",
    "data": {
        "days": 30,
        "since": "2026-10-02 00:00:00",
        "rows": [
            {
                "provider_key": "kakao",
                "label": "카카오",
                "signups": 2,
                "logins": 1,
                "links": 1,
                "failures": 2,
                "linked_accounts": 1,
                "social_only_users": 1
            },
            {
                "provider_key": "naver",
                "label": "네이버",
                "signups": 0,
                "logins": 0,
                "links": 0,
                "failures": 0,
                "linked_accounts": 0,
                "social_only_users": 0
            },
            {
                "provider_key": "google",
                "label": "구글",
                "signups": 0,
                "logins": 0,
                "links": 0,
                "failures": 0,
                "linked_accounts": 0,
                "social_only_users": 0
            },
            {
                "provider_key": "facebook",
                "label": "페이스북",
                "signups": 0,
                "logins": 0,
                "links": 0,
                "failures": 13,
                "linked_accounts": 0,
                "social_only_users": 0
            },
            {
                "provider_key": "mock_kakao",
                "label": "mock_kakao",
                "signups": 13,
                "logins": 7,
                "links": 0,
                "failures": 37,
                "linked_accounts": 0,
                "social_only_users": 0
            },
            {
                "provider_key": "mock_naver",
                "label": "mock_naver",
                "signups": 1,
                "logins": 9,
                "links": 16,
                "failures": 16,
                "linked_accounts": 2,
                "social_only_users": 1
            },
            {
                "provider_key": "mock_google",
                "label": "mock_google",
                "signups": 0,
                "logins": 10,
                "links": 0,
                "failures": 18,
                "linked_accounts": 1,
                "social_only_users": 1
            }
        ],
        "totals": {
            "signups": 16,
            "logins": 27,
            "links": 17,
            "failures": 86,
            "linked_accounts": 4,
            "social_only_users": 3
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-social_login.settings.view`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

관리자 통계 화면이 기간 선택(7일, 30일, 90일)마다 부른다.

- 가입, 로그인, 연결, 실패는 기간 안 시도 기록에서 센다. 관리자 연결 테스트(`intent=test`) 시도는 세지 않는다.
- 로그인 수는 코어 로그인 인계로 넘긴 건수를 포함한다. 인계 뒤 2단계 인증에서 멈춘 회원도 들어간다.
- 시도 시각은 UTC 로 저장돼 있어 질의 경계만 사이트 자정을 UTC 로 옮겨 쓴다. 앱 시간대 자정으로 자르면 첫날 일부가 빠지거나 전날이 섞이기 때문이다.
- `label` 은 등록된 제공자면 표시 이름, 아니면 키 그대로다.

