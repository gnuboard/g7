# Positions API 레퍼런스

> **소유**: plugin `sirsoft-hero_section` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Positions 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-hero_section/admin/positions/{position}/targets
<!-- @generated:start:api.plugins.sirsoft-hero_section.admin.positions.targets -->
- **라우트명**: `api.plugins.sirsoft-hero_section.admin.positions.targets`
- **컨트롤러**: `Plugins\Sirsoft\HeroSection\Http\Controllers\Admin\PositionTargetController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-hero_section.banners.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| position | path | string | 예 | 위치 키 형식 | 동적 위치 키(`board_hero`·`product_hero`·`category_hero`, 확장이 더한 동적 위치) — 정적 위치·등록되지 않은 위치는 404 |
| keyword | query | string | 아니오 | max 100 | 대상 이름(·slug·상품 코드) 검색어 — 비면 앞에서부터 최대 20건 |
| key | query | string | 아니오 | max 100 | 이미 고른 대상 키 — 그 이름·사이트 주소·존재 여부를 `selected` 로 함께 준다 |

**요청 예시**

```http
GET /api/plugins/sirsoft-hero_section/admin/positions/board_hero/targets?keyword=%EC%9E%90%EC%9C%A0&key=2 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| position | string | `board_hero` | 위치 키 |
| items[] | array | `[{"key":"2","label":"자유게시판","hint":"free"}]` | 고르기 후보(최대 20건) |
| items[].key | string | `2` | 저장 키(게시판·상품·카테고리 id) — 배너 `target_key` 로 보낸다 |
| items[].label | string | `자유게시판` | 대상 이름(현재 언어). 카테고리는 상위 경로를 이은 이름(`의류 › 남성`) |
| items[].hint | string | `free` | 보조 표시 — 게시판·카테고리 slug, 상품 코드 |
| selected | object\|null | `{"key":"2","label":"자유게시판","exists":true,"site_url":"/board/free"}` | `key` 를 보냈을 때 그 대상(안 보냈으면 null) |
| selected.label | string\|null | `자유게시판` | 대상 이름(지워졌으면 null) |
| selected.exists | boolean | `true` | 대상이 있는지 — false 면 「없는 대상」(배너는 남는다) |
| selected.site_url | string\|null | `/board/free` | 대상의 실제 화면 주소(「사이트에서 보기」), 없는 대상이면 null |

**응답 예시**

```json
{
    "success": true,
    "message": "대상 목록을 불러왔습니다.",
    "data": {
        "position": "board_hero",
        "items": [
            {"key": "2", "label": "자유게시판", "hint": "free"}
        ],
        "selected": {"key": "2", "label": "자유게시판", "exists": true, "site_url": "/board/free"}
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-hero_section.banners.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**

동적 위치(게시판마다·상품마다·카테고리마다 배너를 따로 두는 위치)의 관리자 대상 고르기 목록. 위치 정의의 대상 해석기(`TargetResolverInterface::search`·`labels`·`siteUrl`)로 찾는다. 히어로 **조회** 권한만으로 받는다 — 게시판·상품 관리 권한이 없는 히어로 관리자도 대상을 고를 수 있게 히어로가 대신 찾아 준다(고르기에 필요한 키·이름·slug/코드만 낸다). 정적 위치(`home_hero`·`shop_hero`)와 등록되지 않은 위치는 404. 관리자 목록은 고른 대상을 주소(`target`)에 담고, 이 API 의 `selected` 로 이름·「사이트에서 보기」 주소·「없는 대상」 여부를 받는다.


### GET /api/plugins/sirsoft-hero_section/admin/positions/{position}/placements
<!-- @generated:start:api.plugins.sirsoft-hero_section.admin.positions.placements -->
- **라우트명**: `api.plugins.sirsoft-hero_section.admin.positions.placements`
- **컨트롤러**: `Plugins\Sirsoft\HeroSection\Http\Controllers\Admin\PositionTargetController@placements`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-hero_section.banners.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| position | path | string | 예 | 위치 키 형식 | 위치 키 — 동적 위치면 대상 목록, 정적·등록되지 않은 위치면 `data` 가 null |
| page | query | integer | 아니오 | 1~100000 | 쪽 번호(쪽당 20개, 넘치면 마지막 쪽) |

**요청 예시**

```http
GET /api/plugins/sirsoft-hero_section/admin/positions/board_hero/placements?page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| position | string | `board_hero` | 위치 키 |
| data[] | array | `[{"key":"4","label":"이벤트","exists":true,"site_url":"/board/event","total":2,"active":1}]` | 배너가 있는 대상 — 최근 수정한 대상이 앞 |
| data[].key | string | `4` | 저장 키(배너 `target_key`) — 관리 화면 주소의 `target` |
| data[].label | string\|null | `이벤트` | 대상 이름(현재 언어). 지워진 대상이면 null |
| data[].exists | boolean | `true` | 대상이 있는지 — false 면 「없는 대상」(배너는 남아 관리로 들어갈 수 있다) |
| data[].site_url | string\|null | `/board/event` | 대상의 실제 화면 주소, 없는 대상이면 null |
| data[].total | integer | `2` | 그 대상의 전체 배너 수 |
| data[].active | integer | `1` | 지금 노출 중인 배너 수 — 공개 노출과 같은 상태 판정(비활성·예약·종료 제외). 없는 대상(`exists: false`)은 사이트에 나가지 않으므로 0 |
| summary.banners | integer | `3` | 이 위치의 배너 수(대상 있는 배너) |
| summary.targets | integer | `2` | 배너를 둔 대상 수 |
| pagination | object | `{"current_page":1,"last_page":1,"total":2,"per_page":20}` | 대상 목록 쪽 정보 |

**응답 예시**

```json
{
    "success": true,
    "message": "배너가 있는 대상 목록을 불러왔습니다.",
    "data": {
        "position": "board_hero",
        "data": [
            {"key": "4", "label": "이벤트", "exists": true, "site_url": "/board/event", "total": 2, "active": 1},
            {"key": "2", "label": "자유게시판", "exists": true, "site_url": "/board/free", "total": 1, "active": 1}
        ],
        "summary": {"banners": 3, "targets": 2},
        "pagination": {"current_page": 1, "last_page": 1, "total": 2, "per_page": 20}
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-hero_section.banners.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

동적 위치의 관리자 「대상 목록」 — 배너가 있는 대상을 이름·사이트 주소·전체·노출 중 건수와 함께 낸다. 대상별 건수는 묶음 조회 두 번(전체·노출 중), 이름은 해석기 `labels()` 한 번, 사이트 주소는 해석기가 `TargetSiteUrlsInterface::siteUrls()` 를 구현하면 한 번(아니면 그 쪽 안의 키만 `siteUrl()` 로 하나씩)이다. 게시판·상품·카테고리와 다른 확장이 더한 동적 위치가 같은 길을 쓴다. 관리 화면이 위치 탭마다 부르므로 정적·등록되지 않은 위치는 오류가 아니라 `data: null` 이다.
