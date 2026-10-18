# Seo API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Seo 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
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
    "message": "성공적으로 처리되었습니다.",
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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| cleared | integer \| string | `12` / `"all"` | 삭제 결과. `layout` 을 지정한 경우 해당 레이아웃에서 무효화된 캐시 항목 수(정수), 미지정 시 전체 캐시를 삭제했음을 뜻하는 문자열 `"all"`. |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "cleared": "all"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 캐시 무효화(`invalidateByLayout` / `clearAll`) 중 예외가 발생한 경우 (`messages.error_occurred`) |

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
| last_updated_at | string | `2026-08-04T02:29:57+00:00` | **예약 시점 기준** 마지막 생성 일시. 이번 재생성의 결과가 아니라 직전 생성 시각이며, 잡 완료 후 갱신됩니다 (아직 한 번도 생성되지 않았으면 null) |
| progress | object | `{"status":"queued","mode":"full","started_at":"2026-08-04…` | 재생성 진행상황. 예약 직후 `status=queued`, `mode=full` 로 즉시 기록됩니다. 필드 상세는 아래 상태 조회 API 참조 (진행 이력이 없으면 null) |
| realtime_enabled | boolean | `false` | 실시간 연결(WebSocket) 가능 여부. true 면 프론트가 진행상황 채널을 구독하고, false 면 상태 API 를 주기적으로 폴링합니다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "Sitemap 재생성을 시작했습니다. 완료까지 시간이 걸릴 수 있습니다.",
    "data": {
        "last_updated_at": "2026-08-04T02:29:57+00:00",
        "progress": {
            "status": "queued",
            "mode": "full",
            "started_at": "2026-08-04T12:53:51+00:00",
            "finished_at": null,
            "phase": null,
            "urls": 0,
            "url_count": null,
            "child_count": null,
            "message": null
        },
        "realtime_enabled": false
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

sitemap.xml 재생성을 큐에 예약합니다. 관리자 수동 재생성은 현 생성 상태와 무관하게 **항상 전체(full) 재생성**입니다. 응답은 예약 접수 결과이며 생성 완료를 의미하지 않습니다 — 실제 생성은 큐 워커가 `GenerateSitemapJob` 을 처리할 때 수행되고, 완료 후 마지막 생성 시각이 갱신됩니다.

요청 스레드에서 동기 생성하지 않는 이유는 대용량(수백만 URL) 사이트에서 생성이 수 분~수십 분 걸려 관리자 요청이 메모리 초과/타임아웃으로 실패하기 때문입니다. 잡은 유니크 락(`seo-sitemap`)을 사용하므로 재생성을 연속으로 요청해도 동시에 여러 건이 실행되지 않고 한 건만 큐에 남습니다.

예약 직후 진행상황이 `queued` 로 기록되며, 이후 진행 단계는 `GET /api/admin/seo/sitemap/status` 로 조회합니다.

이 엔드포인트로 접수된 **관리자 수동 재생성**은 실행한 관리자의 ID 를 잡에 실어, 생성 완료 시 `sitemap_regenerated`, 실패 시 `sitemap_regenerate_failed` 알림이 **그 관리자에게만** 발송됩니다(기본 채널: 앱 내 알림). 스케줄러·리소스 변경(증분)·봇 캐시 미스로 유발된 재생성은 실행 관리자가 없어 알림을 보내지 않습니다. 알림 정의는 `config/core.php` 의 `notification_definitions` 에 있으며 관리자 알림 설정에서 채널을 조정할 수 있습니다.


### GET /api/admin/seo/sitemap/status
<!-- @generated:start:api.admin.seo.sitemap.status -->
- **라우트명**: `api.admin.seo.sitemap.status`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\SeoCacheController@sitemapStatus`
- **인증/권한**: `auth:sanctum` + `permission:core.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/seo/sitemap/status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| last_updated_at | string | `2026-08-04T12:53:51+00:00` | 마지막 생성 일시 (아직 한 번도 생성되지 않았으면 null) |
| progress | object | `{"status":"queued","mode":"full","started_at":"2026-08-04…` | 재생성 진행상황. 진행 이력이 없으면 null |
| realtime_enabled | boolean | `false` | 실시간 연결(WebSocket) 가능 여부. true 면 프론트가 `core.admin.seo.sitemap` 채널을 구독하고, false 면 이 API 를 주기적으로 폴링합니다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": {
        "last_updated_at": "2026-08-04T12:53:51+00:00",
        "progress": {
            "status": "queued",
            "mode": "full",
            "started_at": "2026-08-04T12:53:51+00:00",
            "finished_at": null,
            "phase": null,
            "urls": 0,
            "url_count": null,
            "child_count": null,
            "message": null
        },
        "realtime_enabled": false
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

Sitemap 재생성 진행상황과 실시간 연결 가능 여부를 조회합니다. SEO 탭 진입 시 초기 로드에 사용되며, `realtime_enabled` 가 false(웹소켓 미사용)일 때는 프론트가 진행 중(`queued`/`running`/`writing`) 동안 이 API 를 3초 간격으로 폴링합니다. 실시간 연결이 켜져 있으면 폴링 대신 `core.admin.seo.sitemap` 채널의 `sitemap.progress.updated` 이벤트로 즉시 갱신됩니다.

진행상황은 캐시(TTL 3600초)에 저장되므로, 잡이 비정상 종료해도 시간이 지나면 만료됩니다. 잡 최종 실패 시에는 `progress.status=failed` 로 고정됩니다.


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
    "message": "성공적으로 처리되었습니다.",
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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| status | string | `dispatched` | 워밍업 요청 접수 상태. 현재 구현에서는 항상 `dispatched` 고정값이며, 실제 사전 렌더가 완료되었음을 뜻하지는 않는다. |
| message | string | `SEO 캐시 워밍업이 시작되었습니다.` | 관리자에게 노출할 안내 문구 (`seo.warmup_dispatched` 다국어 키). |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "status": "dispatched",
        "message": "SEO 캐시 워밍업이 시작되었습니다."
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.settings.update`)이 없는 경우 |
| 500 | Internal Server Error | 워밍업 처리 중 예외가 발생한 경우 (`messages.error_occurred`) |

<!-- @generated:end -->

**설명**

SEO 캐시 워밍업(모든 SEO 레이아웃 사전 렌더)을 위한 엔드포인트입니다. 현재 컨트롤러는 실제 워밍업 로직 없이 `status: dispatched` 와 안내 메시지만 반환합니다(실 렌더링은 후속 구현 예정). 응답 성공은 요청 접수만을 의미하며 이 시점에 캐시가 채워지지는 않습니다.


