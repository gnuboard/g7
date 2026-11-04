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

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 304 | Not Modified | `If-None-Match`(또는 `If-None-Match` 없이 `If-Modified-Since`)가 지금 파일과 같은 경우 — 오류가 아니라 재검증 성공 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**

배너에 연결된 이미지·영상을 해시(64자)로 내보낸다. 래스터(JPG·PNG·WEBP)만 인라인, 영상(MP4·WEBM, 업로드 때 내용으로 판정)은 인라인 재생하되 로컬 디스크면 범위 요청을 받는다(`Accept-Ranges: bytes`, `Range: bytes=0-99` → `206` + `Content-Range: bytes 0-99/<크기>` — iOS Safari 는 범위 요청을 받지 않는 영상을 재생하지 않는다. 원격 디스크는 스트림). 모든 응답 nosniff. 해시가 바뀌지 않는 한 내용도 그대로라 운영 환경은 `Cache-Control: public, max-age=31536000, immutable` 이고, 개발 환경은 코어 파일 서빙 정책대로 `no-cache` 다. 검증자(ETag·Last-Modified)로 재검증하면 304 를 돌려준다. 관리자·공개 응답이 싣는 이 주소(`url`·`download_url`·`images.pc.url`·`videos.pc.url` 등)는 사이트 마운트 접두사를 포함한다 — 하위 폴더 설치(`https://도메인/g7/`)에서는 `/g7/api/plugins/sirsoft-hero_section/images/{hash}`, 루트 설치는 `/api/plugins/sirsoft-hero_section/images/{hash}`. 사용자 슬라이더는 받은 주소에 접두사가 없으면 화면에서 한 번 더 붙인다(이미 붙은 주소는 그대로). 저장 전 미리보기(관리자 업로더 썸네일·미리보기 슬라이더)를 위해 아직 배너에 붙지 않은 임시 이미지도 해시로 내보낸다 — 해시는 64자 무작위라 올린 사람만 안다. 모르는 해시는 404. 분당 600회 제한.

