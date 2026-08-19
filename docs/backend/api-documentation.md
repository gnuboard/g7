# API 레퍼런스 문서 규정 (API Documentation)

> **관련 문서**: [routing.md](routing.md) | [api-resources.md](api-resources.md) | [response-helper.md](response-helper.md) | [validation.md](validation.md)

---

## TL;DR (5초 요약)

```text
1. 모든 API 엔드포인트는 레퍼런스 문서 필수 — 메서드/URI/파라미터/응답 필드 + 요청·응답 예시 전수 기재
2. 위치: 코어 = docs/backend/api/, 확장 = {modules|plugins}/_bundled/{id}/docs/api/
3. 생성: php artisan api:docgen — 코드에서 추출한 스캐폴딩 + 사람이 서술 보강 (순수 수기 금지)
4. 추출 불가분(훅 주입 파라미터·동적 응답)은 <!-- TODO --> 마커 남기고 사람이 채움
5. src/routes/api.php 가 없는 web 전용 확장(브라우저 콜백)은 문서 대상 아님
6. Swagger/OpenAPI 도구 미사용 — 마크다운 레퍼런스 전용
```

---

## 목차

1. [왜 이 규정이 필요한가](#왜-이-규정이-필요한가)
2. [문서 위치 규칙](#문서-위치-규칙)
3. [표준 문서 포맷](#표준-문서-포맷)
4. [생성 커맨드 api:docgen](#생성-커맨드-apidocgen)
5. [문서 갱신 의무](#문서-갱신-의무)
6. [체크리스트](#체크리스트)

---

## 왜 이 규정이 필요한가

G7 의 REST API 는 라우트 `->name()` 규약은 있으나 엔드포인트별 공개 레퍼런스가 부재했다. 프론트엔드
(레이아웃 JSON `data_sources`)와 외부 통합 개발자가 소비하는 요청/응답 계약이 코드에만 존재해, 변경 시
소비처가 침묵 속에서 깨진다(이슈 #64 의 `data_source` `auth_required` 계약 변화 사고가 계기).

문서는 **코드에서 추출한 스캐폴딩 + 사람이 채운 서술의 하이브리드**로 유지한다. 674개 규모에서 완전 수기
문서는 반드시 drift 하고, 완전 자동 추출은 훅 주입 파라미터·동적 응답을 못 잡으므로 둘 다 단독으로는
불충분하다.

---

## 문서 위치 규칙

| 대상 | 문서 위치 | 예시 |
|------|----------|------|
| 코어 | `docs/backend/api/{도메인}.md` | `docs/backend/api/users.md` |
| 모듈 | `modules/_bundled/{id}/docs/api/{도메인}.md` | `modules/_bundled/sirsoft-ecommerce/docs/api/products.md` |
| 플러그인 | `plugins/_bundled/{id}/docs/api/{도메인}.md` | `plugins/_bundled/sirsoft-gdpr/docs/api/consents.md` |

확장 API 문서는 **확장이 소유**한다(코어에 모으지 않음). 확장을 배포/삭제하면 그 API 문서도 함께 이동한다.

도메인 그룹핑은 URI/라우트명 prefix 기준(`api.admin.users.*` → `users.md`)으로 커맨드가 자동 분류한다.

### 문서 대상이 아닌 확장 (web 전용 라우트)

확장의 `api/` prefix 라우트는 `src/routes/api.php` 를 통해서만 등록된다
(`{Plugin,Module}RouteServiceProvider` 가 `api.php` → `api/{type}/{id}`, `web.php` → `{type}/{id}` 로 로드).

따라서 `web.php` 만 가진 확장은 API 레퍼런스 문서 대상이 아니다. 브라우저 리다이렉트/콜백
(PG 승인 결과 수신 등)은 JSON API 계약이 아니라 사용자 브라우저를 향한 302 응답이며,
`api:docgen --scope={type}:{id}` 는 이 경우 `범위 '...' 에 해당하는 API 라우트가 없습니다` 로 응답한다.

| 확장 | 라우트 | `docs/api/` |
| --- | --- | --- |
| `sirsoft-pay_kginicis` | `api.php` + `web.php` | 있음 — `api/` 엔드포인트 22건만 문서화 (web 콜백 9건 제외) |
| `sirsoft-tosspayments` | `web.php` | 없음 — PG 콜백 2건 전부 브라우저 리다이렉트 |
| `gnuboard7-hello_plugin` | `web.php` | 없음 |

정적 검사는 `src/routes/api.php` 존재 여부로 이를 판정해, 해당 확장의
라우트/컨트롤러/FormRequest 변경에는 문서 동반을 요구하지 않는다. 확장에 `api.php` 를 추가하면
그 시점부터 자동으로 검사 대상이 되므로, 문서 생성을 함께 수행한다.

### 문서 목차와 발견성 (README.md 규약)

각 대상(코어·확장)의 API 문서 디렉토리에는 `README.md` 목차가 있어야 한다. `api:docgen` 이 도메인 파일
목록·엔드포인트 수를 담아 자동 생성한다(`@generated` 블록, 재생성 멱등, 블록 밖 사람 서술은 보존).

- 코어: `docs/backend/api/README.md`
- 확장: `{modules|plugins}/_bundled/{id}/docs/api/README.md`

코어 README 는 프로젝트 최상위 README 의 "API 레퍼런스" 진입점이다. 따라서 확장 목차와 달리 세
부분으로 구성된다. 이 문서(작성 규정)와 혼동되지 않도록 최상위 README 는 둘을 "API 레퍼런스" 와
"API 문서 작성 규정" 으로 분리해 링크한다. 최상위 README 는 `README.md`(영문)·`README.ko.md`(한국어)
두 벌이며 둘 다 이 진입점을 싣는다 — 한쪽에만 링크가 남으면 그 언어 사용자에게 API 문서가 도달하지 않는다.

| 구성 | 위치 | 소유 |
| --- | --- | --- |
| 공통 규약 개요 (인증·응답 봉투·페이지네이션·에러) | 헤더 인용 블록 뒤 ~ 첫 `@generated` 앞 | 사람 (재생성 시 원문 보존) |
| 코어 도메인 목차 | `@generated:start:api-readme-index` | `api:docgen` |
| 확장 API 목차 | `@generated:start:api-readme-extensions` | `api:docgen` (코어 README 전용) |

개요를 첫 생성 블록 앞에 두는 이유는 목차 표보다 먼저 읽혀야 하기 때문이다. 확장 README 에는 확장 목차
블록을 넣지 않는다. `--scope` 를 좁혀 실행해도 이번 회차에 갱신하지 않는 블록과 사람 서술은 원문 그대로
보존된다.

확장 API 문서의 발견성은 이 목차를 통해 확보한다. `api:docgen` 과 코어 인덱스 생성기
(`generate-docs-index.cjs`) 는 확장명을 하드코딩하지 않고
`{modules,plugins}/_bundled/*/docs/api/README.md` 를 **패턴 스캔**해, 코어 README 의 "확장 API 레퍼런스"
표와 AGENTS.md·docs-index 에 자동 편입한다(동적 로딩 원칙 — 코어는 규약과 스캔 패턴만, 확장
이름은 파일 시스템에서 발견). 문서 수·엔드포인트 수는 각 README 의 집계 라인
(`**문서 수**: N · **엔드포인트 수**: M`)에서 읽으므로, 이 라인 형식을 바꾸면 두 스캐너를 함께 갱신한다.

처음 진입하는 개발자/AI 의 도달 경로:

```text
README.md / README.ko.md "API 레퍼런스"  또는  AGENTS.md "API 레퍼런스 진입점" 표
  → docs/backend/api/README.md (공통 규약 + 코어 목차 + 확장 목차)
  → {도메인}.md  또는  {확장}/docs/api/README.md
  → 엔드포인트별 파라미터·응답·예시
```

---

## 표준 문서 포맷

엔드포인트 1개당 아래 6개 구성(헤더 · 요청 파라미터 · 요청 예시 · 응답 필드 · 응답 예시 · 에러 응답)을
따른다. `<!-- @generated:start -->` ~ `<!-- @generated:end -->` 사이는 `api:docgen` 이 재생성하는 추출
블록이며, 그 바깥의 사람 서술(`**설명**`)은 재생성 시 보존된다.

블록 **안**의 사람 작성분도 보존된다. 자동 생성은 비어 있는 자리를 채울 뿐 사람이 쓴 것을 덮어쓰지 않는다:

| 대상 | 보존 판정 |
| --- | --- |
| 파라미터 표 `용도` 셀 | 행 키(이름+위치)로 대조. 기존 값이 TODO 스텁이 아니면 유지 |
| 응답 필드 표 `용도/설명` 셀 | 행 키(필드)로 대조. 기존 값이 TODO 스텁이 아니면 유지 |
| 응답 필드 표 `실측 예시값` 열 | 같은 필드의 타입이 그대로면 기존 값 유지. 타입이 바뀌면(스펙 변경) 새 실측값 채택. 기존에 없던 신규 필드는 새 값 사용 |
| 응답 필드 본문 (실측 실패로 마커만 남은 자리) | 사람이 채웠으면 유지 |
| 응답 예시 본문 | 실측 산출물에는 `<!-- @probed -->` 출처 마커가 붙는다. 마커가 없으면 사람 작성분으로 보고 유지 |
| 응답 예시 JSON (양쪽 다 실측 산출물) | 응답의 **키 구조**가 같으면 기존 예시 유지. 필드 집합이 달라지면 새 실측 예시 채택 |
| 응답 예시·응답 필드 (이번 실측이 실패) | 기존이 실측 산출물이면 그대로 유지 (마커로 덮지 않는다) |
| 에러 응답 표 | 상태코드 키 단위 병합. 사람이 추가한 행(도메인 특이 에러)은 살리고, 자동 추론에만 있는 상태코드는 편입. 같은 상태코드는 기존 행 우선 |

`@probed` 마커가 필요한 이유: 실측은 1회 호출이 관측한 표본 하나뿐이라, 사람이 코드를 읽고 쓴 사실
(성공 케이스와 엣지 케이스를 함께 제시하는 등)보다 좁다. 실측 결과는 기본값이지 덮어쓰기 권한이 아니다.

**재생성은 멱등해야 한다.** 실측값은 호출 시점 DB 상태가 낳은 표본이라, 그대로 반영하면 코드가 하나도
바뀌지 않은 문서까지 재실행마다 diff 가 난다(`id` 예시값, `updated_at`, `cache_version` 등). 그래서
값이 아니라 **스펙**(필드 집합·타입)이 바뀐 경우에만 갱신한다. 같은 이유로 요청 예시의 path 파라미터는
실측 치환값(`/brands/1`)이 아니라 라우트 정의의 placeholder(`/brands/{brand}`)로 고정한다 — 실측
성패에 따라 예시가 흔들리지 않아야 한다.

실측 실패(`실측 제외: ...`)는 "새로 관측하지 못했다"는 뜻이지 기존 관측이 무효라는 뜻이 아니다.
실측 성패는 호출 시점 데이터 유무에 좌우된다(예: `DELETE /checkout` 은 삭제할 체크아웃이 없으면 404).
따라서 실측 실패가 기존 실측 결과를 지우지 않는다.

nullable 필드는 표본에 값이 있느냐에 따라 관측 타입이 흔들린다(`loggable_type` 이 string ↔ null).
`null` 관측은 타입 변경이 아니라 "이번 표본에 값이 없었다" 는 뜻이므로, 기존에 관측한 실제 값을 유지한다.

에러 응답 표는 라우트 메타에서 대표 상태코드를 자동 추론한다: 인증 필수(`auth:sanctum`)→401,
`admin`/`permission:` 요구→403, FormRequest 검증 규칙 존재→422, path 파라미터 존재→404.
`optional.sanctum`(선택 인증)은 401 을 유발하지 않는다. 도메인 특이 에러(409·429 등)는 사람이 보강한다.

**요청 예시**(raw HTTP 요청)와 **응답 예시**(envelope 전문 JSON)는 실제 호출을 재현할 수 있도록 실측 기반으로
방출된다(단계 6). 요청 예시는 curl 이 아니라 raw HTTP 요청(요청 라인 + 헤더)으로 표기해 응답 예시의
`HTTP/1.1 {status}` 상태줄과 대칭을 이룬다. 세부 규칙:

- **요청 예시**: 요청 라인(`{METHOD} {path} HTTP/1.1`) + `Host:` + `Accept: application/json`, 인증 필요 시
  `Authorization: Bearer {YOUR_TOKEN}`(실측 토큰 평문 유출 방지 마스킹). `Host` 는 실측 기준 URL(로컬 개발
  호스트 등)을 노출하지 않고 공개 placeholder(`api.example.com`, RFC 2606 예약 도메인)로 마스킹한다.
  - **바디 메서드(POST/PUT/PATCH)**: `Content-Type: application/json` + 빈 줄 뒤 JSON 바디. 바디는 필수만이
    아니라 **전체 파라미터**를 담고 값은 이름·타입 기반 현실적 예시값으로 채운다(`"string"` placeholder 남발
    금지). 허용값 `in:` 열거가 있으면 그 첫 값을 채택한다.
  - **파일 업로드**(요청 파라미터 타입이 `image`/`file`): `application/json` 이 아니라
    `Content-Type: multipart/form-data; boundary=...` 로 표기하고 각 파일 파트를 `filename=`+`Content-Type`
    으로 나타낸다(JSON 으로는 파일 전송 불가).
  - **GET/DELETE**: query 파라미터는 URL 쿼리스트링(`?a=..&b=..`)으로 반영한다(바디 아님).
  - `optional.sanctum`(선택 인증) 엔드포인트는 Authorization 헤더에 "비회원은 생략 가능" 주석을 붙인다.
- **응답 예시**: `HTTP/1.1 {status}` 상태줄 + 실측 응답 body 전문(`{success, data, message, error}` envelope).
  목록 응답의 `data.data[]` 는 대표 **2항목**으로 절단하고 나머지는 `... (총 N건 중 2건 표시)` 항목으로 대체한다.
  응답에 섞여 나온 **민감값(토큰·비밀번호·시크릿·API 키)은 `{MASKED}` 로 마스킹**해 방출한다.
- **쓰기 메서드 실측**: GET/HEAD 는 외부 HTTP 로 read-only 실측하고, 쓰기(POST/PUT/PATCH/DELETE)는 **DB 트랜잭션
  안에서 in-process dispatch 후 롤백**하여 응답 shape 만 관측한다(부수효과 미영속). 단, 부수효과가 롤백으로
  되돌릴 수 없는 쓰기(확장 install/activate/update, 언어팩 설치, 코어 업데이트, 파일 업로드, 캐시/워밍업/생성
  등 파일시스템·프로세스·외부 네트워크 접촉)는 실측에서 제외(`side-effectful-write`)하고 요청 예시만 정적 방출한다.
- **실측 제외**(부수효과 쓰기·바이너리·미치환 path 파라미터 등) 엔드포인트: 요청 예시는 파라미터 표 기반으로 정적
  조립(raw HTTP 요청 골격), 응답 예시는 `<!-- 실측 제외: {사유} — 응답 예시는 사람이 작성하세요. -->` 마커로 남긴다.
- 두 예시 블록은 전량 `@generated` 블록 **내부**에 있다. 이미 파라미터/응답 필드 표의 사람 서술이
  채워진 문서에 예시만 추가할 때는 `api:docgen --examples-only` 로 표·서술을 건드리지 않고 예시 2블록만
  in-place 삽입/치환한다(측정은 재수행하되 표는 불가침, 신규 엔드포인트 섹션은 만들지 못한다). 백필
  커맨드(`backfill-params`/`backfill-fields`)도 예시 블록을 건드리지 않는다.

### 재생성이 지우지 못하는 것

전체 재생성(`api:docgen`)은 표를 통째로 다시 조립하지만, **이미 문서에 있던 사실을 지우지 않는다**.
"이번 실행이 알아내지 못했다" 는 "그 사실이 없다" 와 다르기 때문이다. 병합 단계가 다음을 보존한다.

| 대상 | 규칙 |
| --- | --- |
| 표의 설명 셀 | 기존 설명이 있으면 유지. 도구가 만드는 문구는 필드명에서 유추한 일반 문장이라 사람이 적은 도메인 사실보다 정보량이 적다. 값이 바뀌는 컬럼(타입·예시·허용값)은 그대로 재생성된다 |
| 엔드포인트 서술(`**설명**`) | 생성 블록 뒤의 사람 서술을 원문 그대로 되살린다. 생성 마커가 없던 수기 섹션은 헤딩(`### METHOD /uri`)으로 찾아 보존한다 |
| 응답 필드 표 | 이번 실측이 빈 응답이라 표가 사라지는 경우 기존 표를 유지한다 |
| 에러 응답 구간 | 새 문구가 TODO 스텁일 때만 기존 서술을 이어받는다 |

설명을 갱신하려면 그 셀을 직접 고치거나 비운다 — 비운 셀은 다음 재생성이 채운다.

### 응답 예시 크기 상한

응답 예시는 pretty JSON 기준 8KB 를 넘지 않는다. 목록 형태(`data.data[]`)는 대표 2건으로 자르고, 목록이
아닌 큰 응답(편집기 스펙·컴포넌트 카탈로그·라우트 목록 등)은 절단 강도를 단계적으로 올려 상한 이하로
줄인다. 잘린 자리에는 `... (총 N건 중 K건 표시)` / `(N개 키 생략, 총 M개)` 표기를 남겨 무엇이 빠졌는지
문서에 드러낸다. 상한이 없으면 문서 한 파일이 수 MB 가 되어 리뷰·git·에디터가 모두 망가진다.
- **`--examples-only` 모드**: 기존 문서의 각 엔드포인트 `@generated` 블록에 요청 예시(응답 필드 표 앞)와
  응답 예시(에러 응답 표 앞)만 삽입한다. 이미 예시가 있으면 그 블록만 새 실측으로 치환(멱등, 실측 상대시간
  필드는 재측정으로 값이 달라질 수 있음). 표·필드 설명·엔드포인트 서술은 전량 보존된다. 최초 스캐폴딩이
  없는 신규 문서는 먼저 `api:docgen` 으로 생성한 뒤 서술을 채우고, 이후 예시는 `--examples-only` 로 유지한다.

```markdown
### GET /api/admin/users
<!-- @generated:start:api.admin.users.index -->
- **라우트명**: `api.admin.users.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\UserController@index`
- **인증/권한**: `auth:sanctum` + `admin` + `permission:admin,core.users.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
|------|------|------|------|--------|------|
| keyword | query | string | 아니오 | — | <!-- TODO: 용도 --> |
| status | query | string | 아니오 | `active`, `dormant`, `withdrawn` | <!-- TODO: 용도 --> |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있음 (`core.user.search_validation_rules`).

**요청 예시**

​```http
GET /api/admin/users HTTP/1.1
Host: example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
​```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
|------|------|-------------|-----------|
| id | integer | `1` | <!-- TODO: 설명 --> |
| uuid | string | `a231747f-...` | <!-- TODO: 설명 --> |

**응답 예시**

​```http
HTTP/1.1 200
​```

​```json
{
    "success": true,
    "data": {
        "data": [
            { "id": 1, "uuid": "a231747f-..." },
            { "id": 2, "uuid": "b0f2..." },
            "... (총 42건 중 2건 표시)"
        ],
        "pagination": { "current_page": 1, "total": 42 }
    },
    "message": null,
    "error": null
}
​```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`admin,core.users.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
<!-- @generated:end -->

**설명** <!-- 사람이 작성: 이 엔드포인트의 용도, 주의사항, 예시 시나리오 -->
```

### 응답 envelope 표준

모든 응답은 `ResponseHelper` 로 `{success, data, message, error}` 로 래핑된다(response-helper.md).
문서의 "응답 필드" 표는 이 envelope 의 `data` 내부 필드를 기재한다.

- 목록 응답 pagination: `BaseApiCollection::paginationMeta()` →
  `{current_page, last_page, per_page, total, from, to, has_more_pages}`
- 권한 메타: `BaseApiResource::resourceMeta()` → `is_owner` + `abilities.can_*`

### 파라미터 위치 판정

| 위치 | 판정 근거 |
|------|----------|
| `path` | URI 의 `{param}` 세그먼트 |
| `query` | GET/DELETE 요청의 FormRequest rule |
| `body` | POST/PUT/PATCH 요청의 FormRequest rule |

허용값은 FormRequest rule 의 `in:`, `max:`, `min:`, `Rule::in(...)`, `boolean`, `date` 등에서 유추한다.

---

## 생성 커맨드 api:docgen

```bash
# 코어 스캐폴딩 생성 (docs/backend/api/*.md)
php artisan api:docgen --scope=core

# 특정 확장 스캐폴딩 생성
php artisan api:docgen --scope=module:sirsoft-ecommerce
php artisan api:docgen --scope=plugin:sirsoft-gdpr

# 전체
php artisan api:docgen --scope=all

# 생성 없이 누락/drift 만 리포트 (하네스가 소비)
php artisan api:docgen --check

# 이미 서술이 채워진 문서에 요청/응답 예시 블록만 in-place 삽입 (표·서술 불가침, 재생성 금지)
php artisan api:docgen --scope=module:sirsoft-board --seed --base-url=https://example.dev --examples-only

# 생성될 대상만 미리보기
php artisan api:docgen --scope=core --dry-run
```

동작 (실측 기반):

1. `route:list --json` 으로 API 라우트 전수 수집 (method·uri·name·middleware·action).
2. name prefix 로 소유 확장 판별 (`api.modules.{id}.*` / `api.plugins.{id}.*` / 그 외 코어) → 출력 파일 라우팅.
3. 컨트롤러 메서드의 FormRequest 타입힌트 → `rules()` 리플렉션 → 요청 파라미터 표 (타입·필수·허용값).
4. **실측**: 임시 Sanctum 토큰 발급 → 실제 요청 파라미터로 엔드포인트 호출 → **실제 응답 JSON** 관측.
   - GET/HEAD: 실호출(read-only). 목록이 비면 최소 시드 데이터 자동 생성 후 재호출.
   - 쓰기(POST/PUT/PATCH/DELETE): DB 트랜잭션 내 실행 후 롤백(응답 shape 만 관측, 영속 안 함).
   - 외부 부수효과(결제 PG·외부 인증 콜백·메일)가 있는 라우트: allowlist 로 실호출 제외 → 정적+예시 대체.
5. 실제 응답 JSON 의 키·타입·샘플값 → 응답 필드 표 + 응답 예시. `@generated` 블록만 갱신, 사람 서술 보존.
6. 실측 후 임시 토큰·시드 데이터 정리.

한계 / 보강:

- FormRequest 가 `HookManager::applyFilters` 로 규칙을 주입하는 경우(163개) 정적 리플렉션은 훅 주입분을
  못 읽는다 → 커맨드가 훅 필터 존재 시 주석을 남기고 사람이 보강. 단 **응답 필드는 실측이므로 훅으로
  병합된 응답 필드까지 실제로 포착**된다.
- `route:list`(=`RouteFacade::getRoutes()`)는 활성 확장만 노출한다. 명시 범위(`module:{id}`/`plugin:{id}`)로
  지정한 확장이 비활성/미설치여서 등록 라우트가 0건이면, 인벤토리가 그 확장의 번들 라우트 파일
  (`{modules|plugins}/_bundled/{id}/src/routes/api.php`)을 프로바이더와 동일한 prefix
  (`api/{modules|plugins}/{id}`)·name(`api.{modules|plugins}.{id}.`)·`api` 미들웨어 규약으로 로드해
  **정적 폴백 수집**한다. 이때 실측(HTTP 호출)은 불가하므로 응답 필드는 `<!-- 실측 제외 -->` + 정적
  추정으로 대체되며, 설치 후 `--seed` 실측으로 채운다. 폴백은 `api/` 로 시작하는 라우트만 대상이므로
  web(admin) 라우트는 자동 제외된다.
- 실측이 불가한 라우트(외부 의존·allowlist 제외)는 `<!-- 실측 제외: {사유} -->` 마커 + 정적 추정으로 대체.

### 확장 실측 샘플 시더 (`--seed`)

`--seed` 는 상세 GET 실측 시 응답 필드가 null 로 관측되는 것을 줄이기 위해, 도메인 대표 엔티티에
완전한 샘플 레코드를 멱등 시드한다. 코어 도메인은 `App\Support\ApiDoc\ApiDocSampleService` 가 담당한다.

확장은 자신의 도메인 샘플을 **확장이 소유**한다. `App\Contracts\ApiDoc\ApiDocSampleSeeder` 를 구현한
클래스를 규약 위치 `{확장 네임스페이스}\Support\ApiDoc\ApiDocSampleService`
(예: `Modules\Sirsoft\Page\Support\ApiDoc\ApiDocSampleService`, 파일은 `src/Support/ApiDoc/`)에 두면,
`api:docgen --scope=module:{id} --seed` 실행 시 커맨드가 자동으로 발견해 코어 시드 뒤에 병합한다.

- `seed()` 반환 맵의 키는 라우트 도메인 그룹명(`pages` 등), 값은 `{model, key, value}`
  (모델 FQCN·route key 이름·route key 값)이다.
- 이 맵은 상세 GET 의 path 파라미터 치환에 쓰인다. 라우트-모델 바인딩이 없는 확장 패턴
  (`show(int $id)`)도, 파라미터명이 도메인의 단수 리소스명과 일치하면(`pages/{page}`) 이 맵으로 실측된다.
  `{slug}`·`{hash}`·`{versionId}` 처럼 route key 가 다른 문자열/보조 파라미터는 폴백하지 않고 실측 제외된다.
- 확장에 새 PHP 클래스를 추가했으므로 `_bundled` 작업 후 `{type}:update {id} --force` 로 활성 디렉토리에
  반영해야 오토로드된다.

#### 샘플은 시스템 동작을 바꾸지 않는다

`--seed` 로 만든 샘플 레코드는 개발 DB 에 영구 잔존한다. 조회 예시를 만들기 위한 데이터일 뿐이므로,
전역 설정을 읽는 쿼리에 걸리는 조합으로 생성해서는 안 된다.

| ❌ 금지 | ✅ 올바른 사용 |
| --- | --- |
| `LanguagePack::create(['scope' => 'core', 'status' => 'active', ...])` | `['scope' => 'module', 'target_identifier' => '...', 'status' => 'installed']` |
| 실재하지 않는 로케일/통화/국가 코드를 샘플 값으로 사용 | 실재하는 번들 값만 사용 (`ja` 등) |

`scope=core` + `status=active` 조합은 `LanguagePackRepository::getActiveCoreLocales()` 의 승격 쿼리와
일치한다. 걸리면 그 locale 이 `config('app.supported_locales')` / `config('app.translatable_locales')` 로
올라가 시스템 전역이 바뀐다. 그 아래에서 시드·저장되는 모든 다국어 데이터에 실재하지 않는 로케일 키가
박히고, 샘플을 비활성화한 뒤에는 그 키가 `TranslatableField` 검증을 통과하지 못해 해당 엔티티의 저장이
막힌다. 오염 시점과 증상 발현 시점이 떨어져 있어 원인 추적이 어렵다.

같은 원리로 `is_default` / `status=active` 같은 승격·활성 플래그를 샘플에 붙이지 않는다. 샘플 레코드를
만들기 전에 "이 조합이 전역 설정을 읽는 쿼리에 걸리는가" 를 확인한다.

자동 차단: 정적 검사 대상 (위반 시 차단).

### 설명 백필 커맨드 (`api:docgen-backfill-*`)

파라미터/응답 필드 표의 `<!-- TODO: 설명 -->` 셀 중 **도메인 무관 공통 필드**(페이지네이션·정렬·검색·
식별자·기간·토글·공통 파생 필드 등)는 전체 재생성 없이 in-place 로 자동 서술한다. 실측을 재수행하지 않아
빠르고, 실측 예시값도 그대로 둔다(멱등 — 채워진 셀·실측 예시값 불가침).

```bash
# 요청 파라미터 표 TODO 셀 백필 (SSoT: App\Support\ApiDoc\ParameterDescriber)
php artisan api:docgen-backfill-params

# 응답 필드 표 TODO 셀 백필 (SSoT: App\Support\ApiDoc\ResourceFieldDescriber)
php artisan api:docgen-backfill-fields
```

- 도메인 종속 필드(`status`/`type`/`code`/`category`·훅 주입·조건부 필드)는 값 의미가 도메인마다 달라
  자동 채우지 않고 TODO 로 남겨 사람이 컨트롤러/FormRequest/Resource 근거로 서술한다.
- 새 공통 필드 규칙을 Describer 사전에 추가한 뒤 백필을 재실행하면 그 필드가 전 문서에서 자동 채워진다.

---

## 문서 갱신 의무

컨트롤러/라우트/FormRequest/Resource 를 추가·변경하면 대응 API 문서를 같은 변경 단위에서 갱신한다.

- 트리거: `app/Http/Controllers/**`, `routes/api.php`, `app/Http/Requests/**`, `app/Http/Resources/**`
  (+ 확장 대응 경로) 편집.
- 절차: 코드 변경 → `api:docgen --scope=...` 재실행 → `@generated` 블록 갱신 → 신규 TODO 서술 채움.
- 검증: `api:docgen --check` 로 drift 0 확인. 정적 검사가 변경셋에 대응 문서
  동반 여부를 검사한다. 강제 수준은 **대상별**로 다르다 — 문서가 완비된 대상은 차단, 진행 중
  대상은 경고에 그친다. 코어(`docs/backend/api/`)는 2026-07-08 문서 완비로 차단 대상이 됐다.
  즉 코어 API 표면(`routes/api.php`·`app/Http/{Controllers,Requests,Resources}/**`)을
  변경하면서 코어 API 문서를 함께 갱신하지 않으면 그 변경은 통과하지 못한다. 나머지 확장은 문서
  완비 시 순차로 차단 대상에 편입된다.
- 면제: `src/routes/api.php` 가 없는 web 전용 확장은 문서 대상이 아니므로 검사 대상에서 제외된다
  ("문서 대상이 아닌 확장" 참조).

---

## 체크리스트

```text
□ 그 확장에 api/ 표면이 있는가? (src/routes/api.php 부재 = web 전용 → 문서 대상 아님)
□ 엔드포인트가 대응 위치(코어 docs/backend/api/ 또는 확장 docs/api/)에 문서화되었는가?
□ 요청 파라미터 표에 위치/타입/필수/허용값/용도가 모두 기재되었는가?
□ 요청 예시(raw HTTP 요청) 블록이 방출되었는가? (curl 금지, 인증 필요 시 Bearer {YOUR_TOKEN} 마스킹)
□ 응답 필드 표가 envelope 의 data 내부 기준으로 작성되었는가?
□ 응답 예시(envelope 전문 JSON) 블록이 방출되었는가? (목록은 2항목 절단)
□ 훅 주입 파라미터가 있으면 주석 + 사람 보강이 되었는가?
□ 미채움 마커 5종이 전수 0 인가? (아래 참조 — `TODO:` 만 세는 부분 집계 금지)
□ api:docgen --check 가 drift 0 인가?
```

### 미채움 마커 5종 (전수 0 이 완료 기준)

`api:docgen` 은 실측할 수 없는 자리에 "사람이 작성하세요" 마커를 남긴다. **실측 불가는 그 자리를 비워둘
사유가 아니다.** 컨트롤러/Resource/FormRequest/Enum/lang 을 읽어 코드 근거로 채우는 것이 규정이다.

| 마커 | 채울 것 | 근거 소스 |
| --- | --- | --- |
| `<!-- 실측 제외: {사유} — 응답 필드는 사람이 작성하세요. -->` | 응답 필드 표 | Resource `toArray()` 의 키 전수 + 타입, Enum `label()`/`variant()` |
| `<!-- 실측 제외: {사유} — 응답 예시는 사람이 작성하세요. -->` | 응답 예시 JSON | 컨트롤러의 `ResponseHelper::success/paginated/dataSource` 호출 형태 + Resource + lang 의 message 문구 |
| `<!-- TODO: 용도 -->` | 요청 파라미터 설명 셀 | FormRequest `rules()`/`messages()`, 설정 키는 관리자 설정 UI 다국어 라벨 |
| `<!-- TODO: 설명 -->` | 응답 필드 설명 셀 | Resource `toArray()` 파생 로직, 모델 `$casts`/관계, 마이그레이션 한국어 comment |
| `_대표 에러 없음 ... <!-- TODO: ... -->_` | 도메인 특이 에러 표 | 컨트롤러의 `abort()`/예외 throw, Service 의 커스텀 Exception |

도메인 무관하게 의미가 고정된 파라미터/필드는 문서에 손으로 쓰지 말고 `ParameterDescriber`/
`ResourceFieldDescriber` 사전에 등재한다 — 사전은 재생성에 영속하고 신규 문서에도 자동 적용된다.
반대로 **도메인 특이 의미를 공통 사전에 넣지 않는다** (오설명이 전 문서로 퍼진다).

전수 집계는 5종 마커를 한 번에 세야 한다. `TODO:` 만 세면 그 바깥의 미채움(`실측 제외` 등)이 집계에서
빠져 부분 집계가 완료로 통과한다:

```bash
grep -rcE "실측 제외:|TODO: 용도|TODO: 설명|실측 응답에 필드 없음|대표 에러 없음" \
  --include=*.md docs/backend/api {modules,plugins}/_bundled/*/docs/api
```

---

## 관련 문서

- [routing.md](routing.md) - 라우트 네이밍/URL 규칙 (확장 URL 스킴은 `/api/modules/{module}/...`)
- [api-resources.md](api-resources.md) - 응답 필드/pagination/abilities 형태
- [response-helper.md](response-helper.md) - 응답 envelope 표준
- [validation.md](validation.md) - FormRequest rule → 파라미터 허용값 유추 근거
