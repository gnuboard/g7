# Hero API 레퍼런스

> **소유**: plugin `sirsoft-hero_section` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Hero 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-hero_section/hero
<!-- @generated:start:api.plugins.sirsoft-hero_section.hero.show -->
- **라우트명**: `api.plugins.sirsoft-hero_section.hero.show`
- **컨트롤러**: `Plugins\Sirsoft\HeroSection\Http\Controllers\Public\HeroController@show`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-hero_section/hero HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_단건 응답: 위치·설정·노출 배너. 운영 정보(사용 여부·기간·작성자)는 싣지 않는다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| position | string | `home_hero` | 위치 키 |
| settings | object | `{"autoplay":true,"interval_seconds":7,"effect":"fade"}` | 그 위치 슬라이더 설정 |
| banners[] | array | `[{"id":659,"title":"Autumn Arrivals","description":"최대 30…` | 지금 노출 중인 배너(순서대로, 0건이면 빈 배열) |
| banners[].id | integer | `659` | 배너 식별자 |
| banners[].title | string | `Autumn Arrivals` | 요청 언어로 고른 제목(요청 언어 → 기본 언어 → en) |
| banners[].description | string | `최대 30% 할인` | 요청 언어 설명(없으면 빈 문자열) |
| banners[].button_text | string | `보러 가기` | 요청 언어 버튼 문구(빈 문자열이면 배너 전체가 링크) |
| banners[].image_alt | string | `가을 신상품 배너` | 대체 텍스트(요청 언어 alt → 기본 언어 alt → 제목) |
| banners[].title_visible | boolean | `true` | 제목 표시 여부 |
| banners[].title_color | string | `#FFFFFF` | 제목 색 `#RRGGBB` |
| banners[].title_align | string | `left` | 제목 정렬 `left`·`center`·`right` |
| banners[].description_visible | boolean | `true` | 설명 표시 여부 |
| banners[].description_color | string | `#E5E7EB` | 설명 색 `#RRGGBB` |
| banners[].description_align | string | `left` | 설명 정렬 |
| banners[].link_url | string\|null | `/shop` | 이동할 주소(사이트 경로 또는 http(s), 없으면 null) |
| banners[].link_new_window | boolean | `false` | 새 창 열기 |
| banners[].images.pc | object | `{"url":"/api/plugins/sirsoft-hero_section/images/FCm6p4tn…` | PC 이미지 `url`·`width`·`height` |
| banners[].images.mobile | object | `{"url":"/api/plugins/sirsoft-hero_section/images/KrjUXDR7…` | 모바일 이미지(없으면 null — PC 를 쓴다) |

**응답 예시**

```json
{
    "success": true,
    "message": "배너를 불러왔습니다.",
    "data": {
        "position": "home_hero",
        "settings": {
            "autoplay": true,
            "interval_seconds": 7,
            "effect": "fade"
        },
        "banners": [
            {
                "id": 659,
                "title": "Autumn Arrivals",
                "description": "최대 30% 할인",
                "button_text": "보러 가기",
                "image_alt": "가을 신상품 배너",
                "title_visible": true,
                "title_color": "#FFFFFF",
                "title_align": "left",
                "description_visible": true,
                "description_color": "#E5E7EB",
                "description_align": "left",
                "link_url": "/shop",
                "link_new_window": false,
                "images": {
                    "pc": {
                        "url": "/api/plugins/sirsoft-hero_section/images/FCm6p4tnL4qnOmwOHdqbgaoxqNZQmUaowSsWIojRq0Hbf3t9vVw7Pf9nTlEdxvDs",
                        "width": 1920,
                        "height": 640
                    },
                    "mobile": {
                        "url": "/api/plugins/sirsoft-hero_section/images/KrjUXDR79043RZki6CwxzyVVcNhNiK0x0nZwHKc77Yn3otSpwRUUf3zdWm1A3Wkt",
                        "width": 750,
                        "height": 1000
                    }
                }
            }
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Validation Error | `position` 이 없거나 `home_hero`·`shop_hero` 밖의 값 (`The selected position is invalid.`) |
| 429 | Too Many Requests | 같은 주소에서 분당 120회 초과 |

<!-- @generated:end -->

**설명**

사용자 템플릿 확장점(`home_hero`·`shop_hero`) 조각이 부르는 공개 API. 쿼리 `position`(필수, `home_hero`·`shop_hero`)의 **지금 노출 중인** 배너를 순서대로, 요청 언어(Accept-Language)로 고른 문구와 함께 돌려준다 — 요청 언어 → 사이트 기본 언어 → en 순 대체, alt 가 비면 제목. 응답 `data` = `{position, settings{autoplay, interval_seconds, effect}, banners[{id, title, description, button_text, image_alt, title_visible·color·align, description_visible·color·align, link_url, link_new_window, images{pc{url,width,height}, mobile|null}}]}`. 운영 정보(사용 여부·기간·작성자)는 싣지 않는다. 위치·언어별로 캐시하되 다음 시작·종료 시각을 넘기지 않고, 꺼낼 때 다시 판정한다. 모르는 위치는 422. 분당 120회 제한.


## 이미지 표시

`banners[].presentation`에는 기기별 초점 좌표(pc_x, pc_y, mobile_x, mobile_y; 0~100 정수)와 문구 배경(overlay: none/dark/light)이 들어갑니다. 기존 배너는 중앙 초점 50과 배경 없음으로 응답합니다. 사용자 슬라이더와 관리자 미리보기는 이 값을 같은 방식으로 사용합니다.

`GET /api/plugins/sirsoft-hero_section/hero?position=home_hero`에서 확인한 기존 배너의 표시 설정 예시(응답의 해당 부분):

```json
{
    "presentation": {
        "pc_x": 50,
        "pc_y": 50,
        "mobile_x": 50,
        "mobile_y": 50,
        "overlay": "none"
    }
}
```
