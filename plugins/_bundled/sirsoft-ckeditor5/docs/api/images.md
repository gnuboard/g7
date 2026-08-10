# Images API 레퍼런스

> **소유**: plugin `sirsoft-ckeditor5` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Images 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-ckeditor5/images/{hash}
<!-- @generated:start:api.plugins.sirsoft-ckeditor5.api.sirsoft-ckeditor5.images.serve -->
- **라우트명**: `api.plugins.sirsoft-ckeditor5.api.sirsoft-ckeditor5.images.serve`
- **컨트롤러**: `Plugins\Sirsoft\Ckeditor5\Http\Controllers\ImageServeController@serve`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| hash | path | string | 예 | — | 업로드 시 발급된 12자리 소문자 16진수 이미지 해시(라우트 제약 `[a-f0-9]{12}`). 저장된 `<img src>` 의 마지막 경로 세그먼트로, 이 값으로 서빙할 이미지를 조회한다. |

**요청 예시**

```http
GET /api/plugins/sirsoft-ckeditor5/images/{hash} HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON `data` 를 반환하지 않습니다. 성공 시 `ResponseHelper` envelope 없이 이미지 바이너리를 그대로 스트리밍합니다 (`Symfony\Component\HttpFoundation\StreamedResponse`). 실패(404) 시에만 표준 JSON envelope 를 반환합니다._

성공 응답의 관측 가능한 표면은 본문(이미지 바이너리)과 다음 헤더입니다 (`ImageServeService::serve()` 가 `StorageInterface::response()` 에 전달).

| 헤더 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| Content-Type | string | `image/jpeg` | 업로드 레코드의 `mime_type` 값. 허용 MIME: `image/jpeg`, `image/png`, `image/gif`, `image/webp` |
| Cache-Control | string | `public, max-age=31536000` | 공개 콘텐츠 이미지의 장기 캐싱 (1년) |
| Content-Disposition | string | `inline; filename="photo.jpg"` | 업로드 시 원본 파일명(`original_name`)을 파일명으로 사용 |

**응답 예시**

성공 시 (HTTP 200) — JSON 이 아닌 이미지 바이너리 본문:

```http
HTTP/1.1 200 OK
Content-Type: image/jpeg
Cache-Control: public, max-age=31536000
Content-Disposition: inline; filename="photo.jpg"

<binary image data>
```

실패 시 (HTTP 404) — 표준 JSON envelope:

```json
{
    "success": false,
    "message": "이미지를 찾을 수 없습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | `hash` 가 라우트 제약(`[a-f0-9]{12}`)에 맞지 않아 라우트 미매칭 / `ImageServeService::findByHash()` 가 업로드 레코드를 찾지 못함 / 레코드는 있으나 스토리지에 실제 파일이 없어 `serve()` 가 null 반환 (`messages.image.not_found`) |

<!-- @generated:end -->

**설명**

CKEditor5 본문에 삽입된 이미지를 실제로 내려주는 공개 서빙 엔드포인트다. 에디터가 저장한 HTML 의 `<img src="/api/plugins/sirsoft-ckeditor5/images/{hash}">` 를 브라우저가 직접 GET 하므로 **인증이 없다**(`PublicBaseController`). 발행된 콘텐츠를 비로그인 독자가 열람하는 시나리오를 지원하기 위한 설계다.

- `{hash}` 는 라우트 제약 `where('hash', '[a-f0-9]{12}')` 로 12자리 소문자 16진수만 매칭된다. 형식이 어긋나면 라우트 자체가 매칭되지 않아 404 가 된다. 이 값은 이미지 업로드 시 발급된 `download_url` 의 마지막 경로 세그먼트다.
- 성공 응답은 JSON 이 아니라 **이미지 바이너리 스트림**(`StreamedResponse`, `Content-Type` 은 이미지 MIME)이다. `ResponseHelper` envelope 로 감싸지 않는다.
- `ImageServeService::findByHash()` 가 레코드를 찾지 못하거나 스토리지에 실제 파일이 없어 `serve()` 가 null 을 반환하면 `messages.image.not_found`(404) 표준 JSON 을 반환한다.

**응답 예시** (실패 시에만 JSON — 성공 시에는 이미지 바이너리)

```json
{ "success": false, "data": null, "message": "이미지를 찾을 수 없습니다.", "error": { "code": "not_found" } }
```


