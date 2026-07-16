# Seo API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Seo 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/admin/seo/cached-urls
<!-- @generated:start:api.admin.seo.cached-urls -->
- **라우트명**: `api.admin.seo.cached-urls`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SeoCacheController@cachedUrls`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/seo/cached-urls HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| urls | array | `[]` | 현재 사전 렌더 캐시에 남아 있는 봇 대상 페이지 URL 목록 (SeoCacheManager 인덱스에서 조회). |
| count | integer | `0` | 캐시된 URL 개수 (`urls` 배열 길이). |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": {
        "urls": [],
        "count": 0
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**

SeoCacheManager 인덱스에서 현재 캐시된 SEO 페이지 URL 목록과 개수를 조회합니다. 어떤 봇 대상 페이지가 사전 렌더 캐시로 남아 있는지 확인하는 진단 용도로 사용합니다.


### POST /api/admin/seo/clear-cache
<!-- @generated:start:api.admin.seo.clear-cache -->
- **라우트명**: `api.admin.seo.clear-cache`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SeoCacheController@clearCache`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| layout | body | string | 아니오 | — | 무효화 대상 레이아웃명. 지정 시 해당 레이아웃의 SEO 캐시만 삭제하고 무효화된 항목 수를 반환하며, 미지정 시 전체 SEO 캐시를 삭제한다. |
| module | body | string | 아니오 | — | 모듈 식별자 필터. 검증 규칙에는 정의되어 있으나 현재 컨트롤러 로직에서는 사용되지 않는다(향후 모듈 단위 캐시 무효화 확장 예약 필드). |

**요청 예시**

```http
POST /api/admin/seo/clear-cache HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "layout": "예시값",
    "module": "예시값"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: side-effectful-write — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

SEO 사전 렌더 캐시를 삭제합니다. `layout` 을 지정하면 해당 레이아웃 캐시만 무효화하고, 지정하지 않으면 전체 SEO 캐시를 삭제합니다. 응답의 `data.cleared` 는 `layout` 지정 시 무효화된 항목 수(정수), 미지정 시 문자열 `"all"` 입니다. 설정이나 콘텐츠 변경 후 오래된 봇 응답이 캐시로 남는 것을 방지할 때 사용합니다.


### POST /api/admin/seo/sitemap/regenerate
<!-- @generated:start:api.admin.seo.sitemap.regenerate -->
- **라우트명**: `api.admin.seo.sitemap.regenerate`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SeoCacheController@regenerateSitemap`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/seo/sitemap/regenerate HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| last_updated_at | string | `2026-07-08T03:14:49+00:00` | last updated 일시 |
| size_bytes | integer | `25489` | 크기 (바이트) |
| ttl | integer | `86400` | 캐시 유효 시간 (Time To Live, 초) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "Sitemap 재생성이 완료되었습니다.",
    "data": {
        "last_updated_at": "2026-07-08T03:14:49+00:00",
        "size_bytes": 25489,
        "ttl": 86400
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |

<!-- @generated:end -->

**설명**

sitemap.xml 을 즉시 재생성합니다. SitemapManager 에 위임하며 큐 드라이버와 무관하게 동기(즉시) 실행되고, 완료 후 마지막 생성 시각을 갱신합니다. SEO 설정에서 sitemap 기능이 비활성인 경우 400(`seo.sitemap_disabled`), 생성 실패 시 500 을 반환합니다. 관리자가 콘텐츠 변경 후 검색엔진에 노출할 sitemap 을 스케줄 대기 없이 즉시 갱신할 때 사용합니다.


### GET /api/admin/seo/stats
<!-- @generated:start:api.admin.seo.stats -->
- **라우트명**: `api.admin.seo.stats`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SeoCacheController@stats`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/seo/stats HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| overall | object | `{"total_entries":0,"hits":0,"misses":0,"hit_rate":0,"avg_…` | 최근 7일 전체 캐시 통계 집계. `total_entries`(기록 총 건수), `hits`(적중 건수), `misses`(미적중 건수), `hit_rate`(적중률 %, hits/total×100), `avg_response_time_ms`(미적중 시 평균 렌더링 소요 시간 ms, 데이터 없으면 null). |
| by_layout | array | `[]` | 레이아웃별 통계 목록 (`layout_name` 으로 그룹핑). 각 원소는 `layout_name`·`total`·`hits`·`misses`·`hit_rate`·`avg_response_time_ms` 를 가지며, 레이아웃 단위로 캐시 효율을 비교하는 용도. |
| by_module | array | `[]` | 모듈별 통계 목록 (`module_identifier` 로 그룹핑). 각 원소는 `module_identifier`·`total`·`hits`·`misses`·`hit_rate`·`avg_response_time_ms` 를 가지며, 어느 확장 모듈의 SEO 페이지가 캐시로 재사용되는지 파악하는 용도. |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": {
        "overall": {
            "total_entries": 0,
            "hits": 0,
            "misses": 0,
            "hit_rate": 0,
            "avg_response_time_ms": null
        },
        "by_layout": [],
        "by_module": []
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**

SEO 캐시 적중 현황을 최근 7일 기준으로 전체·레이아웃별·모듈별로 조회합니다. 봇 대상 사전 렌더 캐시가 얼마나 효과적으로 재사용되는지(적중률, 렌더 비용 절감)를 모니터링하는 관리자 대시보드용 통계입니다.


### POST /api/admin/seo/warmup
<!-- @generated:start:api.admin.seo.warmup -->
- **라우트명**: `api.admin.seo.warmup`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SeoCacheController@warmup`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/admin/seo/warmup HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: side-effectful-write — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |

<!-- @generated:end -->

**설명**

SEO 캐시 워밍업(모든 SEO 레이아웃 사전 렌더)을 위한 엔드포인트입니다. 현재 컨트롤러는 실제 워밍업 로직 없이 `status: dispatched` 와 안내 메시지만 반환합니다(실 렌더링은 후속 구현 예정). 응답 성공은 요청 접수만을 의미하며 이 시점에 캐시가 채워지지는 않습니다.


