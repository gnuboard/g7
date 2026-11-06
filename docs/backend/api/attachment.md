# Attachment API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Attachment 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
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
| Cache-Control | `public, max-age=86400, must-revalidate` (프로덕션) / `no-cache` (그 외) | 캐싱 정책 — 이미지·비이미지 공통. max-age 는 환경설정 `cache.layout_ttl` (기본 86400초). 이 URL 에는 자산 버전(`?v=`)이 없어 `immutable` 은 붙지 않는다 |
| Expires | `Wed, 15 Jul 2026 00:00:00 GMT` | 만료 시각 (현재 시각 + max-age) — 이미지·비이미지 공통 |
| ETag | `"9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d"` | 검증자 (행 메타 `disk|path|updated_at|size` 의 MD5, 따옴표 포함) — 이미지·비이미지 공통. 요청의 `If-None-Match` 와 일치하면 `304 Not Modified`(`-gzip` 접미사·다중값·`W/` 흡수) |
| Last-Modified | `Wed, 15 Jul 2026 00:00:00 GMT` | 행의 `updated_at` — 요청의 `If-Modified-Since` 가 그 이후면 `304 Not Modified` (`If-None-Match` 가 있으면 그것이 우선) |

**응답 예시**

```http
HTTP/1.1 200 OK
Content-Type: image/png
Content-Disposition: inline; filename=photo.png
X-Content-Type-Options: nosniff
Cache-Control: public, max-age=86400, must-revalidate
Expires: Wed, 15 Jul 2026 00:00:00 GMT
ETag: "9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d"
Last-Modified: Wed, 15 Jul 2026 00:00:00 GMT

<파일 바이너리>
```

이미지가 아닌 파일 (SVG 포함 — 2026-09-27 실측: 내용이 SVG 인 `evil.png` 행):

```http
HTTP/1.1 200 OK
Content-Type: image/svg+xml
Content-Disposition: attachment; filename=evil.png
X-Content-Type-Options: nosniff
Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox
Cache-Control: public, max-age=86400, must-revalidate
Expires: Wed, 15 Jul 2026 00:00:00 GMT
ETag: "1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f"
Last-Modified: Wed, 15 Jul 2026 00:00:00 GMT

<파일 바이너리>
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 304 | Not Modified | 요청 `If-None-Match` 가 현재 ETag 와 일치하거나, 그 헤더 없이 `If-Modified-Since` 가 `Last-Modified` 이후인 경우 — 이미지·비이미지 공통 (본문 없음, 200 과 같은 `Cache-Control`·`Expires`·`Last-Modified` 동반) |
| 403 | Forbidden | 첨부파일 접근 권한 없음 (`core.attachment.download` 훅 권한 미충족) 또는 스토리지에 실제 파일이 없는 경우 — `{"success": false, "message": "이 첨부파일에 대한 접근 권한이 없습니다."}` |
| 404 | Not Found | 해당 해시의 첨부파일이 존재하지 않는 경우 — `{"success": false, "message": "첨부파일을 찾을 수 없습니다."}` |

<!-- @generated:end -->



**설명** 해시(12자)로 식별되는 첨부파일을 다운로드합니다. 이미지 파일은 인라인으로 표시하고 그 외 파일은 다운로드 방식(`Content-Disposition: attachment`)으로 제공하며, **두 경우 모두 같은 캐시 계약**(검증자 `ETag`·`Last-Modified`, 조건부 요청 → 304, 200 과 같은 캐시 헤더 동반)을 갖습니다. 사용자 업로드물이라 응답 압축 대상에서 제외됩니다. 인증이 필요 없는 공개 라우트이지만 접근 권한은 AttachmentService가 로그인/비로그인 사용자 모두를 대상으로 하이브리드 방식으로 검사하며, 파일이 없으면 404, 권한이 없으면 403을 반환합니다. 게시글 첨부·상품 이미지 등 공개 리소스를 URL로 직접 내려받는 시나리오에 사용합니다.



### GET /api/favicon/{hash}
<!-- @generated:start:api.favicon.show -->
- **라우트명**: `api.favicon.show`
- **컨트롤러**: `App\Http\Controllers\Api\Public\FaviconController@show`
- **인증/권한**: 없음 (공개)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| hash | path | string | 예 | 영숫자 12자 | 파비콘 첨부 해시 |

**요청 예시**

```http
GET /api/favicon/AbCdEf123456 HTTP/1.1
Host: api.example.com
```

**응답**

파일 본문(이미지). 헤더: `Content-Type`(파비콘 목록으로 정규화 — ico 는 `image/x-icon`, svg 는 `image/svg+xml`, 그 밖 png·gif·jpeg·webp), `X-Content-Type-Options: nosniff`, `Content-Disposition: inline`, `Cache-Control: public, max-age=31536000, immutable`, ETag. svg 는 `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'` 를 함께 단다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 해시에 해당하는 첨부가 없거나, 파비콘 컬렉션(`site_favicon`·`template_settings.{템플릿}.branding.favicon`) 첨부가 아니거나, MIME 이 파비콘 목록 밖인 경우 |

<!-- @generated:end -->

**설명** 업로드한 파비콘을 탭 아이콘으로 내보내는 공개 경로입니다(#434). 일반 첨부 다운로드(`/api/attachment/{hash}`)는 ico·svg 를 다운로드로 내보내고 캐시 헤더가 없어 탭 아이콘으로 쓸 수 없으므로, 전역 인라인 정책을 넓히지 않고 파비콘 컬렉션 첨부만 이 경로로 내보냅니다. 그 밖의 첨부는 해시를 알아도 이 경로로 받을 수 없습니다. 교체하면 새 첨부(새 해시)라 주소가 바뀌므로 1년 immutable 캐시가 안전합니다. 화면 `<head>` 의 링크는 서버가 우선순위(활성 템플릿 설정 > 일반 탭 파비콘 > 번들 기본)로 골라 넣습니다.

