# Playwright E2E (코어 영역)

G7 코어의 Playwright (TypeScript) 기반 결정론적 E2E 인프라.

## 빠른 시작

```powershell
# PowerShell (개발 환경)
$env:PLAYWRIGHT_BASE_URL='https://g7.dev'
npm run test:e2e            # 전체 spec
npm run test:e2e:smoke      # @smoke 태그 (homepage / login / admin-dashboard 3건)
npm run test:e2e:wysiwyg    # 위지윅 매트릭스 90건 (Phase M)
npm run test:e2e:ui         # Playwright UI 모드 (디버깅)
```

```bash
# Bash (CI/Linux)
PLAYWRIGHT_BASE_URL=https://g7.dev npx playwright test
```

## Base URL 해석 우선순위 (하드코딩 회피)

`playwright.config.ts` 의 `resolveBaseUrl()` 가 다음 순서로 결정:

1. **`PLAYWRIGHT_BASE_URL` 환경변수** — CI / 명시적 오버라이드 (최우선)
2. **`.env` 의 `APP_URL`** — 단 `http://localhost` 류는 fallback 부적합 (Apache vhost 미경유)
3. **그 외** — 명시 에러 (의도적으로 base URL 을 지정하지 않으면 spec 미실행)

도메인이 가변(개발자/CI/운영 환경별 다름)이므로 fallback 하드코딩 금지.

## 디렉토리 구조

```
tests/Playwright/
├── fixtures/
│   └── auth.ts                        (Sanctum 토큰 발급 + addInitScript 주입)
├── specs/
│   ├── smoke/                         (Phase 1 — 3건)
│   │   ├── homepage.spec.ts
│   │   ├── login.spec.ts
│   │   └── admin-dashboard.spec.ts
│   └── wysiwyg/                       (Phase 2 — 90건, 위지윅 편집기 Phase M)
│       ├── auth-guard.spec.ts                (12: access_outcome × user_permission)
│       ├── handler-suppression.spec.ts       (6:  suppressed_handler 축)
│       ├── anchor-intercept.spec.ts          (45: anchor_kind × modifier_key)
│       └── url-template-param.spec.ts        (27: url_template_param × anchor_kind)
├── .auth/                             (gitignore — storageState 캐시)
└── README.md                          (본 문서)
```

## 코어/확장 분리 원칙

기존 PHPUnit / Vitest 와 동일 원칙. **모듈/플러그인/템플릿 E2E spec 을 코어 디렉토리에 작성 금지.**

| 영역 | spec 위치 | config 위치 |
|---|---|---|
| **코어** | `tests/Playwright/specs/` | `playwright.config.ts` (루트) |
| 모듈 | `modules/_bundled/{id}/tests/Playwright/specs/` | 모듈 자체 `playwright.config.ts` |
| 플러그인 | `plugins/_bundled/{id}/tests/Playwright/specs/` | 플러그인 자체 |
| 템플릿 | `templates/_bundled/{id}/tests/Playwright/specs/` | 템플릿 자체 |

확장이 자체 E2E spec 을 추가하는 sample skeleton 은
`modules/_bundled/sirsoft-ecommerce/tests/Playwright/` 참조.

## 인증 가드 (PlaywrightIssueToken 3중 보안)

`app/Console/Commands/PlaywrightIssueToken.php` 는 다음 3중 가드를 강제한다:

1. **CLI 한정** — `php_sapi_name() === 'cli'` (production 웹 요청에서 절대 도달 불가)
2. **`G7_PLAYWRIGHT_BYPASS=1` 환경변수 옵트인** — `.env` 영구 수정 불요, 인라인 부착만으로 활성화
3. **`APP_DEBUG=true` inline override** — `production + debug=false` 환경에서도 토큰 발급 가능

호출은 `fixtures/auth.ts` 의 `issueToken()` 헬퍼가 자동으로 `G7_PLAYWRIGHT_BYPASS=1` 을 부착하므로
spec 작성자는 가드를 인지할 필요 없음.

```typescript
import { issueToken, authenticatePage } from '../../fixtures/auth';

test('...', async ({ page }) => {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto('/admin/dashboard');
});
```

권한 식별자는 임의 string — 코어/모듈/플러그인 공통 API. `Permission::firstOrCreate` 가 자동 생성.

## 데이터 생성 위치 — 책임 분리 매트릭스

E2E spec 이 "특정 유저 + 특정 역할 + 특정 도메인 상황" 에서 동작하려면 백엔드 데이터 생성 코드가
필요하다. **그 코드는 데이터를 소유한 영역에 위치해야 한다** — 기존 Seeder/Factory 분리 원칙과 동일.

