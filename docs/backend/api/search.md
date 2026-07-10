# Search API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Search 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/search
<!-- @generated:start:api.search -->
- **라우트명**: `api.search`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicSearchController@search`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| q | query | string | 아니오 | min 2, max 200 | 검색어 (부분 일치) |
| type | query | string | 아니오 | — | 유형 필터 (해당 유형의 항목만 조회) |
| sort | query | string | 아니오 | `relevance`, `latest`, `oldest`, `views`, `popular`, `price_asc`, `price_desc` | 정렬 기준 (필드명, `-` 접두 시 내림차순) |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| board_slug | query | string | 아니오 | max 100 | 검색 범위를 특정 게시판으로 한정 (게시판 모듈이 `core.search.validation_rules` 훅으로 추가하는 파라미터, 해당 slug의 게시판 글만 검색) |
| category_id | query | integer | 아니오 | — | category 식별자 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.search.validation_rules`).

**요청 예시**

```http
GET /api/search?q=%EC%98%88%EC%8B%9C%EA%B0%92&type=%EC%98%88%EC%8B%9C%EA%B0%92&sort=relevance&page=1&per_page=1&board_slug=example-key&category_id=1 HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| q | string | `` | 실제 검색에 사용된 검색어 (요청 `q` 를 trim 하여 에코, 검색어가 비어 있으면 빈 문자열) |
| total | integer | `0` | 전체 개수 (집계) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "검색어를 입력해주세요.",
    "data": {
        "q": "",
        "total": 0
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

프론트엔드 통합 검색(`search/index.json`)이 호출하는 공개 엔드포인트입니다. 인증이 필요 없으며 게스트도 사용할 수 있습니다. 코어 컨트롤러는 검색 결과를 직접 생성하지 않고, 검증된 파라미터로 검색 컨텍스트(q/type/sort/page/per_page 및 요청 객체)를 구성한 뒤 `core.search.results` Filter 훅을 실행합니다. 게시판·상품 등 각 검색 대상 모듈이 이 훅에 리스너를 등록해 자신의 카테고리 결과를 추가하고, `core.search.build_response` 훅으로 응답 구조를 완성합니다. 따라서 활성 검색 모듈이 없으면 항상 빈 결과(`total: 0`)가 반환됩니다. 검색 엔진 자체는 Scout + `DatabaseFulltextEngine`(MySQL FULLTEXT) 기반이며, 상세는 `docs/backend/search-system.md`를 참고하세요.


