# Settings(설정 상태) API 레퍼런스

> **소유**: plugin `sirsoft-pay_kginicis` · 사람이 서술한 레퍼런스. 관리자 설정 화면이 쓰는 상태 조회 경로를 다룬다. 같은 그룹의 입금통보 URL 조회(`admin/vbank-notify-url`)는 [vbank.md](vbank.md) 가 소유한다.

---

## TL;DR (5초 요약)

```text
1. 관리자 설정 화면용 테스트 모드 상태 조회 1종
2. auth:sanctum + admin + permission:sirsoft-ecommerce.settings.read
3. 설정 미저장 상태의 기본값은 true(테스트 모드)다
4. 쓰기(설정 저장)는 코어 플러그인 설정 API 가 담당 — 이 문서 범위 아님
5. 형제 플러그인(nicepayments·nhnkcp)과 같은 응답 모양이다
```

---

### GET /api/plugins/sirsoft-pay_kginicis/admin/settings/test-mode-status

- **라우트명**: `api.plugins.sirsoft-pay_kginicis.admin.settings.test-mode-status`
- **컨트롤러**: `Plugins\Sirsoft\PayKginicis\Controllers\AdminSettingsStatusController@testMode`
- **인증/권한**: `auth:sanctum` + `admin` + `permission:admin,sirsoft-ecommerce.settings.read`

플러그인의 테스트 모드 여부만 반환한다. 설정 미저장 상태의 기본값은 `true`(테스트 모드). 관리자 주문·설정 화면이 테스트 모드 배지를 그리는 데 쓴다.

**응답 (200)**

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

| 상태코드 | 발생 조건 |
| --- | --- |
| 401 | 토큰 없음·만료 |
| 403 | 관리자가 아니거나 `sirsoft-ecommerce.settings.read` 권한이 없는 경우 |
