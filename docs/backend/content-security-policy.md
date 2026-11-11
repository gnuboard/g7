# 콘텐츠 보안 정책 (Content Security Policy)

사이트 화면 응답에 스크립트 출처 정책과 스타일 출처 정책(CSP)을 붙여, 허용하지 않은 곳의 스크립트·CSS 와
인라인 스크립트·인라인 스타일이 동작하지 않게 하는 방법을 설명한다. 관리자 환경설정으로 켜는 방법과
웹서버에서 직접 거는 방법을 함께 다룬다. 스타일 정책은 코어 7.1.6 에서 추가됐다.

## TL;DR (5초 요약)

```text
1. 환경설정 > 보안 > 「콘텐츠 보안 정책(CSP)」 — 스크립트 정책과 스타일 정책을 따로 끔(기본) / 점검만 / 강제
2. 점검만으로 먼저 켜고 storage/logs/csp-*.log 에 위반이 없는지 본 뒤 강제로 바꾼다
3. 스크립트 호스트 = 'self' + 켜진 확장 선언(자동) + 운영자 추가 / 스타일 호스트 = 'self' + 활성 템플릿의 외부 CSS(자동) + 운영자 추가
4. 강제 모드로 관리자 화면이 막히면 .env 에 G7_CSP_MODE=off(스크립트) · G7_CSP_STYLE_MODE=off(스타일) → php artisan config:clear
5. 웹서버에서 직접 걸면 G7 쪽은 끔으로 둔다 (응답에 이미 CSP 가 있으면 G7 은 붙이지 않는다)
```

## 목차

