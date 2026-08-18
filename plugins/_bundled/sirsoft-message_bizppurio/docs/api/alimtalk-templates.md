# Alimtalk Templates API 레퍼런스

> **소유**: plugin `sirsoft-message_bizppurio` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Alimtalk Templates 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-message_bizppurio/admin/alimtalk-templates
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.alimtalk-templates.index -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.alimtalk-templates.index`
- **컨트롤러**: `Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\AlimtalkTemplateController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-message_bizppurio.messaging.view`

**요청 파라미터**

| 파라미터 | 위치 | 타입 | 필수 | 제약 | 설명 |
| --- | --- | --- | --- | --- | --- |
| status | query | string | 아니오 | max 30 | kapi `templateStatus` 필터 값(어휘는 kapi 정의를 따름) |
| keyword | query | string | 아니오 | max 50 | 템플릿명/코드 검색어 |
| page | query | integer | 아니오 | min 1 | 페이지 번호(기본 1) |
| count | query | integer | 아니오 | min 1 | 페이지당 건수(기본값은 서버 설정) |

**요청 예시**

```http
GET /api/plugins/sirsoft-message_bizppurio/admin/alimtalk-templates HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: http-422 — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.view`)이 없는 경우 |

<!-- @generated:end -->

**설명** <!-- TODO: 이 엔드포인트의 용도·주의사항·예시 시나리오를 작성하세요 -->


### GET /api/plugins/sirsoft-message_bizppurio/admin/alimtalk-templates/categories
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.alimtalk-templates.categories -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.alimtalk-templates.categories`
- **컨트롤러**: `Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\AlimtalkTemplateController@categories`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-message_bizppurio.messaging.view`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-message_bizppurio/admin/alimtalk-templates/categories HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: http-422 — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.view`)이 없는 경우 |

<!-- @generated:end -->

**설명** <!-- TODO: 이 엔드포인트의 용도·주의사항·예시 시나리오를 작성하세요 -->


### GET /api/plugins/sirsoft-message_bizppurio/admin/alimtalk-templates/profiles
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.alimtalk-templates.profiles -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.alimtalk-templates.profiles`
- **컨트롤러**: `Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\AlimtalkTemplateController@profiles`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-message_bizppurio.messaging.view`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-message_bizppurio/admin/alimtalk-templates/profiles HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: http-422 — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.view`)이 없는 경우 |

<!-- @generated:end -->

**설명** <!-- TODO: 이 엔드포인트의 용도·주의사항·예시 시나리오를 작성하세요 -->


### GET /api/plugins/sirsoft-message_bizppurio/admin/alimtalk-templates/{templateCode}
<!-- @generated:start:api.plugins.sirsoft-message_bizppurio.admin.alimtalk-templates.show -->
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.alimtalk-templates.show`
- **컨트롤러**: `Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\AlimtalkTemplateController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-message_bizppurio.messaging.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| templateCode | path | string | 예 | — | 대상 template code의 식별자 |

**요청 예시**

```http
GET /api/plugins/sirsoft-message_bizppurio/admin/alimtalk-templates/{templateCode} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: unresolved-path-param — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.view`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** <!-- TODO: 이 엔드포인트의 용도·주의사항·예시 시나리오를 작성하세요 -->


### POST /api/plugins/sirsoft-message_bizppurio/admin/alimtalk-templates/cache/clear
- **라우트명**: `api.plugins.sirsoft-message_bizppurio.admin.alimtalk-templates.cache.clear`
- **컨트롤러**: `Plugins\Sirsoft\MessageBizppurio\Controllers\Admin\AlimtalkTemplateController@clearCache`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-message_bizppurio.messaging.manage`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/plugins/sirsoft-message_bizppurio/admin/alimtalk-templates/cache/clear HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 설명 |
| --- | --- | --- |
| `cleared` | integer | 초기화한 캐시 키 수(연결된 고유 알림톡 템플릿 코드 수) |

**응답 예시**

```json
{
  "success": true,
  "message": "알림톡 템플릿 내용 캐시를 초기화했습니다. 다음 발송부터 최신 내용이 반영됩니다.",
  "data": { "cleared": 3 }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.manage`)이 없는 경우 |

**설명**

발송 시 알림톡은 카카오 승인 템플릿의 실제 내용(본문·버튼·요소)을 카카오 상세조회로 가져와 채우며, 그 결과를 template_code 단위로 캐시한다(기본 1시간, 환경설정 `template_cache_ttl` 로 조정, 0이면 캐시 끔). 카카오 콘솔에서 템플릿 내용을 방금 변경해 캐시 만료를 기다리지 않고 즉시 반영하고 싶을 때 이 엔드포인트로 캐시를 비운다. 연결(binding)된 모든 알림톡 템플릿의 캐시를 초기화하며, 다음 발송에서 최신 내용으로 재조회된다. 카카오 API 를 호출하지 않고 로컬 캐시만 비우므로 rate limit 에 영향을 주지 않는다. 관리자 화면(알림톡 템플릿 탭)의 "내용 캐시 초기화" 버튼이 이 엔드포인트를 호출한다.


