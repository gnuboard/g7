# Attempts API 레퍼런스

> **소유**: plugin `sirsoft-social_login` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Attempts 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-social_login/admin/attempts
<!-- @generated:start:api.plugins.sirsoft-social_login.admin.attempts.index -->
- **라우트명**: `api.plugins.sirsoft-social_login.admin.attempts.index`
- **컨트롤러**: `Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin\DiagnosticsController@attempts`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-social_login.settings.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| provider | query | string | 아니오 | — | 제공자 키로 거르기(`^[a-z][a-z0-9_]{1,49}$`, 모의 제공자 `mock_*` 포함). 형식이 틀리면 422 |
| outcome | query | string | 아니오 | — | 결과로 거르기. `login`, `signup`, `link`, `pending`, `handoff`, `denied`, `failed` |
| intent | query | string | 아니오 | — | 의도로 거르기. `login`, `link`, `test`, `reauth` |
| error_code | query | string | 아니오 | max 50 | 오류 코드로 거르기(정확히 일치). 예 `token_exchange_failed`, `state_invalid` |
| status | query | string | 아니오 | — | 상태 필터 (해당 상태의 항목만 조회) |
| event_type | query | string | 아니오 | — | 이 목록에서는 쓰지 않는다(웹훅 수신 이력과 같은 검증 규칙을 나눠 써서 받기만 한다). 허용값 `unlink`, `account_delete`, `deauthorize`, `data_deletion`, `incomplete_signup` |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |

**요청 예시**

```http
GET /api/plugins/sirsoft-social_login/admin/attempts?provider=%EC%98%88%EC%8B%9C%EA%B0%92&outcome=%EC%98%88%EC%8B%9C%EA%B0%92&intent=%EC%98%88%EC%8B%9C%EA%B0%92&error_code=%EC%98%88%EC%8B%9C%EA%B0%92&status=%EC%98%88%EC%8B%9C%EA%B0%92&event_type=%EC%98%88%EC%8B%9C%EA%B0%92&page=1&per_page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `361` | 기본 키 (내부 식별자) |
| provider_key | string | `facebook` | 제공자 키(최대 50자). 모의 제공자는 `mock_` 접두 |
| user_id | integer | `5126` | user 식별자 (연관 리소스 참조) |
| intent | string | `link` | 시도의 의도. `login`(로그인과 가입), `link`(마이페이지 연결), `test`(관리자 연결 테스트), `reauth`(본인 재확인) |
| outcome | string | `failed` | 결과. `login` 로그인, `signup` 가입, `link` 연결, `pending` 가입 대기로 넘김, `handoff` 코어 로그인 인계로 넘김, `denied` 정책상 거절, `failed` 오류 |
| error_code | string | `revoke_failed` | 실패나 거절의 코드(실패 시, 최대 50자). 성공이면 `null` |
| error_message | string | `connection_failed` | 오류 요약(비밀 제외, 최대 500자). 없으면 `null` |
| ip | string | `127.0.0.1` | 시도한 요청의 IP |
| created_at | string | `2026-10-30 10:05:26` | 생성 일시 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "소셜 로그인 시도 기록입니다.",
    "data": {
        "data": [
            {
                "id": 361,
                "provider_key": "facebook",
                "user_id": 5126,
                "intent": "link",
                "outcome": "failed",
                "error_code": "revoke_failed",
                "error_message": null,
                "ip": "127.0.0.1",
                "created_at": "2026-10-30 10:05:26"
            },
            {
                "id": 357,
                "provider_key": "mock_google",
                "user_id": null,
                "intent": "login",
                "outcome": "failed",
                "error_code": "token_exchange_failed",
                "error_message": "connection_failed",
                "ip": "127.0.0.1",
                "created_at": "2026-10-29 12:34:35"
            },
            "... (총 25건 중 2건 표시)"
        ],
        "pagination": {
            "current_page": 1,
            "last_page": 8,
            "per_page": 25,
            "total": 189,
            "from": 1,
            "to": 25
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-social_login.settings.view`)이 없는 경우 |
| 422 | Unprocessable Entity | 쿼리 검증 실패(`AdminLogListRequest`): `provider` 형식, `outcome` `intent` `status` `event_type` 허용값 밖, `error_code` 50자 초과, `page` `per_page` 범위 밖. `error.errors` 에 필드별 메시지 |

<!-- @generated:end -->

**설명**

관리자 진단 화면의 「시도 기록」 표가 부른다. 로그인, 가입, 연결, 재확인, 관리자 연결 테스트 시도를 한 줄씩 최신순(`id` 내림차순)으로 돌려준다. 필터는 `provider`, `outcome`, `intent`, `error_code` 이고 모두 정확히 일치로 건다. `per_page` 를 주지 않으면 20건씩이다.

`status` 와 `event_type` 은 웹훅 수신 이력과 같은 요청 검증을 나눠 써서 받기만 하고 이 목록을 거르지 않는다. `user_id` 는 관련 회원의 내부 ID 이며 회원이 정해지기 전 실패(콜백 실패, 가입 대기)는 `null` 이다. 기록은 보존 기간이 지나면 정리 작업이 지운다.

