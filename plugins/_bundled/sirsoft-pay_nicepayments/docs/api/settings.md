# Settings(설정 상태) API 레퍼런스

> **소유**: plugin `sirsoft-pay_nicepayments` · 사람이 서술한 레퍼런스. 관리자 설정 화면이 쓰는 상태 조회 경로를 다룬다.

---

## TL;DR (5초 요약)

```text
1. 관리자 설정 화면용 조회 2종 — 테스트 모드 상태 / 입금통보 URL 안내
2. 두 경로 모두 auth:sanctum + permission:sirsoft-ecommerce.settings.read
3. 테스트 모드 상태는 미설정 시 true(테스트 모드)가 기본값이다
4. 입금통보 URL 은 나이스페이먼츠 상점관리자에 등록할 값을 화면에 보여주기 위한 조회다
5. 쓰기(설정 저장)는 코어 플러그인 설정 API 가 담당 — 이 문서 범위 아님
```

---

### GET /api/plugins/sirsoft-pay_nicepayments/admin/settings/test-mode-status

- **라우트명**: `api.plugins.sirsoft-pay_nicepayments.settings.test-mode-status`
- **컨트롤러**: `AdminSettingsStatusController@testMode`
- **인증/권한**: `auth:sanctum` + `permission:admin,sirsoft-ecommerce.settings.read`

플러그인의 테스트 모드 여부를 반환한다. 설정 미저장 상태의 기본값은 `true`(테스트 모드).

```json
{ "success": true, "data": { "is_test_mode": true } }
```

### GET /api/plugins/sirsoft-pay_nicepayments/admin/vbank-notify-url

- **라우트명**: `api.plugins.sirsoft-pay_nicepayments.vbank.notify.url`
- **컨트롤러**: 라우트 클로저 (`src/routes/api.php`)
- **인증/권한**: `auth:sanctum` + `permission:admin,sirsoft-ecommerce.settings.read`

가상계좌 **입금통보 수신 URL** 을 반환한다. 운영자가 나이스페이먼츠 상점관리자에 등록할 주소를 설정 화면에 표시하는 용도다. 수신 경로의 동작·IP 제한은 [vbank.md](vbank.md) 참조.

```json
{ "success": true, "data": { "url": "https://example.com/plugins/sirsoft-pay_nicepayments/payment/vbank-notify" } }
```
