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
| name | string | 선택 | 최대 255자 | 내려받은 파일명 등 표시 이름 |
| goal | string | 선택 | 최대 100자 | 목표 키 (확장이 등록한 전환) |
| attrs | object | 선택 | — | 목표 속성 (금액·통화·라벨) |
| dims | object | 선택 | 최대 20개, 값 255자 | 확장이 넣은 커스텀 차원 값 |
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

### GET /api/admin/analytics/overview
<!-- @generated:start:api.admin.analytics.overview -->
- **라우트명**: `api.admin.analytics.overview`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\OverviewController@index`
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
| kpi.today | integer | 오늘 순방문자. 집계 표가 아니라 원장에서 직접 세고 60초 캐시한다 — 집계는 한 시간에 한 번 돌아 「오늘」이 늦으면 화면을 믿지 못한다 |
| kpi.yesterday | integer | 어제 순방문자 (일 집계 합) |
| kpi.max.value / kpi.max.date | integer / string | 하루 방문자가 가장 많았던 날과 그 수 |
| kpi.total | integer | 전체 방문자 — **일별 순방문자의 합**이다. 방문자 키가 날마다 바뀔 수 있어 여러 날을 아우르는 진짜 순방문자는 원리적으로 셀 수 없다 |
| kpi.change_pct | float\|null | 어제 대비 증감률. 어제가 0 이면 `null` (「무한 % 증가」로 적으면 오해를 부른다) |
| series.unit | string | 실제로 쓰인 시계열 단위 |
| series.unit_downgraded | boolean | 시간 단위를 요청했으나 그 집계의 보존기간 밖이라 일 단위로 낮춘 경우 `true` |
| series.points[] | array | `{ts, visits, visitors, pageviews, bounces, bounce_rate, avg_time, pages_per_visit}`. 기록이 없는 칸도 0 으로 채워 한 칸씩 나간다 — 건너뛰면 차트가 앞뒤 칸을 곧장 이어 그려 추이가 거짓이 된다. 지금 시간·오늘 뒤의 칸은 만들지 않는다. 기간 전체에 기록이 하나도 없으면 빈 배열이다 (화면이 「선택 기간에 기록이 없습니다」를 띄운다) |
| top.pages / top.referrers / top.countries | array | 상위 목록 (설정 `top_n_overview` 건) |
| realtime.count / realtime.visitors[] | integer / array | 현재 접속자 수와 최근 목록. 이 경로에서는 주소를 담지 않는다 |
| meta.mode | string | 개인정보 수집 모드 (full, consent, anonymous) |
| meta.consent_source | string | 동의를 물어볼 곳. `none` 이면 동의 모드라도 익명으로 모은다 — 화면이 그 사실을 경고로 띄운다 |
| meta.collection_enabled | boolean | 수집 마스터 스위치. 꺼져 있으면 화면 상단에 안내를 띄운다 |
| meta.geoip_enabled | boolean | 지역 조회 사용 여부 (꺼져 있으면 지역 화면이 안내만 보인다) |
| meta.queue_backlogged / meta.queue_pending_jobs | boolean / integer | 큐 적체. 워커가 멈추면 비콘은 204 를 받고도 원장에 닿지 않는다 |

> 화면 첫 장이 한 번에 받아 가는 응답입니다 — 이걸 여섯 번 나눠 부르면 첫 화면에서 요청이 여섯 번 나갑니다.
<!-- @generated:end:api.admin.analytics.overview -->

---

### GET /api/admin/analytics/series
<!-- @generated:start:api.admin.analytics.series -->
- **라우트명**: `api.admin.analytics.series`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\ReportController@series`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.analytics.read`

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
| points[] | array | `{ts, visits, visitors, pageviews, bounces, bounce_rate, avg_time, pages_per_visit}`. 기록이 없는 칸도 0 으로 채워 한 칸씩 나간다 — 건너뛰면 차트가 앞뒤 칸을 곧장 이어 그려 추이가 거짓이 된다. 지금 시간·오늘 뒤의 칸은 만들지 않는다. 기간 전체에 기록이 하나도 없으면 빈 배열이다 (화면이 「선택 기간에 기록이 없습니다」를 띄운다) |

**CSV 내려받기** — `?format=csv` 는 `ts, visits, visitors, pageviews, bounce_rate,
avg_time_seconds, pages_per_visit` 일곱 열입니다. 화면의 그래프를 운영자가 스프레드시트에서
자기 방식대로 다시 그릴 수 있어야 하기 때문입니다. BOM·CRLF 규약은 목록 내려받기와 같고
파일명은 `analytics-series-{from}_{to}.csv` 입니다.
<!-- @generated:end:api.admin.analytics.series -->

---

### GET /api/admin/analytics/reports/{report}
<!-- @generated:start:api.admin.analytics.reports.show -->
- **라우트명**: `api.admin.analytics.reports.show`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\ReportController@show`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.analytics.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| preset | query | string | 아니오 | today, yesterday, 7d, 30d, 90d, this_month, last_month, this_year | 기간 프리셋. 기본 `7d` |
| from | query | date | 아니오 | Y-m-d | 조회 시작일. 주면 `preset` 보다 우선한다 (주소를 공유했을 때 보던 화면이 그대로 열려야 하므로) |
| to | query | date | 아니오 | Y-m-d, `from` 이후 | 조회 종료일. `from` 만 주면 그 하루 |
| unit | query | string | 아니오 | auto, hour, day, week, month, year | 시계열 단위. 기본 `auto` (구간 길이로 고름) |
| exclude[] | query | array | 아니오 | admin, member | 합산에서 뺄 방문자 유형. 화면의 「관리자 제외」·「회원 제외」 토글. **봇 차원(`bot_name`·`bot_pages`)에는 적용되지 않습니다** — 봇 행에는 뺄 사람 유형이 없어, 적용하면 목록이 통째로 비어 버립니다 |
| report | path | string | 예 | 차원 이름 또는 별칭 | 무엇의 상위 목록인지. 차원 이름(`page_url`·`referrer_name`·`browser`·`country`·`city`·`site_search`·`download`·`outlink`·`bot_name` 등) 또는 별칭(`entry_pages`·`exit_pages`·`bot_pages`·`goals`) |
| page | query | integer | 아니오 | min 1 | 쪽 번호 |
| per_page | query | integer | 아니오 | min 1, max 100 | 쪽당 건수. 기본값은 설정의 「상세 화면 상위 목록 건수」(`top_n_detail`, 기본 25) |
| sort | query | string | 아니오 | visits, hits, entries, exits | 정렬 기준. 기본 `visits` |
| q | query | string | 아니오 | max 255 | 라벨 부분 검색. 입력한 글자 그대로 찾는다(`%`·`_` 도 글자). 주소 차원(페이지·진입·이탈·봇 페이지)은 화면에 풀어 보인 모양(「테스트」)으로 찾아도 인코딩돼 저장된 라벨(「%ED%85%8C…」)이 잡힌다 |
| format | query | string | 아니오 | json, csv | `csv` 면 표를 파일로 내려받는다 |


