# Analytics API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 방문자 통계 엔드포인트 레퍼런스입니다
2. 수집 비콘(POST /api/analytics/collect)은 공개 경로이며 응답은 언제나 204 입니다
3. 검증 실패·적재 오류도 204 — 비콘에 4xx 를 주면 방문자 콘솔에 오류가 뜹니다
4. 실패 사유는 응답이 아니라 적재 실패 로그에 남고, 관리자 진단 화면에서 봅니다
5. 관리자 조회·설정 API 는 후속 단계에서 이 문서에 추가됩니다
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

## 적재 방식 — 큐와 즉시 적재

설정 `analytics.dispatch_mode` 가 정합니다.

- **queue**(기본): 수집 요청을 훅으로 넘기고 큐 워커가 적재합니다. 응답이 빨라지지만
  **워커가 돌지 않으면 방문이 쌓이지 않습니다.** 진단 화면이 큐 대기 건수를 보여 줍니다.
- **sync**: 요청 안에서 바로 적재합니다. 워커를 운영하지 않는 설치의 선택지입니다.

**두 방식 모두 방문자 쿠키는 이 요청의 응답에서 심습니다.** 쿠키는 적재 결과물이 아니라
방문자 식별의 전제이고, 큐 워커는 응답 헤더를 쓸 수 없기 때문입니다. 적재와 함께 미루면
쿠키가 영영 나가지 않아 매 비콘이 새 방문으로 잡히고 재방문이 항상 0 이 됩니다 — 응답은
정상 204 라 겉으로는 아무 증상이 없습니다. 큐 방식에서는 이 요청이 정한 방문자 식별값을
훅 페이로드의 `visitor_key` 로 함께 넘겨, 워커가 같은 방문자로 적재하게 합니다.

---

## 확장 지점

| 훅 | 종류 | 용도 |
| --- | --- | --- |
| `core.analytics.collect_validation_rules` | filter | 비콘 검증 규칙에 확장 필드를 더한다 |
| `core.analytics.visitor_has_consent` | filter | 동의 연동 모드에서 이 방문자가 동의했는지 답한다. 기본값 `null`(답할 확장 없음) |
| `core.analytics.tracking_request_received` | action | 큐 방식에서 적재를 넘겨받는다 |
| `core.analytics.after_track` | action | 적재 결과를 관찰한다 (방문 ID·액션 ID·종류) |
| `core.analytics.visit_limit_exceeded` | action | 한 방문의 액션 상한 초과를 알린다 |

---

## 관련 문서

- 설계·개인정보 모드·보존 정책: [../analytics.md](../analytics.md)
- API 문서 색인: [README.md](README.md)
