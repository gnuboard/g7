# Popup Images API 레퍼런스

> **소유**: plugin `sirsoft-popup` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 팝업 등록·수정 화면의 이미지 업로드·임시 이미지 삭제 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 응답 필드 표 + 응답 예시(envelope)
3. 업로드는 언제나 임시 묶음(temp_key)에 들어가고, 팝업에는 등록·수정 「저장」 때만 붙습니다
4. 팝업에 붙은 이미지는 이 API 로 지우지 못합니다(422) — 팝업 수정 저장으로만 바뀝니다
5. 갱신: 코드 변경 후 php artisan api:docgen 재실행
```

---


### POST /api/plugins/sirsoft-popup/admin/popup-images
<!-- @generated:start:api.plugins.sirsoft-popup.admin.popup-images.store -->
- **라우트명**: `api.plugins.sirsoft-popup.admin.popup-images.store`
- **컨트롤러**: `Plugins\Sirsoft\Popup\Http\Controllers\Admin\PopupImageController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-popup.popups.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| file | body | file | 예 | max 2048 | 올릴 이미지 파일. 형식 JPG·PNG·WEBP·GIF(확장자와 MIME 모두 검사), 가로·세로 4000px 이하. 최대 용량은 플러그인 설정(`max_upload_mb`, 1~10MB)과 서버 PHP 한도 중 작은 값이며 허용값 칸의 `max 2048`(KB)은 문서 생성 환경의 값입니다 — 실제 한도는 `GET /admin/display-settings` 의 `upload.effective_mb` |
| device | body | string | 예 | `pc`, `mobile` | 이미지를 쓸 기기. 같은 임시 묶음에 같은 기기 이미지가 이미 있으면 새 이미지로 교체하고 앞 이미지는 지웁니다 |
| temp_key | body | string | 예 | max 64 | 등록·수정 화면이 열릴 때 만든 임시 묶음 키(관리자 화면은 UUID 를 씀). 같은 화면의 업로드를 한 묶음으로 모으고, 저장 요청(`POST`/`PUT /admin/popups`)에 같은 값을 보내 붙일 이미지를 찾습니다 |

**요청 예시**

```http
POST /api/plugins/sirsoft-popup/admin/popup-images HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: multipart/form-data; boundary=----G7ExampleBoundary

------G7ExampleBoundary
Content-Disposition: form-data; name="file"; filename="banner.png"
Content-Type: image/png

(바이너리 파일 내용)
------G7ExampleBoundary
Content-Disposition: form-data; name="device"

pc
------G7ExampleBoundary
Content-Disposition: form-data; name="temp_key"

0b7c6f1e-4d2a-4c8e-9f3b-2a1d5e6f7a8b
------G7ExampleBoundary--
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (`PopupImageResource`). 팝업 상세의 `images.pc`/`images.mobile`, 목록의 `thumbnail` 도 같은 모양입니다._

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 이미지 ID. 등록·수정 저장 때 `pc_image_id`/`mobile_image_id` 로 보냅니다 |
| hash | string | `{hash}` | 공개 주소용 64자 영숫자 해시 |
| device | string | `pc` | 이미지 기기(`pc`, `mobile`) |
| url | string | `/api/plugins/sirsoft-popup/images/{hash}` | 공개 이미지 주소(사이트 상대 경로). 저장 전에도 미리보기에 쓸 수 있습니다 |
| download_url | string | `/api/plugins/sirsoft-popup/images/{hash}` | `url` 과 같은 값. 관리자 파일 업로더 컴포넌트가 기대하는 첨부 모양을 맞추려고 함께 싣습니다 |
| original_filename | string | `banner.png` | 업로드 때의 원본 파일명(최대 255자) |
| mime_type | string | `image/png` | 이미지 MIME |
| size | integer | `245760` | 저장된 파일 크기(바이트). GIF 를 뺀 형식은 서버가 크기를 줄여 저장할 수 있어 원본보다 작을 수 있습니다 |
| size_formatted | string | `240 KB` | 사람이 읽는 크기 표기 |
| width | integer\|null | `480` | 가로 픽셀(측정 실패 시 null). 권장 크기 안내에 씁니다 |
| height | integer\|null | `520` | 세로 픽셀(측정 실패 시 null) |
| order | integer | `0` | 업로더 첨부 모양 호환용 고정값(기기마다 한 장이라 항상 0) |
| is_image | boolean | `true` | 업로더 첨부 모양 호환용 고정값(항상 true) |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "이미지를 올렸습니다.",
    "data": {
        "id": 1,
        "hash": "{hash}",
        "device": "pc",
        "url": "/api/plugins/sirsoft-popup/images/{hash}",
        "download_url": "/api/plugins/sirsoft-popup/images/{hash}",
        "original_filename": "banner.png",
        "mime_type": "image/png",
        "size": 245760,
        "size_formatted": "240 KB",
        "width": 480,
        "height": 520,
        "order": 0,
        "is_image": true
    }
}
```

검증 실패 시 (HTTP 422):

```json
{
    "message": "이미지 파일 필드는 필수입니다. (외 2개 오류)",
    "errors": {
        "file": ["이미지 파일 필드는 필수입니다."],
        "device": ["선택한 기기 값이 올바르지 않습니다."],
        "temp_key": ["임시 업로드 키 필드는 필수입니다."]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 관리자가 아니거나(`admin`) 요구 권한(`sirsoft-popup.popups.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지). 형식·용량·크기(4000px) 초과, 서버 PHP 업로드 한도 초과(파일이 서버에 닿기 전에 잘려 전용 안내 메시지로 응답) 포함 |
