# Product Image API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Product Image 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/product-image/{hash}
<!-- @generated:start:api.modules.sirsoft-ecommerce.product-image.download -->
- **라우트명**: `api.modules.sirsoft-ecommerce.product-image.download`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\ProductImageController@download`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| hash | path | string | 예 | — | 서빙할 상품 이미지의 URL용 고유 해시 (`ecommerce_product_images.hash`) |
| size | query | string | 아니오 | 사용 중인 썸네일 규격 이름 (`xs`·`sm`·`md`·`lg`·`xl`·운영자 규격·확장 규격) | 이 규격의 썸네일을 낸다. `w` 보다 우선한다. 미정의·꺼진 규격·배열(`size[]=`)·같은 키 두 번이면 원본을 내고 `X-G7-Thumbnail: bypass:unknown-preset` 을 붙인다. |
| w | query | integer | 아니오 | 1~10000 | 숫자 요청 대상 규격 중 가로가 이 값 이상인 가장 작은 규격으로 올려 맞춘다(없으면 가로가 가장 큰 규격). 정수가 아니면 원본 + `bypass:unknown-preset`. |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/product-image/{hash} HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON 봉투(`data`)를 반환하지 않습니다. 성공 시 이미지 원본 파일의 바이너리 스트림(`StreamedResponse`)을 직접 반환합니다._

| 응답 헤더 | 값 | 용도/설명 |
| --- | --- | --- |
| Content-Type | `image/jpeg` (레코드의 `mime_type` 값 그대로 — `image/png` \| `image/webp` \| `image/gif` 등) | 저장된 이미지의 MIME 타입 |
| Content-Disposition | `inline; filename=product-main.jpg` (레코드의 `original_filename`) | 래스터 이미지(JPEG·PNG·GIF·WebP 등)만 `inline`, 그 밖(SVG 포함)은 `attachment` + CSP sandbox. 모든 응답에 `X-Content-Type-Options: nosniff` (KVE-2026-2366) |
| X-Content-Type-Options | `nosniff` | 브라우저가 내용을 추측해 다른 형식으로 렌더하지 않게 한다 |
| X-G7-Thumbnail | `hit` · `miss` · `bypass:smaller-than-preset` | `size`·`w` 를 보낸 요청에만 붙는다. `hit`(이미 만든 썸네일)·`miss`(이번 요청에 만듦)·`bypass:{사유}`(원본을 냄 — 사유는 `disabled`·`no-engine`·`not-raster`·`animated`·`unknown-preset`·`smaller-than-preset`·`rate-limited`·`too-large`·`error`) |
| Cache-Control | `public, max-age=31536000, must-revalidate` (프로덕션) / `no-cache` (그 외) | 코어 파일 서빙 단일 지점이 환경에 따라 정하는 캐싱 정책 — `ETag`·`Last-Modified`·`Expires` 동반, 조건부 요청은 304. 썸네일을 요청했는데 원본을 낸 응답(`X-G7-Thumbnail: bypass:{사유}`)은 운영 환경에서 `max-age` 가 썸네일과 같은 코어 레이아웃 캐시 유지 시간(`cache.layout_ttl`, 1년 이하)으로 줄고, 생성 제한·일시 오류면 `no-store` 다 |

**응답 예시**

성공 시 JSON 이 아닌 이미지 바이너리가 스트리밍됩니다.

```http
HTTP/1.1 200 OK
Content-Type: image/jpeg
Content-Disposition: inline; filename=product-main.jpg
X-Content-Type-Options: nosniff
Cache-Control: public, max-age=31536000, must-revalidate
ETag: "9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d"
Last-Modified: Wed, 15 Jul 2026 00:00:00 GMT
Expires: Thu, 15 Jul 2027 00:00:00 GMT

