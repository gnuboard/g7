# Layouts API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Layouts 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/layouts/preview/{token}.json
<!-- @generated:start:api.public.layouts.preview.serve -->
- **라우트명**: `api.public.layouts.preview.serve`
- **컨트롤러**: `App\Http\Controllers\Api\Public\LayoutPreviewController@serve`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| token | path | string | 예 | — | 인증/검증 토큰 |

**요청 예시**

```http
GET /api/layouts/preview/{token}.json HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
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

**설명** 편집 중인 레이아웃을 미리보기 토큰(UUID)으로 조회해 JSON으로 서빙합니다. 토큰으로 대상 레이아웃을 찾은 뒤 상속 병합과 확장(extension) 적용까지 마친 결과를 반환하며, 토큰이 유효하지 않으면 404를 반환합니다. 인증 미들웨어가 적용되지만 실질적 보안 메커니즘은 토큰 자체이며, 레이아웃 편집기에서 저장 전 변경분을 실제 렌더링으로 확인하는 용도입니다.


### GET /api/layouts/{templateIdentifier}/{layoutName}.json
<!-- @generated:start:api.public.layouts.serve -->
- **라우트명**: `api.public.layouts.serve`
- **컨트롤러**: `App\Http\Controllers\Api\Public\PublicLayoutController@serve`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| templateIdentifier | path | string | 예 | — | 대상 template의 식별자 |
| layoutName | path | string | 예 | — | 대상 layout의 이름 (식별자) |

**요청 예시**

```http
GET /api/layouts/{templateIdentifier}/{layoutName}.json HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
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

**설명** 활성 템플릿의 병합된 레이아웃 JSON을 프론트엔드에 서빙합니다. 템플릿이 존재하고 활성 상태여야 하며, 상속 병합·확장 적용을 마친 결과를 ETag·Cache-Control 헤더와 함께 반환하고 미변경 시 304로 응답합니다. 레이아웃의 `permissions`에 따라 접근을 제한하고(비회원 401, 권한 부족 403), 컴포넌트 단위 권한 필터링을 사용자별로 적용합니다. 쿼리 `v`(정수 캐시 버전)로 캐시를 구분하며, `with_source_meta=1`은 `core.templates.layouts.edit` 권한이 있어야 노드별 출처 메타(편집기 전용)를 포함해 반환합니다.


