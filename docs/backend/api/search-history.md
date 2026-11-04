# Search History API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Search History(내 최근 검색어) 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시 + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 본인 전용 묶음(`/api/me`)이라 별도 권한이 없습니다 — 인증된 회원이면 자기 검색어만 다룹니다
5. 범위(scope)는 코어 `core.site` 와 활성 확장이 manifest `search_scopes` 로 선언한 `{확장 식별자}.{이름}` 만 받습니다
```

---

공통 규칙

- **범위**: 선언되지 않았거나 확장이 꺼진 범위는 `scope` 422(`search.history.validation.scope_unknown` — "사용할 수 없는 검색 범위입니다.")입니다. 운영자가 기능을 끈 사이트(`privacy.search_history_enabled = false`)는 기록하지 않고 목록이 `enabled: false` 입니다.
- **검색어 정규화**: 앞뒤 공백 제거, 연속 공백(줄바꿈 포함)을 하나로, 100자까지 저장합니다. 같은 회원·범위·검색어(대소문자 구분 없음)는 한 행이고 다시 검색하면 검색 일시만 새로 씁니다.
- **보관 개수**: 범위별 보관 개수(설정 → 선언값 → 10)를 넘으면 가장 오래된 검색어부터 지웁니다(감추는 것이 아니라 삭제).
- **자동 저장 끄기**: 회원 단위 하나(`users.search_history_auto_save`). 끄면 기록하지 않고(`recorded: false`) 목록을 비워 냅니다. 저장된 검색어는 지우지 않습니다.
- **시간대**: `searched_at` 은 요청 회원 시간대의 ISO 8601, `searched_at_ms` 는 밀리초 시각(엔진이 비회원 목록과 같은 단위로 비교).
- **요청 한도**: `/api/me` 묶음과 같은 사용자 요청 한도(`throttle:user`)를 씁니다.


### DELETE /api/me/search-history
<!-- @generated:start:api.me.search-history.destroy-all -->
- **라우트명**: `api.me.search-history.destroy-all`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\SearchHistoryController@destroyAll`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| scope | query | string | 아니오 | max 100 | 지울 범위. 없으면 전 범위. 지금 선언되지 않은 범위(끈 확장)의 검색어도 지울 수 있다 |

**요청 예시**

