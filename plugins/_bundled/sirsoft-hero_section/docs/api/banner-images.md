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
| url | string | `/api/plugins/sirsoft-hero_section/ima…` | 공개 이미지 주소(인증 불필요). 사이트 마운트 접두사 포함 — 하위 폴더 설치(`/g7/`)면 `/g7/api/plugins/…` |
| download_url | string | `/api/plugins/sirsoft-hero_section/ima…` | 원본 한 장의 주소 — 공개 자산 디스크(public·CDN) 행이면 `direct_url`, 아니면 `url` 과 같음(#878) |
| preview_url | string | `/api/plugins/sirsoft-hero_section/ima…` | 썸네일 기준 공개 API 주소(`url` 과 같음) — 관리자 업로더·목록 미리보기가 `?size=` 를 붙여 받는다 |
| direct_url | string\|null | `null` | 행 disk 의 직접 주소(공개 자산 디스크 행만, 사이트 마운트 접두사 없는 절대 주소). 아니면 null |
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

이미지·영상 업로드(multipart). 필드 `kind`(선택, `image` 기본·`video`), `file`(이미지: JPG·PNG·WEBP, 가로·세로 4000px 이하 / 영상: MP4·WEBM — 확장자와 파일 내용 둘 다로 판정), `device`(pc·mobile), `temp_key`(필수 — 등록·수정 화면이 들어올 때 만든 임시 묶음 키). 업로드는 언제나 임시 묶음에 들어가고 배너에는 등록(`POST /admin/banners`)·수정(`PUT /admin/banners/{banner}`)을 저장할 때만 붙는다 — 올리기만 해서는 사이트 화면이 바뀌지 않는다. 최대 용량 = min(플러그인 설정 `max_upload_mb`(영상은 `max_video_upload_mb`), PHP `upload_max_filesize`, `post_max_size`) — PHP 한도를 넘어 파일이 비어 들어오면 「서버 업로드 한도」 안내로 422. 같은 임시 묶음의 같은 기기·종류 파일은 교체된다(이전 파일 삭제 — PC 이미지와 PC 영상은 서로 바꾸지 않는다). 영상은 가로·세로를 재지 않고(`width`·`height` null) 응답 `kind: "video"`, `is_image: false` 다. 권장 크기(PC 1920×640, 모바일 750×1000)는 강제하지 않고 응답 width·height 로 화면이 안내한다. 응답 이미지는 관리자 업로더(`file-uploader`)의 첨부 모양(`id`·`hash`·`original_filename`·`mime_type`·`size`·`size_formatted`·`download_url`·`order`·`is_image`)에 `url`·`preview_url`·`direct_url`·`device`·`width`·`height` 를 더한 것이고, 배너 상세의 `images.pc`·`images.mobile`·목록의 `thumbnail` 도 같은 모양이다.


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

_본문 없음: `data` 는 `null` 이다. 아직 배너에 붙지 않은 임시 이미지만 지울 수 있다(배너에 붙은 이미지는 422)._

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

아직 배너에 붙지 않은 임시 이미지를 지운다(파일 포함). 배너에 붙은 이미지(PC·모바일)는 422 — 배너 수정 저장의 `pc_image_id`·`mobile_image_id` 로만 바꾸거나 뺀다. 관리자 화면은 이 주소를 부르지 않는다 — 등록·수정 모두 화면에서만 빼고, 저장이 남은 이미지 ID 만 붙인다(붙지 않은 임시 이미지는 매일 정리 명령이 지운다).


### 공개 자산 디스크와 주소 필드 (#878)

플러그인 설정 `public_asset_disk`(비우면 코어 공개 자산 디스크)가 public·CDN 디스크면 새로 올리는 파일이 그 디스크에 저장되고 행 `disk` 에 기록된다. `url`·`preview_url` 은 언제나 공개 API 주소(같은 출처 — 썸네일 `?size=`·관리 목록 미리보기·캔버스 색 추출의 기준)이고, `direct_url` 은 행 disk 가 직접 주소를 낼 때만 채워진다. `download_url` 은 `direct_url ?? url` 이다. 관리자 업로더는 `preview_url` 을 우선해 썸네일을 그리고, 교차 출처 `download_url` 은 인증 요청 없이 그대로 쓴다.