| 데이터 종류 | 책임 영역 | 위치 |
|---|---|---|
| 코어 권한 (`core.*`), 역할, 유저, Sanctum 토큰 | 코어 | `app/Console/Commands/PlaywrightIssueToken.php` |
| 모듈 권한 (`sirsoft-ecommerce.*`) | 모듈 (코어 커맨드의 `--permissions=` 임의 식별자) | — |
| 모듈 도메인 데이터 (상품/카테고리/주문) | 모듈 | `modules/_bundled/{id}/src/Console/Commands/PlaywrightSeed{id}.php` |
| 플러그인 도메인 데이터 (결제 키 등) | 플러그인 | `plugins/_bundled/{id}/src/Console/Commands/PlaywrightSeed{id}.php` |
| 외부 의존 (토스 결제창 응답 등) | spec 안 mock | `page.route('https://api.tosspayments.com/**', ...)` |

**핵심 원칙**: 코어는 모듈 도메인을 모른다. 모듈 도메인 시드 커맨드를 코어에 두면 코어↔모듈 의존이 뒤집힘 (audit 룰 위반).

## 시나리오 매니페스트 ↔ spec 매핑

| YAML axis | spec 파일 | 케이스 |
|---|---|---|
| `tests/scenarios/wysiwyg-editor-access-guard.yaml` `cross_product[0]` (access_outcome × user_permission) | `specs/wysiwyg/auth-guard.spec.ts` | 12 |
| 동일 yaml `cross_product[1]` (anchor_kind × modifier_key) | `specs/wysiwyg/anchor-intercept.spec.ts` | 45 |
| 동일 yaml `cross_product[2]` (suppressed_handler) | `specs/wysiwyg/handler-suppression.spec.ts` | 6 |
| 동일 yaml `cross_product[3]` (url_template_param × anchor_kind) | `specs/wysiwyg/url-template-param.spec.ts` | 27 |

각 spec 은 `test.describe.parallel(axisName)` 으로 묶고 matrix 배열로 매개변수화.
케이스 docblock 에 `// @scenario k1=v1, k2=v2` / `// @effects e1, e2` 마킹.

## 외부 의존 시나리오 — mock-first 패턴 (결제 등)

토스페이먼츠 등 외부 도메인 결제 흐름은 결정론적 자동화에 **mock-first 전략** 필수.

```typescript
// 토스 SDK 호출 가로채기 — 결제창 띄우지 않고 즉시 success callback URL 로 redirect
await page.route('https://js.tosspayments.com/**', route => route.fulfill({
  contentType: 'application/javascript',
  body: `
    window.TossPayments = () => ({
      requestPayment: async (method, params) => {
        window.location.href = params.successUrl
          + '?paymentKey=test_pk_xxx&orderId=' + params.orderId + '&amount=' + params.amount;
      }
    });
  `,
}));

// 토스 confirm API mock — 가맹점 측 callback 흐름 검증
await page.route('https://api.tosspayments.com/v1/payments/confirm', route => route.fulfill({
  status: 200, contentType: 'application/json',
  body: JSON.stringify({
    paymentKey: 'test_pk_xxx', orderId: 'order_001', status: 'DONE',
    method: '카드', totalAmount: 10000, approvedAt: new Date().toISOString(),
  }),
}));
```

분기별(성공/실패/취소/금액 불일치/중복) 가맹점 측 처리(주문 상태 전환, 재고 차감, 알림 발송) 정확성 검증.
실 카드 결제 자동화는 권장하지 않음 — 수동 검증.

## 회귀 / Flaky 처리

- `retries: process.env.CI ? 2 : 0` — CI 에서만 자동 retry
- `trace: 'retain-on-failure'` — 실패 시 trace.zip 보존 (`npx playwright show-trace ...`)
- 2주 내 2회 이상 fail = `@flaky` 태그 격리 + RCA

## 디버깅 워크플로우

```powershell
# UI 모드 — 단계별 step 시각화
$env:PLAYWRIGHT_BASE_URL='https://g7.dev'; npm run test:e2e:ui

# 특정 spec 만 실행
PLAYWRIGHT_BASE_URL=https://g7.dev npx playwright test tests/Playwright/specs/wysiwyg/auth-guard.spec.ts

# trace 열기
npx playwright show-trace test-results/<...>/trace.zip
```

Playwright 가 회귀를 발견하면 → Claude `chrome-devtools-mcp` 로 라이브 재현/진단 → spec 으로 승격.

## 참고

- 가이드: `docs/testing/e2e-testing.md`
- 시나리오 매니페스트: `tests/scenarios/wysiwyg-editor-access-guard.yaml`
- 단위 시뮬레이션 (Vitest): `resources/js/core/template-engine/wysiwyg/__tests__/previewNavigation.test.ts` 등
- 모듈 sample skeleton: `modules/_bundled/sirsoft-ecommerce/tests/Playwright/README.md`
