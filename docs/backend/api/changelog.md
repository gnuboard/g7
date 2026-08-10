# Changelog API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Changelog 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/admin/changelog
<!-- @generated:start:api.admin.changelog -->
- **라우트명**: `api.admin.changelog`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\LicenseController@changelog`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/changelog HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| content | string | `# Changelog  이 프로젝트의 모든 주요 변경사항을 기록합니…` | 본문 내용 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "content": "# Changelog\n\n이 프로젝트의 모든 주요 변경사항을 기록합니다.\n형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,\n[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.\n\n## [7.0.6] - 2026-07-19\n\n### Security\n\n- 보안 환경설정의 계정 잠금 시간을 `0`(무한대)으로 설정하면 실제로 무기한 잠기도록 수정했습니다. 화면 안내는 \"0을 입력하면 무한대\"였지만 실제로는 1분 뒤 자동 해제되어, 무차별 대입 시도를 영구 차단할 수 없었습니다. 무기한 잠긴 계정에는 남은 시간 대신 관리자 문의 안내가 표시됩니다. (#81 @jiwonpapa 님께서 제보해주셨습니… (생략)"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명** 코어의 `CHANGELOG.md` 파일 원문 텍스트를 `content`로 반환합니다. `auth:sanctum` 인증이 필요하며, 파일이 없으면 404(`common.not_found`)를 반환합니다. 관리자 화면에서 코어의 전체 변경 이력을 마크다운 원문 그대로 표시하는 용도로 사용합니다.