- [1. 무엇을 막는가](#1-무엇을-막는가)
- [2. 관리자 설정](#2-관리자-설정)
- [3. 붙는 정책](#3-붙는-정책)
- [4. 허용 호스트의 출처](#4-허용-호스트의-출처)
- [5. .env 로 모드 고정 (탈출구)](#5-env-로-모드-고정-탈출구)
- [6. 위반 보고와 csp.log](#6-위반-보고와-csplog)
- [7. 웹서버에서 직접 거는 경우](#7-웹서버에서-직접-거는-경우)
- [8. 결제·외부 서비스 SDK 허용 호스트](#8-결제외부-서비스-sdk-허용-호스트)
- [9. 범위와 한계](#9-범위와-한계)
- [10. 확인 방법](#10-확인-방법)

---

## 1. 무엇을 막는가

### 스크립트 정책이 막는 것

`script-src 'self'`(`unsafe-inline` 없음)가 걸리면 브라우저는 다음을 실행하지 않는다.

- 같은 사이트·허용 호스트 밖에서 불러오는 `<script src>`
- 페이지에 직접 적힌 `<script>…</script>` 본문
- `onclick="…"` 같은 인라인 이벤트 속성, `href="javascript:…"`

게시글·상품 설명처럼 사용자가 넣은 HTML 에 스크립트가 섞여 들어와도 실행되지 않으므로, HTML 정화가
놓친 경우를 한 겹 더 막는다. G7 코어·번들 템플릿·번들 확장은 인라인 실행 코드를 쓰지 않는다
(서버 값은 `<script type="application/json">` 데이터 블록, 동작은 정적 JS 파일).

### 스타일 정책이 막는 것

스타일 정책(`style-src 'self'; style-src-attr 'none'`)이 걸리면 브라우저는 다음 세 가지를 적용하지 않는다.

| 막히는 것 | 예 | 막는 강도 |
| --- | --- | --- |
| `<style>` 요소 | 뷰에 적힌 `<style>…</style>`, 스크립트가 만들어 붙인 `<style>` | 기본 · 엄격 |
| 허용 호스트 밖의 CSS 파일 | `<link rel="stylesheet" href="https://다른-호스트/x.css">` | 기본 · 엄격 |
| HTML 문자열에 든 `style="…"` 속성 | 서버가 내려준 HTML, `innerHTML`·`dangerouslySetInnerHTML` 로 넣는 문자열 | 엄격 |
| `setAttribute('style', …)` | 스크립트가 속성으로 스타일을 넣는 경우 | 엄격 |

다음은 막히지 않는다(Chrome 156 실측 위반 0건).

- 스크립트가 요소의 스타일 객체에 값을 대입하는 것 — `el.style.color = …`, `el.style.cssText = …`,
  `el.style.setProperty(…)`, React 의 `style={{…}}` prop
- 같은 사이트·허용 호스트의 CSS 파일(`<link rel="stylesheet">`)과 그 시트에 스크립트로 규칙을 더하는 것
- 스크립트가 만든 스타일시트 객체를 문서에 붙이는 것(`document.adoptedStyleSheets`)

엄격한 정책에서는 HTML 문자열을 화면에 붙이지 않고 **파싱만 해도**(`DOMParser`, `<template>`) 그 안의
`style` 속성이 위반으로 보고된다. 그래서 코어는 본문 HTML 을 정화하기 전에 `style` 속성을 떼어 두었다가
화면에 붙인 뒤 스크립트로 다시 입힌다(9장 「본문 서식」).

G7 코어·번들 템플릿(sirsoft-basic·sirsoft-admin_basic)·설치 화면·레이아웃 편집기는 위 세 가지를 쓰지
않는다. 코어가 직접 그리는 화면(오류·점검·DB 접속 오류 화면, 로딩 표시, 시스템 배너)의 스타일은 정적 CSS
파일(`public/assets/core/css/g7-core.css`·`g7-standalone.css`)로 싣는다.

## 2. 관리자 설정

환경설정 > 보안 탭의 「콘텐츠 보안 정책(CSP)」 카드. 스크립트 정책과 스타일 정책은 같은 카드에 있고
**적용 모드를 따로** 고른다.

### 스크립트 정책

| 항목 | 설명 |
| --- | --- |
| 적용 모드 | `끔` — 헤더를 붙이지 않는다(기본값). `점검만` — `Content-Security-Policy-Report-Only` 헤더. 막지 않고 위반만 기록한다. `강제` — `Content-Security-Policy` 헤더. 위반 스크립트를 실행하지 않는다 |
| 추가 스크립트 허용 호스트 | 자동 포함 호스트 밖에서 스크립트를 불러오는 경우에만 더한다. 형식: `cdn.example.com` · `*.example.com` · `https://cdn.example.com` · 포트(`:8443`) 허용 |
| 자동 포함 호스트 | 켜진 확장이 선언한 호스트(읽기 전용) |
| 적용될 정책 미리보기 | **저장된 값** 기준으로 서버가 조립한 정책 문자열. 실제 응답 헤더와 같다 |

### 스타일 정책 (코어 7.1.6)

| 항목 | 설명 |
| --- | --- |
| 스타일 적용 모드 | `끔`(기본값) · `점검만` · `강제`. 뜻은 스크립트 정책과 같고, 스크립트 정책의 모드와 무관하게 고른다 |
| 차단 강도 | `기본` — `<style>` 블록과 허용하지 않은 외부 CSS 파일을 막는다(기본값). `엄격` — 기본 + 태그의 `style` 속성도 막는다 |
| 추가 스타일 허용 호스트 | 자동 포함 호스트 밖에서 CSS 파일을 불러오는 경우에만 더한다. 형식은 스크립트 호스트와 같다 |
| 자동으로 포함되는 스타일 호스트 | 활성 템플릿이 선언한 외부 스타일시트의 호스트(읽기 전용) |
| 적용될 스타일 정책 미리보기 | **저장된 값** 기준으로 서버가 조립한 스타일 정책 문자열 |

스타일 정책은 사용자 화면과 관리자 화면 모두에 적용된다. 강제하면 깨지는 화면이 있으므로(9장) 반드시
`점검만` 으로 먼저 확인한다.

입력할 수 없는 값(두 호스트 칸 공통): 따옴표·세미콜론·공백, `'unsafe-inline'` 같은 키워드, `data:`·`blob:`,
`*` 단독, `http://`(평문). 잘못된 값은 저장 시 422 와 함께 칸 아래에 사유가 보인다.

설정 키:

| 키 | 값 | 기본값 |
| --- | --- | --- |
| `security.csp_mode` | `off`·`report_only`·`enforce` | `off` |
| `security.csp_extra_script_hosts` | 호스트 배열(최대 50) | `[]` |
| `security.csp_style_mode` | `off`·`report_only`·`enforce` | `off` |
| `security.csp_style_level` | `basic`·`strict` | `basic` |
| `security.csp_extra_style_hosts` | 호스트 배열(최대 50) | `[]` |

스타일 키 3종은 업데이트 뒤 저장한 적이 없어도 기본값(끔)으로 읽힌다. API: [settings.md](api/settings.md).

## 3. 붙는 정책

### 스크립트 지시어

```text
script-src 'self' {허용 호스트…}; worker-src 'self' blob:; object-src 'none'; base-uri 'self'
```

- 호스트는 소문자로 바꾸고 중복을 지운 뒤 정렬한다.
- `worker-src 'self' blob:` — 레이아웃 편집기(Monaco)의 작업 스레드가 `blob:` 로 뜬다.

### 스타일 지시어 (강도별)

| 강도 | 지시어 | 뜻 |
| --- | --- | --- |
| 기본 `basic` | `style-src-elem 'self' {허용 호스트…}` | `<style>` 요소와 허용 밖 CSS 파일만 막는다. `style` 속성에는 지시어를 내지 않으므로 허용된다 |
| 엄격 `strict` | `style-src 'self' {허용 호스트…}; style-src-attr 'none'` | 기본이 막는 것 + `style` 속성 |

- `data:`·`blob:` 스타일시트는 허용하지 않는다.
- 기본 강도를 `style-src …; style-src-attr 'unsafe-inline'` 로 쓰지 않는 까닭은, `style-src-attr` 을 모르는
  구형 브라우저에서 엄격 강도로 동작해 버리기 때문이다.

### 헤더 조합

스크립트 모드와 스타일 모드의 조합으로 헤더가 0~2개 나간다. `report-uri /api/csp-report` 는 나가는 헤더마다
맨 끝에 한 번 붙는다.

| 스크립트 모드 | 스타일 모드 | 나가는 헤더 |
| --- | --- | --- |
| 끔 | 끔 | 없음 |
| 켜짐 | 끔 | 스크립트 지시어만 실린 헤더 1개(7.1.5 까지와 같은 문자열) |
| 끔 | 켜짐 | 스타일 지시어만 실린 헤더 1개 |
| 같은 모드 | 같은 모드 | 헤더 1개 — 스크립트 지시어 → 스타일 지시어 → `report-uri` |
| 강제 | 점검만 | `Content-Security-Policy`(스크립트) + `Content-Security-Policy-Report-Only`(스타일) |
| 점검만 | 강제 | `Content-Security-Policy-Report-Only`(스크립트) + `Content-Security-Policy`(스타일) |

예시 — 둘 다 강제, 스타일 강도 엄격:

```text
Content-Security-Policy: script-src 'self' t1.kakaocdn.net; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; style-src 'self'; style-src-attr 'none'; report-uri /api/csp-report
```

예시 — 스크립트 강제, 스타일 점검만(강도 기본):

```text
Content-Security-Policy: script-src 'self' t1.kakaocdn.net; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; report-uri /api/csp-report
Content-Security-Policy-Report-Only: style-src-elem 'self'; report-uri /api/csp-report
```

예시 — 스타일만 강제(강도 기본, 운영자가 `fonts.example.com` 추가):

```text
Content-Security-Policy: style-src-elem 'self' fonts.example.com; report-uri /api/csp-report
```

이미지·접속·프레임 출처(`img-src`·`connect-src`·`frame-src`)는 붙이지 않는다(9장).

## 4. 허용 호스트의 출처

### 스크립트 호스트

| 출처 | 내용 |
| --- | --- |
| `'self'` | 사이트 자신 |
| 켜진 모듈·플러그인의 `trusted_script_hosts` | 확장 manifest 선언. 확장을 끄면 빠진다 |
| 켜진 관리자·사용자 템플릿의 `externals` | 템플릿이 외부에서 싣는 스크립트의 호스트 |
| 운영자 추가 호스트 | 2장의 「추가 스크립트 허용 호스트」 |

허용 목록의 정확성은 **그 호스트를 쓰는 확장이 책임진다**. 결제 SDK 처럼 SDK 가 다른 호스트의 스크립트를
이어서 불러오는 경우 그 호스트까지 선언해야 한다(확장 개발: [module-assets.md](../extension/module-assets.md#trusted_script_hosts--외부-스크립트-신뢰-호스트)).
선언이 빠진 호스트는 점검만 모드의 csp.log 에 `blocked_uri` 로 남으므로, 그 확장의 개발자에게 알리고
그 사이에는 운영자 추가 호스트로 보충한다.

### 스타일 호스트

| 출처 | 내용 |
| --- | --- |
| `'self'` | 사이트 자신 (코어·템플릿·확장이 동봉한 CSS 파일) |
| 켜진 관리자·사용자 템플릿의 `externals` | 템플릿이 외부(https)에서 싣는 스타일시트의 호스트 — 종류가 `style`·`webfont` 이거나, `preload` 이면서 `as` 가 `style` 인 항목 |
| 운영자 추가 호스트 | 2장의 「추가 스타일 허용 호스트」 |

모듈·플러그인이 스타일 호스트를 선언하는 manifest 키는 없다. 확장이 외부 CSS 를 꼭 써야 하면 운영자가
추가 호스트로 넣어야 하므로, 확장은 CSS 를 동봉해 같은 사이트에서 싣는 것이 원칙이다.

## 5. .env 로 모드 고정 (탈출구)

```dotenv
G7_CSP_MODE=off         # 스크립트 정책: off | report_only | enforce
G7_CSP_STYLE_MODE=off   # 스타일 정책:   off | report_only | enforce
```

```bash
php artisan config:clear
```

- `.env` 값이 관리자 설정보다 우선한다. 카드의 해당 모드 선택이 잠기고 「.env 의 G7_CSP_MODE=… 가 이 설정보다
  우선합니다」(스타일은 `G7_CSP_STYLE_MODE=…`) 안내가 보인다. 저장된 값은 바뀌지 않는다.
- **두 값은 서로 독립이다.** `G7_CSP_MODE=off` 는 스타일 정책을 끄지 않고, `G7_CSP_STYLE_MODE=off` 는 스크립트
  정책을 끄지 않는다. 둘 다 끄려면 둘 다 적는다.
- 강제 모드에서 관리자 화면이 동작하지 않거나 깨져 보일 때 쓰는 탈출구다. 설정 캐시(`config:cache`)를 쓰는
  서버는 `config:clear` 를 하지 않으면 바뀌지 않는다.
- 값이 세 가지 중 하나가 아니면 무시하고 관리자 설정을 따른다.

## 6. 위반 보고와 csp.log

정책의 `report-uri` 로 브라우저가 위반을 보고한다. 스크립트 위반과 스타일 위반이 같은 경로·같은 로그로 온다.

| 항목 | 값 |
| --- | --- |
| 엔드포인트 | `POST /api/csp-report` (인증 없음) — [api/csp-report.md](api/csp-report.md) |
| 응답 | 204. 본문 16 KB 초과 413. 같은 IP 분당 60회 초과 429 |
| 기록 | `storage/logs/csp-YYYY-MM-DD.log` (일 단위, 14일 보관) |
| 기록 항목 | `document_uri`·`blocked_uri`·`effective_directive`·`disposition`·`source_file`·`line_number`·`sample` |

주소의 쿼리 문자열은 지우고 기록한다(토큰이 실린 주소가 로그에 남지 않게).

```text
[2026-10-31 14:41:26] production.INFO: csp-violation {"document_uri":"https://example.com/","blocked_uri":"https://cdn.other.example/x.js","effective_directive":"script-src-elem","disposition":"report",…}
```

`blocked_uri` 가 `inline` 이면 인라인 스크립트·속성이다. 번들 밖 확장·템플릿이 인라인 코드를 쓰면 이렇게
남으며, 그 확장을 고치기 전까지는 강제로 바꾸지 않는다.

스타일 위반은 `effective_directive` 로 가려 읽는다.

| `effective_directive` | `blocked_uri` | 뜻 |
| --- | --- | --- |
| `style-src-elem` | `inline` | `<style>` 요소 |
| `style-src-elem` | CSS 파일 주소 | 허용 호스트 밖의 CSS 파일 — 믿을 수 있는 호스트면 「추가 스타일 허용 호스트」에 더한다 |
| `style-src-attr` | `inline` | 태그의 `style` 속성(엄격 강도에서만) |

```text
[2026-11-10 10:12:03] production.INFO: csp-violation {"document_uri":"https://example.com/board/free/12","blocked_uri":"inline","effective_directive":"style-src-attr","disposition":"report","line_number":1,"source_file":"https://example.com/board/free/12","sample":"color: red"}
```

## 7. 웹서버에서 직접 거는 경우

G7 설정 대신 웹서버에 직접 걸어도 된다. 응답에 이미 `Content-Security-Policy`(또는 `-Report-Only`)가
있으면 G7 은 헤더를 붙이지 않는다 — 둘 중 하나만 있어도 G7 은 두 헤더 모두 붙이지 않는다. 같은 정책을 두
곳에서 관리하지 않도록 G7 쪽은 스크립트·스타일 모두 `끔` 으로 둔다.

허용 호스트는 관리자 카드의 「적용될 정책 미리보기」와 「적용될 스타일 정책 미리보기」를 그대로 옮기면
된다(켜진 확장·템플릿이 바뀌면 다시 옮긴다). 한 헤더에 함께 적을 때는 `report-uri` 를 맨 끝에 한 번만 둔다.

nginx (스크립트만):

```nginx
add_header Content-Security-Policy "script-src 'self' js.tosspayments.com stdpay.inicis.com t1.kakaocdn.net; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; report-uri /api/csp-report" always;
```

nginx (스크립트 + 엄격한 스타일):

```nginx
add_header Content-Security-Policy "script-src 'self' js.tosspayments.com stdpay.inicis.com t1.kakaocdn.net; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; style-src 'self'; style-src-attr 'none'; report-uri /api/csp-report" always;
```

Apache (스크립트만):

```apache
Header always set Content-Security-Policy "script-src 'self' js.tosspayments.com stdpay.inicis.com t1.kakaocdn.net; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; report-uri /api/csp-report"
```

Apache (스크립트 + 엄격한 스타일):

```apache
Header always set Content-Security-Policy "script-src 'self' js.tosspayments.com stdpay.inicis.com t1.kakaocdn.net; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; style-src 'self'; style-src-attr 'none'; report-uri /api/csp-report"
```

웹서버에서 스타일 정책을 걸 때도 먼저 `Content-Security-Policy-Report-Only` 로 걸어 csp.log 를 확인한다.
웹서버 헤더는 `.env` 탈출구(5장)로 끌 수 없으므로, 화면이 깨지면 웹서버 설정을 되돌린다.

설치 마법사(`/install`)는 Laravel 밖의 PHP 라 G7 설정의 헤더가 붙지 않는다. 설치 화면에도 정책을 걸려면
웹서버 설정으로 건다(설치 마법사도 인라인 실행 코드와 인라인 스타일을 쓰지 않는다).

## 8. 결제·외부 서비스 SDK 허용 호스트

번들 확장이 선언하는 호스트(켜져 있을 때 자동 포함). 2026-10-31 강제 모드 브라우저 실측에서 SDK 로드와
이어지는 스크립트 로드에 위반이 없음을 확인했다. 이 표는 스크립트 정책 기준이다 — 스타일 정책에서의
동작은 9장을 본다.

| 확장 | 호스트 | 비고 |
| --- | --- | --- |
| 토스페이먼츠 | `js.tosspayments.com` | 결제위젯 SDK |
| KG 이니시스 | `stdpay.inicis.com` · `stgstdpay.inicis.com` | 운영 · 테스트 |
| 나이스페이먼츠 | `web.nicepay.co.kr` | |
| NHN KCP | `pay.kcp.co.kr` · `testpay.kcp.co.kr` · `npay.kcp.co.kr` · `testnpay.kcp.co.kr` · `spay.kcp.co.kr` · `testspay.kcp.co.kr` | SDK(`payplus_web.jsp`)가 `npay`(결제 연동)·`spay`(간편결제) 계열 스크립트를 이어서 싣는다 |
| 다음 우편번호 | `t1.kakaocdn.net` (옛 주소 `t1.daumcdn.net` 도 전환기 동안 함께 선언) | 검색 창 내용은 별도 문서(`postcode.map.kakao.com` iframe)라 이 정책의 영향을 받지 않는다 |

결제사·서비스사가 SDK 내부 호스트를 바꾸면 강제 모드 사이트에서 그 기능이 열리지 않는다. 결제를 쓰는
사이트는 PG 계약 변경·SDK 공지 뒤 점검만 모드로 한 번 결제 흐름을 돌려 csp.log 를 확인한다.

## 9. 범위와 한계

### 공통

- **스크립트·스타일 계열만** — 이미지·접속·프레임 출처는 다루지 않는다. 필요하면 웹서버 정책으로 더한다.
- **호스트 허용 목록 방식** — nonce·hash 기반 정책(`strict-dynamic`)보다 느슨하다. 허용 호스트가 임의
  스크립트를 내주는 경로(JSONP 등)를 가지면 그 경로로 우회될 수 있으므로 허용 호스트는 최소로 둔다.
- **붙이지 않는 응답** — HTML 이 아닌 응답, 개발 대시보드(`/dev`, 디버그 + 최고관리자 전용), 검색봇에게
  주는 화면(사람 브라우저에 가지 않는다), 이미 CSP 헤더가 있는 응답.
- **번들 밖 확장·템플릿** — 인라인 코드·인라인 스타일을 쓰면 강제 모드에서 그 기능이 멈추거나 화면이
  깨진다. 점검만으로 먼저 확인한다.

### 스타일 정책의 한계

스타일 정책을 강제하기 전에 아래를 확인한다. 해당 화면을 쓰는 사이트는 `점검만` 으로 둔다.

- **스스로 스타일을 넣는 외부 라이브러리 화면** — 코어·번들 확장이 싣는 외부 라이브러리 가운데 `<style>`
  요소나 `style` 속성을 직접 만드는 것은 고치지 않았다. 강도별로 깨지는 화면은 다음과 같다(환경설정 카드의
  경고 문구와 같은 목록).

  | 화면 | 기본 강제 | 엄격 강제 | 위반 출처 |
  | --- | --- | --- | --- |
  | 관리자 화면의 태그 입력·다중 선택 칸(환경설정 CSP 카드의 허용 호스트 입력 포함) | 칸 안 배치가 흐트러지고 화면 낭독용 안내 문구가 그대로 보인다. 입력·삭제는 된다 | 기본과 같다 | 선택 칸 라이브러리가 만드는 `<style>` 요소 (`style-src-elem`) |
  | 레이아웃 코드 편집기(관리자 > 템플릿 > 레이아웃 편집) | 구문 색과 배경 테마가 빠진다. 편집·저장은 된다 | 기본 + 줄 강조 등 `style` 속성으로 주는 모양도 빠진다 | 코드 편집기 라이브러리의 `<style>` 요소·`style` 속성 |
  | 글쓰기 편집기(CKEditor) | 영향 없음 | 저장된 글의 글자색·정렬·배경색·글자 크기가 편집하는 동안 보이지 않는다(글 보기 화면에는 보인다) | CKEditor 가 다는 `style` 속성 (`style-src-attr`) |
  | 주소 검색(다음 우편번호) | 영향 없음 | 검색 창은 정상으로 뜬다. 우편번호 스크립트의 `style` 속성 위반 1건이 로그에 남는다 | 우편번호 스크립트 |

  글 보기·목록·쇼핑·마이페이지·관리자 대시보드·환경설정 진입·레이아웃 편집기 미리보기(반응형·다크 전환 포함)는
  두 강도 모두 위반 0건이었다(Chrome 156 실측). 이 표의 화면을 쓰는 사이트는 스타일 정책을 `점검만` 으로 둔다.

- **결제·인증 플러그인 4종** — 다음 화면은 인라인 스타일을 그대로 쓴다. 이 플러그인을 켠 사이트에서 스타일
  정책을 강제하면 해당 화면의 모양이 깨질 수 있다.

  | 플러그인 | 화면 |
  | --- | --- |
  | sirsoft-pay_kginicis | 에스크로 구매 확인 창(PC·모바일), 영수증 팝업 |
  | sirsoft-pay_nhnkcp | 결제 프레임 |
  | sirsoft-totp | 2단계 인증 등록 QR 코드 |
  | sirsoft-gdpr | 동의 전에 막아 둔 외부 콘텐츠를 동의 뒤 되살린 자리(원래 요소에 적혀 있던 `style` 속성이 적용되지 않는다) |

- **검색봇에게 주는 화면** — 검색엔진용 HTML 은 `style` 속성을 그대로 담고, G7 은 이 응답에 CSP 헤더를 붙이지
  않는다. 웹서버에서 모든 응답에 엄격한 스타일 정책을 걸면 이 화면에서 위반이 보고된다(봇은 스타일을
  적용하지 않으므로 색인 내용에는 영향이 없다).
- **본문 서식은 화면에서 스크립트가 적용한다** — 게시글·페이지·상품 설명의 글자색·정렬 같은 서식(`style`
  속성)은 저장 데이터에 그대로 있고, 화면에 그릴 때 스크립트가 떼어 두었다가 다시 입힌다. 그래서 엄격한
  정책에서도 서식이 보이지만, 이 처리를 구현하지 않은 번들 밖 템플릿에서는 엄격 강도에서 본문 서식이
  적용되지 않는다(기본 강도에서는 그대로 보인다).
- **구형 브라우저** — `style-src-elem` 을 모르는 브라우저는 기본 강도의 지시어를 무시한다. 그 브라우저에서는
  기본 강도가 아무것도 막지 않는다(화면도 깨지지 않는다). 엄격 강도는 `style-src` 를 쓰므로 구형
  브라우저에서도 막는다. 또 `document.adoptedStyleSheets` 를 지원하지 않는 브라우저에서 스타일 정책을
  강제하면 페이지 이동 덮개(전환 오버레이)가 표시되지 않는다 — 이동과 화면 내용은 정상이다.
- **Firefox·Safari 미측정** — 동작 확인은 Chrome 계열(Chrome 156)에서만 했다. Firefox·Safari 를 쓰는 방문자가
  있는 사이트는 그 브라우저에서 `점검만` 으로 직접 확인한다.
- **점검 화면·DB 접속 오류 화면의 헤더** — 점검 화면(503)과 서버 오류 페이지(404 등)에는 정책 헤더가 붙지 않는다(실측). 이 화면들은 인라인 스타일 없이 정적 CSS 파일 하나로 그려지므로, 웹서버에서 직접 건 정책 아래에서도 모양이 유지된다. DB 접속 오류 화면은 브라우저로 재현하지 못했고 렌더 결과에 인라인 스타일이 없음만 확인했다.
- **설정 화면이 깨져 끌 수 없을 때** — 스타일 정책을 강제한 뒤 관리자 환경설정 화면이 깨져 모드를 되돌릴 수
  없으면 `.env` 에 `G7_CSP_STYLE_MODE=off` 를 적고 `php artisan config:clear` 를 실행한다(5장). 화면이 돌아온 뒤
  모드를 `점검만` 으로 저장하고 `.env` 의 줄을 지운다.

## 10. 확인 방법

```bash
# 응답 헤더 (점검만이면 -Report-Only, 두 정책의 모드가 다르면 두 줄)
curl -sI https://example.com/ | grep -i content-security-policy

# 최근 위반
tail -n 20 storage/logs/csp-$(date +%F).log

# 스타일 위반만
grep -E '"effective_directive":"style-src' storage/logs/csp-$(date +%F).log | tail -n 20
```

브라우저 개발자 도구 콘솔에 `Refused to load the script …` / `Refused to apply inline style …` /
`[Report Only] Refused to …` 가 보이면 그 주소나 그 인라인 코드·스타일이 정책 밖이다.

## 관련 문서

- [api/csp-report.md](api/csp-report.md) — 위반 보고 엔드포인트
- [api/settings.md](api/settings.md) — 환경설정 API (`security.csp_*`, `_meta.csp`)
- [module-assets.md](../extension/module-assets.md#trusted_script_hosts--외부-스크립트-신뢰-호스트) — 확장의 외부 스크립트 호스트 선언, 인라인 스타일 대체 수단
- [frontend/security.md](../frontend/security.md) — 프론트엔드 보안 (HTML 정화·외부 스크립트 허용목록)
