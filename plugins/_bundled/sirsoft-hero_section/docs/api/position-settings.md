# Position Settings API 레퍼런스

> **소유**: plugin `sirsoft-hero_section` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Position Settings 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-hero_section/admin/position-settings
<!-- @generated:start:api.plugins.sirsoft-hero_section.admin.position-settings.index -->
- **라우트명**: `api.plugins.sirsoft-hero_section.admin.position-settings.index`
- **컨트롤러**: `Plugins\Sirsoft\HeroSection\Http\Controllers\Admin\PositionSettingController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-hero_section.banners.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-hero_section/admin/position-settings HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: 위치 목록·위치별 슬라이더 설정·업로드 한도._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| positions[] | array | `[{"key":"home_hero","extension_point":"home_hero","label_…` | 위치 목록(고정 2개) |
| positions[].key | string | `home_hero` | 위치 키 |
| positions[].extension_point | string | `home_hero` | 템플릿 확장점 이름(위치 키와 같음) |
| positions[].label_key | string | `sirsoft-hero_section.admin.position.h…` | 화면 이름 번역 키 |
| positions[].settings | object | `{"autoplay":true,"interval_seconds":5,"effect":"slide","width":"content"}` | `autoplay`·`interval_seconds`(3·5·7)·`effect`(`slide`·`fade`·`cube`·`flip`·`coverflow`·`creative`·`none`)·`width`(`content`·`full`), 저장 전이면 기본값 |
| upload.max_mb | integer | `5` | 플러그인 설정의 이미지 최대 용량(MB) |
| upload.server_limit_mb | integer | `2` | PHP `upload_max_filesize`·`post_max_size` 중 작은 값(MB) |
| upload.effective_mb | integer | `2` | 실제로 받을 수 있는 크기 = 둘 중 작은 값 — 업로더 `maxSize`·안내에 쓴다 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "노출 위치 설정을 불러왔습니다.",
    "data": {
        "positions": [
            {
                "key": "home_hero",
                "extension_point": "home_hero",
                "label_key": "sirsoft-hero_section.admin.position.home_hero",
                "settings": {
                    "autoplay": true,
                    "interval_seconds": 5,
                    "effect": "slide"
                }
            },
            {
                "key": "shop_hero",
                "extension_point": "shop_hero",
                "label_key": "sirsoft-hero_section.admin.position.shop_hero",
                "settings": {
                    "autoplay": true,
                    "interval_seconds": 5,
                    "effect": "slide"
                }
            }
        ],
        "upload": {
            "max_mb": 5,
            "server_limit_mb": 2,
            "effective_mb": 2
        },
        "editor": {
            "timezone": "Asia/Seoul",
            "server_now": "2026-10-27T03:51:29+00:00",
            "default_locale": "ko",
            "locales": [
                "ko",
                "en",
                "ja"
            ],
            "focus_limits": {
                "min": 0,
                "max": 100
            }
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

위치 목록(`key` = 확장점 이름 `home_hero`·`shop_hero`, `label_key`)과 위치별 슬라이더 설정(저장 행이 없으면 기본값 autoplay=true·5초·slide·본문 폭 content), 업로드 한도 `upload{max_mb, server_limit_mb, effective_mb}` — 플러그인 설정 API 권한이 없는 배너 담당자도 화면 안내에 쓸 수 있게 여기서 준다.

`editor.preview_frame` 은 편집 미리보기의 구좌 틀이다 — `{template, stylesheets[], positions{home_hero|shop_hero: {layout, path, chain[{className, responsive{키: className}}], content{className, responsive{desktop: className}} | null} | null}}`. 활성 사용자 템플릿에서 그 확장점을 가진 레이아웃(상속 병합본)을 찾아 루트부터 확장점까지의 상자 클래스(바인딩 `{{…}}` 토큰은 뺀 정적 클래스)와 반응형 덮어쓰기를 담고(덮어쓰기가 없으면 빈 배열 `[]`), `stylesheets` 는 그 템플릿의 `components.css` 주소(확장 캐시 버전 포함)다. 편집기 견본(`editor-spec.json`)은 버전이 바뀌는 주소 대신 빈 `stylesheets` 를 둔다. 확장점이 없는 위치는 null, 활성 사용자 템플릿이 없으면 `template: null`·`stylesheets: []` 이고 `positions` 의 각 위치가 null 이다. `content` 는 확장점이 넘긴 본문 상자 클래스다(속성 `contentClass` → `className`, `contentClassDesktop` → `responsive.desktop`, 바인딩 토큰은 뺀다) — 미리보기가 표시 폭이 본문 폭이면 슬라이더 바깥, 화면 전체면 문구 층에 이 상자를 쓴다. 확장점이 넘기지 않았으면 null 이다. 결과는 템플릿 식별자·확장 캐시 버전별로 1시간 캐시한다. `path` 는 그 레이아웃을 쓰는 활성 사용자 템플릿 라우트의 사이트 주소(모듈 주소 접두어를 푼 값)로, 목록의 「사이트에서 보기」가 쓴다 — 매개변수(`:slug`)·와일드카드가 든 주소이거나 라우트가 없으면 null 이고, 라우트 목록을 읽지 못하면 주소만 null 로 두고 오류 로그를 남긴다. 주소는 캐시 밖에서 매번 붙인다.

```json
"preview_frame": {
    "template": "sirsoft-basic",
    "stylesheets": ["/build/ext/{확장 캐시 버전}/templates/sirsoft-basic/assets/css/components.css"],
    "positions": {
        "home_hero": {
            "layout": "home",
            "path": "/",
            "chain": [
                { "className": "min-h-fit px-4", "responsive": { "desktop": "min-h-fit max-w-7xl mx-auto px-8" } }
            ]
        },
        "shop_hero": { "layout": "shop/index", "path": "/shop/products", "chain": [ … ] }
    }
}
```


### PUT /api/plugins/sirsoft-hero_section/admin/position-settings/{position}
<!-- @generated:start:api.plugins.sirsoft-hero_section.admin.position-settings.update -->
- **라우트명**: `api.plugins.sirsoft-hero_section.admin.position-settings.update`
- **컨트롤러**: `Plugins\Sirsoft\HeroSection\Http\Controllers\Admin\PositionSettingController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-hero_section.banners.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| position | path | string | 예 | — | 대상 position의 식별자 |

**요청 예시**

```http
PUT /api/plugins/sirsoft-hero_section/admin/position-settings/{position} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: 저장한 위치 설정._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| position | string | `home_hero` | 위치 키 |
| settings | object | `{"autoplay":true,"interval_seconds":7,"effect":"fade","width":"content"}` | 저장된 `autoplay`·`interval_seconds`·`effect`·`width` |

**응답 예시**

```json
{
    "success": true,
    "message": "슬라이더 설정을 저장했습니다.",
    "data": {
        "position": "home_hero",
        "settings": {
            "autoplay": true,
            "interval_seconds": 7,
            "effect": "fade"
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

위치 슬라이더 설정 저장 — `autoplay`(boolean), `interval_seconds`(3·5·7), `effect`(slide·fade·cube·flip·coverflow·creative·none), `width`(선택, `content` 본문 폭·`full` 화면 전체 — 빼면 지금 값을 유지하고 처음 저장이면 `content`), `header_overlay`(선택 boolean, 메뉴 겹치기 — 빼면 지금 값을 유지하고 처음 저장이면 `false`. 사이트에는 `full` 일 때만 적용되지만 저장은 받는다). 처음 저장하면 행을 만든다. 모르는 위치는 404. 그 위치 공개 캐시는 즉시 비워진다.


## 편집 화면 메타

관리자 위치 설정 GET 응답의 `editor`는 사이트 `timezone`, ISO 8601 `server_now`, `default_locale`, 활성 언어 코드 목록 `locales`를 제공합니다. 등록·수정 화면은 이 값을 미리보기 언어와 기간 상태 안내에 사용합니다. 플러그인 환경설정 관리 권한 없이 배너 조회 권한으로 받을 수 있습니다.
