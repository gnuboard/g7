# Status API 레퍼런스

> **소유**: plugin `sirsoft-koreaexim` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Status 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-koreaexim/admin/status
<!-- @generated:start:api.plugins.sirsoft-koreaexim.admin.status -->
- **라우트명**: `api.plugins.sirsoft-koreaexim.admin.status`
- **컨트롤러**: `Plugins\Sirsoft\Koreaexim\Http\Controllers\Admin\RateRunController@status`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-koreaexim.exchange_rate.view`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-koreaexim/admin/status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| managed_codes | array | `["USD","JPY","CNY","EUR"]` | 활성 제공자가 관리하는 통화 코드 목록 |
| unsupported_codes | array | `[]` | 응답에 없거나 유효한 환율을 계산할 수 없는 통화 코드 목록 |
| notice_key | null | `null` | 자동 관리 비활성 사유 번역 키; 정상 관리 시 null |
| last_updated_at | string | `2026-09-14T14:02:15+09:00` | last updated 일시 |
| last_updated_at_formatted | string | `2026-09-14 14:02:15` | `last_updated_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| auto_manage | boolean | `true` | 자동 관리 설정 활성 여부 |
| auth_key_set | boolean | `true` | 인증키가 실제 설정되어 있는지 여부; 키 자체는 반환하지 않음 |
| base_currency | string | `KRW` | 실행 당시 사이트 기본 통화 코드 |
| last_success_run | object | `{"id":3,"trigger":"manual","trigger_label":"수동","status":…` | 최근 성공 또는 일부 적용 실행 요약; 없으면 null |
| last_run | object | `{"id":3,"trigger":"manual","trigger_label":"수동","status":…` | 최근 실행 요약; 없으면 null |
| today_request_count | integer | `4` | today request 개수 (집계) |
| quota_limit | integer | `1000` | 한국시간 하루 외부 요청 예산(1000회) |
| next_run_at_kst | string | `2026-09-15 11:30 KST` | 다음 정기 실행 예정 시각(한국시간, 없으면 null) |
| next_run_at | string | `2026-09-15 11:30` | next run 일시 |
| update_time_site | string | `11:30` | 오늘의 한국시간 갱신 시각을 사이트 시간대로 변환한 HH:mm 문자열 |
| scheduler_hint | string | `서버 스케줄러가 필요합니다. 미가동 환경에서는 지금 갱신을 사용하세요.` | 서버 스케줄러 가동이 필요함을 알리는 운영 안내 |
| abilities | object | `{"can_manage":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

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
        "managed_codes": [
            "USD",
            "JPY",
            "CNY",
            "EUR"
        ],
        "unsupported_codes": [],
        "notice_key": null,
        "last_updated_at": "2026-09-14T14:02:15+09:00",
        "last_updated_at_formatted": "2026-09-14 14:02:15",
        "auto_manage": true,
        "auth_key_set": true,
        "base_currency": "KRW",
        "last_success_run": {
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
        "last_run": {
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
        "today_request_count": 4,
        "quota_limit": 1000,
        "next_run_at_kst": "2026-09-15 11:30 KST",
        "next_run_at": "2026-09-15 11:30",
        "scheduler_hint": "서버 스케줄러가 필요합니다. 미가동 환경에서는 지금 갱신을 사용하세요.",
        "abilities": {
            "can_manage": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-koreaexim.exchange_rate.view`)이 없는 경우 |

<!-- @generated:end -->

**설명** 관리 통화·API 요청 예산 사용량·최근 실행·다음 정기 실행·관리 권한을 조회합니다. 다음 실행은 스케줄러 등록 예정이며 서버 cron의 실제 가동을 보증하지 않습니다. 인증키 값은 반환하지 않습니다.
