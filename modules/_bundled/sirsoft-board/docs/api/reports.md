# Reports API 레퍼런스

> **소유**: module `sirsoft-board` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Reports 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-board/admin/reports
<!-- @generated:start:api.modules.sirsoft-board.admin.reports.index -->
- **라우트명**: `api.modules.sirsoft-board.admin.reports.index`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\ReportController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.reports.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| filters | query | array | 아니오 | — | 추가 필터 조건 맵 (필드별 조건) |
| status | query | string | 아니오 | — | 상태 필터 (해당 상태의 항목만 조회) |
| target_type | query | string | 아니오 | — | 신고 대상 타입 필터 (`post`/`comment`). 단일 문자열 또는 배열로 여러 타입을 전달할 수 있습니다. |
| target_status | query | string | 아니오 | — | 신고 대상 콘텐츠의 현재 상태 필터 (게시글/댓글의 status 기준). 단일 문자열 또는 배열로 전달하며 `all`은 무시됩니다. |
| board_id | query | integer | 아니오 | — | board 식별자 |
| reported_at_from | query | string | 아니오 | — | 신고 접수 기간 시작일 (`YYYY-MM-DD`). 마지막 신고일시(`last_reported_at`) 기준으로 이 날짜 00:00:00 이후 건만 조회합니다. |
| reported_at_to | query | string | 아니오 | — | 신고 접수 기간 종료일 (`YYYY-MM-DD`). 마지막 신고일시(`last_reported_at`) 기준으로 이 날짜 23:59:59 이전 건만 조회합니다. |
| sort_by | query | string | 아니오 | — | 정렬 기준 필드명 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 (asc 오름차순 / desc 내림차순) |
| per_page | query | integer | 아니오 | — | 페이지당 항목 수 |

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/reports?filters=%EC%98%88%EC%8B%9C%EA%B0%92&status=%EC%98%88%EC%8B%9C%EA%B0%92&target_type=%EC%98%88%EC%8B%9C%EA%B0%92&target_status=%EC%98%88%EC%8B%9C%EA%B0%92&board_id=1&reported_at_from=%EC%98%88%EC%8B%9C%EA%B0%92&reported_at_to=%EC%98%88%EC%8B%9C%EA%B0%92&sort_by=%EC%98%88%EC%8B%9C%EA%B0%92&sort_order=asc&per_page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `7` | 기본 키 (내부 식별자) |
| board_id | integer | `6` | 게시판 ID (게시판 삭제 시 NULL) |
| board | object | `{"id":6,"name":"Q&A","slug":"qna","title":"파일 업로드 오류","cu…` | 신고 대상이 속한 게시판 정보 (id/name/slug/대상 제목/현재 상태). 게시판 삭제 시 관계 대신 첫 신고 로그의 스냅샷 값으로 폴백합니다. |
| target_type | string | `comment` | 신고 대상 타입 (post, comment) |
| target_type_label | string | `댓글` | `target_type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| target_id | integer | `282` | 신고 대상 ID (동적 테이블의 ID) |
| post_id | integer | `99` | post 식별자 (연관 리소스 참조) |
| content | null | `null` | 본문 내용 |
| content_mode | string | `text` | 대상 본문의 형식 모드 (`text`/`html` 등). 목록에서는 미리보기 표시 방식을 결정하며 스냅샷이 없으면 `text`로 기본 설정됩니다. |
| content_preview | string | `저도 비슷한 경험이 있어요.` | 대상 본문의 미리보기 (앞 100자로 잘린 발췌). 목록 응답에서만 채워지고 상세 응답에서는 null 입니다. |
| author | object | `{"uuid":"a1e0a91a-fba6-491c-a53e-7285a5686857","name":"관리…` | 작성자 사용자 객체 (uuid/name — author 관계 파생) |
| reporter | object | `{"uuid":"a1e0a91a-fba6-491c-a53e-7285a5686857","name":"관리…` | 대표 신고자 정보 (uuid/name/email/is_guest). 첫 번째 신고 로그에서 추출하며, 비회원 신고 시 게스트로 표시됩니다. |
| reason_type | string | `abuse` | 대표 신고 사유 코드 (첫 번째 신고 로그의 사유 Enum 값 — 예: abuse, spam). |
| reason_type_label | string | `욕설/비방` | `reason_type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| status | string | `review` | 신고 상태 (pending, review, rejected, suspended) |
| status_label | string | `검토` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| status_variant | string | `info` | 상태 표시 색상/스타일 변형 키 (상태 Enum variant() 산물 — UI 배지용) |
| processor | object | `{"uuid":"a1e0a91a-fba6-491c-a53e-7285a5686857","name":"관리자"}` | 신고를 처리한 관리자 정보 (uuid/name). 처리자가 지정되지 않은 경우 null 입니다. |
| processed_at | string | `2026-06-01 09:35:42` | processed 일시 |
| metadata | null | `null` | 메타데이터 (IP, User Agent 등) |
| report_count | integer | `1` | report 개수 (집계) |
| last_reported_at | string | `2026-06-02 09:35:42` | last reported 일시 |
| is_reactivated | boolean | `false` | reactivated 여부 |
| target_status | string | `published` | 신고 대상 콘텐츠(게시글/댓글)의 현재 상태. 대상 테이블의 status 컬럼을 서브쿼리로 조인한 값입니다 (예: published, blinded). |
| target_trigger_type | string | `admin` | 신고 대상의 현재 상태를 유발한 트리거 유형. 대상 테이블의 trigger_type 값으로, 블라인드 처리가 자동(auto_hide)/관리자 수동(admin) 중 무엇에 의한 것인지 구분합니다. |
| target_status_label | string | `게시중` | `target_status` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| created_at | string | `2026-06-04 09:35:42` | 생성 일시 |
| updated_at | string | `2026-06-04 09:35:42` | 최종 수정 일시 |
| is_owner | boolean | `false` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_view":true,"can_manage":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "신고 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 1,
                "board_id": 1,
                "board": {
                    "id": 1,
                    "name": "API 문서 샘플 게시판",
                    "slug": "apidoc-sample-board",
                    "title": null,
                    "current_status": null,
                    "deleted_at": null
                },
                "target_type": "post",
                "target_type_label": "게시글",
                "target_id": 1,
                "post_id": 1,
                "content": null,
                "content_mode": "text",
                "content_preview": "",
                "author": {
                    "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
                    "name": "API 문서 샘플 사용자",
                    "email": "apidoc-sample-user@example.com",
                    "is_guest": false
                },
                "reporter": null,
                "reason_type": null,
                "reason_type_label": null,
                "status": "pending",
                "status_label": "접수",
                "status_variant": "warning",
                "processor": null,
                "processed_at": null,
                "metadata": null,
                "report_count": 0,
                "last_reported_at": "2026-07-08 10:41:34",
                "is_reactivated": false,
                "target_status": "published",
                "target_trigger_type": "user",
                "target_status_label": "게시중",
                "created_at": "2026-07-08 10:41:34",
                "updated_at": "2026-07-08 10:41:34",
                "is_owner": false,
                "abilities": {
                    "can_view": true,
                    "can_manage": true
                }
            }
        ],
        "statistics": {
            "by_status": {
                "pending": 1
            },
            "by_type": {
                "post": 1
            },
            "total": 1
        },
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 20,
            "total": 1,
            "from": 1,
            "to": 1,
            "has_more_pages": false
        },
        "abilities": {
            "can_view": true,
            "can_manage": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.reports.view`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 신고 관리 화면의 목록을 조회합니다. `auth:sanctum` 인증과 `sirsoft-board.reports.view` 권한이 필요합니다. 동일 대상(게시글/댓글)에 대한 여러 신고는 그룹화되어 최초 신고 1건만 목록에 노출되며, `filters`/`status`/`target_type`/`target_status`/`board_id`/기간(`reported_at_from`~`reported_at_to`)으로 필터링하고 `sort_by`/`sort_order`로 정렬합니다. `per_page`는 10~20 범위로 강제 제한되며, 응답에는 상태별 통계와 사용자 권한 정보가 함께 포함됩니다.


### PATCH /api/modules/sirsoft-board/admin/reports/bulk-status
<!-- @generated:start:api.modules.sirsoft-board.admin.reports.bulk-status -->
- **라우트명**: `api.modules.sirsoft-board.admin.reports.bulk-status`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\ReportController@bulkUpdateStatus`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.reports.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | min 1 | 대상 리소스 식별자 배열 (대량 작업 대상) |
| status | body | string | 예 | — | 일괄 전환할 신고 상태 (`ReportStatus` 허용값: pending/review/rejected/suspended 등). 지정한 모든 신고를 이 상태로 변경합니다. |
| process_note | body | string | 아니오 | max 1000 | 처리 메모 (최대 1000자). 상태 변경 사유나 조치 내용을 처리 이력에 함께 기록합니다. |

**요청 예시**

```http
PATCH /api/modules/sirsoft-board/admin/reports/bulk-status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "ids": [
        "예시값"
    ],
    "status": "예시값",
    "process_note": "예시값"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.reports.manage`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 여러 신고의 상태를 한 번에 변경합니다. `auth:sanctum` 인증과 `sirsoft-board.reports.manage` 권한이 필요합니다. `ids`로 지정한 신고들만 대상으로 하며(그룹 확장 없음) `status`로 지정한 상태로 일괄 전환하고, 선택적으로 `process_note`(최대 1000자)를 처리 메모로 남깁니다. 응답은 실제 변경된 건수(`affected_count`), 대상 콘텐츠 복구 건수(`restored_count`), 수동 블라인드 복구 건수(`manual_blind_restored`)와 안내 메시지를 반환합니다.


### POST /api/modules/sirsoft-board/admin/reports/status-counts
<!-- @generated:start:api.modules.sirsoft-board.admin.reports.status-counts -->
- **라우트명**: `api.modules.sirsoft-board.admin.reports.status-counts`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\ReportController@getStatusCounts`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.reports.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | min 1 | 대상 리소스 식별자 배열 (대량 작업 대상) |
| target_status | body | string | 아니오 | — | 집계 기준이 되는 전환 대상 상태 (`ReportStatus` 허용값). 지정 시 해당 상태로의 일괄 전환을 가정한 상태별 건수 요약을 계산합니다. |

**요청 예시**

```http
POST /api/modules/sirsoft-board/admin/reports/status-counts HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "ids": [
        "예시값"
    ],
    "target_status": "예시값"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.reports.view`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 선택된 신고들의 상태별 건수를 집계하여 반환합니다. `auth:sanctum` 인증과 `sirsoft-board.reports.view` 권한이 필요합니다. 대량 상태 변경을 실행하기 전에 사용자에게 선택한 신고들의 상태 분포를 미리 보여주기 위한 조회용 API로, `ids` 배열(최소 1개)과 선택적 `target_status`를 받아 상태별 건수와 요약 정보를 계산합니다.


### DELETE /api/modules/sirsoft-board/admin/reports/{report}
<!-- @generated:start:api.modules.sirsoft-board.admin.reports.destroy -->
- **라우트명**: `api.modules.sirsoft-board.admin.reports.destroy`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\ReportController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.reports.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| report | path | string | 예 | — | 대상 report의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-board/admin/reports/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)



