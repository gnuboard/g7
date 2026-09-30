# Runs API 레퍼런스

> **소유**: plugin `sirsoft-koreaexim` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Runs 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-koreaexim/admin/runs
<!-- @generated:start:api.plugins.sirsoft-koreaexim.admin.runs.index -->
- **라우트명**: `api.plugins.sirsoft-koreaexim.admin.runs.index`
- **컨트롤러**: `Plugins\Sirsoft\Koreaexim\Http\Controllers\Admin\RateRunController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-koreaexim.exchange_rate.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| status | query | string | 아니오 | — | 상태 필터 (해당 상태의 항목만 조회) |
| trigger | query | string | 아니오 | — | 실행 계기: scheduled, retry, manual, dry_run, currency_changed |
| date_from | query | date | 아니오 | Y-m-d | 조회 기간 시작일 |
| date_to | query | date | 아니오 | Y-m-d, date_from 이상 | 조회 기간 종료일 (date_from 과 함께 오면 그보다 앞설 수 없다) |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | between 1,100 | 페이지당 항목 수 |

**요청 예시**

```http
GET /api/plugins/sirsoft-koreaexim/admin/runs?status=success&trigger=manual&date_from=2026-01-01&date_to=2026-01-01&page=1&per_page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `3` | 기본 키 (내부 식별자) |
| trigger | string | `manual` | 실행 계기: scheduled, retry, manual, dry_run, currency_changed |
| trigger_label | string | `수동` | `trigger` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| status | string | `success` | 상태 값 (도메인별 상태 집합 — 사람이 읽는 라벨은 status_label, UI 변형은 status_variant 참조) |
| status_label | string | `성공` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| search_date | string | `2026-09-14` | 조회한 고시 기준일(한국시간 날짜) |
| rate_type | string | `deal_bas_r` | 적용 환율 종류: deal_bas_r, ttb, tts |
| base_currency | string | `KRW` | 실행 당시 사이트 기본 통화 코드 |
| base_unit | integer | `1000` | 실행 당시 기본 통화의 기준 수량 |
| request_count | integer | `1` | request 개수 (집계) |
| applied_count | integer | `4` | applied 개수 (집계) |
| unchanged_count | integer | `0` | unchanged 개수 (집계) |
| skipped_count | integer | `0` | skipped 개수 (집계) |
| message | string | `성공` | 키를 제거한 처리 결과 또는 오류 설명 |
| source_run_id | null | `null` | source run 식별자 (연관 리소스 참조) |
| started_at | string | `2026-09-14 14:02:14` | started 일시 |
| finished_at | string | `2026-09-14 14:02:15` | finished 일시 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "data": [
            {
                "id": 3,
                "trigger": "manual",
                "trigger_label": "수동",
                "status": "success",
                "status_label": "성공",
                "search_date": "2026-09-14",
                "rate_type": "deal_bas_r",
                "base_currency": "KRW",
                "base_unit": 1000,
                "request_count": 1,
                "applied_count": 4,
                "unchanged_count": 0,
                "skipped_count": 0,
                "message": "성공",
                "source_run_id": null,
                "started_at": "2026-09-14 14:02:14",
                "finished_at": "2026-09-14 14:02:15"
            },
            {
                "id": 2,
                "trigger": "dry_run",
                "trigger_label": "연결 테스트",
                "status": "dry_run",
                "status_label": "연결 테스트",
                "search_date": "2026-09-14",
                "rate_type": "deal_bas_r",
                "base_currency": "KRW",
                "base_unit": 1000,
                "request_count": 1,
                "applied_count": 0,
                "unchanged_count": 0,
                "skipped_count": 0,
                "message": "연결 테스트",
                "source_run_id": null,
                "started_at": "2026-09-14 13:24:15",
                "finished_at": "2026-09-14 13:24:16"
            },
            "... (총 3건 중 2건 표시)"
        ],
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 25,
            "total": 3,
            "has_more_pages": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-koreaexim.exchange_rate.view`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지). 메시지의 항목 이름은 요청 언어의 표시 이름(예: `date_to` → 「종료일」)으로 나간다 — 종료일이 시작일보다 앞서면 `date_to` 에 「종료일 필드는 시작일 이후 또는 같은 날짜여야 합니다.」 |

<!-- @generated:end -->

**설명** 한국시간 조회 기준일·실행 계기·상태로 실행 이력을 필터링합니다. 목록에는 raw_response와 통화별 스냅샷을 싣지 않습니다. 일시는 사용자 시간대로 표시합니다.


### POST /api/plugins/sirsoft-koreaexim/admin/runs
<!-- @generated:start:api.plugins.sirsoft-koreaexim.admin.runs.store -->
- **라우트명**: `api.plugins.sirsoft-koreaexim.admin.runs.store`
- **컨트롤러**: `Plugins\Sirsoft\Koreaexim\Http\Controllers\Admin\RateRunController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-koreaexim.exchange_rate.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| dry_run | body | boolean | 아니오 | — | true이면 연결·계산·이력 기록만 수행하고 통화 설정에 적용하지 않음 |

