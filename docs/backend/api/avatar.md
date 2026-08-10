# Avatar API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Avatar 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### DELETE /api/me/avatar
<!-- @generated:start:api.me.avatar.delete -->
- **라우트명**: `api.me.avatar.delete`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\ProfileController@deleteAvatar`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
DELETE /api/me/avatar HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만 — `data` 는 `null`)._

**응답 예시**

```json
{
    "success": true,
    "message": "프로필 이미지가 성공적으로 삭제되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | 삭제할 아바타 첨부파일이 없는 경우 (`user.avatar_not_found` — "삭제할 프로필 이미지가 없습니다.") |
| 500 | Server Error | 첨부파일/실제 파일 삭제 중 예외 발생 (`user.avatar_delete_failed` — "프로필 이미지 삭제에 실패했습니다.") |

<!-- @generated:end -->

**설명** 현재 인증 사용자의 아바타를 삭제합니다. 사용자에 연결된 아바타 첨부파일(Attachment) 레코드와 실제 파일을 함께 제거하며, 삭제 활동이 로그로 기록됩니다. 아바타가 없으면 404(`user.avatar_not_found`)를 반환합니다. `auth:sanctum` 인증만 필요하고 별도 권한은 없으며, 사용자가 자신의 프로필 사진을 기본값으로 되돌리는 시나리오에 사용합니다.


### POST /api/me/avatar
<!-- @generated:start:api.me.avatar.upload -->
- **라우트명**: `api.me.avatar.upload`
- **컨트롤러**: `App\Http\Controllers\Api\Auth\ProfileController@uploadAvatar`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| avatar | body | image | 예 | max 2048 | 아바타 이미지 |

**요청 예시**

```http
POST /api/me/avatar HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: multipart/form-data; boundary=----G7ExampleBoundary

------G7ExampleBoundary
Content-Disposition: form-data; name="avatar"; filename="example.png"
Content-Type: image/png

(바이너리 파일 내용)
------G7ExampleBoundary--
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| avatar | string\|null | `/api/attachment/9f1c2b7d8e...` | 업로드된 아바타의 다운로드 URL (`Attachment.download_url` = `/api/attachment/{hash}`). 레거시 `avatar` 컬럼만 있는 경우 `storage/attachments/avatars/{파일명}` 형태의 절대 URL |
| attachment_id | integer | `1` | 생성된 첨부파일(Attachment) 레코드의 기본 키 |

**응답 예시**

```json
{
    "success": true,
    "message": "프로필 이미지가 성공적으로 업로드되었습니다.",
    "data": {
        "avatar": "/api/attachment/9f1c2b7d8e3a4c5b6d7e8f90a1b2c3d4",
        "attachment_id": 1
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Server Error | 파일 저장/첨부파일 생성 중 예외 발생 (`user.avatar_upload_failed` — "프로필 이미지 업로드에 실패했습니다.") |

<!-- @generated:end -->

**설명** 현재 인증 사용자의 아바타 이미지를 업로드합니다. 기존 아바타가 있으면 먼저 삭제한 뒤 새 이미지를 `avatar` 컬렉션의 다형성 첨부파일로 등록하고, 업로드 활동을 로그로 기록합니다. `auth:sanctum` 인증만 필요하고 별도 권한은 없으며, 이미지는 최대 2048KB로 제한됩니다. 확장은 `core.user.upload_avatar_validation_rules` 훅으로 검증 규칙을 추가할 수 있습니다.


