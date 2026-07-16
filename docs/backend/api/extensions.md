# Extensions API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Extensions 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/admin/extensions/auto-deactivated
<!-- @generated:start:api.admin.extensions.auto-deactivated -->
- **라우트명**: `api.admin.extensions.auto-deactivated`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ExtensionRecoveryController@autoDeactivated`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.activate`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/extensions/auto-deactivated HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| items | object | `{"plugins":[],"modules":[],"templates":[]}` | 코어 비호환으로 자동 비활성화된 확장을 타입별(`plugins`/`modules`/`templates`)로 묶은 목록. 각 원소는 식별자(`identifier`), 비호환 요구 버전(`incompatible_required_version`), 비활성화 시각(`deactivated_at`)을 가지며, 사용자가 dismiss했거나 hidden(학습용 샘플) 확장은 제외됨 |
| current_core_version | string | `7.0.1` | 현재 설치된 코어 버전 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "자동 비활성화된 확장 목록입니다.",
    "data": {
        "items": {
            "plugins": [],
            "modules": [],
            "templates": []
        },
        "current_core_version": "7.0.1"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.activate`)이 없는 경우 |

<!-- @generated:end -->

**설명** 코어 버전 비호환으로 자동 비활성화된 확장 목록을 타입별(`plugins`/`modules`/`templates`)로 반환합니다. 각 항목에는 식별자, 비호환 요구 버전, 비활성화 시각과 함께 현재 코어 버전이 담깁니다. 사용자가 dismiss한 알림과 hidden(학습용 샘플) 확장은 결과에서 제외됩니다. `core.plugins.activate` 권한이 필요하며, 상단 배너·대시보드 카드의 데이터 소스로 사용됩니다.


### POST /api/admin/extensions/{type}/{identifier}/dismiss
<!-- @generated:start:api.admin.extensions.dismiss -->
- **라우트명**: `api.admin.extensions.dismiss`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ExtensionRecoveryController@dismiss`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.activate`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| type | path | string | 예 | module, plugin, template | 대상 확장의 타입 (module: 모듈, plugin: 플러그인, template: 템플릿). 타입에 맞는 Repository/Manager를 해석하는 데 사용되며 그 외 값은 422 |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
POST /api/admin/extensions/{type}/{identifier}/dismiss HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.activate`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 특정 확장의 호환성 알림을 현재 사용자 기준으로 dismiss(닫기) 처리합니다. 경로의 `{type}`(module|plugin|template)과 `{identifier}`로 대상을 지정하며, 해당 확장의 자동 비활성화 알림과 재호환 알림을 함께 dismiss합니다. `core.plugins.activate` 권한이 필요합니다. dismiss는 사용자별로 저장되므로, 캐시 만료나 감지 갱신 시 재호환 상태가 바뀌면 다시 노출될 수 있습니다.


### POST /api/admin/extensions/{type}/{identifier}/recover
<!-- @generated:start:api.admin.extensions.recover -->
- **라우트명**: `api.admin.extensions.recover`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ExtensionRecoveryController@recover`
- **인증/권한**: `auth:sanctum` + `permission:core.plugins.activate`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| type | path | string | 예 | module, plugin, template | 대상 확장의 타입 (module: 모듈, plugin: 플러그인, template: 템플릿). 타입에 맞는 Repository/Manager를 해석하는 데 사용되며 그 외 값은 422 |
| identifier | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
POST /api/admin/extensions/{type}/{identifier}/recover HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.plugins.activate`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 코어와 재호환된 확장을 원클릭으로 복구(재활성화)합니다. 경로의 `{type}`/`{identifier}`로 대상을 지정하며, 대상이 `IncompatibleCore` 사유로 자동 비활성화된 상태인지 검증한 뒤 코어 버전 재검증을 거쳐 활성화합니다. 잘못된 타입은 422, 미존재 확장은 404, hidden 확장이나 자동 비활성화가 아닌 경우는 error_code와 함께 422를 반환하고, 재검증 실패 시 글로벌 핸들러가 core_version_mismatch로 변환합니다. `core.plugins.activate` 권한이 필요합니다.