**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| report | string | 요청한 리포트 이름 |
| data[].label | string | 저장된 라벨 (기계가 읽는 값 — 국가 코드, 접두를 뗀 주소) |
| data[].label_display | string | 화면에 보일 문자열 (국가·지역·도시는 번역, 주소는 사이트 호스트를 떼고 퍼센트 인코딩을 푼 값 — 풀어서 올바른 UTF-8 이 아니거나 제어 문자가 섞이면 원문. `label` 은 저장값 그대로) |
| data[].visits / hits | integer | 이 라벨이 나온 방문 수 / 액션 수. 같은 방문이 한 라벨을 여러 번 봐도 방문은 1 이다 |
| data[].entries / exits | integer | 이 라벨로 시작한 / 끝난 방문 수 |
| data[].bounce_rate | float | 이탈률 (%) |
| data[].avg_time | integer | 방문당 평균 체류 (초) |
| data[].pages_per_visit | float | 방문당 조회 수 |
| data[].extra | object | 차원별 부가 값을 기간 내내 키마다 더한 값. 내부 검색어의 `no_result`(결과 없음 건수)처럼 차원이 스스로 싣는 값이며, 부가 값이 없는 차원은 빈 객체 `{}` 다 — 화면이 언제나 같은 모양을 읽도록 키 자체는 늘 있다 |
| pagination.has_more | boolean | 다음 쪽이 있는지 |

