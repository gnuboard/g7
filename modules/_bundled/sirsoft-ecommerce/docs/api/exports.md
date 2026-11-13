# Exports API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **작성**: 손으로 작성(`api:docgen` 기본 경로는 사람이 쓴 서술을 다시 만들어 지운다). 예시 응답은 개발 사이트에서 실제 호출로 관측한 값이다.

---

## TL;DR (5초 요약)

```text
1. 목록 화면의 [내보내기] 창: 카탈로그(GET catalog) → 예상 행 수(POST estimate) → 요청(POST, 202, Idempotency-Key 헤더 필수)
2. 파일은 큐 작업이 만든다 — 요청 응답은 대기(pending) 작업이고, 진행 상황은 목록(GET)·상세(GET {export})로 본다
3. 권한: exports.create/read/download/delete + 그 유형의 원본 조회 권한(주문=orders.read …) + 개인정보 필드는 exports.personal_data. 이미 만든 작업의 개인정보(파일 받기·다시 요청·받은 사람 IP)는 exports.personal_data 의 범위도 본다
4. 범위: 코어 표준 권한 범위(`owner_key = requested_by`) — 역할 관리에서 조회·다운로드·삭제마다 본인만/소유 역할/전체를 고른다(기본: 관리자 전체, 이커머스 관리자 본인만). 다시 요청은 조회 범위를 따른다. 범위 밖 작업은 403(`{export}` 경로는 코어 `auth.scope_denied`, 다시 요청·일회용 주소는 `scope_denied`)
5. 내려받기(GET {export}/download)는 파일 바이트를 준다(JSON 아님) — 다운로드 이력·횟수·활동 로그가 남는다
6. 화면은 일회용 주소를 쓴다: POST {export}/download-link 로 60초·1회용 주소를 받고 브라우저가 GET exports/file/{token} 을 직접 연다(파일을 메모리에 올리지 않음). 주소는 사이트 마운트 접두사를 이미 붙여서 준다(하위 폴더 설치 `/g7` 이면 `/g7/api/…`) — 화면 핸들러가 `<a href>` 에 그대로 넣기 때문이다
```

---

