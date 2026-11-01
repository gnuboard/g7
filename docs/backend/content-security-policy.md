# 콘텐츠 보안 정책 (Content Security Policy)

사이트 화면 응답에 스크립트 출처 정책(CSP)을 붙여, 허용하지 않은 곳의 스크립트와 인라인 스크립트가
실행되지 않게 하는 방법을 설명한다. 관리자 환경설정으로 켜는 방법과 웹서버에서 직접 거는 방법을 함께 다룬다.

## TL;DR (5초 요약)

```text
1. 환경설정 > 보안 > 「콘텐츠 보안 정책(CSP)」 — 끔(기본) / 점검만 / 강제
2. 점검만으로 먼저 켜고 storage/logs/csp-*.log 에 위반이 없는지 본 뒤 강제로 바꾼다
3. 허용 호스트 = 'self' + 켜진 확장이 선언한 호스트(자동) + 운영자가 더한 호스트
4. 강제 모드로 관리자 화면이 막히면 .env 에 G7_CSP_MODE=off → php artisan config:clear
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

`script-src 'self'`(`unsafe-inline` 없음)가 걸리면 브라우저는 다음을 실행하지 않는다.

- 같은 사이트·허용 호스트 밖에서 불러오는 `<script src>`
- 페이지에 직접 적힌 `<script>…</script>` 본문
- `onclick="…"` 같은 인라인 이벤트 속성, `href="javascript:…"`

게시글·상품 설명처럼 사용자가 넣은 HTML 에 스크립트가 섞여 들어와도 실행되지 않으므로, HTML 정화가
놓친 경우를 한 겹 더 막는다. G7 코어·번들 템플릿·번들 확장은 인라인 실행 코드를 쓰지 않는다
(서버 값은 `<script type="application/json">` 데이터 블록, 동작은 정적 JS 파일).

## 2. 관리자 설정

환경설정 > 보안 탭의 「콘텐츠 보안 정책(CSP)」 카드.

| 항목 | 설명 |
| --- | --- |
| 적용 모드 | `끔` — 헤더를 붙이지 않는다(기본값). `점검만` — `Content-Security-Policy-Report-Only` 헤더. 막지 않고 위반만 기록한다. `강제` — `Content-Security-Policy` 헤더. 위반 스크립트를 실행하지 않는다 |
| 추가 스크립트 허용 호스트 | 자동 포함 호스트 밖에서 스크립트를 불러오는 경우에만 더한다. 형식: `cdn.example.com` · `*.example.com` · `https://cdn.example.com` · 포트(`:8443`) 허용 |
| 자동 포함 호스트 | 켜진 확장이 선언한 호스트(읽기 전용) |
| 적용될 정책 미리보기 | **저장된 값** 기준으로 서버가 조립한 정책 문자열. 실제 응답 헤더와 같다 |

입력할 수 없는 값: 따옴표·세미콜론·공백, `'unsafe-inline'` 같은 키워드, `data:`·`blob:`, `*` 단독,
`http://`(평문). 잘못된 값은 저장 시 422 와 함께 칸 아래에 사유가 보인다.

설정 키: `security.csp_mode`(`off`·`report_only`·`enforce`) · `security.csp_extra_script_hosts`(배열).
API: [settings.md](api/settings.md).

## 3. 붙는 정책

```text
script-src 'self' {허용 호스트…}; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; report-uri /api/csp-report
```

- 호스트는 소문자로 바꾸고 중복을 지운 뒤 정렬한다.
- `worker-src 'self' blob:` — 레이아웃 편집기(Monaco)의 작업 스레드가 `blob:` 로 뜬다.
- 스크립트 계열만 다룬다. `style-src`·`img-src`·`connect-src`·`frame-src` 는 붙이지 않는다(9장).

## 4. 허용 호스트의 출처

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

## 5. .env 로 모드 고정 (탈출구)

```dotenv
G7_CSP_MODE=off   # off | report_only | enforce
```

```bash
php artisan config:clear
```

- `.env` 값이 관리자 설정보다 우선한다. 카드의 모드 선택이 잠기고 「.env 의 G7_CSP_MODE=… 가 이 설정보다
  우선합니다」 안내가 보인다.
- 강제 모드에서 관리자 화면이 동작하지 않을 때 쓰는 탈출구다. 설정 캐시(`config:cache`)를 쓰는 서버는
  `config:clear` 를 하지 않으면 바뀌지 않는다.
- 값이 세 가지 중 하나가 아니면 무시하고 관리자 설정을 따른다.

## 6. 위반 보고와 csp.log

정책의 `report-uri` 로 브라우저가 위반을 보고한다.

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