**CSV 내려받기** — `?format=csv` 는 `text/csv; charset=UTF-8` 스트림입니다.
공통 아홉 열(`label, label_display, visits, hits, entries, exits, bounce_rate, avg_time_seconds,
pages_per_visit`) 뒤에 **그 차원의 부가 열**이 붙습니다. 내부 검색어(`site_search`)는
`no_result_count`(결과 없음 건수), 목표(`goal`)는 `revenue_sum`(전환 금액 합계) 한 열이며,
부가 값이 없는 차원은 아홉 열 그대로입니다. `revenue_sum` 은 집계가 센트 정수로 담아 둔
값을 소수점 두 자리로 되돌린 것입니다 — `extra` 가 정수 칸이라 금액을 그대로 담으면
반올림으로 값이 샙니다.
화면에서 보이던 값이 파일에서 빠지면 운영자는 내려받은 표가 틀린 것인지 화면이 틀린
것인지 판단할 근거가 없으므로, 차원이 `extra` 에 담는 값은 열로 내보냅니다.
파일 앞에 바이트 순서 표식(BOM)을 답니다. 없으면 엑셀이 UTF-8 로 읽지 않아 한글이 전부
깨집니다. 줄 끝은 CRLF, 파일명은 `analytics-{report}-{from}_{to}.csv` 입니다.
내려받기는 쪽을 나누지 않습니다 — 운영자가 받으려는 것은 화면에 보이던 25행이 아니라
그 기간 전체이기 때문입니다.

없는 리포트 이름은 **404** 입니다.
<!-- @generated:end:api.admin.analytics.reports.show -->

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
| exclude[] | query | array | 아니오 | admin, member | 합산에서 뺄 방문자 유형. 화면의 「관리자 제외」·「회원 제외」 토글. **봇 차원(`bot_name`·`bot_pages`)에는 적용되지 않습니다** — 봇 행에는 뺄 사람 유형이 없어, 적용하면 목록이 통째로 비어 버립니다 |
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
| recent_hits[] | array | 최근 봇 요청 50건 — `bot_name`·`url`·`ip`·`cache_status`·`occurred_at`. 집계는 「어느 봇이 얼마나」까지만 답하므로, 어떤 주소를 언제 긁어 갔는지를 함께 보여 운영자가 차단 여부를 판단할 수 있게 한다 |

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
| visitors[].ip | string\|null | 주소. **`core.analytics.privacy` 권한이 있을 때만** 채워지고, 없으면 `null` |

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
| collection.effective_mode | string | 실제로 도는 모드. 운영자가 고른 `mode` 와 다를 수 있다(익명 폴백) |
| collection.anonymous_fallback_reason | string\|null | 익명으로 내려간 사유. `consent_source_missing`(동의 모드인데 물어볼 곳이 없음) · `consent_plugin_blocks_cookies`(동의 관리 플러그인이 동의 전 방문자 쿠키를 막음). 내려가지 않았으면 `null` |
| collection.last_tracked_at | string\|null | 마지막으로 무언가 세어진 때 |
| collection.tracked_today | integer | 오늘 순방문자 |
| collection.excluded_today / excluded_yesterday | object | 사유별 제외 건수. 캐시에 살기 때문에 캐시를 비우거나 서버를 다시 띄우면 사라진다 — 화면이 그 사실을 함께 적는다 |
| queue.connection / pending_jobs / failed_jobs | mixed | 큐 상태. `countable` 이 `false` 면 데이터베이스 큐가 아니라 여기서 셀 수 없다는 뜻이고, 0 을 「비어 있다」로 읽으면 안 된다 |
| queue.backlogged / worker_hint | boolean | 적체 판정. 수집은 큐를 타지 않으므로 여기 쌓이는 것은 **집계·익명화 배치**다 — 워커가 멈추면 방문은 계속 쌓이는데 집계 숫자만 그 시점에 멈춘다 |
| archive.last_hour_run / last_day_run / lag_hours / running_run_id | mixed | 집계가 밀렸는지. 밀리면 화면 숫자가 그 시점에 멈춘 채 그럴듯하게 남는다 |
| storage | object | 표별 행 수·크기와 경고 한계 초과 여부 |
| geoip.enabled / db_present | boolean | 지역 조회 사용 여부와 데이터베이스 존재 |
| trusted_proxy | object | 프록시 신뢰 설정. 설정하지 않으면 프록시 뒤 전 방문자가 주소 하나로 뭉친다 |
| broadcasting.configured | boolean | 실시간 푸시 가능 여부 (아니면 화면이 폴링으로 돈다) |
| cloud_ranges | object | 클라우드 대역 차단 사용 여부와 목록 파일 상태. `updated_at` 은 다른 통계 시각과 같은 **사이트 표준시**(`Y-m-d H:i:s`, 오프셋 없음) |
| timezone | string | 사이트 표준시 이름. 통계 화면의 모든 시각이 이 기준이다 |
| timezone_differs | boolean | 보는 사람의 시간대가 사이트와 다른지. 참이면 화면이 「이 시각은 사이트 표준시 기준」을 알린다 |
| failures_24h | integer | 최근 24시간 적재 실패 건수 |
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
| reason | query | string | 아니오 | validation, max_actions, dictionary, db_error, listener_exception, unknown_goal, unknown_dimension | 실패 사유 필터 |
| page | query | integer | 아니오 | min 1 | 쪽 번호 |
| per_page | query | integer | 아니오 | min 1, max 100 | 쪽당 건수 |


