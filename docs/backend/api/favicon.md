# Favicon API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 업로드한 파비콘을 브라우저 탭 아이콘으로 내보내는 공개 경로다 (7.2.0, #434)
2. GET /api/favicon/{hash} — 인증 없음, 파비콘 컬렉션 첨부만 내보낸다
3. 응답은 파일 본문(이미지)이고 1년 immutable 캐시 헤더가 붙는다
4. 일반 첨부 다운로드는 GET /api/attachment/{hash} (attachment.md)
5. 화면 <head> 의 링크는 서버가 우선순위(활성 템플릿 설정 > 일반 탭 파비콘 > 번들 기본)로 골라 넣는다
```

---


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
