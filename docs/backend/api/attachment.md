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
| size | query | string | 아니오 | 정의된 썸네일 규격 이름 (`xs`·`sm`·`md`·`lg`·`xl`·운영자·확장 규격) | 래스터 이미지면 그 규격 썸네일을 낸다(권한 검사를 지난 뒤). `w` 보다 우선 |
| w | query | integer | 아니오 | 1~10000 | 요청 가로 — 「숫자 요청 대상」 규격 중 가로가 이 값 이상인 가장 작은 규격으로 올려 맞춘다(없으면 가장 큰 규격) |

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
| X-G7-Thumbnail | `hit` / `miss` / `bypass:{사유}` | `size`·`w` 를 붙인 요청에만. `hit` 은 만들어 둔 썸네일, `miss` 는 이번 요청에서 만든 썸네일, `bypass:{사유}` 는 썸네일을 낼 수 없어 원본을 낸 경우(사유: `disabled`·`no-engine`·`not-raster`·`animated`·`unknown-preset`·`smaller-than-preset`·`rate-limited`·`too-large`·`error`) |

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

**썸네일 (`?size=` · `?w=`)** 래스터 이미지에 `?size=sm`(규격 이름) 또는 `?w=300`(가로)을 붙이면 환경설정 업로드 탭에 정의한 규격으로 줄인 썸네일을 냅니다. 권한 검사는 원본과 같고(권한이 없으면 원본과 같은 403), 그 검사를 지난 뒤에만 썸네일을 만듭니다. 코어 첨부는 권한이 걸린 원본이라 썸네일도 비공개 디스크에서 스트림하고 `Cache-Control: private, …` 로 냅니다(공유 캐시·CDN 에 남지 않음). 처음 요청할 때 만들어 저장하고 다음부터는 저장본을 냅니다(`X-G7-Thumbnail: miss` → `hit`). 규격 이름이 없거나 원본이 규격보다 작거나 움직이는 이미지(GIF·WebP·APNG, 업로드 탭 「움직이는 이미지는 원본 사용」이 켜져 있을 때)이면 원본을 그대로 내고 `X-G7-Thumbnail: bypass:{사유}` 를 붙입니다. 이 통로의 주소에는 서명이 없습니다 — 권한은 요청마다 위 검사로 판정합니다(서명 주소를 쓰는 통로의 `size`·`w` 처리는 [image-thumbnails.md](../image-thumbnails.md) 「확장 서빙 지점 계약」).

**공유 캐시와 본인인증 정책** 원본은 받을 수 있는 사람이 요청자마다 갈릴 때만 `Cache-Control: private, …` 로 냅니다 — 권한 훅(`core.attachment.download`)에 권한이 매핑돼 있거나, 켜진 본인인증 정책이 hook 범위 `core.attachment.before_download_action` 또는 route 범위 `api.attachment.download` 에 걸려 있을 때입니다. 인증을 마친 사람이 받은 응답이 CDN·프록시에 남아 인증하지 않은 사람에게 가지 않게 하기 위해서입니다. 둘 다 없으면 위 표처럼 `public` 입니다. 정책 조회는 캐시된 정책 색인을 읽으므로 요청마다 정책 표를 조회하지 않습니다(정책 저장·확장 활성화 때 비워짐). 두 범위의 정책은 이미지(인라인)·내려받기·썸네일(`?size=`·`?w=`) 요청을 모두 막습니다 — 인증하지 않았으면 `428` 과 본인인증 응답 본문(`error_code: identity_verification_required`)이고, 정책의 유예 시간 안에 인증을 마쳤으면 통과합니다. `<img>` 로 읽는 이미지는 화면이 428 을 받아도 본인인증 창을 띄우지 못해 깨진 이미지로 보이므로, 이미지 첨부에 정책을 걸 때는 사용자가 다른 화면에서 먼저 인증하도록 유예 시간을 두는 것이 좋습니다.

**훅 발화 횟수** 이 요청은 한 번에 액션 훅 `core.attachment.before_download_action` 과 필터 훅 `core.attachment.before_download` 를 각각 한 번만 돌립니다 — 이미지(인라인)·비이미지 내려받기·썸네일(`?size=`·`?w=`)·조건부 요청(304) 모두 같습니다. 7.2.0 전에는 비이미지 내려받기에서 필터 훅이 두 번 돌아, 필터로 내려받기 횟수를 세는 구독자가 한 번에 2씩 올렸습니다.

**필터가 MIME 을 바꿀 때** 인라인(래스터 이미지)과 내려받기 중 어느 쪽으로 낼지는 필터 훅 `core.attachment.before_download` 를 지난 행의 MIME 으로 한 번만 정합니다. 응답의 `Content-Type` 도 그 MIME 입니다. 구독자가 SVG 행을 `image/png` 로 바꾸면 인라인(`nosniff`)으로 나가고, 썸네일은 파일 머리로 다시 판정해 만들지 않습니다(`bypass:not-raster`). 래스터 행을 `image/svg+xml` 로 바꾸면 내려받기(`attachment`, CSP, `nosniff`)입니다. 7.2.0 전에는 서비스가 필터 전 MIME 으로, 컨트롤러가 필터 뒤 MIME 으로 따로 골라 두 판정이 갈렸습니다.

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