<binary image data>
```

실패(404) 시에만 JSON 봉투가 반환됩니다.

```json
{
    "success": false,
    "message": "상품 이미지를 찾을 수 없습니다."
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 레코드는 있으나 스토리지에 실제 파일이 없어 스트림 생성에 실패한 경우 (`ProductImageService::download()` null — 서버 로그에 `상품 이미지 스토리지에 없음` 기록) |

<!-- @generated:end -->

**설명** 공개 API로, 해시(`hash`, 12자)로 식별되는 상품 이미지 원본 파일을 스트리밍 서빙합니다. 인증이 필요 없으며(`PublicBaseController`), `ProductImageController@download`가 먼저 `ProductImageService::findByHash()`로 이미지 레코드 존재를 확인한 뒤 `ProductImageService::download()`로 `StorageInterface::response()` 기반 `StreamedResponse`를 반환합니다. 응답에는 저장된 `mime_type`과 `Cache-Control: public, max-age=31536000`(1년) 헤더가 부여되어 브라우저/CDN 캐싱에 최적화됩니다. 해시에 해당하는 레코드가 없거나 스토리지에 실제 파일이 없으면 404 에러 응답을 반환합니다.

**캐시 계약** 응답은 코어 파일 서빙 단일 지점과 같은 규칙을 따릅니다 — 검증자 `ETag`(행 메타 `disk|path|updated_at|file_size` 의 MD5, 따옴표 포함)·`Last-Modified`(행의 `updated_at`)를 싣고, 요청의 `If-None-Match`(`-gzip` 접미사·다중값·`W/` 흡수) 또는 `If-Modified-Since` 가 일치하면 본문 없는 `304 Not Modified` 를 200 과 같은 `Cache-Control`·`Expires` 와 함께 돌려줍니다. `Cache-Control` 은 프로덕션에서 `public, max-age=31536000, must-revalidate`(자산 버전 `?v=` 가 없는 URL 이라 `immutable` 은 붙지 않음), 그 외 환경에서는 `no-cache` 입니다. 사용자 업로드물이라 응답 압축 대상에서 제외됩니다.

**썸네일** `?size={규격}` 또는 `?w={숫자}` 를 붙이면 래스터 이미지(JPEG·PNG·WebP 등)는 환경설정 > 업로드 탭의 썸네일 규격으로 줄인 사본을 냅니다. 첫 요청에 만들어 저장해 두고 다음부터는 저장본을 냅니다. 상품 이미지는 인증 없이 보이는 완전 공개 원본이라 썸네일도 공개 이미지 썸네일 저장 위치에 두며, 그 위치가 공개 자산 디스크(CDN 등)면 `302` 로 썸네일 직접 주소를 알려 줍니다(`Cache-Control: public, max-age={레이아웃 캐시 TTL}`). 저장 위치가 비공개 디스크(`thumbnails`, 기본)면 `200` 으로 썸네일 바이너리를 스트리밍하고 검증자(`ETag`·`Last-Modified`)는 썸네일 파일 기준이라 `304` 도 같은 규칙입니다. 규격보다 작은 원본(확대 끔)·움직이는 GIF·WebP·APNG(「움직이는 이미지는 원본 사용」이 켜져 있을 때)·SVG·꺼진 기능에서는 원본을 그대로 내고 `X-G7-Thumbnail: bypass:{사유}` 로 이유를 밝힙니다. 파라미터가 없으면 응답은 이전과 같습니다(헤더도 붙지 않습니다). 원본을 덮어쓰거나 지우면 그 썸네일도 함께 지워집니다.

화면과 SEO 는 이 경로(`/api/modules/sirsoft-ecommerce/product-image/*`)에만 규격을 붙입니다. 이미지 저장 디스크를 공개 자산 디스크로 고른 사이트의 직접 주소(CDN)는 이 엔드포인트를 지나지 않으므로 원본 그대로입니다.

```http
GET /api/modules/sirsoft-ecommerce/product-image/{hash}?size=sm HTTP/1.1

HTTP/1.1 200 OK
Content-Type: image/jpeg
X-G7-Thumbnail: miss
ETag: "4b1e…"
X-Content-Type-Options: nosniff

<썸네일 바이너리>
```
