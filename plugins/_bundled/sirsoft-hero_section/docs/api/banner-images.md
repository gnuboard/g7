# Banner Images API 레퍼런스

> **소유**: plugin `sirsoft-hero_section` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Banner Images 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /api/plugins/sirsoft-hero_section/admin/banner-images
<!-- @generated:start:api.plugins.sirsoft-hero_section.admin.banner-images.store -->
- **라우트명**: `api.plugins.sirsoft-hero_section.admin.banner-images.store`
- **컨트롤러**: `Plugins\Sirsoft\HeroSection\Http\Controllers\Admin\BannerImageController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-hero_section.banners.update`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
POST /api/plugins/sirsoft-hero_section/admin/banner-images HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: 올린 이미지(관리자 업로더 첨부 모양 + `url`·`device`·`width`·`height`)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `717` | 이미지 식별자 |
| hash | string | `FCm6p4tnL4qnOmwOHdqbgaoxqNZQmUaowSsWI…` | 공개 주소에 쓰는 64자 해시(불변) |
| device | string | `pc` | 기기 `pc`·`mobile` |
| url | string | `/api/plugins/sirsoft-hero_section/ima…` | 공개 이미지 주소(인증 불필요) |
| download_url | string | `/api/plugins/sirsoft-hero_section/ima…` | `url` 과 같음 — 관리자 업로더가 미리보기로 읽는 자리 |
| original_filename | string | `pc.jpg` | 올린 파일의 원래 이름 |
| mime_type | string | `image/jpeg` | 파일 형식 |
| size | integer | `19891` | 바이트 크기 |
| size_formatted | string | `19.42 KB` | 사람이 읽는 크기 |
| width | integer | `1920` | 올릴 때 잰 가로(px) — 권장 크기 안내·화면 밀림 방지에 쓴다 |
| height | integer | `640` | 올릴 때 잰 세로(px) |
| order | integer | `0` | 첨부 순서(배너당 기기별 1장이라 늘 0) |
| is_image | boolean | `true` | 이미지 여부(늘 true) |

**응답 예시**

```json
{
    "success": true,
    "message": "이미지를 올렸습니다.",
    "data": {
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

이미지 업로드(multipart). 필드 `file`(JPG·PNG·WEBP, 가로·세로 4000px 이하), `device`(pc·mobile), `temp_key`(등록 화면) 또는 `banner_id`(수정 화면) 중 하나. 최대 용량 = min(플러그인 설정 `max_upload_mb`, PHP `upload_max_filesize`, `post_max_size`) — PHP 한도를 넘어 파일이 비어 들어오면 「서버 업로드 한도」 안내로 422. 같은 소유자의 같은 기기 이미지는 교체된다(이전 파일 삭제). 권장 크기(PC 1920×640, 모바일 750×1000)는 강제하지 않고 응답 width·height 로 화면이 안내한다. 응답 이미지는 관리자 업로더(`file-uploader`)의 첨부 모양(`id`·`hash`·`original_filename`·`mime_type`·`size`·`size_formatted`·`download_url`·`order`·`is_image`)에 `url`·`device`·`width`·`height` 를 더한 것이고, 배너 상세의 `images.pc`·`images.mobile`·목록의 `thumbnail` 도 같은 모양이다.


### DELETE /api/plugins/sirsoft-hero_section/admin/banner-images/{image}
<!-- @generated:start:api.plugins.sirsoft-hero_section.admin.banner-images.destroy -->
- **라우트명**: `api.plugins.sirsoft-hero_section.admin.banner-images.destroy`
- **컨트롤러**: `Plugins\Sirsoft\HeroSection\Http\Controllers\Admin\BannerImageController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-hero_section.banners.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| image | path | string | 예 | — | 대상 image의 식별자 |

**요청 예시**

```http
DELETE /api/plugins/sirsoft-hero_section/admin/banner-images/{image} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_본문 없음: `data` 는 `null` 이다. 모바일 이미지만 지울 수 있다(PC 는 교체만 — 422)._

**응답 예시**

```json
{
    "success": true,
    "message": "이미지를 삭제했습니다.",
    "data": null
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

모바일 이미지를 지운다(파일 포함 — 모바일이 없으면 화면은 PC 이미지를 쓴다). PC 이미지는 필수라 422 — 새 이미지를 올려 교체한다.

