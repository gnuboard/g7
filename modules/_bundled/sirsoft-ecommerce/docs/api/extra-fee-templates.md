# Extra Fee Templates API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Extra Fee Templates 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/admin/extra-fee-templates
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.extra-fee-templates.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.extra-fee-templates.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ExtraFeeTemplateController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.shipping-policies.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| search | query | string | 아니오 | max 200 | 검색어 (지정한 검색 대상 필드에서 부분 일치) |
| region | query | string | 아니오 | max 100 | 지역/권역 |
| is_active | query | string | 아니오 | ``, `true`, `false` | 활성 여부 (true 활성 / false 비활성) |
| sort_by | query | string | 아니오 | `id`, `zipcode`, `fee`, `region`, `is_active`, `created_at`, `updated_at` | 정렬 기준 필드명 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 (asc 오름차순 / desc 내림차순) |
| per_page | query | integer | 아니오 | min 10, max 100 | 페이지당 항목 수 |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/extra-fee-templates?search=%EC%98%88%EC%8B%9C%EA%B0%92&region=%EC%98%88%EC%8B%9C%EA%B0%92&is_active=%2C%20&sort_by=id&sort_order=asc&per_page=1&page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| number | integer | `36` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| id | integer | `399` | 기본 키 (내부 식별자) |
| zipcode | string | `15654` | 추가배송비를 적용할 우편번호 (단일 또는 `-` 로 이은 범위) |
| fee | integer | `3000` | 해당 우편번호에 부과할 추가 배송비 (상점 기본 통화 기준 반올림) |
| fee_formatted | string | `3,000원` | `fee` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| region | string | `경기 안산 풍도동` | 지역명 (도서산간 등 관리자 참고용 표시 라벨) |
| description | string | `도서산간 지역` | 설명 (다국어 필드는 로케일별 값 객체) |
| is_active | boolean | `true` | active 여부 |
| created_by | null | `null` | 등록자 UUID (creator 관계 파생, 없으면 null) |
| updated_by | null | `null` | 최종 수정자 UUID (updater 관계 파생, 없으면 null) |
| created_at | string | `2026-07-30 23:35:47` | 생성 일시 |
| updated_at | string | `2026-07-30 23:35:47` | 최종 수정 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "추가배송비 템플릿 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "number": 36,
                "id": 399,
                "zipcode": "15654",
                "fee": 3000,
                "fee_formatted": "3,000원",
                "...": "(8개 키 생략, 총 13개)"
            },
            {
                "number": 35,
                "id": 400,
                "zipcode": "23008-23010",
                "fee": 3000,
                "fee_formatted": "3,000원",
                "...": "(8개 키 생략, 총 13개)"
            },
            "... (총 25건 중 2건 표시)"
        ],
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true
        },
        "statistics": {
            "total": 36,
            "active": 36,
            "inactive": 0,
            "by_region": [
                {
                    "region": "경기 안산 풍도동",
                    "count": 1,
                    "avg_fee": 3000,
                    "min_fee": "3000.00",
                    "max_fee": "3000.00"
                },
                {
                    "region": "경남 사천 섬지역",
                    "count": 1,
                    "avg_fee": 3000,
                    "min_fee": "3000.00",
                    "max_fee": "3000.00"
                },
                {
                    "region": "경남 통영 산양/한산/욕지/사량면",
                    "count": 1,
                    "avg_fee": 3000,
                    "min_fee": "3000.00",
                    "max_fee": "3000.00"
                },
                {
                    "region": "경남 통영 용남면",
                    "count": 1,
                    "avg_fee": 3000,
                    "min_fee": "3000.00",
                    "max_fee": "3000.00"
                },
                {
                    "region": "경북 울릉도",
                    "count": 1,
                    "avg_fee": 3000,
                    "min_fee": "3000.00",
                    "max_fee": "3000.00"
                },
                "... (총 34건 중 5건 표시)"
            ]
        },
        "pagination": {
            "current_page": 1,
            "last_page": 2,
            "per_page": 25,
            "total": 36,
            "from": 1,
            "...": "(2개 키 생략, 총 7개)"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.shipping-policies.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 관리자가 추가배송비(도서산간 등 우편번호별 할증) 템플릿 목록을 검색·필터·정렬·페이지네이션으로 조회합니다. `permission:sirsoft-ecommerce.shipping-policies.read` 권한이 필요하며, `search`·`region`·`is_active` 필터와 다양한 정렬 기준을 지원합니다. `ExtraFeeTemplateService::getList()`가 조회하고 통계(`getStatistics()`)를 함께 담아 `ExtraFeeTemplateCollection`으로 반환합니다. 각 항목은 우편번호·추가 배송비·지역명을 포함하며 배송정책의 지역별 할증 관리 화면에 사용됩니다.


### POST /api/modules/sirsoft-ecommerce/admin/extra-fee-templates
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.extra-fee-templates.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.extra-fee-templates.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ExtraFeeTemplateController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.shipping-policies.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| zipcode | body | string | 예 | max 20 | 우편번호 |
| fee | body | number | 예 | min 0, max 9999999999.99 | 해당 우편번호에 부과할 추가 배송비 (0 이상) |
| region | body | string | 아니오 | max 100 | 지역/권역 |
| description | body | string | 아니오 | max 1000 | 설명 |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/extra-fee-templates HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "zipcode": "06234",
    "fee": 1,
    "region": "예시값",
    "description": "예시 내용입니다.",
    "is_active": true
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`ExtraFeeTemplateResource`)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| zipcode | string | `06234` | 추가배송비를 적용할 우편번호 (단일 또는 `-` 로 이은 범위) |
| fee | integer | `3000` | 해당 우편번호에 부과할 추가 배송비 (상점 기본 통화 기준 반올림) |
| fee_formatted | string | `3,000원` | `fee` 값의 표시용 포맷 문자열 (기본 통화 포맷) |
| region | string \| null | `경기 안산 풍도동` | 지역명 (도서산간 등 관리자 참고용 표시 라벨, 미입력 시 null) |
| description | string \| null | `도서산간 지역` | 설명 (미입력 시 null) |
| is_active | boolean | `true` | 활성 여부 (true 이면 배송비 계산에 할증 반영) |
| created_by | string \| null | `a234c2b1-cde8-437f-b28b-23323be2b98d` | 등록자 UUID (creator 관계 파생, 없으면 null) |
| updated_by | string \| null | `a234c2b1-cde8-437f-b28b-23323be2b98d` | 최종 수정자 UUID (updater 관계 파생, 없으면 null) |
| created_at | string | `2026-07-08 10:44:49` | 생성 일시 (사용자 타임존 기준 포맷) |
| updated_at | string | `2026-07-08 10:44:49` | 최종 수정 일시 (사용자 타임존 기준 포맷) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (모두 `sirsoft-ecommerce.settings.update` 권한 기반) |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "추가배송비 템플릿이 등록되었습니다.",
    "data": {
        "id": 1,
        "zipcode": "06234",
        "fee": 3000,
        "fee_formatted": "3,000원",
        "region": "경기 안산 풍도동",
        "description": "도서산간 지역",
        "is_active": true,
        "created_by": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "updated_by": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "created_at": "2026-07-08 10:44:49",
        "updated_at": "2026-07-08 10:44:49",
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 저장 중 예외 발생 시 (`exceptions.operation_failed`) |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.shipping-policies.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). `zipcode` 는 숫자 또는 `숫자-숫자` 범위 형식이어야 하며 이미 등록된 우편번호면 중복 오류 |

