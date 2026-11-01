# Scim API 레퍼런스

> **소유**: plugin `sirsoft-sso` · **생성**: 손 작성(1.0.0, 코드 근거). @generated 블록은 다음 `php artisan api:docgen` 재생성 때 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. SCIM 2.0 서버다. IdP(Entra ID, Okta 등)의 SCIM 클라이언트가 부른다. 기준 주소는 /api/plugins/sirsoft-sso/scim/v2/{IdP 키}
2. 응답은 G7 공통 응답 형식이 아니라 application/scim+json 자원 그대로다. 오류는 SCIM 오류 스키마(schemas, status, scimType, detail)
3. 인증은 IdP 마다 발급한 Bearer 토큰(관리자 API POST admin/idps/{id}/scim-token). IdP 가 꺼졌거나 SCIM 사용이 꺼지면 토큰이 맞아도 403
4. DELETE /Users/{id} 는 회원을 지우지 않고 퇴사 처리(차단 + 토큰 회수)한다. DELETE 뒤 GET 은 404 가 아니라 active:false 자원을 돌려준다
   — RFC 7644 §3.6(삭제 뒤 404)과 다른 점이며 게시글, 주문 기록 보존 때문이다
5. filter 는 attribute eq "값" 하나만(Users: userName, externalId, emails.value / Groups: displayName, externalId). 정렬, Bulk, ETag, /Me 미지원
6. PATCH 는 IdP 마다 다른 모양을 받고 Users 의 모르는 속성은 무시한다. Groups 의 모르는 경로는 400 invalidPath
7. 응답은 미실측 — 실측 예시값 열은 —, 응답 예시는 코드에서 옮긴 모양(… 는 실제 값 자리)
```

---

## 공통 사항

**기준 주소**: `{사이트 주소}/api/plugins/sirsoft-sso/scim/v2/{IdP 키}` — 관리자 IdP 편집 「프로비저닝」 탭과 토큰 발급 응답의 `endpoint`. `meta.location` 과 `$ref` 도 요청 Host 가 아니라 사이트 주소로 만든다.

**인증**: `Authorization: Bearer {SCIM 토큰}`. 토큰은 IdP 마다 다르고 sha256 해시로만 저장한다. 경로의 IdP 키와 토큰이 맞아야 한다.

| 상태 | 조건 | 응답 |
| --- | --- | --- |
| 401 | 토큰이 없거나 틀렸거나, 경로의 IdP 키가 없음 | SCIM 오류 + `WWW-Authenticate: Bearer realm="scim"` |
| 403 | IdP 가 꺼져 있거나 IdP 의 SCIM 사용(`deprovision.scim`)이 꺼짐 | SCIM 오류 |

**요청 본문**: `Content-Type: application/scim+json` 또는 `application/json`.

**응답**: `Content-Type: application/scim+json`. 자원 본문 그대로이고 G7 봉투(`success`, `data`)가 없다. 생성은 201 + `Location` 헤더, 삭제는 204(본문 없음).

**오류 본문** (RFC 7644 §3.12):

```json
{
    "schemas": ["urn:ietf:params:scim:api:messages:2.0:Error"],
    "status": "409",
    "scimType": "uniqueness",
    "detail": "같은 식별자의 자원이 이미 있습니다."
}
```

`status` 는 문자열이다. `scimType` 이 없는 오류(401, 403, 404)는 그 키를 싣지 않는다. `detail` 은 요청 로케일 문구이고 개인정보를 싣지 않는다.

| 상태 | `scimType` | 조건 |
| --- | --- | --- |
| 400 | `invalidValue` | 요청 값 검증 실패, Users 생성에 대조 키 값이 없음, Groups 생성에 `displayName` 이 없음 |
| 400 | `invalidSyntax` | PATCH 본문 검증 실패(`Operations` 없음 등), 모르는 `op`, 경로 없는 `add`/`replace` 의 값이 객체가 아님 |
| 400 | `noTarget` | Users PATCH 의 `remove` 에 `path` 가 없음 |
| 400 | `invalidPath` | Groups PATCH 의 모르는 경로 |
| 400 | `invalidFilter` | `filter` 형식이 틀렸거나 받지 않는 속성 |
| 404 | (없음) | 자원이 없음(다른 IdP 의 자원도 404) |
| 409 | `uniqueness` | 같은 대조 키 또는 같은 그룹 이름이 있음, 같은 이메일의 기존 회원에 연결할 수 없음, 관리자 계정 |
| 429 | — | 요청 제한 `throttle:300,1,sirsoft-sso.scim` 초과(SCIM 오류 스키마가 아닌 코어 응답) |

**대조 키**: IdP 설정 `deprovision.scim_match` 가 `userName`(기본) 또는 `externalId` 를 고른다. 그 값이 연결의 고유 식별자(subject)이며, 로그인 때 IdP 가 보내는 고유 식별자(OIDC `sub` 나 `oid`, SAML NameID)와 같아야 SCIM 으로 만든 회원과 로그인한 회원이 이어진다.

**지원 범위** (ServiceProviderConfig): `patch` 지원, `filter` 지원(최대 100건), `bulk`, `changePassword`, `sort`, `etag` 미지원. `/Me` 와 `/Bulk` 주소는 없다. 목록의 `sortBy`, `attributes` 는 무시하고, `excludedAttributes=members` 만 Groups 에서 쓴다.

**수신 이력**: 요청마다 메서드, 자원, 대상 ID, 상태, `scimType` 이 남는다(본문은 남지 않는다). 관리자 `GET /api/plugins/sirsoft-sso/admin/logs/scim`(logs.md).


### GET /api/plugins/sirsoft-sso/scim/v2/{idp}/Groups
<!-- @generated:start:api.plugins.sirsoft-sso.scim.groups.index -->
- **라우트명**: `api.plugins.sirsoft-sso.scim.groups.index`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Scim\ScimGroupController@index`
- **인증/권한**: IdP 별 SCIM Bearer 토큰(`ScimBearerAuth`)
- **요청 제한**: `throttle:300,1,sirsoft-sso.scim`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | IdP 키 |
| filter | query | string | 아니오 | 500자, `displayName eq "값"` 또는 `externalId eq "값"` | 한 속성 일치 거르기(속성 이름 대소문자 무관) |
| startIndex | query | integer | 아니오 | 1 이상(기본 1) | 1부터 세는 시작 위치 |
| count | query | integer | 아니오 | 0~100(기본 100) | 한 번에 받을 건수 |
| excludedAttributes | query | string | 아니오 | 200자 | `members` 가 들어 있으면 소속을 빼고 답한다 |

**요청 예시**

```http
GET /api/plugins/sirsoft-sso/scim/v2/{idp}/Groups?filter=displayName%20eq%20%22%EC%9D%B8%EC%82%AC%ED%8C%80%22&excludedAttributes=members HTTP/1.1
Host: api.example.com
Accept: application/scim+json
Authorization: Bearer {SCIM_TOKEN}
```

