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
| positions[].settings | object | `{"autoplay":true,"interval_seconds":5,"effect":"slide"}` | `autoplay`·`interval_seconds`(3·5·7)·`effect`(`slide`·`fade`·`none`), 저장 전이면 기본값 |
| upload.max_mb | integer | `5` | 플러그인 설정의 이미지 최대 용량(MB) |
| upload.server_limit_mb | integer | `2` | PHP `upload_max_filesize`·`post_max_size` 중 작은 값(MB) |
| upload.effective_mb | integer | `2` | 실제로 받을 수 있는 크기 = 둘 중 작은 값 — 업로더 `maxSize`·안내에 쓴다 |

**응답 예시**

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

위치 목록(`key` = 확장점 이름 `home_hero`·`shop_hero`, `label_key`)과 위치별 슬라이더 설정(저장 행이 없으면 기본값 autoplay=true·5초·slide), 업로드 한도 `upload{max_mb, server_limit_mb, effective_mb}` — 플러그인 설정 API 권한이 없는 배너 담당자도 화면 안내에 쓸 수 있게 여기서 준다.


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
| settings | object | `{"autoplay":true,"interval_seconds":7,"effect":"fade"}` | 저장된 `autoplay`·`interval_seconds`·`effect` |

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

위치 슬라이더 설정 저장 — `autoplay`(boolean), `interval_seconds`(3·5·7), `effect`(slide·fade·none). 처음 저장하면 행을 만든다. 모르는 위치는 404. 그 위치 공개 캐시는 즉시 비워진다.

