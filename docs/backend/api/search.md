# Search API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Search 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/search
<!-- @generated:start:api.search -->
- **라우트명**: `api.search`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicSearchController@search`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| q | query | string | 아니오 | min 2, max 200 | 검색어 (부분 일치) |
| type | query | string | 아니오 | — | 유형 필터 (해당 유형의 항목만 조회) |
| sort | query | string | 아니오 | `relevance`, `latest`, `oldest`, `views`, `popular`, `price_asc`, `price_desc` | 정렬 기준 (필드명, `-` 접두 시 내림차순) |
| page | query | integer | 아니오 | min 1, max = 목록 한계값 설정 | 조회할 페이지 번호 (1부터 시작). 상한은 관리자 > 환경설정 > 고급의 «페이지 번호 상한» 이며 남용 차단용입니다 — 정상 탐색은 `has_more_pages` 로 계속 열려 있습니다 |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| cursor | query | string | 아니오 | max 500 | 커서(키셋) 페이지 이동용 커서. 응답의 `next_cursor` / `prev_cursor` 를 그대로 돌려보냅니다. 특정 탭을 볼 때만 유효하며(전체 탭은 무시), 실제 컬럼 기준 정렬(`latest`·`oldest`·`views`·`popular`·`price_asc`·`price_desc`)에서만 적용됩니다. 관련도순은 계산값 정렬이라 커서를 쓸 수 없어 `page` 기반 이동을 유지합니다. 형식이 깨진 값은 오류 없이 첫 페이지로 처리됩니다 |
| board_slug | query | string | 아니오 | max 100 | 검색 범위를 특정 게시판으로 한정 (게시판 모듈이 `core.search.index_validation_rules` 훅으로 추가하는 파라미터, 해당 slug의 게시판 글만 검색) |
| category_id | query | integer | 아니오 | — | category 식별자 |

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
| total | integer | `0` | 전체 개수 (집계). 상한을 넘기면 상한값이며 «그 이상» 을 뜻합니다 |
| total_relation | string | `exact` | 총 건수 정확도 (`exact` 정확 / `at_least` 그 이상) |
| total_is_exact | boolean | `true` | 총 건수가 정확한지 여부. `false` 면 화면이 "N건 이상" 으로 표기합니다 |
| result_cap | integer\|null | `10000` | 총 건수 집계에 적용된 상한 (무제한이면 `null`) |
| all_count | integer | `0` | 전체 탭 기준 합계 (탭 배지 표기용) |
| all_count_is_exact | boolean | `true` | 전체 합계가 정확한지 여부. 카테고리 중 하나라도 상한에 걸리면 `false` 입니다 |
| last_page | integer\|null | `1` | 특정 탭 조회 시의 마지막 페이지. **총 건수가 부정확하면 `null`** |
| has_more_pages | boolean | `false` | 다음 페이지 존재 여부 (총 건수를 몰라도 정확) |
| next_cursor | string\|null | `null` | 다음 페이지 커서. 커서 방식으로 응답했을 때만 채워지며, `page` 방식 응답에서는 `null` 입니다 |
| prev_cursor | string\|null | `null` | 이전 페이지 커서. 위와 같습니다 |
| counts_are_exact | object | `{}` | 카테고리별 총 건수 정확도 (`{"posts": true, "products": false}`). 탭 배지가 카테고리마다 그려지므로 정확도도 카테고리마다 제공됩니다 — 정확하지 않은 배지는 화면에서 "이상" 으로 표기됩니다 |
| categories_failed | object | `{}` | 카테고리별 검색 실패 여부 (`{"posts": true, "products": false}`). 카테고리 검색이 서버 예외로 실패하면 그 카테고리만 `true` 가 되며, 화면은 이 값으로 "검색 결과 없음" 과 구분되는 오류 안내를 그립니다. 실패해도 HTTP 는 200 입니다 (다른 카테고리 결과는 정상 전달) |
| search_failed | boolean | `false` | 하나 이상의 카테고리가 실패했는지 여부 (`categories_failed` 의 논리합) |

카테고리(탭) 중 하나라도 상한에 걸리면 합계도 정확하지 않습니다 — 정확한 카테고리 몇 개를
더해 봐야 전체가 정확해지지 않기 때문입니다. 그 경우 `total_is_exact` 는 `false` 가 됩니다.

실패한 카테고리의 페이로드는 `failed: true` 와 함께 `total: 0`, `total_is_exact: false`
(`total_relation: at_least`) 로 내려갑니다 — 실패한 0건을 "정확한 0건" 으로 말하지 않기
위함입니다. 배지·건수 표기는 이 정확도를 그대로 따릅니다.

> 상한·페이지 이동 규약 상세: [pagination.md](../pagination.md)

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
        "total": 0,
        "total_relation": "exact",
        "total_is_exact": true,
        "result_cap": 10000
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

프론트엔드 통합 검색(`search/index.json`)이 호출하는 공개 엔드포인트입니다. 인증 없이(게스트) 사용할 수 있고, Bearer 토큰을 보내면 회원으로 해석되어 게시판별 열람 권한이 검색 결과와 `available_boards` 필터 목록에 반영됩니다(`optional.sanctum` — 위조 토큰은 401, 만료 토큰은 게스트로 처리). 코어 컨트롤러는 검색 결과를 직접 생성하지 않고, 검증된 파라미터로 검색 컨텍스트(q/type/sort/page/per_page 및 요청 객체)를 구성한 뒤 `core.search.results` Filter 훅을 실행합니다. 게시판·상품 등 각 검색 대상 모듈이 이 훅에 리스너를 등록해 자신의 카테고리 결과를 추가하고, `core.search.build_response` 훅으로 응답 구조를 완성합니다. 따라서 활성 검색 모듈이 없으면 항상 빈 결과(`total: 0`)가 반환됩니다. 검색 엔진 자체는 Scout + `DatabaseFulltextEngine`(MySQL FULLTEXT) 기반이며, 상세는 `docs/backend/search-system.md`를 참고하세요.


