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
| hash | path | string | 예 | — | 업로드 때 발급된 64자 영숫자 이미지 해시(라우트 제약 `[A-Za-z0-9]{64}`). 관리자 응답의 `url`/`preview_url`(직접 주소가 없는 행이면 `download_url` 도), 공개 응답의 `image.pc.url`/`image.mobile.url` 마지막 경로 세그먼트입니다 |
| size | query | string | 아니오 | 사용 중인 썸네일 규격 이름 (`xs`·`sm`·`md`·`lg`·`xl`·운영자 규격·확장 규격) | 이 규격의 썸네일을 낸다. `w` 보다 우선한다. 미정의·꺼진 규격·배열(`size[]=`)·같은 키 두 번이면 원본을 내고 `X-G7-Thumbnail: bypass:unknown-preset` 을 붙인다. |
| w | query | integer | 아니오 | 1~10000 | 숫자 요청 대상 규격 중 가로가 이 값 이상인 가장 작은 규격으로 올려 맞춘다(없으면 가로가 가장 큰 규격). 정수가 아니면 원본 + `bypass:unknown-preset`. |

**요청 예시**

```http
GET /api/plugins/sirsoft-popup/images/{hash} HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON `data` 를 반환하지 않습니다. 성공 시 `ResponseHelper` envelope 없이 이미지 바이너리를 그대로 스트리밍합니다 (`Symfony\Component\HttpFoundation\StreamedResponse`). 실패(404) 시에만 표준 JSON envelope 를 반환합니다._

성공 응답의 관측 가능한 표면은 본문(이미지 바이너리)과 다음 헤더입니다. `PopupImageService::publicServing()` 이 플러그인 스토리지 스트림을 만들고 `InlineMediaPolicy::harden()` 으로 인라인 여부를 정하면, 컨트롤러가 코어 파일 서빙 헬퍼(`BaseApiController::streamedFileResponseFromMeta()`, `immutable: true`, 압축 제외)로 캐시·검증자 헤더를 붙입니다. 사이트의 다른 첨부 이미지와 같은 계약입니다.

| 헤더 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| Content-Type | string | `image/png` | 업로드 레코드의 MIME. 업로드 허용 형식은 `image/jpeg`, `image/png`, `image/webp`, `image/gif` |
| Cache-Control | string | 운영 `public, max-age=31536000, immutable` / 개발 `no-cache, private` | 운영 환경은 1년 불변 캐시(해시가 같으면 내용도 같다 — 이미지를 교체하면 새 해시). 운영 밖(`APP_ENV` ≠ production)은 코어 정책대로 매번 재검증 |
| ETag | string | `"1439af54b70320d1469c4266b4bc1b8c"` | 행 메타(해시·경로·크기·올린 시각)로 만든 검증자. `If-None-Match` 로 다시 요청하면 304 |
| Last-Modified | string | `Tue, 03 Nov 2026 16:08:03 GMT` | 이미지를 올린 시각. `If-None-Match` 가 없을 때 `If-Modified-Since` 로 재검증 |
| Expires | string | `Thu, 04 Nov 2027 00:47:49 GMT` | 요청 시각 + 1년 |
| Content-Disposition | string | `inline; filename=banner.png` | 업로드 때의 원본 파일명. 래스터 이미지는 `inline`, 그 밖의 형식은 `attachment` + CSP 격리 |
| X-Content-Type-Options | string | `nosniff` | 브라우저의 형식 추측 차단(200·304 모두) |
| X-G7-Thumbnail | string | `hit` · `miss` · `bypass:smaller-than-preset` | `size`·`w` 를 보낸 요청에만 붙는다. `hit`(이미 만든 썸네일)·`miss`(이번 요청에 만듦)·`bypass:{사유}`(원본을 냄 — 사유는 `disabled`·`no-engine`·`not-raster`·`animated`·`unknown-preset`·`smaller-than-preset`·`rate-limited`·`too-large`·`error`) |

**응답 예시**

성공 시 (HTTP 200) — JSON 이 아닌 이미지 바이너리 본문(개발 서버 실측, 운영은 `Cache-Control` 만 위 표의 운영 값):

```http
HTTP/1.1 200 OK
Content-Type: image/png
Cache-Control: no-cache, private
ETag: "1439af54b70320d1469c4266b4bc1b8c"
Last-Modified: Tue, 03 Nov 2026 16:08:03 GMT
Expires: Thu, 04 Nov 2027 00:47:49 GMT
Content-Disposition: inline; filename=mobile-800x800.png
X-Content-Type-Options: nosniff

<binary image data>
```

재검증 (`If-None-Match: "1439af54b70320d1469c4266b4bc1b8c"`) — 바뀌지 않았으면 본문 없이 304, 200 과 같은 캐시 정책·검증자를 다시 싣습니다:

```http
HTTP/1.1 304 Not Modified
Cache-Control: no-cache, private
ETag: "1439af54b70320d1469c4266b4bc1b8c"
Last-Modified: Tue, 03 Nov 2026 16:08:03 GMT
Expires: Thu, 04 Nov 2027 00:47:52 GMT
X-Content-Type-Options: nosniff
```

썸네일 (HTTP 200) — `?size=sm` 처럼 규격을 붙이면 원본 대신 그 규격의 썸네일 바이너리를 낸다. 캐시 검증자는 썸네일 파일 기준이고 캐시 시간은 레이아웃 캐시 TTL 이다:

```http
GET /api/plugins/sirsoft-popup/images/{hash}?size=sm HTTP/1.1

