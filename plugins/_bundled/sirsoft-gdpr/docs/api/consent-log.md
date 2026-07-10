# Consent Log API 레퍼런스

> **소유**: plugin `sirsoft-gdpr` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Consent Log 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-gdpr/admin/consent-log
<!-- @generated:start:api.plugins.sirsoft-gdpr.admin.consent-log.index -->
- **라우트명**: `api.plugins.sirsoft-gdpr.admin.consent-log.index`
- **컨트롤러**: `Plugins\Sirsoft\Gdpr\Http\Controllers\Admin\GdprAdminConsentLogController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-gdpr.privacy.view`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-gdpr/admin/consent-log HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `83` | 기본 키 (내부 식별자) |
| user_id | integer | `130` | user 식별자 (연관 리소스 참조) |
| session_id | string | `c50916f6-58ee-42a6-a046-60a0c9ecfad2` | session 식별자 (연관 리소스 참조) |
| consent_key | string | `cookie_marketing` | 변경된 동의 항목 키 (쿠키 카테고리 키). 어떤 동의 항목의 부여/철회가 기록된 행인지 나타냅니다 |
| action | string | `revoked` | 이 이력 행의 변경 유형 (`granted`=동의 부여, `revoked`=철회, `acknowledged`=확인). Art.7(1) 입증 트레일의 행위 구분입니다 |
| source | string | `banner` | 동의 변경이 발생한 경로 (`banner`=쿠키 배너, `preference_center`=환경설정 센터, `mypage`=마이페이지, 그 외 `register`/`order`/`withdraw`) |
| policy_version | string | `10` | 변경 시점의 정책 버전 문자열. 해당 동의가 어느 정책 버전 기준으로 표명되었는지 기록해 정책 갱신 후 재동의 판정과 감사에 사용됩니다 |
| categories | object | `{"cookie_analytics":false,"cookie_marketing":false,"cooki…` | 변경 시점 전체 카테고리별 동의 여부 스냅샷 (키→boolean). MySQL JSON 컬럼이라 키가 알파벳 순으로 정규화 저장됩니다 |
| categories_snapshot | array | `[{"key":"cookie_necessary","label_key":"sirsoft-gdpr.cons…` | `categories` 객체를 관리자 화면 iteration 친화 배열(`{key, label_key, granted}`)로 변환한 것. 필수→분석→마케팅 UX 위계 순으로 재정렬되며 `label_key`는 프론트가 다국어로 해석합니다 |
| ip_address | string | `127.0.0.1` | 요청/행위가 발생한 IP 주소 |
| user_agent | string | `Mozilla/5.0 (Windows NT 10.0; Win64; …` | 동의 변경 요청의 User-Agent 문자열. DPO 감사 시 동의 표명 단말/브라우저를 식별하는 용도이며 회원 삭제 시 NULL로 익명화됩니다 |
| created_at | string | `2026-07-03 00:24:27` | 생성 일시 |
| user | object | `{"id":130,"uuid":"a21d03b4-df0b-4aa6-ab7a-f51143f6375a","…` | 동의 주체 회원 정보(id/uuid/name/email). 관계가 로드된 경우에만 포함되며 게스트 이력이거나 삭제로 익명화된 경우 null입니다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "messages.success",
    "data": {
        "data": [],
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 25,
            "total": 0,
            "from": null,
            "to": null
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-gdpr.privacy.view`)이 없는 경우 |

<!-- @generated:end -->

**설명** 관리자 감사 화면에서 `gdpr_user_consent_histories` 테이블의 동의 로그를 페이지네이션으로 조회합니다. `auth:sanctum`과 `sirsoft-gdpr.privacy.view` 권한이 필요합니다. `email`, `session_id`, `consent_keys[]`, `actions[]`(granted|revoked), `sources[]`(banner|preference_center|mypage), `per_page`(1~100, 기본 20) 쿼리 필터를 지원합니다. DPO 감사 용도로 IP 주소와 User-Agent까지 노출되는 조회 전용 엔드포인트입니다.