| 500 | Internal Server Error | 스토리지에 파일을 쓰지 못했거나, 쓴 뒤 이미지 행 저장이 실패한 경우(`sirsoft-popup::messages.image.upload_failed`). 행은 만들지 않고 쓴 파일은 지웁니다 |

<!-- @generated:end -->

**설명**

팝업 등록·수정 화면의 PC·모바일 이미지 업로더가 파일을 고르는 즉시 부르는 업로드 엔드포인트입니다. 권한은 `auth:sanctum` + 관리자(`admin`) + 팝업 등록·수정 권한(`sirsoft-popup.popups.update`)입니다.

- 업로드한 이미지는 **임시 묶음**(`temp_key`)에만 들어가고 팝업에는 아직 붙지 않습니다. 등록·수정 「저장」 요청이 `temp_key`·`pc_image_id`·`mobile_image_id` 로 붙일 이미지를 정할 때 비로소 팝업에 연결되므로, 저장하지 않고 화면을 떠나면 사용자 화면은 바뀌지 않습니다.
- 같은 묶음·같은 기기로 다시 올리면 새 이미지로 교체되고 앞 이미지(파일·행)는 지워집니다. 기기마다 한 장만 유지됩니다.
- 저장에 실패하면(500) 파일도 행도 남기지 않습니다 — 행이 없는 파일은 임시 이미지 정리 명령이 찾지 못하므로 그 자리에서 지웁니다.
- 끝내 팝업에 붙지 않은 임시 이미지는 매일 도는 정리 스케줄(`sirsoft-popup:prune-temp-images`, 기본 보존 2일)이 회수합니다.
- 권장 크기(표시 방식의 PC 너비×높이)는 강제하지 않습니다. 화면이 응답의 `width`·`height` 로 안내만 합니다. GIF 는 움직임을 지키려고 크기를 줄이지 않고, 그 밖의 형식은 서버가 크기를 줄여 저장할 수 있습니다.
- 성공은 `201` 입니다. 업로드 파일은 `sirsoft-popup.popup_image.filter_upload_file` 필터 훅을 거치고, 저장 뒤 `sirsoft-popup.popup_image.after_upload` 액션 훅이 발행됩니다.


### DELETE /api/plugins/sirsoft-popup/admin/popup-images/{image}
<!-- @generated:start:api.plugins.sirsoft-popup.admin.popup-images.destroy -->
- **라우트명**: `api.plugins.sirsoft-popup.admin.popup-images.destroy`
- **컨트롤러**: `Plugins\Sirsoft\Popup\Http\Controllers\Admin\PopupImageController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-popup.popups.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| image | path | string | 예 | — | 지울 임시 이미지 ID(정수, 라우트 제약 `whereNumber`). 업로드 응답의 `data.id` |

**요청 예시**

```http
DELETE /api/plugins/sirsoft-popup/admin/popup-images/{image} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_성공 시 `data` 는 `null` 입니다 (처리 결과는 `success`·`message` 로만 전달)._

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| (data) | null | `null` | 반환 데이터 없음 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "이미지를 삭제했습니다.",
    "data": null
}
```

팝업에 붙은 이미지를 지우려 한 경우 (HTTP 422):

```json
{
    "success": false,
    "message": "팝업에 붙은 이미지는 여기서 지울 수 없습니다. 팝업 수정 화면에서 바꾸고 저장하세요."
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 관리자가 아니거나(`admin`) 요구 권한(`sirsoft-popup.popups.update`)이 없는 경우 |
| 404 | Not Found | 해당 ID 의 이미지가 없는 경우(라우트 모델 바인딩) |
| 422 | Unprocessable Entity | 이미 팝업에 붙은 이미지인 경우(`sirsoft-popup::messages.image.attached_not_deletable`) |
| 500 | Internal Server Error | 스토리지에서 파일을 지우지 못한 경우(`sirsoft-popup::messages.image.delete_failed`). 행은 남아 정리 명령이 다시 시도합니다 |

<!-- @generated:end -->

**설명**

등록·수정 화면에서 방금 올린 임시 이미지를 업로더의 삭제 단추로 지울 때 부릅니다. 권한은 `auth:sanctum` + 관리자(`admin`) + 팝업 등록·수정 권한(`sirsoft-popup.popups.update`)입니다.

- **아직 팝업에 붙지 않은 임시 이미지만** 지웁니다. 팝업에 붙은 이미지는 이 API 로 바꾸거나 지울 수 없고(422), 팝업 수정 「저장」(`PUT /admin/popups/{popup}` 의 `pc_image_id`·`mobile_image_id`)만 바꿉니다. 저장 전에는 사용자 화면이 바뀌지 않게 하려는 것입니다.
- 파일을 먼저 지우고 성공해야 행을 지웁니다. 파일이 이미 없으면 지운 것으로 봅니다. 파일 삭제가 실패하면 행을 남기고 500 으로 응답하며, 남은 임시 이미지는 정리 명령이 다시 지웁니다.
- 삭제 성공 시 `sirsoft-popup.popup_image.after_delete` 액션 훅이 발행됩니다.
