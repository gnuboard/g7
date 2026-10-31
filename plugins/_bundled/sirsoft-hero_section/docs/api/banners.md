# Banners API 레퍼런스

> **소유**: plugin `sirsoft-hero_section` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Banners 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-hero_section/admin/banners
<!-- @generated:start:api.plugins.sirsoft-hero_section.admin.banners.index -->
- **라우트명**: `api.plugins.sirsoft-hero_section.admin.banners.index`
- **컨트롤러**: `Plugins\Sirsoft\HeroSection\Http\Controllers\Admin\BannerController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-hero_section.banners.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-hero_section/admin/banners?position=%EC%98%88%EC%8B%9C%EA%B0%92&status=%EC%98%88%EC%8B%9C%EA%B0%92&search=%EC%98%88%EC%8B%9C%EA%B0%92&sort_by=%EC%98%88%EC%8B%9C%EA%B0%92&sort_order=%EC%98%88%EC%8B%9C%EA%B0%92&page=1&per_page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data` 객체 안에 행 배열(`data`)·`abilities`·`statistics`·`pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| data[] | array | `[{"id":659,"position":"home_hero","is_active":true,"statu…` | 배너 목록(쪽 단위) |
| data[].id | integer | `659` | 배너 식별자 |
| data[].position | string | `home_hero` | 노출 위치 키(`home_hero`·`shop_hero`) — 확장점 이름과 같다 |
| data[].is_active | boolean | `true` | 사용 여부 |
| data[].status | string | `active` | 지금 시각 기준 상태 `active`(노출중)·`scheduled`(예약)·`ended`(종료)·`inactive`(비활성) — 저장하지 않고 조회 때 계산 |
| data[].sort_order | integer | `0` | 위치 안 순서(0부터) |
| data[].display_period | string | `always` | `always`(상시)·`range`(기간 지정) |
| data[].starts_at_display | string\|null | `null` | 노출 시작(사이트 시간대 표시 문자열, 상시면 null) |
| data[].ends_at_display | string\|null | `null` | 노출 종료(사이트 시간대 표시 문자열, 상시면 null) |
| data[].title | object | `{"ko":"가을 신상품","en":"Autumn arrivals"}` | 언어별 제목 원본 |
| data[].title_localized | string | `Autumn arrivals` | 요청 언어로 고른 제목(없으면 기본 언어) |
| data[].title_locales | array | `["ko","en"]` | 제목이 입력된 언어 목록 |
| data[].created_at_display | string | `2026-10-26 10:20:17` | 등록일(사이트 시간대) |
| data[].thumbnail | object\|null | `{"id":717,"hash":"FCm6p4tnL4qnOmwOHdqbgaoxqNZQmUaowSsWIoj…` | PC 이미지(업로드 응답과 같은 모양, 없으면 null) |
| data[].has_mobile_image | boolean | `false` | 모바일 이미지가 있는지 — 없으면 사이트가 PC 이미지를 세로 비율로 잘라 쓴다 |
| data[].button_without_link | boolean | `false` | 버튼 문구가 어느 언어에든 있는데 이동 주소가 없는지 — 그러면 사이트에 버튼이 보이지 않는다 |
| data[].abilities | object | `{"can_update":true,"can_delete":true}` | 이 배너에 대한 `can_update`·`can_delete` |
| data[].thumbnail.device | string | `pc` | 기기(썸네일은 늘 `pc`) |
| data[].thumbnail.url | string | `/api/plugins/sirsoft-hero_section/ima…` | 공개 이미지 주소(인증 불필요) |
| data[].thumbnail.width | integer | `1920` | 올릴 때 잰 가로(px) — 권장 크기 안내·화면 밀림 방지에 쓴다 |
| data[].thumbnail.height | integer | `640` | 올릴 때 잰 세로(px) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 목록 단추용 `can_create`·`can_update`·`can_delete` |
| statistics.positions | object | `{"home_hero":2,"shop_hero":0}` | 위치별 배너 수(탭 건수) |
| statistics.statuses | object | `{"active":1,"scheduled":1,"ended":0,"inactive":0}` | 이 위치의 상태별 배너 수 |
| pagination | object | `{"current_page":1,"last_page":1,"per_page":20,"total":2,"…` | `current_page`·`last_page`·`per_page`·`total`·`from`·`to`·`has_more_pages` |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "배너를 불러왔습니다.",
    "data": {
        "data": [
            {
                "id": 87,
                "position": "home_hero",
                "is_active": true,
                "status": "active",
                "sort_order": 0,
                "display_period": "always",
                "starts_at_display": null,
                "ends_at_display": null,
                "title": {
                    "ko": "이미지 저장 반영 확인"
                },
                "title_localized": "이미지 저장 반영 확인",
                "title_locales": [
                    "ko"
                ],
                "created_at_display": "2026-10-26 16:14:10",
                "thumbnail": {
                    "id": 102,
                    "hash": "VL0wvz5dgQSGOtSArT8MjqCgmpHx74avhuuXZwJM2R4LThwPUzoKCGYnyg6yApRW",
                    "device": "pc",
                    "url": "/api/plugins/sirsoft-hero_section/images/VL0wvz5dgQSGOtSArT8MjqCgmpHx74avhuuXZwJM2R4LThwPUzoKCGYnyg6yApRW",
                    "download_url": "/api/plugins/sirsoft-hero_section/images/VL0wvz5dgQSGOtSArT8MjqCgmpHx74avhuuXZwJM2R4LThwPUzoKCGYnyg6yApRW",
                    "original_filename": "pc_green.png",
                    "mime_type": "image/png",
                    "size": 5206,
                    "size_formatted": "5.08 KB",
                    "width": 1920,
                    "height": 640,
                    "order": 0,
                    "is_image": true
                },
                "abilities": {
                    "can_update": true,
                    "can_delete": true
                }
            },
            {
                "id": 161,
                "position": "home_hero",
                "is_active": false,
                "status": "inactive",
                "sort_order": 1,
                "display_period": "always",
                "starts_at_display": null,
                "ends_at_display": null,
                "title": {
                    "ko": "배너 편집 미리보기",
                    "en": "Editor improvement preview",
                    "ja": "編集改善プレビュー"
                },
                "title_localized": "배너 편집 미리보기",
                "title_locales": [
                    "ko",
                    "en",
                    "ja"
                ],
                "created_at_display": "2026-10-27 11:16:40",
                "thumbnail": {
                    "id": 182,
                    "hash": "K4uE5ZicA9zso2Ea42LrN7fCW744OEd0f8Hep9yoeB2JFwLcVsiFA6FAV2eTdZEG",
                    "device": "pc",
                    "url": "/api/plugins/sirsoft-hero_section/images/K4uE5ZicA9zso2Ea42LrN7fCW744OEd0f8Hep9yoeB2JFwLcVsiFA6FAV2eTdZEG",
                    "download_url": "/api/plugins/sirsoft-hero_section/images/K4uE5ZicA9zso2Ea42LrN7fCW744OEd0f8Hep9yoeB2JFwLcVsiFA6FAV2eTdZEG",
                    "original_filename": "issue810-focus.png",
                    "mime_type": "image/png",
                    "size": 26242,
                    "size_formatted": "25.63 KB",
                    "width": 1920,
                    "height": 640,
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
            "positions": {
                "home_hero": 2,
                "shop_hero": 0
            },
            "statuses": {
                "active": 1,
                "scheduled": 0,
                "ended": 0,
                "inactive": 1
            }
        },
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 25,
            "total": 2,
            "from": 1,
            "to": 2,
            "has_more_pages": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-hero_section.banners.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**

관리자 목록. 쿼리 `position`(기본 `home_hero`)·`status`(active·scheduled·ended·inactive)·`search`(제목, 모든 번역 언어)·`sort_by`(sort_order·created_at·status)·`sort_order`·`page`·`per_page`(1~100). 상태 필터·정렬은 SQL 에서 기간 조건으로 처리해 총건수·쪽 수가 정확하다. 응답에 `statistics{positions{home_hero,shop_hero}, statuses{active,scheduled,ended,inactive}}` 와 목록 단추용 `abilities{can_create,can_update,can_delete}`, 행마다 `status`·`thumbnail`·`title_localized`·`title_locales`·기간 표시 문자열(사이트 시간대)·`abilities`.


### POST /api/plugins/sirsoft-hero_section/admin/banners
<!-- @generated:start:api.plugins.sirsoft-hero_section.admin.banners.store -->
- **라우트명**: `api.plugins.sirsoft-hero_section.admin.banners.store`
- **컨트롤러**: `Plugins\Sirsoft\HeroSection\Http\Controllers\Admin\BannerController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-hero_section.banners.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/plugins/sirsoft-hero_section/admin/banners HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: 만든 배너 상세(조회 응답과 같은 모양)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `659` | 배너 식별자 |
| position | string | `home_hero` | 노출 위치 키(`home_hero`·`shop_hero`) — 확장점 이름과 같다 |
| is_active | boolean | `true` | 사용 여부 |
| status | string | `active` | 지금 시각 기준 상태 `active`(노출중)·`scheduled`(예약)·`ended`(종료)·`inactive`(비활성) — 저장하지 않고 조회 때 계산 |
| sort_order | integer | `0` | 위치 안 순서(0부터) |
| display_period | string | `always` | `always`(상시)·`range`(기간 지정) |
| starts_at_display | string\|null | `null` | 노출 시작(사이트 시간대 표시 문자열, 상시면 null) |
| ends_at_display | string\|null | `null` | 노출 종료(사이트 시간대 표시 문자열, 상시면 null) |
| title | object | `{"ko":"가을 신상품","en":"Autumn arrivals"}` | 언어별 제목 원본 |
| title_localized | string | `Autumn arrivals` | 요청 언어로 고른 제목(없으면 기본 언어) |
| title_locales | array | `["ko","en"]` | 제목이 입력된 언어 목록 |
| created_at_display | string | `2026-10-26 10:20:17` | 등록일(사이트 시간대) |
| description | object\|null | `{"ko":"최대 30% 할인"}` | 언어별 설명 원본(없으면 null) |
| button_text | object\|null | `{"ko":"보러 가기"}` | 언어별 버튼 문구 원본(없으면 null — 배너 전체가 링크) |
| image_alt | object\|null | `{"ko":"가을 신상품 배너"}` | 언어별 대체 텍스트 원본(없으면 null) |
| title_visible | boolean | `true` | 제목 표시 여부 |
| title_color | string | `#FFFFFF` | 제목 색 `#RRGGBB` |
| title_align | string | `left` | 제목 정렬 `left`·`center`·`right` |
| description_visible | boolean | `true` | 설명 표시 여부 |
| description_color | string | `#E5E7EB` | 설명 색 `#RRGGBB` |
| description_align | string | `left` | 설명 정렬 |
| link_url | string\|null | `/shop` | 이동할 주소(사이트 경로 또는 http(s), 없으면 null) |
| link_new_window | boolean | `false` | 새 창 열기 |
| starts_at_local | string\|null | `null` | 노출 시작 — 수정 화면 입력값(사이트 시간대 `Y-m-d\TH:i`, 상시면 null) |
| ends_at_local | string\|null | `null` | 노출 종료 — 수정 화면 입력값 |
| images | object | `{"pc":{"id":717,"hash":"FCm6p4tnL4qnOmwOHdqbgaoxqNZQmUaow…` | `pc`·`mobile` 이미지(업로드 응답과 같은 모양, 모바일 없으면 null) |
| images.pc.id | integer | `717` | 이미지 식별자 |
| images.pc.hash | string | `FCm6p4tnL4qnOmwOHdqbgaoxqNZQmUaowSsWI…` | 공개 주소에 쓰는 64자 해시(불변) |
| images.pc.device | string | `pc` | 기기 `pc`·`mobile` |
| images.pc.url | string | `/api/plugins/sirsoft-hero_section/ima…` | 공개 이미지 주소(인증 불필요) |
| images.pc.download_url | string | `/api/plugins/sirsoft-hero_section/ima…` | `url` 과 같음 — 관리자 업로더가 미리보기로 읽는 자리 |
| images.pc.original_filename | string | `pc.jpg` | 올린 파일의 원래 이름 |
| images.pc.mime_type | string | `image/jpeg` | 파일 형식 |
| images.pc.size | integer | `19891` | 바이트 크기 |
| images.pc.size_formatted | string | `19.42 KB` | 사람이 읽는 크기 |
| images.pc.width | integer | `1920` | 올릴 때 잰 가로(px) — 권장 크기 안내·화면 밀림 방지에 쓴다 |
| images.pc.height | integer | `640` | 올릴 때 잰 세로(px) |
| images.pc.order | integer | `0` | 첨부 순서(배너당 기기별 1장이라 늘 0) |
| images.pc.is_image | boolean | `true` | 이미지 여부(늘 true) |
| abilities | object | `{"can_update":true,"can_delete":true}` | `can_update`·`can_delete` |

**응답 예시**

```json
{
    "success": true,
    "message": "배너를 등록했습니다.",
    "data": {
        "id": 659,
        "position": "home_hero",
        "is_active": true,
        "status": "active",
        "sort_order": 0,
        "display_period": "always",
        "starts_at_display": null,
        "ends_at_display": null,
        "title": {
            "ko": "가을 신상품",
            "en": "Autumn arrivals"
        },
        "title_localized": "Autumn arrivals",
        "title_locales": [
            "ko",
            "en"
        ],
        "created_at_display": "2026-10-26 10:20:17",
        "description": {
            "ko": "최대 30% 할인"
        },
        "button_text": {
            "ko": "보러 가기"
        },
        "image_alt": {
            "ko": "가을 신상품 배너"
        },
        "title_visible": true,
        "title_color": "#FFFFFF",
        "title_align": "left",
        "description_visible": true,
        "description_color": "#E5E7EB",
        "description_align": "left",
        "link_url": "/shop",
        "link_new_window": false,
        "starts_at_local": null,
        "ends_at_local": null,
        "images": {
            "pc": {
                "id": 717,
                "hash": "FCm6p4tnL4qnOmwOHdqbgaoxqNZQmUaowSsWIojRq0Hbf3t9vVw7Pf9nTlEdxvDs",
                "device": "pc",
                "url": "/api/plugins/sirsoft-hero_section/images/FCm6p4tnL4qnOmwOHdqbgaoxqNZQmUaowSsWIojRq0Hbf3t9vVw7Pf9nTlEdxvDs",
                "download_url": "/api/plugins/sirsoft-hero_section/images/FCm6p4tnL4qnOmwOHdqbgaoxqNZQmUaowSsWIojRq0Hbf3t9vVw7Pf9nTlEdxvDs",
                "original_filename": "pc.jpg",
                "mime_type": "image/jpeg",
                "size": 19891,
                "size_formatted": "19.42 KB",
                "width": 1920,
                "height": 640,
                "order": 0,
                "is_image": true
            },
            "mobile": {
                "id": 718,
                "hash": "KrjUXDR79043RZki6CwxzyVVcNhNiK0x0nZwHKc77Yn3otSpwRUUf3zdWm1A3Wkt",
                "device": "mobile",
                "url": "/api/plugins/sirsoft-hero_section/images/KrjUXDR79043RZki6CwxzyVVcNhNiK0x0nZwHKc77Yn3otSpwRUUf3zdWm1A3Wkt",
                "download_url": "/api/plugins/sirsoft-hero_section/images/KrjUXDR79043RZki6CwxzyVVcNhNiK0x0nZwHKc77Yn3otSpwRUUf3zdWm1A3Wkt",
                "original_filename": "m.jpg",
                "mime_type": "image/jpeg",
                "size": 12535,
                "size_formatted": "12.24 KB",
                "width": 750,
                "height": 1000,
                "order": 0,
                "is_image": true
            }
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
| 403 | Forbidden | 요구 권한(`sirsoft-hero_section.banners.update`)이 없는 경우 |

<!-- @generated:end -->

**설명**

배너 등록. 먼저 `POST /admin/banner-images` 로 같은 `temp_key` 에 PC 이미지(필수)·모바일 이미지(선택)를 올린 뒤 부른다. 본문: position, is_active, display_period(always·range), starts_at·ends_at(range 일 때 필수, 사이트 시간대 `Y-m-d\TH:i`, 종료 > 시작), title(사이트 기본 언어 필수)·description·button_text·image_alt(다국어 객체), title/description_visible·color(#RRGGBB)·align(left·center·right), link_url(`/…` 또는 http(s), `//`·`/\` 시작과 다른 스킴 거부), link_new_window, temp_key, (선택) media_type(`image` 기본·`youtube`·`video`), youtube_url(`youtube` 일 때 필수 — 주소창·공유·쇼츠·임베드 주소 또는 영상 ID, 그 밖의 호스트는 422. 저장은 영상 ID 만 `youtube_id` 로), pc_video_id·mobile_video_id(`video` 일 때 — 같은 묶음의 영상 ID, PC 영상 필수: 없으면 `pc_video` 422), (선택) pc_image_id·mobile_image_id — 같은 `temp_key` 묶음의 이미지 ID. PC 이미지는 어느 미디어 종류든 대표 이미지로 필수다. 보낸 기기는 그 이미지만 붙고(`mobile_image_id: null` 이면 모바일 없음), 보내지 않은 기기는 묶음의 가장 최근 이미지가 붙는다 — 기기마다 한 장. 다른 묶음·다른 기기 이미지는 422(「고른 이미지를 찾을 수 없습니다」). 붙지 않은 임시 이미지는 매일 정리 명령이 지운다. 위치 맨 뒤 순서로 저장된다. 훅 before/after_create 발행.


### DELETE /api/plugins/sirsoft-hero_section/admin/banners/bulk
<!-- @generated:start:api.plugins.sirsoft-hero_section.admin.banners.bulk-destroy -->
- **라우트명**: `api.plugins.sirsoft-hero_section.admin.banners.bulk-destroy`
- **컨트롤러**: `Plugins\Sirsoft\HeroSection\Http\Controllers\Admin\BannerController@bulkDestroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-hero_section.banners.delete`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
DELETE /api/plugins/sirsoft-hero_section/admin/banners/bulk HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: 처리 건수._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| count | integer | `2` | 삭제한 배너 수(이미지 파일 포함) |

**응답 예시**

```json
{
    "success": true,
    "message": "배너 2개를 삭제했습니다.",
    "data": {
        "count": 2
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-hero_section.banners.delete`)이 없는 경우 |

<!-- @generated:end -->

**설명**

고른 배너(`ids` 1~100개)를 **영구 삭제**한다 — 배너마다 PC·모바일 이미지를 떼어 낸 뒤 배너를 지우고, 이어서 이미지 파일·행을 지운다(파일 삭제가 실패한 이미지는 임시 이미지로 남아 매일 정리 명령이 다시 지운다). 활동 로그는 배너마다 한 줄. 되돌릴 수 없으므로 잠시 내리려면 일괄 비노출을 쓴다.


### PATCH /api/plugins/sirsoft-hero_section/admin/banners/bulk-status
<!-- @generated:start:api.plugins.sirsoft-hero_section.admin.banners.bulk-status -->
- **라우트명**: `api.plugins.sirsoft-hero_section.admin.banners.bulk-status`
- **컨트롤러**: `Plugins\Sirsoft\HeroSection\Http\Controllers\Admin\BannerController@bulkStatus`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-hero_section.banners.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
PATCH /api/plugins/sirsoft-hero_section/admin/banners/bulk-status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: 처리 건수._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| count | integer | `1` | 노출 여부를 바꾼 배너 수 |

**응답 예시**

```json
{
    "success": true,
    "message": "배너 1개의 노출 여부를 바꿨습니다.",
    "data": {
        "count": 1
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-hero_section.banners.update`)이 없는 경우 |

<!-- @generated:end -->

**설명**

고른 배너(`ids` 1~100개)의 사용 여부(`is_active`)를 바꾼다(노출·비노출). 활동 로그는 배너마다 한 줄, 관련 위치의 공개 캐시는 즉시 비워진다. 응답 `data.count` = 바뀐 건수.


### GET /api/plugins/sirsoft-hero_section/admin/banners/order
<!-- @generated:start:api.plugins.sirsoft-hero_section.admin.banners.order -->
- **라우트명**: `api.plugins.sirsoft-hero_section.admin.banners.order`
- **컨트롤러**: `Plugins\Sirsoft\HeroSection\Http\Controllers\Admin\BannerController@orderList`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-hero_section.banners.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-hero_section/admin/banners/order HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: [순서 변경] 창이 쓰는 위치 전체 목록._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| data[] | array | `[{"id":659,"position":"home_hero","is_active":true,"statu…` | 그 위치의 배너 전부(순서대로, 쪽 나눔 없음) — 목록 행과 같은 모양 |
| data[].id | integer | `659` | 배너 식별자 |
| data[].status | string | `active` | 지금 시각 기준 상태 `active`(노출중)·`scheduled`(예약)·`ended`(종료)·`inactive`(비활성) — 저장하지 않고 조회 때 계산 |
| data[].sort_order | integer | `0` | 위치 안 순서(0부터) |
| data[].title_localized | string | `Autumn arrivals` | 요청 언어로 고른 제목(없으면 기본 언어) |
| data[].thumbnail | object\|null | `{"id":717,"hash":"FCm6p4tnL4qnOmwOHdqbgaoxqNZQmUaowSsWIoj…` | PC 이미지(업로드 응답과 같은 모양, 없으면 null) |
| data[].has_mobile_image | boolean | `false` | 모바일 이미지가 있는지 — 없으면 사이트가 PC 이미지를 세로 비율로 잘라 쓴다 |
| data[].button_without_link | boolean | `false` | 버튼 문구가 어느 언어에든 있는데 이동 주소가 없는지 — 그러면 사이트에 버튼이 보이지 않는다 |

**응답 예시**

```json
{
    "success": true,
    "message": "배너를 불러왔습니다.",
    "data": {
        "data": [
            {
                "id": 659,
                "position": "home_hero",
                "is_active": true,
                "status": "active",
                "sort_order": 0,
                "display_period": "always",
                "starts_at_display": null,
                "ends_at_display": null,
                "title": {
                    "ko": "가을 신상품",
                    "en": "Autumn arrivals"
                },
                "title_localized": "Autumn arrivals",
                "title_locales": [
                    "ko",
                    "en"
                ],
                "created_at_display": "2026-10-26 10:20:17",
                "thumbnail": {
                    "id": 717,
                    "hash": "FCm6p4tnL4qnOmwOHdqbgaoxqNZQmUaowSsWIojRq0Hbf3t9vVw7Pf9nTlEdxvDs",
                    "device": "pc",
                    "url": "/api/plugins/sirsoft-hero_section/images/FCm6p4tnL4qnOmwOHdqbgaoxqNZQmUaowSsWIojRq0Hbf3t9vVw7Pf9nTlEdxvDs",
                    "download_url": "/api/plugins/sirsoft-hero_section/images/FCm6p4tnL4qnOmwOHdqbgaoxqNZQmUaowSsWIojRq0Hbf3t9vVw7Pf9nTlEdxvDs",
                    "original_filename": "pc.jpg",
                    "mime_type": "image/jpeg",
                    "size": 19891,
                    "size_formatted": "19.42 KB",
                    "width": 1920,
                    "height": 640,
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
| 403 | Forbidden | 요구 권한(`sirsoft-hero_section.banners.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**

[순서 변경] 창용 — 쿼리 `position` 의 배너 **전체**를 현재 순서대로 돌려준다(썸네일·제목·상태 포함, 쪽 나눔 없음).


### PUT /api/plugins/sirsoft-hero_section/admin/banners/order
<!-- @generated:start:api.plugins.sirsoft-hero_section.admin.banners.reorder -->
- **라우트명**: `api.plugins.sirsoft-hero_section.admin.banners.reorder`
- **컨트롤러**: `Plugins\Sirsoft\HeroSection\Http\Controllers\Admin\BannerController@reorder`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-hero_section.banners.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
PUT /api/plugins/sirsoft-hero_section/admin/banners/order HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_본문 없음: `data` 는 `null` 이다. 성공 메시지만 돌려준다._

**응답 예시**

```json
{
    "success": true,
    "message": "배너 순서를 바꿨습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-hero_section.banners.update`)이 없는 경우 |

<!-- @generated:end -->

**설명**

위치의 순서를 바꾼다. 본문 `position` + `ids`(그 위치 배너 **전체**를 새 순서로). 빠짐·중복·다른 위치 id 가 섞이면 422 — 일부만 보내 순서 값이 겹치는 것을 막는다. sort_order 를 0 부터 다시 매기고, 활동 로그는 위치 1건, 공개 캐시는 즉시 비워진다.


### GET /api/plugins/sirsoft-hero_section/admin/banners/preview
<!-- @generated:start:api.plugins.sirsoft-hero_section.admin.banners.preview -->
- **라우트명**: `api.plugins.sirsoft-hero_section.admin.banners.preview`
- **컨트롤러**: `Plugins\Sirsoft\HeroSection\Http\Controllers\Admin\BannerController@preview`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-hero_section.banners.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-hero_section/admin/banners/preview HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data` 에 노출 중 배너만._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| data[] | array | `[{"id":659,"position":"home_hero","is_active":true,"statu…` | 그 위치에서 지금 노출 중인 배너(순서대로) — 목록 행과 같은 모양 |
| data[].id | integer | `659` | 배너 식별자 |
| data[].status | string | `active` | 지금 시각 기준 상태 `active`(노출중)·`scheduled`(예약)·`ended`(종료)·`inactive`(비활성) — 저장하지 않고 조회 때 계산 |
| data[].sort_order | integer | `0` | 위치 안 순서(0부터) |
| data[].title_localized | string | `Autumn arrivals` | 요청 언어로 고른 제목(없으면 기본 언어) |

**응답 예시**

```json
{
    "success": true,
    "message": "배너를 불러왔습니다.",
    "data": {
        "data": [
            {
                "id": 659,
                "position": "home_hero",
                "is_active": true,
                "status": "active",
                "sort_order": 0,
                "display_period": "always",
                "starts_at_display": null,
                "ends_at_display": null,
                "title": {
                    "ko": "가을 신상품",
                    "en": "Autumn arrivals"
                },
                "title_localized": "Autumn arrivals",
                "title_locales": [
                    "ko",
                    "en"
                ],
                "created_at_display": "2026-10-26 10:20:17",
                "description": {
                    "ko": "최대 30% 할인"
                },
                "button_text": {
                    "ko": "보러 가기"
                },
                "image_alt": {
                    "ko": "가을 신상품 배너"
                },
                "title_visible": true,
                "title_color": "#FFFFFF",
                "title_align": "left",
                "description_visible": true,
                "description_color": "#E5E7EB",
                "description_align": "left",
                "link_url": "/shop",
                "link_new_window": false,
                "starts_at_local": null,
                "ends_at_local": null,
                "images": {
                    "pc": {
                        "id": 717,
                        "hash": "FCm6p4tnL4qnOmwOHdqbgaoxqNZQmUaowSsWIojRq0Hbf3t9vVw7Pf9nTlEdxvDs",
                        "device": "pc",
                        "url": "/api/plugins/sirsoft-hero_section/images/FCm6p4tnL4qnOmwOHdqbgaoxqNZQmUaowSsWIojRq0Hbf3t9vVw7Pf9nTlEdxvDs",
                        "download_url": "/api/plugins/sirsoft-hero_section/images/FCm6p4tnL4qnOmwOHdqbgaoxqNZQmUaowSsWIojRq0Hbf3t9vVw7Pf9nTlEdxvDs",
                        "original_filename": "pc.jpg",
                        "mime_type": "image/jpeg",
                        "size": 19891,
                        "size_formatted": "19.42 KB",
                        "width": 1920,
                        "height": 640,
                        "order": 0,
                        "is_image": true
                    },
                    "mobile": {
                        "id": 718,
                        "hash": "KrjUXDR79043RZki6CwxzyVVcNhNiK0x0nZwHKc77Yn3otSpwRUUf3zdWm1A3Wkt",
                        "device": "mobile",
                        "url": "/api/plugins/sirsoft-hero_section/images/KrjUXDR79043RZki6CwxzyVVcNhNiK0x0nZwHKc77Yn3otSpwRUUf3zdWm1A3Wkt",
                        "download_url": "/api/plugins/sirsoft-hero_section/images/KrjUXDR79043RZki6CwxzyVVcNhNiK0x0nZwHKc77Yn3otSpwRUUf3zdWm1A3Wkt",
                        "original_filename": "m.jpg",
                        "mime_type": "image/jpeg",
                        "size": 12535,
                        "size_formatted": "12.24 KB",
                        "width": 750,
                        "height": 1000,
                        "order": 0,
                        "is_image": true
                    }
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
| 403 | Forbidden | 요구 권한(`sirsoft-hero_section.banners.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**

목록 상단 미리보기용 — 쿼리 `position` 의 **지금 노출 중인** 배너를 공개 API 와 같은 판정·순서로 돌려준다(관리자 상세 모양).


### DELETE /api/plugins/sirsoft-hero_section/admin/banners/{banner}
<!-- @generated:start:api.plugins.sirsoft-hero_section.admin.banners.destroy -->
- **라우트명**: `api.plugins.sirsoft-hero_section.admin.banners.destroy`
- **컨트롤러**: `Plugins\Sirsoft\HeroSection\Http\Controllers\Admin\BannerController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-hero_section.banners.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| banner | path | string | 예 | — | 대상 banner의 식별자 |

**요청 예시**

```http
DELETE /api/plugins/sirsoft-hero_section/admin/banners/{banner} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_본문 없음: `data` 는 `null` 이다. 배너와 PC·모바일 이미지 파일을 바로 지운다._

**응답 예시**

```json
{
    "success": true,
    "message": "배너를 삭제했습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-hero_section.banners.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**

배너를 **영구 삭제**한다 — PC·모바일 이미지를 떼어 낸 뒤 배너를 지우고, 이어서 이미지 파일·행을 지운다(파일 삭제가 실패한 이미지는 임시 이미지로 남아 매일 정리 명령이 다시 지운다). 활동 로그는 남는다. 훅 before/after_delete 발행.


### GET /api/plugins/sirsoft-hero_section/admin/banners/{banner}
<!-- @generated:start:api.plugins.sirsoft-hero_section.admin.banners.show -->
- **라우트명**: `api.plugins.sirsoft-hero_section.admin.banners.show`
- **컨트롤러**: `Plugins\Sirsoft\HeroSection\Http\Controllers\Admin\BannerController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-hero_section.banners.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| banner | path | string | 예 | — | 대상 banner의 식별자 |

**요청 예시**

```http
GET /api/plugins/sirsoft-hero_section/admin/banners/{banner} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: 수정 화면용 상세(다국어 원본·이미지 2종)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `659` | 배너 식별자 |
| position | string | `home_hero` | 노출 위치 키(`home_hero`·`shop_hero`) — 확장점 이름과 같다 |
| is_active | boolean | `true` | 사용 여부 |
| status | string | `active` | 지금 시각 기준 상태 `active`(노출중)·`scheduled`(예약)·`ended`(종료)·`inactive`(비활성) — 저장하지 않고 조회 때 계산 |
| sort_order | integer | `0` | 위치 안 순서(0부터) |
| display_period | string | `always` | `always`(상시)·`range`(기간 지정) |
| starts_at_display | string\|null | `null` | 노출 시작(사이트 시간대 표시 문자열, 상시면 null) |
| ends_at_display | string\|null | `null` | 노출 종료(사이트 시간대 표시 문자열, 상시면 null) |
| title | object | `{"ko":"가을 신상품","en":"Autumn arrivals"}` | 언어별 제목 원본 |
| title_localized | string | `Autumn arrivals` | 요청 언어로 고른 제목(없으면 기본 언어) |
| title_locales | array | `["ko","en"]` | 제목이 입력된 언어 목록 |
| created_at_display | string | `2026-10-26 10:20:17` | 등록일(사이트 시간대) |
| description | object\|null | `{"ko":"최대 30% 할인"}` | 언어별 설명 원본(없으면 null) |
| button_text | object\|null | `{"ko":"보러 가기"}` | 언어별 버튼 문구 원본(없으면 null — 배너 전체가 링크) |
| image_alt | object\|null | `{"ko":"가을 신상품 배너"}` | 언어별 대체 텍스트 원본(없으면 null) |
| title_visible | boolean | `true` | 제목 표시 여부 |
| title_color | string | `#FFFFFF` | 제목 색 `#RRGGBB` |
| title_align | string | `left` | 제목 정렬 `left`·`center`·`right` |
| description_visible | boolean | `true` | 설명 표시 여부 |
| description_color | string | `#E5E7EB` | 설명 색 `#RRGGBB` |
| description_align | string | `left` | 설명 정렬 |
| link_url | string\|null | `/shop` | 이동할 주소(사이트 경로 또는 http(s), 없으면 null) |
| link_new_window | boolean | `false` | 새 창 열기 |
| starts_at_local | string\|null | `null` | 노출 시작 — 수정 화면 입력값(사이트 시간대 `Y-m-d\TH:i`, 상시면 null) |
| ends_at_local | string\|null | `null` | 노출 종료 — 수정 화면 입력값 |
| images | object | `{"pc":{"id":717,"hash":"FCm6p4tnL4qnOmwOHdqbgaoxqNZQmUaow…` | `pc`·`mobile` 이미지(업로드 응답과 같은 모양, 모바일 없으면 null) |
| images.pc.id | integer | `717` | 이미지 식별자 |
| images.pc.hash | string | `FCm6p4tnL4qnOmwOHdqbgaoxqNZQmUaowSsWI…` | 공개 주소에 쓰는 64자 해시(불변) |
| images.pc.device | string | `pc` | 기기 `pc`·`mobile` |
| images.pc.url | string | `/api/plugins/sirsoft-hero_section/ima…` | 공개 이미지 주소(인증 불필요) |
| images.pc.download_url | string | `/api/plugins/sirsoft-hero_section/ima…` | `url` 과 같음 — 관리자 업로더가 미리보기로 읽는 자리 |
| images.pc.original_filename | string | `pc.jpg` | 올린 파일의 원래 이름 |
| images.pc.mime_type | string | `image/jpeg` | 파일 형식 |
| images.pc.size | integer | `19891` | 바이트 크기 |
| images.pc.size_formatted | string | `19.42 KB` | 사람이 읽는 크기 |
| images.pc.width | integer | `1920` | 올릴 때 잰 가로(px) — 권장 크기 안내·화면 밀림 방지에 쓴다 |
| images.pc.height | integer | `640` | 올릴 때 잰 세로(px) |
| images.pc.order | integer | `0` | 첨부 순서(배너당 기기별 1장이라 늘 0) |
| images.pc.is_image | boolean | `true` | 이미지 여부(늘 true) |
| abilities | object | `{"can_update":true,"can_delete":true}` | `can_update`·`can_delete` |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "배너를 불러왔습니다.",
    "data": {
        "id": 87,
        "position": "home_hero",
        "is_active": true,
        "status": "active",
        "sort_order": 0,
        "display_period": "always",
        "starts_at_display": null,
        "ends_at_display": null,
        "title": {
            "ko": "이미지 저장 반영 확인"
        },
        "title_localized": "이미지 저장 반영 확인",
        "title_locales": [
            "ko"
        ],
        "created_at_display": "2026-10-26 16:14:10",
        "description": {
            "ko": "수정 화면에서 이미지를 바꾸고 취소·저장을 확인하세요"
        },
        "presentation": {
            "pc_x": 50,
            "pc_y": 50,
            "mobile_x": 50,
            "mobile_y": 50,
            "overlay": "none"
        },
        "revision": 0,
        "button_text": null,
        "image_alt": null,
        "title_visible": true,
        "title_color": "#FFFFFF",
        "title_align": "left",
        "description_visible": true,
        "description_color": "#E5E7EB",
        "description_align": "left",
        "link_url": "/",
        "link_new_window": false,
        "starts_at_local": null,
        "ends_at_local": null,
        "images": {
            "pc": {
                "id": 102,
                "hash": "VL0wvz5dgQSGOtSArT8MjqCgmpHx74avhuuXZwJM2R4LThwPUzoKCGYnyg6yApRW",
                "device": "pc",
                "url": "/api/plugins/sirsoft-hero_section/images/VL0wvz5dgQSGOtSArT8MjqCgmpHx74avhuuXZwJM2R4LThwPUzoKCGYnyg6yApRW",
                "download_url": "/api/plugins/sirsoft-hero_section/images/VL0wvz5dgQSGOtSArT8MjqCgmpHx74avhuuXZwJM2R4LThwPUzoKCGYnyg6yApRW",
                "original_filename": "pc_green.png",
                "mime_type": "image/png",
                "size": 5206,
                "size_formatted": "5.08 KB",
                "width": 1920,
                "height": 640,
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
| 403 | Forbidden | 요구 권한(`sirsoft-hero_section.banners.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**

수정 화면용 상세 — 다국어 원본(title·description·button_text·image_alt), 문구 표시·색·정렬, 링크, 기간(`starts_at_local`·`ends_at_local` = 사이트 시간대 datetime-local 입력값, `*_display` = 표시 문자열), 이미지 2종(`images.pc`·`images.mobile`, 업로드 응답과 같은 모양 — 관리자 업로더 첨부 필드 + url·device·가로·세로), `abilities`. 작성자 id 는 싣지 않는다.


### PUT /api/plugins/sirsoft-hero_section/admin/banners/{banner}
<!-- @generated:start:api.plugins.sirsoft-hero_section.admin.banners.update -->
- **라우트명**: `api.plugins.sirsoft-hero_section.admin.banners.update`
- **컨트롤러**: `Plugins\Sirsoft\HeroSection\Http\Controllers\Admin\BannerController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-hero_section.banners.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| banner | path | string | 예 | — | 대상 banner의 식별자 |

**요청 예시**

```http
PUT /api/plugins/sirsoft-hero_section/admin/banners/{banner} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: 고친 배너 상세(조회 응답과 같은 모양)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `659` | 배너 식별자 |
| position | string | `home_hero` | 노출 위치 키(`home_hero`·`shop_hero`) — 확장점 이름과 같다 |
| is_active | boolean | `true` | 사용 여부 |
| status | string | `active` | 지금 시각 기준 상태 `active`(노출중)·`scheduled`(예약)·`ended`(종료)·`inactive`(비활성) — 저장하지 않고 조회 때 계산 |
| sort_order | integer | `0` | 위치 안 순서(0부터) |
| display_period | string | `always` | `always`(상시)·`range`(기간 지정) |
| starts_at_display | string\|null | `null` | 노출 시작(사이트 시간대 표시 문자열, 상시면 null) |
| ends_at_display | string\|null | `null` | 노출 종료(사이트 시간대 표시 문자열, 상시면 null) |
| title | object | `{"ko":"가을 신상품","en":"Autumn arrivals"}` | 언어별 제목 원본 |
| title_localized | string | `Autumn arrivals` | 요청 언어로 고른 제목(없으면 기본 언어) |
| title_locales | array | `["ko","en"]` | 제목이 입력된 언어 목록 |
| created_at_display | string | `2026-10-26 10:20:17` | 등록일(사이트 시간대) |
| description | object\|null | `{"ko":"최대 30% 할인"}` | 언어별 설명 원본(없으면 null) |
| button_text | object\|null | `{"ko":"보러 가기"}` | 언어별 버튼 문구 원본(없으면 null — 배너 전체가 링크) |
| image_alt | object\|null | `{"ko":"가을 신상품 배너"}` | 언어별 대체 텍스트 원본(없으면 null) |
| title_visible | boolean | `true` | 제목 표시 여부 |
| title_color | string | `#FFFFFF` | 제목 색 `#RRGGBB` |
| title_align | string | `left` | 제목 정렬 `left`·`center`·`right` |
| description_visible | boolean | `true` | 설명 표시 여부 |
| description_color | string | `#E5E7EB` | 설명 색 `#RRGGBB` |
| description_align | string | `left` | 설명 정렬 |
| link_url | string\|null | `/shop` | 이동할 주소(사이트 경로 또는 http(s), 없으면 null) |
| link_new_window | boolean | `false` | 새 창 열기 |
| starts_at_local | string\|null | `null` | 노출 시작 — 수정 화면 입력값(사이트 시간대 `Y-m-d\TH:i`, 상시면 null) |
| ends_at_local | string\|null | `null` | 노출 종료 — 수정 화면 입력값 |
| images | object | `{"pc":{"id":717,"hash":"FCm6p4tnL4qnOmwOHdqbgaoxqNZQmUaow…` | `pc`·`mobile` 이미지(업로드 응답과 같은 모양, 모바일 없으면 null) |
| images.pc.id | integer | `717` | 이미지 식별자 |
| images.pc.hash | string | `FCm6p4tnL4qnOmwOHdqbgaoxqNZQmUaowSsWI…` | 공개 주소에 쓰는 64자 해시(불변) |
| images.pc.device | string | `pc` | 기기 `pc`·`mobile` |
| images.pc.url | string | `/api/plugins/sirsoft-hero_section/ima…` | 공개 이미지 주소(인증 불필요) |
| images.pc.download_url | string | `/api/plugins/sirsoft-hero_section/ima…` | `url` 과 같음 — 관리자 업로더가 미리보기로 읽는 자리 |
| images.pc.original_filename | string | `pc.jpg` | 올린 파일의 원래 이름 |
| images.pc.mime_type | string | `image/jpeg` | 파일 형식 |
| images.pc.size | integer | `19891` | 바이트 크기 |
| images.pc.size_formatted | string | `19.42 KB` | 사람이 읽는 크기 |
| images.pc.width | integer | `1920` | 올릴 때 잰 가로(px) — 권장 크기 안내·화면 밀림 방지에 쓴다 |
| images.pc.height | integer | `640` | 올릴 때 잰 세로(px) |
| images.pc.order | integer | `0` | 첨부 순서(배너당 기기별 1장이라 늘 0) |
| images.pc.is_image | boolean | `true` | 이미지 여부(늘 true) |
| abilities | object | `{"can_update":true,"can_delete":true}` | `can_update`·`can_delete` |

**응답 예시**

```json
{
    "success": true,
    "message": "배너를 수정했습니다.",
    "data": {
        "id": 659,
        "position": "home_hero",
        "is_active": true,
        "status": "active",
        "sort_order": 0,
        "display_period": "always",
        "starts_at_display": null,
        "ends_at_display": null,
        "title": {
            "ko": "가을 신상품 기획전",
            "en": "Autumn Arrivals"
        },
        "title_localized": "Autumn Arrivals",
        "title_locales": [
            "ko",
            "en"
        ],
        "created_at_display": "2026-10-26 10:20:17",
        "description": {
            "ko": "최대 30% 할인"
        },
        "button_text": {
            "ko": "보러 가기"
        },
        "image_alt": {
            "ko": "가을 신상품 배너"
        },
        "title_visible": true,
        "title_color": "#FFFFFF",
        "title_align": "left",
        "description_visible": true,
        "description_color": "#E5E7EB",
        "description_align": "left",
        "link_url": "/shop",
        "link_new_window": false,
        "starts_at_local": null,
        "ends_at_local": null,
        "images": {
            "pc": {
                "id": 717,
                "hash": "FCm6p4tnL4qnOmwOHdqbgaoxqNZQmUaowSsWIojRq0Hbf3t9vVw7Pf9nTlEdxvDs",
                "device": "pc",
                "url": "/api/plugins/sirsoft-hero_section/images/FCm6p4tnL4qnOmwOHdqbgaoxqNZQmUaowSsWIojRq0Hbf3t9vVw7Pf9nTlEdxvDs",
                "download_url": "/api/plugins/sirsoft-hero_section/images/FCm6p4tnL4qnOmwOHdqbgaoxqNZQmUaowSsWIojRq0Hbf3t9vVw7Pf9nTlEdxvDs",
                "original_filename": "pc.jpg",
                "mime_type": "image/jpeg",
                "size": 19891,
                "size_formatted": "19.42 KB",
                "width": 1920,
                "height": 640,
                "order": 0,
                "is_image": true
            },
            "mobile": {
                "id": 718,
                "hash": "KrjUXDR79043RZki6CwxzyVVcNhNiK0x0nZwHKc77Yn3otSpwRUUf3zdWm1A3Wkt",
                "device": "mobile",
                "url": "/api/plugins/sirsoft-hero_section/images/KrjUXDR79043RZki6CwxzyVVcNhNiK0x0nZwHKc77Yn3otSpwRUUf3zdWm1A3Wkt",
                "download_url": "/api/plugins/sirsoft-hero_section/images/KrjUXDR79043RZki6CwxzyVVcNhNiK0x0nZwHKc77Yn3otSpwRUUf3zdWm1A3Wkt",
                "original_filename": "m.jpg",
                "mime_type": "image/jpeg",
                "size": 12535,
                "size_formatted": "12.24 KB",
                "width": 750,
                "height": 1000,
                "order": 0,
                "is_image": true
            }
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
| 403 | Forbidden | 요구 권한(`sirsoft-hero_section.banners.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**

배너 수정 — 등록과 같은 규칙에 이미지 선택을 더한다. `media_type` 을 보내지 않으면 저장된 종류 그대로다. 영상 선택(`pc_video_id`·`mobile_video_id`)을 보내지 않으면 붙은 영상을 그대로 두고, `null` 로 보내면 떼어 파일까지 지운다(관리자 화면은 종류가 영상이 아니면 둘 다 `null` 을 보낸다). 유튜브가 아닌 종류로 저장하면 `youtube_id` 를 지운다. 응답에 `media_type`·`youtube_id`·`youtube_url`(보기 주소)·`videos{pc,mobile}`(업로드 응답 모양)을 더한다. 위치를 바꾸면 새 위치 맨 뒤로 가고 옛·새 위치 공개 캐시가 모두 비워진다.

이미지는 이 저장 때만 바뀐다. 수정 화면은 `POST /admin/banner-images` 로 임시 묶음(`temp_key`)에 올려 두고, 저장 때 화면에 남은 이미지 ID 를 보낸다.

| 필드 | 필수 | 설명 |
| --- | --- | --- |
| temp_key | 아니오 | 이 화면의 임시 묶음 키(최대 64자). 새로 올린 이미지를 고를 때 필요 |
| pc_image_id | 아니오 | 남길 PC 이미지 ID — 지금 배너의 PC 이미지 또는 `temp_key` 묶음에 올린 PC 이미지. 보내면 null 불가(PC 필수). 보내지 않으면 그대로 |
| mobile_image_id | 아니오 | 남길 모바일 이미지 ID — 지금 배너의 모바일 이미지 또는 `temp_key` 묶음의 모바일 이미지. `null` 이면 모바일 이미지를 뺀다. 보내지 않으면 그대로 |

다른 배너의 이미지, 다른 임시 묶음의 이미지, 기기가 다른 이미지는 422(`pc_image_id`·`mobile_image_id`, 「고른 이미지를 찾을 수 없습니다」). `pc_image_id` 를 보내지 않았는데 배너에 PC 이미지가 없으면 422(`pc_image`). 바뀐 기기마다 옛 이미지는 배너에서 떼어 파일·행을 지우고 `sirsoft-hero_section.banner_image.after_change` 를 발행한다(활동 로그 `hero_banner.image_update`·`hero_banner.image_remove`). 옛 파일 삭제가 실패해도 저장은 성공하고, 떼어 낸 이미지는 임시 이미지로 남아 매일 정리 명령(`prune-temp-images`)이 다시 지운다.


## 편집 표시 설정과 수정 충돌

등록·수정 본문에 선택적 `presentation` 객체를 보낼 수 있습니다. `pc_x`, `pc_y`, `mobile_x`, `mobile_y`는 0~100 정수 초점 좌표이며, `overlay`는 `none`, `dark`, `light`입니다. 등록 시 생략하거나 null이면 중앙 초점과 배경 없음을 사용합니다. 수정 시 생략하면 기존 설정을 유지하고 null이면 기본값으로 되돌립니다. 상세 및 공개 hero 응답에 같은 표시 설정을 돌려줍니다.

수정 화면은 상세 응답의 정수 `revision`을 PUT 본문에 그대로 보냅니다. 서버는 트랜잭션 안에서 최신 부모 행을 잠그고 비교해, 다른 수정이나 일괄 상태 변경이 먼저 저장되었으면 409를 반환합니다. 충돌 응답은 기존 배너·이미지를 바꾸지 않습니다. 기존 API 호출의 revision 생략은 호환되지만, 사용자 편집 화면에서는 항상 보내야 합니다. 이미지 검증 전에 확인 가능한 오래된 세대도 먼저 409로 안내합니다.