<!-- @generated:end -->

**설명** 관리자가 추가배송비 템플릿 1건을 생성합니다. `permission:sirsoft-ecommerce.shipping-policies.create` 권한이 필요하며, 우편번호(`zipcode`, 단일 또는 범위)와 추가 배송비(`fee`)를 필수로 받고 지역명·설명·활성 여부를 선택 입력합니다. `ExtraFeeTemplateService::create()`가 저장하고 생성된 템플릿을 201로 반환합니다. 활성 템플릿은 배송비 계산 시 해당 우편번호에 할증으로 반영됩니다.


### GET /api/modules/sirsoft-ecommerce/admin/extra-fee-templates/active-settings
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.extra-fee-templates.active-settings -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.extra-fee-templates.active-settings`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ExtraFeeTemplateController@activeSettings`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.shipping-policies.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/extra-fee-templates/active-settings HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| zipcode | string | `15654` | 추가배송비 적용 우편번호 (배송정책 설정용 축약 필드) |
| fee | integer | `3000` | 해당 우편번호의 추가 배송비 (float 변환값) |
| region | string | `경기 안산 풍도동` | 지역명 (없으면 빈 문자열) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "활성 추가배송비 템플릿 설정을 조회했습니다.",
    "data": [
        {
            "zipcode": "15654",
            "fee": 3000,
            "region": "경기 안산 풍도동"
        },
        {
            "zipcode": "23008-23010",
            "fee": 3000,
            "region": "인천 강화 섬지역"
        },
        {
            "zipcode": "23100-23116",
            "fee": 3000,
            "region": "인천 옹진 백령/대청/연평/북도면"
        },
        {
            "zipcode": "23124-23136",
            "fee": 3000,
            "region": "인천 옹진 자월/덕적면"
        },
        {
            "zipcode": "32133",
            "fee": 3000,
            "region": "충남 태안 섬지역"
        },
        {
            "zipcode": "33411",
            "fee": 3000,
            "region": "충남 보령 섬지역"
        },
        {
            "zipcode": "40200-40240",
            "fee": 3000,
            "region": "경북 울릉도"
        },
        {
            "zipcode": "52570-52571",
            "fee": 3000,
            "region": "경남 사천 섬지역"
        },
        {
            "zipcode": "53031-53033",
            "fee": 3000,
            "region": "경남 통영 용남면"
        },
        {
            "zipcode": "53088-53104",
            "fee": 3000,
            "region": "경남 통영 산양/한산/욕지/사량면"
        },
        {
            "zipcode": "54000",
            "fee": 3000,
            "region": "전북 군산 옥도면"
        },
        {
            "zipcode": "56347-56349",
            "fee": 3000,
            "region": "전북 부안 위도면"
        },
        {
            "zipcode": "57068-57069",
            "fee": 3000,
            "region": "전남 영광 낙월면"
        },
        {
            "zipcode": "58760-58761",
            "fee": 3000,
            "region": "전남 목포 섬지역"
        },
        {
            "zipcode": "58800-58804",
            "fee": 3000,
            "region": "전남 신안 임자면"
        },
        {
            "zipcode": "58809-58810",
            "fee": 3000,
            "region": "전남 신안 증도/지도"
        },
        {
            "zipcode": "58816-58818",
            "fee": 3000,
            "region": "전남 신안 지도/압해"
        },
        {
            "zipcode": "58826",
            "fee": 3000,
            "region": "전남 신안 압해"
        },
        {
            "zipcode": "58832",
            "fee": 3000,
            "region": "전남 신안 암태면"
        },
        {
            "zipcode": "58839-58841",
            "fee": 3000,
            "region": "전남 신안 안좌면"
        },
        {
            "zipcode": "58843-58866",
            "fee": 3000,
            "region": "전남 신안 비금/도초/하의/신의/장산/흑산면"
        },
        {
            "zipcode": "58953-58958",
            "fee": 3000,
            "region": "전남 진도 조도면"
        },
        {
            "zipcode": "59102-59103",
            "fee": 3000,
            "region": "전남 완도 군외면"
        },
        {
            "zipcode": "59127",
            "fee": 3000,
            "region": "전남 완도 군외면"
        },
        {
            "zipcode": "59137-59145",
            "fee": 3000,
            "region": "전남 완도 금당/금일/생일면"
        },
        {
            "zipcode": "59149-59170",
            "fee": 3000,
            "region": "전남 완도 청산/소안/노화/보길/군외/금일면"
        },
        {
            "zipcode": "59421",
            "fee": 3000,
            "region": "전남 보성 벌교"
        },
        {
            "zipcode": "59531",
            "fee": 3000,
            "region": "전남 고흥 도화면"
        },
        {
            "zipcode": "59551",
            "fee": 3000,
            "region": "전남 고흥 도양읍"
        },
        {
            "zipcode": "59563",
            "fee": 3000,
            "region": "전남 고흥 도양읍"
        },
        {
            "zipcode": "59568",
            "fee": 3000,
            "region": "전남 고흥 봉래면"
        },
        {
            "zipcode": "59650",
            "fee": 3000,
            "region": "전남 여수 화정면"
        },
        {
            "zipcode": "59766",
            "fee": 3000,
            "region": "전남 여수 경호동"
        },
        {
            "zipcode": "59781-59790",
            "fee": 3000,
            "region": "전남 여수 화정/남/삼산면"
        },
        {
            "zipcode": "63000-63365",
            "fee": 3000,
            "region": "제주 제주시"
        },
        {
            "zipcode": "63500-63644",
            "fee": 3000,
            "region": "제주 서귀포시"
        }
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.shipping-policies.read`)이 없는 경우 |

<!-- @generated:end -->

**설명** 활성화된 추가배송비 템플릿을 배송정책에서 바로 사용할 수 있는 축약 JSON 배열(우편번호·배송비·지역명)로 반환합니다. `permission:sirsoft-ecommerce.shipping-policies.read` 권한이 필요하며, `ExtraFeeTemplateService::getAllAsExtraFeeSettings()`가 `is_active=true` 인 템플릿만 배송정책 설정 형식으로 변환합니다. 배송정책 편집 화면에서 지역별 할증 규칙을 채우거나 계산 로직에 주입하는 용도로, 관리 목록(index)의 상세 필드 대신 계산에 필요한 최소 필드만 내려줍니다.


### DELETE /api/modules/sirsoft-ecommerce/admin/extra-fee-templates/bulk
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.extra-fee-templates.bulk-destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.extra-fee-templates.bulk-destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ExtraFeeTemplateController@bulkDestroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.shipping-policies.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | query | array | 예 | min 1 | 대상 리소스 식별자 배열 (대량 작업 대상) |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/extra-fee-templates/bulk?ids=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (일괄 삭제 결과 요약)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted_count | integer | `3` | 실제로 삭제된 템플릿 건수 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": ":count개 추가배송비 템플릿이 삭제되었습니다.",
    "data": {
        "deleted_count": 3
    }
}
```

> 컨트롤러가 메시지 치환 파라미터를 전달하지 않으므로 `message` 의 `:count` 자리표시자는 치환되지 않은 채로 내려갑니다. 실제 삭제 건수는 `data.deleted_count` 를 사용하세요.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 삭제 중 예외 발생 시 (`exceptions.operation_failed`) |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.shipping-policies.delete`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). `ids` 는 1건 이상이어야 하며 각 값은 존재하는 템플릿 ID 여야 함 |

<!-- @generated:end -->

**설명** 관리자가 여러 추가배송비 템플릿을 한 번에 삭제합니다. `permission:sirsoft-ecommerce.shipping-policies.delete` 권한이 필요하고 대상 ID 배열(`ids`)을 쿼리로 전달하며, `ExtraFeeTemplateService::bulkDelete()`가 일괄 삭제 후 삭제 건수(`deleted_count`)를 반환합니다. 목록에서 여러 지역 할증 규칙을 선택해 한꺼번에 정리할 때 사용합니다.


### POST /api/modules/sirsoft-ecommerce/admin/extra-fee-templates/bulk
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.extra-fee-templates.bulk-store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.extra-fee-templates.bulk-store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ExtraFeeTemplateController@bulkStore`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.shipping-policies.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| items | body | array | 예 | min 1, max 1000 | 처리 대상 항목 배열 |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/extra-fee-templates/bulk HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "items": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (일괄 등록 결과 요약)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| created_count | integer | `2` | 실제로 등록(또는 갱신)된 템플릿 건수 |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": ":count개 추가배송비 템플릿이 등록되었습니다.",
    "data": {
        "created_count": 2
    }
}
```

> 컨트롤러가 메시지 치환 파라미터를 전달하지 않으므로 `message` 의 `:count` 자리표시자는 치환되지 않은 채로 내려갑니다. 실제 등록 건수는 `data.created_count` 를 사용하세요.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 일괄 등록 중 예외 발생 시 (`exceptions.operation_failed`) |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.shipping-policies.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). 항목별로 `items.*.zipcode` (필수, 숫자 또는 `숫자-숫자` 범위, 최대 20자) 와 `items.*.fee` (필수, 0 이상) 가 검증됨 |

<!-- @generated:end -->

**설명** 관리자가 CSV/엑셀 업로드로 추가배송비 템플릿을 한 번에 대량 등록합니다. `permission:sirsoft-ecommerce.shipping-policies.create` 권한이 필요하고 최대 1000건까지 `items` 배열로 전달하며, `ExtraFeeTemplateService::bulkCreate()`가 일괄 생성 후 등록 건수(`created_count`)를 201로 반환합니다. 도서산간 우편번호 목록처럼 다수의 지역 할증을 수기 입력 없이 파일로 업로드할 때 사용합니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/extra-fee-templates/bulk-toggle-active
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.extra-fee-templates.bulk-toggle-active -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.extra-fee-templates.bulk-toggle-active`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ExtraFeeTemplateController@bulkToggleActive`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.shipping-policies.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | min 1 | 대상 리소스 식별자 배열 (대량 작업 대상) |
| is_active | body | boolean | 예 | — | 활성 여부 (true 활성 / false 비활성) |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/extra-fee-templates/bulk-toggle-active HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "ids": [
        "예시값"
    ],
    "is_active": true
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (일괄 변경 결과 요약)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| updated_count | integer | `3` | 활성 여부가 실제로 변경된 템플릿 건수 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": ":count개 추가배송비 템플릿의 사용여부가 변경되었습니다.",
    "data": {
        "updated_count": 3
    }
}
```

