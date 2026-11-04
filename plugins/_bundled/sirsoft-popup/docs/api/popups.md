# Popups API 레퍼런스

> **소유**: plugin `sirsoft-popup` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 팝업 관리(관리자 11개)와 사용자 화면 노출(공개 1개) 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 응답 필드 표 + 응답 예시(envelope)
3. 상태(status)는 저장값이 아니라 조회 시각에 사용 여부·기간으로 판정합니다 — 비활성 > 예약 > 종료 > 노출 중
4. 수정은 세대 번호(revision)가 지금과 다르면 409 — 다른 관리자의 저장을 덮지 않습니다
5. 갱신: 코드 변경 후 php artisan api:docgen 재실행
```

공통 사항:

- 관리자 API 는 모두 `auth:sanctum` + 관리자(`admin`) 미들웨어 뒤에 있고, 권한은 조회(`sirsoft-popup.popups.read`) · 등록·수정(`sirsoft-popup.popups.update`) · 삭제(`sirsoft-popup.popups.delete`) 셋입니다. 관리자가 아니면 403 `관리자 권한이 필요합니다.`, 관리자이지만 권한이 없으면 403 `해당 권한이 없습니다.` 입니다.
- 기간 입력(`starts_at`·`ends_at`)은 사이트 시간대의 `YYYY-MM-DDTHH:mm`(datetime-local) 로 받아 UTC 로 저장하고, 응답의 `*_display`·`*_local` 은 다시 사이트 시간대로 돌려 줍니다.
- 다국어 값(`image_alt`·`body_html`)은 `{언어 코드: 값}` 객체이며 사이트 지원 언어만 받습니다. 비운 언어는 사용자 화면에서 사이트 기본 언어 값으로 대체됩니다. 수정 때는 언어팩을 꺼서 지원 목록에서 빠진 언어라도 이미 저장된 번역이면 받고(보내지 않아도 지우지 않음), 저장돼 있지 않던 지원 밖 언어만 422 입니다.
- 직접 작성형 본문은 정화한 뒤에도 내용이 남아야 합니다 — 허용하지 않는 요소(`iframe`·`script`·`form` 등)만 있는 언어가 있으면 422(`body_html`)입니다. 오류 문구는 그 언어를 화면의 언어 탭과 같은 이름으로 알립니다(예: 「English 본문에 표시할 내용이 없습니다. …」, 이름이 정해지지 않은 언어만 코드).
- 이미지 위 글 상자(`overlays`)는 `{"pc": [상자…], "mobile": [상자…] | null}` 입니다. 상자 한 개는 `id`(영숫자 8자, 기기 안에서 유일)·`x`·`y`(왼쪽 위, 이미지 폭·높이 대비 % 0~100)·`w`(폭 %, 5~100, `x + w ≤ 100`)·`text`(`{언어: 일반 글}`, 언어별 200자, 사이트 기본 언어 필수, 태그는 저장 때 제거)·`size`(글자 크기, 이미지 폭 대비 % 2~20, 0.5 단위)·`color`(`#RRGGBB`)·`bold`·`align`(`left`·`center`·`right`)·`bg`(null 또는 `{color, opacity}` — opacity 는 진하기 % 0~90)·`shadow` 입니다. 기기마다 10개까지, `mobile` 이 null 이면 휴대폰도 PC 배치를 씁니다. 이미지형만 저장하고(직접 작성형이면 null), 모바일 이미지가 없으면 `mobile` 을 null 로 저장합니다. 상자가 하나도 없으면 null 입니다. 수정 때 지원 밖 언어의 저장된 상자 글은 같은 `id` 상자에 보존합니다. 상자 오류는 어느 기기의 몇 번째 상자인지와 칸 이름으로 알립니다(예: `errors["overlays.pc.0.bg.color"]` = 「PC 글 상자 1 바탕 색 필드는 필수입니다.」, `bg` 를 보냈으면 `color`·`opacity` 둘 다 필수).
- 응답 예시의 ID·날짜·해시는 설명용 예시 값입니다(`{hash}` 는 64자 영숫자 이미지 해시 자리).

---