**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| data[] | array | 실패 기록 (사유·메시지·주소·요청 IP·브라우저 문자열·발생 시각). 페이로드 발췌는 목록에 싣지 않습니다 — 행이 커져 목록 조회가 느려집니다 |
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
| run_type / trigger / status | string | 무엇을, 누가 시켜서, 어떻게 끝났는지 |
| period_start / period_end | string\|null | 대상 기간 |
| processed_visits / processed_actions / chunks | integer | 처리량 |
| progress_percent | integer | 진행률. 화면이 이 값을 2초마다 물어본다 |
| started_at / finished_at | string\|null | 시작·종료 시각 |
| error | string\|null | 실패·건너뜀 사유. **단건 조회에만** 실립니다(목록은 행을 가볍게 둡니다) |

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
영구 보존되는 일·차원 집계를 빈 값으로 덮어쓰기 때문입니다. `force` 도 이 경계를 넘지 못합니다.
<!-- @generated:end:api.admin.analytics.archive-runs.store -->

---

### GET /api/admin/analytics/settings · PUT /api/admin/analytics/settings
<!-- @generated:start:api.admin.analytics.settings.show -->
- **라우트명**: `api.admin.analytics.settings.show`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\Analytics\SettingsController@show · @update`
- **인증/권한**: `auth:sanctum` + `permission:admin,core.analytics.update`

**요청 파라미터**

전체 설정 키는 [../analytics.md](../analytics.md) 「설정」 절의 표와 같고, PUT 은 **보낸 키만** 바꿉니다 (설정 화면이 탭 하나만 저장할 수 있도록).

주요 범위: `retention_days` 7~3650 · `hourly_retention_days` 7~3650 · `visit_timeout_minutes` 5~120 · `max_actions_per_visit` 50~10000 · `ip_mask_octets` 0~3 · `archive_chunk_size` 500~50000 · `realtime_poll_seconds` 10~300 · `realtime_window_minutes` 1~60 · `top_n_*` 3~100 · `visitor_cookie_days` 1~730.


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

### GET · POST · DELETE /api/admin/analytics/opt-out
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
| tables[] | array | 표별 행 수·데이터·인덱스 크기 (데이터베이스가 들고 있는 어림값) |
| exact | object | 방문·액션 두 표의 정확한 행 수 — 운영자가 지울지 말지를 정하는 값이라 정확히 센다 |
| over_limit | boolean | 경고 한계를 넘었는지 |
| driver_supported | boolean | 지금 드라이버가 표 용량 통계를 제공하는지 |
| deleted | object | (삭제 시) 대상별 삭제 건수 |

삭제는 되돌릴 수 없고 `confirm` 없이는 **422** 입니다. 원장·집계·봇 기록·실패 로그·
실행 이력·솔트를 모두 지우고 `core.analytics.data_purged` 훅을 발화하며 활동 로그에
남깁니다.
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

### GET /api/admin/analytics/privacy/subjects · POST …/export · DELETE …/subjects
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
| visits[] | array | 찾은 방문 — `visit_id`·`visitor_key`·`visit_date`·`first_action_at`·`last_action_at`·`ip`·`visitor_type`·`pageviews_count`·`actions_count`(화면의 표가 그리는 값). 내보내기는 각 방문의 액션 타임라인과 전환을 함께 담는다 |
| notice | string\|null | 찾기의 한계 안내 (익명 모드 구간은 최근 이틀치만 주소로 찾을 수 있다) |
| export_filename | string | 내보내기 파일명 `analytics-subject-{yyyymmdd}.json`(사이트 기준 날짜). 내보내기를 토큰 동반 blob 으로 받는 화면이 저장 이름으로 쓴다 — 검색값을 파일명에 넣지 않기 위해서다 |
| deleted | object | (삭제 시) `{visits, actions, conversions}` |

**내보내기는 파일로 옵니다** — `POST …/subjects/export` 는 봉투에 담은 JSON 이 아니라
`application/json; charset=UTF-8` + `Content-Disposition: attachment; filename="analytics-subject-{yyyymmdd}.json"`
으로 흘려보냅니다. 열람권 이행의 산출물이라 운영자가 요청한 사람에게 그대로 건넬 수
있어야 하기 때문입니다. 본문은 `{exported_at, subject, notice, visits[]}` 입니다.

셋 중 하나는 반드시 주어야 합니다 — 조건이 없으면 원장 전체가 대상이 되어 조회의 뜻이
사라지고 삭제에서는 사고가 됩니다(**422**).

**주소 찾기의 한계**: 저장할 때 마스킹을 거치므로 찾을 때도 같은 마스킹을 입력에 적용해
비교합니다. 익명 모드로 모은 구간은 주소 대신 그날의 솔트로 만든 해시만 남고, 솔트는 며칠
뒤 지워지므로 그보다 오래된 구간은 주소로 찾을 수 없습니다.

삭제는 액션·전환을 먼저 지우고 방문을 지웁니다(연쇄 삭제에 기대지 않습니다). 집계는
건드리지 않고 `core.analytics.subject_deleted` 훅과 활동 로그를 남깁니다.
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
| q | query | string | 아니오 | max 64 | 방문자 키 앞자리·주소·회원 번호 중 무엇으로 찾을지 미리 고르지 않는다 — 한 칸에 넣은 값을 셋 모두에 대 본다 |
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
| data[].user_id | integer\|null | 회원이면 그 번호 |
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
| visitor | object | 화면의 「방문자 카드」가 읽는 한 덩어리 — `visitor_key`·`visitor_type`·`country_code`·`device_type`·`browser_name`·`os_name`·`visits`·`first_action_at`·`last_action_at`. 기간 안에 방문이 0건이어도 키와 유형은 남아 카드가 비지 않는다 |
| visits[] | array | 방문과 그 안의 액션 타임라인·전환 (시간순). 액션마다 저장 이름 `name` 과 **화면용** `name_display`(사이트 호스트 제거·퍼센트 인코딩 해제)가 함께 온다 — 내보내기 파일(CSV·정보주체 JSON)은 `name` 만 싣는다 |
| mode | string | 지금 수집 모드 |
| mode_notice | string\|null | 익명 모드에서는 방문자 키가 날마다 바뀌어 같은 사람의 방문이 하루 안에서만 묶인다는 안내 |

**CSV 내려받기** — `?format=csv` 는 액션 하나가 한 줄이고 방문 정보는 줄마다 되풀이됩니다
(`visit_id, first_action_at, last_action_at, visitor_type, country_code, device_type, browser,
os, referrer_type, referrer_name, pageviews, total_time_seconds, action_occurred_at,
action_type, action_name, time_spent_seconds`). 정보주체가 열람을 요구했을 때 건네줄 파일이
이 모양입니다. 액션이 없는 방문도 한 줄로 남습니다 — 방문했다는 사실 자체가 기록이기
때문입니다. 파일명은 `analytics-visitor-{visitorKey}.csv` 이고, 내려받기도 목록과 같은
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
| `core.analytics.visitor_has_consent` | filter | 동의 연동 모드에서 이 방문자가 동의했는지 답한다. 기본값 `null`(답할 확장 없음) |
| `core.analytics.consent_session_id` | filter | 동의 조회에 쓸 세션 식별자를 수집 요청 조립 시점에 정한다. 인자 `($default, Request $request)`, 기본값은 시작된 라라벨 세션 ID(없으면 `null`). 게스트 동의를 자기 식별자(예: GDPR 플러그인의 서명된 `gdpr_session` UUID)로 저장하는 동의 관리 확장이 답한다 — 요청이 살아 있을 때 정해 싣으므로 큐 적재에도 그대로 간다 |
| `core.analytics.tracking_request_received` | action | 적재를 넘겨받는다. 코어 리스너가 `'sync' => true` 로 구독해 **요청 안에서** 돈다 — 구독하는 확장도 같은 전제로 쓴다 |
| `core.analytics.after_track` | action | 적재 결과를 관찰한다 (방문 ID·액션 ID·종류) |
| `core.analytics.visit_limit_exceeded` | action | 한 방문의 액션 상한 초과를 알린다 |
| `core.analytics.period_validation_rules` | filter | 기간 조회 파라미터에 확장 필드를 더한다 |
| `core.analytics.report_validation_rules` | filter | 상위 목록 조회 파라미터를 확장한다 |
| `core.analytics.settings_validation_rules` | filter | 설정 저장 규칙을 확장한다 |
| `core.analytics.subject_validation_rules` | filter | 정보주체 조회 조건을 확장한다 |
| `core.analytics.{visitor_index·visitor_show·realtime·referrer_urls·diagnostics·collect_failures·archive_run·archive_runs·anonymize·purge·subject_delete·opt_out}_validation_rules` | filter | 같은 이름 관리자 요청의 검증 규칙을 확장한다 (인자 `($rules, FormRequest $request)`) |
| `core.analytics.archive.day` | action | 집계가 하루치를 마칠 때 확장 차원 행을 얹는다 (**`'sync' => true` 로 구독해야 한다** — 큐로 가면 수집기를 잃는다) |
| `core.analytics.archive_failed` | action | 집계 실패를 알린다 |
| `core.analytics.ledger_limit_exceeded` | action | 원장이 경고 한계를 넘었음을 알린다 |
| `core.analytics.before_prune` / `after_prune` | action | 보존 정리 전후 |
| `core.analytics.anonymized` | action | 소급 익명화 완료 |
| `core.analytics.data_purged` | action | 전체 삭제 완료 |
| `core.analytics.extension_purged` | action | 제거된 확장의 통계 데이터 정리 결과 — 실패해도 지운 행 수 0 으로 쏜다 |
| `core.analytics.subject_exported` / `subject_deleted` | action | 정보주체 내보내기·삭제 |
| `core.analytics.settings_updated` / `privacy_mode_changed` | action | 설정·모드 변경 |
| `core.analytics.opt_out_changed` | action | 수집 제외 전환 |
| `core.analytics.archive_requested` | action | 수동 집계 요청 |
| `core.analytics.weekly_report_ready` / `monthly_report_ready` | action | 정기 리포트 발송 (기간마다 훅을 갈라 둔다 — 한 훅에 두 정의를 걸면 주간·월간이 함께 나간다) |
| `core.analytics.notification.extract_data` | filter | 알림 본문의 `{visits}` 같은 자리를 값으로 채운다 |

---

## 관련 문서

- 설계·개인정보 모드·보존 정책: [../analytics.md](../analytics.md)
- API 문서 색인: [README.md](README.md)