> 컨트롤러가 메시지 치환 파라미터를 전달하지 않으므로 `message` 의 `:count` 자리표시자는 치환되지 않은 채로 내려갑니다. 실제 변경 건수는 `data.updated_count` 를 사용하세요.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 일괄 변경 중 예외 발생 시 (`exceptions.operation_failed`) |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.shipping-policies.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). `ids` 는 1건 이상이며 각 값은 존재하는 템플릿 ID, `is_active` 는 필수 boolean |

<!-- @generated:end -->

**설명** 관리자가 여러 추가배송비 템플릿의 활성 상태를 한 번에 지정한 값(`is_active`)으로 변경합니다. `permission:sirsoft-ecommerce.shipping-policies.update` 권한이 필요하고 대상 ID 배열(`ids`)과 적용할 활성 여부를 전달하며, `ExtraFeeTemplateService::bulkToggleActive()`가 일괄 갱신 후 변경 건수(`updated_count`)를 반환합니다. 단건 토글과 달리 여러 지역 할증을 한꺼번에 켜거나 끌 때 사용합니다.


### DELETE /api/modules/sirsoft-ecommerce/admin/extra-fee-templates/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.extra-fee-templates.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.extra-fee-templates.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ExtraFeeTemplateController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.shipping-policies.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/extra-fee-templates/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만 — `data` 는 `null`)._

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.shipping-policies.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 추가배송비 템플릿 1건을 삭제합니다. `permission:sirsoft-ecommerce.shipping-policies.delete` 권한이 필요하며, 대상이 없으면 404 를 반환하고 존재하면 `ExtraFeeTemplateService::delete()`가 삭제합니다. 특정 지역의 할증 규칙을 개별적으로 제거할 때 사용합니다.


