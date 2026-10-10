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

**설명** 클레임 첨부 이미지는 공개 스토리지 경로를 갖지 않습니다. 신청서 응답(`ClaimImageResource`)의 `download_url` 은 30분 유효한 한시 서명 URL 이고, 이 엔드포인트는 그 서명(`hasValidSignature`)을 먼저 검사합니다. 서명이 없거나 만료된 요청은 로그인한 신청자 본인이거나 관리자 권한 `sirsoft-ecommerce.claims.read` 를 가진 경우에만 통과하며, 그 밖에는 첨부의 존재 여부를 드러내지 않도록 전부 404 로 응답합니다. 비회원 신청자는 서명 URL 로만 열 수 있습니다(토큰 인증이 아닌 서명이 열람 근거). 응답은 파일 스트림이므로 `<img>` 의 `src` 에 그대로 넣어 쓰고, 만료된 뒤에는 신청서를 다시 조회해 새 `download_url` 을 받습니다.
