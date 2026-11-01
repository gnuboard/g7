# CSP Report API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 콘텐츠 보안 정책(CSP) 위반 보고를 받는 공개 경로입니다 — 브라우저가 보냅니다
2. 관리자 환경설정 > 보안의 CSP 모드가 '점검만'·'강제'일 때 정책의 report-uri 가 이 경로입니다
3. 응답은 언제나 204 입니다 — 형식이 잘못된 보고도 기록만 생략합니다(16KB 초과만 413)
4. 기록은 storage/logs/csp-YYYY-MM-DD.log, 주소의 쿼리스트링은 남기지 않고 요청 IP 는 기록하지 않습니다
5. 사용법·정책 내용은 docs/backend/content-security-policy.md 를 봅니다
```

---

### POST /api/csp-report
<!-- @generated:start:api.public.csp_report -->
- **라우트명**: `api.public.csp_report`
- **컨트롤러**: `App\Http\Controllers\Api\Public\CspReportController@store`
- **인증/권한**: 공개 (인증 불필요) · `throttle:60,1,csp_report`

**요청 파라미터**

본문은 브라우저가 정하는 두 형식 중 하나입니다. 두 형식 모두 받습니다.

| 형식 (`Content-Type`) | 본문 모양 | 읽는 필드 |
| --- | --- | --- |
| `application/csp-report` (`report-uri`, 대부분 브라우저) | `{"csp-report": {…}}` | `document-uri` · `blocked-uri` · `effective-directive`(없으면 `violated-directive`) · `disposition` · `line-number` · `source-file` · `script-sample` |
| `application/reports+json` (Reporting API) | `[{"type": "csp-violation", "body": {…}}, …]` | `documentURL` · `blockedURL` · `effectiveDirective` · `disposition` · `lineNumber` · `sourceFile` · `sample`. `type` 이 `csp-violation` 이 아닌 항목은 무시하고, 한 요청에서 최대 20건만 기록 |

**요청 예시**

```http
POST /api/csp-report HTTP/1.1
Host: api.example.com
Content-Type: application/csp-report

{
  "csp-report": {
    "document-uri": "https://example.com/board/free?page=2",
    "blocked-uri": "inline",
    "effective-directive": "script-src-elem",
    "disposition": "report",
    "line-number": 12,
    "source-file": "https://example.com/board/free",
    "script-sample": "window.dataLayer = window.dataLayer || [];"
  }
}
```

**응답 필드**

응답 본문이 없습니다(204 No Content).

**응답 예시**

```http
HTTP/1.1 204 No Content
```

| 상태 | 언제 |
| --- | --- |
| 204 | 기록함 · 형식이 잘못돼 기록하지 않음 (둘을 구분하지 않는다 — 보고에 4xx 를 주면 방문자 콘솔에 오류가 쌓인다) |
| 413 | 본문(또는 선언한 `Content-Length`)이 16KB 초과 |
| 429 | 같은 출처에서 분당 60회 초과 |

기록 한 줄의 모양(`storage/logs/csp-YYYY-MM-DD.log`, 14일 보관):

```text
[2026-10-31 21:19:13] production.INFO: csp-violation {"document_uri":"https://example.com/board/free","blocked_uri":"inline","effective_directive":"script-src-elem","disposition":"report","line_number":12,"source_file":"https://example.com/board/free","sample":"window.dataLayer = window.dataLayer || [];"}
```

주소 세 칸(`document_uri`·`blocked_uri`·`source_file`)은 `?`·`#` 뒤를 지운 값입니다. `sample` 은 앞 80자까지입니다.
<!-- @generated:end:api.public.csp_report -->
