# Attachment API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Attachment 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/attachment/{hash}
<!-- @generated:start:api.attachment.download -->
- **라우트명**: `api.attachment.download`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicAttachmentController@download`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| hash | path | string | 예 | — | 대상 리소스의 해시 식별자 |

**요청 예시**

```http
GET /api/attachment/{hash} HTTP/1.1
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

**설명** 해시(12자)로 식별되는 첨부파일을 다운로드합니다. 이미지 파일은 캐싱 헤더와 함께 인라인으로 표시하고 그 외 파일은 다운로드 방식으로 제공합니다. 인증이 필요 없는 공개 라우트이지만 접근 권한은 AttachmentService가 로그인/비로그인 사용자 모두를 대상으로 하이브리드 방식으로 검사하며, 파일이 없으면 404, 권한이 없으면 403을 반환합니다. 게시글 첨부·상품 이미지 등 공개 리소스를 URL로 직접 내려받는 시나리오에 사용합니다.


