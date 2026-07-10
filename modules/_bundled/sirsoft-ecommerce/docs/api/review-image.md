# Review Image API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Review Image 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
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

<!-- 실측 제외: unresolved-path-param — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 리뷰에 첨부된 이미지를 해시(12자) 기반으로 공개 서빙합니다. 인증이 필요 없으며, `ReviewImageController@download` 가 `ProductReviewImageService::download()` 로 해시에 해당하는 이미지를 찾아 스트림(`StreamedResponse`)으로 반환합니다. 해시에 해당하는 이미지가 없으면 404 를 반환합니다. `<img src>` 등에서 리뷰 이미지 원본을 표시할 때 사용하며, 실제 파일 경로를 노출하지 않고 해시로만 접근하게 합니다.


