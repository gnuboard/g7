# Settings API 레퍼런스

> **소유**: plugin `sirsoft-pay_kginicis` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Settings 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/sirsoft-pay_kginicis/admin/settings/test-mode-status
<!-- @generated:start:api.plugins.sirsoft-pay_kginicis.admin.settings.test-mode-status -->
- **라우트명**: `api.plugins.sirsoft-pay_kginicis.admin.settings.test-mode-status`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\AdminSettingsStatusController@testMode`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-pay_kginicis/admin/settings/test-mode-status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| is_test_mode | boolean | `true` | test mode 여부 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "is_test_mode": true
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-ecommerce.settings.read`)이 없는 경우 |

<!-- @generated:end -->

**설명** 관리자 환경설정·주문 화면이 KG 이니시스 플러그인이 지금 **테스트 모드**로 동작하는지를 배지로 표시하기 위해 읽는 조회 전용 엔드포인트입니다. 값은 플러그인 설정에 저장된 모드 그대로이며, 결제창·승인 API 가 이 값으로 테스트/운영 MID 와 서명키를 고릅니다. 운영 도메인에서 `is_test_mode` 가 `true` 로 남아 있으면 실제 결제가 성립하지 않으므로, 상점 오픈 점검에서 이 응답을 먼저 확인합니다. 설정 조회 권한만 요구하고 값을 바꾸지 않습니다.
