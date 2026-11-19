# Review Image API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Review Image 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/review-image/{hash}
<!-- @generated:start:api.modules.sirsoft-ecommerce.review-image.download -->
- **라우트명**: `api.modules.sirsoft-ecommerce.review-image.download`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\User\ReviewImageController@download`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| hash | path | string | 예 | — | 대상 리소스의 해시 식별자 |
| size | query | string | 아니오 | 사용 중인 썸네일 규격 이름 (`xs`·`sm`·`md`·`lg`·`xl`·운영자 규격·확장 규격) | 이 규격의 썸네일을 낸다. `w` 보다 우선한다. 미정의·꺼진 규격·배열(`size[]=`)·같은 키 두 번이면 원본을 내고 `X-G7-Thumbnail: bypass:unknown-preset` 을 붙인다. |
| w | query | integer | 아니오 | 1~10000 | 숫자 요청 대상 규격 중 가로가 이 값 이상인 가장 작은 규격으로 올려 맞춘다(없으면 가로가 가장 큰 규격). 정수가 아니면 원본 + `bypass:unknown-preset`. |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/review-image/{hash} HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON 봉투(`data`)를 반환하지 않습니다. 성공 시 이미지 바이너리 스트림(`StreamedResponse`)을 그대로 반환합니다 (`ProductReviewImageService::download()` → `StorageInterface::response()`)._

성공 응답의 헤더는 다음과 같습니다.

| 헤더 | 값 | 용도/설명 |
| --- | --- | --- |
| Content-Type | `image/jpeg` | 업로드 시 저장된 이미지의 MIME 타입 (`product_review_images.mime_type`) |
| Cache-Control | `public, max-age=31536000, must-revalidate` (프로덕션) / `no-cache` (그 외) | 코어 파일 서빙 단일 지점이 환경에 따라 정하는 캐싱 정책 — `ETag`·`Last-Modified`·`Expires` 동반, 조건부 요청은 304. 썸네일을 요청했는데 원본을 낸 응답(`X-G7-Thumbnail: bypass:{사유}`)은 운영 환경에서 `max-age` 가 썸네일과 같은 코어 레이아웃 캐시 유지 시간(`cache.layout_ttl`, 1년 이하)으로 줄고, 생성 제한·일시 오류면 `no-store` 다 |
| Content-Disposition | `inline; filename=review.jpg` | 원본 파일명(`original_filename`). 래스터 이미지(JPEG·PNG·GIF·WebP 등)만 `inline`, 그 밖(SVG 포함)은 `attachment` + CSP sandbox. 모든 응답에 `X-Content-Type-Options: nosniff` (KVE-2026-2366) |
| X-Content-Type-Options | `nosniff` | 브라우저가 내용을 추측해 다른 형식으로 렌더하지 않게 한다 |
| X-G7-Thumbnail | `hit` · `miss` · `bypass:smaller-than-preset` | `size`·`w` 를 보낸 요청에만 붙는다. `hit`(이미 만든 썸네일)·`miss`(이번 요청에 만듦)·`bypass:{사유}`(원본을 냄 — 사유는 `disabled`·`no-engine`·`not-raster`·`animated`·`unknown-preset`·`smaller-than-preset`·`rate-limited`·`too-large`·`error`) |

**응답 예시**

```http
HTTP/1.1 200 OK
Content-Type: image/jpeg
Cache-Control: public, max-age=31536000, must-revalidate
ETag: "9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d"
Last-Modified: Wed, 15 Jul 2026 00:00:00 GMT
Expires: Thu, 15 Jul 2027 00:00:00 GMT
Content-Disposition: inline; filename=review.jpg
X-Content-Type-Options: nosniff