### GET /api/modules/sirsoft-ecommerce/admin/extra-fee-templates/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.extra-fee-templates.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.extra-fee-templates.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ExtraFeeTemplateController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.shipping-policies.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/extra-fee-templates/{id} HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.shipping-policies.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 추가배송비 템플릿 1건의 상세 정보를 조회합니다. `permission:sirsoft-ecommerce.shipping-policies.read` 권한이 필요하며, `ExtraFeeTemplateService::getDetail()`이 대상을 조회해 `ExtraFeeTemplateResource`로 반환합니다. 템플릿 편집 폼에 기존 값(우편번호·배송비·지역명·설명·활성 여부)을 채우는 용도로 사용되며, 대상이 없으면 404 를 반환합니다.


### PUT /api/modules/sirsoft-ecommerce/admin/extra-fee-templates/{id}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.extra-fee-templates.update -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.extra-fee-templates.update`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ExtraFeeTemplateController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.shipping-policies.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| zipcode | body | string | 예 | max 20 | 우편번호 |
| fee | body | number | 예 | min 0, max 9999999999.99 | 해당 우편번호에 부과할 추가 배송비 (0 이상) |
| region | body | string | 아니오 | max 100 | 지역/권역 |
| description | body | string | 아니오 | max 1000 | 설명 |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |

