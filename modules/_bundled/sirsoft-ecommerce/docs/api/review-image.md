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
| Cache-Control | `public, max-age=31536000` | 공개 이미지 장기 캐싱 (1년) |
| Content-Disposition | `attachment; filename="review.jpg"` | 업로드 시의 원본 파일명 (`original_filename`) |

**응답 예시**

```http
HTTP/1.1 200 OK
Content-Type: image/jpeg
Cache-Control: public, max-age=31536000
Content-Disposition: attachment; filename="review.jpg"

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

브라우저 `<img src>` 는 Authorization 헤더를 실을 수 없으므로, 숨김 리뷰의 이미지를 싣는 관리자 응답(리뷰 목록·상세)의 `download_url` 에는 `expires`·`signature` 쿼리가 붙은 **한시 서명 URL** 이 직렬화되며, 이 엔드포인트는 유효한 서명을 상태 게이트와 동등한 자격으로 허용합니다. 서명이 변조·만료된 요청은 종전과 동일하게 404 로 차단됩니다. 노출(VISIBLE) 리뷰의 `download_url` 은 무서명 공개 URL(직접 URL/CDN 또는 이 API 경로)을 유지합니다.


