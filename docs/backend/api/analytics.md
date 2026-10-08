# Analytics API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 방문자 통계 엔드포인트 레퍼런스입니다
2. 수집 비콘(POST /api/analytics/collect)은 공개 경로이며 응답은 언제나 204 입니다
3. 검증 실패·적재 오류도 204 — 비콘에 4xx 를 주면 방문자 콘솔에 오류가 뜹니다
4. 실패 사유는 응답이 아니라 적재 실패 로그에 남고, 관리자 진단 화면에서 봅니다
5. 관리자 조회·설정 API 는 권한 셋(read·update·privacy)으로 갈립니다
```

---

> **조회 기간은 최대 366일입니다.** 관리자 조회 API 의 `from`~`to` 가 366일을 넘으면 **422**(`range_too_long`)입니다 —
> 몇 년치를 한 번에 합산하면 집계 표를 통째로 훑게 되므로, 긴 추이는 월·년 단위로 나눠 봅니다.

> **422 오류 문구는 필드 키 대신 항목명을 씁니다.** 예를 들어 `to` 가 `from` 보다 앞서면 「종료일 필드는 시작일 이후
> 또는 같은 날짜여야 합니다.」로 나옵니다. 설정 저장(`PUT …/settings` · 환경설정의 방문자 통계 탭)도 관리자 화면의 항목
> 이름으로 나옵니다(「원장 보존 필드는 최소 7 이상이어야 합니다.」). `errors` 객체의 **키**는 그대로 필드 이름이므로
> 클라이언트는 키로 칸을 찾습니다.

## 응답 계약 — 왜 언제나 204 인가

수집 비콘은 방문자 브라우저가 **백그라운드로** 보내는 요청입니다. 여기에 4xx·5xx 를
돌려주면 방문자의 개발자 도구 콘솔에 빨간 오류가 남고, 그 화면을 본 운영자는 사이트가
고장 났다고 판단합니다. 통계를 남기지 못한 것이 사이트 장애로 보여선 안 되므로 이
경로는 **어떤 경우에도 204 No Content** 를 돌려줍니다.

그 대신 실패는 반드시 흔적을 남깁니다:

| 상황 | 응답 | 남는 곳 |
| --- | --- | --- |
| 정상 수집 | 204 | 방문·액션 원장 |
| 요청 형식 오류 | 204 | 적재 실패 로그 (사유 `validation`) |
| 방문당 액션 상한 초과 | 204 | 적재 실패 로그 (사유 `max_actions`) |
| 적재 중 오류 | 204 | 적재 실패 로그 (사유 `db_error`) |
| 수집 기능 꺼짐 | 204 | 남기지 않음 (운영자의 결정이지 결함이 아님) |
| 제외 규칙에 걸림 | 204 | 제외 사유 카운터 (진단 화면) |

---

### POST /api/analytics/collect
<!-- @generated:start:api.public.analytics.collect -->
- **라우트명**: `api.public.analytics.collect`
- **컨트롤러**: `App\Http\Controllers\Api\Public\AnalyticsCollectController@store`
- **인증/권한**: 공개 (인증 불필요) · `optional.sanctum` · `throttle:120,1,analytics_collect`

**요청 파라미터**

| 파라미터 | 타입 | 필수 | 제약 | 용도/설명 |
| --- | --- | --- | --- | --- |
| t | string | 필수 | `pageview` `download` `outlink` `event` `goal` | 액션 종류. 사이트 내부 검색(`site_search`)은 브라우저가 보낼 수 없다 — 결과 건수는 서버만 아는 값이라 검색 API 의 훅이 직접 기록한다 |
| url | string | 필수 | URL 형식, 최대 2048자 | 방문자가 보고 있던 주소. SPA 라 서버가 아는 주소와 다를 수 있어 브라우저가 알려 준다 |
| title | string | 선택 | 최대 512자 | 페이지 제목 |
| ref | string | 선택 | URL 형식, 최대 1024자 | 유입 URL. 방문의 첫 비콘에서만 의미가 있다 |
| res | string | 선택 | `1920x1080` 형식 | 화면 해상도 |
| lang | string | 선택 | 최대 16자 | 브라우저 언어. 저장은 첫 언어 태그만(예: `ko-KR`) |
| locale | string | 선택 | 최대 10자 | 사이트에서 보고 있던 로케일 |
| prev | integer | 선택 | 0~86400 | 직전 페이지 체류 시간(초). 저장 시 1800초로 자른다 |
| init | boolean | 선택 | — | 이 방문의 첫 비콘인지 여부. 서버 폴백과의 병합 판정에 쓴다 |
| target | string | 선택 | URL 형식, 최대 1024자 | 내려받기·외부 링크의 대상 주소 |
| name | string | 선택 | 최대 255자 | 내려받은 파일명 등 표시 이름. 이벤트(`t=event`)는 이 이름을 사전의 `event` 종류로 저장해 「이벤트」 리포트가 센다(하루 500종 상한) — 이름이 없는 이벤트는 예전처럼 방문 주소를 담고 이벤트 리포트에서 빠진다 |
| goal | string | 선택 | 최대 100자 | 목표 키 (확장이 등록한 전환) |
| attrs | object | 선택 | — | 목표 속성 (금액·통화·라벨) |
| dims | object | 선택 | 최대 20개, 값 255자 | 확장이 넣은 분류 기준(dimension)별 값 |
| ch | object | 선택 | — | 클라이언트 힌트 (`brands`·`platform`·`mobile`) |
| wd | boolean | 선택 | — | `navigator.webdriver` 값. 자동화 브라우저 판정에 쓴다 |

**요청 예시**

```http
POST /api/analytics/collect HTTP/1.1
Host: api.example.com
Content-Type: application/json
Accept: application/json

{
  "t": "pageview",
  "url": "https://example.com/board/free?page=2",
  "title": "자유게시판",
  "ref": "https://www.google.com/",
  "res": "1920x1080",
  "lang": "ko-KR",
  "prev": 42,
  "init": true
}
```

**응답 필드**

응답 본문이 없습니다(204 No Content).

**응답 예시**

```http
HTTP/1.1 204 No Content
Set-Cookie: g7_vid=3f2a...; Path=/; Max-Age=34128000; HttpOnly; SameSite=Lax
```

방문자 쿠키는 **서버가** 심습니다. 브라우저에서 `document.cookie` 로 쓰면 동의 관리
플러그인의 쿠키 차단기가 조용히 버리기 때문입니다. 익명 모드에서는 쿠키를 심지 않습니다.
<!-- @generated:end:api.public.analytics.collect -->

---

## 적재 방식 — 언제나 요청 안에서

**고를 것이 없습니다.** 수집 요청은 훅(`core.analytics.tracking_request_received`)을 거치되
그 리스너가 `'sync' => true` 로 구독하므로 큐를 타지 않고 요청 안에서 원장에 닿습니다.
워커를 운영하지 않는 설치에서도 방문이 유실되지 않습니다.

큐를 써서 얻는 것이 없기 때문입니다 — 배치·합산이 없어 **방문 1건 = 잡 1개**가 되고,
`jobs` 의 INSERT + 워커 SELECT + DELETE 가 원장 INSERT 한 번보다 비쌉니다. 지연을 미룰
이유도 없습니다: 비콘은 `sendBeacon` 이라 브라우저가 기다리지 않고, 서버 폴백은 `terminate`
단계라 응답이 이미 나간 뒤입니다.

훅을 그대로 거치므로 **확장의 이음매는 유지됩니다.** 다만 이 훅을 구독하는 확장은 자기
리스너가 요청 안에서 돈다는 것을 전제해야 합니다 — 느린 작업은 확장 쪽에서 자기 잡으로
넘기십시오.

**방문자 쿠키는 이 요청의 응답에서 심습니다.** 쿠키는 적재 결과물이 아니라 방문자 식별의
전제입니다. 적재와 함께 미루면 쿠키가 영영 나가지 않아 매 비콘이 새 방문으로 잡히고
재방문이 항상 0 이 됩니다 — 응답은 정상 204 라 겉으로는 아무 증상이 없습니다. 그래서
식별(`identify`)은 훅 발화보다 **먼저** 합니다.

집계·익명화 같은 **배치는 큐 드라이버를 따릅니다**(구간 재집계·소급 익명화·확장 제거 시의
정리). 큐 드라이버가 `sync` 면 요청 안에서 즉시 돕니다.

## 집계 신선도 — 화면 열 때 집계와 `data.freshness`

집계 표를 읽는 조회 여섯 개(개요·추이·패턴·봇·리포트·한 항목 추이)는 **권한 확인 뒤, 컨트롤러 앞에서**
오래된 집계를 다시 계산합니다(미들웨어 `analytics.archive_on_view`). 마지막 매시 집계가 1분 넘게 지났으면
오늘치를 그 자리에서 다시 계산하고, 스케줄러가 멈춘 동안 확정되지 않은 날(보존기간 안, 최근 날부터 최대 7일)도
확정합니다. 여러 관리자가 동시에 열어도 1분에 한 번만 돌고(잠금), 오늘 방문이 설정 상한
(`archive_on_view_max_visits`, 기본 50,000)을 넘으면 요청 안에서는 돌리지 않습니다. 어떤 실패도 응답을 깨지
않습니다 — 숫자는 기존 집계로 나갑니다. 실행은 실행 이력에 `trigger=on_view` 로 남고 활동 로그는 남기지
않습니다(분당 1건이 쌓이므로). 원장을 직접 읽는 실시간·방문자·유입 주소·공개 방문자 카운터는 집계를 일으키지
않습니다.

성공 JSON 응답의 `data.freshness` 에 아래 블록이 실립니다(CSV·오류 응답에는 없음).

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| last_run_at | string\|null | 마지막으로 성공한 매시·일 집계 중 늦게 끝난 시각 (사이트 표준시 `Y-m-d H:i:s`). 한 번도 없으면 `null` |
| last_run_trigger | string\|null | 그 실행의 주체 — `scheduled`·`manual`·`on_view` |
| is_today | boolean | `last_run_at` 이 사이트 표준시로 오늘인지 (화면이 시각만 보일지 날짜까지 보일지 고른다) |
| lag_minutes | integer\|null | 마지막 집계 뒤 흐른 분 |
| stale | boolean | 120분 넘게 멈췄는지. 한 번도 집계하지 않았는데 방문이 쌓여 있어도 `true` |
| scheduler.alive | boolean\|null | 스케줄러 생존 — 예약 매시 실행(`trigger=scheduled`, 성공·실패 무관)이 120분 안에 시작했으면 `true`. 없으면 판정 근거(원장 첫 방문·첫 집계 실행 중 이른 시각)가 120분 넘게 쌓였을 때 `false`, 아직 이르거나 근거가 없으면 `null`(설치 직후 오경보 방지) |
| scheduler.last_run_at | string\|null | 마지막 예약 매시 실행 시작 시각 |
| on_view.enabled | boolean | 설정 `archive_on_view_enabled` |
| on_view.status | string\|null | 이번 요청의 결과 — `ran`(돌림)·`fresh`(1분 안에 끝난 집계가 있음)·`throttled`(1분 안에 다른 요청이 돌림)·`locked`(같은 날을 다른 실행이 계산 중)·`too_many_visits`(오늘 방문이 상한 초과)·`disabled`·`failed` |
| on_view.today_visits | integer\|null | 이번 요청이 센 오늘 방문 수 (세지 않았으면 `null`) |
| on_view.max_visits | integer | 오늘 방문 상한 |

---

### GET /api/admin/analytics/overview
<!-- @generated:start:api.admin.analytics.overview -->
- **라우트명**: `api.admin.analytics.overview`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\OverviewController@index`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.analytics.read` → `analytics.archive_on_view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| preset | query | string | 아니오 | today, yesterday, 7d, 30d, 90d, this_month, last_month, this_year | 기간 프리셋. 기본 `7d` |
| from | query | date | 아니오 | Y-m-d | 조회 시작일. 주면 `preset` 보다 우선한다 (주소를 공유했을 때 보던 화면이 그대로 열려야 하므로) |
| to | query | date | 아니오 | Y-m-d, `from` 이후 | 조회 종료일. `from` 만 주면 그 하루 |
| unit | query | string | 아니오 | auto, hour, day, week, month, year | 시계열 단위. 기본 `auto` (구간 길이로 고름) |
| exclude[] | query | array | 아니오 | admin, member | 합산에서 뺄 방문자 유형. 화면의 「관리자 제외」·「회원 제외」 토글 |


