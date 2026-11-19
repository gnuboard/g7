# Claim Image API 레퍼런스

> **소유**: 모듈 `sirsoft-ecommerce` · **생성**: `php artisan api:docgen` (실측 기반).
> 이 문서는 클레임(교환/반품/취소·환불) 첨부 이미지 서빙 엔드포인트를 다룹니다.

## TL;DR (5초 요약)

```text
1. 첨부 이미지는 직접 URL 이 없다 — 응답의 download_url(한시 서명 URL, 30분) 로만 연다
2. 서명이 없거나 만료되면 신청자 본인(회원) 또는 운영자(sirsoft-ecommerce.claims.read)만 열 수 있다
3. 둘 다 아니면 존재 여부를 드러내지 않고 404 를 돌려준다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

## 엔드포인트 목록

| 메서드 | URI | 라우트명 |
| --- | --- | --- |
| GET | /api/modules/sirsoft-ecommerce/claim-image/{hash} | `api.modules.sirsoft-ecommerce.claim-image.download` |

---

### GET /api/modules/sirsoft-ecommerce/claim-image/{hash}
<!-- @generated:start:api.modules.sirsoft-ecommerce.claim-image.download -->
- **라우트명**: `api.modules.sirsoft-ecommerce.claim-image.download`
- **컨트롤러**: `Modules\Sirsoft\Ecommerce\Http\Controllers\Public\ClaimImageDownloadController@download`
- **인증/권한**: `optional.sanctum` (서명 URL 이면 비로그인 허용 / 서명이 없으면 신청자 본인 또는 `sirsoft-ecommerce.claims.read` 권한)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| hash | path | string | 예 | `[a-zA-Z0-9]{12}` | 첨부 이미지 해시 (`ClaimImageResource.hash`) |
| expires | query | integer | 아니오 | — | 서명 URL 만료 시각(Unix time) — `download_url` 에 포함되어 내려온다 |
| signature | query | string | 아니오 | — | 서명 URL 서명 — `download_url` 에 포함되어 내려온다 |
| size | query | string | 아니오 | 사용 중인 썸네일 규격 이름 (`xs`·`sm`·`md`·`lg`·`xl`·운영자 규격·확장 규격) | 이 규격의 썸네일을 낸다. `w` 보다 우선한다. 미정의·꺼진 규격·배열(`size[]=`)·같은 키 두 번이면 원본을 내고 `X-G7-Thumbnail: bypass:unknown-preset` 을 붙인다. |
| w | query | integer | 아니오 | 1~10000 | 숫자 요청 대상 규격 중 가로가 이 값 이상인 가장 작은 규격으로 올려 맞춘다(없으면 가로가 가장 큰 규격). 정수가 아니면 원본 + `bypass:unknown-preset`. |

**요청 예시**

```http
GET /api/modules/sirsoft-ecommerce/claim-image/a1B2c3D4e5F6?expires=1787000000&signature=0f3c... HTTP/1.1
Host: api.example.com
Accept: image/*
```

**응답**

성공 시 이미지 바이너리를 스트리밍합니다 (`Content-Type` 은 저장된 `mime_type`). JSON 봉투가 아닙니다.

**오류 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 해시에 해당하는 첨부가 없거나, 서명이 유효하지 않고 열람자가 신청자 본인·운영자도 아닌 경우 (`claim_image_not_found` — 세 경우를 구분하지 않는다) |

<!-- @generated:end -->

**설명** 클레임 첨부 이미지는 공개 스토리지 경로를 갖지 않습니다. 신청서 응답(`ClaimImageResource`)의 `download_url` 은 30분 유효한 한시 서명 URL 이고, 이 엔드포인트는 그 서명(썸네일 파라미터 `size`·`w` 를 뺀 상대 서명 — `hasValidRelativeSignatureWhileIgnoring`)을 먼저 검사합니다. 서명이 없거나 만료된 요청은 로그인한 신청자 본인이거나 관리자 권한 `sirsoft-ecommerce.claims.read` 를 가진 경우에만 통과하며, 그 밖에는 첨부의 존재 여부를 드러내지 않도록 전부 404 로 응답합니다. 비회원 신청자는 서명 URL 로만 열 수 있습니다(토큰 인증이 아닌 서명이 열람 근거). 응답은 파일 스트림이므로 `<img>` 의 `src` 에 그대로 넣어 쓰고, 만료된 뒤에는 신청서를 다시 조회해 새 `download_url` 을 받습니다. 사이트를 하위 폴더(예: `/g7`)에 설치하면 이 서명 URL 은 설치 폴더 접두사를 실은 경로(`/g7/api/…`)로 내려간다 — 서명은 접두사를 뺀 경로에 걸리고 검증도 접두사를 뺀 요청 경로로 하므로, 그 주소 그대로 열면 서명이 유효하다.

**썸네일** `?size={규격}` 또는 `?w={숫자}` 를 붙이면 위 게이트(한시 서명·신청자 본인·운영자)를 지난 래스터 이미지는 환경설정 > 업로드 탭의 썸네일 규격으로 줄인 사본을 냅니다. 게이트를 지나지 못하면 원본과 같은 404 입니다 — 썸네일이 접근 범위를 넓히지 않습니다. 클레임 첨부는 권한이 걸린 원본이라 **원본이 어느 디스크에 있든** 썸네일은 비공개 썸네일 저장 위치(권한이 걸린 이미지 썸네일)에만 저장하고, 302 없이 서버가 직접 스트리밍하며 `Cache-Control` 은 `private` 입니다(프로덕션 `private, max-age=300` — 원본과 같은 상한이라 레이아웃 캐시 TTL 이 더 짧으면 그 값, 그 외 `private, no-cache`) — 공유 캐시(CDN, 프록시)가 저장하지 못하게, 그리고 원본보다 오래 브라우저에 남지 않게. 원본 파일이 저장소에서 사라진 첨부는 이미 만든 썸네일이 남아 있어도 원본과 같은 404 입니다. 한시 서명은 `size`·`w` 를 빼고 검증하므로 응답이 준 서명 주소 뒤에 `&size=sm` 을 덧붙여도 서명이 유효합니다(서명 대상 쿼리를 바꾸거나 만료되면 원본과 같이 거부). 규격보다 작은 원본(확대 끔)·움직이는 GIF·WebP·APNG(「움직이는 이미지는 원본 사용」이 켜져 있을 때)·SVG·꺼진 기능에서는 원본을 그대로 내고 `X-G7-Thumbnail: bypass:{사유}` 로 이유를 밝힙니다. 파라미터가 없으면 응답은 이전과 같습니다(헤더도 붙지 않습니다).

```http
GET /api/modules/sirsoft-ecommerce/claim-image/{hash}?expires=1787000000&signature=0f3c…&size=sm HTTP/1.1

HTTP/1.1 200 OK
Content-Type: image/jpeg
Cache-Control: private, max-age=300
X-G7-Thumbnail: hit
X-Content-Type-Options: nosniff

<썸네일 바이너리>
```

응답 헤더 `X-G7-Thumbnail` 은 `size`·`w` 를 보낸 요청에만 붙습니다. `hit`(이미 만든 썸네일)·`miss`(이번 요청에 만듦)·`bypass:{사유}`(원본을 냄 — 사유는 `disabled`·`no-engine`·`not-raster`·`animated`·`unknown-preset`·`smaller-than-preset`·`rate-limited`·`too-large`·`error`) 중 하나입니다.