**요청 예시**

```http
PUT /api/modules/sirsoft-ecommerce/admin/extra-fee-templates/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "zipcode": "06234",
    "fee": 1,
    "region": "예시값",
    "description": "예시 내용입니다.",
    "is_active": true
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`ExtraFeeTemplateResource`)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| zipcode | string | `06234` | 추가배송비를 적용할 우편번호 (단일 또는 `-` 로 이은 범위) |
| fee | integer | `5000` | 해당 우편번호에 부과할 추가 배송비 (상점 기본 통화 기준 반올림) |
| fee_formatted | string | `5,000원` | `fee` 값의 표시용 포맷 문자열 (기본 통화 포맷) |
| region | string \| null | `경기 안산 풍도동` | 지역명 (도서산간 등 관리자 참고용 표시 라벨, 미입력 시 null) |
| description | string \| null | `도서산간 지역` | 설명 (미입력 시 null) |
| is_active | boolean | `true` | 활성 여부 (true 이면 배송비 계산에 할증 반영) |
| created_by | string \| null | `null` | 등록자 UUID (creator 관계 파생, 없으면 null) |
| updated_by | string \| null | `a234c2b1-cde8-437f-b28b-23323be2b98d` | 최종 수정자 UUID (수정 시 현재 로그인 사용자로 갱신) |
| created_at | string | `2026-07-08 10:44:49` | 생성 일시 (사용자 타임존 기준 포맷) |
| updated_at | string | `2026-07-08 15:00:20` | 최종 수정 일시 (사용자 타임존 기준 포맷) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (모두 `sirsoft-ecommerce.settings.update` 권한 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "추가배송비 템플릿이 수정되었습니다.",
    "data": {
        "id": 1,
        "zipcode": "06234",
        "fee": 5000,
        "fee_formatted": "5,000원",
        "region": "경기 안산 풍도동",
        "description": "도서산간 지역",
        "is_active": true,
        "created_by": null,
        "updated_by": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "created_at": "2026-07-08 10:44:49",
        "updated_at": "2026-07-08 15:00:20",
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 수정 중 예외 발생 시 (`exceptions.operation_failed`) |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.shipping-policies.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). `zipcode` 는 숫자 또는 `숫자-숫자` 범위 형식이어야 하며 자신을 제외한 다른 템플릿과 중복될 수 없음 |

<!-- @generated:end -->

**설명** 관리자가 추가배송비 템플릿 1건을 전체 수정합니다. `permission:sirsoft-ecommerce.shipping-policies.update` 권한이 필요하며, path 의 `id` 로 대상을 조회해 없으면 404(`messages.extra_fee_template.not_found`)를 반환합니다. 존재하면 `ExtraFeeTemplateService::update()`가 우편번호·추가 배송비·지역명·설명·활성 여부를 갱신합니다. 우편번호와 배송비는 필수이며, 수정된 템플릿이 활성 상태이면 이후 배송비 계산에 즉시 반영됩니다.


### PATCH /api/modules/sirsoft-ecommerce/admin/extra-fee-templates/{id}/toggle-active
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.extra-fee-templates.toggle-active -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.extra-fee-templates.toggle-active`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ExtraFeeTemplateController@toggleActive`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.shipping-policies.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
PATCH /api/modules/sirsoft-ecommerce/admin/extra-fee-templates/{id}/toggle-active HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.shipping-policies.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 추가배송비 템플릿 1건의 활성 상태를 반전(active↔inactive)합니다. `permission:sirsoft-ecommerce.shipping-policies.update` 권한이 필요하며, path 의 `id` 로 대상을 조회해 없으면 404(`messages.extra_fee_template.not_found`)를 반환합니다. 존재하면 `ExtraFeeTemplateService::toggleActive()`가 현재 값을 뒤집어 저장하고 갱신된 템플릿을 반환합니다. 목록 화면에서 특정 지역 할증 규칙을 개별적으로 켜거나 끌 때 사용하며, 여러 건을 동일 값으로 일괄 설정하려면 bulk-toggle-active 엔드포인트를 사용합니다.