**요청 예시**

```http
POST /api/plugins/sirsoft-koreaexim/admin/runs HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "dry_run": true
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `5` | 기본 키 (내부 식별자) |
| trigger | string | `dry_run` | 실행 계기: scheduled, retry, manual, dry_run, currency_changed |
| trigger_label | string | `연결 테스트` | `trigger` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| status | string | `dry_run` | 상태 값 (도메인별 상태 집합 — 사람이 읽는 라벨은 status_label, UI 변형은 status_variant 참조) |
| status_label | string | `연결 테스트` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| search_date | string | `2026-09-14` | 조회한 고시 기준일(한국시간 날짜) |
| rate_type | string | `deal_bas_r` | 적용 환율 종류: deal_bas_r, ttb, tts |
| base_currency | string | `KRW` | 실행 당시 사이트 기본 통화 코드 |
| base_unit | integer | `1000` | 실행 당시 기본 통화의 기준 수량 |
| request_count | integer | `1` | request 개수 (집계) |
| applied_count | integer | `0` | applied 개수 (집계) |
| unchanged_count | integer | `4` | unchanged 개수 (집계) |
| skipped_count | integer | `0` | skipped 개수 (집계) |
| message | string | `연결 테스트` | 키를 제거한 처리 결과 또는 오류 설명 |
| source_run_id | null | `null` | source run 식별자 (연관 리소스 참조) |
| started_at | string | `2026-09-14 14:35:53` | started 일시 |
| finished_at | string | `2026-09-14 14:35:54` | finished 일시 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "id": 5,
        "trigger": "dry_run",
        "trigger_label": "연결 테스트",
        "status": "dry_run",
        "status_label": "연결 테스트",
        "search_date": "2026-09-14",
        "rate_type": "deal_bas_r",
        "base_currency": "KRW",
        "base_unit": 1000,
        "request_count": 1,
        "applied_count": 0,
        "unchanged_count": 4,
        "skipped_count": 0,
        "message": "연결 테스트",
        "source_run_id": null,
        "started_at": "2026-09-14 14:35:53",
        "finished_at": "2026-09-14 14:35:54"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-koreaexim.exchange_rate.manage`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 수동 갱신 또는 연결 테스트를 요청합니다. 관리 권한이 필요하며 60초 쿨다운·하루 수동 20회·외부 호출 총량 1000회와 공통 실행 잠금이 적용됩니다. 연결 테스트는 설정을 변경하지 않으며 실패 알림을 보내지 않습니다. 실제 갱신은 자동 관리와 제공자 선택이 활성일 때만 허용합니다. 잠금·속도 제한은 429, 입력 오류는 422로 응답합니다.


