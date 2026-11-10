# Attachment API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Attachment 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
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
| hash | path | string | 예 | — | 다운로드할 첨부파일의 해시 식별자 (`attachments.hash`) |

**요청 예시**

```http
GET /api/attachment/{hash} HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON 봉투(`data`)를 반환하지 않습니다. 성공 시 파일 바이너리 본문을 그대로 응답합니다 (실패 시에만 JSON 에러 봉투)._

| 응답 헤더 | 값 | 용도/설명 |
| --- | --- | --- |
| Content-Type | `image/png` 등 | 첨부파일의 MIME 타입 (`attachments.mime_type`) |
| Content-Disposition | `inline; filename=원본파일명.png` / `attachment; filename=원본파일명.pdf` | 래스터 이미지(JPEG·PNG·GIF·WebP·AVIF·BMP)만 `inline`. 그 밖의 파일은 **SVG 를 포함해** `attachment`(다운로드) — SVG 는 스크립트를 품을 수 있어 앱 오리진에서 열리면 안 된다(KVE-2026-2366) |
| X-Content-Type-Options | `nosniff` | 모든 파일 응답 — 브라우저가 내용을 추측해 다른 형식으로 렌더하지 않게 한다 |
| Content-Security-Policy | `default-src 'none'; style-src 'unsafe-inline'; sandbox` | 래스터 이미지가 아닌 파일에만 — 다운로드를 무시하고 렌더하는 환경에서도 스크립트·외부 로드를 막는다 |
| Cache-Control | `public, max-age=86400, immutable` (프로덕션) / `no-cache` (그 외) | 이미지 응답의 캐싱 정책. max-age 는 환경설정 `cache.layout_ttl` (기본 86400초) |
| Expires | `Wed, 15 Jul 2026 00:00:00 GMT` | 이미지 응답의 만료 시각 (현재 시각 + max-age) |
| ETag | `9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d` | 이미지 응답의 검증자 (파일 수정시각 + 크기의 MD5). 요청의 `If-None-Match` 와 일치하면 `304 Not Modified` |

**응답 예시**

```http
HTTP/1.1 200 OK
Content-Type: image/png
Content-Disposition: inline; filename=photo.png
X-Content-Type-Options: nosniff
Cache-Control: public, max-age=86400, immutable
Expires: Wed, 15 Jul 2026 00:00:00 GMT
ETag: 9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d

<파일 바이너리>
```

이미지가 아닌 파일 (SVG 포함 — 2026-09-27 실측: 내용이 SVG 인 `evil.png` 행):

```http
HTTP/1.1 200 OK
Content-Type: image/svg+xml
Content-Disposition: attachment; filename=evil.png
X-Content-Type-Options: nosniff
Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox

<파일 바이너리>
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 304 | Not Modified | 이미지 응답에서 요청 `If-None-Match` 헤더가 현재 ETag 와 일치하는 경우 (본문 없음) |
| 403 | Forbidden | 첨부파일 접근 권한 없음 (`core.attachment.download` 훅 권한 미충족) 또는 스토리지에 실제 파일이 없는 경우 — `{"success": false, "message": "이 첨부파일에 대한 접근 권한이 없습니다."}` |
| 404 | Not Found | 해당 해시의 첨부파일이 존재하지 않는 경우 — `{"success": false, "message": "첨부파일을 찾을 수 없습니다."}` |

<!-- @generated:end -->

**설명** 해시(12자)로 식별되는 첨부파일을 다운로드합니다. 이미지 파일은 캐싱 헤더와 함께 인라인으로 표시하고 그 외 파일은 다운로드 방식으로 제공합니다. 인증이 필요 없는 공개 라우트이지만 접근 권한은 AttachmentService가 로그인/비로그인 사용자 모두를 대상으로 하이브리드 방식으로 검사하며, 파일이 없으면 404, 권한이 없으면 403을 반환합니다. 게시글 첨부·상품 이미지 등 공개 리소스를 URL로 직접 내려받는 시나리오에 사용합니다. 행을 만든 확장이 꺼져 있으면 403 이고, 확장은 아래 판정 필터로 자기 행의 열람 여부를 답합니다(7.1.5).