### GET /api/modules/sirsoft-ecommerce/admin/exports/catalog
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.exports.catalog -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.catalog`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ExportController@catalog`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.exports.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| type | query | string | 예 | `orders` · `products` · `mileage` · 확장 등록 유형 | 내보내기 유형 |
| start_date | query | date | 아니오 | — | 조회 기간 시작일 (이 날짜 이후 데이터) |
| end_date | query | date | 아니오 | — | 조회 기간 종료일 (이 날짜 이전 데이터) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.export.catalog_validation_rules`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/exports/catalog?type=%EC%98%88%EC%8B%9C%EA%B0%92&start_date=2026-01-01&end_date=2026-01-01 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`ExportService::catalog()` 가 만든 배열 — Resource 로 감싸지 않는다)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| type | string | `mileage` | 요청한 내보내기 유형 키 |
| label | string | `마일리지 내역` | 유형 라벨 |
| formats | array | `[{"key":"csv","label":"CSV (엑셀 호환)","extension":"csv"}]` | 고를 수 있는 파일 형식 `{key, label, extension}` (확장이 형식을 더할 수 있다) |
| row_units | array | `[{"key":"transaction","label":"거래 1건당 1행"}]` | 행 단위 `{key, label}` |
| default_row_unit | string | `transaction` | `row_unit` 을 생략했을 때 쓰는 행 단위 (유형이 선언한 첫 행 단위) |
| screen_columns | array | `[{"key":"created_at","label":"일시"}]` | 목록 화면 컬럼 `{key, label}` (「현재 화면 컬럼」 모드의 선택지) |
| screen_column_fields | object | `{"transaction":{"member":["mileage.member_name","mileage.member_uuid"]}}` | 행 단위별 화면 컬럼 키에서 실제 열(필드 키) 목록으로 가는 매핑. 요청자가 고를 수 없는 필드는 빠진다 |
| fields | object | `{"transaction":[{"key":"mileage.amount","label":"금액","group":"amount","type":"money","personal":false,"summable":true,"parent_level":false}]}` | 행 단위별 고를 수 있는 필드 `{key, label, group, type, personal, summable, parent_level}`. 개인정보 필드는 `exports.personal_data` 권한이 있을 때만 실린다 |
| parent_level_label | string\|null | `null` | `parent_level` 필드에 붙일 상위 단위 배지 (주문 단위, 상품 단위). 문구가 없는 유형은 `null` |
| date_types | array | `[{"key":"created_at","label":"거래일"}]` | 기간 기준 `{key, label}` |
| period_required | boolean | `true` | 기간이 필수인 유형인지 |
| list_period_usable | boolean | `false` | 요청의 `start_date`, `end_date`(목록의 조회 기간)를 그대로 쓸 수 있는지. 기간이 필수인데 없거나 1년을 넘으면 `false`, 기간이 필수가 아니면 늘 `true` |
| default_period | object\|null | `{"mode":"custom","date_type":"created_at","start_date":"2026-10-01","end_date":"2026-11-01"}` | 기간이 필수인 유형의 기본 기간 (오늘 포함 최근 1개월). 필수가 아니면 `null` |
| max_period_days | integer | `366` | 기간 상한 일수 (시작일 포함 1년) |
| max_selected_ids | integer | `1000` | 「선택한 항목」 범위의 최대 ID 수 |
| locales | array | `[{"code":"ko","label":"한국어"}]` | 다국어 데이터 언어 `{code, label}` |
| default_locale | string | `ko` | 기본 데이터 언어 (요청 언어가 지원 언어면 그 언어, 아니면 대체 언어) |
| can_personal_data | boolean | `true` | 요청자가 개인정보 필드를 고를 수 있는지 (`exports.personal_data` 권한) |
| warnings | array | `["queue_sync"]` | 설치 경고 코드 (`queue_sync` 큐 연결이 sync, `storage_public` 공개 디스크) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "내보내기 설정을 불러왔습니다.",
    "data": {
        "type": "mileage",
        "label": "마일리지 내역",
        "formats": [
            {
                "key": "csv",
                "label": "CSV (엑셀 호환)",
                "extension": "csv"
            }
        ],
        "row_units": [
            {
                "key": "transaction",
                "label": "거래 1건당 1행"
            }
        ],
        "default_row_unit": "transaction",
        "screen_columns": [
            {
                "key": "created_at",
                "label": "일시"
            },
            {
                "key": "member",
                "label": "회원"
            },
            "... (총 8건 중 2건 표시)"
        ],
        "screen_column_fields": {
            "transaction": {
                "created_at": [
                    "mileage.created_at"
                ],
                "member": [
                    "mileage.member_name",
                    "mileage.member_uuid"
                ],
                "...": "(6개 키 생략, 총 8개)"
            }
        },
        "fields": {
            "transaction": [
                {
                    "key": "mileage.id",
                    "label": "거래 ID",
                    "group": "extra",
                    "type": "integer",
                    "personal": false,
                    "summable": false,
                    "parent_level": false
                },
                {
                    "key": "mileage.amount",
                    "label": "금액",
                    "group": "amount",
                    "type": "money",
                    "personal": false,
                    "summable": true,
                    "parent_level": false
                },
                "... (총 15건 중 2건 표시)"
            ]
        },
        "parent_level_label": null,
        "date_types": [
            {
                "key": "created_at",
                "label": "거래일"
            }
        ],
        "period_required": true,
        "list_period_usable": false,
        "default_period": {
            "mode": "custom",
            "date_type": "created_at",
            "start_date": "2026-10-01",
            "end_date": "2026-11-01"
        },
        "max_period_days": 366,
        "max_selected_ids": 1000,
        "locales": [
            {
                "code": "ko",
                "label": "한국어"
            },
            {
                "code": "en",
                "label": "English"
            },
            "... (총 3건 중 2건 표시)"
        ],
        "default_locale": "ko",
        "can_personal_data": true,
        "warnings": [
            "queue_sync"
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.exports.create`)이 없거나, 그 유형의 원본 조회 권한(주문=`orders.read` 등)이 없는 경우 (`exceptions.export.domain_permission_required`) |
| 422 | Unprocessable Entity | `type` 이 없거나 등록되지 않은 유형인 경우, 날짜 형식이 틀린 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.catalog`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ExportController@catalog`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.exports.create` + 유형의 원본 조회 권한(없으면 403)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| type | query | string | 예 | `orders` · `products` · `mileage` · 확장 등록 유형 | 내보내기 유형 |
| start_date · end_date | query | string | 아니오 | `Y-m-d` | 목록의 조회 기간 — 그대로 쓸 수 있는지(`list_period_usable`) 판정에만 쓴다 |

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| type · label | string | `mileage` · `마일리지 내역` | 유형 키와 라벨 |
| formats[] | array | `[{"key":"csv","label":"CSV (엑셀 호환)","extension":"csv"}]` | 고를 수 있는 파일 형식 (확장이 `sirsoft-ecommerce.export.registered_formats` 로 더한다) |
| row_units[] · default_row_unit | array · string | `[{"key":"transaction","label":"거래 1건당 1행"}]` | 행 단위 (주문: `order`·`order_option`, 상품: `product`·`option`) |
| screen_columns[] | array | `[{"key":"member","label":"회원"}]` | 목록 화면 컬럼 (「현재 화면 컬럼」 모드의 선택지) |
| screen_column_fields | object | `{"transaction":{"member":["mileage.member_name","mileage.member_uuid"]}}` | 행 단위별 화면 컬럼 → 실제 열(필드 키) 매핑. 합쳐진 컬럼은 여러 열로 나뉜다 |
| fields | object | `{"transaction":[{"key":"mileage.amount","label":"금액","group":"amount","type":"money","personal":false,"summable":true,"parent_level":false}]}` | 행 단위별 고를 수 있는 필드. 개인정보 필드는 `exports.personal_data` 권한이 있을 때만 실린다. `group` 은 화면 컬럼 키(없으면 `extra` = 그 밖의 항목), `parent_level` 은 하위 단위 행에서 상위의 첫 행에만 쓰이는 금액 |
| parent_level_label | string\|null | `상품 단위` | `parent_level` 필드에 붙일 배지 — 그 유형의 상위 단위(주문 단위·상품 단위). 파일 머리글 꼬리표도 같은 단위를 쓴다(`(상품 단위·첫 행에만 표시)`). 문구가 없는 유형(외부 확장)은 `null` |
| date_types[] · period_required | array · boolean | `[{"key":"created_at","label":"거래일"}]` · `true` | 기간 기준과 기간 필수 여부 |
| list_period_usable | boolean | `false` | 목록의 조회 기간을 그대로 쓸 수 있는지 — 기간이 필수인데 없거나 1년을 넘으면 `false`(필수가 아니면 늘 `true`) |
| default_period | object\|null | `{"mode":"custom","date_type":"ordered_at","start_date":"2026-09-16","end_date":"2026-10-16"}` | 기간이 필수인 유형의 기본 기간 — 오늘 포함 최근 1개월(주문은 사이트 시간대, 그 밖은 운영자 시간대). `list_period_usable` 이 `false` 면 창이 이 값으로 채운다. 필수가 아니면 `null` |
| max_period_days · max_selected_ids | integer | `366` · `1000` | 기간 상한(시작일 포함 1년)·선택 범위 상한 |
| locales[] · default_locale | array · string | `[{"code":"ko","label":"한국어"}]` · `ko` | 다국어 데이터 언어 |
| can_personal_data | boolean | `true` | 개인정보 필드를 고를 수 있는지 |
| warnings[] | array | `["queue_sync"]` | 설치 경고 코드 (`queue_sync` 큐 sync · `storage_public` 공개 디스크) — 허용하되 창 맨 위에 알린다 |

---

### POST /api/modules/sirsoft-ecommerce/admin/exports/estimate
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.exports.estimate -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.estimate`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ExportController@estimate`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.exports.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| type | body | string | 예 | — | 내보내기 유형 키 (`orders`, `products`, `mileage`, 확장이 등록한 유형). 없거나 등록되지 않은 키면 422 |
| format | body | string | 예 | — | 파일 형식 키 (카탈로그 `formats[].key`, 기본 제공은 `csv`) |
| column_mode | body | string | 예 | — | 열 구성 방식. `current` 현재 화면 컬럼, `custom` 데이터 선택 |
| screen_columns | body | array | 아니오 | — | `column_mode=current` 일 때 필수. 내보낼 화면 컬럼 키 목록 (카탈로그 `screen_columns[].key`) |
| fields | body | array | 아니오 | — | `column_mode=custom` 일 때 필수. 그 행 단위의 필드 키 목록 (각 150자 이하, 고른 순서가 열 순서). 알 수 없는 키가 있으면 422 |
| row_unit | body | string | 아니오 | — | 행 단위 키 (카탈로그 `row_units[].key`). 생략하면 그 유형의 기본 행 단위 |
| scope | body | string | 예 | — | 조회 범위 한정 키 |
| filters | body | array | 아니오 | — | 추가 필터 조건 맵 (필드별 조건) |
| selected_ids | body | array | 아니오 | max 1000 | selected 식별자 배열 |
| period | body | array | 아니오 | — | 기간 객체 `{mode, date_type, start_date, end_date}`. 기간이 필수인 유형(주문, 마일리지)은 기간이 걸려야 하며 어느 방식이든 시작일 포함 1년까지. `scope=selected` 면 보지 않는다 |
| period.mode | body | string | 아니오 | — | `current` 목록의 조회 기간(`filters.start_date`, `filters.end_date`)을 쓴다(기본), `custom` 이 객체의 `start_date`, `end_date` 를 쓴다 (`custom` 은 두 날짜 모두 필수) |
| period.date_type | body | string | 아니오 | — | 기간 기준 키 (카탈로그 `date_types[].key`, 예: 주문 `ordered_at`, 마일리지 `created_at`) |
| period.start_date | body | date | 아니오 | — | 조회 기간 시작일 (이 날짜 이후 데이터) |
| period.end_date | body | date | 아니오 | — | 조회 기간 종료일 (이 날짜 이전 데이터) |
| locale_mode | body | string | 예 | — | 다국어 데이터 형식. `locale` 한 언어로, `per_column` 언어별 열, `single_column` 한 열에 모든 언어 |
| locale | body | string | 아니오 | — | 로케일 코드 (표시 언어/지역) |
| include_totals | body | boolean | 아니오 | — | 참이면 마지막 줄에 금액 합계 행을 붙인다 (통화별) |
| reason | body | string | 아니오 | max 500 | 다운로드 사유. 예상 건수 계산은 이 값을 보지 않는다 |

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/exports/estimate HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "type": "예시값",
    "format": "예시값",
    "column_mode": "예시값",
    "screen_columns": [
        "예시값"
    ],
    "fields": [
        "예시값"
    ],
    "row_unit": "예시값",
    "scope": "예시값",
    "filters": [
        "예시값"
    ],
    "selected_ids": [
        "예시값"
    ],
    "period": [
        "예시값"
    ],
    "period.mode": "예시값",
    "period.date_type": "예시값",
    "period.start_date": "2026-01-01",
    "period.end_date": "2026-01-01",
    "locale_mode": "ko",
    "locale": "ko",
    "include_totals": true,
    "reason": "예시값"
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| count | integer | `90` | 지금 조건으로 파일에 들어갈 예상 행 수 (선택한 행 단위 기준) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "예상 건수를 계산했습니다.",
    "data": {
        "count": 90
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.exports.create`)이 없거나, 그 유형의 원본 조회 권한(주문=`orders.read` 등)이 없는 경우 (`exceptions.export.domain_permission_required`) |
| 422 | Unprocessable Entity | 검증 실패 (`error.errors` 의 키: `type`, `format`, `fields` 또는 `screen_columns`(비었거나 알 수 없음), `selected_ids`, `period`(필수, 순서, 1년 상한), `filters.*`(그 목록의 검증 규칙)) |
| 429 | Too Many Requests | 운영자당 분당 60회를 넘은 경우 (`throttle:sirsoft-ecommerce.export-estimate`) |
| 500 | Internal Server Error | 건수 계산 중 예외 (`exceptions.operation_failed`) |

<!-- @generated:end -->

**설명**

- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.estimate`
- **컨트롤러**: `ExportController@estimate`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.exports.create` + 원본 조회 권한 · `throttle:sirsoft-ecommerce.export-estimate`(운영자당 분당 60)

본문은 아래 「요청」과 같다(사유·중복 방지 키·동시 요청 상한은 보지 않는다).

**응답 예시**

```json
{
    "success": true,
    "message": "예상 건수를 계산했습니다.",
    "data": { "count": 90 }
}
```

---