### GET /api/plugins/sirsoft-koreaexim/admin/runs/{run}
<!-- @generated:start:api.plugins.sirsoft-koreaexim.admin.runs.show -->
- **라우트명**: `api.plugins.sirsoft-koreaexim.admin.runs.show`
- **컨트롤러**: `Plugins\Sirsoft\Koreaexim\Http\Controllers\Admin\RateRunController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-koreaexim.exchange_rate.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| run | path | string | 예 | — | 대상 run의 식별자 |

**요청 예시**

```http
GET /api/plugins/sirsoft-koreaexim/admin/runs/{run} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `3` | 기본 키 (내부 식별자) |
| trigger | string | `manual` | 실행 계기: scheduled, retry, manual, dry_run, currency_changed |
| trigger_label | string | `수동` | `trigger` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| status | string | `success` | 상태 값 (도메인별 상태 집합 — 사람이 읽는 라벨은 status_label, UI 변형은 status_variant 참조) |
| status_label | string | `성공` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| search_date | string | `2026-09-14` | 조회한 고시 기준일(한국시간 날짜) |
| rate_type | string | `deal_bas_r` | 적용 환율 종류: deal_bas_r, ttb, tts |
| base_currency | string | `KRW` | 실행 당시 사이트 기본 통화 코드 |
| base_unit | integer | `1000` | 실행 당시 기본 통화의 기준 수량 |
| request_count | integer | `1` | request 개수 (집계) |
| applied_count | integer | `4` | applied 개수 (집계) |
| unchanged_count | integer | `0` | unchanged 개수 (집계) |
| skipped_count | integer | `0` | skipped 개수 (집계) |
| message | string | `성공` | 키를 제거한 처리 결과 또는 오류 설명 |
| source_run_id | null | `null` | source run 식별자 (연관 리소스 참조) |
| started_at | string | `2026-09-14 14:02:14` | started 일시 |
| finished_at | string | `2026-09-14 14:02:15` | finished 일시 |
| items | array | `[]` | 통화별 실행 스냅샷 목록 |
| items[].id | integer | `1` | 항목 식별자 |
| items[].currency_code | string | `USD` | 사이트 통화 코드 |
| items[].api_currency_unit | string | `USD` | 외부 응답 통화 단위 |
| items[].unit_divisor | number | `1` | 100단위 고시의 정규화 나눗수 |
| items[].source_rate | number/null | `1346.4` | 선택한 외부 환율 |
| items[].previous_rate | number/null | `0.7` | 적용 전 사이트 환율 |
| items[].new_rate | number/null | `0.742721` | 계산된 환율 |
| items[].status | string | `applied` | applied, unchanged, base_currency, unsupported, invalid_rate |
| items[].status_label | string | `적용` | 현지화된 항목 상태 |
| items[].currency_snapshot | object | `{}` | 당시 통화 설정 스냅샷 |
| items[].api_fields | object | `{}` | 비밀값을 제외한 고시 응답 필드 |

**응답 예시**

다음은 Resource 계약에 따른 구성 예시입니다.

```json
{
  "success": true,
  "message": "성공적으로 처리되었습니다.",
  "data": {
    "id": 1,
    "trigger": "manual",
    "trigger_label": "수동",
    "status": "success",
    "status_label": "성공",
    "search_date": "2026-09-14",
    "rate_type": "deal_bas_r",
    "base_currency": "KRW",
    "base_unit": 1000,
    "request_count": 1,
    "applied_count": 1,
    "unchanged_count": 0,
    "skipped_count": 0,
    "message": "성공",
    "source_run_id": null,
    "started_at": "2026-09-14 11:30:00",
    "finished_at": "2026-09-14 11:30:01",
    "items": [
      {
        "id": 1,
        "currency_code": "USD",
        "api_currency_unit": "USD",
        "unit_divisor": 1,
        "source_rate": 1346.4,
        "previous_rate": 0.7,
        "new_rate": 0.742721,
        "status": "applied",
        "status_label": "적용",
        "currency_snapshot": {
          "name": "USD (달러)",
          "symbol": "$",
          "base_unit": 1,
          "decimal_places": 2
        },
        "api_fields": {
          "cur_unit": "USD",
          "deal_bas_r": "1,346.4"
        }
      }
    ]
  }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-koreaexim.exchange_rate.view`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 실행 1건과 해당 시점의 통화별 계산 스냅샷을 반환합니다. 원본 응답 전체와 인증키는 공개하지 않습니다. 삭제된 실행은 404입니다.