<!-- 실측 응답에 필드 없음(빈 목록 등) — 데이터가 있는 상태로 재실측하거나 사람이 작성. -->

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "신고가 삭제되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.reports.manage`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 신고를 삭제합니다(소프트 삭제). `auth:sanctum` 인증과 `sirsoft-board.reports.manage` 권한이 필요합니다. 경로의 `report`(신고 ID)에 해당하는 신고 케이스를 소프트 삭제하며, 존재하지 않으면 404, 스코프 권한 위반 시 403을 반환합니다.


### GET /api/modules/sirsoft-board/admin/reports/{report}
<!-- @generated:start:api.modules.sirsoft-board.admin.reports.show -->
- **라우트명**: `api.modules.sirsoft-board.admin.reports.show`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\ReportController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.reports.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| report | path | string | 예 | — | 대상 report의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/reports/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| board_id | integer | `4` | 게시판 ID (게시판 삭제 시 NULL) |
| board | object | `{"id":4,"name":"갤러리","slug":"gallery"}` | 신고 대상이 속한 게시판 정보 (id/name/slug). 게시판이 삭제된 경우 관계 대신 첫 신고 로그 스냅샷의 게시판명으로 폴백합니다. |
| target_type | string | `post` | 신고 대상 타입 (post, comment) |
| target_id | integer | `55` | 신고 대상 ID (동적 테이블의 ID) |
| post | object | `{"id":55,"title":"작업물 공유합니다","content":"최근에 작업한 결과물입니다.피드…` | 신고 대상 게시글 상세 (id/title/content/작성일시/작성자). 대상이 댓글이면 해당 댓글의 상위 게시글 정보가 담기며, 조회 불가 시 null 입니다. |
| comment | null | `null` | 신고 대상 댓글 상세 (id/content/작성일시/작성자). 대상 타입이 comment 일 때만 채워지고 게시글 신고에서는 null 입니다. |
| target_status | string | `published` | 신고 대상 콘텐츠의 현재 상태 (reportable의 current_status — 예: published, blinded). |
| target_status_label | string | `게시중` | `target_status` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| blind_trigger_type | string | `admin` | 대상 콘텐츠의 블라인드/상태 변경을 유발한 트리거 유형 (reportable의 trigger_type — 예: 자동/관리자 수동). |
| blind_trigger_type_label | string | `관리자 수동` | `blind_trigger_type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| status | string | `pending` | 신고 상태 (pending, review, rejected, suspended) |
| available_actions | array | `["review","rejected","suspended","deleted"]` | 현재 상태에서 전환 가능한 다음 신고 상태 목록 (상태 Enum의 getAvailableTransitions() 산물). 상태 변경 UI의 선택지로 사용됩니다. |
| abilities | object | `{"can_view":true,"can_manage":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |
| reporters | array | `[{"id":1,"reporter":{"uuid":"a1e0a91a-fba6-491c-a53e-7285…` | 이 케이스에 접수된 개별 신고 로그 목록. 각 항목은 신고자(reporter)·사유(reason_type/reason_detail)·신고 시점 스냅샷(snapshot)·신고 일시를 포함합니다. |
| report_count | integer | `1` | report 개수 (집계) |
| reason_summary | string | `욕설/비방 1건` | 신고 사유 요약 문자열. 상위 2개 사유를 "사유 N건" 형식으로 나열하고 나머지는 "외 N건"으로 합산해 표시합니다. |
| first_reported_at | string | `2026-06-04 09:35:42` | first reported 일시 |
| last_reported_at | string | `2026-05-13 09:35:42` | last reported 일시 |
| histories | array | `[{"id":1,"type":"reported","action_label":"신고 접수","proces…` | 신고 처리 이력 타임라인 (process_histories JSON 기반, 최신순). 각 항목은 이벤트 유형·라벨·처리자·사유·신고자 수·발생 일시를 포함하며, 접수 이벤트에는 처리자가 없습니다. |
| metadata | object | `{"ip":"127.0.0.1","user_agent":"Mozilla\/5.0 (Windows NT …` | 메타데이터 (IP, User Agent 등) |
| created_at | string | `2026-06-04 09:35:42` | 생성 일시 |
| updated_at | string | `2026-06-04 09:35:42` | 최종 수정 일시 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "신고 목록을 조회했습니다.",
    "data": {
        "id": 1,
        "board_id": 1,
        "board": {
            "id": 1,
            "name": "API 문서 샘플 게시판",
            "slug": "apidoc-sample-board"
        },
        "target_type": "post",
        "target_id": 1,
        "post": {
            "id": 1,
            "title": "API 문서 샘플 게시글",
            "content": "<p>API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.</p>",
            "content_mode": "html",
            "created_at": "2026-07-08 10:41:34",
            "author": {
                "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
                "name": "API 문서 샘플 사용자",
                "email": "apidoc-sample-user@example.com",
                "is_guest": false
            }
        },
        "comment": null,
        "target_status": "published",
        "target_status_label": "게시중",
        "blind_trigger_type": "user",
        "blind_trigger_type_label": "사용자 직접 삭제",
        "status": "pending",
        "available_actions": [
            "review",
            "rejected",
            "suspended",
            "deleted"
        ],
        "abilities": {
            "can_view": true,
            "can_manage": true
        },
        "reporters": [],
        "report_count": 0,
        "reason_summary": "-",
        "first_reported_at": "2026-07-08 10:41:34",
        "last_reported_at": "2026-07-08 10:41:34",
        "histories": [],
        "metadata": {
            "reason": "spam"
        },
        "created_at": "2026-07-08 10:41:34",
        "updated_at": "2026-07-08 10:41:34"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.reports.view`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 신고 케이스 1건의 상세 정보를 조회합니다. `auth:sanctum` 인증과 `sirsoft-board.reports.view` 권한이 필요합니다. 경로의 `report`(신고 ID)를 기준으로 동일 대상에 대한 모든 신고를 그룹화한 상세 정보와 신고 대상 콘텐츠(reportable) 데이터를 함께 반환하며, 처리 이력(histories), 신고자 목록(reporters), 사유 요약(reason_summary), 전환 가능한 상태(available_actions) 등을 포함합니다. 대상이 없으면 404, 스코프 권한 위반 시 403을 반환합니다.


### GET /api/modules/sirsoft-board/admin/reports/{report}/reporters
<!-- @generated:start:api.modules.sirsoft-board.admin.reports.reporters -->
- **라우트명**: `api.modules.sirsoft-board.admin.reports.reporters`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\ReportController@reporters`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.reports.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| report | path | string | 예 | — | 대상 report의 식별자 |
| per_page | query | integer | 아니오 | min 1 | 페이지당 항목 수 |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/reports/1/reporters?per_page=1&page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| reporter | object | `{"uuid":"a1e0a91a-fba6-491c-a53e-7285a5686857","name":"관리…` | 개별 신고자 정보 (uuid/name/email). 비회원(게스트) 신고 로그인 경우 null 입니다. |
| reason_type | string | `abuse` | 신고 사유 코드 (사유 Enum 값 — 예: abuse, spam). |
| reason_type_label | string | `욕설/비방` | `reason_type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| reason_detail | string | `욕설과 비방이 포함된 게시글입니다. 다른 사용자를 모욕하는 내용이 …` | 신고자가 직접 입력한 상세 사유 텍스트. 입력하지 않은 경우 null 입니다. |
| snapshot | object | `{"board_name":"갤러리","title":"작업물 공유합니다","content":"최근에 작업…` | 신고 접수 시점의 대상 콘텐츠 스냅샷 (게시판명/제목/본문/작성자 등). 이후 대상이 수정·삭제되어도 신고 당시 내용을 보존합니다. |
| reported_at | string | `2026-06-04 09:35:42` | reported 일시 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "신고 목록을 조회했습니다.",
    "data": {
        "data": [],
        "pagination": {
            "total": 0,
            "from": 0,
            "to": 0,
            "per_page": 25,
            "current_page": 1,
            "last_page": 1
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.reports.view`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 신고 케이스에 접수된 개별 신고자 목록을 페이지네이션으로 반환합니다. `auth:sanctum` 인증과 `sirsoft-board.reports.view` 권한이 필요합니다. 경로의 `report`(신고 케이스 ID)에 대해 각 신고자의 신고 사유(reason_type/reason_detail)와 신고 시점 스냅샷(snapshot)을 포함한 항목을 반환하며, `per_page`(최대 50)와 `page`로 페이지를 제어합니다. 대상이 없으면 404를 반환합니다.


### PATCH /api/modules/sirsoft-board/admin/reports/{report}/status
<!-- @generated:start:api.modules.sirsoft-board.admin.reports.update-status -->
- **라우트명**: `api.modules.sirsoft-board.admin.reports.update-status`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\ReportController@updateStatus`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.reports.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| report | path | string | 예 | — | 대상 report의 식별자 |
| status | body | string | 예 | — | 전환할 신고 상태 (`ReportStatus` 허용값). 현재 상태에서 전환 불가한 값(영구삭제 등)은 검증에서 422로 차단됩니다. |
| process_note | body | string | 아니오 | max 1000 | 처리 메모 (최대 1000자). 상태 변경 사유나 조치 내용을 처리 이력에 함께 기록합니다. |

**요청 예시**

```http
PATCH /api/modules/sirsoft-board/admin/reports/1/status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "status": "예시값",
    "process_note": "예시값"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.reports.manage`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 단건 신고의 상태를 변경합니다. `auth:sanctum` 인증과 `sirsoft-board.reports.manage` 권한이 필요합니다. 경로의 `report`(신고 ID)에 대해 `status`로 지정한 상태로 전환하고 선택적으로 `process_note`(최대 1000자)를 처리 메모로 남깁니다. 영구삭제(deleted) 등 전환 불가 상태는 FormRequest 검증에서 422로 선차단되고 서비스의 deleted 가드가 2차 방어선으로 동작하며, 대상이 없으면 404를 반환합니다.