### POST /api/modules/sirsoft-ecommerce/admin/exports
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.exports.store -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.store`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ExportController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.exports.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| type | body | string | 예 | — | 내보내기 유형 키 (`orders`, `products`, `mileage`, 확장이 등록한 유형). 없거나 등록되지 않은 키면 422 |
| format | body | string | 예 | — | 파일 형식 키 (카탈로그 `formats[].key`, 기본 제공은 `csv`) |
| column_mode | body | string | 예 | — | 열 구성 방식. `current` 현재 화면 컬럼, `custom` 데이터 선택 |
| screen_columns | body | array | 아니오 | — | `column_mode=current` 일 때 필수. 내보낼 화면 컬럼 키 목록 (카탈로그 `screen_columns[].key`) |
| fields | body | array | 아니오 | — | `column_mode=custom` 일 때 필수. 그 행 단위의 필드 키 목록 (각 150자 이하, 고른 순서가 열 순서). 알 수 없는 키가 있으면 422 |
| row_unit | body | string | 아니오 | — | 행 단위 키 (카탈로그 `row_units[].key`). 생략하면 그 유형의 기본 행 단위 |
| scope | body | string | 예 | — | 조회 범위 한정 키 |
| filters | body | array | 아니오 | — | 추가 필터 조건 맵 (필드별 조건) |
| selected_ids | body | array | 아니오 | max 1000 | selected 식별자 배열 |
| period | body | array | 아니오 | — | 기간 객체 `{mode, date_type, start_date, end_date}`. 기간이 필수인 유형(주문, 마일리지)은 기간이 걸려야 하며 어느 방식이든 시작일 포함 1년까지. `scope=selected` 면 보지 않는다 |
| period.mode | body | string | 아니오 | — | `current` 목록의 조회 기간(`filters.start_date`, `filters.end_date`)을 쓴다(기본), `custom` 이 객체의 `start_date`, `end_date` 를 쓴다 (`custom` 은 두 날짜 모두 필수) |
| period.date_type | body | string | 아니오 | — | 기간 기준 키 (카탈로그 `date_types[].key`, 예: 주문 `ordered_at`, 마일리지 `created_at`) |
| period.start_date | body | date | 아니오 | — | 조회 기간 시작일 (이 날짜 이후 데이터) |
| period.end_date | body | date | 아니오 | — | 조회 기간 종료일 (이 날짜 이전 데이터) |
| locale_mode | body | string | 예 | — | 다국어 데이터 형식. `locale` 한 언어로, `per_column` 언어별 열, `single_column` 한 열에 모든 언어 |
| locale | body | string | 아니오 | — | 로케일 코드 (표시 언어/지역) |
| include_totals | body | boolean | 아니오 | — | 참이면 마지막 줄에 금액 합계 행을 붙인다 (통화별) |
| reason | body | string | 아니오 | max 500 | 다운로드 사유. 고른 필드에 개인정보 필드가 들어가면 필수 (비면 422 `reason`) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.export.request_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/exports HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "type": "예시값",
    "format": "예시값",
    "column_mode": "예시값",
    "screen_columns": [
        "예시값"
    ],
    "fields": [
        "예시값"
    ],
    "row_unit": "예시값",
    "scope": "예시값",
    "filters": [
        "예시값"
    ],
    "selected_ids": [
        "예시값"
    ],
    "period": [
        "예시값"
    ],
    "period.mode": "예시값",
    "period.date_type": "예시값",
    "period.start_date": "2026-01-01",
    "period.end_date": "2026-01-01",
    "locale_mode": "ko",
    "locale": "ko",
    "include_totals": true,
    "reason": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: 만든 작업 (`ExportResource`) 에 지금의 설치 경고 `warnings_now` 를 더한 객체._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `23` | 작업 ID (`{export}` 경로가 받는 값) |
| uuid | string | `5b0e6a8e-2c1f-4a51-9d0b-7f3c2e8a1d40` | 작업 UUID |
| type | string | `orders` | 내보내기 유형 키 |
| type_label | string | `주문내역` | 유형 라벨 |
| format | string | `csv` | 파일 형식 키 |
| status | string | `pending` | 진행 상황 (`pending` 대기, `processing` 진행중, `completed` 완료, `failed` 실패, `expired` 만료). 새 작업은 `pending` |
| status_label | string | `대기` | 진행 상황 라벨 |
| requester | object | `{"name":"관리자","email":"admin@example.com","uuid":"…"}` | 요청자 `{name, email, uuid}`. `name` 은 요청 때 남긴 이름 그대로다(그 시점의 관리자 영역 표시 이름. 회원의 지금 이름이나 표시 이름 설정을 따라 바뀌지 않는다). 계정이 지워졌으면 `uuid` 는 `null`. 이메일은 노출 게이트를 통과할 때만 |
| file_name | string\|null | `null` | 완성 파일명 (완료 전 `null`) |
| file_size | integer\|null | `null` | 파일 크기(바이트, 완료 전 `null`) |
| file_size_label | string\|null | `null` | 파일 크기 표기 (완료 전 `null`) |
| total_rows | integer\|null | `null` | 대상 행 수 (처리를 시작할 때 정해진다) |
| processed_rows | integer | `0` | 처리한 행 수 |
| progress_percent | integer | `0` | 진행률(%). `processed_rows / total_rows` 를 내림한 값, 대상 행 수가 없으면 완료 100, 그 밖 0 |
| download_count | integer | `0` | 다운로드 횟수 |
| contains_personal_data | boolean | `true` | 개인정보 필드 포함 여부 |
| reason | string\|null | `정기 정산 자료` | 다운로드 사유 |
| warnings | array | `[{"code":"queue_sync","label":"동기 처리"}]` | 요청 당시 설치 경고 `{code, label}` |
| error_code | string\|null | `null` | 실패 사유 키 (실패 전 `null`) |
| error_message | string\|null | `null` | 실패 사유 문장 (`error_code` 의 다국어 문구) |
| summary | object | `{"scope":"현재 검색 조건","period":"2026-10-01 ~ 2026-10-31",…}` | 요청 조건 요약 `{scope, period, row_unit, locale_mode, include_totals, field_count, selected_count}` |
| requested_at | string | `2026-11-01 10:15:00` | 요청 일시 (보는 사람 시간대) |
| cutoff_at | string | `2026-11-01 10:15:00` | 기준 시각. 이 뒤에 생긴 행은 파일에 없다 |
| started_at | string\|null | `null` | 처리 시작 일시 |
| completed_at | string\|null | `null` | 처리 완료 일시 |
| expires_at | string\|null | `null` | 파일 보관 만료 일시 |
| is_owner | boolean | `true` | 요청자 본인인지 |
| abilities | object | `{"can_view_downloads":true,"can_download":false,…}` | 이 작업에 대한 동작 권한 (`can_view_downloads`, `can_download`, `can_delete`, `can_retry`, `retry_needs_reason`) |
| warnings_now | array | `["queue_sync"]` | 응답 시점의 설치 경고 코드 (요청 당시 경고 `warnings` 와 별개로, 창이 지금 상태를 알린다) |

**응답 예시**

```http
HTTP/1.1 202
```

```json
{
    "success": true,
    "message": "내보내기를 요청했습니다. 완료되면 「내보내기 관리」에서 내려받을 수 있습니다.",
    "data": {
        "id": 23,
        "uuid": "5b0e6a8e-2c1f-4a51-9d0b-7f3c2e8a1d40",
        "type": "orders",
        "type_label": "주문내역",
        "format": "csv",
        "status": "pending",
        "status_label": "대기",
        "requester": {
            "name": "관리자",
            "email": "admin@example.com",
            "uuid": "a26f0fb0-8b47-4bb9-835a-685b76efc963"
        },
        "file_name": null,
        "file_size": null,
        "file_size_label": null,
        "total_rows": null,
        "processed_rows": 0,
        "progress_percent": 0,
        "download_count": 0,
        "contains_personal_data": true,
        "reason": "정기 정산 자료",
        "warnings": [
            {
                "code": "queue_sync",
                "label": "동기 처리"
            }
        ],
        "error_code": null,
        "error_message": null,
        "summary": {
            "scope": "현재 검색 조건",
            "period": "2026-10-01 ~ 2026-10-31",
            "row_unit": "주문 1건당 1행",
            "locale_mode": "한 언어로",
            "include_totals": true,
            "field_count": 12,
            "selected_count": 0
        },
        "requested_at": "2026-11-01 10:15:00",
        "cutoff_at": "2026-11-01 10:15:00",
        "started_at": null,
        "completed_at": null,
        "expires_at": null,
        "is_owner": true,
        "abilities": {
            "can_view_downloads": true,
            "can_download": false,
            "can_delete": true,
            "can_retry": false,
            "retry_needs_reason": false
        },
        "warnings_now": [
            "queue_sync"
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.exports.create`)이 없거나, 그 유형의 원본 조회 권한이 없거나, 개인정보 필드를 골랐는데 `exports.personal_data` 권한이 없는 경우 (`exceptions.export.domain_permission_required`, 활동 로그 `export.request_denied`) |
| 422 | Unprocessable Entity | 검증 실패 (`error.errors` 의 키: `type`(유형, 대기와 진행중 합계 3건 상한), `period`, `fields` 또는 `screen_columns`, `selected_ids`, `reason`(개인정보 필드인데 사유 없음), `idempotency_key`(헤더 없음, 64자 초과, 허용 문자 밖), `filters.*`) |
| 429 | Too Many Requests | 운영자당 분당 5회를 넘은 경우 (`throttle:sirsoft-ecommerce.export-request`) |
| 500 | Internal Server Error | 큐 발행 실패 (`exceptions.export.enqueue_failed`, 작업은 실패 상태로 남는다) 또는 그 밖의 처리 오류 (`exceptions.operation_failed`) |

<!-- @generated:end -->

**설명**

- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.store`
- **컨트롤러**: `ExportController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.exports.create` + 원본 조회 권한 + (개인정보 필드면) `exports.personal_data` · `throttle:sirsoft-ecommerce.export-request`(운영자당 분당 5)
- **헤더**: `Idempotency-Key` 필수(영숫자·`-`·`_`, 64자 이하). 같은 요청자·같은 키는 새 작업을 만들지 않고 기존 작업을 돌려준다
- **응답**: `202` + 작업(아래 목록 항목과 같은 모양). 권한 거부·동시 요청 상한 초과는 활동 로그(`export.request_denied`)에 남는다

**요청 본문**

| 이름 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- |
| type | string | 예 | 카탈로그 유형 키 | 내보내기 유형 |
| format | string | 예 | 카탈로그 형식 키 | 파일 형식 |
| column_mode | string | 예 | `current` · `custom` | 현재 화면 컬럼 / 데이터 선택 |
| screen_columns[] | string[] | `current` 일 때 | 카탈로그 화면 컬럼 키 | 내보낼 화면 컬럼 |
| fields[] | string[] | `custom` 일 때 | 그 행 단위의 필드 키 | 내보낼 필드(고른 순서가 열 순서) |
| row_unit | string | 아니오 | 카탈로그 행 단위 키 | 행 단위 (생략 시 기본) |
| scope | string | 예 | `filter` · `selected` · `all` | 현재 검색 조건 / 선택한 행 / 조건 없음 |
| filters | object | 아니오 | 그 목록 API 의 쿼리 매개변수 | 「현재 검색 조건」 — 그 목록의 FormRequest 로 위임 검증한다(쪽·쪽당 개수는 버린다) |
| selected_ids[] | integer[] | `selected` 일 때 | 최대 1000 | 대상 ID |
| period | object | 유형에 따라 | `{mode: current\|custom, date_type, start_date, end_date}` | 기간. `custom` 은 시작일 포함 1년까지. `current` 는 목록의 조회 기간(`filters.start_date`·`end_date`) |
| locale_mode | string | 예 | `locale` · `per_column` · `single_column` | 다국어 데이터 형식 |
| locale | string | 아니오 | 카탈로그 언어 코드 | `locale` 모드의 언어 |
| include_totals | boolean | 아니오 | — | 마지막 줄 금액 합계 행 (통화별) |
| reason | string | 개인정보 필드면 예 | 500자 이하 | 다운로드 사유 |

**검증 오류(422) 키**: `type`(유형·동시 요청 3건 상한) · `period`(필수·순서·1년 상한) · `fields`·`screen_columns`(비었거나 알 수 없음) · `selected_ids` · `reason` · `idempotency_key` · `filters.*`(목록 규칙 그대로)

---

### GET /api/modules/sirsoft-ecommerce/admin/exports
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.exports.index -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.index`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ExportController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.exports.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| search_field | query | string | 아니오 | `all` · `file_name` · `requester` | 검색 대상 |
| search_keyword | query | string | 아니오 | max 200 | 검색어 (요청자는 요청 때 이름·지금 이름·이메일, 부분 일치). `%`·`_` 는 글자 그대로 찾는다 |
| requester_uuid | query | string | 아니오 | 회원 UUID | 요청자 한정 — 그 회원 한 사람의 작업만 (요청자 메뉴 「이 요청자의 내보내기만 보기」) |
| statuses[] | query | string[] | 아니오 | `pending` · `processing` · `completed` · `failed` · `expired` | 진행 상황 |
| types[] | query | string[] | 아니오 | 유형 키 | 작업 유형 |
| statuses | query | array | 아니오 | — | 진행 상황 한정 (`pending`, `processing`, `completed`, `failed`, `expired` 중 여럿) |
| types | query | array | 아니오 | — | 작업 유형 한정 (등록된 유형 키 중 여럿) |
| personal | query | string | 아니오 | `all` · `with` · `without` | 개인정보 포함 여부 |
| start_date | query | date | 아니오 | — | 조회 기간 시작일 (이 날짜 이후 데이터) |
| end_date | query | date | 아니오 | — | 조회 기간 종료일 (이 날짜 이전 데이터) |
| mine | query | boolean | 아니오 | — | 내 요청만 |
| sort_by | query | string | 아니오 | — | 정렬 기준 필드명 |
| sort_order | query | string | 아니오 | — | 정렬 방향 (asc 오름차순 / desc 내림차순) |
| per_page | query | integer | 아니오 | min 10, max 100 | 페이지당 항목 수 |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.export.list_validation_rules`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/exports?search_field=%EC%98%88%EC%8B%9C%EA%B0%92&search_keyword=%EC%98%88%EC%8B%9C%EA%B0%92&requester_uuid=%EC%98%88%EC%8B%9C%EA%B0%92&statuses=%EC%98%88%EC%8B%9C%EA%B0%92&types=%EC%98%88%EC%8B%9C%EA%B0%92&personal=%EC%98%88%EC%8B%9C%EA%B0%92&start_date=2026-01-01&end_date=2026-01-01&mine=1&sort_by=%EC%98%88%EC%8B%9C%EA%B0%92&sort_order=%EC%98%88%EC%8B%9C%EA%B0%92&per_page=1&page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| number | integer | `13` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| id | integer | `22` | 기본 키 (내부 식별자) |
| uuid | string | `897d3a02-7280-4bc1-9fa7-b8ad3cdde8cf` | 외부 노출용 UUID (URL/API 식별자, 내부 id 비노출) |
| type | string | `products` | 내보내기 유형 키 (orders=주문내역, products=상품내역, mileage=마일리지 내역, 확장 유형 키) |
| type_label | string | `상품내역` | `type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| format | string | `csv` | 파일 형식 키 (csv, 확장 형식 키) |
| status | string | `completed` | 상태 값 (도메인별 상태 집합 — 사람이 읽는 라벨은 status_label, UI 변형은 status_variant 참조) |
| status_label | string | `완료` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| requester | object | `{"name":"관리자B","email":"admin-b@example.com","uuid":"a317…` | `{name, email, uuid}` — 이메일은 관리자 응답에만 |
| file_name | string | `상품내역_20261029_022345.csv` | 내려받을 때의 파일명 (완료 전 `null`) |
| file_size | integer | `70` | 파일 크기 (바이트, 완료 전 `null`) |
| file_size_label | string | `70 B` | `file_size` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| total_rows | integer | `1` | 대상 행 수 (처리를 시작할 때 정해진다) |
| processed_rows | integer | `1` | 처리한 행 수 (진행률 분자) |
| progress_percent | integer | `100` | 진행률(%). `processed_rows / total_rows` 를 내림한 값, 대상 행 수가 없으면 완료 100, 그 밖 0 |
| download_count | integer | `0` | 다운로드 횟수 |
| contains_personal_data | boolean | `false` | 개인정보 필드 포함 여부 |
| reason | string | `월말 정산 자료` | 다운로드 사유 (개인정보 포함 요청에서 필수) |
| warnings | array | `[]` | 요청 당시 설치 경고 `{code, label}` 목록 |
| error_code | string | `timeout` | 실패 사유 키 (예외 원문은 저장하지 않음) |
| error_message | string | `처리 시간이 한도를 넘어 중단했습니다. 기간이나 범위를 줄여 다시 …` | 실패 사유 문장 (`error_code` 의 다국어 문구, 실패가 아니면 `null`) |
| summary | object | `{"scope":"선택한 항목","period":"적용 안 함","row_unit":"상품 1개당 1행…` | `{scope, period, row_unit, locale_mode, include_totals, field_count, selected_count}` 요청 조건 요약. `period` 는 실제로 걸린 기간 — 직접 지정은 `시작일 ~ 종료일`, 목록의 조회 기간이 걸렸으면 「목록의 현재 기간」, 「선택한 항목」이거나 걸린 기간이 없으면 「적용 안 함」 |
| requested_at | string | `2026-10-29 02:23:45` | requested 일시 |
| cutoff_at | string | `2026-10-29 02:23:45` | cutoff 일시 |
| started_at | string | `2026-10-29 02:23:46` | started 일시 |
| completed_at | string | `2026-10-29 02:23:46` | completed 일시 |
| expires_at | string | `2026-11-05 02:23:46` | expires 일시 |
| is_owner | boolean | `false` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_view_downloads":true,"can_download":true,"can_delet…` | `{"can_delete":true,"can_create":true}` 목록 수준 권한 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "내보내기 목록을 불러왔습니다.",
    "data": {
        "data": [
            {
                "number": 13,
                "id": 22,
                "uuid": "897d3a02-7280-4bc1-9fa7-b8ad3cdde8cf",
                "type": "products",
                "type_label": "상품내역",
                "format": "csv",
                "status": "completed",
                "status_label": "완료",
                "requester": {
                    "name": "관리자B",
                    "email": "admin-b@example.com",
                    "uuid": "a3176178-2cfb-4e19-944e-373decb7c21f"
                },
                "file_name": "상품내역_20261029_022345.csv",
                "file_size": 70,
                "file_size_label": "70 B",
                "total_rows": 1,
                "processed_rows": 1,
                "progress_percent": 100,
                "download_count": 0,
                "contains_personal_data": false,
                "reason": null,
                "warnings": [],
                "error_code": null,
                "error_message": null,
                "summary": {
                    "scope": "선택한 항목",
                    "period": "적용 안 함",
                    "row_unit": "상품 1개당 1행",
                    "locale_mode": "한 언어로",
                    "include_totals": false,
                    "field_count": 2,
                    "selected_count": 1
                },
                "requested_at": "2026-10-29 02:23:45",
                "cutoff_at": "2026-10-29 02:23:45",
                "started_at": "2026-10-29 02:23:46",
                "completed_at": "2026-10-29 02:23:46",
                "expires_at": "2026-11-05 02:23:46",
                "is_owner": false,
                "abilities": {
                    "can_view_downloads": true,
                    "can_download": true,
                    "can_delete": true,
                    "can_retry": false,
                    "retry_needs_reason": false
                }
            },
            {
                "number": 12,
                "id": 21,
                "uuid": "aa62908b-4b48-4513-9305-bea27cbde7a9",
                "type": "products",
                "type_label": "상품내역",
                "format": "csv",
                "status": "completed",
                "status_label": "완료",
                "requester": {
                    "name": "관리자",
                    "email": "admin@example.com",
                    "uuid": "a3171880-5270-4b99-8578-03b635db0f3c"
                },
                "file_name": "상품내역_20261029_021122.csv",
                "file_size": 5973,
                "file_size_label": "5.83 KB",
                "total_rows": 115,
                "processed_rows": 115,
                "progress_percent": 100,
                "download_count": 0,
                "contains_personal_data": false,
                "reason": null,
                "warnings": [],
                "error_code": null,
                "error_message": null,
                "summary": {
                    "scope": "현재 검색 조건",
                    "period": "적용 안 함",
                    "row_unit": "상품 1개당 1행",
                    "locale_mode": "한 언어로",
                    "include_totals": false,
                    "field_count": 3,
                    "selected_count": 0
                },
                "requested_at": "2026-10-29 02:11:22",
                "cutoff_at": "2026-10-29 02:11:22",
                "started_at": "2026-10-29 02:11:24",
                "completed_at": "2026-10-29 02:11:25",
                "expires_at": "2026-11-05 02:11:25",
                "is_owner": false,
                "abilities": {
                    "can_view_downloads": true,
                    "can_download": true,
                    "can_delete": true,
                    "can_retry": false,
                    "retry_needs_reason": false
                }
            },
            "... (총 13건 중 2건 표시)"
        ],
        "abilities": {
            "can_delete": true,
            "can_create": true
        },
        "has_active": false,
        "warnings": [
            {
                "code": "queue_sync",
                "message": "큐 연결이 sync 라 내보내기가 요청 안에서 바로 처리됩니다. 건수가 많으면 화면이 오래 멈추거나 시간 초과로 실패할 수 있습니다. 운영 환경에서는 큐 워커를 설정하세요."
            }
        ],
        "pagination": {
            "current_page": 1,
            "per_page": 25,
            "from": 1,
            "to": 13,
            "has_more_pages": false,
            "last_page": 1,
            "total": 13
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.exports.read`)이 없는 경우 |
| 422 | Unprocessable Entity | `statuses[]`, `types[]` 에 알 수 없는 값이 있거나 정렬, 쪽 크기 등이 규칙을 벗어난 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.index`
- **컨트롤러**: `ExportController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.exports.read` (조회 범위 안의 작업만 — 본인만/소유 역할/전체)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| search_field | query | string | 아니오 | `all` · `file_name` · `requester` | 검색 대상 |
| search_keyword | query | string | 아니오 | max 200 | 검색어 (요청자는 요청 때 이름·지금 이름·이메일, 부분 일치). `%`·`_` 는 글자 그대로 찾는다 |
| requester_uuid | query | string | 아니오 | 회원 UUID | 요청자 한정 — 그 회원 한 사람의 작업만 (요청자 메뉴 「이 요청자의 내보내기만 보기」) |
| statuses[] | query | string[] | 아니오 | `pending` · `processing` · `completed` · `failed` · `expired` | 진행 상황 |
| types[] | query | string[] | 아니오 | 유형 키 | 작업 유형 |
| personal | query | string | 아니오 | `all` · `with` · `without` | 개인정보 포함 여부 |
| start_date · end_date | query | date | 아니오 | Y-m-d | 요청일 (보는 사람 시간대의 하루) |
| mine | query | boolean | 아니오 | — | 내 요청만 |
| sort_by · sort_order | query | string | 아니오 | `requested_at` · `completed_at` · `file_size` · `download_count` / `asc` · `desc` | 정렬 |
| per_page · page | query | integer | 아니오 | 10~100 | 쪽 |

**응답 필드** (`data` 내부)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| data[] | array | 작업 목록 — 아래 「작업」 필드 |
| abilities | object | `{"can_delete":true,"can_create":true}` 목록 수준 권한 |
| has_active | boolean | 대기·진행중 작업이 있는지 — 화면이 참이면 몇 초마다 다시 받고 거짓이면 멈춘다 |
| warnings[] | array | `[{"code":"queue_sync","message":"…"}]` 설치 경고 + 「대기 지연」(`pending_stalled`, 10분 넘게 대기) |
| pagination | object | `current_page` · `per_page` · `has_more_pages` · `last_page` · `total` |

**작업 필드** (`data.data[]` · 상세 · 요청 응답 공통)

| 필드 | 타입 | 용도/설명 |
| --- | --- | --- |
| id · uuid | integer · string | 식별자 |
| type · type_label · format | string | 유형·라벨·형식 |
| status · status_label | string | 진행 상황 |
| requester | object | `{name, email, uuid}` — 이메일은 관리자 응답에만 |
| file_name · file_size · file_size_label | string · integer · string | 완성 파일 (완료 전 `null`) |
| total_rows · processed_rows · progress_percent | integer | 진행률 |
| download_count | integer | 다운로드 횟수 |
| contains_personal_data · reason | boolean · string | 개인정보 포함 여부와 사유 |
| warnings[] | array | 요청 당시 경고 `{code, label}` |
| error_code · error_message | string | 실패 사유 키와 문장 |
| summary | object | `{scope, period, row_unit, locale_mode, include_totals, field_count, selected_count}` 요청 조건 요약. `period` 는 실제로 걸린 기간 — 직접 지정은 `시작일 ~ 종료일`, 목록의 조회 기간이 걸렸으면 「목록의 현재 기간」, 「선택한 항목」이거나 걸린 기간이 없으면 「적용 안 함」 |
| requested_at · cutoff_at · started_at · completed_at · expires_at | string | 보는 사람 시간대 일시. `cutoff_at` 뒤에 생긴 행은 파일에 없다 |
| abilities | object | `can_view_downloads` · `can_download`(완료+권한+범위+원본 조회 권한, 남의 파일이면 원본 조회 범위 「전체」) · `can_delete`(진행중 제외) · `can_retry`(실패만) · `retry_needs_reason`(남의 개인정보 작업) |

---

### GET /api/modules/sirsoft-ecommerce/admin/exports/{export}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.exports.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ExportController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.exports.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| export | path | integer | 예 | — | 대상 export의 식별자 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.export.show_validation_rules`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/exports/{export} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `10` | 기본 키 (내부 식별자) |
| uuid | string | `22d5f796-401d-403f-9571-d45056d92108` | 외부 노출용 UUID (URL/API 식별자, 내부 id 비노출) |
| type | string | `orders` | 내보내기 유형 키 (orders=주문내역, products=상품내역, mileage=마일리지 내역, 확장 유형 키) |
| type_label | string | `주문내역` | `type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| format | string | `csv` | 파일 형식 키 (csv, 확장 형식 키) |
| status | string | `completed` | 상태 값 (도메인별 상태 집합 — 사람이 읽는 라벨은 status_label, UI 변형은 status_variant 참조) |
| status_label | string | `완료` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| requester | object | `{"name":"관리자","email":"admin@example.com","uuid":"a3171880…` | 요청자 `{name, email, uuid}`. `name` 은 요청 때 남긴 이름 그대로다(그 시점의 관리자 영역 표시 이름. 회원의 지금 이름이나 표시 이름 설정을 따라 바뀌지 않는다). 계정이 지워졌으면 `uuid` 는 `null`. 이메일은 노출 게이트를 통과할 때만 |
| file_name | string | `주문내역_20261028_230249.csv` | 내려받을 때의 파일명 |
| file_size | integer | `33484` | 파일 크기 (바이트) |
| file_size_label | string | `32.7 KB` | `file_size` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| total_rows | integer | `92` | 대상 행 수 (시작 시 산정) |
| processed_rows | integer | `92` | 처리한 행 수 (진행률 분자) |
| progress_percent | integer | `100` | 진행률(%). `processed_rows / total_rows` 를 내림한 값, 대상 행 수가 없으면 완료 100, 그 밖 0 |
| download_count | integer | `7` | download 개수 (집계) |
| contains_personal_data | boolean | `true` | 개인정보 필드 포함 여부 (1=포함, 0=미포함) |
| reason | string | `정기 정산 자료` | 다운로드 사유 (개인정보 포함 요청에서 필수) |
| warnings | array | `[]` | 요청 시점 경고 코드 목록 (queue_sync=큐 미설정, storage_public=공개 저장소) |
| error_code | null | `null` | 실패 사유 키 (예외 원문은 저장하지 않음) |
| error_message | null | `null` | 실패 사유 문장 (`error_code` 의 다국어 문구, 실패가 아니면 `null`) |
| summary | object | `{"scope":"현재 검색 조건","period":"2026-09-28 ~ 2026-10-28","r…` | 요청 조건 요약 `{scope, period, row_unit, locale_mode, include_totals, field_count, selected_count}`. `period` 는 실제로 걸린 기간 |
| requested_at | string | `2026-10-28 23:02:49` | requested 일시 |
| cutoff_at | string | `2026-10-28 23:02:49` | cutoff 일시 |
| started_at | string | `2026-10-28 23:02:50` | started 일시 |
| completed_at | string | `2026-10-28 23:02:50` | completed 일시 |
| expires_at | string | `2026-11-04 23:02:50` | expires 일시 |
| is_owner | boolean | `false` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_view_downloads":true,"can_download":true,"can_delet…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "내보내기 작업을 불러왔습니다.",
    "data": {
        "id": 10,
        "uuid": "22d5f796-401d-403f-9571-d45056d92108",
        "type": "orders",
        "type_label": "주문내역",
        "format": "csv",
        "status": "completed",
        "status_label": "완료",
        "requester": {
            "name": "관리자",
            "email": "admin@example.com",
            "uuid": "a3171880-5270-4b99-8578-03b635db0f3c"
        },
        "file_name": "주문내역_20261028_230249.csv",
        "file_size": 33484,
        "file_size_label": "32.7 KB",
        "total_rows": 92,
        "processed_rows": 92,
        "progress_percent": 100,
        "download_count": 7,
        "contains_personal_data": true,
        "reason": "정기 정산 자료",
        "warnings": [],
        "error_code": null,
        "error_message": null,
        "summary": {
            "scope": "현재 검색 조건",
            "period": "2026-09-28 ~ 2026-10-28",
            "row_unit": "주문 1건당 1행",
            "locale_mode": "한 언어로",
            "include_totals": true,
            "field_count": 36,
            "selected_count": 0
        },
        "requested_at": "2026-10-28 23:02:49",
        "cutoff_at": "2026-10-28 23:02:49",
        "started_at": "2026-10-28 23:02:50",
        "completed_at": "2026-10-28 23:02:50",
        "expires_at": "2026-11-04 23:02:50",
        "is_owner": false,
        "abilities": {
            "can_view_downloads": true,
            "can_download": true,
            "can_delete": true,
            "can_retry": false,
            "retry_needs_reason": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.exports.read`)이 없거나, 작업이 조회 권한 범위 밖인 경우 (`exceptions.export.scope_denied`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 404 | Not Found | 없거나 이미 지운 작업 번호인 경우 (`messages.export.not_found`, 요청 언어) |

<!-- @generated:end -->

**설명**

- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.show` · **권한**: `exports.read` (범위 검사 — 범위 밖 403 `scope_denied`)
- 응답: 「작업 필드」 한 건
- 없거나 이미 지운 작업 번호는 이 절의 모든 `{export}` 경로에서 404 `{"success":false,"message":"내보내기 작업을 찾을 수 없습니다."}`(요청 언어)

### GET /api/modules/sirsoft-ecommerce/admin/exports/{export}/download
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.exports.download -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.download`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ExportController@download`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.exports.download`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| export | path | integer | 예 | — | 대상 export의 식별자 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.export.download_validation_rules`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/exports/{export}/download HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON `data` 를 반환하지 않습니다. 성공 시 파일 바이트를 스트리밍하며(`Content-Disposition: attachment`, `Cache-Control: private, no-store`), JSON 봉투는 실패 시에만 씁니다. 받을 때마다 다운로드 이력 1행이 쌓이고 `download_count` 가 1 늘며 활동 로그 `export.download` 가 남습니다._

**응답 예시**

```http
HTTP/1.1 200
Content-Type: text/csv; charset=UTF-8
Content-Disposition: attachment; filename="주문내역_20261028_230249.csv"

(파일 바이너리 스트림)
```

실패 시(보관 기간이 지난 파일):

```json
{
    "success": false,
    "message": "보관 기간이 지나 파일이 삭제되었습니다."
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.exports.download`)이 없거나 작업이 다운로드 권한 범위 밖인 경우 (`exceptions.export.scope_denied`), 원본 조회 권한 또는 개인정보 권한과 그 범위가 모자란 경우 (`exceptions.export.domain_permission_required`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 404 | Not Found | 없거나 이미 지운 작업 번호인 경우 (`messages.export.not_found`), 저장소에 파일이 없는 경우 (`exceptions.export.file_missing`) |
| 410 | Gone | 아직 완료되지 않은 작업 (`exceptions.export.not_downloadable`), 보관 기간이 지난 작업 (`exceptions.export.file_expired`) |
| 500 | Internal Server Error | 파일 전송 준비 중 예외 (`exceptions.operation_failed`) |

<!-- @generated:end -->

**설명**

- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.download` · **권한**: `exports.download` (범위 검사 — 범위 밖 403 `scope_denied`) + 원본 조회 권한 + (개인정보 파일이면) `exports.personal_data` 와 그 범위 · 남이 만든 파일이면 원본 조회 범위가 「전체」, 자기 파일이면 만들 때보다 좁지 않아야 함(아니면 403 `domain_permission_required`)
- 응답: 파일 바이트(`text/csv`, `Content-Disposition: attachment`). 완료 전 410(`not_downloadable`) · 만료 410(`file_expired`) · 파일 없음 404 · 원본 조회 권한 없음 403
- 부수 효과: 다운로드 이력 1행, `download_count` +1, 활동 로그 `export.download`

### POST /api/modules/sirsoft-ecommerce/admin/exports/{export}/download-link
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.exports.download-link -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.download-link`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ExportController@downloadLink`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.exports.download`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| export | path | integer | 예 | — | 대상 export의 식별자 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.export.download_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/exports/{export}/download-link HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| url | string | `/api/modules/sirsoft-ecommerce/exports/file/{token}` | 일회용 다운로드 주소 (호스트 없는 상대 경로, 64자 토큰 포함). 한 번만 쓸 수 있다 |
| expires_in | integer | `60` | 주소 유효 시간(초) |
| download_count | integer | `7` | 발급 시점의 다운로드 횟수 (화면이 이 값보다 커질 때까지 상세를 다시 읽어 기록 반영을 확인한다) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "다운로드 주소를 발급했습니다.",
    "data": {
        "url": "/api/modules/sirsoft-ecommerce/exports/file/{token}",
        "expires_in": 60,
        "download_count": 7
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.exports.download`)이 없거나 작업이 다운로드 권한 범위 밖인 경우 (`exceptions.export.scope_denied`), 원본 조회 권한 또는 개인정보 권한과 그 범위가 모자란 경우 (`exceptions.export.domain_permission_required`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 404 | Not Found | 없거나 이미 지운 작업 번호인 경우 (`messages.export.not_found`) |
| 410 | Gone | 아직 완료되지 않은 작업 (`exceptions.export.not_downloadable`), 보관 기간이 지난 작업 (`exceptions.export.file_expired`) |

<!-- @generated:end -->

**설명**

- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.download-link` · **권한**: `exports.download` (범위 검사 — 범위 밖 403 `scope_denied`) + 원본 조회 권한 + (개인정보 파일이면) `exports.personal_data` 와 그 범위 · 남이 만든 파일이면 원본 조회 범위가 「전체」, 자기 파일이면 만들 때보다 좁지 않아야 함(아니면 403 `domain_permission_required`)
- 본인 확인(IDV) 정책을 다운로드에 걸려면 이 라우트와 `admin.exports.download` 둘 다에 건다 — 화면은 이 라우트를, API 직접 호출은 다른 쪽을 쓴다. 일회용 주소(`exports.file`)는 로그인 없이 열리는 경로라 정책 대상이 아니다
- 브라우저는 주소창·링크로 여는 요청에 인증 헤더를 싣지 못한다 — 그래서 먼저 이 API 로 일회용 주소를 받는다
- 응답 `data`: `{url, expires_in, download_count}` — `url` 은 아래 `exports/file/{token}` 의 상대 경로, `expires_in` 은 60(초), `download_count` 는 발급 시점의 횟수(화면이 기록 반영을 확인하는 기준). 주소는 한 번만 쓸 수 있다
- 보관 기간(`expires_at`)이 지난 작업은 정리 전이라도 410(`file_expired`)
- 부수 효과 없음(이력·횟수는 실제로 받을 때 남는다)

### GET /api/modules/sirsoft-ecommerce/exports/file/{token}
<!-- @generated:start:api.modules.sirsoft-ecommerce.exports.file -->
- **라우트명**: `api.modules.sirsoft-ecommerce.exports.file`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ExportController@downloadByToken`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| token | path | string | 예 | — | 인증/검증 토큰 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.export.download_by_token_validation_rules`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/exports/file/{token} HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON `data` 를 반환하지 않습니다. 성공 시 파일 바이트를 스트리밍하며(`Content-Disposition: attachment`, `Cache-Control: private, no-store`), JSON 봉투는 실패 시에만 씁니다. 토큰은 쓰는 순간 소모되며, 받을 때마다 다운로드 이력 1행이 쌓이고 `download_count` 가 1 늘며 활동 로그 `export.download` 가 남습니다._

**응답 예시**

```http
HTTP/1.1 200
Content-Type: text/csv; charset=UTF-8
Content-Disposition: attachment; filename="주문내역_20261028_230249.csv"

(파일 바이너리 스트림)
```

실패 시(만료됐거나 이미 쓴 토큰):

```json
{
    "success": false,
    "message": "다운로드 주소가 만료됐거나 이미 사용됐습니다. 내보내기 관리에서 파일명을 다시 누르세요."
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 404 | Not Found | 토큰이 64자 영숫자가 아니라 라우트에 맞지 않는 경우, 저장소에 파일이 없는 경우 (`exceptions.export.file_missing`) |
| 403 | Forbidden | 토큰 주인에게 지금 `exports.download` 권한이나 범위가 없는 경우 (`exceptions.export.scope_denied`), 원본 조회 권한 또는 개인정보 권한과 그 범위가 모자란 경우 (`exceptions.export.domain_permission_required`) |
| 410 | Gone | 만료됐거나 이미 쓴 토큰 (`exceptions.export.download_link_expired`), 보관 기간이 지난 작업 (`exceptions.export.file_expired`) |
| 429 | Too Many Requests | 분당 30회를 넘은 경우 (`throttle:sirsoft-ecommerce.export-file`) |

<!-- @generated:end -->

**설명**

- **라우트명**: `api.modules.sirsoft-ecommerce.exports.file` · **인증**: 없음 — 64자 일회용 토큰이 대신한다 · `throttle:sirsoft-ecommerce.export-file`(분당 30)
- 받을 때 토큰을 발급받은 운영자의 `exports.download` 권한·범위·원본 조회 권한을 **다시** 본다 — 그사이 권한을 잃으면 403
- 응답: `GET {export}/download` 와 같다(파일 바이트·부수 효과). 만료·이미 쓴 토큰 410(`download_link_expired`) · 형식이 틀린 토큰 404

### GET /api/modules/sirsoft-ecommerce/admin/exports/{export}/downloads
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.exports.downloads -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.downloads`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ExportController@downloads`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.exports.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| export | path | integer | 예 | — | 대상 export의 식별자 |
| per_page | query | integer | 아니오 | min 10, max 100 | 페이지당 항목 수 |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.export.downloads_validation_rules`).

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/admin/exports/{export}/downloads?per_page=1&page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `15` | 기본 키 (내부 식별자) |
| sequence | integer | `8` | 몇 번째 다운로드인지 (최신이 가장 큰 번호, 전체 건수에서 쪽 위치를 빼 매긴다) |
| user | object | `{"name":"관리자","uuid":"a26f0fb0-8b47-4bb9-835a-685b76efc963"}` | 대상 사용자 정보 객체 (uuid/name/email 등 — user 관계 파생) |
| ip_address | string | `127.0.0.1` | 요청/행위가 발생한 IP 주소 |
| downloaded_at | string | `2026-11-01 19:28:30` | downloaded 일시 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "다운로드 이력을 불러왔습니다.",
    "data": {
        "data": [
            {
                "id": 15,
                "sequence": 8,
                "user": {
                    "name": "관리자",
                    "uuid": "a26f0fb0-8b47-4bb9-835a-685b76efc963"
                },
                "ip_address": "127.0.0.1",
                "downloaded_at": "2026-11-01 19:28:30"
            },
            {
                "id": 14,
                "sequence": 7,
                "user": {
                    "name": "관리자",
                    "uuid": "a26f0fb0-8b47-4bb9-835a-685b76efc963"
                },
                "ip_address": "127.0.0.1",
                "downloaded_at": "2026-11-01 19:06:15"
            },
            "... (총 8건 중 2건 표시)"
        ],
        "pagination": {
            "current_page": 1,
            "per_page": 25,
            "from": 1,
            "to": 8,
            "has_more_pages": false,
            "last_page": 1,
            "total": 8
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.exports.read`)이 없거나, 작업이 조회 권한 범위 밖인 경우 (`exceptions.export.scope_denied`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 404 | Not Found | 없거나 이미 지운 작업 번호인 경우 (`messages.export.not_found`) |

<!-- @generated:end -->

**설명**

- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.downloads` · **권한**: `exports.read` (범위 검사 — 범위 밖 403 `scope_denied`)
- 요청: `per_page`(10~100) · `page`
- 응답 `data.data[]`: `{id, sequence, user: {name, uuid}, ip_address, downloaded_at}` + `pagination`. 조회 자체가 활동 로그 `export.view_downloads` 로 남는다
- `ip_address` 는 보는 사람에게 `exports.personal_data` 가 있고 이 작업이 그 권한의 범위(본인만/소유 역할/전체) 안일 때만 값이 있고 아니면 `null`

### POST /api/modules/sirsoft-ecommerce/admin/exports/{export}/retry
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.exports.retry -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.retry`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ExportController@retry`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.exports.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| export | path | integer | 예 | — | 대상 export의 식별자 |
| reason | body | string | 아니오 | max 500 | 다운로드 사유. 다른 운영자가 요청한 개인정보 작업을 다시 요청하면 필수, 자기 작업이면 비워도 원래 사유를 쓴다 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.export.retry_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-ecommerce/admin/exports/{export}/retry HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "reason": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: 새로 만든 작업 (`ExportResource`). 필드는 `POST /admin/exports` 응답과 같고 `warnings_now` 만 없다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `23` | 작업 ID (`{export}` 경로가 받는 값) |
| uuid | string | `5b0e6a8e-2c1f-4a51-9d0b-7f3c2e8a1d40` | 작업 UUID |
| type | string | `orders` | 내보내기 유형 키 |
| type_label | string | `주문내역` | 유형 라벨 |
| format | string | `csv` | 파일 형식 키 |
| status | string | `pending` | 진행 상황 (`pending` 대기, `processing` 진행중, `completed` 완료, `failed` 실패, `expired` 만료). 새 작업은 `pending` |
| status_label | string | `대기` | 진행 상황 라벨 |
| requester | object | `{"name":"관리자","email":"admin@example.com","uuid":"…"}` | 요청자 `{name, email, uuid}`. `name` 은 요청 때 남긴 이름 그대로다(그 시점의 관리자 영역 표시 이름. 회원의 지금 이름이나 표시 이름 설정을 따라 바뀌지 않는다). 계정이 지워졌으면 `uuid` 는 `null`. 이메일은 노출 게이트를 통과할 때만 |
| file_name | string\|null | `null` | 완성 파일명 (완료 전 `null`) |
| file_size | integer\|null | `null` | 파일 크기(바이트, 완료 전 `null`) |
| file_size_label | string\|null | `null` | 파일 크기 표기 (완료 전 `null`) |
| total_rows | integer\|null | `null` | 대상 행 수 (처리를 시작할 때 정해진다) |
| processed_rows | integer | `0` | 처리한 행 수 |
| progress_percent | integer | `0` | 진행률(%). `processed_rows / total_rows` 를 내림한 값, 대상 행 수가 없으면 완료 100, 그 밖 0 |
| download_count | integer | `0` | 다운로드 횟수 |
| contains_personal_data | boolean | `true` | 개인정보 필드 포함 여부 |
| reason | string\|null | `정기 정산 자료` | 다운로드 사유 |
| warnings | array | `[{"code":"queue_sync","label":"동기 처리"}]` | 요청 당시 설치 경고 `{code, label}` |
| error_code | string\|null | `null` | 실패 사유 키 (실패 전 `null`) |
| error_message | string\|null | `null` | 실패 사유 문장 (`error_code` 의 다국어 문구) |
| summary | object | `{"scope":"현재 검색 조건","period":"2026-10-01 ~ 2026-10-31",…}` | 요청 조건 요약 `{scope, period, row_unit, locale_mode, include_totals, field_count, selected_count}` |
| requested_at | string | `2026-11-01 10:15:00` | 요청 일시 (보는 사람 시간대) |
| cutoff_at | string | `2026-11-01 10:15:00` | 기준 시각. 이 뒤에 생긴 행은 파일에 없다 |
| started_at | string\|null | `null` | 처리 시작 일시 |
| completed_at | string\|null | `null` | 처리 완료 일시 |
| expires_at | string\|null | `null` | 파일 보관 만료 일시 |
| is_owner | boolean | `true` | 요청자 본인인지 |
| abilities | object | `{"can_view_downloads":true,"can_download":false,…}` | 이 작업에 대한 동작 권한 (`can_view_downloads`, `can_download`, `can_delete`, `can_retry`, `retry_needs_reason`) |

**응답 예시**

```http
HTTP/1.1 202
```

```json
{
    "success": true,
    "message": "내보내기를 요청했습니다. 완료되면 「내보내기 관리」에서 내려받을 수 있습니다.",
    "data": {
        "id": 23,
        "uuid": "5b0e6a8e-2c1f-4a51-9d0b-7f3c2e8a1d40",
        "type": "orders",
        "type_label": "주문내역",
        "format": "csv",
        "status": "pending",
        "status_label": "대기",
        "requester": {
            "name": "관리자",
            "email": "admin@example.com",
            "uuid": "a26f0fb0-8b47-4bb9-835a-685b76efc963"
        },
        "file_name": null,
        "file_size": null,
        "file_size_label": null,
        "total_rows": null,
        "processed_rows": 0,
        "progress_percent": 0,
        "download_count": 0,
        "contains_personal_data": true,
        "reason": "정기 정산 자료",
        "warnings": [
            {
                "code": "queue_sync",
                "label": "동기 처리"
            }
        ],
        "error_code": null,
        "error_message": null,
        "summary": {
            "scope": "현재 검색 조건",
            "period": "2026-10-01 ~ 2026-10-31",
            "row_unit": "주문 1건당 1행",
            "locale_mode": "한 언어로",
            "include_totals": true,
            "field_count": 12,
            "selected_count": 0
        },
        "requested_at": "2026-11-01 10:15:00",
        "cutoff_at": "2026-11-01 10:15:00",
        "started_at": null,
        "completed_at": null,
        "expires_at": null,
        "is_owner": true,
        "abilities": {
            "can_view_downloads": true,
            "can_download": false,
            "can_delete": true,
            "can_retry": false,
            "retry_needs_reason": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.exports.create`)이 없거나 작업이 조회 권한 범위 밖인 경우 (`exceptions.export.scope_denied`), 그 유형의 원본 조회 권한이나 개인정보 권한이 없는 경우 (`exceptions.export.domain_permission_required`) |
| 422 | Unprocessable Entity | 사유가 필요한데 비었거나 500자를 넘은 경우 (`error.errors.reason`), 유형을 지금 쓸 수 없는 경우 (`exceptions.export.type_unavailable`), 지금 조건에 내보낼 행이 없는 경우 (`exceptions.export.no_rows`, 새 작업을 만들지 않음) |
| 404 | Not Found | 없거나 이미 지운 작업 번호인 경우 (`messages.export.not_found`) |
| 409 | Conflict | 실패 상태가 아닌 작업 (`exceptions.export.not_retryable`) |
| 429 | Too Many Requests | 운영자당 분당 5회를 넘은 경우 (`throttle:sirsoft-ecommerce.export-request`) |

<!-- @generated:end -->

**설명**

- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.retry` · **권한**: `exports.create` + 원본 조회 권한 · `throttle:sirsoft-ecommerce.export-request`
- 실패한 작업과 같은 조건으로 **새 작업**을 만든다(202) — 새 작업은 다시 요청한 사람의 권한·범위로 뽑힌다. 실패가 아니면 409(`not_retryable`), 지금 조건에 내보낼 행이 없으면 422(`no_rows` — 새 작업을 만들지 않는다)
- 본문: `reason`(문자열, 500자) — 다른 운영자가 요청한 개인정보 작업이면 필수(없으면 422). 자기 작업이면 비워도 원래 사유를 쓴다
- 목록·상세의 `abilities.retry_needs_reason` 이 참이면 창이 사유 칸을 연다

### DELETE /api/modules/sirsoft-ecommerce/admin/exports/{export}
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.exports.destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ExportController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.exports.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| export | path | integer | 예 | — | 대상 export의 식별자 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.export.destroy_validation_rules`).

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/exports/{export} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만 — `data` 는 `null`)._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "내보내기 파일을 삭제했습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.exports.delete`)이 없거나, 작업이 삭제 권한 범위 밖인 경우 (`exceptions.export.scope_denied`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 404 | Not Found | 없거나 이미 지운 작업 번호인 경우 (`messages.export.not_found`) |
| 409 | Conflict | 진행중인 작업 (`exceptions.export.processing_not_deletable`) |
| 500 | Internal Server Error | 파일 삭제 중 예외 (`exceptions.operation_failed`) |

<!-- @generated:end -->

**설명**

- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.destroy` · **권한**: `exports.delete` (범위 검사 — 범위 밖 403 `scope_denied`)
- 파일을 지우고 작업은 소프트 삭제한다(다운로드 이력·활동 로그는 남는다). 진행중이면 409(`processing_not_deletable`)

### DELETE /api/modules/sirsoft-ecommerce/admin/exports/bulk
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.exports.bulk-destroy -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.bulk-destroy`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ExportController@bulkDestroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.exports.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | query | array | 예 | min 1, max 100 | 대상 리소스 식별자 배열 (대량 작업 대상) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-ecommerce.export.bulk_delete_validation_rules`).

**요청 예시**

```http
DELETE /api/modules/sirsoft-ecommerce/admin/exports/bulk?ids=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted_count | integer | `3` | 실제로 삭제한 작업 수 |
| skipped_count | integer | `1` | 건너뛴 작업 수 (진행중, 삭제 권한 범위 밖, 이미 없는 작업) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "3건을 삭제했습니다. (건너뜀 1건)",
    "data": {
        "deleted_count": 3,
        "skipped_count": 1
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.exports.delete`)이 없는 경우 |
| 422 | Unprocessable Entity | `ids` 가 비었거나 100개를 넘는 경우, 정수가 아닌 값이 섞인 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 삭제 처리 중 예외 (`exceptions.operation_failed`) |

<!-- @generated:end -->

**설명**

- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.bulk-destroy` · **권한**: `exports.delete`
- 본문: `ids[]`(1~100). 진행중·범위 밖·이미 없는 작업은 거절하지 않고 건너뛴다
- 응답 `data`: `{deleted_count, skipped_count}`
