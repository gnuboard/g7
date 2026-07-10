# API 레퍼런스 문서 규정 (API Documentation)

> **관련 문서**: [routing.md](routing.md) | [api-resources.md](api-resources.md) | [response-helper.md](response-helper.md) | [validation.md](validation.md)

---

## TL;DR (5초 요약)

```text
1. 모든 API 엔드포인트는 레퍼런스 문서 필수 — 메서드/URI/파라미터/응답 필드 + 요청·응답 예시 전수 기재
2. 위치: 코어 = docs/backend/api/, 확장 = {modules|plugins}/_bundled/{id}/docs/api/
3. 생성: php artisan api:docgen — 코드에서 추출한 스캐폴딩 + 사람이 서술 보강 (순수 수기 금지)
4. 추출 불가분(훅 주입 파라미터·동적 응답)은 <!-- TODO --> 마커 남기고 사람이 채움
5. Swagger/OpenAPI 도구 미사용 — 마크다운 레퍼런스 전용
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

### 문서 목차와 발견성 (README.md 규약)

각 대상(코어·확장)의 API 문서 디렉토리에는 `README.md` 목차가 있어야 한다. `api:docgen` 이 도메인 파일
목록·엔드포인트 수를 담아 자동 생성한다(`@generated` 블록, 재생성 멱등, 블록 밖 사람 서술은 보존).

- 코어: `docs/backend/api/README.md`
- 확장: `{modules|plugins}/_bundled/{id}/docs/api/README.md`

코어 README 는 프로젝트 최상위 `README.md` 의 "API 레퍼런스" 진입점이다. 따라서 확장 목차와 달리 세
부분으로 구성된다. 이 문서(작성 규정)와 혼동되지 않도록 최상위 `README.md` 는 둘을 "API 레퍼런스" 와
"API 문서 작성 규정" 으로 분리해 링크한다.

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
README.md "API 레퍼런스"  또는  AGENTS.md "API 레퍼런스 진입점" 표
  → docs/backend/api/README.md (공통 규약 + 코어 목차 + 확장 목차)
  → {도메인}.md  또는  {확장}/docs/api/README.md
  → 엔드포인트별 파라미터·응답·예시
```

---

## 표준 문서 포맷

엔드포인트 1개당 아래 6개 구성(헤더 · 요청 파라미터 · 요청 예시 · 응답 필드 · 응답 예시 · 에러 응답)을
따른다. `<!-- @generated:start -->` ~ `<!-- @generated:end -->` 사이는 `api:docgen` 이 재생성하는 추출
블록이며, 그 바깥의 사람 서술(`**설명**`)은 재생성 시 보존된다.

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
- 두 예시 블록은 전량 `@generated` 블록 **내부**에 있다. 다만 이미 파라미터/응답 필드 표의 사람 서술이
  채워진 문서에 예시를 추가할 때는 전체 재생성(`api:docgen`)을 쓰지 않는다 — 전체 재생성은 표를 통째로
  다시 조립해 정적 추출로 재현 불가능한 도메인 서술 셀을 TODO 로 되돌린다. 대신 `api:docgen --examples-only`
  로 표·서술을 건드리지 않고 예시 2블록만 in-place 삽입/치환한다(측정은 재수행하되 표는 불가침). 백필
  커맨드(`backfill-params`/`backfill-fields`)도 예시 블록을 건드리지 않는다.
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

자동 차단: audit 룰 `apidoc-sample-no-global-active-language-pack` (severity: error).

### 설명 백필 커맨드 (`api:docgen-backfill-*`)

파라미터/응답 필드 표의 `<!-- TODO: 설명 -->` 셀 중 **도메인 무관 공통 필드**(페이지네이션·정렬·검색·
식별자·기간·토글·공통 파생 필드 등)는 전체 재생성 없이 in-place 로 자동 서술한다. 전체 재생성
(`api:docgen`)은 `@generated` 블록 표를 통째로 재조립해 실측 예시값·사람 서술 셀을 되돌리므로, TODO
셀만 치환하는 별도 백필 커맨드를 쓴다(멱등 — 채워진 셀·실측 예시값 불가침).

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
- 검증: `api:docgen --check` 로 drift 0 확인. audit 룰 `api-doc-coverage` 가 변경셋에 대응 문서
  동반 여부를 검사한다. severity 는 **대상별**로 부여된다 — 문서가 완비된 대상은 `error`(문서
  미동반 변경 차단), 진행 중 대상은 `warn`. 코어(`docs/backend/api/`)는 2026-07-08 완료로
  `error` 승격됨. 즉 코어 API 표면(`routes/api.php`·`app/Http/{Controllers,Requests,Resources}/**`)을
  변경하면서 코어 API 문서를 함께 갱신하지 않으면 세션 종료 시 차단된다. 나머지 확장은 문서 완비
  시 순차 승격된다(룰의 `ENFORCED_TARGETS`).

---

## 체크리스트

```text
□ 엔드포인트가 대응 위치(코어 docs/backend/api/ 또는 확장 docs/api/)에 문서화되었는가?
□ 요청 파라미터 표에 위치/타입/필수/허용값/용도가 모두 기재되었는가?
□ 요청 예시(raw HTTP 요청) 블록이 방출되었는가? (curl 금지, 인증 필요 시 Bearer {YOUR_TOKEN} 마스킹)
□ 응답 필드 표가 envelope 의 data 내부 기준으로 작성되었는가?
□ 응답 예시(envelope 전문 JSON) 블록이 방출되었는가? (목록은 2항목 절단)
□ 훅 주입 파라미터가 있으면 주석 + 사람 보강이 되었는가?
□ TODO 마커가 모두 채워졌는가?
□ api:docgen --check 가 drift 0 인가?
```

---

## 관련 문서

- [routing.md](routing.md) - 라우트 네이밍/URL 규칙 (확장 URL 스킴은 `/api/modules/{module}/...`)
- [api-resources.md](api-resources.md) - 응답 필드/pagination/abilities 형태
- [response-helper.md](response-helper.md) - 응답 envelope 표준
- [validation.md](validation.md) - FormRequest rule → 파라미터 허용값 유추 근거
