# Policy Versions API 레퍼런스

> **소유**: plugin `sirsoft-gdpr` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Policy Versions 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-gdpr/admin/policy-versions
<!-- @generated:start:api.plugins.sirsoft-gdpr.admin.policy-versions.index -->
- **라우트명**: `api.plugins.sirsoft-gdpr.admin.policy-versions.index`
- **컨트롤러**: `Plugins\Sirsoft\Gdpr\Http\Controllers\Admin\GdprAdminPolicyVersionController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-gdpr.privacy.view`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-gdpr/admin/policy-versions HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `10` | 기본 키 (내부 식별자) |
| version | integer | `10` | 정책 버전 번호(단조 증가 정수). 발행 시마다 1씩 증가하며 회원 동의 시점의 버전과 비교해 재동의 필요 여부를 판정하는 기준입니다 |
| change_type | string | `material` | 변경 종류. `material`=카테고리 key/설명·slug 변경 등 모든 회원 재동의를 트리거하는 중대 변경, `non_material`=도메인/라벨/힌트 정정 등 재동의 미유발, `initial`=최초 발행(시드) |
| memo | string | `Chrome MCP 정밀 점검 — 마이페이지 정책 갱신 트리거 (P…` | 발행 사유 메모(최대 500자). 수동 발행 시 운영자가 입력하는 감사 추적용 설명으로 자동 감지 밖의 변경 배경을 남깁니다 |
| created_at | string | `2026-06-18 09:27:39` | 생성 일시 |
| publisher | object | `{"uuid":"a1e0a91a-fba6-491c-a53e-7285a5686857","name":"관리…` | 이 버전을 발행한 운영자 정보(uuid/name/email). raw FK(created_by)는 노출하지 않고 관계가 로드된 경우에만 포함되며, 발행자가 없으면 null입니다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": {
        "data": [
            {
                "id": 1,
                "version": 1,
                "change_type": "initial",
                "memo": null,
                "created_at": "2026-07-07 16:38:26",
                "publisher": null
            }
        ],
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 25,
            "total": 1,
            "from": 1,
            "to": 1
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-gdpr.privacy.view`)이 없는 경우 |

<!-- @generated:end -->

**설명** 관리자 화면에서 발행된 개인정보 처리방침 정책 버전 이력을 version 내림차순 페이지네이션으로 조회합니다. `auth:sanctum`과 `sirsoft-gdpr.privacy.view` 권한이 필요합니다. `per_page`(1~100, 기본 20) 쿼리 파라미터로 페이지 크기를 조절할 수 있습니다. 조회 전용입니다.


### POST /api/plugins/sirsoft-gdpr/admin/policy-versions
<!-- @generated:start:api.plugins.sirsoft-gdpr.admin.policy-versions.store -->
- **라우트명**: `api.plugins.sirsoft-gdpr.admin.policy-versions.store`
- **컨트롤러**: `Plugins\Sirsoft\Gdpr\Http\Controllers\Admin\GdprAdminPolicyVersionController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-gdpr.privacy.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| memo | body | string | 예 | min 1, max 500 | 발행 사유 메모(1~500자, 필수). 자동 감지 밖의 변경을 인지하고 명시적으로 새 버전을 발행할 때 감사 추적용으로 남기는 설명입니다 |

**요청 예시**

