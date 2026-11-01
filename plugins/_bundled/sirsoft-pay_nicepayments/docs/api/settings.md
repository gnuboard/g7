# Settings(설정 상태) API 레퍼런스

> **소유**: plugin `sirsoft-pay_nicepayments` · 사람이 서술한 레퍼런스. 관리자 설정 화면이 쓰는 상태 조회 경로를 다룬다.

---

## TL;DR (5초 요약)

```text
1. 관리자 설정 화면이 쓰는 테스트 모드 상태 조회
2. auth:sanctum + admin + permission:sirsoft-ecommerce.settings.read
3. 테스트 모드 상태는 설정을 저장한 적이 없으면 true(테스트 모드)다
4. 같은 화면이 쓰는 입금통보 URL 조회(admin/vbank-notify-url)는 vbank.md 에 있다
5. 쓰기(설정 저장)는 코어 플러그인 설정 API 가 맡는다. 이 문서 범위가 아니다
```

---

### GET /api/plugins/sirsoft-pay_nicepayments/admin/settings/test-mode-status
<!-- @generated:start:api.plugins.sirsoft-pay_nicepayments.admin.settings.test-mode-status -->
- **라우트명**: `api.plugins.sirsoft-pay_nicepayments.admin.settings.test-mode-status`
- **컨트롤러**: `Plugins\Sirsoft\PayNicepayments\Controllers\AdminSettingsStatusController@testMode`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-ecommerce.settings.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/sirsoft-pay_nicepayments/admin/settings/test-mode-status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| is_test_mode | boolean | `true` | 플러그인 설정 `is_test_mode` 값. `true` 면 테스트 상점 자격으로 결제한다. 설정을 저장한 적이 없으면 `true` |

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
| 403 | Forbidden | 관리자가 아니거나 요구 권한(`sirsoft-ecommerce.settings.read`)이 없는 경우 |

<!-- @generated:end -->

**설명**

플러그인의 테스트 모드 여부를 반환한다. 설정을 저장한 적이 없으면 기본값 `true`(테스트 모드)로 내려간다. 라우트 그룹의 `admin` 미들웨어도 함께 거친다.

입금통보 수신 주소 조회(`GET /api/plugins/sirsoft-pay_nicepayments/admin/vbank-notify-url`)는 [vbank.md](vbank.md) 에서 다룬다.