## 7. 웹서버에서 직접 거는 경우

G7 설정 대신 웹서버에 직접 걸어도 된다. 응답에 이미 `Content-Security-Policy`(또는 `-Report-Only`)가
있으면 G7 은 헤더를 붙이지 않는다. 같은 정책을 두 곳에서 관리하지 않도록 G7 쪽은 `끔` 으로 둔다.

허용 호스트는 관리자 카드의 「적용될 정책 미리보기」를 그대로 옮기면 된다(켜진 확장이 바뀌면 다시 옮긴다).

nginx:

```nginx
add_header Content-Security-Policy "script-src 'self' js.tosspayments.com stdpay.inicis.com t1.daumcdn.net; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; report-uri /api/csp-report" always;
```

Apache:

```apache
Header always set Content-Security-Policy "script-src 'self' js.tosspayments.com stdpay.inicis.com t1.daumcdn.net; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; report-uri /api/csp-report"
```

설치 마법사(`/install`)는 Laravel 밖의 PHP 라 G7 설정의 헤더가 붙지 않는다. 설치 화면에도 정책을 걸려면
웹서버 설정으로 건다(설치 마법사도 인라인 실행 코드를 쓰지 않는다).

## 8. 결제·외부 서비스 SDK 허용 호스트

번들 확장이 선언하는 호스트(켜져 있을 때 자동 포함). 2026-10-31 강제 모드 브라우저 실측에서 SDK 로드와
이어지는 스크립트 로드에 위반이 없음을 확인했다.

| 확장 | 호스트 | 비고 |
| --- | --- | --- |
| 토스페이먼츠 | `js.tosspayments.com` | 결제위젯 SDK |
| KG 이니시스 | `stdpay.inicis.com` · `stgstdpay.inicis.com` | 운영 · 테스트 |
| 나이스페이먼츠 | `web.nicepay.co.kr` | |
| NHN KCP | `pay.kcp.co.kr` · `testpay.kcp.co.kr` · `npay.kcp.co.kr` · `testnpay.kcp.co.kr` · `spay.kcp.co.kr` · `testspay.kcp.co.kr` | SDK(`payplus_web.jsp`)가 `npay`(결제 연동)·`spay`(간편결제) 계열 스크립트를 이어서 싣는다 |
| Daum 우편번호 | `t1.daumcdn.net` | 검색 창 내용은 별도 문서(카카오)라 이 정책의 영향을 받지 않는다 |

결제사·서비스사가 SDK 내부 호스트를 바꾸면 강제 모드 사이트에서 그 기능이 열리지 않는다. 결제를 쓰는
사이트는 PG 계약 변경·SDK 공지 뒤 점검만 모드로 한 번 결제 흐름을 돌려 csp.log 를 확인한다.

## 9. 범위와 한계

- **스크립트 계열만** — 스타일·이미지·접속·프레임 출처는 다루지 않는다. 필요하면 웹서버 정책으로 더한다.
- **호스트 허용 목록 방식** — nonce·hash 기반 정책(`strict-dynamic`)보다 느슨하다. 허용 호스트가 임의
  스크립트를 내주는 경로(JSONP 등)를 가지면 그 경로로 우회될 수 있으므로 허용 호스트는 최소로 둔다.
- **붙이지 않는 응답** — HTML 이 아닌 응답, 개발 대시보드(`/dev`, 디버그 + 최고관리자 전용), 검색봇에게
  주는 화면(사람 브라우저에 가지 않는다), 이미 CSP 헤더가 있는 응답.
- **번들 밖 확장·템플릿** — 인라인 코드를 쓰면 강제 모드에서 그 기능이 멈춘다. 점검만으로 먼저 확인한다.

## 10. 확인 방법

```bash
# 응답 헤더 (점검만이면 -Report-Only)
curl -sI https://example.com/ | grep -i content-security-policy

# 최근 위반
tail -n 20 storage/logs/csp-$(date +%F).log
```

브라우저 개발자 도구 콘솔에 `Refused to load the script …` / `[Report Only] Refused to …` 가 보이면 그
주소가 허용 목록 밖이다.

## 관련 문서

- [api/csp-report.md](api/csp-report.md) — 위반 보고 엔드포인트
- [api/settings.md](api/settings.md) — 환경설정 API (`security.csp_*`, `_meta.csp`)
- [module-assets.md](../extension/module-assets.md#trusted_script_hosts--외부-스크립트-신뢰-호스트) — 확장의 외부 스크립트 호스트 선언
- [frontend/security.md](../frontend/security.md) — 프론트엔드 보안 (HTML 정화·외부 스크립트 허용목록)
