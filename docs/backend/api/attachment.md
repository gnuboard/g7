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
| Content-Disposition | `attachment; filename="원본파일명.pdf"` | 이미지가 아닌 파일에만 부여 — 원본 파일명으로 다운로드 |
| Cache-Control | `public, max-age=86400, immutable` (프로덕션) / `no-cache` (그 외) | 이미지 응답의 캐싱 정책. max-age 는 환경설정 `cache.layout_ttl` (기본 86400초) |
| Expires | `Wed, 15 Jul 2026 00:00:00 GMT` | 이미지 응답의 만료 시각 (현재 시각 + max-age) |
| ETag | `9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d` | 이미지 응답의 검증자 (파일 수정시각 + 크기의 MD5). 요청의 `If-None-Match` 와 일치하면 `304 Not Modified` |

**응답 예시**

```http
HTTP/1.1 200 OK
Content-Type: image/png
Cache-Control: public, max-age=86400, immutable
Expires: Wed, 15 Jul 2026 00:00:00 GMT
ETag: 9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d

<파일 바이너리>
```

이미지가 아닌 파일:

```http
HTTP/1.1 200 OK
Content-Type: application/pdf
Content-Disposition: attachment; filename="manual.pdf"

<파일 바이너리>
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 304 | Not Modified | 이미지 응답에서 요청 `If-None-Match` 헤더가 현재 ETag 와 일치하는 경우 (본문 없음) |
| 403 | Forbidden | 첨부파일 접근 권한 없음 (`core.attachment.download` 훅 권한 미충족) 또는 스토리지에 실제 파일이 없는 경우 — `{"success": false, "message": "이 첨부파일에 대한 접근 권한이 없습니다."}` |
| 404 | Not Found | 해당 해시의 첨부파일이 존재하지 않는 경우 — `{"success": false, "message": "첨부파일을 찾을 수 없습니다."}` |

<!-- @generated:end -->

**설명** 해시(12자)로 식별되는 첨부파일을 다운로드합니다. 이미지 파일은 캐싱 헤더와 함께 인라인으로 표시하고 그 외 파일은 다운로드 방식으로 제공합니다. 인증이 필요 없는 공개 라우트이지만 접근 권한은 AttachmentService가 로그인/비로그인 사용자 모두를 대상으로 하이브리드 방식으로 검사하며, 파일이 없으면 404, 권한이 없으면 403을 반환합니다. 게시글 첨부·상품 이미지 등 공개 리소스를 URL로 직접 내려받는 시나리오에 사용합니다.


