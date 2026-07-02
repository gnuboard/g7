# 이커머스 모듈 Playwright E2E (sample skeleton)

본 디렉토리는 이커머스 모듈이 자체 E2E spec 을 추가하기 위한 sample skeleton 이다.
**현재 모든 spec 은 `test.skip` 으로 비활성화** 되어 있으며, 모듈 작업 세션에서 활성화한다.

## 디렉토리 구조

```
modules/_bundled/sirsoft-ecommerce/
├── src/Console/Commands/
│   └── PlaywrightSeedEcommerce.php       (도메인 시드 커맨드 — stub, LogicException)
└── tests/Playwright/
    ├── playwright.config.ts              (모듈 자체 config — testDir=./specs)
    ├── fixtures/
    │   ├── ecommerce-auth.ts             (모듈 권한 토큰 fixture)
    │   └── ecommerce-seed.ts             (도메인 시드 fixture)
    ├── specs/
    │   └── admin/
    │       └── ecommerce-settings-menu.spec.ts  (placeholder — test.skip)
    └── README.md                         (본 문서)
```

## 데이터 생성 위치 분리 (CRITICAL)

E2E spec 이 "특정 유저 + 특정 역할 + 특정 도메인 상황" 에서 동작하려면 백엔드 데이터 생성 코드가
필요하다. **그 코드는 데이터를 소유한 영역에 위치해야 한다** — 기존 G7 의 Seeder/Factory 분리 원칙과 동일.

| 데이터 종류 | 위치 |
|---|---|
| 코어 권한 (`core.*`), 역할, 유저, Sanctum 토큰 | 코어 `app/Console/Commands/PlaywrightIssueToken.php` |
| 모듈 권한 (`sirsoft-ecommerce.*`) | 코어 커맨드의 `--permissions=` 임의 식별자 (Permission::firstOrCreate 자동 생성) |
| 모듈 도메인 데이터 (상품/카테고리/주문/배송지) | 본 모듈 `src/Console/Commands/PlaywrightSeedEcommerce.php` |
| 외부 의존 데이터 (토스 결제창 응답 등) | spec 안에서 `page.route()` mock |

**핵심 원칙**: 코어는 모듈 도메인을 모른다. 모듈 도메인 시드 커맨드를 코어에 두면 코어 ↔ 모듈 의존이 뒤집힘.

## 활성화 절차

### 1. PlaywrightSeedEcommerce 본문 구현

`src/Console/Commands/PlaywrightSeedEcommerce.php` 의 `throw new \LogicException(...)` 부분을
실제 시드 로직으로 교체:

```php
$categoryIds = Category::factory()->count((int) $this->option('categories'))->create()->pluck('id')->toArray();
$productIds = Product::factory()
    ->count((int) $this->option('products'))
    ->state(fn () => ['category_id' => $categoryIds[array_rand($categoryIds)]])
    ->create()
    ->pluck('id')
    ->toArray();
$orderIds = Order::factory()->count((int) $this->option('orders'))->create()->pluck('id')->toArray();

$payload = ['productIds' => $productIds, 'categoryIds' => $categoryIds, 'orderIds' => $orderIds];

if ($this->option('json')) {
    $this->line(json_encode($payload));
} else {
    $this->info(sprintf('시드 완료 — products:%d, categories:%d, orders:%d', count($productIds), count($categoryIds), count($orderIds)));
}

return self::SUCCESS;
```

### 2. 사전 testid 보강

templates/sirsoft-admin_basic 사이드바 메뉴 컴포넌트:
```tsx
<a data-testid="admin-menu-ecommerce-settings" href="/admin/ecommerce/settings">...</a>
```

이커머스 환경설정 폼 컴포넌트:
```tsx
<form data-testid="ecommerce-settings-form">...</form>
```

### 3. spec 의 test.skip 제거

`specs/admin/ecommerce-settings-menu.spec.ts` 의 `test.describe.skip(...)` → `test.describe(...)`.

### 4. 모듈 자체 실행

```powershell
cd modules/_bundled/sirsoft-ecommerce
$env:PLAYWRIGHT_BASE_URL='https://g7.dev'
npx playwright test
```

또는 모듈 `package.json` 에 script 추가:
```json
"test:e2e": "playwright test --config=tests/Playwright/playwright.config.ts"
```

## 토스페이먼츠 결제 spec (외부 의존 시나리오)

토스페이먼츠 플러그인 결제 흐름은 외부 도메인(`js.tosspayments.com`) 의존 — **mock-first 전략** 필수.

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

// 토스 confirm API mock — 가맹점 측 callback 흐름만 검증
await page.route('https://api.tosspayments.com/v1/payments/confirm', route => route.fulfill({
  status: 200, contentType: 'application/json',
  body: JSON.stringify({
    paymentKey: 'test_pk_xxx', orderId: 'order_001', status: 'DONE',
    method: '카드', totalAmount: 10000, approvedAt: new Date().toISOString(),
  }),
}));
```

분기별 가맹점 측 처리(주문 상태 전환, 재고 차감, 알림 발송 등) 가 정확한지 검증.
실 카드 결제 자동화는 권장하지 않음 — 수동 검증.

## 참고

- 코어 가이드: `tests/Playwright/README.md`
- 시나리오 매니페스트 매핑: `tests/scenarios/*.yaml` ↔ spec describe.parallel + matrix 배열
- 코어 fixture: `tests/Playwright/fixtures/auth.ts` (`issueToken`, `authenticatePage`)