<이미지 바이너리 스트림>
```

이미지를 찾지 못한 경우에만 JSON 봉투가 반환됩니다.

```json
{
    "success": false,
    "message": "이미지를 찾을 수 없습니다."
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 해시에 해당하는 리뷰 이미지가 없거나(`findByHash()` → null), 레코드는 있으나 스토리지에 실제 파일이 없는 경우, **또는 이미지가 속한 리뷰가 노출(VISIBLE) 상태가 아니면서 유효한 서명도 없는 경우**(숨김·블라인드·대기 리뷰의 이미지는 존재를 감추기 위해 동일하게 404) (`messages.reviews.image_not_found`) |

<!-- @generated:end -->

**설명** 리뷰에 첨부된 이미지를 해시(12자) 기반으로 서빙합니다. `ReviewImageController@download` 가 `ProductReviewImageService::download()` 로 해시에 해당하는 이미지를 찾아 스트림(`StreamedResponse`)으로 반환합니다. `<img src>` 등에서 리뷰 이미지 원본을 표시할 때 사용하며, 실제 파일 경로를 노출하지 않고 해시로만 접근하게 합니다.

이미지 서빙은 **부모 리뷰가 노출(VISIBLE) 상태일 때로 한정**됩니다(KVE-2026-1914). 숨김·블라인드·대기 상태 리뷰의 이미지는 해시가 유효해도 서빙되지 않고 404 를 반환합니다 — 본문이 감춰진 리뷰의 이미지가 해시만으로 노출되는 것을 막고, 존재 자체를 드러내지 않기 위해 "이미지 없음"과 동일한 404 로 응답합니다. 노출 상태 리뷰의 이미지는 별도 인증 없이 공개 접근할 수 있습니다.

브라우저 `<img src>` 는 Authorization 헤더를 실을 수 없으므로, 숨김 리뷰의 이미지를 싣는 관리자 응답(리뷰 목록·상세)의 `download_url` 에는 `expires`·`signature` 쿼리가 붙은 **한시 서명 URL** 이 직렬화되며, 이 엔드포인트는 유효한 서명을 상태 게이트와 동등한 자격으로 허용합니다. 서명이 변조·만료된 요청은 종전과 동일하게 404 로 차단됩니다. 노출(VISIBLE) 리뷰의 `download_url` 은 무서명 공개 URL(직접 URL/CDN 또는 이 API 경로)을 유지합니다. 사이트를 하위 폴더(예: `/g7`)에 설치하면 서명 URL 은 설치 폴더 접두사를 실은 경로(`/g7/api/…`)로 내려가며, 서명은 접두사를 뺀 경로에 걸리고 검증도 접두사를 뺀 요청 경로로 하므로 그 주소 그대로 열면 서명이 유효합니다.

**캐시 계약** 응답은 코어 파일 서빙 단일 지점과 같은 규칙을 따릅니다 — 검증자 `ETag`(행 메타 `disk|path|updated_at|file_size` 의 MD5, 따옴표 포함)·`Last-Modified`(행의 `updated_at`)를 싣고, 요청의 `If-None-Match`(`-gzip` 접미사·다중값·`W/` 흡수) 또는 `If-Modified-Since` 가 일치하면 본문 없는 `304 Not Modified` 를 200 과 같은 `Cache-Control`·`Expires` 와 함께 돌려줍니다. `Cache-Control` 은 프로덕션에서 `public, max-age=31536000, must-revalidate`(자산 버전 `?v=` 가 없는 URL 이라 `immutable` 은 붙지 않음), 그 외 환경에서는 `no-cache` 입니다. 사용자 업로드물이라 응답 압축 대상에서 제외됩니다.

**썸네일** `?size={규격}` 또는 `?w={숫자}` 를 붙이면 위 게이트(노출 상태 또는 한시 서명)를 지난 래스터 이미지는 환경설정 > 업로드 탭의 썸네일 규격으로 줄인 사본을 냅니다. 게이트를 지나지 못하면 원본과 같은 404 입니다 — 썸네일이 접근 범위를 넓히지 않습니다. 리뷰 이미지는 권한이 걸린 원본이라 **원본이 어느 디스크에 있든** 썸네일은 비공개 썸네일 저장 위치(권한이 걸린 이미지 썸네일)에만 저장하고, 302 없이 서버가 직접 스트리밍하며 `Cache-Control` 은 `private` 입니다(프로덕션 `private, max-age={레이아웃 캐시 TTL}`, 그 외 `private, no-cache`) — 공유 캐시(CDN·프록시)가 저장하지 못하게. 한시 서명은 `size`·`w` 를 빼고 검증하므로 응답이 준 서명 주소 뒤에 `&size=sm` 을 덧붙여도 서명이 유효합니다(서명 대상 쿼리를 바꾸거나 만료되면 원본과 같이 거부). 규격보다 작은 원본(확대 끔)·움직이는 GIF·WebP·APNG(「움직이는 이미지는 원본 사용」이 켜져 있을 때)·SVG·꺼진 기능에서는 원본을 그대로 내고 `X-G7-Thumbnail: bypass:{사유}` 로 이유를 밝힙니다. 파라미터가 없으면 응답은 이전과 같습니다(헤더도 붙지 않습니다).

```http
GET /api/modules/sirsoft-ecommerce/review-image/{hash}?expires=1787000000&signature=0f3c…&size=sm HTTP/1.1

HTTP/1.1 200 OK
Content-Type: image/jpeg
Cache-Control: private, max-age=86400
X-G7-Thumbnail: hit
X-Content-Type-Options: nosniff

<썸네일 바이너리>
```