HTTP/1.1 200 OK
Content-Type: image/jpeg
X-G7-Thumbnail: miss
ETag: "4b1e…"
X-Content-Type-Options: nosniff

<썸네일 바이트>
```

썸네일 (HTTP 302) — 공개 이미지 썸네일 저장 위치를 공개 자산 디스크(CDN 등)로 고른 사이트는 썸네일 직접 주소로 보낸다:

```http
HTTP/1.1 302 Found
Location: https://cdn.example.com/thumbnails/plugins/sirsoft-popup/images/2026/11/{hash}.png/sm_1a2b3c4d.png
Cache-Control: public, max-age=86400
X-G7-Thumbnail: hit
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
| 304 | Not Modified | `If-None-Match`(또는 `If-None-Match` 없이 `If-Modified-Since`)가 지금 이미지와 같은 경우 — 오류가 아니라 재검증 성공 |
| 404 | Not Found | `hash` 가 라우트 제약(64자 영숫자)에 맞지 않아 라우트 미매칭 / 해시에 해당하는 이미지 행이 없음 / 행은 있으나 스토리지에 파일이 없음(`sirsoft-popup::messages.image.not_found`, 파일 없음은 서버 오류 로그도 남김) |
| 429 | Too Many Requests | 같은 클라이언트가 분당 600회를 넘겨 요청한 경우(`throttle:600,1`) |

<!-- @generated:end -->

**설명**

사용자 화면 팝업 레이어와 관리자 화면(목록 썸네일·등록/수정 미리보기)이 `<img src>` 로 직접 부르는 공개 이미지 서빙 엔드포인트입니다. 브라우저가 이미지 태그로 GET 하므로 **인증이 없습니다**.

- 저장 전 미리보기를 위해 아직 팝업에 붙지 않은 임시 업로드 이미지도 같은 주소로 내보냅니다. 64자 무작위 해시라 추측할 수 없다는 것이 공개의 전제입니다.
- 성공 응답은 이미지 바이너리 스트림이며 `ResponseHelper` envelope 로 감싸지 않습니다. 저장 경로는 응답 어디에도 싣지 않습니다.
- 공개 자산 디스크(#878): 플러그인 설정 `public_asset_disk`(비우면 코어 공개 자산 디스크)가 public·CDN 디스크면 새로 올리는 이미지가 그 디스크에 저장되고, 공개 응답 `image.*.direct_url`·관리자 응답 `direct_url` 이 그 직접 주소를 싣습니다. 이 API 주소는 그 행에서도 계속 동작하며(썸네일 `?size=` 기준·옛 캐시 호환), 서빙·삭제는 행에 기록된 디스크를 따릅니다.
- 관리자·공개 응답이 싣는 이 주소(`url`·`preview_url`·`image.pc.url` 등, 직접 주소가 없는 행의 `download_url`)는 사이트 마운트 접두사를 포함합니다. 하위 폴더 설치(`https://도메인/g7/`)에서는 `/g7/api/plugins/sirsoft-popup/images/{hash}` 이고, 루트 설치는 `/api/plugins/sirsoft-popup/images/{hash}` 입니다. 사용자 레이어·미리보기는 받은 주소에 접두사가 없으면 화면에서 한 번 더 붙입니다(이미 붙은 주소는 그대로).
- 팝업에서 떼어 낸(교체·삭제된) 이미지는 파일과 행이 지워지므로 옛 해시 주소는 404 가 됩니다. 공개 팝업 응답은 캐시되므로 교체 직후에는 캐시가 비워질 때까지(팝업 저장 시 즉시 무효화) 새 주소가 반영됩니다.
- **썸네일**: `?size={규격}` 또는 `?w={숫자}` 를 붙이면 래스터 이미지는 환경설정 > 업로드 탭의 썸네일 규격으로 줄인 사본을 냅니다. 첫 요청에 만들어 저장해 두고 다음부터는 저장본을 냅니다. PC·모바일 이미지는 기기별로 따로 올린 각자의 원본이라 각자 썸네일을 갖습니다. 해시만 알면 받는 공개 원본이라 썸네일도 공개 이미지 썸네일 저장 위치에 두며, 그 위치가 공개 자산 디스크면 302 로 직접 주소를 알려 줍니다. 규격보다 작은 원본(확대 끔)·움직이는 GIF·꺼진 기능에서는 원본을 그대로 내고 `X-G7-Thumbnail: bypass:{사유}` 로 이유를 밝히며, 이때는 코어 썸네일 헬퍼(`ThumbnailResponder::markBypass()`)가 `immutable` 을 빼고 유지 시간도 썸네일 응답과 같은 값(코어 환경설정 레이아웃 캐시 유지 시간 `cache.layout_ttl`, 1년을 넘지 않음)으로 줄입니다(운영 `public, max-age={그 값}, must-revalidate`) — 규격이나 「움직이는 이미지는 원본 사용」을 바꾸면 같은 주소가 썸네일로 바뀌어야 하기 때문입니다. 생성 제한·일시 오류로 원본을 냈으면 `no-store` 입니다. 파라미터가 없으면 응답은 이전과 같습니다. 사용자 레이어는 숫자 요청 규격(기본 md·lg·xl) 중 원본보다 작은 것과 원본을 `srcset` 으로 싣고 레이어 배치로 `sizes` 를 주며, 관리 목록은 `?size=sm` 을 받습니다.
