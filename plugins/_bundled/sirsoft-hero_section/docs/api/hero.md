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

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| position | query | string | 예 | 등록된 위치 키 | 위치 키(확장점 이름) — 위치 등록 필터가 모은 목록 |
| target | query | string | 동적 위치면 예 | max 200 | 동적 위치의 화면 값(게시판·카테고리 slug, 상품 코드, 또는 id). slug·상품 코드를 먼저 찾고 없을 때만 숫자 id 로 찾는다(숫자 slug 가 다른 대상의 id 와 겹치면 slug 쪽). 대소문자는 모듈 화면과 같이 모듈 저장소 조회를 따른다. 정적 위치는 쓰지 않는다 |

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
| target | string\|null | `free` | 해석에 쓴 화면 값 — 받은 값에서 앞뒤 공백만 걷는다(정적 위치는 null) — 조각이 SPA 이동 뒤 도착한 앞 대상 응답을 가려낸다 |
| target_key | string\|null | `2` | 화면 값을 푼 대상 키(정적 위치·풀지 못한 값은 null) |
| settings | object | `{"autoplay":true,"interval_seconds":7,"effect":"fade","width":"content"}` | 그 위치 슬라이더 설정(`width`: `content` 본문 폭·`full` 화면 전체, `header_overlay`, `pagination`: `dots`·`progress`·`tabs`·`fraction`·`pill`, `max_height_pc`·`max_height_mobile`: 기기별 최대 높이 px 또는 `null`) |
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
| banners[].images.pc | object | `{"url":"/api/plugins/sirsoft-hero_section/images/FCm6p4tn…` | PC 이미지 `url`·`direct_url`·`width`·`height`. `url` 은 사이트 마운트 접두사 포함(하위 폴더 설치면 `/g7/api/plugins/…`, 영상 `videos.*.url` 도 같다) |
| banners[].images.pc.direct_url | string\|null | `null` | 원본 직접 주소 — 공개 자산 디스크(public·CDN)에 올린 행만 채워지고 접두사 없는 절대 주소다. 슬라이더는 `<img src>`·srcset 의 원본 후보에만 쓰고 축소 후보는 `url?size=` 다. 영상 `videos.*.direct_url` 도 같고, 있으면 그 주소로 재생한다 |
| abilities.can_update | boolean | `false` | 요청한 회원이 배너를 수정할 수 있는지(`sirsoft-hero_section.banners.update`). 로그인하지 않았으면 false. 공개 캐시에 담지 않고 요청마다 판정한다 |
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
        ],
        "abilities": {
            "can_update": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 422 | Validation Error | `position` 이 없거나 등록되지 않은 위치(「등록되지 않은 노출 위치입니다…」), 동적 위치인데 `target` 이 없음. 모르는 대상 값은 422 가 아니라 빈 목록이다 |
| 429 | Too Many Requests | 같은 주소에서 분당 600회 초과 |

<!-- @generated:end -->

**설명**

사용자 템플릿 확장점(`home_hero`·`shop_hero`, 동적 위치 `board_hero`·`product_hero`·`category_hero` 와 확장이 더한 위치) 조각이 부르는 공개 API. 쿼리 `position`(필수, 등록된 위치)과 동적 위치면 `target`(화면 값 — 서버가 대상 해석기로 id 로 푼다)의 **지금 노출 중인** 배너를 순서대로, 요청 언어(Accept-Language)로 고른 문구와 함께 돌려준다 — 요청 언어 → 사이트 기본 언어 → en 순 대체, alt 가 비면 제목. 응답 `data` = `{position, settings{autoplay, interval_seconds, effect, width, header_overlay, pagination}, banners[{id, title, description, button_text, image_alt, title_visible·color·align, description_visible·color·align, link_url, link_new_window, images{pc{url,direct_url,width,height}, mobile|null}, media_type, videos{pc{url,direct_url,mime}, mobile|null}|null}]}`. `videos` 는 `media_type` 이 `video` 일 때만 싣는다(종류를 바꿔도 남은 값이 사이트에 나가지 않게). `images.pc` 는 영상 배너에서도 대표 이미지다. 운영 정보(사용 여부·기간·작성자)는 싣지 않는다. 위치(동적이면 위치 + 대상)·언어별로 캐시하되 다음 시작·종료 시각을 넘기지 않고, 꺼낼 때 다시 판정한다 — 배너를 고치면 그 대상 캐시만, 위치 슬라이더 설정을 고치면 그 위치의 모든 대상 캐시가 비워진다. 화면 값을 푼 결과도 캐시 시간 동안 기억한다(공개 요청마다 대상 테이블을 묻지 않는다). 풀지 못한 값은 기억하지 않는다 — 아무 값이나 보내 캐시를 부풀릴 수 없고, 나중에 생긴 대상은 바로 풀린다. 게시판·상품·카테고리가 생기거나 바뀌거나 지워지면(모듈 훅) 그 위치의 해석 기억을 버려, 지운 게시판의 slug 를 새 게시판이 써도 지운 게시판 배너가 나오지 않는다. 확장이 더한 동적 위치는 해석 기억이 캐시 시간만큼 남는다. 대상에 배너가 없거나 화면 값을 풀지 못하면(모르는 slug·지워진 대상) 빈 `banners` 다. 등록되지 않은 위치(위치를 더한 확장을 껐을 때)는 422 이고 그 배너는 나가지 않는다. 분당 600회 제한.


## 이미지 표시

인증은 선택이다 — 로그인하지 않아도 받고, 토큰을 실어 보내면 `abilities.can_update` 만 그 회원 기준으로 바뀐다(`optional.sanctum`). 배너·설정은 방문자 공용 캐시에서 나오고 권한은 그 밖에서 붙인다.

`banners[].presentation`에는 기기별 초점 좌표(pc_x, pc_y, mobile_x, mobile_y; 0~100 정수)와 문구 배경(overlay: none/dark/light)이 들어갑니다. 기존 배너는 중앙 초점 50과 배경 없음으로 응답합니다. 문구 자리(`text_pc_x`·`text_pc_y`·`text_mobile_x`·`text_mobile_y`, 0~100)·문구 상자 폭(`text_width` %)·등장 효과(`animation`·`animation_speed`·`animation_delay`)·바탕 밝기(`tone` dark/light)도 같은 객체에 있고, 저장하지 않은 배너는 왼쪽 아래·전폭·효과 없음·어두움(종전 화면)입니다. 머리글은 `banners[].eyebrow`(요청 언어 문구, 없으면 빈 문자열)·`eyebrow_visible`·`eyebrow_color` 입니다. `settings.header_overlay`(boolean)는 위치 설정의 메뉴 겹치기이며, 사용자 슬라이더는 `width` 가 `full` 일 때만 이 값을 씁니다. `settings.pagination` 은 페이징 모양(`dots` 점 기본·`progress` 진행 막대 점·`tabs` 배너 이름 탭·`fraction` 번호)입니다. 사용자 슬라이더와 관리자 미리보기는 이 값을 같은 방식으로 사용합니다.

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