### GET /api/plugins/sirsoft-popup/admin/popups
<!-- @generated:start:api.plugins.sirsoft-popup.admin.popups.index -->
- **라우트명**: `api.plugins.sirsoft-popup.admin.popups.index`
- **컨트롤러**: `Plugins\Sirsoft\Popup\Http\Controllers\Admin\PopupController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-popup.popups.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| status | query | string | 아니오 | `active`, `scheduled`, `ended`, `inactive` | 상태 필터 — 조회 시각 기준으로 판정한 상태가 이 값인 팝업만 |
| device | query | string | 아니오 | `pc`, `mobile` | 노출 기기 필터. 「PC + 모바일」(`all`) 팝업은 어느 값으로 걸러도 함께 나옵니다 |
| search | query | string | 아니오 | max 100 | 검색어 — 관리용 제목(`title`) 부분 일치 |
| sort_by | query | string | 아니오 | `sort_order`, `created_at`, `status` | 정렬 기준(기본 `sort_order`). `status` 는 노출 중 → 예약 → 종료 → 비활성 순서로 묶은 뒤 순서값으로 정렬 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 (기본 asc) |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 (기본 20) |

> 이 엔드포인트는 확장이 목록 조건을 가공할 수 있습니다 (`sirsoft-popup.popup.filter_list_params`).

**요청 예시**

```http
GET /api/plugins/sirsoft-popup/admin/popups?status=active&device=pc&search=%EC%98%88%EC%8B%9C&sort_by=sort_order&sort_order=asc&page=1&per_page=20 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.abilities` + `data.statistics` + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 팝업 ID |
| title | string | `예시 팝업` | 관리용 제목(관리자 화면 전용, 다국어 아님, 최대 100자). 사용자 화면·공개 API 에는 나가지 않습니다 |
| is_active | boolean | `true` | 사용 여부 — 관리자가 켜고 끄는 노출 스위치(기간과 별개) |
| status | string | `active` | 조회 시각 기준 판정 상태: `active` 노출 중 · `scheduled` 예약(시작 전) · `ended` 종료 · `inactive` 비활성. 우선순위는 비활성 > 예약 > 종료 > 노출 중이며 저장하지 않고 매번 계산합니다 |
| sort_order | integer | `0` | 순서값(0부터, 작을수록 앞). 사용자 화면 캐러셀 순서입니다 |
| content_type | string | `image` | 내용 유형: `image` 이미지형 · `html` 직접 작성형 |
| display_period | string | `always` | 노출 기간 방식: `always` 상시 · `range` 기간 지정 |
| starts_at_display | null | `null` | 노출 시작 일시(사이트 시간대, `Y-m-d H:i:s`). 상시 노출이면 null |
| ends_at_display | null | `null` | 노출 종료 일시(사이트 시간대, `Y-m-d H:i:s`). 상시 노출이면 null |
| page_scope | string | `all` | 노출 페이지: `all` 전체 · `routes` 지정 경로만 |
| routes | array | `[]` | 노출 경로 목록(`page_scope` 가 `routes` 일 때만 값이 있고, 그 외에는 빈 배열) |
| audience | string | `all` | 노출 대상: `all` 전체 · `guest` 비회원만 · `member` 회원만 |
| device | string | `all` | 노출 기기: `all` PC + 모바일 · `pc` · `mobile` |
| filled_locales | array | `[]` | 내용이 입력된 언어 코드 목록 — 이미지형은 `image_alt`, 직접 작성형은 `body_html` 기준. 목록의 「유형 · 언어」 표시용 |
| created_at_display | string | `2026-01-15 10:00:00` | 등록 일시(사이트 시간대, `Y-m-d H:i:s`) |
| thumbnail | object | `{"id":1,"hash":"{hash}","device":"pc","url":"/api/plugins/sirsoft-…` | PC 이미지 정보(업로드 응답과 같은 모양 — `id`·`hash`·`url`·`original_filename`·`width`·`height` 등). 직접 작성형이거나 PC 이미지가 없으면 null |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 팝업에 수행 가능한 작업 — `can_update`(등록·수정 권한), `can_delete`(삭제 권한) |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "팝업을 불러왔습니다.",
    "data": {
        "data": [
            {
                "id": 1,
                "title": "예시 팝업",
                "is_active": true,
                "status": "active",
                "sort_order": 0,
                "content_type": "image",
                "display_period": "always",
                "starts_at_display": null,
                "ends_at_display": null,
                "page_scope": "all",
                "routes": [],
                "audience": "all",
                "device": "all",
                "filled_locales": [],
                "created_at_display": "2026-01-15 10:00:00",
                "thumbnail": {
                    "id": 1,
                    "hash": "{hash}",
                    "device": "pc",
                    "url": "/api/plugins/sirsoft-popup/images/{hash}",
                    "download_url": "/api/plugins/sirsoft-popup/images/{hash}",
                    "original_filename": "banner.png",
                    "mime_type": "image/png",
                    "size": 245760,
                    "size_formatted": "240 KB",
                    "width": 480,
                    "height": 520,
                    "order": 0,
                    "is_image": true
                },
                "abilities": {
                    "can_update": true,
                    "can_delete": true
                }
            }
        ],
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true
        },
        "statistics": {
            "total": 1,
            "statuses": {
                "active": 1,
                "scheduled": 0,
                "ended": 0,
                "inactive": 0
            }
        },
        "pagination": {
            "current_page": 1,
            "per_page": 20,
            "from": 1,
            "to": 1,
            "has_more_pages": false,
            "last_page": 1,
            "total": 1
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-popup.popups.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지). 허용값 밖의 `status`·`device`·`sort_by`·`sort_order`, 100 초과 `per_page` 포함 |

<!-- @generated:end -->

**설명**

팝업 관리 목록 화면의 데이터 소스입니다. 권한은 `auth:sanctum` + 관리자 + 팝업 조회 권한(`sirsoft-popup.popups.read`)입니다.

- 상태 필터·정렬은 저장 컬럼이 아니라 조회 시각으로 판정한 상태를 SQL 로 계산합니다(UTC 기준). 그래서 같은 팝업도 시간이 지나면 `scheduled` → `active` → `ended` 로 바뀌어 보입니다.
- 응답에는 목록 행 외에 컬렉션 권한 `abilities`(`can_create`·`can_update` 는 등록·수정 권한, `can_delete` 는 삭제 권한 — 화면 단추 표시 근거)와 상태별 건수 `statistics`(`total`, `statuses.{active|scheduled|ended|inactive}` — 필터와 무관한 전체 집계, 상단 탭 숫자용)가 함께 실립니다.
- 목록 행에는 다국어 원본·링크·기간 입력값을 싣지 않습니다. 수정 화면은 `GET /admin/popups/{popup}` 을 씁니다. 작성자 ID 는 어느 응답에도 싣지 않습니다.


### POST /api/plugins/sirsoft-popup/admin/popups
<!-- @generated:start:api.plugins.sirsoft-popup.admin.popups.store -->
- **라우트명**: `api.plugins.sirsoft-popup.admin.popups.store`
- **컨트롤러**: `Plugins\Sirsoft\Popup\Http\Controllers\Admin\PopupController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-popup.popups.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| title | body | string | 예 | max 100 | 관리용 제목(앞뒤 공백 제거). 관리자 목록에서 팝업을 구별하는 이름이며 다국어가 아닙니다 |
| is_active | body | boolean | 예 | — | 사용 여부 (true 사용 / false 사용 안 함). 꺼 두면 기간과 무관하게 노출되지 않습니다 |
| content_type | body | string | 예 | `image`, `html` | 내용 유형 — `image` 이미지형(이미지·alt·링크) / `html` 직접 작성형(언어별 본문). 쓰지 않는 유형의 필드는 검증에서 빠지고 저장 때 비워집니다 |
| display_period | body | string | 예 | `always`, `range` | 노출 기간 방식 — `always` 상시 / `range` 기간 지정. `always` 면 `starts_at`·`ends_at` 은 무시되고 비워집니다 |
| starts_at | body | date | 예 | — | 노출 시작 일시(사이트 시간대 `YYYY-MM-DDTHH:mm`). `display_period=range` 일 때만 필수·검증 |
| ends_at | body | date | 예 | — | 노출 종료 일시(사이트 시간대 `YYYY-MM-DDTHH:mm`, `starts_at` 이후). `display_period=range` 일 때만 필수·검증 |
| page_scope | body | string | 예 | `all`, `routes` | 노출 페이지 — `all` 전체 / `routes` 지정 경로만. `all` 이면 `routes` 는 무시되고 비워집니다 |
| routes | body | array | 예 | min 1, max 20 | 노출 경로 목록(`page_scope=routes` 일 때만 필수). 각 경로는 `/` 로 시작, 200자 이하, `//`·`..` 구간·`?`·`#`·`*`·공백 불가. 끝 `/` 하나는 떼고 중복은 합칩니다. 경로가 같거나 그 하위 경로(`/shop` → `/shop/1`)면 노출되며, `/` 는 첫 화면만 뜻합니다 |
| audience | body | string | 예 | `all`, `guest`, `member` | 노출 대상 — 전체 / 비회원만 / 회원만 |
| device | body | string | 예 | `all`, `pc`, `mobile` | 노출 기기 — PC + 모바일 / PC 만 / 모바일만 |
| image_alt | body | array | 아니오 | — | 이미지 대체 텍스트 `{언어: 텍스트}`(언어별 최대 200자, 사이트 지원 언어만). 이미지형만 사용. 비우면 관리용 제목으로 채우지 않고, 링크 팝업은 일반 문구(「팝업 링크 열기」)를 링크 이름으로 씁니다 |
| link_url | body | string | 아니오 | max 2048 | 이미지를 눌렀을 때 이동할 주소(이미지형만). 사이트 내부 경로(`/…`) 또는 `http(s)://` 절대 주소만 허용하고 `//`·`/\` 시작, `javascript:` 등 다른 스킴, 공백·제어문자는 거부합니다. 빈 문자열은 null(링크 없음) |
| link_new_window | body | boolean | 아니오 | — | 링크를 새 창에서 열지(이미지형만, 기본 false) |
| overlays | body | object | 아니오 | 기기별 max 10 | 이미지 위 글 상자 `{pc, mobile}`(이미지형만). 상자 모양·한도는 위 공통 사항 |
| body_html | body | array | 예 | — | 직접 작성형 본문 `{언어: HTML}`(`content_type=html` 일 때만 필수). 사이트 기본 언어 값은 필수, 언어별 최대 65535자. 저장 전에 HTML 정화기로 허용 태그만 남깁니다 |
| temp_key | body | string | 예 | UUID | 등록 화면의 이미지 임시 묶음 키(`content_type=image` 일 때만 필수, UUID 형식만). 그 묶음에 PC 이미지가 올라와 있어야 합니다 |
| pc_image_id | body | integer | 예 | — | 붙일 PC 이미지 ID(이미지형, 선택). 같은 `temp_key` 묶음의 PC 이미지여야 합니다. 보내지 않으면 묶음의 가장 최근 PC 이미지를 붙입니다 |
| mobile_image_id | body | integer | 아니오 | — | 붙일 모바일 이미지 ID(이미지형, 선택). 같은 묶음의 모바일 이미지여야 하고, null 이면 모바일 이미지를 붙이지 않습니다. 키를 보내지 않으면 묶음의 가장 최근 모바일 이미지를 붙입니다 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-popup.popup.store_validation_rules`).

**요청 예시**

```http
POST /api/plugins/sirsoft-popup/admin/popups HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "title": "예시 팝업",
    "is_active": true,
    "content_type": "image",
    "display_period": "range",
    "starts_at": "2026-02-01T09:00",
    "ends_at": "2026-02-28T23:59",
    "page_scope": "routes",
    "routes": [
        "/",
        "/shop"
    ],
    "audience": "all",
    "device": "all",
    "image_alt": {
        "ko": "예시 이미지 설명",
        "en": "Example image description"
    },
    "link_url": "/shop",
    "link_new_window": false,
    "temp_key": "0b7c6f1e-4d2a-4c8e-9f3b-2a1d5e6f7a8b",
    "pc_image_id": 1,
    "mobile_image_id": null
}
```

**응답 필드** (`data` 내부)