**응답 필드** (SCIM ListResponse 본문 — G7 봉투 없음)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| schemas | array | — | `["urn:ietf:params:scim:api:messages:2.0:ListResponse"]` |
| totalResults | integer | — | 조건에 맞는 전체 수 |
| startIndex | integer | — | 요청한 시작 위치 |
| itemsPerPage | integer | — | 이번 응답의 자원 수 |
| Resources | array | — | Group 자원(아래 「Group 자원」) |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Content-Type: application/scim+json
```

```json
{
    "schemas": ["urn:ietf:params:scim:api:messages:2.0:ListResponse"],
    "totalResults": 1,
    "startIndex": 1,
    "itemsPerPage": 1,
    "Resources": [
        {
            "schemas": ["urn:ietf:params:scim:schemas:core:2.0:Group"],
            "id": "…",
            "displayName": "인사팀",
            "meta": { "resourceType": "Group", "created": "…", "lastModified": "…", "location": "https://www.example.com/api/plugins/sirsoft-sso/scim/v2/…/Groups/…" }
        }
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | `invalidFilter`(형식 오류 또는 받지 않는 속성), `invalidValue`(파라미터 검증 실패) |
| 401 | Unauthorized | SCIM 토큰이 없거나 틀림, 없는 IdP 키 |
| 403 | Forbidden | IdP 꺼짐 또는 SCIM 사용 꺼짐 |
| 404 | Not Found | 이 주소에는 찾을 자원이 없어 404 를 내지 않는다. 없는 IdP 키는 401(`ScimBearerAuth`). `idp` 가 `^[a-z][a-z0-9_]{1,49}$` 에 맞지 않을 때만 라우트 제약으로 404 |
| 422 | Unprocessable Entity | SCIM 엔드포인트는 422 를 돌려주지 않는다. 검증 실패는 위 400 `invalidValue`(SCIM 오류 본문) |

<!-- @generated:end -->

**설명**

이 IdP 의 그룹만 돌려준다. 그룹은 역할 매핑 입력으로만 쓴다(IdP 의 그룹 출처 `group_source` 가 `scim` 일 때). G7 역할을 직접 만들지 않는다.

**Group 자원**

| 필드 | 타입 | 설명 |
| --- | --- | --- |
| schemas | array | `["urn:ietf:params:scim:schemas:core:2.0:Group"]` |
| id | string | 그룹 ID(숫자 문자열) |
| displayName | string | 그룹 이름(255자, IdP 안에서 겹치지 않음) |
| externalId | string | IdP 쪽 ID. 값이 있을 때만 |
| members | array | `{value, $ref}`. `value` 는 User 자원 `id`. `excludedAttributes=members` 면 없다 |
| meta | object | `{resourceType: "Group", created, lastModified, location}` |


### POST /api/plugins/sirsoft-sso/scim/v2/{idp}/Groups
<!-- @generated:start:api.plugins.sirsoft-sso.scim.groups.store -->
- **라우트명**: `api.plugins.sirsoft-sso.scim.groups.store`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Scim\ScimGroupController@store`
- **인증/권한**: IdP 별 SCIM Bearer 토큰(`ScimBearerAuth`)
- **요청 제한**: `throttle:300,1,sirsoft-sso.scim`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | IdP 키 |
| schemas | body | array | 아니오 | — | SCIM 스키마 URN(읽지 않는다) |
| displayName | body | string | 예 | 255자 | 그룹 이름 |
| externalId | body | string\|null | 아니오 | 191자 | IdP 쪽 ID |
| members | body | array\|null | 아니오 | 5000개 | 소속 `{value}`. `value` 는 이 IdP 의 User 자원 `id`. 다른 값은 조용히 뺀다 |

**요청 예시**

```http
POST /api/plugins/sirsoft-sso/scim/v2/{idp}/Groups HTTP/1.1
Host: api.example.com
Accept: application/scim+json
Authorization: Bearer {SCIM_TOKEN}
Content-Type: application/scim+json

{
    "schemas": ["urn:ietf:params:scim:schemas:core:2.0:Group"],
    "displayName": "인사팀",
    "externalId": "{IDP_GROUP_ID}",
    "members": [{ "value": "{USER_RESOURCE_ID}" }]
}
```

**응답 필드** (Group 자원 — G7 봉투 없음)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | string | — | 만든 그룹 ID |
| displayName | string | — | 그룹 이름 |
| externalId | string | — | 보냈을 때만 |
| members | array | — | 실제로 붙은 소속 `{value, $ref}` |
| meta | object | — | `location` 은 `Location` 헤더와 같다 |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 201
Content-Type: application/scim+json
Location: https://www.example.com/api/plugins/sirsoft-sso/scim/v2/…/Groups/…
```

```json
{
    "schemas": ["urn:ietf:params:scim:schemas:core:2.0:Group"],
    "id": "…",
    "displayName": "인사팀",
    "externalId": "…",
    "members": [{ "value": "…", "$ref": "https://www.example.com/api/plugins/sirsoft-sso/scim/v2/…/Users/…" }],
    "meta": { "resourceType": "Group", "created": "…", "lastModified": "…", "location": "https://www.example.com/api/plugins/sirsoft-sso/scim/v2/…/Groups/…" }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | `invalidValue` — 검증 실패 또는 `displayName` 이 비었음 |
| 401 | Unauthorized | SCIM 토큰이 없거나 틀림, 없는 IdP 키 |
| 403 | Forbidden | IdP 꺼짐 또는 SCIM 사용 꺼짐 |
| 404 | Not Found | 이 주소에는 찾을 자원이 없어 404 를 내지 않는다. 없는 IdP 키는 401(`ScimBearerAuth`). `idp` 가 `^[a-z][a-z0-9_]{1,49}$` 에 맞지 않을 때만 라우트 제약으로 404 |
| 409 | Conflict | `uniqueness` — 같은 이름의 그룹이 이 IdP 에 있음 |
| 422 | Unprocessable Entity | SCIM 엔드포인트는 422 를 돌려주지 않는다. 검증 실패는 위 400 `invalidValue`(SCIM 오류 본문) |

<!-- @generated:end -->

**설명**

소속이 바뀐 회원은 IdP 의 그룹 출처가 `scim` 이면 역할 매핑을 다시 돈다(활성 연결만).


### DELETE /api/plugins/sirsoft-sso/scim/v2/{idp}/Groups/{id}
<!-- @generated:start:api.plugins.sirsoft-sso.scim.groups.destroy -->
- **라우트명**: `api.plugins.sirsoft-sso.scim.groups.destroy`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Scim\ScimGroupController@destroy`
- **인증/권한**: IdP 별 SCIM Bearer 토큰(`ScimBearerAuth`)
- **요청 제한**: `throttle:300,1,sirsoft-sso.scim`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | IdP 키 |
| id | path | string | 예 | 숫자 1~19자리 | 그룹 ID |
| filter | query | string | 아니오 | max 500 | 이 주소에서는 쓰지 않는다. 문자열이 아니거나 500자를 넘으면 400 `invalidValue` |
| startIndex | query | integer | 아니오 | — | 이 주소에서는 쓰지 않는다. 정수가 아니면 400 `invalidValue` |
| count | query | integer | 아니오 | — | 이 주소에서는 쓰지 않는다. 정수가 아니면 400 `invalidValue` |
| excludedAttributes | query | string | 아니오 | max 200 | 이 주소에서는 쓰지 않는다 |

**요청 예시**

```http
DELETE /api/plugins/sirsoft-sso/scim/v2/{idp}/Groups/{id} HTTP/1.1
Host: api.example.com
Accept: application/scim+json
Authorization: Bearer {SCIM_TOKEN}
```

**응답 필드** (본문 없음)

204 응답이라 본문이 없다.

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 204
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthorized | SCIM 토큰이 없거나 틀림, 없는 IdP 키 |
| 403 | Forbidden | IdP 꺼짐 또는 SCIM 사용 꺼짐 |
| 404 | Not Found | 이 IdP 에 그 그룹이 없음 |
| 422 | Unprocessable Entity | SCIM 엔드포인트는 422 를 돌려주지 않는다. 검증 실패는 400 `invalidValue`(SCIM 오류 본문, 공통 사항 오류 표) |

<!-- @generated:end -->

**설명**

그룹은 실제로 지운다(User 와 다르다). 옛 소속 회원은 그룹 출처가 `scim` 이면 역할 매핑을 다시 돈다.


### GET /api/plugins/sirsoft-sso/scim/v2/{idp}/Groups/{id}
<!-- @generated:start:api.plugins.sirsoft-sso.scim.groups.show -->
- **라우트명**: `api.plugins.sirsoft-sso.scim.groups.show`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Scim\ScimGroupController@show`
- **인증/권한**: IdP 별 SCIM Bearer 토큰(`ScimBearerAuth`)
- **요청 제한**: `throttle:300,1,sirsoft-sso.scim`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | IdP 키 |
| id | path | string | 예 | 숫자 1~19자리 | 그룹 ID |
| filter | query | string | 아니오 | max 500 | 이 주소에서는 쓰지 않는다. 문자열이 아니거나 500자를 넘으면 400 `invalidValue` |
| startIndex | query | integer | 아니오 | — | 이 주소에서는 쓰지 않는다. 정수가 아니면 400 `invalidValue` |
| count | query | integer | 아니오 | — | 이 주소에서는 쓰지 않는다. 정수가 아니면 400 `invalidValue` |
| excludedAttributes | query | string | 아니오 | 200자 | `members` 가 들어 있으면 소속을 뺀다 |

**요청 예시**

```http
GET /api/plugins/sirsoft-sso/scim/v2/{idp}/Groups/{id} HTTP/1.1
Host: api.example.com
Accept: application/scim+json
Authorization: Bearer {SCIM_TOKEN}
```

**응답 필드** (Group 자원 — G7 봉투 없음)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| schemas | array | — | Group 스키마 URN |
| id | string | — | 그룹 ID |
| displayName | string | — | 그룹 이름 |
| externalId | string | — | 값이 있을 때만 |
| members | array | — | `{value, $ref}` |
| meta | object | — | `{resourceType, created, lastModified, location}` |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Content-Type: application/scim+json
```

```json
{
    "schemas": ["urn:ietf:params:scim:schemas:core:2.0:Group"],
    "id": "…",
    "displayName": "…",
    "members": [],
    "meta": { "resourceType": "Group", "created": "…", "lastModified": "…", "location": "…" }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthorized | SCIM 토큰이 없거나 틀림, 없는 IdP 키 |
| 403 | Forbidden | IdP 꺼짐 또는 SCIM 사용 꺼짐 |
| 404 | Not Found | 이 IdP 에 그 그룹이 없음 |
| 422 | Unprocessable Entity | SCIM 엔드포인트는 422 를 돌려주지 않는다. 검증 실패는 400 `invalidValue`(SCIM 오류 본문, 공통 사항 오류 표) |

<!-- @generated:end -->

**설명**

Group 자원 모양은 목록 절의 「Group 자원」 표와 같다.


### PATCH /api/plugins/sirsoft-sso/scim/v2/{idp}/Groups/{id}
<!-- @generated:start:api.plugins.sirsoft-sso.scim.groups.patch -->
- **라우트명**: `api.plugins.sirsoft-sso.scim.groups.patch`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Scim\ScimGroupController@patch`
- **인증/권한**: IdP 별 SCIM Bearer 토큰(`ScimBearerAuth`)
- **요청 제한**: `throttle:300,1,sirsoft-sso.scim`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | IdP 키 |
| id | path | string | 예 | 숫자 1~19자리 | 그룹 ID |
| schemas | body | array | 아니오 | — | `urn:ietf:params:scim:api:messages:2.0:PatchOp`(읽지 않는다) |
| Operations | body | array | 예 | 1~200개 | 연산 목록 |
| Operations[].op | body | string | 예 | `add`, `replace`, `remove`(대소문자 무관) | 연산 |
| Operations[].path | body | string\|null | 아니오 | 500자, 아래 경로 | 대상 경로 |
| Operations[].value | body | mixed | 아니오 | — | 값 |

받는 경로: `displayName`, `externalId`, `members`, `members[value eq "{id}"]`(`remove` 만). 경로 없이 객체 값(`{displayName, externalId, members}`)도 받는다. `members` 는 `add` 면 더하고, `replace` 면 통째로 바꾸고, `remove` 는 값의 `value` 들을 빼며 값이 없으면 전부 뺀다.

**요청 예시**

```http
PATCH /api/plugins/sirsoft-sso/scim/v2/{idp}/Groups/{id} HTTP/1.1
Host: api.example.com
Accept: application/scim+json
Authorization: Bearer {SCIM_TOKEN}
Content-Type: application/scim+json

{
    "schemas": ["urn:ietf:params:scim:api:messages:2.0:PatchOp"],
    "Operations": [
        { "op": "add", "path": "members", "value": [{ "value": "{USER_RESOURCE_ID}" }] },
        { "op": "remove", "path": "members[value eq \"{USER_RESOURCE_ID}\"]" }
    ]
}
```

**응답 필드** (Group 자원 — G7 봉투 없음)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | string | — | 그룹 ID |
| displayName | string | — | 바뀐 뒤 이름 |
| members | array | — | 바뀐 뒤 소속(언제나 싣는다) |
| meta | object | — | `{resourceType, created, lastModified, location}` |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Content-Type: application/scim+json
```

```json
{
    "schemas": ["urn:ietf:params:scim:schemas:core:2.0:Group"],
    "id": "…",
    "displayName": "…",
    "members": [{ "value": "…", "$ref": "…" }],
    "meta": { "resourceType": "Group", "created": "…", "lastModified": "…", "location": "…" }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | `invalidSyntax`(검증 실패, 모르는 `op`), `invalidPath`(위 목록에 없는 경로) |
| 401 | Unauthorized | SCIM 토큰이 없거나 틀림, 없는 IdP 키 |
| 403 | Forbidden | IdP 꺼짐 또는 SCIM 사용 꺼짐 |
| 404 | Not Found | 이 IdP 에 그 그룹이 없음 |
| 409 | Conflict | `uniqueness` — 바꾸려는 이름을 다른 그룹이 씀 |
| 422 | Unprocessable Entity | SCIM 엔드포인트는 422 를 돌려주지 않는다. 검증 실패는 위 400 `invalidSyntax`(SCIM 오류 본문) |

<!-- @generated:end -->

**설명**

연산을 순서대로 모은 뒤 소속 변경을 한 번에 반영한다. 이 IdP 의 연결이 아닌 `value` 는 조용히 뺀다. 소속이 바뀐 회원은 그룹 출처가 `scim` 이면 역할 매핑을 다시 돈다.


### PUT /api/plugins/sirsoft-sso/scim/v2/{idp}/Groups/{id}
<!-- @generated:start:api.plugins.sirsoft-sso.scim.groups.update -->
- **라우트명**: `api.plugins.sirsoft-sso.scim.groups.update`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Scim\ScimGroupController@update`
- **인증/권한**: IdP 별 SCIM Bearer 토큰(`ScimBearerAuth`)
- **요청 제한**: `throttle:300,1,sirsoft-sso.scim`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | IdP 키 |
| id | path | string | 예 | 숫자 1~19자리 | 그룹 ID |
| schemas | body | array | 아니오 | — | 읽지 않는다 |
| displayName | body | string | 예 | 255자 | 그룹 이름 |
| externalId | body | string\|null | 아니오 | 191자 | 보내면 바꾼다(빈 값이면 지운다) |
| members | body | array\|null | 아니오 | 5000개 | 소속 전체. **보내지 않으면 소속을 모두 뺀다** |

**요청 예시**

```http
PUT /api/plugins/sirsoft-sso/scim/v2/{idp}/Groups/{id} HTTP/1.1
Host: api.example.com
Accept: application/scim+json
Authorization: Bearer {SCIM_TOKEN}
Content-Type: application/scim+json

{
    "schemas": ["urn:ietf:params:scim:schemas:core:2.0:Group"],
    "displayName": "인사팀",
    "members": [{ "value": "{USER_RESOURCE_ID}" }]
}
```

**응답 필드** (Group 자원 — G7 봉투 없음)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | string | — | 그룹 ID |
| displayName | string | — | 이름 |
| members | array | — | 바뀐 뒤 소속 |
| meta | object | — | `{resourceType, created, lastModified, location}` |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Content-Type: application/scim+json
```

```json
{
    "schemas": ["urn:ietf:params:scim:schemas:core:2.0:Group"],
    "id": "…",
    "displayName": "인사팀",
    "members": [{ "value": "…", "$ref": "…" }],
    "meta": { "resourceType": "Group", "created": "…", "lastModified": "…", "location": "…" }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | `invalidValue` — 검증 실패(`displayName` 없음 등) |
| 401 | Unauthorized | SCIM 토큰이 없거나 틀림, 없는 IdP 키 |
| 403 | Forbidden | IdP 꺼짐 또는 SCIM 사용 꺼짐 |
| 404 | Not Found | 이 IdP 에 그 그룹이 없음 |
| 409 | Conflict | `uniqueness` — 바꾸려는 이름을 다른 그룹이 씀 |
| 422 | Unprocessable Entity | SCIM 엔드포인트는 422 를 돌려주지 않는다. 검증 실패는 위 400 `invalidValue`(SCIM 오류 본문) |

<!-- @generated:end -->

**설명**

PUT 은 그룹 전체 교체다. 소속을 바꾸지 않으려면 `members` 를 함께 보내거나 PATCH 를 쓴다.


### GET /api/plugins/sirsoft-sso/scim/v2/{idp}/ResourceTypes
<!-- @generated:start:api.plugins.sirsoft-sso.scim.resource-types.index -->
- **라우트명**: `api.plugins.sirsoft-sso.scim.resource-types.index`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Scim\ScimDiscoveryController@resourceTypes`
- **인증/권한**: IdP 별 SCIM Bearer 토큰(`ScimBearerAuth`)
- **요청 제한**: `throttle:300,1,sirsoft-sso.scim`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | IdP 키 |
| filter | query | string | 아니오 | max 500 | 이 주소에서는 쓰지 않는다. 문자열이 아니거나 500자를 넘으면 400 `invalidValue` |
| startIndex | query | integer | 아니오 | — | 이 주소에서는 쓰지 않는다. 정수가 아니면 400 `invalidValue` |
| count | query | integer | 아니오 | — | 이 주소에서는 쓰지 않는다. 정수가 아니면 400 `invalidValue` |
| excludedAttributes | query | string | 아니오 | max 200 | 이 주소에서는 쓰지 않는다 |

**요청 예시**

```http
GET /api/plugins/sirsoft-sso/scim/v2/{idp}/ResourceTypes HTTP/1.1
Host: api.example.com
Accept: application/scim+json
Authorization: Bearer {SCIM_TOKEN}
```

**응답 필드** (SCIM ListResponse — G7 봉투 없음)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| totalResults | integer | — | 언제나 2 |
| Resources | array | — | `User`(엔드포인트 `/Users`, 확장 스키마 enterprise User 선택), `Group`(엔드포인트 `/Groups`) |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Content-Type: application/scim+json
```

```json
{
    "schemas": ["urn:ietf:params:scim:api:messages:2.0:ListResponse"],
    "totalResults": 2,
    "startIndex": 1,
    "itemsPerPage": 2,
    "Resources": [
        {
            "schemas": ["urn:ietf:params:scim:schemas:core:2.0:ResourceType"],
            "id": "User", "name": "User", "endpoint": "/Users",
            "schema": "urn:ietf:params:scim:schemas:core:2.0:User",
            "schemaExtensions": [{ "schema": "urn:ietf:params:scim:schemas:extension:enterprise:2.0:User", "required": false }],
            "meta": { "resourceType": "ResourceType", "location": "…/ResourceTypes/User" }
        },
        {
            "schemas": ["urn:ietf:params:scim:schemas:core:2.0:ResourceType"],
            "id": "Group", "name": "Group", "endpoint": "/Groups",
            "schema": "urn:ietf:params:scim:schemas:core:2.0:Group",
            "meta": { "resourceType": "ResourceType", "location": "…/ResourceTypes/Group" }
        }
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthorized | SCIM 토큰이 없거나 틀림, 없는 IdP 키 |
| 403 | Forbidden | IdP 꺼짐 또는 SCIM 사용 꺼짐 |
| 404 | Not Found | 이 주소에는 찾을 자원이 없어 404 를 내지 않는다. 없는 IdP 키는 401(`ScimBearerAuth`). `idp` 가 `^[a-z][a-z0-9_]{1,49}$` 에 맞지 않을 때만 라우트 제약으로 404 |
| 422 | Unprocessable Entity | SCIM 엔드포인트는 422 를 돌려주지 않는다. 검증 실패는 400 `invalidValue`(SCIM 오류 본문, 공통 사항 오류 표) |

<!-- @generated:end -->

**설명**

탐색 주소도 토큰이 필요하다.


### GET /api/plugins/sirsoft-sso/scim/v2/{idp}/ResourceTypes/{id}
<!-- @generated:start:api.plugins.sirsoft-sso.scim.resource-types.show -->
- **라우트명**: `api.plugins.sirsoft-sso.scim.resource-types.show`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Scim\ScimDiscoveryController@resourceType`
- **인증/권한**: IdP 별 SCIM Bearer 토큰(`ScimBearerAuth`)
- **요청 제한**: `throttle:300,1,sirsoft-sso.scim`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | IdP 키 |
| id | path | string | 예 | `User`, `Group` | 자원 종류 |
| filter | query | string | 아니오 | max 500 | 이 주소에서는 쓰지 않는다. 문자열이 아니거나 500자를 넘으면 400 `invalidValue` |
| startIndex | query | integer | 아니오 | — | 이 주소에서는 쓰지 않는다. 정수가 아니면 400 `invalidValue` |
| count | query | integer | 아니오 | — | 이 주소에서는 쓰지 않는다. 정수가 아니면 400 `invalidValue` |
| excludedAttributes | query | string | 아니오 | max 200 | 이 주소에서는 쓰지 않는다 |

**요청 예시**

```http
GET /api/plugins/sirsoft-sso/scim/v2/{idp}/ResourceTypes/User HTTP/1.1
Host: api.example.com
Accept: application/scim+json
Authorization: Bearer {SCIM_TOKEN}
```

**응답 필드** (ResourceType 자원 — G7 봉투 없음)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | string | — | `User` 또는 `Group` |
| endpoint | string | — | `/Users` 또는 `/Groups` |
| schema | string | — | 핵심 스키마 URN |
| schemaExtensions | array | — | User 만 — enterprise User 확장(선택) |
| meta | object | — | `{resourceType: "ResourceType", location}` |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Content-Type: application/scim+json
```

```json
{
    "schemas": ["urn:ietf:params:scim:schemas:core:2.0:ResourceType"],
    "id": "Group",
    "name": "Group",
    "endpoint": "/Groups",
    "schema": "urn:ietf:params:scim:schemas:core:2.0:Group",
    "meta": { "resourceType": "ResourceType", "location": "…/ResourceTypes/Group" }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthorized | SCIM 토큰이 없거나 틀림, 없는 IdP 키 |
| 403 | Forbidden | IdP 꺼짐 또는 SCIM 사용 꺼짐 |
| 404 | Not Found | `User`, `Group` 이 아닌 값(라우트 제약) |
| 422 | Unprocessable Entity | SCIM 엔드포인트는 422 를 돌려주지 않는다. 검증 실패는 400 `invalidValue`(SCIM 오류 본문, 공통 사항 오류 표) |

<!-- @generated:end -->

**설명**

목록의 한 항목과 같다.


### GET /api/plugins/sirsoft-sso/scim/v2/{idp}/Schemas
<!-- @generated:start:api.plugins.sirsoft-sso.scim.schemas.index -->
- **라우트명**: `api.plugins.sirsoft-sso.scim.schemas.index`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Scim\ScimDiscoveryController@schemas`
- **인증/권한**: IdP 별 SCIM Bearer 토큰(`ScimBearerAuth`)
- **요청 제한**: `throttle:300,1,sirsoft-sso.scim`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | IdP 키 |
| filter | query | string | 아니오 | max 500 | 이 주소에서는 쓰지 않는다. 문자열이 아니거나 500자를 넘으면 400 `invalidValue` |
| startIndex | query | integer | 아니오 | — | 이 주소에서는 쓰지 않는다. 정수가 아니면 400 `invalidValue` |
| count | query | integer | 아니오 | — | 이 주소에서는 쓰지 않는다. 정수가 아니면 400 `invalidValue` |
| excludedAttributes | query | string | 아니오 | max 200 | 이 주소에서는 쓰지 않는다 |

**요청 예시**

```http
GET /api/plugins/sirsoft-sso/scim/v2/{idp}/Schemas HTTP/1.1
Host: api.example.com
Accept: application/scim+json
Authorization: Bearer {SCIM_TOKEN}
```

**응답 필드** (SCIM ListResponse — G7 봉투 없음)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| totalResults | integer | — | 언제나 3 |
| Resources | array | — | 핵심 User(`userName`, `name.formatted`, `name.givenName`, `name.familyName`, `displayName`, `title`, `active`, `emails`, `phoneNumbers`), enterprise User(`department`, `employeeNumber`), Group(`displayName`, `members`). 이 서버가 읽고 쓰는 속성만 적는다 |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Content-Type: application/scim+json
```

```json
{
    "schemas": ["urn:ietf:params:scim:api:messages:2.0:ListResponse"],
    "totalResults": 3,
    "startIndex": 1,
    "itemsPerPage": 3,
    "Resources": [
        {
            "schemas": ["urn:ietf:params:scim:schemas:core:2.0:Schema"],
            "id": "urn:ietf:params:scim:schemas:core:2.0:User",
            "name": "User",
            "attributes": [
                { "name": "userName", "type": "string", "multiValued": false, "required": true, "caseExact": false, "mutability": "readWrite", "returned": "default", "uniqueness": "server" }
            ],
            "meta": { "resourceType": "Schema", "location": "…/Schemas/urn:ietf:params:scim:schemas:core:2.0:User" }
        }
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthorized | SCIM 토큰이 없거나 틀림, 없는 IdP 키 |
| 403 | Forbidden | IdP 꺼짐 또는 SCIM 사용 꺼짐 |
| 404 | Not Found | 이 주소에는 찾을 자원이 없어 404 를 내지 않는다. 없는 IdP 키는 401(`ScimBearerAuth`). `idp` 가 `^[a-z][a-z0-9_]{1,49}$` 에 맞지 않을 때만 라우트 제약으로 404 |
| 422 | Unprocessable Entity | SCIM 엔드포인트는 422 를 돌려주지 않는다. 검증 실패는 400 `invalidValue`(SCIM 오류 본문, 공통 사항 오류 표) |

<!-- @generated:end -->

**설명**

예시는 첫 스키마의 첫 속성만 보였다.


### GET /api/plugins/sirsoft-sso/scim/v2/{idp}/Schemas/{id}
<!-- @generated:start:api.plugins.sirsoft-sso.scim.schemas.show -->
- **라우트명**: `api.plugins.sirsoft-sso.scim.schemas.show`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Scim\ScimDiscoveryController@schema`
- **인증/권한**: IdP 별 SCIM Bearer 토큰(`ScimBearerAuth`)
- **요청 제한**: `throttle:300,1,sirsoft-sso.scim`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | IdP 키 |
| id | path | string | 예 | `[A-Za-z0-9:._-]{1,120}` | 스키마 URN(`urn:ietf:params:scim:schemas:core:2.0:User`, `urn:ietf:params:scim:schemas:extension:enterprise:2.0:User`, `urn:ietf:params:scim:schemas:core:2.0:Group`) |
| filter | query | string | 아니오 | max 500 | 이 주소에서는 쓰지 않는다. 문자열이 아니거나 500자를 넘으면 400 `invalidValue` |
| startIndex | query | integer | 아니오 | — | 이 주소에서는 쓰지 않는다. 정수가 아니면 400 `invalidValue` |
| count | query | integer | 아니오 | — | 이 주소에서는 쓰지 않는다. 정수가 아니면 400 `invalidValue` |
| excludedAttributes | query | string | 아니오 | max 200 | 이 주소에서는 쓰지 않는다 |

**요청 예시**

```http
GET /api/plugins/sirsoft-sso/scim/v2/{idp}/Schemas/urn:ietf:params:scim:schemas:core:2.0:Group HTTP/1.1
Host: api.example.com
Accept: application/scim+json
Authorization: Bearer {SCIM_TOKEN}
```

**응답 필드** (Schema 자원 — G7 봉투 없음)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | string | — | 스키마 URN |
| name | string | — | `User`, `EnterpriseUser`, `Group` |
| attributes | array | — | 속성 정의 |
| meta | object | — | `{resourceType: "Schema", location}` |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Content-Type: application/scim+json
```

```json
{
    "schemas": ["urn:ietf:params:scim:schemas:core:2.0:Schema"],
    "id": "urn:ietf:params:scim:schemas:core:2.0:Group",
    "name": "Group",
    "attributes": [
        { "name": "displayName", "type": "string", "multiValued": false, "required": true, "caseExact": false, "mutability": "readWrite", "returned": "default", "uniqueness": "server" },
        { "name": "members", "type": "complex", "multiValued": true, "required": false, "mutability": "readWrite", "returned": "default", "subAttributes": ["…"] }
    ],
    "meta": { "resourceType": "Schema", "location": "…/Schemas/urn:ietf:params:scim:schemas:core:2.0:Group" }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthorized | SCIM 토큰이 없거나 틀림, 없는 IdP 키 |
| 403 | Forbidden | IdP 꺼짐 또는 SCIM 사용 꺼짐 |
| 404 | Not Found | 위 세 URN 이 아님 |
| 422 | Unprocessable Entity | SCIM 엔드포인트는 422 를 돌려주지 않는다. 검증 실패는 400 `invalidValue`(SCIM 오류 본문, 공통 사항 오류 표) |

<!-- @generated:end -->

**설명**

목록의 한 항목과 같다.


### GET /api/plugins/sirsoft-sso/scim/v2/{idp}/ServiceProviderConfig
<!-- @generated:start:api.plugins.sirsoft-sso.scim.service-provider-config -->
- **라우트명**: `api.plugins.sirsoft-sso.scim.service-provider-config`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Scim\ScimDiscoveryController@serviceProviderConfig`
- **인증/권한**: IdP 별 SCIM Bearer 토큰(`ScimBearerAuth`)
- **요청 제한**: `throttle:300,1,sirsoft-sso.scim`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | IdP 키 |
| filter | query | string | 아니오 | max 500 | 이 주소에서는 쓰지 않는다. 문자열이 아니거나 500자를 넘으면 400 `invalidValue` |
| startIndex | query | integer | 아니오 | — | 이 주소에서는 쓰지 않는다. 정수가 아니면 400 `invalidValue` |
| count | query | integer | 아니오 | — | 이 주소에서는 쓰지 않는다. 정수가 아니면 400 `invalidValue` |
| excludedAttributes | query | string | 아니오 | max 200 | 이 주소에서는 쓰지 않는다 |

**요청 예시**

```http
GET /api/plugins/sirsoft-sso/scim/v2/{idp}/ServiceProviderConfig HTTP/1.1
Host: api.example.com
Accept: application/scim+json
Authorization: Bearer {SCIM_TOKEN}
```

**응답 필드** (ServiceProviderConfig — G7 봉투 없음)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| patch.supported | boolean | — | true |
| bulk.supported | boolean | — | false(`maxOperations`, `maxPayloadSize` 0) |
| filter.supported | boolean | — | true, `maxResults` 100 |
| changePassword.supported | boolean | — | false |
| sort.supported | boolean | — | false |
| etag.supported | boolean | — | false |
| authenticationSchemes | array | — | `oauthbearertoken` 1개(IdP 마다 발급하는 Bearer 토큰) |
| meta | object | — | `{resourceType: "ServiceProviderConfig", location}` |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Content-Type: application/scim+json
```

```json
{
    "schemas": ["urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig"],
    "patch": { "supported": true },
    "bulk": { "supported": false, "maxOperations": 0, "maxPayloadSize": 0 },
    "filter": { "supported": true, "maxResults": 100 },
    "changePassword": { "supported": false },
    "sort": { "supported": false },
    "etag": { "supported": false },
    "authenticationSchemes": [
        { "type": "oauthbearertoken", "name": "Bearer Token", "description": "IdP 마다 발급하는 Bearer 토큰 (Authorization: Bearer …)", "primary": true }
    ],
    "meta": { "resourceType": "ServiceProviderConfig", "location": "…/ServiceProviderConfig" }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthorized | SCIM 토큰이 없거나 틀림, 없는 IdP 키 |
| 403 | Forbidden | IdP 꺼짐 또는 SCIM 사용 꺼짐 |
| 404 | Not Found | 이 주소에는 찾을 자원이 없어 404 를 내지 않는다. 없는 IdP 키는 401(`ScimBearerAuth`). `idp` 가 `^[a-z][a-z0-9_]{1,49}$` 에 맞지 않을 때만 라우트 제약으로 404 |
| 422 | Unprocessable Entity | SCIM 엔드포인트는 422 를 돌려주지 않는다. 검증 실패는 400 `invalidValue`(SCIM 오류 본문, 공통 사항 오류 표) |

<!-- @generated:end -->

**설명**

IdP 의 연결 테스트가 보통 이 주소를 먼저 부른다. 값은 코드에 고정돼 있다(`ScimSchema::serviceProviderConfig`).


### GET /api/plugins/sirsoft-sso/scim/v2/{idp}/Users
<!-- @generated:start:api.plugins.sirsoft-sso.scim.users.index -->
- **라우트명**: `api.plugins.sirsoft-sso.scim.users.index`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Scim\ScimUserController@index`
- **인증/권한**: IdP 별 SCIM Bearer 토큰(`ScimBearerAuth`)
- **요청 제한**: `throttle:300,1,sirsoft-sso.scim`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | IdP 키 |
| filter | query | string | 아니오 | 500자, `userName eq "값"`, `externalId eq "값"`, `emails.value eq "값"` | 한 속성 일치 거르기. 다른 연산자(`co`, `sw`, `and`, `or`)는 400 `invalidFilter` |
| startIndex | query | integer | 아니오 | 1 이상(기본 1) | 1부터 세는 시작 위치 |
| count | query | integer | 아니오 | 0~100(기본 100) | 한 번에 받을 건수 |
| excludedAttributes | query | string | 아니오 | 200자 | Users 에서는 쓰지 않는다 |

**요청 예시**

```http
GET /api/plugins/sirsoft-sso/scim/v2/{idp}/Users?filter=userName%20eq%20%22hong%40example.com%22 HTTP/1.1
Host: api.example.com
Accept: application/scim+json
Authorization: Bearer {SCIM_TOKEN}
```

**응답 필드** (SCIM ListResponse — G7 봉투 없음)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| schemas | array | — | ListResponse URN |
| totalResults | integer | — | 조건에 맞는 전체 수 |
| startIndex | integer | — | 요청한 시작 위치 |
| itemsPerPage | integer | — | 이번 응답의 자원 수 |
| Resources | array | — | User 자원(아래 「User 자원」). 퇴사 처리된 연결도 `active: false` 로 실린다 |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Content-Type: application/scim+json
```

```json
{
    "schemas": ["urn:ietf:params:scim:api:messages:2.0:ListResponse"],
    "totalResults": 1,
    "startIndex": 1,
    "itemsPerPage": 1,
    "Resources": [
        {
            "schemas": ["urn:ietf:params:scim:schemas:core:2.0:User"],
            "id": "…",
            "userName": "hong@example.com",
            "name": { "formatted": "…" },
            "displayName": "…",
            "active": true,
            "emails": [{ "value": "hong@example.com", "type": "work", "primary": true }],
            "meta": { "resourceType": "User", "created": "…", "lastModified": "…", "location": "…/Users/…" }
        }
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | `invalidFilter`(형식 오류 또는 받지 않는 속성), `invalidValue`(파라미터 검증 실패) |
| 401 | Unauthorized | SCIM 토큰이 없거나 틀림, 없는 IdP 키 |
| 403 | Forbidden | IdP 꺼짐 또는 SCIM 사용 꺼짐 |
| 404 | Not Found | 이 주소에는 찾을 자원이 없어 404 를 내지 않는다. 없는 IdP 키는 401(`ScimBearerAuth`). `idp` 가 `^[a-z][a-z0-9_]{1,49}$` 에 맞지 않을 때만 라우트 제약으로 404 |
| 422 | Unprocessable Entity | SCIM 엔드포인트는 422 를 돌려주지 않는다. 검증 실패는 위 400 `invalidValue`(SCIM 오류 본문) |

<!-- @generated:end -->

**설명**

이 IdP 의 연결만 돌려준다. IdP 는 보통 생성 전에 `filter=userName eq "…"` 로 있는지 먼저 묻는다. 대조 키가 `externalId` 인 IdP 에서 `userName` 거르기는 마지막으로 받은 `userName` 값(추가 속성)으로 찾는다. `emails.value` 는 연결 행에 저장한 이메일로 찾는다.

**User 자원**

| 필드 | 타입 | 설명 |
| --- | --- | --- |
| schemas | array | 핵심 User URN, 부서나 사번이 있으면 enterprise User URN 이 더해진다 |
| id | string | 자원 ID = 이 IdP 연결의 ID(회원 ID 가 아니다) |
| userName | string | 대조 키가 `userName` 이면 연결의 고유 식별자, 아니면 마지막으로 받은 `userName` |
| externalId | string | 대조 키가 `externalId` 면 고유 식별자, 아니면 받은 `externalId`. 값이 있을 때만 |
| name | object | `{formatted}` — 회원 이름 |
| displayName | string | 연결의 표시 이름(없으면 회원 이름) |
| active | boolean | 연결 활성 여부. 퇴사 처리면 false |
| emails | array | `[{value, type: "work", primary: true}]`. 받을 수 없는 대체 주소(`…@sso.invalid`)면 빈 배열 |
| phoneNumbers | array | `[{value, type: "mobile"}]` — 회원 휴대전화가 있을 때만 |
| title | string | 직함 — 있을 때만 |
| urn:ietf:params:scim:schemas:extension:enterprise:2.0:User | object | `{department, employeeNumber}` — 있을 때만 |
| meta | object | `{resourceType: "User", created, lastModified, location}` |


### POST /api/plugins/sirsoft-sso/scim/v2/{idp}/Users
<!-- @generated:start:api.plugins.sirsoft-sso.scim.users.store -->
- **라우트명**: `api.plugins.sirsoft-sso.scim.users.store`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Scim\ScimUserController@store`
- **인증/권한**: IdP 별 SCIM Bearer 토큰(`ScimBearerAuth`)
- **요청 제한**: `throttle:300,1,sirsoft-sso.scim`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | IdP 키 |
| schemas | body | array | 아니오 | — | 읽지 않는다 |
| userName | body | string\|null | 조건부 | 191자 | 대조 키가 `userName` 이면 필수(고유 식별자) |
| externalId | body | string\|null | 조건부 | 191자 | 대조 키가 `externalId` 면 필수(고유 식별자) |
| displayName | body | string\|null | 아니오 | 255자 | 표시 이름 |
| name | body | object\|null | 아니오 | `formatted`, `givenName`, `familyName` 각 255자 | 이름. 회원 이름은 `name.formatted`, `displayName`, `givenName familyName` 순으로 고른다(50자) |
| name.formatted | body | string | 아니오 | max 255 | 전체 이름. 회원 이름의 첫째 후보 |
| name.givenName | body | string | 아니오 | max 255 | 이름. `name.formatted`, `displayName` 이 없으면 `givenName familyName` 으로 이어 회원 이름이 된다 |
| name.familyName | body | string | 아니오 | max 255 | 성. `givenName` 뒤에 이어 붙인다 |
| title | body | string\|null | 아니오 | 255자 | 직함(추가 속성) |
| active | body | boolean\|string | 아니오 | `true`, `false`, `"True"`, `"False"` | false 면 만든 뒤 바로 퇴사 처리 |
| emails | body | array\|null | 아니오 | 20개, `value` 255자 | 이메일. `primary`, `type: work`, 첫째 순으로 하나를 고른다 |
| phoneNumbers | body | array\|null | 아니오 | 20개, `value` 30자 | 전화. `primary`, `type: mobile`, 첫째 순으로 하나를 골라 회원 휴대전화로 |
| urn:ietf:params:scim:schemas:extension:enterprise:2.0:User | body | object | 아니오 | — | `department`, `employeeNumber`(추가 속성) |

**요청 예시**

```http
POST /api/plugins/sirsoft-sso/scim/v2/{idp}/Users HTTP/1.1
Host: api.example.com
Accept: application/scim+json
Authorization: Bearer {SCIM_TOKEN}
Content-Type: application/scim+json

{
    "schemas": ["urn:ietf:params:scim:schemas:core:2.0:User"],
    "userName": "hong@example.com",
    "name": { "givenName": "길동", "familyName": "홍" },
    "displayName": "홍길동",
    "emails": [{ "value": "hong@example.com", "type": "work", "primary": true }],
    "active": true
}
```

**응답 필드** (User 자원 — G7 봉투 없음)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | string | — | 만든 연결 ID(이후 자원 ID) |
| userName | string | — | 저장된 `userName` |
| active | boolean | — | 연결 활성 여부 |
| emails | array | — | 회원 이메일(대체 주소면 빈 배열) |
| meta | object | — | `location` 은 `Location` 헤더와 같다 |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 201
Content-Type: application/scim+json
Location: https://www.example.com/api/plugins/sirsoft-sso/scim/v2/…/Users/…
```

```json
{
    "schemas": ["urn:ietf:params:scim:schemas:core:2.0:User"],
    "id": "…",
    "userName": "hong@example.com",
    "name": { "formatted": "홍길동" },
    "displayName": "홍길동",
    "active": true,
    "emails": [{ "value": "hong@example.com", "type": "work", "primary": true }],
    "meta": { "resourceType": "User", "created": "…", "lastModified": "…", "location": "https://www.example.com/api/plugins/sirsoft-sso/scim/v2/…/Users/…" }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | `invalidValue` — 검증 실패, 또는 대조 키 값이 없거나 191자 초과 |
| 401 | Unauthorized | SCIM 토큰이 없거나 틀림, 없는 IdP 키 |
| 403 | Forbidden | IdP 꺼짐 또는 SCIM 사용 꺼짐 |
| 404 | Not Found | 이 주소에는 찾을 자원이 없어 404 를 내지 않는다. 없는 IdP 키는 401(`ScimBearerAuth`). `idp` 가 `^[a-z][a-z0-9_]{1,49}$` 에 맞지 않을 때만 라우트 제약으로 404 |
| 409 | Conflict | `uniqueness` — 같은 대조 키의 연결이 이 IdP 에 있음, 같은 이메일 회원이 있는데 연결할 수 없음(소유 도메인 자동 연결이 꺼졌거나 소유 도메인이 아니거나 그 회원이 이 IdP 에 이미 연결됨), 그 회원이 관리자 |
| 422 | Unprocessable Entity | SCIM 엔드포인트는 422 를 돌려주지 않는다. 검증 실패는 위 400 `invalidValue`(SCIM 오류 본문) |

<!-- @generated:end -->

**설명**

같은 이메일의 회원이 있으면 IdP 가 소유 도메인 자동 연결(`link_methods.domain_auto`)을 켰고, 이메일이 소유 도메인이고, 회원이 관리자가 아닐 때만 그 회원에 연결한다. 없으면 비밀번호 없는 회원을 만든다(가입 본인인증 정책 생략, 약관 동의 기록 없음). 이메일은 IdP 의 소유 도메인일 때만 회원 이메일로 쓰고, 아니면 받을 수 없는 대체 주소(`{12자}@sso.invalid`)로 만든다. 연결은 `via = scim` 이다. 그룹 출처가 `scim` 이면 역할 매핑을 돈다.


### DELETE /api/plugins/sirsoft-sso/scim/v2/{idp}/Users/{id}
<!-- @generated:start:api.plugins.sirsoft-sso.scim.users.destroy -->
- **라우트명**: `api.plugins.sirsoft-sso.scim.users.destroy`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Scim\ScimUserController@destroy`
- **인증/권한**: IdP 별 SCIM Bearer 토큰(`ScimBearerAuth`)
- **요청 제한**: `throttle:300,1,sirsoft-sso.scim`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | IdP 키 |
| id | path | string | 예 | 숫자 1~19자리 | User 자원 ID(연결 ID) |
| filter | query | string | 아니오 | max 500 | 이 주소에서는 쓰지 않는다. 문자열이 아니거나 500자를 넘으면 400 `invalidValue` |
| startIndex | query | integer | 아니오 | — | 이 주소에서는 쓰지 않는다. 정수가 아니면 400 `invalidValue` |
| count | query | integer | 아니오 | — | 이 주소에서는 쓰지 않는다. 정수가 아니면 400 `invalidValue` |
| excludedAttributes | query | string | 아니오 | max 200 | 이 주소에서는 쓰지 않는다 |

**요청 예시**

```http
DELETE /api/plugins/sirsoft-sso/scim/v2/{idp}/Users/{id} HTTP/1.1
Host: api.example.com
Accept: application/scim+json
Authorization: Bearer {SCIM_TOKEN}
```

**응답 필드** (본문 없음)

204 응답이라 본문이 없다.

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 204
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthorized | SCIM 토큰이 없거나 틀림, 없는 IdP 키 |
| 403 | Forbidden | IdP 꺼짐 또는 SCIM 사용 꺼짐 |
| 404 | Not Found | 이 IdP 에 그 연결이 없음 |
| 422 | Unprocessable Entity | SCIM 엔드포인트는 422 를 돌려주지 않는다. 검증 실패는 400 `invalidValue`(SCIM 오류 본문, 공통 사항 오류 표) |

<!-- @generated:end -->

**설명**

**회원을 지우지 않고 퇴사 처리한다.** 연결을 비활성(`active: false`, 출처 `scim`)으로 만들고 이 IdP 로 받은 세션을 끝낸 뒤, 회원에게 다른 활성 연결이 없으면 회원을 차단하고 모든 로그인 토큰을 회수한다. 다른 IdP 의 활성 연결이 있으면 차단하지 않는다(여러 회사 겸직). 최고관리자는 차단하지 않고 토큰만 회수한 뒤 최고관리자 전원에게 알린다. 게시글과 주문 기록을 보존하기 위해서다.

그래서 **DELETE 뒤 같은 자원을 GET 하면 404 가 아니라 `active: false` 인 자원이 돌아온다.** 삭제 뒤 404 를 요구하는 RFC 7644 §3.6 과 다른 점이다. IdP 가 DELETE 뒤 404 를 기대해 오류를 남기면, 그 IdP 의 SCIM 이 삭제 대신 비활성화(`active: false` PATCH)를 보내도록 설정한다. 같은 자원에 DELETE 를 다시 보내도 204 다. 퇴사 처리 뒤 N일 지나 탈퇴시키려면 IdP 설정 `deprovision.withdraw_after_days` 를 쓴다.


### GET /api/plugins/sirsoft-sso/scim/v2/{idp}/Users/{id}
<!-- @generated:start:api.plugins.sirsoft-sso.scim.users.show -->
- **라우트명**: `api.plugins.sirsoft-sso.scim.users.show`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Scim\ScimUserController@show`
- **인증/권한**: IdP 별 SCIM Bearer 토큰(`ScimBearerAuth`)
- **요청 제한**: `throttle:300,1,sirsoft-sso.scim`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | IdP 키 |
| id | path | string | 예 | 숫자 1~19자리 | User 자원 ID(연결 ID) |
| filter | query | string | 아니오 | max 500 | 이 주소에서는 쓰지 않는다. 문자열이 아니거나 500자를 넘으면 400 `invalidValue` |
| startIndex | query | integer | 아니오 | — | 이 주소에서는 쓰지 않는다. 정수가 아니면 400 `invalidValue` |
| count | query | integer | 아니오 | — | 이 주소에서는 쓰지 않는다. 정수가 아니면 400 `invalidValue` |
| excludedAttributes | query | string | 아니오 | max 200 | 이 주소에서는 쓰지 않는다 |

**요청 예시**

```http
GET /api/plugins/sirsoft-sso/scim/v2/{idp}/Users/{id} HTTP/1.1
Host: api.example.com
Accept: application/scim+json
Authorization: Bearer {SCIM_TOKEN}
```

**응답 필드** (User 자원 — G7 봉투 없음)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | string | — | 연결 ID |
| userName | string | — | `userName` |
| active | boolean | — | 퇴사 처리(DELETE 포함) 뒤에는 false |
| emails | array | — | 회원 이메일 |
| meta | object | — | `{resourceType, created, lastModified, location}` |

**응답 예시** (미실측, 코드에서 옮긴 모양)

DELETE 뒤의 조회:

```http
HTTP/1.1 200
Content-Type: application/scim+json
```

```json
{
    "schemas": ["urn:ietf:params:scim:schemas:core:2.0:User"],
    "id": "…",
    "userName": "…",
    "name": { "formatted": "…" },
    "displayName": "…",
    "active": false,
    "emails": [{ "value": "…", "type": "work", "primary": true }],
    "meta": { "resourceType": "User", "created": "…", "lastModified": "…", "location": "…/Users/…" }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthorized | SCIM 토큰이 없거나 틀림, 없는 IdP 키 |
| 403 | Forbidden | IdP 꺼짐 또는 SCIM 사용 꺼짐 |
| 404 | Not Found | 이 IdP 에 그 연결이 없거나 연결된 회원이 없음 |
| 422 | Unprocessable Entity | SCIM 엔드포인트는 422 를 돌려주지 않는다. 검증 실패는 400 `invalidValue`(SCIM 오류 본문, 공통 사항 오류 표) |

<!-- @generated:end -->

**설명**

자원 모양은 목록 절의 「User 자원」 표와 같다. 관리자가 연결을 해제(행 삭제)했거나 회원 탈퇴로 연결이 지워졌으면 404 다.


### PATCH /api/plugins/sirsoft-sso/scim/v2/{idp}/Users/{id}
<!-- @generated:start:api.plugins.sirsoft-sso.scim.users.patch -->
- **라우트명**: `api.plugins.sirsoft-sso.scim.users.patch`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Scim\ScimUserController@patch`
- **인증/권한**: IdP 별 SCIM Bearer 토큰(`ScimBearerAuth`)
- **요청 제한**: `throttle:300,1,sirsoft-sso.scim`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | IdP 키 |
| id | path | string | 예 | 숫자 1~19자리 | User 자원 ID(연결 ID) |
| schemas | body | array | 아니오 | — | `urn:ietf:params:scim:api:messages:2.0:PatchOp`(읽지 않는다) |
| Operations | body | array | 예 | 1~200개 | 연산 목록 |
| Operations[].op | body | string | 예 | `add`, `replace`, `remove`(대소문자 무관) | 연산 |
| Operations[].path | body | string\|null | 조건부 | 500자 | 대상 경로. `remove` 면 필수 |
| Operations[].value | body | mixed | 아니오 | — | 값. 경로가 없으면 객체여야 한다 |

받는 경로(대소문자 무관, `urn:ietf:params:scim:schemas:core:2.0:User:` 와 enterprise 접두를 붙여도 된다, `emails[type eq "work"].value` 처럼 거르개를 붙여도 거르개는 무시): `userName`, `externalId`, `displayName`, `name.formatted`, `name.givenName`, `name.familyName`, `title`, `active`, `preferredLanguage`, `locale`, `emails`, `emails.value`, `phoneNumbers`, `phoneNumbers.value`, `department`, `employeeNumber`. **그 밖의 경로는 오류 없이 무시한다.**

**요청 예시**

```http
PATCH /api/plugins/sirsoft-sso/scim/v2/{idp}/Users/{id} HTTP/1.1
Host: api.example.com
Accept: application/scim+json
Authorization: Bearer {SCIM_TOKEN}
Content-Type: application/scim+json

{
    "schemas": ["urn:ietf:params:scim:api:messages:2.0:PatchOp"],
    "Operations": [
        { "op": "replace", "path": "active", "value": "False" },
        { "op": "replace", "value": { "displayName": "홍길동", "title": "팀장" } }
    ]
}
```

**응답 필드** (User 자원 — G7 봉투 없음)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | string | — | 연결 ID |
| active | boolean | — | 바뀐 뒤 활성 여부 |
| displayName | string | — | 바뀐 뒤 표시 이름 |
| meta | object | — | `{resourceType, created, lastModified, location}` |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Content-Type: application/scim+json
```

```json
{
    "schemas": ["urn:ietf:params:scim:schemas:core:2.0:User"],
    "id": "…",
    "userName": "…",
    "name": { "formatted": "홍길동" },
    "displayName": "홍길동",
    "active": false,
    "emails": [{ "value": "…", "type": "work", "primary": true }],
    "title": "팀장",
    "meta": { "resourceType": "User", "created": "…", "lastModified": "…", "location": "…/Users/…" }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | `invalidSyntax`(검증 실패, 모르는 `op`, 경로 없는 값이 객체가 아님), `noTarget`(`remove` 에 경로 없음) |
| 401 | Unauthorized | SCIM 토큰이 없거나 틀림, 없는 IdP 키 |
| 403 | Forbidden | IdP 꺼짐 또는 SCIM 사용 꺼짐 |
| 404 | Not Found | 이 IdP 에 그 연결이 없음 |
| 409 | Conflict | `uniqueness` — 바꾸려는 대조 키를 다른 연결이 씀 |
| 422 | Unprocessable Entity | SCIM 엔드포인트는 422 를 돌려주지 않는다. 검증 실패는 위 400 `invalidSyntax`(SCIM 오류 본문) |

<!-- @generated:end -->

**설명**

IdP 마다 PATCH 모양이 달라(경로 있는 연산, 경로 없이 객체 값, 거르개 붙은 경로, `"True"`/`"False"` 문자열 참거짓) 모두 받는다. 반영 규칙:

- `active: false` 면 퇴사 처리(DELETE 와 같다), `active: true` 면 다시 켠다. 다시 켤 때는 그 퇴사 처리가 건 차단만 풀고, 관리자가 직접 끈 연결(퇴사 출처 `admin`)은 SCIM 으로 다시 켜지지 않는다.
- 이름은 회원 이름(50자)과 연결 표시 이름을 바꾼다. `preferredLanguage`, `locale` 은 사이트가 지원하는 언어일 때만 회원 언어로 쓴다. 전화는 회원 휴대전화로 쓴다.
- 이메일은 IdP 소유 도메인 주소일 때만 회원 이메일을 바꾸고, 다른 회원이 쓰는 주소면 바꾸지 않는다.
- `title`, `department`, `employeeNumber`, `userName`(대조 키가 아닐 때)은 이 IdP 의 추가 속성으로 둔다. `title`, `department`, `employeeNumber` 의 `remove` 는 그 값을 비운다.


### PUT /api/plugins/sirsoft-sso/scim/v2/{idp}/Users/{id}
<!-- @generated:start:api.plugins.sirsoft-sso.scim.users.update -->
- **라우트명**: `api.plugins.sirsoft-sso.scim.users.update`
- **컨트롤러**: `Plugins\Sirsoft\Sso\Http\Controllers\Scim\ScimUserController@update`
- **인증/권한**: IdP 별 SCIM Bearer 토큰(`ScimBearerAuth`)
- **요청 제한**: `throttle:300,1,sirsoft-sso.scim`

**요청 파라미터**

생성(`POST /Users`)과 같은 칸을 받는다. 대조 키 값은 필수가 아니다(보내면 바꾼다).

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| idp | path | string | 예 | `^[a-z][a-z0-9_]{1,49}$` | IdP 키 |
| id | path | string | 예 | 숫자 1~19자리 | User 자원 ID(연결 ID) |
| schemas | body | array | 아니오 | — | 읽지 않는다 |
| userName | body | string\|null | 아니오 | 191자 | 사용자 이름 |
| externalId | body | string\|null | 아니오 | 191자 | IdP 쪽 ID |
| displayName | body | string\|null | 아니오 | 255자 | 표시 이름 |
| name | body | object\|null | 아니오 | — | 이름 |
| name.formatted | body | string | 아니오 | max 255 | 전체 이름. 회원 이름의 첫째 후보 |
| name.givenName | body | string | 아니오 | max 255 | 이름. `name.formatted`, `displayName` 이 없으면 `givenName familyName` 으로 이어 회원 이름이 된다 |
| name.familyName | body | string | 아니오 | max 255 | 성. `givenName` 뒤에 이어 붙인다 |
| title | body | string\|null | 아니오 | 255자 | 직함 |
| active | body | boolean\|string | 아니오 | — | 활성 여부 |
| emails | body | array\|null | 아니오 | 20개 | 이메일 |
| phoneNumbers | body | array\|null | 아니오 | 20개 | 전화 |

**요청 예시**

```http
PUT /api/plugins/sirsoft-sso/scim/v2/{idp}/Users/{id} HTTP/1.1
Host: api.example.com
Accept: application/scim+json
Authorization: Bearer {SCIM_TOKEN}
Content-Type: application/scim+json

{
    "schemas": ["urn:ietf:params:scim:schemas:core:2.0:User"],
    "userName": "hong@example.com",
    "displayName": "홍길동",
    "active": true
}
```

**응답 필드** (User 자원 — G7 봉투 없음)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | string | — | 연결 ID |
| userName | string | — | 바뀐 뒤 `userName` |
| active | boolean | — | 바뀐 뒤 활성 여부 |
| meta | object | — | `{resourceType, created, lastModified, location}` |

**응답 예시** (미실측, 코드에서 옮긴 모양)

```http
HTTP/1.1 200
Content-Type: application/scim+json
```

```json
{
    "schemas": ["urn:ietf:params:scim:schemas:core:2.0:User"],
    "id": "…",
    "userName": "hong@example.com",
    "name": { "formatted": "홍길동" },
    "displayName": "홍길동",
    "active": true,
    "emails": [{ "value": "…", "type": "work", "primary": true }],
    "meta": { "resourceType": "User", "created": "…", "lastModified": "…", "location": "…/Users/…" }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | `invalidValue` — 검증 실패 |
| 401 | Unauthorized | SCIM 토큰이 없거나 틀림, 없는 IdP 키 |
| 403 | Forbidden | IdP 꺼짐 또는 SCIM 사용 꺼짐 |
| 404 | Not Found | 이 IdP 에 그 연결이 없음 |
| 409 | Conflict | `uniqueness` — 바꾸려는 대조 키를 다른 연결이 씀 |
| 422 | Unprocessable Entity | SCIM 엔드포인트는 422 를 돌려주지 않는다. 검증 실패는 위 400 `invalidValue`(SCIM 오류 본문) |

<!-- @generated:end -->

**설명**

PUT 도 보낸 속성만 반영한다. 빠진 속성을 지우지 않는다(지우려면 PATCH `remove`). 반영 규칙은 PATCH 와 같다.
