# Images API 레퍼런스

> **소유**: plugin `sirsoft-hero_section` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

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


### GET /api/plugins/sirsoft-hero_section/images/{hash}
<!-- @generated:start:api.plugins.sirsoft-hero_section.images.show -->
- **라우트명**: `api.plugins.sirsoft-hero_section.images.show`
- **컨트롤러**: `Plugins\Sirsoft\HeroSection\Http\Controllers\Public\BannerImageController@show`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| hash | path | string | 예 | — | 대상 리소스의 해시 식별자 |
| size | query | string | 아니오 | 사용 중인 썸네일 규격 이름 (`xs`·`sm`·`md`·`lg`·`xl`·운영자 규격·확장 규격) | 이 규격의 썸네일을 낸다. `w` 보다 우선한다. 미정의·꺼진 규격·배열(`size[]=`)·같은 키 두 번이면 원본을 내고 `X-G7-Thumbnail: bypass:unknown-preset` 을 붙인다. |
| w | query | integer | 아니오 | 1~10000 | 숫자 요청 대상 규격 중 가로가 이 값 이상인 가장 작은 규격으로 올려 맞춘다(없으면 가로가 가장 큰 규격). 정수가 아니면 원본 + `bypass:unknown-preset`. |

**요청 예시**

```http
GET /api/plugins/sirsoft-hero_section/images/{hash} HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_JSON 이 아니라 이미지·영상 본문을 돌려준다. 캐시·검증자 헤더는 코어 파일 서빙 헬퍼가 붙인다(이미지와 원격 디스크 영상은 `streamedFileResponseFromMeta()`, 로컬 디스크 영상은 범위 요청을 받는 `fileResponse()` — 둘 다 `immutable: true`, 압축 제외). 응답 헤더:_

| 헤더 | 값 | 의미 |
| --- | --- | --- |
| Content-Type | `image/jpeg` | 저장된 형식(이미지 JPG·PNG·WEBP, 영상 MP4·WEBM. 그 밖의 영상 형식은 `application/octet-stream`) |
| Cache-Control | 운영 `public, max-age=31536000, immutable` / 개발 `no-cache, private` | 운영 환경은 해시가 불변이라 1년 캐시. 운영 밖(`APP_ENV` ≠ production)은 코어 정책대로 매번 재검증. 지시어 순서는 응답 종류마다 다를 수 있다 |
| ETag | `"1439af54b70320d1469c4266b4bc1b8c"` | 검증자(이미지는 행 메타 — 해시·경로·크기·올린 시각, 로컬 영상은 파일 수정 시각·크기). `If-None-Match` 로 다시 요청하면 304 |
| Last-Modified | `Tue, 03 Nov 2026 16:08:03 GMT` | 올린 시각(로컬 영상은 파일 수정 시각) |
| Expires | 요청 시각 + 1년 | |
| Content-Disposition | `inline; filename=pc.jpg` | 브라우저 안에서 표시. 래스터가 아닌 이미지 형식은 `attachment` + CSP 격리(`InlineMediaPolicy`) |
| Accept-Ranges | `bytes` | 로컬 디스크 영상만. `Range: bytes=0-99` → `206` + `Content-Range: bytes 0-99/<크기>` |
| X-Content-Type-Options | `nosniff` | 형식 추측 금지(200·206·304 모두) |
| X-G7-Thumbnail | `hit` · `miss` · `bypass:smaller-than-preset` | `size`·`w` 를 보낸 요청에만 붙는다. `hit`(이미 만든 썸네일)·`miss`(이번 요청에 만듦)·`bypass:{사유}`(원본을 냄 — 사유는 `disabled`·`no-engine`·`not-raster`·`animated`·`unknown-preset`·`smaller-than-preset`·`rate-limited`·`too-large`·`error`) |

**응답 예시**

```http
HTTP/1.1 200 OK
Content-Type: image/jpeg
Cache-Control: public, max-age=31536000, immutable
ETag: "1439af54b70320d1469c4266b4bc1b8c"
Last-Modified: Tue, 03 Nov 2026 16:08:03 GMT
Expires: Thu, 04 Nov 2027 00:47:49 GMT
Content-Disposition: inline; filename=pc.jpg
X-Content-Type-Options: nosniff

