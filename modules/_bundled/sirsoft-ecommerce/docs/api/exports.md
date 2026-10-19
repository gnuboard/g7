# Exports API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **작성**: 손으로 작성(`api:docgen` 기본 경로는 사람이 쓴 서술을 다시 만들어 지운다). 예시 응답은 개발 사이트에서 실제 호출로 관측한 값이다.

---

## TL;DR (5초 요약)

```text
1. 목록 화면의 [내보내기] 창: 카탈로그(GET catalog) → 예상 행 수(POST estimate) → 요청(POST, 202, Idempotency-Key 헤더 필수)
2. 파일은 큐 작업이 만든다 — 요청 응답은 대기(pending) 작업이고, 진행 상황은 목록(GET)·상세(GET {export})로 본다
3. 권한: exports.create/read/download/delete + 그 유형의 원본 조회 권한(주문=orders.read …) + 개인정보 필드는 exports.personal_data
4. 범위: `exports.all`(다른 운영자의 내보내기 관리)이 없으면 본인이 요청한 작업만 보고·받고·지우고·다시 요청한다. 범위 밖 작업은 403(`scope_denied`)
5. 내려받기(GET {export}/download)는 파일 바이트를 준다(JSON 아님) — 다운로드 이력·횟수·활동 로그가 남는다
6. 화면은 일회용 주소를 쓴다: POST {export}/download-link 로 60초·1회용 주소를 받고 브라우저가 GET exports/file/{token} 을 직접 연다(파일을 메모리에 올리지 않음)
```

---

### GET /api/modules/sirsoft-ecommerce/admin/exports/catalog

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

- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.index`
- **컨트롤러**: `ExportController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.exports.read` (`exports.all` 이 없으면 자기 요청만)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| search_field | query | string | 아니오 | `all` · `file_name` · `requester` | 검색 대상 |
| search_keyword | query | string | 아니오 | max 200 | 검색어 (요청자는 이름·이메일) |
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
| summary | object | `{scope, period, row_unit, locale_mode, include_totals, field_count, selected_count}` 요청 조건 요약 |
| requested_at · cutoff_at · started_at · completed_at · expires_at | string | 보는 사람 시간대 일시. `cutoff_at` 뒤에 생긴 행은 파일에 없다 |
| abilities | object | `can_view_downloads` · `can_download`(완료+권한+범위+원본 조회 권한, 남의 파일이면 원본 조회 범위 「전체」) · `can_delete`(진행중 제외) · `can_retry`(실패만) · `retry_needs_reason`(남의 개인정보 작업) |

---

### GET /api/modules/sirsoft-ecommerce/admin/exports/{export}

- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.show` · **권한**: `exports.read` (범위 검사 — 범위 밖 403 `scope_denied`)
- 응답: 「작업 필드」 한 건

### GET /api/modules/sirsoft-ecommerce/admin/exports/{export}/download

- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.download` · **권한**: `exports.download` (범위 검사 — 범위 밖 403 `scope_denied`) + 원본 조회 권한 + (개인정보 파일이면) `exports.personal_data` · 남이 만든 파일이면 원본 조회 범위가 「전체」, 자기 파일이면 만들 때보다 좁지 않아야 함(아니면 403 `domain_permission_required`)
- 응답: 파일 바이트(`text/csv`, `Content-Disposition: attachment`). 완료 전 410(`not_downloadable`) · 만료 410(`file_expired`) · 파일 없음 404 · 원본 조회 권한 없음 403
- 부수 효과: 다운로드 이력 1행, `download_count` +1, 활동 로그 `export.download`

### POST /api/modules/sirsoft-ecommerce/admin/exports/{export}/download-link

- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.download-link` · **권한**: `exports.download` (범위 검사 — 범위 밖 403 `scope_denied`) + 원본 조회 권한 + (개인정보 파일이면) `exports.personal_data` · 남이 만든 파일이면 원본 조회 범위가 「전체」, 자기 파일이면 만들 때보다 좁지 않아야 함(아니면 403 `domain_permission_required`)
- 본인 확인(IDV) 정책을 다운로드에 걸려면 이 라우트와 `admin.exports.download` 둘 다에 건다 — 화면은 이 라우트를, API 직접 호출은 다른 쪽을 쓴다. 일회용 주소(`exports.file`)는 로그인 없이 열리는 경로라 정책 대상이 아니다
- 브라우저는 주소창·링크로 여는 요청에 인증 헤더를 싣지 못한다 — 그래서 먼저 이 API 로 일회용 주소를 받는다
- 응답 `data`: `{url, expires_in, download_count}` — `url` 은 아래 `exports/file/{token}` 의 상대 경로, `expires_in` 은 60(초), `download_count` 는 발급 시점의 횟수(화면이 기록 반영을 확인하는 기준). 주소는 한 번만 쓸 수 있다
- 보관 기간(`expires_at`)이 지난 작업은 정리 전이라도 410(`file_expired`)
- 부수 효과 없음(이력·횟수는 실제로 받을 때 남는다)

### GET /api/modules/sirsoft-ecommerce/exports/file/{token}

- **라우트명**: `api.modules.sirsoft-ecommerce.exports.file` · **인증**: 없음 — 64자 일회용 토큰이 대신한다 · `throttle:sirsoft-ecommerce.export-file`(분당 30)
- 받을 때 토큰을 발급받은 운영자의 `exports.download` 권한·범위·원본 조회 권한을 **다시** 본다 — 그사이 권한을 잃으면 403
- 응답: `GET {export}/download` 와 같다(파일 바이트·부수 효과). 만료·이미 쓴 토큰 410(`download_link_expired`) · 형식이 틀린 토큰 404

### GET /api/modules/sirsoft-ecommerce/admin/exports/{export}/downloads

- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.downloads` · **권한**: `exports.read` (범위 검사 — 범위 밖 403 `scope_denied`)
- 요청: `per_page`(10~100) · `page`
- 응답 `data.data[]`: `{id, sequence, user: {name, uuid}, ip_address, downloaded_at}` + `pagination`. 조회 자체가 활동 로그 `export.view_downloads` 로 남는다
- `ip_address` 는 보는 사람에게 `exports.personal_data` 가 있을 때만 값이 있고 없으면 `null`

### POST /api/modules/sirsoft-ecommerce/admin/exports/{export}/retry

- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.retry` · **권한**: `exports.create` + 원본 조회 권한 · `throttle:sirsoft-ecommerce.export-request`
- 실패한 작업과 같은 조건으로 **새 작업**을 만든다(202) — 새 작업은 다시 요청한 사람의 권한·범위로 뽑힌다. 실패가 아니면 409(`not_retryable`)
- 본문: `reason`(문자열, 500자) — 다른 운영자가 요청한 개인정보 작업이면 필수(없으면 422). 자기 작업이면 비워도 원래 사유를 쓴다
- 목록·상세의 `abilities.retry_needs_reason` 이 참이면 창이 사유 칸을 연다

### DELETE /api/modules/sirsoft-ecommerce/admin/exports/{export}

- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.destroy` · **권한**: `exports.delete` (범위 검사 — 범위 밖 403 `scope_denied`)
- 파일을 지우고 작업은 소프트 삭제한다(다운로드 이력·활동 로그는 남는다). 진행중이면 409(`processing_not_deletable`)

### DELETE /api/modules/sirsoft-ecommerce/admin/exports/bulk

- **라우트명**: `api.modules.sirsoft-ecommerce.admin.exports.bulk-destroy` · **권한**: `exports.delete`
- 본문: `ids[]`(1~100). 진행중·범위 밖·이미 없는 작업은 거절하지 않고 건너뛴다
- 응답 `data`: `{deleted_count, skipped_count}`