_단건 응답: 등록한 팝업의 상세(`GET /admin/popups/{popup}` 과 같은 필드) + `sanitized`._

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id ~ abilities | — | — | `GET /admin/popups/{popup}` 응답 필드와 같습니다(등록 직후 `revision` 은 `0`, `sort_order` 는 맨 뒤) |
| sanitized | boolean | `false` | 직접 작성형 본문이 HTML 정화로 바뀌었는지. true 면 화면이 「일부 서식이 제거되었습니다」를 알립니다(이미지형은 항상 false) |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "팝업을 등록했습니다.",
    "data": {
        "id": 2,
        "title": "예시 팝업",
        "is_active": true,
        "status": "scheduled",
        "sort_order": 1,
        "content_type": "image",
        "display_period": "range",
        "starts_at_display": "2026-02-01 09:00:00",
        "ends_at_display": "2026-02-28 23:59:00",
        "page_scope": "routes",
        "routes": [
            "/",
            "/shop"
        ],
        "audience": "all",
        "device": "all",
        "filled_locales": [
            "ko",
            "en"
        ],
        "created_at_display": "2026-01-15 10:00:00",
        "revision": 0,
        "image_alt": {
            "ko": "예시 이미지 설명",
            "en": "Example image description"
        },
        "body_html": {},
        "link_url": "/shop",
        "link_new_window": false,
        "starts_at_local": "2026-02-01T09:00",
        "ends_at_local": "2026-02-28T23:59",
        "images": {
            "pc": {
                "id": 1,
                "hash": "{hash}",
                "device": "pc",
                "url": "/api/plugins/sirsoft-popup/images/{hash}",
                "download_url": "/api/plugins/sirsoft-popup/images/{hash}",
                "original_filename": "banner.png",
                "mime_type": "image/png",
                "size": 245760,
                "size_formatted": "240 KB",
                "width": 480,
                "height": 520,
                "order": 0,
                "is_image": true
            },
            "mobile": null
        },
        "abilities": {
            "can_update": true,
            "can_delete": true
        },
        "sanitized": false
    }
}
```

검증 실패 시 (HTTP 422) — 예: 이미지형인데 필수값이 비고 임시 묶음 키가 없을 때:

```json
{
    "message": "관리용 제목 필드는 필수입니다. (외 6개 오류)",
    "errors": {
        "title": ["관리용 제목 필드는 필수입니다."],
        "is_active": ["사용 여부 필드는 필수입니다."],
        "display_period": ["노출 기간 방식 필드는 필수입니다."],
        "page_scope": ["노출 페이지 필드는 필수입니다."],
        "audience": ["노출 대상 필드는 필수입니다."],
        "device": ["기기 필드는 필수입니다."],
        "temp_key": ["임시 업로드 키 필드는 필수입니다."]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-popup.popups.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지). 이미지형인데 임시 묶음에 PC 이미지가 없음, 다른 묶음·다른 기기의 이미지 ID, 안전하지 않은 링크·경로, 기본 언어 본문 누락, 지원 밖 언어 키, 정화 뒤 표시할 텍스트·이미지가 없는 본문(빈 문단·NBSP 포함) 포함 |

<!-- @generated:end -->

**설명**

팝업 등록 화면의 「저장」이 부릅니다. 권한은 `auth:sanctum` + 관리자 + 팝업 등록·수정 권한(`sirsoft-popup.popups.update`)이며 성공은 `201` 입니다.

- 새 팝업은 순서의 **맨 뒤**에 놓입니다. 노출 순서는 `PUT /admin/popups/order` 로 바꿉니다.
- 이미지형은 화면이 먼저 `POST /admin/popup-images` 로 같은 `temp_key` 묶음에 이미지를 올려 둔 뒤 저장해야 합니다. 저장할 때 기기마다 한 장씩(보낸 ID 또는 묶음의 가장 최근 이미지) 팝업에 붙고, 붙지 않은 임시 이미지는 정리 스케줄이 회수합니다.
- 직접 작성형은 이미지·alt·링크를 받지 않고, 본문은 언어별로 HTML 정화 후 저장합니다. 정화로 내용이 바뀌면 응답 `sanitized: true` 입니다. 직접 작성형 요청 본문 예:

```json
{
    "title": "예시 공지",
    "is_active": true,
    "content_type": "html",
    "display_period": "always",
    "page_scope": "all",
    "audience": "member",
    "device": "all",
    "body_html": {
        "ko": "<p>예시 공지 본문입니다.</p>",
        "en": "<p>Example notice.</p>"
    }
}
```

- 발행 훅: `sirsoft-popup.popup.before_create` → `sirsoft-popup.popup.filter_create_data`(저장 값 가공) → `sirsoft-popup.popup.after_create`. 저장 뒤 사용자 화면 공개 캐시는 즉시 비워지고 활동 로그가 남습니다.


### DELETE /api/plugins/sirsoft-popup/admin/popups/bulk
<!-- @generated:start:api.plugins.sirsoft-popup.admin.popups.bulk-destroy -->
- **라우트명**: `api.plugins.sirsoft-popup.admin.popups.bulk-destroy`
- **컨트롤러**: `Plugins\Sirsoft\Popup\Http\Controllers\Admin\PopupController@bulkDestroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-popup.popups.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | query | array | 예 | min 1, max 100 | 영구 삭제할 팝업 ID 배열(정수, 중복 불가, 존재하는 ID 만). 관리자 화면은 JSON 본문으로 보내며 쿼리스트링(`ids[]=1&ids[]=2`)도 받습니다 |

**요청 예시**

```http
DELETE /api/plugins/sirsoft-popup/admin/popups/bulk HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "ids": [
        1,
        2
    ]
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| count | integer | `2` | 실제로 삭제한 팝업 수 (메시지 `팝업 :count개를 삭제했습니다.` 의 `:count`) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "팝업 2개를 삭제했습니다.",
    "data": {
        "count": 2
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-popup.popups.delete`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지). `ids` 누락·100개 초과·중복·없는 ID(`ids.N`) 포함 |

<!-- @generated:end -->

**설명**

목록에서 여러 팝업을 골라 「삭제」할 때 부릅니다. 권한은 `auth:sanctum` + 관리자 + 팝업 삭제 권한(`sirsoft-popup.popups.delete`)입니다.

- **영구 삭제**이며 되돌릴 수 없습니다(소프트 삭제 없음). 단건 삭제와 같이 PC·모바일 이미지를 먼저 떼어 낸 뒤 팝업 행을 지우고, 트랜잭션이 끝난 뒤 이미지 파일·행을 지웁니다. 파일 삭제가 실패한 이미지는 임시 이미지로 남아 정리 스케줄이 다시 지웁니다.
- 없는 ID 가 하나라도 섞이면 전체가 422 로 거부됩니다(일부만 지우지 않음).
- 발행 훅: `sirsoft-popup.popup.before_bulk_delete` → `sirsoft-popup.popup.after_bulk_delete`. 공개 캐시는 즉시 비워지고 항목별 활동 로그가 남습니다.


### POST /api/plugins/sirsoft-popup/admin/popups/bulk-copy
<!-- @generated:start:api.plugins.sirsoft-popup.admin.popups.bulk-copy -->
- **라우트명**: `api.plugins.sirsoft-popup.admin.popups.bulk-copy`
- **컨트롤러**: `Plugins\Sirsoft\Popup\Http\Controllers\Admin\PopupController@bulkCopy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-popup.popups.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | min 1, max 100 | 복사할 원본 팝업 ID 배열(정수, 중복 불가, 존재하는 ID 만) |

**요청 예시**

```http
POST /api/plugins/sirsoft-popup/admin/popups/bulk-copy HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "ids": [
        1,
        2
    ]
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| count | integer | `2` | 복사에 성공한 팝업 수 |
| copies | object | `{"1":3,"2":4}` | 원본 ID → 새로 만든 복사본 ID 맵. 성공한 건이 하나도 없으면 빈 배열 `[]` 로 직렬화됩니다 |
| failed_ids | array | `[]` | 복사하지 못한 원본 ID 목록(이미지 파일 복제 실패 등) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "팝업 2개를 복사했습니다. 복사본은 비활성으로 맨 뒤에 추가됐습니다.",
    "data": {
        "count": 2,
        "copies": {
            "1": 3,
            "2": 4
        },
        "failed_ids": []
    }
}
```

일부 실패 시에도 HTTP 200 이며 메시지와 `failed_ids` 로 구분합니다:

```json
{
    "success": true,
    "message": "팝업 1개를 복사했고 1개는 복사하지 못했습니다. 이미지 파일을 확인한 뒤 다시 시도하세요.",
    "data": {
        "count": 1,
        "copies": {
            "1": 3
        },
        "failed_ids": [
            2
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-popup.popups.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지). `ids` 누락·100개 초과·중복·없는 ID 포함 |

<!-- @generated:end -->

**설명**

목록에서 여러 팝업을 골라 「복사」할 때 부릅니다. 권한은 `auth:sanctum` + 관리자 + 팝업 등록·수정 권한(`sirsoft-popup.popups.update`)입니다.

- 복사본은 **비활성**(`is_active=false`)으로 순서 맨 뒤에 원본 순서대로 추가됩니다. 복사 즉시 같은 팝업이 사용자 화면에 두 번 뜨지 않게 하려는 것입니다. 관리용 제목 뒤에는 ` (복사)` 가 붙습니다(100자를 넘지 않게 원래 제목을 자름).
- 기간·노출 조건·다국어 내용·링크를 그대로 복사하고, 이미지는 파일까지 새 해시로 복제합니다. 원본을 지워도 복사본 이미지가 깨지지 않습니다.
- 한 건 복사가 실패하면(원본 이미지 파일 없음·쓰기 실패 등) 그 건만 되돌리고 나머지는 계속합니다. 실패해도 응답은 200 이며 `failed_ids` 와 부분 실패 메시지로 알립니다.
- 발행 훅: `sirsoft-popup.popup.before_bulk_copy` → `sirsoft-popup.popup.after_bulk_copy`(복사 맵·실패 ID 전달). 공개 캐시는 즉시 비워집니다.


### PATCH /api/plugins/sirsoft-popup/admin/popups/bulk-status
<!-- @generated:start:api.plugins.sirsoft-popup.admin.popups.bulk-status -->
- **라우트명**: `api.plugins.sirsoft-popup.admin.popups.bulk-status`
- **컨트롤러**: `Plugins\Sirsoft\Popup\Http\Controllers\Admin\PopupController@bulkStatus`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-popup.popups.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | min 1, max 100 | 대상 팝업 ID 배열(정수, 중복 불가, 존재하는 ID 만) |
| is_active | body | boolean | 예 | — | 바꿀 사용 여부 (true 노출 / false 비노출) |

**요청 예시**

```http
PATCH /api/plugins/sirsoft-popup/admin/popups/bulk-status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "ids": [
        1,
        2
    ],
    "is_active": false
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| count | integer | `2` | 사용 여부를 바꾼 팝업 수 (메시지의 `:count`) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "팝업 2개의 노출 여부를 바꿨습니다.",
    "data": {
        "count": 2
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-popup.popups.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지). `ids` 누락·100개 초과·중복·없는 ID, `is_active` 누락 포함 |

<!-- @generated:end -->

**설명**

목록에서 여러 팝업을 골라 「노출」·「비노출」로 바꿀 때 부릅니다. 권한은 `auth:sanctum` + 관리자 + 팝업 등록·수정 권한(`sirsoft-popup.popups.update`)입니다.

- 사용 여부(`is_active`)만 바꾸며 기간은 건드리지 않습니다. 기간이 지난 팝업을 켜도 상태는 `ended` 입니다.
- 바뀐 팝업마다 세대 번호(`revision`)가 1 올라갑니다. 그 팝업의 수정 화면을 열어 두었던 다른 관리자가 저장하면 409 로 충돌을 알립니다.
- 발행 훅: `sirsoft-popup.popup.before_bulk_status` → `sirsoft-popup.popup.after_bulk_status`. 공개 캐시는 즉시 비워지고 항목별 활동 로그가 남습니다.


### GET /api/plugins/sirsoft-popup/admin/popups/order
<!-- @generated:start:api.plugins.sirsoft-popup.admin.popups.order -->
- **라우트명**: `api.plugins.sirsoft-popup.admin.popups.order`
- **컨트롤러**: `Plugins\Sirsoft\Popup\Http\Controllers\Admin\PopupController@orderList`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-popup.popups.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-popup/admin/popups/order HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 팝업 ID |
| title | string | `예시 팝업` | 관리용 제목(관리자 화면 전용, 다국어 아님) |
| is_active | boolean | `true` | 사용 여부 — 관리자가 켜고 끄는 노출 스위치(기간과 별개) |
| status | string | `active` | 조회 시각 기준 판정 상태: `active` 노출 중 · `scheduled` 예약 · `ended` 종료 · `inactive` 비활성 |
| sort_order | integer | `0` | 순서값(0부터, 작을수록 앞) — 이 목록의 정렬 기준 |
| content_type | string | `image` | 내용 유형: `image` 이미지형 · `html` 직접 작성형 |
| display_period | string | `always` | 노출 기간 방식: `always` 상시 · `range` 기간 지정 |
| starts_at_display | null | `null` | 노출 시작 일시(사이트 시간대). 상시 노출이면 null |
| ends_at_display | null | `null` | 노출 종료 일시(사이트 시간대). 상시 노출이면 null |
| page_scope | string | `all` | 노출 페이지: `all` 전체 · `routes` 지정 경로만 |
| routes | array | `[]` | 노출 경로 목록(`page_scope=routes` 일 때만 값이 있음) |
| audience | string | `all` | 노출 대상: `all` 전체 · `guest` 비회원만 · `member` 회원만 |
| device | string | `all` | 노출 기기: `all` PC + 모바일 · `pc` · `mobile` |
| filled_locales | array | `[]` | 내용이 입력된 언어 코드 목록(이미지형은 alt, 직접 작성형은 본문 기준) |
| created_at_display | string | `2026-01-15 10:00:00` | 등록 일시(사이트 시간대) |
| thumbnail | object | `{"id":1,"hash":"{hash}","device":"pc","url":"/api/plugins/sirsoft-…` | PC 이미지 정보(업로드 응답과 같은 모양). 직접 작성형이거나 이미지가 없으면 null — 순서 변경 창의 썸네일 |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 팝업에 수행 가능한 작업 — `can_update`, `can_delete` |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "팝업을 불러왔습니다.",
    "data": {
        "data": [
            {
                "id": 1,
                "title": "예시 팝업",
                "is_active": true,
                "status": "active",
                "sort_order": 0,
                "content_type": "image",
                "display_period": "always",
                "starts_at_display": null,
                "ends_at_display": null,
                "page_scope": "all",
                "routes": [],
                "audience": "all",
                "device": "all",
                "filled_locales": [],
                "created_at_display": "2026-01-15 10:00:00",
                "thumbnail": {
                    "id": 1,
                    "hash": "{hash}",
                    "device": "pc",
                    "url": "/api/plugins/sirsoft-popup/images/{hash}",
                    "download_url": "/api/plugins/sirsoft-popup/images/{hash}",
                    "original_filename": "banner.png",
                    "mime_type": "image/png",
                    "size": 245760,
                    "size_formatted": "240 KB",
                    "width": 480,
                    "height": 520,
                    "order": 0,
                    "is_image": true
                },
                "abilities": {
                    "can_update": true,
                    "can_delete": true
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
| 403 | Forbidden | 요구 권한(`sirsoft-popup.popups.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**

목록 화면의 [순서 변경] 창이 여는 순간 부르는 조회 전용 엔드포인트입니다. 권한은 `auth:sanctum` + 관리자 + 팝업 조회 권한(`sirsoft-popup.popups.read`)입니다.

- 상태·기기와 관계없이 **팝업 전체**를 순서값(`sort_order`) → ID 순으로 페이지 없이 돌려줍니다. 순서 변경(`PUT /admin/popups/order`)은 전체 ID 를 받아야 하므로, 창은 이 응답의 ID 를 모두 새 순서로 보내야 합니다.
- 항목 모양은 목록(`GET /admin/popups`)의 행과 같고, 컬렉션 `abilities`·`statistics`·`pagination` 은 없습니다.


### PUT /api/plugins/sirsoft-popup/admin/popups/order
<!-- @generated:start:api.plugins.sirsoft-popup.admin.popups.reorder -->
- **라우트명**: `api.plugins.sirsoft-popup.admin.popups.reorder`
- **컨트롤러**: `Plugins\Sirsoft\Popup\Http\Controllers\Admin\PopupController@reorder`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-popup.popups.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | min 1 | 새 순서대로 나열한 **전체** 팝업 ID 배열(정수, 중복 불가). 빠지거나 없는 ID 가 있으면 422 — 배열의 위치(0부터)가 그대로 순서값이 됩니다 |

**요청 예시**

```http
PUT /api/plugins/sirsoft-popup/admin/popups/order HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "ids": [
        2,
        1,
        3
    ]
}
```

**응답 필드** (`data` 내부)

_성공 시 `data` 는 `null` 입니다 (처리 결과는 `success`·`message` 로만 전달)._

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| (data) | null | `null` | 반환 데이터 없음 — 화면은 성공 뒤 목록을 다시 불러옵니다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "팝업 순서를 바꿨습니다.",
    "data": null
}
```

팝업 일부만 보낸 경우 (HTTP 422):

```json
{
    "message": "순서를 바꿀 때는 팝업을 모두 보내야 합니다. 목록을 다시 불러온 뒤 시도하세요.",
    "errors": {
        "ids": ["순서를 바꿀 때는 팝업을 모두 보내야 합니다. 목록을 다시 불러온 뒤 시도하세요."]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-popup.popups.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지). 보낸 ID 집합이 현재 팝업 전체와 다름(빠짐·없는 ID — 그사이 다른 관리자가 팝업을 추가·삭제한 경우 포함), 중복 ID 포함 |

<!-- @generated:end -->

**설명**

[순서 변경] 창의 「저장」이 부릅니다. 권한은 `auth:sanctum` + 관리자 + 팝업 등록·수정 권한(`sirsoft-popup.popups.update`)입니다.

- 정렬 대상은 팝업 **전체**입니다(위치 개념 없이 캐러셀 순서 하나). 일부만 보내면 나머지 팝업의 순서값이 겹쳐 사용자 화면 순서가 꼬이므로 422 로 거부합니다. 422 를 받으면 `GET /admin/popups/order` 로 다시 불러온 뒤 시도합니다.
- 배열 위치가 곧 `sort_order`(0, 1, 2…)가 되며 한 트랜잭션으로 저장합니다. 사용자 화면에는 노출 조건을 통과한 팝업만 이 순서로 담깁니다.
- 발행 훅: `sirsoft-popup.popup.before_reorder` → `sirsoft-popup.popup.after_reorder`. 공개 캐시는 즉시 비워지고 활동 로그가 남습니다.


### GET /api/plugins/sirsoft-popup/admin/popups/summary
<!-- @generated:start:api.plugins.sirsoft-popup.admin.popups.summary -->
- **라우트명**: `api.plugins.sirsoft-popup.admin.popups.summary`
- **컨트롤러**: `Plugins\Sirsoft\Popup\Http\Controllers\Admin\PopupController@summary`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-popup.popups.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-popup/admin/popups/summary HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 팝업 ID |
| title | string | `예시 팝업` | 관리용 제목(관리자 화면 전용, 다국어 아님) |
| is_active | boolean | `true` | 사용 여부(이 목록에서는 항상 true) |
| status | string | `active` | 판정 상태(이 목록에서는 항상 `active` 노출 중) |
| sort_order | integer | `0` | 순서값(0부터, 작을수록 앞) — 사용자 화면 캐러셀 순서 |
| content_type | string | `image` | 내용 유형: `image` 이미지형 · `html` 직접 작성형 |
| display_period | string | `always` | 노출 기간 방식: `always` 상시 · `range` 기간 지정 |
| starts_at_display | null | `null` | 노출 시작 일시(사이트 시간대). 상시 노출이면 null |
| ends_at_display | null | `null` | 노출 종료 일시(사이트 시간대). 상시 노출이면 null |
| page_scope | string | `all` | 노출 페이지: `all` 전체 · `routes` 지정 경로만 |
| routes | array | `[]` | 노출 경로 목록(`page_scope=routes` 일 때만 값이 있음) |
| audience | string | `all` | 노출 대상: `all` 전체 · `guest` 비회원만 · `member` 회원만 |
| device | string | `all` | 노출 기기: `all` PC + 모바일 · `pc` · `mobile` |
| filled_locales | array | `[]` | 내용이 입력된 언어 코드 목록(이미지형은 alt, 직접 작성형은 본문 기준) |
| created_at_display | string | `2026-01-15 10:00:00` | 등록 일시(사이트 시간대) |
| thumbnail | object | `{"id":1,"hash":"{hash}","device":"pc","url":"/api/plugins/sirsoft-…` | PC 이미지 정보(업로드 응답과 같은 모양). 직접 작성형이거나 이미지가 없으면 null |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 팝업에 수행 가능한 작업 — `can_update`, `can_delete` |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "팝업을 불러왔습니다.",
    "data": {
        "data": [
            {
                "id": 1,
                "title": "예시 팝업",
                "is_active": true,
                "status": "active",
                "sort_order": 0,
                "content_type": "image",
                "display_period": "always",
                "starts_at_display": null,
                "ends_at_display": null,
                "page_scope": "all",
                "routes": [],
                "audience": "all",
                "device": "all",
                "filled_locales": [],
                "created_at_display": "2026-01-15 10:00:00",
                "thumbnail": {
                    "id": 1,
                    "hash": "{hash}",
                    "device": "pc",
                    "url": "/api/plugins/sirsoft-popup/images/{hash}",
                    "download_url": "/api/plugins/sirsoft-popup/images/{hash}",
                    "original_filename": "banner.png",
                    "mime_type": "image/png",
                    "size": 245760,
                    "size_formatted": "240 KB",
                    "width": 480,
                    "height": 520,
                    "order": 0,
                    "is_image": true
                },
                "abilities": {
                    "can_update": true,
                    "can_delete": true
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
| 403 | Forbidden | 요구 권한(`sirsoft-popup.popups.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**

목록 화면 상단 「지금 노출 중」 띠가 부르는 조회 전용 엔드포인트입니다. 권한은 `auth:sanctum` + 관리자 + 팝업 조회 권한(`sirsoft-popup.popups.read`)입니다.

- 조회 시각 기준으로 상태가 `active`(사용 중이고 기간 안이거나 상시)인 팝업만 순서값 → ID 순으로 페이지 없이 돌려줍니다.
- 노출 대상(회원·비회원)·노출 페이지·기기는 거르지 않습니다. 실제 방문자에게 보이는지는 그 조건과 방문자의 「보지 않기」 기록에 따라 사용자 화면에서 다시 갈립니다.


### DELETE /api/plugins/sirsoft-popup/admin/popups/{popup}
<!-- @generated:start:api.plugins.sirsoft-popup.admin.popups.destroy -->
- **라우트명**: `api.plugins.sirsoft-popup.admin.popups.destroy`
- **컨트롤러**: `Plugins\Sirsoft\Popup\Http\Controllers\Admin\PopupController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-popup.popups.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| popup | path | string | 예 | — | 삭제할 팝업 ID(정수, 라우트 제약 `whereNumber`) |

**요청 예시**

```http
DELETE /api/plugins/sirsoft-popup/admin/popups/{popup} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_성공 시 `data` 는 `null` 입니다 (처리 결과는 `success`·`message` 로만 전달)._

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| (data) | null | `null` | 반환 데이터 없음 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "팝업을 삭제했습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-popup.popups.delete`)이 없는 경우 |
| 404 | Not Found | 해당 ID 의 팝업이 없는 경우(라우트 모델 바인딩 — 본문 `{"message": "No query results for model [...] {id}"}`) |

<!-- @generated:end -->

**설명**

팝업 한 건을 **영구 삭제**합니다(되돌릴 수 없음). 권한은 `auth:sanctum` + 관리자 + 팝업 삭제 권한(`sirsoft-popup.popups.delete`)입니다.

- PC·모바일 이미지를 먼저 떼어 낸 뒤 팝업 행을 지우고, 트랜잭션이 끝난 뒤 이미지 파일·행을 지웁니다. 파일 삭제가 실패한 이미지는 임시 이미지로 남아 정리 스케줄이 다시 지웁니다.
- 발행 훅: `sirsoft-popup.popup.before_delete` → `sirsoft-popup.popup.after_delete`(ID·삭제 전 스냅숏). 공개 캐시는 즉시 비워지고 활동 로그가 남습니다.


### GET /api/plugins/sirsoft-popup/admin/popups/{popup}
<!-- @generated:start:api.plugins.sirsoft-popup.admin.popups.show -->
- **라우트명**: `api.plugins.sirsoft-popup.admin.popups.show`
- **컨트롤러**: `Plugins\Sirsoft\Popup\Http\Controllers\Admin\PopupController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-popup.popups.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| popup | path | string | 예 | — | 조회할 팝업 ID(정수, 라우트 제약 `whereNumber`) |

**요청 예시**

```http
GET /api/plugins/sirsoft-popup/admin/popups/{popup} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 팝업 ID |
| title | string | `예시 팝업` | 관리용 제목(관리자 화면 전용, 다국어 아님) |
| is_active | boolean | `true` | 사용 여부 — 관리자가 켜고 끄는 노출 스위치(기간과 별개) |
| status | string | `active` | 조회 시각 기준 판정 상태: `active` 노출 중 · `scheduled` 예약 · `ended` 종료 · `inactive` 비활성 |
| sort_order | integer | `0` | 순서값(0부터, 작을수록 앞) — 사용자 화면 캐러셀 순서 |
| content_type | string | `image` | 내용 유형: image(이미지형), html(직접 작성형) |
| display_period | string | `always` | 노출 기간: always(상시), range(기간 지정) |
| starts_at_display | null | `null` | 노출 시작 일시 표시값(사이트 시간대, `Y-m-d H:i:s`). 상시 노출이면 null |
| ends_at_display | null | `null` | 노출 종료 일시 표시값(사이트 시간대, `Y-m-d H:i:s`). 상시 노출이면 null |
| page_scope | string | `all` | 노출 페이지: all(전체), routes(라우트 지정) |
| routes | array | `[]` | 노출 경로 목록(`page_scope=routes` 일 때만 값이 있음) |
| audience | string | `all` | 노출 대상: all(전체), guest(비회원만), member(회원만) |
| device | string | `all` | 노출 기기: all(전체), pc, mobile |
| filled_locales | array | `[]` | 내용이 입력된 언어 코드 목록(이미지형은 `image_alt`, 직접 작성형은 `body_html` 기준) |
| created_at_display | string | `2026-01-15 10:00:00` | 등록 일시(사이트 시간대) |
| revision | integer | `0` | 세대 번호 — 저장(수정·일괄 노출 변경)마다 1씩 오릅니다. 수정 요청에 그대로 보내 동시 수정 충돌(409)을 판정합니다 |
| image_alt | array | `[]` | 이미지 대체 텍스트 (다국어 JSON `{언어: 텍스트}`, 값이 없으면 빈 객체) |
| body_html | array | `[]` | 직접 작성형 본문 (다국어 JSON, 저장 때 정화한 HTML, 값이 없으면 빈 객체) |
| link_url | null | `null` | 이미지 클릭 시 이동 주소(사이트 경로 또는 http(s) 주소). 없으면 null |
| link_new_window | boolean | `false` | 새 창에서 열기 |
| overlays | object\|null | `null` | 이미지 위 글 상자 `{pc, mobile}` — 글은 언어별 원본 그대로(편집기가 받는다). 없으면 null |
| starts_at_local | null | `null` | 수정 화면 입력용 노출 시작 일시(사이트 시간대 `YYYY-MM-DDTHH:mm`, datetime-local 값). 상시 노출이면 null |
| ends_at_local | null | `null` | 수정 화면 입력용 노출 종료 일시(사이트 시간대 `YYYY-MM-DDTHH:mm`). 상시 노출이면 null |
| images | object | `{"pc":{"id":1,"hash":"{hash}","device":"pc","url":"/api/plu…` | 기기별 이미지 `{pc, mobile}` — 각 값은 업로드 응답과 같은 모양의 객체 또는 null. 수정 저장 때 `pc_image_id`·`mobile_image_id` 로 보낼 ID 의 출처 |
| abilities | object | `{"can_update":true,"can_delete":true}` | 현재 사용자가 이 팝업에 수행 가능한 작업 — `can_update`(등록·수정 권한), `can_delete`(삭제 권한) |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "팝업을 불러왔습니다.",
    "data": {
        "id": 1,
        "title": "예시 팝업",
        "is_active": true,
        "status": "active",
        "sort_order": 0,
        "content_type": "image",
        "display_period": "always",
        "starts_at_display": null,
        "ends_at_display": null,
        "page_scope": "all",
        "routes": [],
        "audience": "all",
        "device": "all",
        "filled_locales": [],
        "created_at_display": "2026-01-15 10:00:00",
        "revision": 0,
        "image_alt": [],
        "body_html": [],
        "link_url": null,
        "link_new_window": false,
        "starts_at_local": null,
        "ends_at_local": null,
        "images": {
            "pc": {
                "id": 1,
                "hash": "{hash}",
                "device": "pc",
                "url": "/api/plugins/sirsoft-popup/images/{hash}",
                "download_url": "/api/plugins/sirsoft-popup/images/{hash}",
                "original_filename": "banner.png",
                "mime_type": "image/png",
                "size": 245760,
                "size_formatted": "240 KB",
                "width": 480,
                "height": 520,
                "order": 0,
                "is_image": true
            },
            "mobile": null
        },
        "abilities": {
            "can_update": true,
            "can_delete": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-popup.popups.read`)이 없는 경우 |
| 404 | Not Found | 해당 ID 의 팝업이 없는 경우(라우트 모델 바인딩) |

<!-- @generated:end -->

**설명**

팝업 수정 화면이 여는 순간 부르는 상세 조회입니다. 권한은 `auth:sanctum` + 관리자 + 팝업 조회 권한(`sirsoft-popup.popups.read`)입니다.

- 목록 행 필드에 더해 다국어 원본(`image_alt`·`body_html`), 링크, 기간 입력값(`*_local`), 기기별 이미지(`images`), 세대 번호(`revision`)를 싣습니다.
- 수정 화면은 `revision` 을 기억했다가 저장 요청에 그대로 보냅니다. 그사이 다른 관리자가 저장했으면 409 가 나며, 이때 다시 이 엔드포인트로 최신본을 받아 비교합니다.
- 다국어 값이 비어 있으면 실제 JSON 은 빈 객체 `{}` 로 내려갑니다(예시의 `[]` 는 비어 있음을 나타냄).


### PUT /api/plugins/sirsoft-popup/admin/popups/{popup}
<!-- @generated:start:api.plugins.sirsoft-popup.admin.popups.update -->
- **라우트명**: `api.plugins.sirsoft-popup.admin.popups.update`
- **컨트롤러**: `Plugins\Sirsoft\Popup\Http\Controllers\Admin\PopupController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-popup.popups.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| popup | path | string | 예 | — | 수정할 팝업 ID(정수, 라우트 제약 `whereNumber`) |
| title | body | string | 예 | max 100 | 관리용 제목(앞뒤 공백 제거). 다국어가 아닙니다 |
| is_active | body | boolean | 예 | — | 사용 여부 (true 사용 / false 사용 안 함) |
| content_type | body | string | 예 | `image`, `html` | 내용 유형 — `image` 이미지형 / `html` 직접 작성형. `html` 로 바꾸면 붙어 있던 이미지를 떼어 지웁니다 |
| display_period | body | string | 예 | `always`, `range` | 노출 기간 방식 — `always` 상시 / `range` 기간 지정. `always` 면 기간을 비웁니다 |
| starts_at | body | date | 예 | — | 노출 시작 일시(사이트 시간대 `YYYY-MM-DDTHH:mm`). `display_period=range` 일 때만 필수·검증 |
| ends_at | body | date | 예 | — | 노출 종료 일시(사이트 시간대 `YYYY-MM-DDTHH:mm`, `starts_at` 이후). `display_period=range` 일 때만 필수·검증 |
| page_scope | body | string | 예 | `all`, `routes` | 노출 페이지 — `all` 전체 / `routes` 지정 경로만. `all` 이면 경로를 비웁니다 |
| routes | body | array | 예 | min 1, max 20 | 노출 경로 목록(`page_scope=routes` 일 때만 필수). 형식·정규화 규칙은 등록(`POST /admin/popups`)과 같습니다 |
| audience | body | string | 예 | `all`, `guest`, `member` | 노출 대상 — 전체 / 비회원만 / 회원만 |
| device | body | string | 예 | `all`, `pc`, `mobile` | 노출 기기 — PC + 모바일 / PC 만 / 모바일만 |
| image_alt | body | array | 아니오 | — | 이미지 대체 텍스트 `{언어: 텍스트}`(언어별 최대 200자, 이미지형만) |
| link_url | body | string | 아니오 | max 2048 | 이미지 클릭 시 이동 주소(이미지형만). 사이트 내부 경로 또는 http(s) 절대 주소만 — 규칙은 등록과 같습니다 |
| link_new_window | body | boolean | 아니오 | — | 링크를 새 창에서 열지(이미지형만, 기본 false) |
| overlays | body | object | 아니오 | 기기별 max 10 | 이미지 위 글 상자 `{pc, mobile}`(이미지형만). 보낸 목록으로 통째로 바뀌고, 빈 목록이면 null — 규칙은 등록과 같습니다 |
| body_html | body | array | 예 | — | 직접 작성형 본문 `{언어: HTML}`(`content_type=html` 일 때만 필수, 기본 언어 필수, 언어별 최대 65535자, 저장 전 HTML 정화) |
| revision | body | integer | 예 | min 0 | 수정 화면이 받은 세대 번호(상세 응답의 `revision`). 지금 값과 다르면 검증보다 먼저 409 를 돌려줍니다. 키를 보내지 않으면 충돌 검사를 하지 않으므로 화면은 항상 보냅니다 |
| temp_key | body | string | 아니오 | UUID | 수정 화면의 이미지 임시 묶음 키(이미지형, UUID 형식만). 새로 올린 이미지를 `pc_image_id`·`mobile_image_id` 로 고를 때 그 이미지가 이 묶음 것인지 확인하는 데 씁니다 |
| pc_image_id | body | integer | 예 | — | 남길 PC 이미지 ID(이미지형, 선택). 이 팝업에 지금 붙은 PC 이미지이거나 같은 `temp_key` 묶음의 PC 이미지여야 하며 null 은 받지 않습니다. 보내지 않으면 PC 이미지를 그대로 둡니다(그때 팝업에 PC 이미지가 없으면 422) |
| mobile_image_id | body | integer | 아니오 | — | 남길 모바일 이미지 ID(이미지형, 선택). null 이면 모바일 이미지를 뗍니다. 보내지 않으면 그대로 둡니다 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-popup.popup.update_validation_rules`).

**요청 예시**

```http
PUT /api/plugins/sirsoft-popup/admin/popups/{popup} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "title": "예시 팝업",
    "is_active": true,
    "content_type": "image",
    "display_period": "range",
    "starts_at": "2026-02-01T09:00",
    "ends_at": "2026-02-28T23:59",
    "page_scope": "routes",
    "routes": [
        "/",
        "/shop"
    ],
    "audience": "all",
    "device": "all",
    "image_alt": {
        "ko": "예시 이미지 설명",
        "en": "Example image description"
    },
    "link_url": "/shop",
    "link_new_window": false,
    "revision": 0,
    "temp_key": "0b7c6f1e-4d2a-4c8e-9f3b-2a1d5e6f7a8b",
    "pc_image_id": 1,
    "mobile_image_id": null
}
```

**응답 필드** (`data` 내부)

_단건 응답: 수정한 팝업의 상세(`GET /admin/popups/{popup}` 과 같은 필드) + `sanitized`._

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id ~ abilities | — | — | `GET /admin/popups/{popup}` 응답 필드와 같습니다. `revision` 은 저장 전보다 1 오른 값이며, 화면은 이어서 저장할 때 이 값을 보냅니다 |
| sanitized | boolean | `false` | 직접 작성형 본문이 HTML 정화로 바뀌었는지(이미지형은 항상 false) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "팝업을 수정했습니다.",
    "data": {
        "id": 2,
        "title": "예시 팝업",
        "is_active": true,
        "status": "scheduled",
        "sort_order": 1,
        "content_type": "image",
        "display_period": "range",
        "starts_at_display": "2026-02-01 09:00:00",
        "ends_at_display": "2026-02-28 23:59:00",
        "page_scope": "routes",
        "routes": [
            "/",
            "/shop"
        ],
        "audience": "all",
        "device": "all",
        "filled_locales": [
            "ko",
            "en"
        ],
        "created_at_display": "2026-01-15 10:00:00",
        "revision": 1,
        "image_alt": {
            "ko": "예시 이미지 설명",
            "en": "Example image description"
        },
        "body_html": {},
        "link_url": "/shop",
        "link_new_window": false,
        "starts_at_local": "2026-02-01T09:00",
        "ends_at_local": "2026-02-28T23:59",
        "images": {
            "pc": {
                "id": 1,
                "hash": "{hash}",
                "device": "pc",
                "url": "/api/plugins/sirsoft-popup/images/{hash}",
                "download_url": "/api/plugins/sirsoft-popup/images/{hash}",
                "original_filename": "banner.png",
                "mime_type": "image/png",
                "size": 245760,
                "size_formatted": "240 KB",
                "width": 480,
                "height": 520,
                "order": 0,
                "is_image": true
            },
            "mobile": null
        },
        "abilities": {
            "can_update": true,
            "can_delete": true
        },
        "sanitized": false
    }
}
```

동시 수정 충돌 시 (HTTP 409) — 보낸 `revision` 이 지금 세대 번호와 다를 때:

```json
{
    "success": false,
    "message": "다른 관리자가 먼저 수정했습니다. 입력 내용은 보존되어 있습니다. 최신 팝업과 비교한 뒤 다시 저장하세요."
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-popup.popups.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지). 이미지형인데 PC 이미지가 없음(`pc_image`), 다른 팝업·다른 묶음·다른 기기의 이미지 ID, 저장돼 있지 않던 지원 밖 언어 키, 정화 뒤 표시할 텍스트·이미지가 없는 본문(빈 문단·NBSP 포함) 포함 |
| 404 | Not Found | 해당 ID 의 팝업이 없는 경우(라우트 모델 바인딩) |
| 409 | Conflict | 보낸 `revision` 이 지금 세대 번호와 다른 경우 — 다른 관리자가 먼저 저장함(`sirsoft-popup::messages.popup.conflict`). 검증 오류보다 먼저 판정하고, 잠금을 잡은 뒤 한 번 더 확인합니다 |

<!-- @generated:end -->

**설명**

팝업 수정 화면의 「저장」이 부릅니다. 권한은 `auth:sanctum` + 관리자 + 팝업 등록·수정 권한(`sirsoft-popup.popups.update`)입니다. 규칙은 등록과 대칭이고, 저장할 이미지 선택(`pc_image_id`·`mobile_image_id`)과 세대 번호(`revision`)를 더 받습니다.

- **동시 수정**: 세대 번호가 다르면 409 입니다. 관리자 화면은 409 를 받으면 입력을 보존한 채 최신본(`GET /admin/popups/{popup}`)과 비교해 다시 적용하게 합니다. 일괄 노출 변경(`PATCH /admin/popups/bulk-status`)도 세대 번호를 올립니다.
- **이미지는 저장할 때만 바뀝니다.** 수정 화면의 업로드는 임시 묶음에 머물고, 이 요청이 보낸 ID 로 기기별 이미지를 맞춥니다. 떼어 낸 옛 이미지는 트랜잭션 뒤에 파일·행을 지우고, 실패분은 정리 스케줄이 다시 지웁니다. 직접 작성형으로 바꾸면 이미지를 모두 뗍니다.
- 기기별 이미지가 바뀌면 `sirsoft-popup.popup_image.after_change`(기기·`replaced`/`removed`) 훅이 발행됩니다.
- 발행 훅: `sirsoft-popup.popup.before_update` → `sirsoft-popup.popup.filter_update_data`(저장 값 가공) → `sirsoft-popup.popup.after_update`(수정 전 스냅숏 포함). 공개 캐시는 즉시 비워지고 활동 로그가 남습니다.


### GET /api/plugins/sirsoft-popup/popups
<!-- @generated:start:api.plugins.sirsoft-popup.popups.index -->
- **라우트명**: `api.plugins.sirsoft-popup.popups.index`
- **컨트롤러**: `Plugins\Sirsoft\Popup\Http\Controllers\Public\PopupController@index`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-popup/popups HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| settings | object | `{"pc_position":"center","pc_left":80,"pc_top":120,"pc_wid…` | 전역 표시 방식(PC 위치·크기, 모바일 표시, 배경 어둡게·배경 클릭 닫기, 자동 넘김, 「보지 않기」 방식). 키별 의미는 [display-settings.md](display-settings.md) 의 `settings` 객체 표와 같습니다 |
| popups | array | `[]` | 요청자에게 보일 수 있는 노출 후보(관리자 순서대로). 항목 필드는 아래 설명 절의 표 — 노출 페이지·기기·「보지 않기」는 브라우저가 다시 거릅니다 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "팝업을 불러왔습니다.",
    "data": {
        "settings": {
            "pc_position": "center",
            "pc_left": 80,
            "pc_top": 120,
            "pc_width": 480,
            "pc_height": 520,
            "mobile_style": "sheet",
            "dim_background": true,
            "close_on_backdrop": false,
            "autoplay": true,
            "autoplay_interval": 6,
            "dismiss_mode": "today",
            "dismiss_days": 7
        },
        "popups": [
            {
                "id": 1,
                "content_type": "image",
                "page_scope": "all",
                "routes": [],
                "device": "all",
                "image": {
                    "pc": {
                        "url": "/api/plugins/sirsoft-popup/images/{hash}",
                        "width": 480,
                        "height": 520
                    },
                    "mobile": null
                },
                "image_alt": "예시 이미지 설명",
                "link_url": "/shop",
                "link_new_window": false,
                "body_html": ""
            },
            {
                "id": 2,
                "content_type": "html",
                "page_scope": "routes",
                "routes": [
                    "/shop"
                ],
                "device": "pc",
                "image": null,
                "image_alt": "",
                "link_url": null,
                "link_new_window": false,
                "body_html": "<p>예시 공지 본문입니다.</p>"
            }
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 429 | Too Many Requests | 같은 클라이언트가 분당 120회를 넘겨 요청한 경우(`throttle:120,1`) |

<!-- @generated:end -->

**설명**

사용자 화면의 팝업 레이어(템플릿 공통 확장점 `user_global_overlay` 에 주입되는 조각)가 페이지를 열 때 부르는 공개 엔드포인트입니다. 인증은 선택(`optional.sanctum`)이라 비회원도 부르며, 토큰이 있으면 회원, 없거나 무효이거나 정지 회원이면 비회원으로 노출 대상을 고릅니다. 언어는 요청 언어(로케일)로 정해지고 요청 파라미터는 없습니다.

- **서버가 거르는 것**: 사용 여부·기간(조회 시각에 노출 중인 것만)과 노출 대상(전체 + 요청자에 맞는 회원/비회원). **브라우저가 거르는 것**: 노출 페이지(`page_scope`·`routes`), 노출 기기(`device`), 방문자의 「보지 않기」 기록(쿠키 `g7_popup_dismissed`). 페이지 이동마다 다시 묻지 않고 응답을 회원 여부·언어 단위로 캐시하기 위한 분담입니다.
- 응답은 회원 여부·언어별로 플러그인 설정 `public_cache_minutes`(기본 10분, 0 이면 캐시 안 함) 동안 캐시되며, 다음 예약 시작·종료 시각이 더 가까우면 그때 만료됩니다. 캐시에서 꺼낸 뒤에도 지금 시각으로 상태를 다시 판정하고, 관리자가 팝업을 저장·삭제·순서 변경하거나 플러그인 설정을 저장하면 즉시 비워집니다.
- 검색엔진 봇 요청에는 `popups` 를 빈 배열로 돌려주고 캐시·훅을 거치지 않습니다.
- 운영 정보(관리용 제목·사용 여부·기간·작성자)는 싣지 않습니다. 다국어 값(대체 텍스트·본문·글 상자 글)은 요청 언어 → 사이트 기본 언어 순으로 골라 문자열로 내려갑니다.
- 발행 훅: `sirsoft-popup.popup.before_public_list` → `sirsoft-popup.popup.filter_public_list_result`(노출 목록 가공) → `sirsoft-popup.popup.after_public_list`. 캐시 밖에서 요청마다 발행하므로 한 요청자의 가공이 다른 방문자에게 섞이지 않습니다.

**`popups[]` 항목**

| 필드 | 타입 | 예시값 | 설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 팝업 ID — 「보지 않기」 쿠키 기록의 키 |
| content_type | string | `image` | 내용 유형: `image` 이미지형 · `html` 직접 작성형 |
| page_scope | string | `all` | 노출 페이지: `all` 전체 · `routes` 지정 경로만(브라우저가 판정) |
| routes | array | `["/shop"]` | 노출 경로 목록. 현재 경로가 같거나 그 하위 경로면 노출, `/` 는 첫 화면만 |
| device | string | `all` | 노출 기기: `all` · `pc` · `mobile`(템플릿 반응형 기준 폭으로 브라우저가 판정) |
| image | object\|null | `{"pc":{"url":"…","width":480,"height":520},"mobile":null}` | 이미지형의 기기별 이미지 `{url, width, height}`(없으면 null). `url` 은 사이트 마운트 접두사 포함(하위 폴더 설치면 `/g7/api/plugins/sirsoft-popup/images/{hash}`). 모바일 이미지가 없으면 화면은 PC 이미지를 씁니다. 직접 작성형은 null |
| image_alt | string | `예시 이미지 설명` | 요청 언어의 대체 텍스트(없으면 사이트 기본 언어). 둘 다 비었으면 빈 문자열이며 관리용 제목으로 채우지 않습니다. 직접 작성형은 빈 문자열 |
| link_url | string\|null | `/shop` | 이미지 클릭 시 이동 주소(이미지형만, 없으면 null) |
| link_new_window | boolean | `false` | 새 창에서 열기(이미지형만) |
| overlays | object\|null | `{"pc":[{"id":"a1b2c3d4","x":8,"y":62,"w":84,"text":"가을 할인",…}],"mobile":null}` | 이미지 위 글 상자(이미지형만). 상자의 `text` 는 요청 언어 → 사이트 기본 언어로 고른 문자열 하나이고 글이 빈 상자는 뺍니다. `mobile` 은 모바일 이미지가 있고 따로 배치했을 때만 배열(아니면 null — 휴대폰도 PC 배치). 상자가 없으면 null |
| body_html | string | `<p>…</p>` | 요청 언어의 정화된 본문 HTML(직접 작성형만, 이미지형은 빈 문자열) |

## 공개 목록의 예약 갱신

공개 응답 `data.server_time`은 응답 생성 시각, `data.refresh_at`은 다음 예약 시작 또는 종료 다음 초(ISO 8601, 없으면 null)입니다. 팝업이 0건이어도 제공합니다. 봇 응답은 타이머 정보 없이 빈 목록입니다. 사용자 레이어는 두 시각의 차이로 타이머 하나를 두고 경계에서 다시 받습니다. 숨긴 탭은 타이머를 멈추고 복귀 때 다시 받으며, 짧은 연속 이벤트·진행 중 요청은 합칩니다. 닫기·보지 않기 기록은 재조회로 지우지 않습니다. 새로 생긴 예약은 다음 탭 복귀·SPA 조회에서 알게 됩니다(지속 polling 없음).

직접 작성형 본문은 등록·수정 모두 정화 후 실제 텍스트 또는 src 이미지가 필요합니다. 기본 언어는 필수이고 선택 언어를 문자열 공백으로 비워 두는 정책과 비활성 언어의 저장된 번역 보존은 유지합니다.
