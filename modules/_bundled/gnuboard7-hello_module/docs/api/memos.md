# Memos API 레퍼런스

> **소유**: module `gnuboard7-hello_module` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Memos 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/gnuboard7-hello_module/memos
<!-- @generated:start:api.modules.gnuboard7-hello_module.memos.index -->
- **라우트명**: `api.modules.gnuboard7-hello_module.memos.index`
- **컨트롤러**: `Modules\Gnuboard7\HelloModule\Http\Controllers\Api\MemoController@index`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/gnuboard7-hello_module/memos HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: http-404 — 응답 필드는 사람이 작성하세요. -->

**에러 응답**

_대표 에러 없음 (공개 조회). <!-- TODO: 도메인 특이 에러가 있으면 보강 -->_

<!-- @generated:end -->

**설명**

메모 목록을 페이지네이션으로 조회하는 공개 엔드포인트다. 라우트는 `optional.sanctum` 미들웨어를 쓰므로 **비로그인 사용자도 조회할 수 있다**(생성기 표기 `auth:sanctum` 은 실제와 다르며, 실제로는 토큰이 있으면 인증 컨텍스트를 붙이되 없어도 통과한다). 이 확장은 학습용 샘플로, 공개 읽기 API 의 표준 패턴(`PublicBaseController` + `throttle:600,1`)을 보여준다.

- **요청 파라미터**: query `per_page`(정수, 기본 10)로 페이지 크기를 조절한다. FormRequest 를 쓰지 않고 컨트롤러가 `$request->query('per_page', 10)` 로 직접 읽는다.
- **응답**: `data` 는 `MemoCollection`(`BaseApiCollection`) 산물로, `data.data` 에 `MemoResource` 배열, `data.meta.pagination` 에 페이지 메타(`current_page`/`last_page`/`per_page`/`total`/`from`/`to`/`has_more_pages`)를 담는다. 각 메모 항목 필드는 아래 상세 조회와 동일하다.
- **미설치 주의**: 이 문서는 확장이 미설치인 상태에서 라우트 파일 정적 분석으로 생성되어 실측 응답이 없다(`http-404`). 설치 후 `api:docgen --scope=module:gnuboard7-hello_module --seed` 로 실측하면 응답 예시가 채워진다.

**응답 예시** (정적 — MemoResource 구조 기준)

```json
{
  "success": true,
  "data": {
    "data": [
      { "id": 1, "uuid": "…", "title": "샘플 메모", "content": "본문", "created_at": "…", "updated_at": "…", "is_owner": false, "abilities": { "can_create": false, "can_update": false, "can_delete": false } }
    ],
    "meta": { "pagination": { "current_page": 1, "last_page": 1, "per_page": 10, "total": 1, "from": 1, "to": 1, "has_more_pages": false } }
  },
  "message": "메모를 조회했습니다.",
  "error": null
}
```


### GET /api/modules/gnuboard7-hello_module/memos/{id}
<!-- @generated:start:api.modules.gnuboard7-hello_module.memos.show -->
- **라우트명**: `api.modules.gnuboard7-hello_module.memos.show`
- **컨트롤러**: `Modules\Gnuboard7\HelloModule\Http\Controllers\Api\MemoController@show`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/modules/gnuboard7-hello_module/memos/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: unresolved-path-param — 응답 필드는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**

단일 메모를 조회하는 공개 엔드포인트다. 목록과 마찬가지로 `optional.sanctum` 이라 비로그인 조회가 허용된다(생성기 표기 `auth:sanctum` 은 실제와 다름).

- **path 파라미터** `{id}`: 메모의 정수 PK. 라우트 제약 `whereNumber('id')` 로 숫자만 매칭된다. 라우트-모델 바인딩 없이 컨트롤러가 `MemoService::getMemo($id)` 로 직접 조회하므로, 존재하지 않으면 `ModelNotFoundException` → `messages.memo.not_found`(404).
- **응답**: `data` 는 단건 `MemoResource` 다. 필드는 `id`(integer), `uuid`(string), `title`(string), `content`(string), 타임스탬프(`created_at`/`updated_at`), 그리고 `BaseApiResource` 공통 메타 `is_owner`(boolean) + `abilities`(`can_create`/`can_update`/`can_delete` — 각 권한 보유 여부). 권한 능력은 `gnuboard7-hello_module.memos.{create,update,delete}` 권한 매핑에서 파생된다.
- **미설치 주의**: 이 문서는 미설치 상태 정적 분석으로 생성되어 실측 응답이 없다(`unresolved-path-param` — 실측할 실제 메모 레코드가 없음). 설치 후 `--seed` 실측 시 실제 값으로 채워진다.

**응답 예시** (정적 — MemoResource 구조 기준)

```json
{
  "success": true,
  "data": {
    "id": 1,
    "uuid": "…",
    "title": "샘플 메모",
    "content": "본문",
    "created_at": "…",
    "updated_at": "…",
    "is_owner": false,
    "abilities": { "can_create": false, "can_update": false, "can_delete": false }
  },
  "message": "메모를 조회했습니다.",
  "error": null
}
```


