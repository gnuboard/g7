# Upload API 레퍼런스

> **소유**: plugin `sirsoft-ckeditor5` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Upload 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /api/plugins/sirsoft-ckeditor5/upload
<!-- @generated:start:api.plugins.sirsoft-ckeditor5.api.sirsoft-ckeditor5.upload -->
- **라우트명**: `api.plugins.sirsoft-ckeditor5.api.sirsoft-ckeditor5.upload`
- **컨트롤러**: `Plugins\Sirsoft\Ckeditor5\Http\Controllers\ImageUploadController@upload`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| upload | body | file | 예 | max 2048 | 에디터에 드롭/붙여넣은 이미지 파일 1개(multipart). 허용 MIME 은 `jpeg,jpg,png,gif,webp`, 최대 크기는 플러그인 설정 `imageMaxSizeMb`(기본 2MB) 로 결정된다. |

**요청 예시**

```http
POST /api/plugins/sirsoft-ckeditor5/upload HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: multipart/form-data; boundary=----G7ExampleBoundary

------G7ExampleBoundary
Content-Disposition: form-data; name="upload"; filename="example.pdf"
Content-Type: application/octet-stream

(바이너리 파일 내용)
------G7ExampleBoundary--
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 표준 envelope(`success`/`message`/`data`)를 사용하지 않습니다. CKEditor5 SimpleUploadAdapter 규격상 성공 시 HTTP 201 + 최상위 `url` 키만 반환합니다 (`data` 래핑 없음)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| url | string | `/api/plugins/sirsoft-ckeditor5/images/a1b2c3d4e5f6` | 업로드된 이미지의 서빙 URL. 모델 접근자 `download_url` 이 생성하며 `/api/plugins/sirsoft-ckeditor5/images/{hash}` (hash = 12자리 hex) 형식이다. CKEditor 가 이 값을 그대로 `<img src>` 에 삽입한다. |

실패 시에는 최상위 `error.message` 만 반환합니다 (아래 에러 응답 표 참조).

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| error.message | string | `이미지 파일만 업로드할 수 있습니다.` | 검증/권한/서버 오류 사유 (다국어 처리된 단일 메시지). CKEditor 가 에디터 UI 의 알림 문구로 표시한다. |

**응답 예시**

```json
{
    "url": "/api/plugins/sirsoft-ckeditor5/images/a1b2c3d4e5f6"
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | query 파라미터 `permission` 이 지정됐고 현재 사용자가 해당 권한을 갖지 못한 경우 — `{"error":{"message":"이미지 업로드 권한이 없습니다."}}` |
| 422 | Unprocessable Entity | 검증 규칙 위반 (`upload` 누락 / 파일 아님 / 이미지 아님 / 허용 MIME 아님 / 용량 초과). 응답은 Laravel 기본 `errors` 형식이 아니라 CKEditor 규격 `{"error":{"message":"<첫 번째 오류 메시지>"}}` |
| 500 | Internal Server Error | 스토리지 저장 또는 업로드 기록 생성 중 예외 발생 — `{"error":{"message":"이미지 업로드에 실패했습니다."}}` |

<!-- @generated:end -->

**설명**

CKEditor5 의 SimpleUploadAdapter 가 에디터에 드롭/붙여넣은 이미지를 업로드하는 관리자 엔드포인트다. 컨트롤러가 `AdminBaseController` 를 상속하므로 실제 인증은 `auth:sanctum` **에 더해 관리자(admin) 미들웨어**가 적용된다(생성기 표기는 `auth:sanctum` 만 노출).

- **응답 형식이 표준 envelope 가 아니다.** SimpleUploadAdapter 규격상 성공 시 HTTP 201 + 최상위 `{"url": "..."}`, 실패 시 4xx/5xx + `{"error": {"message": "..."}}` 를 반환한다. `ResponseHelper` 를 쓰지 않으므로 `data`/`success` 필드가 없다.
  이 규격은 우리 코드가 정한 것이 아니다 — 응답을 파싱하는 주체가 CDN 으로 로드되는 CKEditor5 43.3.1 의 `SimpleUploadAdapter`(`resources/js/handlers/initEditor.ts` 의 `editorConfig.simpleUpload`)이므로 파싱 규약을 바꿀 수 없다. 컨트롤러의 각 응답 지점에는 이 사유로 `audit:allow response-helper-bypass` 를 명시해 두었으니, envelope 로 감싸는 "수정" 을 하면 에디터의 이미지 업로드가 조용히 실패한다.
- **요청 파라미터**: multipart body 의 `upload` 필드(이미지 파일 1개). 허용 MIME 은 `jpeg,jpg,png,gif,webp`, 최대 크기는 플러그인 설정 `imageMaxSizeMb`(기본 2MB) 로 동적 결정된다. 검증 실패도 CKEditor 규격(`{"error":{"message":...}}`, HTTP 422)으로 응답한다.
- **선택 권한 게이트**: query 파라미터 `permission` 이 주어지면, 현재 사용자가 해당 권한을 갖지 못한 경우 403 `{"error":{"message":...}}`. 에디터를 임베드하는 화면이 업로드 권한을 세분화할 때 사용한다.
- 업로드 성공 시 반환하는 `url` 은 공개 서빙 엔드포인트(`GET /images/{hash}`)의 절대 URL 이다.

**응답 예시** (성공 — CKEditor 규격, envelope 아님)

```json
{ "url": "https://example.com/api/plugins/sirsoft-ckeditor5/images/a1b2c3d4e5f6" }
```

**오류 예시** (검증/권한/서버 오류 공통 — HTTP 422/403/500)

```json
{ "error": { "message": "이미지 파일만 업로드할 수 있습니다." } }
```


