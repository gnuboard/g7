# Locales API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Locales 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/locales/active
<!-- @generated:start:api.public.locales.active -->
- **라우트명**: `api.public.locales.active`
- **컨트롤러**: `App\Http\Controllers\Api\Public\LocaleController@active`
- **인증/권한**: 공개 (인증 불필요)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/locales/active HTTP/1.1
Host: api.example.com
Accept: application/json
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| locales | array | `["ko","en","fr","ja"]` | 활성 로케일 코드 배열 |
| locale_names | object | `{"ko":"한국어","en":"English","ja":"日本語","fr":"Français"}` | 로케일 코드별 표시명 맵 (config app.locale_names) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "locales.fetched",
    "data": {
        "locales": [
            "ko",
            "en",
            "fr"
        ],
        "locale_names": {
            "ko": "한국어",
            "en": "English",
            "fr": "Français"
        }
    }
}
```

**에러 응답**

_대표 에러 없음 (공개 조회). 인증·권한 미요구 엔드포인트로 도메인 특이 에러를 반환하지 않습니다._

<!-- @generated:end -->

**설명** 현재 사이트가 즉시 노출 가능한 활성 로케일 목록과 로케일별 표시명(`locale_names`) 매핑을 반환합니다. 활성 코어 언어팩을 기준으로 산출되며 인증이 필요 없는 공개 엔드포인트입니다. 언어팩 설치·활성화 직후 사용자 언어 셀렉터를 새로고침 없이 갱신하는 시나리오에 사용합니다.