**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| kpi.today | integer | 오늘 순방문자. 집계 표가 아니라 원장에서 직접 세고 60초 캐시한다 — 집계는 한 시간에 한 번 돌아 「오늘」이 늦으면 화면을 믿지 못한다 |
| kpi.yesterday | integer | 어제 순방문자 (일 집계 합) |
| kpi.max.value / kpi.max.date | integer / string | 하루 방문자가 가장 많았던 날과 그 수 |
| kpi.total | integer | 전체 방문자 — **일별 순방문자의 합**이다. 방문자 키가 날마다 바뀔 수 있어 여러 날을 아우르는 진짜 순방문자는 원리적으로 셀 수 없다 |
| kpi.change_pct | float\|null | 어제 대비 증감률. 어제가 0 이면 `null` (「무한 % 증가」로 적으면 오해를 부른다) |
| series.unit | string | 실제로 쓰인 시계열 단위 |
| series.unit_downgraded | boolean | 시간 단위를 요청했으나 그 집계의 보존기간 밖이라 일 단위로 낮춘 경우 `true` |
| series.points[] | array | `{ts, visits, visitors, pageviews, bounces, bounce_rate, avg_time, pages_per_visit, new_visitors, returning_visitors}`. 신규·재방문은 쿠키를 쓰는 모드에서만 센다 — 칸의 행이 모두 익명 모드면 `null`(모름), 하나라도 값이 있으면 값이 있는 행만 더한다. 기록이 없는 칸도 0 으로 채워 한 칸씩 나간다 — 건너뛰면 차트가 앞뒤 칸을 곧장 이어 그려 추이가 거짓이 된다. 지금 시간·오늘 뒤의 칸은 만들지 않는다. 기간 전체에 기록이 하나도 없으면 빈 배열이다 (화면이 「선택 기간에 기록이 없습니다」를 띄운다) |
| top.pages / top.referrers / top.countries | array | 상위 목록 (설정 `top_n_overview` 건) |
| realtime.count / realtime.visitors[] | integer / array | 현재 접속자 수와 최근 목록. 이 경로에서는 주소를 담지 않는다 |
| meta.mode | string | 개인정보 수집 모드 (full, consent, anonymous) |
| meta.consent_source | string | 동의 출처 — `provider`(동의 관리 제공자가 있다) · `none`(없다). `none` 이면 동의 모드라도 익명으로 모은다 — 화면이 그 사실을 경고로 띄운다 |
| meta.consent_selection | string | 동의 제공자 선택 상태 — `none`·`auto`·`selected`·`ambiguous`·`missing`. `ambiguous`·`missing` 이면 출처는 `provider` 지만 모든 방문자가 「동의 확인 불가」로 판정된다 |
| meta.collection_enabled | boolean | 수집 마스터 스위치. 꺼져 있으면 화면 상단에 안내를 띄운다 |
| meta.geoip_enabled | boolean | 지역 조회 사용 여부 (꺼져 있으면 지역 화면이 안내만 보인다) |
| meta.queue_backlogged / meta.queue_pending_jobs | boolean / integer | 큐 적체. 워커가 멈추면 비콘은 204 를 받고도 원장에 닿지 않는다 |
| freshness | object | [집계 신선도](#집계-신선도--화면-열-때-집계와-datafreshness) |

> 화면 첫 장이 한 번에 받아 가는 응답입니다 — 이걸 여섯 번 나눠 부르면 첫 화면에서 요청이 여섯 번 나갑니다.
<!-- @generated:end:api.admin.analytics.overview -->

---

### GET /api/admin/analytics/series
<!-- @generated:start:api.admin.analytics.series -->
- **라우트명**: `api.admin.analytics.series`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\ReportController@series`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.analytics.read` → `analytics.archive_on_view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| preset | query | string | 아니오 | today, yesterday, 7d, 30d, 90d, this_month, last_month, this_year | 기간 프리셋. 기본 `7d` |
| from | query | date | 아니오 | Y-m-d | 조회 시작일. 주면 `preset` 보다 우선한다 (주소를 공유했을 때 보던 화면이 그대로 열려야 하므로) |
| to | query | date | 아니오 | Y-m-d, `from` 이후 | 조회 종료일. `from` 만 주면 그 하루 |
| unit | query | string | 아니오 | auto, hour, day, week, month, year | 시계열 단위. 기본 `auto` (구간 길이로 고름) |
| exclude[] | query | array | 아니오 | admin, member | 합산에서 뺄 방문자 유형. 화면의 「관리자 제외」·「회원 제외」 토글 |
| format | query | string | 아니오 | json, csv | `csv` 면 추이를 파일로 내려받는다 |


**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| unit | string | 실제 단위 (auto 는 구간 ≤2일 hour, ≤92일 day, ≤730일 week, 그 밖 month) |
| unit_downgraded | boolean | 시간 집계 보존기간 밖을 시간 단위로 요청해 일 단위로 낮춘 경우 `true` |
| points[] | array | `{ts, visits, visitors, pageviews, bounces, bounce_rate, avg_time, pages_per_visit, new_visitors, returning_visitors}` (신규·재방문의 `null` 규칙은 개요와 같다). 기록이 없는 칸도 0 으로 채워 한 칸씩 나간다 — 건너뛰면 차트가 앞뒤 칸을 곧장 이어 그려 추이가 거짓이 된다. 지금 시간·오늘 뒤의 칸은 만들지 않는다. 기간 전체에 기록이 하나도 없으면 빈 배열이다 (화면이 「선택 기간에 기록이 없습니다」를 띄운다) |
| freshness | object | [집계 신선도](#집계-신선도--화면-열-때-집계와-datafreshness) |

**CSV 내려받기** — `?format=csv` 는 `ts, visits, visitors, pageviews, bounce_rate,
avg_time_seconds, pages_per_visit, new_visitors, returning_visitors` 아홉 열입니다(신규·재방문을 알 수 없는
칸은 빈 칸). 화면의 그래프를 운영자가 스프레드시트에서
자기 방식대로 다시 그릴 수 있어야 하기 때문입니다. BOM·CRLF 규약은 목록 내려받기와 같고
파일명은 `analytics-series-{from}_{to}.csv` 입니다.
<!-- @generated:end:api.admin.analytics.series -->

---

### GET /api/admin/analytics/reports/{report}
<!-- @generated:start:api.admin.analytics.reports.show -->
- **라우트명**: `api.admin.analytics.reports.show`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\ReportController@show`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.analytics.read` → `analytics.archive_on_view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| preset | query | string | 아니오 | today, yesterday, 7d, 30d, 90d, this_month, last_month, this_year | 기간 프리셋. 기본 `7d` |
| from | query | date | 아니오 | Y-m-d | 조회 시작일. 주면 `preset` 보다 우선한다 (주소를 공유했을 때 보던 화면이 그대로 열려야 하므로) |
| to | query | date | 아니오 | Y-m-d, `from` 이후 | 조회 종료일. `from` 만 주면 그 하루 |
| unit | query | string | 아니오 | auto, hour, day, week, month, year | 시계열 단위. 기본 `auto` (구간 길이로 고름) |
| exclude[] | query | array | 아니오 | admin, member | 합산에서 뺄 방문자 유형. 화면의 「관리자 제외」·「회원 제외」 토글. **봇 리포트(`bot_name`·`bot_pages`)에는 적용되지 않습니다** — 봇 행에는 뺄 사람 유형이 없어, 적용하면 목록이 통째로 비어 버립니다 |
| report | path | string | 예 | 리포트 이름 또는 별칭 | 무엇의 상위 목록인지. 리포트 이름(`page_url`·`referrer_name`·`browser`·`country`·`city`·`site_search`·`download`·`outlink`·`bot_name`·`campaign_medium`·`search_tab`·`event_name`·`visit_duration`·`visit_pages` 등) 또는 별칭(`entry_pages`·`exit_pages`·`bot_pages`·`goals`). 확장 분류 기준은 `ext:{확장 식별자}:{키}` — 지금 등록된 것이거나 집계 행이 남아 있는 것만 받는다([등록 목록](#get-apiadminanalyticsregistered)) |
| page | query | integer | 아니오 | min 1 | 쪽 번호 |
| per_page | query | integer | 아니오 | min 1, max 100 | 쪽당 건수. 기본값은 설정의 「상세 화면 상위 목록 건수」(`top_n_detail`, 기본 25) |
| sort | query | string | 아니오 | visits, hits, entries, exits | 정렬 기준. 기본 `visits` |
| q | query | string | 아니오 | max 255 | 라벨 부분 검색. 입력한 글자 그대로 찾는다(`%`·`_` 도 글자). 주소 리포트(페이지·진입·이탈·봇 페이지)은 화면에 풀어 보인 모양(「테스트」)으로 찾아도 인코딩돼 저장된 라벨(「%ED%85%8C…」)이 잡힌다. 유입 종류·기기 종류는 화면 이름(「직접 유입」·「데스크톱」)으로 찾아도 저장 코드(`direct`·`desktop`)가 잡힌다 |
| format | query | string | 아니오 | json, csv | `csv` 면 표를 파일로 내려받는다 |


**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| report | string | 요청한 리포트 이름 |
| data[].label | string | 저장된 라벨 (기계가 읽는 값 — 국가 코드, 접두를 뗀 주소) |
| data[].label_display | string | 화면에 보일 문자열 (국가·지역·도시는 번역, 주소는 사이트 호스트를 떼고 퍼센트 인코딩을 푼 값 — 풀어서 올바른 UTF-8 이 아니거나 제어 문자가 섞이면 원문. `label` 은 저장값 그대로). 목표(`goal`)는 선언한 이름(「회원가입」), 유입 종류·기기 종류는 요청 로케일의 이름(「직접 유입」·「데스크톱」, 모르는 코드는 원값), 확장 분류 기준은 그 확장의 라벨 풀이 함수가 돌려준 이름 — 확장이 꺼졌으면 원값 |
| data[].visits / hits | integer | 이 라벨이 나온 방문 수 / 액션 수. 같은 방문이 한 라벨을 여러 번 봐도 방문은 1 이다 |
| data[].entries / exits | integer | 이 라벨로 시작한 / 끝난 방문 수 |
| data[].bounce_rate | float | 이탈률 (%) |
| data[].avg_time | integer | 방문당 평균 체류 (초) |
| data[].pages_per_visit | float | 방문당 조회 수 |
| data[].extra | object | 리포트별 부가 값을 기간 내내 키마다 더한 값. 내부 검색어의 `no_result`(결과 없음 건수)처럼 리포트가 스스로 싣는 값이며, 부가 값이 없는 리포트는 빈 객체 `{}` 다 — 화면이 언제나 같은 모양을 읽도록 키 자체는 늘 있다 |
| pagination.has_more | boolean | 다음 쪽이 있는지 |
| freshness | object | [집계 신선도](#집계-신선도--화면-열-때-집계와-datafreshness) |

**새 리포트 다섯** — `campaign_medium`(utm_medium, 매체만 붙은 주소도 센다) · `search_tab`(사이트 검색을 한 탭,
`all`·`posts`·`products`·`pages` 는 번역하고 그 밖은 원값, 하루 50종 상한) · `event_name`(확장이
`G7Core.analytics.trackEvent()` 로 보낸 이벤트 이름, 하루 500종 상한) · `visit_duration`(체류시간 구간) ·
`visit_pages`(본 페이지 수 구간, 페이지를 한 번이라도 본 방문만). **구간 두 리포트는 `sort`·`page`·`per_page` 를
무시하고** 구간이 작은 것부터 전부 돌려줍니다(`has_more=false`). 저장 라벨은 경계를 담은 문자열(체류 `0-10`·`10-30`·
`1800+` = (하한, 상한], 첫 구간만 [0, 상한] / 페이지 `1`·`6-7`·`21+`)이고 `label_display` 가 「1분 ~ 3분」·
「6~7페이지」처럼 풉니다. 경계는 설정 `duration_bucket_bounds`·`pages_bucket_bounds` 로 바꾸며 바꾼 뒤 집계부터
적용됩니다.

**CSV 내려받기** — `?format=csv` 는 `text/csv; charset=UTF-8` 스트림입니다.
공통 아홉 열(`label, label_display, visits, hits, entries, exits, bounce_rate, avg_time_seconds,
pages_per_visit`) 뒤에 **그 리포트의 부가 열**이 붙습니다. 내부 검색어(`site_search`)는
`no_result_count`(결과 없음 건수), 목표(`goal`)는 `revenue_sum`(전환 금액 합계) 한 열이며,
부가 값이 없는 리포트는 아홉 열 그대로입니다. 확장 분류 기준은 `core.analytics.csv_columns` 필터로
자기 `extra` 키를 열로 더할 수 있고(코어 아홉 열과 같은 이름은 받지 않습니다),
`core.analytics.csv_row` 로 값을 사람이 읽는 단위로 바꿀 수 있습니다(열 수가 바뀌면 무시).
`revenue_sum` 은 집계가 센트 정수로 담아 둔
값을 소수점 두 자리로 되돌린 것입니다 — `extra` 가 정수 칸이라 금액을 그대로 담으면
반올림으로 값이 샙니다.
화면에서 보이던 값이 파일에서 빠지면 운영자는 내려받은 표가 틀린 것인지 화면이 틀린
것인지 판단할 근거가 없으므로, 리포트가 `extra` 에 담는 값은 열로 내보냅니다.
파일 앞에 바이트 순서 표식(BOM)을 답니다. 없으면 엑셀이 UTF-8 로 읽지 않아 한글이 전부
깨집니다. 줄 끝은 CRLF, 파일명은 `analytics-{report}-{from}_{to}.csv` 입니다(확장 분류 기준 이름의
`:` 는 윈도 파일명에 쓸 수 없어 `-` 로 바꿉니다).
내려받기는 쪽을 나누지 않습니다 — 운영자가 받으려는 것은 화면에 보이던 25행이 아니라
그 기간 전체이기 때문입니다.

없는 리포트 이름은 **404** 입니다. `ext:` 이름은 지금 등록된 것이거나 집계 행이 남아 있는
것만 받습니다 — 확장을 끄거나 데이터를 남기고 지워도 쌓인 집계는 원값 라벨로 읽히고, 등록도
집계도 없는 이름은 404 입니다.
<!-- @generated:end:api.admin.analytics.reports.show -->

---

### GET /api/admin/analytics/reports/{report}/series
<!-- @generated:start:api.admin.analytics.reports.series -->
- **라우트명**: `api.admin.analytics.reports.series`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\ReportController@rowSeries`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.analytics.read` → `analytics.archive_on_view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| report | path | string | 예 | 리포트 이름 또는 별칭 | [상위 목록](#get-apiadminanalyticsreportsreport)과 같은 이름 규약 |
| label | query | string | 예 | 최대 512자 | 목록 행의 **저장 라벨**(`data[].label`) — 화면용 `label_display` 가 아니다 |
| preset · from · to | query | — | 아니오 | 기간 규약 | 목록과 같다 |
| unit | query | string | 아니오 | auto, hour, day, week, month, year | 기본 `auto` — 92일까지 `day`, 그 뒤 `week`(기간 상한이 366일이라 월은 생기지 않는다). `hour` 는 차원 집계가 일 단위뿐이라 `day` 로 낮춘다 |
| exclude[] | query | array | 아니오 | admin, member | 목록과 같다 — 봇 리포트는 목록처럼 제외 토글을 적용하지 않고 봇 유형 행만 읽는다(통계 화면에서 켠 토글이 봇 화면으로 따라와도 추이가 비지 않게) |

**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| report · label | string | 요청 그대로 |
| label_display | string | 목록과 같은 풀이(주소는 호스트를 떼고 풀기, 국가 이름, 구간 문장) — 창 제목에 쓴다 |
| from · to | string | 실제 조회 기간 |
| unit | string | 실제 단위 |
| unit_downgraded | boolean | `hour` 를 요청해 `day` 로 낮췄으면 `true` |
| points[] | array | `{ts, visits, hits, bounces, total_time_seconds, bounce_rate, avg_time}` — 유형 행을 날짜로 묶어 더한 값. 기록이 없는 칸은 0 으로 채우고, 기간 전체에 기록이 없으면 빈 배열 |
| freshness | object | [집계 신선도](#집계-신선도--화면-열-때-집계와-datafreshness) |

없는 리포트 이름은 **404**, `label` 이 없으면 **422** 입니다.
<!-- @generated:end:api.admin.analytics.reports.series -->

---

### GET /api/admin/analytics/registered
<!-- @generated:start:api.admin.analytics.registered -->
- **라우트명**: `api.admin.analytics.registered`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\ReportController@registered`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.analytics.read`

**요청 파라미터**

_없음._

**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| dimensions[].key | string | 원장 이름 `{확장 식별자}:{키}` |
| dimensions[].dimension | string | 리포트 이름 `ext:{확장 식별자}:{키}` — 상위 목록 API 의 `{report}` 로 그대로 쓴다 |
| dimensions[].label | string | 현재 로케일의 이름 |
| dimensions[].owner_type / owner_identifier | string | 공급 확장 (module·plugin, 식별자) |
| dimensions[].scope | string | `action`(액션마다) 또는 `visit`(방문에 한 번) |
| dimensions[].value_type / values | mixed | 값 종류(`int`·`string`·`enum`)와 enum 허용값 |
| dimensions[].max_cardinality | integer | 하루 집계에 남기는 라벨 수 상한 |
| dimensions[].pii | boolean | 개인정보 분류 기준 여부 — 집계하지 않아 리포트에 나오지 않고(원장에만 남음), 익명 모드에서 적재하지 않고 소급 익명화 때 지운다 |
| dimensions[].inactive | boolean | 꺼졌거나 데이터를 남기고 지운 확장의 분류 기준이면 `true`(집계가 남은 것만 실린다). 이때 `label` 은 원값 키에 「꺼진 확장」 표시를 붙인 것이고 `owner_type`·`scope`·`value_type`·`max_cardinality` 는 `null` 이다. 활성 확장 분류 기준에는 이 키가 없다 |
| goals[].key | string | 정규화 목표 키 `{공급자}.{키}` (코어는 `core.`) |
| goals[].label / description | string | 현재 로케일의 이름·설명 |
| goals[].owner_type / owner_identifier | string | `core`·`module`·`plugin` 과 식별자 |
| goals[].category / dedupe | string | 성격 분류와 중복 판정 방식(`every_time`·`once_per_visit`·`once_per_visitor`) |
| goals[].attributes | object | 선언된 속성 스키마 — 수집이 이 스키마로 검증한다 |
| goals[].attribution_window_days / dimensions | mixed | 확장이 선언한 귀속 기간(표시용)과 함께 볼 자기 분류 기준 키 |

관리자 › 방문자 통계 › 검색어·다운로드·전환 화면의 「확장별 분류」·「전환」 탭이 선택지로 씁니다. 등록부는 DB 에 적재하지 않고
활성 확장에서 매번 모으므로, 확장을 끄면 그 목표는 목록에서 빠집니다. 분류 기준은 집계가 남아 있으면
`inactive: true` 로 계속 실려 쌓인 수치를 고를 수 있습니다.
<!-- @generated:end:api.admin.analytics.registered -->

---

### GET /api/admin/analytics/referrer-urls
<!-- @generated:start:api.admin.analytics.referrer-urls -->
- **라우트명**: `api.admin.analytics.referrer-urls`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\ReportController@referrerUrls`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.analytics.read`

유입 사이트 목록에서 고른 도메인 **한 곳**의 전체 유입 주소입니다. 집계 표에는 호스트까지만 쌓이므로
이 한 갈래만 원장을 직접 읽습니다 — 그래서 호스트가 필수이고, 원장 보존 기간 밖의 날짜는 비어 옵니다.

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| host | query | string | 예 | max 100 | 유입 사이트 목록 행의 이름(`referrer_name`) 그대로 |
| from / to / preset / exclude | query | — | 아니오 | 기간 규약과 같음(최대 366일) | 조회 기간·제외 유형 |
| page | query | integer | 아니오 | min 1 | 쪽 번호 |
| per_page | query | integer | 아니오 | 1~100 | 쪽당 건수(없으면 `top_n_detail`) |

**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| host · from · to | string | 조회한 호스트와 실제 기간 |
| data[].url | string | 유입 주소 원문 |
| data[].visits | integer | 그 주소로 들어온 방문 수 |
| data[].hits | integer | 그 방문들의 페이지뷰 합 |
| pagination.page · per_page · has_more | — | 쪽 정보 |

호스트가 없으면 **422** 입니다 — 호스트 없이 원장 전체를 주소별로 묶는 질의가 되지 않게 합니다.
CSV 는 제공하지 않습니다(내려받기는 사이트 목록 리포트가 맡습니다).
<!-- @generated:end:api.admin.analytics.referrer-urls -->

---

### GET /api/admin/analytics/patterns
<!-- @generated:start:api.admin.analytics.patterns -->
- **라우트명**: `api.admin.analytics.patterns`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\ReportController@patterns`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.analytics.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| preset | query | string | 아니오 | today, yesterday, 7d, 30d, 90d, this_month, last_month, this_year | 기간 프리셋. 기본 `7d` |
| from | query | date | 아니오 | Y-m-d | 조회 시작일. 주면 `preset` 보다 우선한다 (주소를 공유했을 때 보던 화면이 그대로 열려야 하므로) |
| to | query | date | 아니오 | Y-m-d, `from` 이후 | 조회 종료일. `from` 만 주면 그 하루 |
| unit | query | string | 아니오 | auto, hour, day, week, month, year | 시계열 단위. 기본 `auto` (구간 길이로 고름) |
| exclude[] | query | array | 아니오 | admin, member | 합산에서 뺄 방문자 유형. 화면의 「관리자 제외」·「회원 제외」 토글 |


**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| weekday[] | array | 요일 일곱 칸 `{dow(0=일), days, visits, visitors, pageviews}` |
| hour[] | array | 시간대 스물네 칸 `{hour, days, visits, visitors, pageviews}` |
| hourly_available_from | string | 시간대 패턴을 볼 수 있는 가장 이른 날 |
| from · to | string | 실제 조회 기간 |
| freshness | object | [집계 신선도](#집계-신선도--화면-열-때-집계와-datafreshness) |

> 값은 합이 아니라 **평균**입니다. 기간에 월요일이 다섯 번, 화요일이 네 번 들어 있으면
> 합계만으로는 월요일이 더 붐비는 것처럼 보이기 때문입니다. `days` 가 그 나눈 횟수입니다.
>
> 시간대 패턴은 시간 집계의 보존기간 안에서만 나옵니다 — 그 밖은 재료가 이미 지워졌습니다.
<!-- @generated:end:api.admin.analytics.patterns -->

---

### GET /api/admin/analytics/bots
<!-- @generated:start:api.admin.analytics.bots -->
- **라우트명**: `api.admin.analytics.bots`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\ReportController@bots`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.analytics.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| preset | query | string | 아니오 | today, yesterday, 7d, 30d, 90d, this_month, last_month, this_year | 기간 프리셋. 기본 `7d` |
| from | query | date | 아니오 | Y-m-d | 조회 시작일. 주면 `preset` 보다 우선한다 (주소를 공유했을 때 보던 화면이 그대로 열려야 하므로) |
| to | query | date | 아니오 | Y-m-d, `from` 이후 | 조회 종료일. `from` 만 주면 그 하루 |
| unit | query | string | 아니오 | auto, hour, day, week, month, year | 시계열 단위. 기본 `auto` (구간 길이로 고름) |
| exclude[] | query | array | 아니오 | admin, member | 합산에서 뺄 방문자 유형. 화면의 「관리자 제외」·「회원 제외」 토글. **봇 리포트(`bot_name`·`bot_pages`)에는 적용되지 않습니다** — 봇 행에는 뺄 사람 유형이 없어, 적용하면 목록이 통째로 비어 버립니다 |
| page | query | integer | 아니오 | 1 이상 | 쪽 번호. 기본 1 |
| per_page | query | integer | 아니오 | 1~100 | 한 쪽에 담을 행 수. 기본값은 설정의 `top_n_detail` |
| sort | query | string | 아니오 | visits, hits, entries, exits | 정렬 기준 열 |
| q | query | string | 아니오 | — | 라벨 검색어 |
| format | query | string | 아니오 | json, csv | `csv` 면 집계 표를 파일로 내려받습니다 |


**응답 필드** (`data` 내부)

`reports/bot_name` 과 같은 모양에 기간 합계와 최근 요청 목록이 더 붙습니다.

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| data[] | array | 봇 이름별 방문·조회 수 (`reports/bot_name` 과 같은 행 모양) |
| pagination | object | 쪽 정보 |
| totals | object | 기간 전체 합계 — `hits`(봇 요청 수)·`labels`(봇 종류 수). `data[]` 는 쪽으로 잘려 있어 그 행을 더하면 이 쪽의 합일 뿐이므로, 「봇 요청」·「봇 종류」 같은 기간 숫자는 이 값을 읽는다. 쪽·검색어와 무관하다 |
| freshness | object | [집계 신선도](#집계-신선도--화면-열-때-집계와-datafreshness) — 봇 화면 요청 하나는 집계를 한 번만 일으킨다 |
| recent_hits[] | array | **고른 기간 안의** 최근 봇 요청 50건(최신순, 사이트 표준시 하루 경계) — `id`(행 식별자)·`bot_name`·`url`·`ip`·`cache_status`·`occurred_at`. 화면의 다른 숫자와 같은 기간을 따른다. 집계는 「어느 봇이 얼마나」까지만 답하므로, 어떤 주소를 언제 긁어 갔는지를 함께 보여 운영자가 차단 여부를 판단할 수 있게 한다 |

`format=csv` 로 받을 때는 집계 표만 나갑니다 — 내려받기는 원장 목록을 싣지 않습니다.
<!-- @generated:end:api.admin.analytics.bots -->

---

### GET /api/admin/analytics/realtime
<!-- @generated:start:api.admin.analytics.realtime -->
- **라우트명**: `api.admin.analytics.realtime`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\RealtimeController@index`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.analytics.read` (주소는 `core.analytics.privacy` 보유 시에만)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| minutes | query | integer | 아니오 | min 1, max 60 | 「지금」으로 볼 구간(분). 기본은 설정 `realtime_window_minutes` |


**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| count | integer | 그 구간에 움직인 서로 다른 방문자 수 |
| window_minutes | integer | 실제로 쓰인 구간 |
| visitors[].visitor_key | string | 방문자 키 앞 8자 (전체는 내보내지 않는다) |
| visitors[].page | string\|null | 마지막으로 본 페이지 — 리포트의 `label_display` 와 같은 화면용 모양(사이트 호스트 제거·인코딩 해제) |
| visitors[].last_action_at | string | 마지막 활동 시각 |
| visitors[].visitor_type / country_code / device_type / browser_name | string\|null | 방문자 구분과 환경 |
| visitors[].country_name / device_type_label | string\|null | **화면용** 이름 — 국가 이름(요청 로케일, 번역이 없으면 코드)·기기 이름. 코드는 위 필드가 그대로 싣는다 |
| visitors[].ip | string\|null | 주소. **`core.analytics.privacy` 권한이 있을 때만** 채워지고, 없으면 `null` |
| visitors[].extensions | object | 확장이 `core.analytics.realtime_fields` 필터로 이 방문에 붙인 값 (`{식별자: 값}`). 코어 필드를 덮지 못하게 한 칸 아래에 둔다 |

> 집계 표가 아니라 원장을 직접 읽습니다 — 집계는 한 시간에 한 번 도는데, 지금 누가 있는지는 그 사이를 기다릴 수 없습니다.
<!-- @generated:end:api.admin.analytics.realtime -->

---

### GET /api/admin/analytics/diagnostics
<!-- @generated:start:api.admin.analytics.diagnostics -->
- **라우트명**: `api.admin.analytics.diagnostics`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\DiagnosticsController@index`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.analytics.read`

**요청 파라미터**

_없음._

**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| collection.enabled / mode / consent_source | mixed | 수집이 켜져 있는지, 어떤 모드로, 동의를 어디서 받는지 |
| collection.consent_selection | string | 동의 제공자 선택 상태 — `none`·`auto`·`selected`·`ambiguous`·`missing`. 화면의 동의 출처 배지가 읽는다(`ambiguous`·`missing` 이면 「제공자 선택 필요」) |
| collection.consent_provider_label | string\|null | 답하는 동의 제공자 이름 (`auto`·`selected` 일 때만) |
| collection.effective_mode | string | 실제로 도는 사이트 모드. 운영자가 고른 `mode` 와 다를 수 있다(익명 폴백). 동의 관리 플러그인 때문에 **동의 전 방문자만** 익명으로 모일 때는 `full` 이고 사유가 `anonymous_fallback_reason` 에 실린다 |
| collection.anonymous_fallback_reason | string\|null | 익명으로 내려간 사유. `consent_source_missing`(동의 모드인데 물어볼 곳이 없음) · `consent_plugin_blocks_cookies`(동의 관리 플러그인이 동의 전 방문자 쿠키를 막음). 내려가지 않았으면 `null` |
| collection.last_tracked_at | string\|null | 마지막으로 무언가 세어진 때 |
| collection.tracked_today | integer | 오늘 순방문자 |
| collection.excluded_today / excluded_yesterday | object | 사유별 제외 건수. 캐시에 살기 때문에 캐시를 비우거나 서버를 다시 띄우면 사라진다 — 화면이 그 사실을 함께 적는다. 확장이 등록한 사유는 `ext:{식별자}:{사유}` 키로 함께 온다 |
| collection.excluded_reason_labels | object | 확장 제외 사유의 표시 이름 (`ext:…` 키 → 현재 로케일 이름). 화면 번역 파일에 없는 이름이라 서버가 싣는다 |
| queue.connection / pending_jobs / failed_jobs | mixed | 큐 상태. `countable` 이 `false` 면 데이터베이스 큐가 아니라 여기서 셀 수 없다는 뜻이고, 0 을 「비어 있다」로 읽으면 안 된다 |
| queue.backlogged / worker_hint | boolean | 적체 판정. 수집은 큐를 타지 않으므로 여기 쌓이는 것은 **집계·익명화 배치**다 — 워커가 멈추면 방문은 계속 쌓이는데 집계 숫자만 그 시점에 멈춘다 |
| archive.last_hour_run / last_day_run / lag_hours / running_run_id | mixed | 집계가 밀렸는지. 밀리면 화면 숫자가 그 시점에 멈춘 채 그럴듯하게 남는다 |
| archive.scheduler | object | `{alive, last_run_at}` — [집계 신선도](#집계-신선도--화면-열-때-집계와-datafreshness)의 스케줄러 판정과 같다. 화면 열 때 집계가 숫자를 맞춰도 어제 확정·정리·메일 보고서는 스케줄러만 돌린다 |
| archive.on_view_enabled | boolean | 화면 열 때 집계 켜짐 여부 |
| storage | object | 표별 행 수·크기와 경고 한계 초과 여부 |
| geoip.enabled / db_present | boolean | 지역 조회 사용 여부와 데이터베이스 존재 |
| trusted_proxy | object | 프록시 신뢰 설정. 설정하지 않으면 프록시 뒤 전 방문자가 주소 하나로 뭉친다 |
| broadcasting.configured | boolean | 실시간 푸시 가능 여부 (아니면 화면이 폴링으로 돈다) |
| cloud_ranges | object | 클라우드 대역 차단 사용 여부와 목록 파일 상태. `updated_at` 은 다른 통계 시각과 같은 **사이트 표준시**(`Y-m-d H:i:s`, 오프셋 없음) |
| timezone | string | 사이트 표준시 이름. 통계 화면의 모든 시각이 이 기준이다 |
| timezone_differs | boolean | 보는 사람의 시간대가 사이트와 다른지. 참이면 화면이 「이 시각은 사이트 표준시 기준」을 알린다 |
| failures_24h | integer | 최근 24시간 적재 실패 건수 |
| declaration_warnings | string[] | 확장이 잘못 선언해 버려진 목표·분류 기준의 사유(없으면 빈 배열). 진단 화면 맨 위 경고 줄이 읽는다 |
| extensions | object | 확장이 `core.analytics.diagnostics_sections` 필터로 붙인 자기 섹션 (`{식별자: 섹션}`). 코어 섹션은 덮지 못한다 — 화면은 확장이 레이아웃 확장 지점에 둔 조각으로 읽는다 |
<!-- @generated:end:api.admin.analytics.diagnostics -->

---

### GET /api/admin/analytics/collect-failures
<!-- @generated:start:api.admin.analytics.collect-failures -->
- **라우트명**: `api.admin.analytics.collect-failures`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\DiagnosticsController@failures`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.analytics.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| reason | query | string | 아니오 | validation, max_actions, db_error, listener_exception, unknown_goal, unknown_dimension | 실패 사유 필터 |
| page | query | integer | 아니오 | min 1 | 쪽 번호 |
| per_page | query | integer | 아니오 | min 1, max 100 | 쪽당 건수 |


**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| data[] | array | 실패 기록 (사유·메시지·주소·요청 IP·브라우저 문자열·발생 시각). 페이로드 발췌는 목록에 싣지 않습니다 — 행이 커져 목록 조회가 느려집니다. 요청 IP 는 `core.analytics.privacy` 권한이 있을 때만 채워지고 없으면 `null` 입니다(현재 접속자와 같은 경계). 행마다 `ip_masked`(그 주소가 지금 마스킹 설정대로 가려진 값인가 — `true`·`false`, 마스킹이 꺼졌거나 주소가 없으면 `null`)가 붙습니다. 설정을 켜며 소급을 건너뛴 기존 행은 `false` 입니다 |
| pagination | object | 쪽 정보 |
<!-- @generated:end:api.admin.analytics.collect-failures -->

---

### GET /api/admin/analytics/archive-runs · GET /api/admin/analytics/archive-runs/{run}
<!-- @generated:start:api.admin.analytics.archive-runs.index -->
- **라우트명**: `api.admin.analytics.archive-runs.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\ArchiveRunController@index · @show`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.analytics.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| run_type | query | string | 아니오 | hour, day, reaggregate, anonymize, purge, extension_purge | 실행 종류 필터 |
| status | query | string | 아니오 | running, success, failed, skipped | 상태 필터 |
| page / per_page | query | integer | 아니오 | min 1 / max 100 | 쪽 정보 |
| run | path | integer | 예 (단건) | — | 실행 이력 ID |


**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| run_type / trigger / status | string | 무엇을, 누가 시켜서(`scheduled` 예약 — 스케줄러 등록 실행만 `--scheduled` 로 이렇게 적힌다 · `manual` 수동 — CLI 를 손으로 돌린 것 포함 · `on_view` 관리자가 통계 화면을 열 때), 어떻게 끝났는지 — 코드다. 화면은 템플릿 언어 파일로 이름을 붙인다 |
| period_start / period_end | string\|null | 대상 기간 |
| processed_visits / processed_actions / chunks | integer | 처리량 |
| progress_percent | integer | 진행률. 화면이 이 값을 2초마다 물어본다 |
| started_at / finished_at | string\|null | 시작·종료 시각 |
| error | string\|null | 실패·건너뜀 사유. 실패 사유(예외 원문)는 **단건 조회에만** 실립니다(목록은 행을 가볍게 둡니다). 건너뜀 사유는 목록에도 실리고, 보는 사람의 언어로 바뀌어 나옵니다 |
| skip_reason | string\|null | 건너뛴 이유 코드 — `locked`(같은 기간을 다른 집계가 돌리는 중) · `finalized`(이미 확정된 날) · `beyond_retention`(원장 보존 기간 밖). 건너뜀이 아니면 `null` |

> 단건 조회는 없는 ID 에 **404** 를 돌려줍니다.
<!-- @generated:end:api.admin.analytics.archive-runs.index -->

**진행률 방송** — 실행 이력의 `progress_percent`·`status` 가 바뀔 때마다(시작 0% · 청크마다 ·
성공 100% · 실패) 관리자 채널 `core.admin.analytics` 로 `analytics.archive.progress` 이벤트가
나갑니다. 페이로드는 `{type: "archive_progress", data: <단건 조회의 data 와 같은 행>}` 이라
소켓으로 받은 값과 폴링으로 받은 값이 같은 모양입니다. 진단 화면은 소켓이 있으면 이
방송으로 진행률 바를 움직이고, 없으면(`diagnostics.broadcasting.configured=false`) 진단 API 의
`archive.running_run_id` 를 2초 간격으로 물어봅니다. 방송 드라이버가 꺼진 설치에서는 방송
시도 자체를 건너뜁니다(현재 접속자 방송과 같은 규약).

---

### POST /api/admin/analytics/archive-runs
<!-- @generated:start:api.admin.analytics.archive-runs.store -->
- **라우트명**: `api.admin.analytics.archive-runs.store`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\ArchiveRunController@store`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.analytics.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| run_type | body | string | 예 | hour, day, reaggregate | 무엇을 집계할지 |
| from | body | date | `reaggregate` 일 때 예 | Y-m-d | 재집계 시작일 |
| to | body | date | `reaggregate` 일 때 예 | Y-m-d, `from` 이후 | 재집계 종료일 |
| force | body | boolean | 아니오 | — | 이미 확정된 날도 다시 집계 |


**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| run_id | integer\|null | `hour`·`day` 는 그 자리에서 돌고 이력 번호를 돌려준다 |
| queued | boolean | `reaggregate` 는 워커에게 넘기므로 `true` — 화면은 이력 목록에서 진행률을 찾는다 |

응답은 **202 Accepted** 입니다. 같은 기간을 이미 집계 중이면 새 실행은 `skipped` 이력만 남기고 집계 표를 건드리지 않습니다.
원장 보존 기간이 지난 날(`오늘 − 보존일` 이전)도 `skipped` 로 남깁니다 — 원장이 이미 지워져 다시 계산하면
영구 보존되는 일·리포트별 집계를 빈 값으로 덮어쓰기 때문입니다. `force` 도 이 경계를 넘지 못합니다.
<!-- @generated:end:api.admin.analytics.archive-runs.store -->

---

### GET /api/admin/analytics/settings · PUT /api/admin/analytics/settings
<!-- @generated:start:api.admin.analytics.settings.show -->
- **라우트명**: `api.admin.analytics.settings.show`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\SettingsController@show · @update`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.analytics.update`

**요청 파라미터**

전체 설정 키는 [../analytics.md](../analytics.md) 「설정」 절의 표와 같고, PUT 은 **보낸 키만** 바꿉니다 (설정 화면이 탭 하나만 저장할 수 있도록).

주요 범위: `retention_days` 7~3650 · `hourly_retention_days` 7~3650 · `visit_timeout_minutes` 5~120 · `max_actions_per_visit` 50~10000 · `ip_mask_octets` 0~3 · `archive_chunk_size` 500~50000 · `realtime_poll_seconds` 10~300 · `realtime_window_minutes` 1~60 · `top_n_*` 3~100 · `visitor_cookie_days` 1~730 · `archive_on_view_max_visits` 1000~1000000.

집계 네 키: `archive_on_view_enabled`(boolean, 기본 `true`) · `archive_on_view_max_visits`(기본 50000) ·
`duration_bucket_bounds`(체류 경계 초, 쉼표 문자열, 기본 `10,30,60,180,600,1800`, 1~86400) ·
`pages_bucket_bounds`(페이지 수 경계, 기본 `1,2,3,4,5,7,10,14,20`, 1~1000). 경계는 숫자와 쉼표만(공백 없음),
최대 15개, **작은 것부터 겹치지 않게** 적어야 저장된다(`validation.analytics.bucket_bounds`).


**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| settings | object | 저장된 전체 설정 (GET·PUT 공통) |
| meta.retro_anonymize_available | boolean | **PUT 응답에만 담깁니다.** 개인정보 수준이 **더 엄격한 쪽으로** 바뀐 경우 `true` — 화면이 「기존 데이터도 소급 적용할까요?」 모달을 띄운다. 약해진 쪽으로 바꿀 때는 과거 기록이 이미 그보다 약한 정보를 담고 있어 소급할 것이 없다 |
| meta.ledger_rows | object | **PUT 응답에만 담깁니다.** 소급이 몇 건에 적용되는지 (방문·액션 행 수) |

`meta` 는 「무엇이 바뀌었는가」에 답하는 값이라 저장 응답에만 실립니다. GET 은 현재 설정만 돌려줍니다.

저장 규칙은 **전용 API 와 코어 설정 API(`PUT /api/admin/settings`) 양쪽에서 같습니다** —
규칙을 한쪽에만 두면 다른 길로 들어온 값이 검증 없이 저장되고, 그 값이 수집·정리·집계를
조용히 망가뜨립니다.

저장은 활동 로그에 남고 `core.analytics.settings_updated` 훅을, 모드가 바뀌면
`core.analytics.privacy_mode_changed` 훅을 함께 발화합니다.
<!-- @generated:end:api.admin.analytics.settings.show -->

---

### GET /api/admin/analytics/opt-out · POST /api/admin/analytics/opt-out · DELETE /api/admin/analytics/opt-out
<!-- @generated:start:api.admin.analytics.opt-out.show -->
- **라우트명**: `api.admin.analytics.opt-out.show`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\OptOutController@show · @store · @destroy`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.analytics.update`

**요청 파라미터**

_없음._

**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| opted_out | boolean | 이 브라우저가 통계에서 빠져 있는지 |

운영자가 자기 브라우저를 통계에서 빼는 길입니다 — 사이트를 손보는 동안 자기 방문이
인기 페이지 1위가 되는 것을 막습니다.

쿠키(`g7_analytics_optout`)는 **서버가** 심습니다. 동의 플러그인이 브라우저 쪽 쿠키
쓰기를 가로채므로 화면에서 직접 심으면 조용히 사라집니다. 다만 사용자 화면의 트래커가 이
값을 읽고 스스로 멈춰야 하므로 스크립트가 못 읽는 쿠키로 만들지는 않습니다 — 담긴 값은
「제외됨」이라는 사실 하나뿐입니다.
<!-- @generated:end:api.admin.analytics.opt-out.show -->

---

### GET /api/admin/analytics/data · DELETE /api/admin/analytics/data
<!-- @generated:start:api.admin.analytics.data.show -->
- **라우트명**: `api.admin.analytics.data.show`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\DataController@show · @destroy`
- **인증/권한**: 조회 `permission:admin,core.analytics.read` · 삭제 `permission:admin,core.analytics.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| confirm | body | boolean | 삭제 시 예 | accepted | 되돌릴 수 없음을 확인. 화면의 체크박스 |


**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| tables[] | array | 표별 행 수·데이터·인덱스 크기 (데이터베이스가 들고 있는 어림값. 방문·액션 두 표의 행 수만은 `exact` 와 같은 정확한 값) |
| tables[].table / label | string | 표 이름과 화면 이름(요청 로케일). 확장이 `core.analytics.storage_tables` 로 더한 표는 이름이 없어 `label` 이 표 이름과 같다 |
| exact | object | 방문·액션 두 표의 정확한 행 수 — 운영자가 지울지 말지를 정하는 값이라 정확히 센다 |
| over_limit | boolean | 경고 한계를 넘었는지 |
| driver_supported | boolean | 지금 드라이버가 표 용량 통계를 제공하는지 |
| deleted | object | (삭제 시) 대상별 삭제 건수. 확장이 참여했으면 `ext:{식별자}` 키로 그 확장이 지운 건수가 함께 온다 |

삭제는 되돌릴 수 없고 `confirm` 없이는 **422** 입니다. 원장·집계·봇 기록·실패 로그·
실행 이력·솔트를 모두 지우고 `core.analytics.data_purged` 훅을 발화하며 활동 로그에
남깁니다. 확장이 통계용으로 둔 표는 `core.analytics.purge_all` 필터가 **코어보다 먼저**
한 트랜잭션으로 지웁니다 — 코어 원장은 청크로 지우므로 한 트랜잭션에 담지 못하는 대신,
확장이 실패하면 코어 행을 하나도 건드리기 전에 멈춥니다.
<!-- @generated:end:api.admin.analytics.data.show -->

---

### POST /api/admin/analytics/privacy/anonymize
<!-- @generated:start:api.admin.analytics.privacy.anonymize -->
- **라우트명**: `api.admin.analytics.privacy.anonymize`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\PrivacyController@anonymize`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.analytics.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| mask_octets | body | integer | 조건부 | 0~3 | 주소에서 가릴 옥텟 수 |
| hash | body | boolean | 조건부 | — | 주소를 지우고 해시만 남긴다 |
| from / to | body | date | 아니오 | Y-m-d | 적용 구간 (없으면 전체) |
| confirm | body | boolean | 예 | accepted | 되돌릴 수 없음을 확인 |


**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| queued | boolean | 워커에게 넘겼음 — 화면은 실행 이력(`run_type=anonymize`)에서 진행률을 찾는다 |

응답은 **202 Accepted** 입니다. `mask_octets` 와 `hash` 중 하나는 반드시 지정해야
합니다(둘 다 없으면 **422**) — 무엇을 가릴지 정하지 않은 소급은 되돌릴 수 없는 빈 작업입니다.

**집계 표는 건드리지 않습니다.** 집계에는 개인을 가리키는 값이 없어 지울 것이 없고,
함께 지우면 그 기간 통계가 통째로 사라집니다.
<!-- @generated:end:api.admin.analytics.privacy.anonymize -->

---

### GET /api/admin/analytics/privacy/subjects · POST /api/admin/analytics/privacy/subjects/export · DELETE /api/admin/analytics/privacy/subjects
<!-- @generated:start:api.admin.analytics.privacy.subjects -->
- **라우트명**: `api.admin.analytics.privacy.subjects`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\PrivacyController@subjects · @export · @deleteSubject`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.analytics.privacy`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ip | query/body | string | 셋 중 하나 | IP | 주소로 찾는다 |
| user_id | query/body | integer | 셋 중 하나 | min 1 | 회원 번호로 찾는다. 회원 ID 가명화를 켰거나 소급 익명화를 돌린 구간은 번호 대신 해시로 저장되는데, 같은 번호를 넣으면 두 구간이 함께 나온다 |
| visitor_key | query/body | string | 셋 중 하나 | max 64 | 방문자 키로 찾는다 |
| from / to | query/body | date | 아니오 | Y-m-d | 기간 제한 |
| confirm | body | boolean | 삭제 시 예 | accepted | 되돌릴 수 없음을 확인 |


**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| visits[] | array | 찾은 방문 — `visit_id`·`visitor_key`·`visit_date`·`first_action_at`·`last_action_at`·`ip`·`ip_masked`(그 주소가 지금 마스킹 설정대로 가려진 값인가, 마스킹이 꺼졌으면 `null`)·`visitor_type`·`pageviews_count`·`actions_count`(화면의 표가 그리는 값). 내보내기는 각 방문의 액션 타임라인과 전환을 함께 담는다 |
| notice | string\|null | 찾기의 한계 안내 (익명 모드 구간은 최근 이틀치만 주소로 찾을 수 있다) |
| export_filename | string | 내보내기 파일명 `analytics-subject-{yyyymmdd}.json`(사이트 기준 날짜). 내보내기를 토큰 동반 blob 으로 받는 화면이 저장 이름으로 쓴다 — 검색값을 파일명에 넣지 않기 위해서다 |
| deleted | object | (삭제 시) `{visits, actions, conversions, extensions}` — `extensions` 는 확장이 같은 트랜잭션에서 지운 건수 `{식별자: 건수}` |

**내보내기는 파일로 옵니다** — `POST …/subjects/export` 는 봉투에 담은 JSON 이 아니라
`application/json; charset=UTF-8` + `Content-Disposition: attachment; filename="analytics-subject-{yyyymmdd}.json"`
으로 흘려보냅니다. 열람권 이행의 산출물이라 운영자가 요청한 사람에게 그대로 건넬 수
있어야 하기 때문입니다. 본문은 `{extensions, exported_at, subject, notice, visits[]}` 입니다.
방문·액션·전환마다 확장이 넣은 `custom_dimensions` 가 함께 실리고, `extensions` 에는 확장이
`core.analytics.subject_export` 필터로 더한 자기 데이터(`{식별자: 내용}`)가 담깁니다 — 열람권은
「이 사이트가 나에 대해 아는 것」 전부입니다.

셋 중 하나는 반드시 주어야 합니다 — 조건이 없으면 원장 전체가 대상이 되어 조회의 뜻이
사라지고 삭제에서는 사고가 됩니다(**422**).

**주소 찾기의 한계**: 저장할 때 마스킹을 거치므로 찾을 때도 같은 마스킹을 입력에 적용해
비교합니다. 익명 모드로 모은 구간은 주소 대신 그날의 솔트로 만든 해시만 남고, 솔트는 며칠
뒤 지워지므로 그보다 오래된 구간은 주소로 찾을 수 없습니다.

삭제는 액션·전환을 먼저 지우고 방문을 지웁니다(연쇄 삭제에 기대지 않습니다). 집계는
건드리지 않고 `core.analytics.subject_deleted` 훅과 활동 로그를 남깁니다. 확장은
`core.analytics.subject_delete` 필터로 **같은 트랜잭션 안에서** 자기 표를 지웁니다 — 확장이
실패하면 코어 삭제도 함께 되돌아가고 요청은 오류로 끝납니다(일부만 지워지고 완료로 보이지 않게).
코어 원장에 방문이 없어도 확장 단계는 돕니다. 삭제·내보내기 필터는 요청의 `from`·`to`(없으면 `null`)도
받아, 방문 ID 로 묶이지 않는 확장 표도 코어와 같은 기간만 다룹니다.
<!-- @generated:end:api.admin.analytics.privacy.subjects -->

---

### GET /api/admin/analytics/visitors
<!-- @generated:start:api.admin.analytics.visitors.index -->
- **라우트명**: `api.admin.analytics.visitors.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\VisitorController@index`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.analytics.privacy`

**요청 파라미터**

기간 규약(`preset`·`from`·`to`)에 아래를 얹습니다. 방문자 목록은 사람을 찾는 화면이라
`exclude[]`(합산에서 빼는 토글)를 쓰지 않습니다 — 유형으로 좁히려면 `type` 을 씁니다.

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| q | query | string | 아니오 | max 64 | 방문자 키 앞자리·주소·회원 번호 중 무엇으로 찾을지 미리 고르지 않는다 — 한 칸에 넣은 값을 셋 모두에 대 본다. `#123` 처럼 `#` 을 붙이면 그 회원 번호의 방문만 찾는다(숫자만 넣으면 키가 같은 숫자로 시작하는 방문도 걸린다) |
| type | query | string | 아니오 | guest, member, admin, bot | 방문자 유형 필터 |
| page / per_page | query | integer | 아니오 | min 1 / max 100 | 쪽 정보 |

**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| data[].visitor_key | string | 방문자 키 |
| data[].visitor_type | string | 방문자 구분 |
| data[].visits / pageviews | integer | 그 사람의 방문 수와 조회 수 — 방문 하나하나가 아니라 **사람 단위로 묶은** 값이다 |
| data[].last_action_at | string | 마지막 활동 시각 (목록 기본 정렬) |
| data[].country_code / device_type / browser_name | string\|null | 환경 |
| data[].country_name / device_type_label | string\|null | **화면용** 이름 — 국가 이름(요청 로케일, 번역이 없으면 코드)·기기 이름 |
| data[].user_id | integer\|null | 회원이면 그 번호 |
| data[].user | object\|null | 회원이면 `{uuid, name, email}`(이메일은 노출 정책이 허용할 때만, 아니면 null) — 화면이 이름을 보이고 회원 화면으로 건너가게 한다. 비회원은 null |
| from · to | string | 실제 조회 기간 |
| pagination.has_more | boolean | 다음 쪽이 있는지 |
| mode / mode_notice | string / string\|null | 익명 모드에서는 방문자 키가 날마다 바뀌어 같은 사람의 방문이 하루 안에서만 묶인다는 안내 |

> 방문자 화면의 **첫 걸음**입니다. 여기서 한 사람을 고르면 아래 단건 조회가 그 사람의
> 활동을 시간순으로 펼칩니다 — 목록이 없으면 운영자는 방문자 키를 어디서도 얻을 수 없습니다.
<!-- @generated:end:api.admin.analytics.visitors.index -->

---

### GET /api/admin/analytics/visitors/{visitorKey}
<!-- @generated:start:api.admin.analytics.visitors.show -->
- **라우트명**: `api.admin.analytics.visitors.show`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\VisitorController@show`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.analytics.privacy`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| visitorKey | path | string | 예 | — | 목록에서 고른 방문자 키 |
| from / to | query | date | 아니오 | Y-m-d | 기간 제한. **익명 모드에서는 무시하고 당일로 좁힌다** — 방문자 키가 날마다 새로 만들어져, 과거 구간을 함께 펼치면 우연히 같은 키를 쓴 다른 날의 다른 사람이 한 프로필에 섞인다 |
| format | query | string | 아니오 | json, csv | `csv` 면 활동 내역을 파일로 내려받는다 |

**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| visitor | object | 화면의 「방문자 카드」가 읽는 한 덩어리 — `visitor_key`·`visitor_type`·`country_code`·`region_code`·`city`·`device_type`·`device_type_label`(화면용 기기 이름)·`country_name`(화면용 국가 이름)·`browser_name`·`browser_version`·`os_name`·`os_version`·`screen_resolution`·`browser_language`·`browser_language_label`·`locale`·`locale_label`(화면용 언어 이름, 가장 최근 방문 기준)·`visits`·`first_action_at`·`last_action_at`·`user_id`·`user_hash`·`user`(`{uuid, name, email}` — 목록과 같은 모양)·`is_returning`. 기간 안에 방문이 0건이어도 키와 유형은 남아 카드가 비지 않는다 |
| visits[] | array | 방문과 그 안의 액션 타임라인·전환 (시간순). 액션마다 저장 이름 `name` 과 **화면용** `name_display`(사이트 호스트 제거·퍼센트 인코딩 해제)가 함께 온다 — 내보내기 파일(CSV·정보주체 JSON)은 `name` 만 싣는다 |
| visits[].referrer_url | string\|null | 그 방문의 유입 주소 |
| visits[] 부수정보 | mixed | 원장이 아는 그 방문의 값 전부 — `source`(`beacon`·`server`)·`visitor_type`·`ip`·`country_code`·`region_code`·`city`·`browser_name`·`browser_version`·`os_name`·`os_version`·`device_type`·`screen_resolution`·`browser_language`·`locale`·`is_returning`·`is_bounce`·`referrer_type`·`referrer_name`·`referrer_keyword`·`campaign_name`·`campaign_source`·`campaign_medium`·`pageviews`·`total_time_seconds`. 정보주체 내보내기 파일도 같은 값을 싣는다. **화면용**으로 `device_type_label`·`referrer_type_label`·`country_name`·`browser_language_label`·`locale_label`(요청 로케일 이름 — 번역이 없으면 코드)이 붙는다. 내보내기 파일·CSV 는 코드만 싣는다 |
| visits[].conversions[] | array | 그 방문의 전환 — `occurred_at`·`goal_key`·`label`(전환 속성: 주문번호 등)·`revenue`·`currency`·`custom_dimensions` 에 **화면용** `goal_label`(선언된 목표 이름, 요청 로케일 — 전환 탭과 같은 이름. 등록부에 없는 목표는 `goal_key` 그대로)이 붙는다. 내보내기 파일은 `goal_label` 을 싣지 않는다 |
| exported_at | string | 조회 시각 (사이트 표준시 `Y-m-d H:i:s`) |
| ip_mask_octets | integer | 화면의 주소가 몇 칸 가려진 값인지 — 마스킹된 주소를 원래 주소로 읽지 않게 한다. 방문마다 `ip_masked` 가 붙어, 설정을 켜며 소급을 건너뛴 기존 방문(`false` — 원래 주소)을 가린다 |
| mode | string | 지금 수집 모드 |
| mode_notice | string\|null | 익명 모드에서는 방문자 키가 날마다 바뀌어 같은 사람의 방문이 하루 안에서만 묶인다는 안내 |

**CSV 내려받기** — `?format=csv` 는 액션 하나가 한 줄이고 방문 정보는 줄마다 되풀이됩니다
(`visit_id, first_action_at, last_action_at, visitor_type, country_code, device_type, browser,
os, referrer_type, referrer_name, referrer_url, pageviews, total_time_seconds, action_occurred_at,
action_type, action_name, time_spent_seconds`). 시각 열(`first_action_at`·`last_action_at`·`action_occurred_at`)은 화면과 같은 **사이트 표준시** `Y-m-d H:i:s` 입니다 — 파일 이름의 기간도 사이트 날짜입니다. 정보주체가 열람을 요구했을 때 건네줄 파일이
이 모양입니다. 액션이 없는 방문도 한 줄로 남습니다 — 방문했다는 사실 자체가 기록이기
때문입니다. 파일명은 `analytics-visitor-{visitorKey}-{from}_{to}.csv`(기간을 주지 않았으면 `analytics-visitor-{visitorKey}.csv`)이고 — 같은 사람을 두 기간으로 받으면 앞 파일을 덮지 않게 — 내려받기도 목록과 같은
`core.analytics.privacy` 권한을 요구합니다.
<!-- @generated:end:api.admin.analytics.visitors.show -->

---

### POST /api/admin/analytics/cloud-ranges/refresh
<!-- @generated:start:api.admin.analytics.cloud-ranges.refresh -->
- **라우트명**: `api.admin.analytics.cloud-ranges.refresh`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\CloudRangeController@refresh`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.analytics.update`

**요청 파라미터**

_없음._

**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| count | integer | 저장된 대역 수 |
| updated_at | string | 갱신 시각 |
| sources | object | 출처별 받은 건수 |
| failed[] | array | 받지 못한 출처 |

하나도 받아 오지 못하면 **502** 이고 **기존 파일은 그대로 둡니다** — 새 목록이 없다고
옛 목록을 버리면 그 순간부터 모든 데이터센터 요청이 사람으로 집계됩니다.
<!-- @generated:end:api.admin.analytics.cloud-ranges.refresh -->

---

### GET /api/analytics/summary
<!-- @generated:start:api.public.analytics.summary -->
- **라우트명**: `api.public.analytics.summary`
- **컨트롤러**: `App\Http\Controllers\Api\Public\AnalyticsSummaryController@index`
- **인증/권한**: 공개 (인증 불필요) · `throttle:60,1,analytics_summary`

**요청 파라미터**

_없음._

**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| today / yesterday / max / total | integer | 오늘·어제·최대·전체 방문자 |

사용자 화면의 방문자 카운터가 읽는 값입니다 — 네 숫자뿐이고 개인을 가리키는 것은 하나도
나가지 않습니다. 설정 `public_summary_enabled` 가 꺼져 있으면 **404** 입니다(값 0 이
아니라 「없는 것」으로 답합니다 — 위젯이 0 을 보고 아무도 오지 않았다고 그리면 안 됩니다).

로그인 없이 누구나 부르므로 응답을 60초 캐시합니다.
<!-- @generated:end:api.public.analytics.summary -->


---
---

## 확장 지점

| 훅 | 종류 | 용도 |
| --- | --- | --- |
| `core.analytics.collect_validation_rules` | filter | 비콘 검증 규칙에 확장 필드를 더한다 |
| `core.analytics.tracking_request_received` | action | 적재를 넘겨받는다. 코어 리스너가 `'sync' => true` 로 구독해 **요청 안에서** 돈다 — 구독하는 확장도 같은 전제로 쓴다 |
| `core.analytics.after_track` | action | 적재 결과를 관찰한다 (방문 ID·액션 ID·종류) |
| `core.analytics.visit_limit_exceeded` | action | 한 방문의 액션 상한 초과를 알린다 |
| `core.analytics.period_validation_rules` | filter | 기간 조회 파라미터에 확장 필드를 더한다 |
| `core.analytics.report_validation_rules` | filter | 상위 목록 조회 파라미터를 확장한다 |
| `core.analytics.row_series_validation_rules` | filter | 한 항목 추이 조회 파라미터를 확장한다 (인자 `($rules, FormRequest $request)`) |
| `core.analytics.settings_validation_rules` | filter | 설정 저장 규칙을 확장한다 |
| `core.analytics.subject_validation_rules` | filter | 정보주체 조회 조건을 확장한다 |
| `core.analytics.{visitor_index·visitor_show·realtime·referrer_urls·diagnostics·collect_failures·archive_run·archive_runs·anonymize·purge·subject_delete·opt_out}_validation_rules` | filter | 같은 이름 관리자 요청의 검증 규칙을 확장한다 (인자 `($rules, FormRequest $request)`) |
| `core.analytics.archive.day` | action | 집계가 하루치를 마칠 때 확장 분류 기준 행을 얹는다. 인자 `{date, collector, is_final, run_type}` (**`'sync' => true` 로 구독해야 한다** — 큐로 가면 수집기가 사본이 되어 행이 돌아오지 않고, 수집기가 직렬화되는 순간 경고 로그를 남긴다). 매시 집계도 오늘 날짜로 이 훅을 쏜다 |
| `core.analytics.registered_goals` / `registered_dimensions` | filter | 모은 목표·분류 기준 선언에 한 번 더 건다 — 운영자가 끈 기능의 목표를 숨기는 등 |
| `core.analytics.resolve_dimensions` | filter | 수집 요청의 분류 값을 서버가 보탠다. 인자 `($dims, $dto)` — 결과는 등록부로 다시 걸러진다 |
| `core.analytics.search_engines` / `campaign_params` / `stripped_query_params` / `ua_rules` | filter | 검색엔진 표·캠페인 파라미터 이름·추적 파라미터·브라우저/OS 판정 패턴에 확장 항목을 보탠다 |
| `core.analytics.exclusion_rules` | filter | 코어 판정을 통과한 요청을 확장이 더 뺀다. 인자 `(null, $dto)`, 제외하려면 등록한 사유 이름을 돌려준다 |
| `core.analytics.registered_exclusion_reasons` | filter | 확장 제외 사유를 `'{식별자}:{사유}' => 이름` 으로 등록한다 — 등록하지 않은 사유로는 제외할 수 없다 |
| `core.analytics.subject_delete` / `subject_export` / `purge_all` | filter | 정보주체 삭제(같은 트랜잭션)·내보내기·전체 삭제(코어보다 먼저)에 확장 표를 참여시킨다 |
| `core.analytics.anonymize_attributes` | filter | 소급 익명화가 방문 하나에 덮어쓸 값에 보탠다. 코어가 정한 값은 되돌릴 수 없다 |
| `core.analytics.csv_columns` / `csv_row` | filter | 리포트 CSV 에 확장 `extra` 열을 더하고 값을 바꾼다 |
| `core.analytics.diagnostics_sections` / `realtime_fields` / `storage_tables` | filter | 진단 섹션·실시간 행 값·저장소 현황 표를 더한다 |
| `core.analytics.archive_failed` | action | 집계 실패를 알린다 |
| `core.analytics.ledger_limit_exceeded` | action | 원장이 경고 한계를 넘었음을 알린다 |
| `core.analytics.before_prune` / `after_prune` | action | 보존 정리 전후 |
| `core.analytics.anonymized` | action | 소급 익명화 완료 |
| `core.analytics.data_purged` | action | 전체 삭제 완료 |
| `core.analytics.extension_purged` | action | 제거된 확장의 통계 데이터 정리 결과 — 실패해도 지운 행 수 0 으로 쏜다 |
| `core.analytics.subject_exported` / `subject_deleted` | action | 정보주체 내보내기·삭제 |
| `core.analytics.settings_updated` / `privacy_mode_changed` | action | 설정·모드 변경 |
| `core.analytics.opt_out_changed` | action | 수집 제외 전환 |
| `core.analytics.archive_requested` | action | 수동 집계 요청 (관리자 버튼만 — 화면 열 때 집계는 발화하지 않는다) |
| `core.analytics.weekly_report_ready` / `monthly_report_ready` | action | 정기 리포트 발송 (기간마다 훅을 갈라 둔다 — 한 훅에 두 정의를 걸면 주간·월간이 함께 나간다) |
| `core.analytics.notification.extract_data` | filter | 알림 본문의 `{visits}` 같은 자리를 값으로 채운다 |

---

## 관련 문서

- 설계·개인정보 모드·보존 정책: [../analytics.md](../analytics.md)
- API 문서 색인: [README.md](README.md)