```http
POST /api/plugins/sirsoft-gdpr/admin/policy-versions HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "memo": "예시값"
}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| uuid | string | `a234c2b1-cde8-437f-b28b-23323be2b98d` | 외부 노출용 UUID (URL/API 식별자, 내부 id 비노출) |
| name | string | `API 문서 샘플 사용자` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| email | string | `apidoc-sample-user@example.com` | 이 버전을 발행한 운영자의 이메일. publisher 관계에서 노출되며 감사 화면에서 발행자를 식별하는 용도입니다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "새 정책 버전이 발행되었습니다. 모든 회원이 다음 방문 시 재동의를 진행합니다.",
    "data": {
        "data": {
            "id": 6,
            "version": 2,
            "0": "... (총 6건 중 2건 표시)"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-gdpr.privacy.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 운영자가 새 개인정보 처리방침 정책 버전을 수동으로 발행합니다. `auth:sanctum`과 `sirsoft-gdpr.privacy.update` 권한이 필요하며, `memo`(1~500자)가 필수입니다. 자동 감지되는 변경(카테고리 key/설명·slug 변경) 밖의 변경(정책 본문 외부 수정, 법인명 변경 후 의도적 재동의 트리거 등)을 발행 시점의 현재 settings 스냅샷과 함께 새 버전으로 기록하며, settings 자체는 변경하지 않습니다. 이 발행이 회원의 `needs_renewal`을 true로 만드는 트리거가 됩니다.


### GET /api/plugins/sirsoft-gdpr/admin/policy-versions/current
<!-- @generated:start:api.plugins.sirsoft-gdpr.admin.policy-versions.current -->
- **라우트명**: `api.plugins.sirsoft-gdpr.admin.policy-versions.current`
- **컨트롤러**: `Plugins\Sirsoft\Gdpr\Http\Controllers\Admin\GdprAdminPolicyVersionController@current`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-gdpr.privacy.view`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-gdpr/admin/policy-versions/current HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| uuid | string | `a1e0a91a-fba6-491c-a53e-7285a5686857` | 외부 노출용 UUID (URL/API 식별자, 내부 id 비노출) |
| name | string | `관리자` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| email | string | `heuristing@gmail.com` | 이 버전을 발행한 운영자의 이메일. publisher 관계에서 노출되며 감사 화면에서 발행자를 식별하는 용도입니다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": {
        "data": {
            "id": 1,
            "version": 1,
            "0": "... (총 6건 중 2건 표시)"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-gdpr.privacy.view`)이 없는 경우 |

<!-- @generated:end -->

**설명** 현재 발행된 최신 정책 버전을 반환합니다. `auth:sanctum`과 `sirsoft-gdpr.privacy.view` 권한이 필요합니다. 발행된 정책 버전 row가 하나도 없으면 `data`가 null입니다. 조회 전용입니다.


### GET /api/plugins/sirsoft-gdpr/admin/policy-versions/{version}
<!-- @generated:start:api.plugins.sirsoft-gdpr.admin.policy-versions.show -->
- **라우트명**: `api.plugins.sirsoft-gdpr.admin.policy-versions.show`
- **컨트롤러**: `Plugins\Sirsoft\Gdpr\Http\Controllers\Admin\GdprAdminPolicyVersionController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-gdpr.privacy.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| version | path | string | 예 | — | 대상 버전 (버전 문자열) |

**요청 예시**

```http
GET /api/plugins/sirsoft-gdpr/admin/policy-versions/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| banner_enabled | boolean | `true` | 발행 시점 스냅샷의 쿠키 배너·자동 차단 통합 활성 여부. `true`일 때만 게스트에게 배너가 노출되고 도메인 차단 엔진이 동작합니다 |
| banner_position | string | `bottom_bar` | 발행 시점의 쿠키 배너 노출 위치(bottom_bar / bottom_left_popup / bottom_right_popup / centered_modal) |
| blocked_domains | object | `{"analytics":["google-analytics.com","*.google-analytics.…` | 발행 시점에 설정된 카테고리별 차단 도메인 패턴(키→도메인 배열). 동의 전 로드를 차단하는 대상이며, 분쟁 시 당시 어떤 도메인이 차단 대상이었는지 확인하는 근거입니다 |
| cookie_categories | array | `[{"key":"necessary","label":{"en":"Strictly Necessary","k…` | 발행 시점의 쿠키 카테고리 카탈로그 배열. 각 항목은 `key`, 필수 여부, 다국어 `label`/`description`을 담으며, 회원 분쟁 시 당시 동의 대상 카테고리 본문을 확인하는 근거가 됩니다 |
| legal_entity_name | string | `` | 발행 시점에 표기된 개인정보 처리 법인/사업자명. 미설정 시 빈 문자열입니다 |
| privacy_policy_slug | string | `privacy` | 발행 시점의 개인정보 처리방침 페이지 slug. 배너/마이페이지의 방침 링크 대상이며, 미설정 시 빈 값입니다 |
| data_storage_location | string | `` | 발행 시점에 표기된 데이터 저장 위치 문자열(국가 단위 안내). 미설정 시 빈 문자열입니다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": {
        "data": {
            "id": 1,
            "version": 1,
            "0": "... (총 8건 중 2건 표시)"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-gdpr.privacy.view`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 특정 정책 버전의 상세(발행 시점 settings 스냅샷 본문 포함)를 반환합니다. `auth:sanctum`과 `sirsoft-gdpr.privacy.view` 권한이 필요합니다. `{version}`은 정수 path 파라미터로, 관리자 동의 이력·정책 버전 이력 화면에서 행 클릭 시 그 시점의 cookie_categories·privacy_policy_slug·blocked_domains를 모달로 표시해 회원 분쟁 시 당시 정책 본문을 확인할 수 있게 합니다(Art.7(1) 입증 책임). 해당 버전이 없으면 404를 반환합니다.


