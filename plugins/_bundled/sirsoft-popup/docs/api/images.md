# Images API 레퍼런스

> **소유**: plugin `sirsoft-popup` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 팝업 이미지를 해시 주소로 내보내는 공개 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 응답 헤더 표 + 응답 예시
3. 성공 응답은 JSON 이 아니라 이미지 바이너리입니다 — 실패(404)만 JSON envelope 입니다
4. 해시는 업로드 때 발급되는 64자 영숫자이며, 이미지를 교체하면 새 해시가 발급됩니다(장기 캐시 안전)
5. 갱신: 코드 변경 후 php artisan api:docgen 재실행
```

---


### GET /api/plugins/sirsoft-popup/images/{hash}
<!-- @generated:start:api.plugins.sirsoft-popup.images.show -->
- **라우트명**: `api.plugins.sirsoft-popup.images.show`
- **컨트롤러**: `Plugins\Sirsoft\Popup\Http\Controllers\Public\PopupImageController@show`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| hash | path | string | 예 | — | 업로드 때 발급된 64자 영숫자 이미지 해시(라우트 제약 `[A-Za-z0-9]{64}`). 관리자 응답의 `url`/`download_url`, 공개 응답의 `image.pc.url`/`image.mobile.url` 마지막 경로 세그먼트입니다 |

**요청 예시**

```http
GET /api/plugins/sirsoft-popup/images/{hash} HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON `data` 를 반환하지 않습니다. 성공 시 `ResponseHelper` envelope 없이 이미지 바이너리를 그대로 스트리밍합니다 (`Symfony\Component\HttpFoundation\StreamedResponse`). 실패(404) 시에만 표준 JSON envelope 를 반환합니다._

성공 응답의 관측 가능한 표면은 본문(이미지 바이너리)과 다음 헤더입니다 (`PopupImageService::publicResponse()` 가 플러그인 스토리지 `response()` 에 전달하고 `InlineMediaPolicy::harden()` 으로 보안 헤더를 붙임).

| 헤더 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| Content-Type | string | `image/png` | 업로드 레코드의 MIME. 업로드 허용 형식은 `image/jpeg`, `image/png`, `image/webp`, `image/gif` |
| Cache-Control | string | `public, max-age=31536000, immutable` | 1년 장기 캐시. 해시가 같으면 내용도 같다(이미지를 교체하면 새 해시가 발급됨) |
| Content-Disposition | string | `inline; filename=banner.png` | 업로드 때의 원본 파일명. 래스터 이미지는 `inline`, 그 밖의 형식은 `attachment` + CSP 격리 |
| X-Content-Type-Options | string | `nosniff` | 브라우저의 형식 추측 차단(모든 성공 응답) |

**응답 예시**

성공 시 (HTTP 200) — JSON 이 아닌 이미지 바이너리 본문:

```http
HTTP/1.1 200 OK
Content-Type: image/png
Cache-Control: public, max-age=31536000, immutable
Content-Disposition: inline; filename=banner.png
X-Content-Type-Options: nosniff

<binary image data>
```

실패 시 (HTTP 404) — 표준 JSON envelope:

```http
HTTP/1.1 404
```

```json
{
    "success": false,
    "message": "이미지를 찾을 수 없습니다."
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | `hash` 가 라우트 제약(64자 영숫자)에 맞지 않아 라우트 미매칭 / 해시에 해당하는 이미지 행이 없음 / 행은 있으나 스토리지에 파일이 없음(`sirsoft-popup::messages.image.not_found`, 파일 없음은 서버 오류 로그도 남김) |
| 429 | Too Many Requests | 같은 클라이언트가 분당 600회를 넘겨 요청한 경우(`throttle:600,1`) |

<!-- @generated:end -->

**설명**

사용자 화면 팝업 레이어와 관리자 화면(목록 썸네일·등록/수정 미리보기)이 `<img src>` 로 직접 부르는 공개 이미지 서빙 엔드포인트입니다. 브라우저가 이미지 태그로 GET 하므로 **인증이 없습니다**.

- 저장 전 미리보기를 위해 아직 팝업에 붙지 않은 임시 업로드 이미지도 같은 주소로 내보냅니다. 64자 무작위 해시라 추측할 수 없다는 것이 공개의 전제입니다.
- 성공 응답은 이미지 바이너리 스트림이며 `ResponseHelper` envelope 로 감싸지 않습니다. 저장 경로는 응답 어디에도 싣지 않습니다.
- 팝업에서 떼어 낸(교체·삭제된) 이미지는 파일과 행이 지워지므로 옛 해시 주소는 404 가 됩니다. 공개 팝업 응답은 캐시되므로 교체 직후에는 캐시가 비워질 때까지(팝업 저장 시 즉시 무효화) 새 주소가 반영됩니다.
