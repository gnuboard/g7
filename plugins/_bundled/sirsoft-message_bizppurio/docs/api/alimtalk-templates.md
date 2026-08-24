# Alimtalk Templates API 레퍼런스

> **소유**: plugin `sirsoft-message_bizppurio` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 알림톡 템플릿 작성 모달의 참조 조회(카테고리·발신프로필) 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 실시간 템플릿 목록/상세 화면(구 Phase 5)은 DB 기반 라이프사이클(templates.md)로 대체되어 제거됐습니다
4. kapi 실패는 422 + errors.bizppurio_message(카카오 사유 원문) 규약을 따릅니다
5. 갱신: 코드 변경 후 php artisan api:docgen 재실행
```

---

작성 모달 참조 조회(카테고리·발신프로필) 전용 문서다. 알림톡 템플릿 작성 모달의 카테고리 셀렉트·발신프로필
셀렉트가 소비하는 kapi(카카오 관리 API) 조회를 프록시한다. 템플릿의 등록·검수·승인 라이프사이클 API 는
[templates.md](templates.md) 를 참조.


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

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| categories | array | `[{...}]` | kapi `/v3/kakao/template/category/all` 조회 결과 배열. 각 항목: `code`(카테고리 코드 — `content.categoryCode` 에 사용), `name`(소분류명), `groupName`(대분류명) |

**응답 예시**

```json
{
  "success": true,
  "message": "성공적으로 처리되었습니다.",
  "data": {
    "categories": [
      { "code": "001001", "name": "회원가입", "groupName": "회원" },
      { "code": "004001", "name": "구매완료", "groupName": "구매" }
    ]
  }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.view`)이 없는 경우 |

<!-- @generated:end -->

**설명**

템플릿 등록에 사용할 카카오 카테고리 전체(대분류·소분류)를 조회한다(`data.categories`). 작성 모달의
카테고리 셀렉트가 소비하며, 선택된 소분류 코드가 템플릿 생성/수정의 `content.categoryCode` 값이 된다.
kapi 자격증명 미설정·호출 실패 시 422 + `errors.bizppurio_message`(카카오 사유 원문) 를 반환한다.


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

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| profiles | array | `[{...}]` | kapi `/v3/kakao/profile/use` 응답 `data.success` 배열(조회 실패 프로필 `fail` 은 제외). 각 항목: `senderKey`(발신프로필 키), `name`(프로필명), `status`, `block`, `dormant`, `categoryCode` 등 kapi 원형 필드 |

**응답 예시**

```json
{
  "success": true,
  "message": "성공적으로 처리되었습니다.",
  "data": {
    "profiles": [
      { "senderKey": "05aa099bcbc5220a8c0b2...", "name": "@우리상점", "status": "A", "block": false, "dormant": false }
    ]
  }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-message_bizppurio.messaging.view`)이 없는 경우 |

<!-- @generated:end -->

**설명**

발신프로필(사용중) 상태 정보를 조회한다(`data.profiles`). 작성 모달의 발신프로필 셀렉트·상태 표시가
소비한다. kapi 자격증명 미설정·호출 실패 시 422 + `errors.bizppurio_message`(카카오 사유 원문) 를 반환한다.