<이미지 바이트>
```

재검증(`If-None-Match` 가 지금 ETag 와 같음) — 본문 없이 304, 200 과 같은 캐시 정책·검증자를 다시 싣는다:

```http
HTTP/1.1 304 Not Modified
Cache-Control: public, max-age=31536000, immutable
ETag: "1439af54b70320d1469c4266b4bc1b8c"
Last-Modified: Tue, 03 Nov 2026 16:08:03 GMT
X-Content-Type-Options: nosniff
```

썸네일 (HTTP 200) — `?size=md` 처럼 규격을 붙이면 원본 대신 그 규격의 썸네일 바이너리를 낸다. 캐시 검증자는 썸네일 파일 기준이고 캐시 시간은 레이아웃 캐시 TTL 이다:

```http
GET /api/plugins/sirsoft-hero_section/images/{hash}?size=md HTTP/1.1

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
Location: https://cdn.example.com/thumbnails/plugins/sirsoft-hero_section/images/2026/11/{hash}.jpg/md_1a2b3c4d.jpg
Cache-Control: public, max-age=86400
X-G7-Thumbnail: hit
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 304 | Not Modified | `If-None-Match`(또는 `If-None-Match` 없이 `If-Modified-Since`)가 지금 파일과 같은 경우 — 오류가 아니라 재검증 성공 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우. `size`·`w` 를 붙여도 원본 파일이 저장소에 없으면 같은 404 다(남은 썸네일을 내지 않는다) |

<!-- @generated:end -->

**설명**

배너에 연결된 이미지·영상을 해시(64자)로 내보낸다. 래스터(JPG·PNG·WEBP)만 인라인, 영상(MP4·WEBM, 업로드 때 내용으로 판정)은 인라인 재생하되 로컬 디스크면 범위 요청을 받는다(`Accept-Ranges: bytes`, `Range: bytes=0-99` → `206` + `Content-Range: bytes 0-99/<크기>` — iOS Safari 는 범위 요청을 받지 않는 영상을 재생하지 않는다. 원격 디스크는 스트림). 모든 응답 nosniff. 해시가 바뀌지 않는 한 내용도 그대로라 운영 환경은 `Cache-Control: public, max-age=31536000, immutable` 이고, 개발 환경은 코어 파일 서빙 정책대로 `no-cache` 다. 검증자(ETag·Last-Modified)로 재검증하면 304 를 돌려준다. 관리자·공개 응답이 싣는 이 주소(`url`·`download_url`·`images.pc.url`·`videos.pc.url` 등)는 사이트 마운트 접두사를 포함한다 — 하위 폴더 설치(`https://도메인/g7/`)에서는 `/g7/api/plugins/sirsoft-hero_section/images/{hash}`, 루트 설치는 `/api/plugins/sirsoft-hero_section/images/{hash}`. 사용자 슬라이더는 받은 주소에 접두사가 없으면 화면에서 한 번 더 붙인다(이미 붙은 주소는 그대로). 저장 전 미리보기(관리자 업로더 썸네일·미리보기 슬라이더)를 위해 아직 배너에 붙지 않은 임시 이미지도 해시로 내보낸다 — 해시는 64자 무작위라 올린 사람만 안다. 모르는 해시는 404. 분당 600회 제한.

**썸네일**: `?size={규격}` 또는 `?w={숫자}` 를 붙이면 래스터 이미지(JPG·PNG·WEBP)는 환경설정 > 업로드 탭의 썸네일 규격으로 줄인 사본을 낸다. 첫 요청에 만들어 저장해 두고 다음부터는 저장본을 낸다. PC·모바일 이미지는 기기별로 따로 올린 각자의 원본이라 각자 썸네일을 갖는다. 배너 이미지는 해시만 알면 받는 공개 원본이라 썸네일도 공개 이미지 썸네일 저장 위치에 두며, 그 위치가 공개 자산 디스크면 302 로 직접 주소를 알려 준다. 규격보다 작은 원본(확대 끔)·영상·꺼진 기능에서는 원본을 그대로 내고 `X-G7-Thumbnail: bypass:{사유}` 로 이유를 밝힌다(영상은 `bypass:not-raster`). 파라미터가 없으면 응답은 이전과 같다(헤더도 붙지 않는다). 이미지를 교체하거나 지우면 그 썸네일도 함께 지워진다. 사용자 슬라이더는 이 주소에 숫자 요청 규격(기본 md·lg·xl) 중 원본보다 작은 것과 원본을 `srcset` 으로 싣고(`sizes` 는 그려진 슬라이더 상자를 잰 폭, 잴 수 없으면 `100vw`), 관리 목록은 이 플러그인 확장 규격 `?size=sirsoft-hero_section.list`(가로 240, 가로만 맞춤)를 받는다. 썸네일을 요청했는데 원본을 낼 때는 코어 썸네일 헬퍼(`ThumbnailResponder::markBypass()`)가 `immutable` 을 빼고 유지 시간도 썸네일 응답과 같은 값(코어 환경설정 레이아웃 캐시 유지 시간 `cache.layout_ttl`, 1년을 넘지 않음)으로 줄인다(운영 `public, max-age={그 값}, must-revalidate`, 영상도 같다) — 규격이나 「움직이는 이미지는 원본 사용」을 바꾸면 같은 주소가 썸네일로 바뀌어야 하기 때문이다. 생성 제한·일시 오류로 원본을 냈으면 `no-store` 다.

