# Category Image API 레퍼런스

> **소유**: module `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Category Image 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-ecommerce/category-image/{hash}
<!-- @generated:start:api.modules.sirsoft-ecommerce.category-image.download -->
- **라우트명**: `api.modules.sirsoft-ecommerce.category-image.download`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\CategoryImageController@download`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| hash | path | string | 예 | — | 대상 리소스의 해시 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/category-image/{hash} HTTP/1.1
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

**설명** 공개 API로, 해시(`hash`)로 식별되는 카테고리 이미지 원본 파일을 스트리밍 서빙합니다. 인증이 필요 없으며(`PublicBaseController`), `CategoryImageController@download`가 `CategoryImageService::download()`를 호출해 리포지토리에서 해시로 이미지를 찾고 `StorageInterface::response()`로 `StreamedResponse`를 반환합니다. 응답에는 저장된 `mime_type`과 `Cache-Control: public, max-age=31536000`(1년) 헤더가 부여되어 브라우저/CDN 캐싱에 최적화됩니다. 해시에 해당하는 레코드가 없거나 스토리지에 실제 파일이 없으면 404를, 그 외 처리 오류 시 400 에러 응답을 반환합니다.


