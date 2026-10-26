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

_JSON 이 아니라 이미지 본문을 돌려준다. 실측 응답 헤더:_

| 헤더 | 실측 값 | 의미 |
| --- | --- | --- |
| Content-Type | `image/jpeg` | 저장된 형식(JPG·PNG·WEBP) |
| Cache-Control | `immutable, max-age=31536000, public` | 해시가 불변이라 1년 캐시 |
| Content-Disposition | `inline; filename=pc.jpg` | 브라우저 안에서 표시(원래 파일명) |
| X-Content-Type-Options | `nosniff` | 형식 추측 금지(`InlineMediaPolicy`) |

**응답 예시**

```http
HTTP/1.1 200 OK
Content-Type: image/jpeg
Cache-Control: immutable, max-age=31536000, public
Content-Disposition: inline; filename=pc.jpg
X-Content-Type-Options: nosniff

<이미지 바이트>
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**

배너에 연결된 이미지를 해시(64자)로 내보낸다. 래스터(JPG·PNG·WEBP)만 인라인, 모든 응답 nosniff, 해시가 바뀌지 않는 한 내용도 그대로라 `Cache-Control: public, max-age=31536000, immutable`. 등록 화면에서 올리기만 한 임시 이미지는 공개하지 않아 404. 분당 600회 제한.