#### 다운로드 판정 필터 `core.attachment.filter_can_download` (7.1.5)

코어는 확장을 모릅니다. 확장이 만든 첨부 행을 이 주소로 내보내도 되는지는 **원 리소스 권한 확인** 원칙에 따라 그 행을 만든 확장이 답합니다 — 행이 붙은 원 리소스(글·문서·상품 등)를 요청한 사람이 볼 수 있으면 내보내고, 볼 수 없으면 막습니다. 해시를 안다는 것만으로 비공개 리소스의 파일이 나가지 않게 하려는 장치입니다.

| 항목 | 내용 |
| --- | --- |
| 종류 | filter (`AttachmentService::CAN_DOWNLOAD_FILTER`) |
| 인자 | `($verdict, Attachment $attachment, ?User $user)` — `$verdict` 의 초기값은 `null`, `$user` 는 요청한 회원(비로그인이면 `null`) |
| 반환 | `null` = 의견 없음(기존 흐름 그대로) · `false` = 거부(403 `attachment.access_denied`) · `true` = 허용 |
| 호출 위치 | `AttachmentService::getFileInfo()`·`download()` 가 같은 도우미로 호출합니다. 이미지가 아닌 파일은 두 메서드를 차례로 거치므로 한 요청에서 두 번 불릴 수 있습니다 — 리스너는 부작용 없이 판정만 하세요 |
| 허용 뒤 흐름 | `true`·`null` 이어도 기존 흐름을 그대로 탑니다 — 내려받기(`download()`)는 기능 권한(`core.attachment.download` 권한 훅) → `core.attachment.before_download_action`(본인인증 가드 등) → `core.attachment.before_download` 필터, 래스터 이미지 인라인 서빙(`getFileInfo()`)은 기능 권한 → `core.attachment.before_download` 필터만(`before_download_action` 은 발화하지 않음) |

리스너 작성 규칙:

- 자기 행(`source_type` 이 자기 종류이고 `source_identifier` 가 자기 식별자)에만 `true`/`false` 를 돌려주고, 그 밖의 행은 받은 `$verdict` 를 그대로 돌려줍니다. 남의 행에 답하지 않습니다.
- 판정은 그 행이 붙은 원 리소스의 열람 권한으로 합니다(예: 글 읽기 권한·비밀글·공개 여부). 첨부 행만 보고 판단하지 않습니다.
- 리스너가 없는 행(회원 아바타·사이트 로고·기본 OG 이미지·게시판 반응 아이콘 등)은 `null` 이 되어 지금처럼 공개로 서빙됩니다.

```php
HookManager::addFilter('core.attachment.filter_can_download', function ($verdict, Attachment $attachment, ?User $user) {
    if ($attachment->source_identifier !== 'vendor-module') {
        return $verdict; // 남의 행 — 의견 없음 그대로
    }

    // 원 리소스(이 행이 붙은 문서)의 열람 권한으로 답한다
    return app(DocumentService::class)->canView($attachment->attachmentable_id, $user);
});
```

코어 일반 규칙(필터보다 먼저 적용, 리스너가 뒤집지 못함):

- 행의 `source_type` 이 `module`·`plugin` 이고 `source_identifier` 의 확장이 비활성이거나 설치돼 있지 않으면 403 입니다. 그 확장의 판정 리스너가 꺼져 있어 원 리소스 권한을 확인할 수 없기 때문입니다. 확장을 다시 켜면 종전처럼 서빙됩니다.
- 코어 `attachments` 표에는 임시 업로드(저장 전 업로드) 개념이 없습니다. 편집기 본문 미디어처럼 저장 전 업로드가 필요한 확장은 자기 첨부 표에 두고 자기 서빙 라우트로 내보냅니다([본문 미디어](../../extension/content-media.md)).


