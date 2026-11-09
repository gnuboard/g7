# Content Media API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 상품 설명·상품 공통 정보 편집기 본문에 넣는 이미지·동영상·오디오(본문 미디어) 엔드포인트입니다 (1.3.4)
2. 편집기는 업로드 계약(GET admin/content-media/target)을 받아 그 주소로 올립니다 — 응답 url 은 서명 주소
3. 저장 때 본문이 참조한 것만 상품·공통 정보에 이어지고, 빠진 것은 지워집니다
4. 서빙(GET content-media/{hash}): 상품은 「전시 중 또는 관리자 또는 서명」, 공통 정보는 공개. Range 지원
5. 계약 형식은 코어 문서 docs/extension/content-media.md
```

---

### GET /api/modules/sirsoft-ecommerce/admin/content-media/target
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.content-media.target -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.content-media.target`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ContentMediaController@target`
- **인증/권한**: `auth:sanctum` + `admin` + 상품·공통 정보 등록·수정 중 하나(OR). 소유 종류별 권한은 컨트롤러가 다시 본다(아니면 403)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| owner_type | query | string | 예 | `product`·`common_info` | 계약을 받을 편집기 (상품 설명·공통 정보) |

**응답**: `data` = 업로드 계약(`version`·`endpoint`·`fileField`·`fields.owner_type`·`headers`·`response`·`mediaUrlPattern`·`policy`), 받는 종류가 없으면 `null`.
<!-- @generated:end -->

---

### POST /api/modules/sirsoft-ecommerce/admin/content-media
<!-- @generated:start:api.modules.sirsoft-ecommerce.admin.content-media.upload -->
- **라우트명**: `api.modules.sirsoft-ecommerce.admin.content-media.upload`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Admin\ContentMediaController@upload`
- **인증/권한**: `auth:sanctum` + `admin` + 상품·공통 정보 등록·수정 중 하나(OR), 소유 종류별 재판정 · `throttle:60,1`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| owner_type | body | string | 예 | `product`·`common_info` | 소유 종류 |
| file | body | file | 예 | 본문 미디어 정책 | 본문에 넣을 파일. 형식은 파일 내용으로 판정 |
| temp_key | body | string | 예 | UUID | 편집 화면 임시 키 — 저장 요청의 `content_media_temp_key` 와 같아야 이어진다 |

**응답 필드** (`data` 내부): `data.id` · `data.hash`(12자) · `data.kind`(`image`·`video`·`audio`) · `data.url`(서명된 서빙 주소)

- 상태: `201` · `403` 소유 종류 권한 없음 · `422` 정책 밖 파일·임시 키 없음·같은 임시 키 업로드가 문서당 최대 × 2 초과(`errors.code = content_media_limit_exceeded`)
<!-- @generated:end -->

---

### GET /api/modules/sirsoft-ecommerce/content-media/{hash}
<!-- @generated:start:api.modules.sirsoft-ecommerce.content-media.show -->
- **라우트명**: `api.modules.sirsoft-ecommerce.content-media.show`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\ContentMediaController@show`
- **인증/권한**: `optional.sanctum` · `throttle:600,1` — 권한 미들웨어 없음(`<img>`·`<video>` 는 Bearer 없음)

**요청 파라미터**: `hash`(path, `[A-Za-z0-9]{12}`) · `expires`·`signature`(query, 선택) · `Range`(header, 한 구간)

**게이트**: 서명이 유효하면 통과. 서명이 없으면 임시 행 403, 상품은 전시 중(visible)이거나 상품 읽기 권한 관리자면 통과, 공통 정보는 공개.

**응답**: 파일 본문. 래스터 이미지는 inline, 동영상·오디오는 정책·코어 후보가 켜졌을 때 inline + `media-src 'self'` CSP, 그 밖에는 다운로드. `nosniff`·`Accept-Ranges: bytes`.

- 상태: `200`·`206` · `403` · `404` · `416`
<!-- @generated:end -->