```http
DELETE /api/me/search-history?scope=core.site HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted | integer | `2` | 지운 검색어 수 |

**응답 예시**

```json
{
    "success": true,
    "message": "최근 검색어를 모두 삭제했습니다.",
    "data": {
        "deleted": 2
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | `scope` 가 문자열이 아니거나 100자를 넘는 경우 |
| 500 | Server Error | 삭제 중 예외 (`search.history.messages.failed` — "최근 검색어를 처리하지 못했습니다.") |

<!-- @generated:end -->

**설명** 내 최근 검색어를 모두 지웁니다. 검색창 펼침 목록의 「전체 삭제」가 그 검색창의 범위를 주어 부릅니다. 범위를 주지 않으면 모든 범위를 지웁니다.


### GET /api/me/search-history
<!-- @generated:start:api.me.search-history.index -->
- **라우트명**: `api.me.search-history.index`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\SearchHistoryController@index`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| scope | query | string | 예 | 지금 선언된 범위 id, max 100 | 읽을 검색 범위 |

**요청 예시**

```http
GET /api/me/search-history?scope=core.site HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| enabled | boolean | `true` | 이 범위를 쓸 수 있는지 (기능 켬 + 선언된 범위) |
| auto_save | boolean | `true` | 회원의 자동 저장 여부 — false 면 `items` 는 빈 배열 |
| max_count | integer | `10` | 이 범위의 보관 개수 |
| items | array | `[{"id":2,"keyword":"노트북 가방",…}]` | 최근 검색어, 최신순 (최대 `max_count`건) |
| items[].id | integer | `2` | 검색어 ID (한 건 삭제에 쓴다) |
| items[].keyword | string | `노트북 가방` | 검색어 |
| items[].searched_at | string | `2026-11-04T05:45:07+09:00` | 마지막으로 검색한 일시 (회원 시간대 ISO 8601) |
| items[].searched_at_ms | integer | `1793738707000` | 같은 일시의 밀리초 시각 |

**응답 예시**

```json
{
    "success": true,
    "message": "최근 검색어를 불러왔습니다.",
    "data": {
        "enabled": true,
        "auto_save": true,
        "max_count": 10,
        "items": [
            {
                "id": 2,
                "keyword": "노트북 가방",
                "searched_at": "2026-11-04T05:45:07+09:00",
                "searched_at_ms": 1793738707000
            },
            {
                "id": 1,
                "keyword": "무선 이어폰",
                "searched_at": "2026-11-04T05:45:07+09:00",
                "searched_at_ms": 1793738707000
            }
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | `scope` 가 없거나 선언되지 않은 범위인 경우 (`search.history.validation.scope_unknown`) |
| 500 | Server Error | 조회 중 예외 (`search.history.messages.failed`) |

<!-- @generated:end -->

**설명** 범위 하나의 내 최근 검색어를 최신순으로 돌려줍니다. 검색창 펼침 목록이 부릅니다(엔진 `G7Core.searchHistory.list(scope)` / `G7Core.useSearchHistory(scope)`).


### POST /api/me/search-history
<!-- @generated:start:api.me.search-history.store -->
- **라우트명**: `api.me.search-history.store`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\SearchHistoryController@store`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| scope | body | string | 예 | 지금 선언된 범위 id, max 100 | 검색 범위 |
| keyword | body | string | 예 | max 200 (저장은 정규화 후 100자) | 검색어 |

**요청 예시**

```http
POST /api/me/search-history HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "scope": "core.site",
    "keyword": "  무선   이어폰 "
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| recorded | boolean | `true` | 기록했는지 — 자동 저장 꺼짐·기능 꺼짐·정규화 후 빈 검색어·확장 필터가 거른 검색어면 false |
| item | object\|null | `{"id":1,"keyword":"무선 이어폰",…}` | 기록한 검색어 (`recorded` 가 false 면 null). 모양은 목록 `items[]` 와 같다 |

**응답 예시**

```json
{
    "success": true,
    "message": "검색어를 저장했습니다.",
    "data": {
        "recorded": true,
        "item": {
            "id": 1,
            "keyword": "무선 이어폰",
            "searched_at": "2026-11-04T05:45:07+09:00",
            "searched_at_ms": 1793738707000
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 선언되지 않은 범위 — 실측 `{"message":"사용할 수 없는 검색 범위입니다.","errors":{"scope":["사용할 수 없는 검색 범위입니다."]}}`, 또는 `keyword` 누락·200자 초과 |
| 500 | Server Error | 기록 중 예외 (`search.history.messages.failed`) |

<!-- @generated:end -->

**설명** 검색창 제출 때 엔진(내장 핸들러 `recordSearchKeyword`·`G7Core.searchHistory.record`)이 부릅니다. 같은 검색어면 검색 일시만 새로 쓰고, 범위의 보관 개수를 넘는 옛 검색어를 지웁니다. 확장은 `core.search_history.filter_keyword` 필터로 저장하지 않을 검색어를 거를 수 있습니다.


### POST /api/me/search-history/merge
<!-- @generated:start:api.me.search-history.merge -->
- **라우트명**: `api.me.search-history.merge`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\SearchHistoryController@merge`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| items | body | array | 예 | max 200 | 브라우저(비회원 때)에 쌓인 검색어 |
| items.*.scope | body | string | 예 | max 100 | 범위 id — 지금 선언되지 않은 범위의 항목은 거부하지 않고 건너뛴다 |
| items.*.keyword | body | string | 예 | max 200 | 검색어 |
| items.*.searched_at | body | integer | 아니오 | min 0 (밀리초 시각) | 검색 일시. 없거나 미래면 지금 |

**요청 예시**

```http
POST /api/me/search-history/merge HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "items": [
        { "scope": "core.site", "keyword": "브라우저 검색어", "searched_at": 1793600000000 }
    ]
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| merged | integer | `1` | 넣은 항목 수 (건너뛴 항목 제외, 자동 저장을 끈 회원이면 0) |

**응답 예시**

```json
{
    "success": true,
    "message": "브라우저에 있던 최근 검색어를 합쳤습니다.",
    "data": {
        "merged": 1
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | `items` 가 배열이 아니거나 200개를 넘는 경우 (`search.history.validation.items_max`), 항목의 `scope`·`keyword` 누락 |
| 500 | Server Error | 병합 중 예외 (`search.history.messages.failed`) |

<!-- @generated:end -->

**설명** 로그인 직후 엔진이 비회원 때 브라우저(localStorage `g7_search_history`)에 쌓인 검색어를 보냅니다. 검색 일시 순으로 넣어 같은 검색어는 하나(늦은 일시)로 합치고, 범위마다 보관 개수로 자릅니다. 엔진은 성공하면 브라우저 칸을 지우고, 실패하면 남겨 다음 로그인 때 다시 보냅니다.


### PUT /api/me/search-history/settings
<!-- @generated:start:api.me.search-history.settings -->
- **라우트명**: `api.me.search-history.settings`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\SearchHistoryController@updateSettings`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| auto_save | body | boolean | 예 | — | 자동 저장 여부 |

**요청 예시**

```http
PUT /api/me/search-history/settings HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "auto_save": false
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| auto_save | boolean | `false` | 바뀐 자동 저장 여부 |

**응답 예시**

```json
{
    "success": true,
    "message": "검색어 자동 저장을 껐습니다.",
    "data": {
        "auto_save": false
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | `auto_save` 가 없거나 불리언이 아닌 경우 |
| 500 | Server Error | 저장 중 예외 (`search.history.messages.failed`) |

<!-- @generated:end -->

**설명** 검색창의 「자동 저장 끄기/켜기」가 부릅니다. 끄면 기록을 멈추고 목록을 감춥니다(저장된 검색어는 남김). 범위별이 아니라 회원 단위 하나입니다.


### DELETE /api/me/search-history/{id}
<!-- @generated:start:api.me.search-history.destroy -->
- **라우트명**: `api.me.search-history.destroy`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\SearchHistoryController@destroy`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | 숫자 | 검색어 ID (목록 `items[].id`) |

**요청 예시**

```http
DELETE /api/me/search-history/2 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted | integer | `1` | 지운 수 (늘 1) |

**응답 예시**

```json
{
    "success": true,
    "message": "검색어를 삭제했습니다.",
    "data": {
        "deleted": 1
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | 없거나 다른 회원의 검색어 — 실측 `{"success":false,"message":"검색어를 찾을 수 없습니다."}` |
| 500 | Server Error | 삭제 중 예외 (`search.history.messages.failed`) |

<!-- @generated:end -->

**설명** 검색창 펼침 목록 항목의 × 가 부릅니다. 다른 회원의 검색어 ID 를 주면 존재 여부를 드러내지 않고 404 입니다.
