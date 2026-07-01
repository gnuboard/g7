# 그누보드7 Playwright E2E 테스트 가이드

## TL;DR (5초 요약)

```text
- 도구: Playwright 1.49+ (TypeScript, Vitest 와 동일 스택)
- 인증: PlaywrightIssueToken artisan 커맨드가 Sanctum 토큰 발급 (CLI + G7_PLAYWRIGHT_BYPASS=1 + APP_DEBUG 3중 가드)
- Base URL: PLAYWRIGHT_BASE_URL 환경변수 우선, .env APP_URL 차순위 (하드코딩 회피)
- 코어/확장 분리: 코어 = tests/Playwright/, 확장 = {확장 디렉토리}/tests/Playwright/
- 데이터 생성: 데이터를 소유한 영역에 시드 커맨드 배치 (코어 ↔ 모듈 의존 역전 회피)
- 외부 의존: mock-first 전략 (page.route() 로 결제창/외부 API 가로채기)
```

## §1. 도구 선택 정당화

| 기준 | Playwright | Laravel Dusk | Selenium | Claude MCP |
|---|---|---|---|---|
| Windows 11 + Apache + MySQL 호환 | ✅ 1급 | ChromeDriver 이슈 | 무거움 | ✅ |
| TypeScript (resources/js 동일 스택) | ✅ | PHP 전용 | 부진 | TS 아님 |
| Sanctum 토큰 fixture | `request.newContext()` | 가능 | 가능 | 가능 |
| 결정론적 | auto-wait + fixture isolation | 보통 | 보통 | ❌ (LLM 변동) |
| 개발자 학습 곡선 | ≈ 0 (Vitest 동일 언어) | PHP 별도 | 가파름 | 자연어 |
| CI/커밋 게이트 | ✅ | ✅ | ✅ | ❌ |

**선택**: Playwright (TypeScript). Vitest 와 동일 언어 → 학습 비용 0. Claude MCP 는 **디버깅 도구**로 보존.

## §2. 도구 설치 + 기본 사용법

```powershell
# 설치 (devDependency)
npm install -D @playwright/test
npx playwright install chromium
```

**`playwright.config.ts`** (코어 루트 — 모듈/플러그인/템플릿은 각자 위치):

```typescript
import { defineConfig, devices } from '@playwright/test';

function resolveBaseUrl(): string {
  if (process.env.PLAYWRIGHT_BASE_URL) return process.env.PLAYWRIGHT_BASE_URL;
  // .env 의 APP_URL (단 localhost 류 제외)
  // 그 외 — Error
}

export default defineConfig({
  testDir: './tests/Playwright/specs',
  fullyParallel: true,
  use: { baseURL: resolveBaseUrl(), trace: 'retain-on-failure', ignoreHTTPSErrors: true },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
```

**최소 spec 구조**:

```typescript
import { test, expect } from '@playwright/test';

test('@smoke 홈페이지 마운트', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByTestId('nav-home')).toBeVisible({ timeout: 15_000 });
});
```

## §3. 코어/확장 분리 원칙 (CRITICAL)

```text
모듈/플러그인/템플릿 E2E spec 을 코어 디렉토리(tests/Playwright/) 에 작성 금지
✅ 모듈 E2E:    modules/_bundled/{id}/tests/Playwright/specs/
✅ 플러그인 E2E: plugins/_bundled/{id}/tests/Playwright/specs/
✅ 템플릿 E2E:  templates/_bundled/{id}/tests/Playwright/specs/
✅ 코어 E2E:    tests/Playwright/specs/ (코어 엔진/관리자 API 검증만)
```

기존 PHPUnit testsuite (`Unit`/`Feature`/`Module`/`Plugin`) 및 Vitest 의 코어/확장 분리 원칙과 동일.

확장이 자체 config 를 가지므로 **확장 디렉토리에서 직접 실행**:

```powershell
cd modules/_bundled/sirsoft-ecommerce
$env:PLAYWRIGHT_BASE_URL='https://g7.dev'
npx playwright test
```

## §4. 데이터 생성 위치 — 책임 분리 매트릭스

| 데이터 종류 | 책임 영역 | 위치 | 호출 |
|---|---|---|---|
| 코어 권한/역할/유저/Sanctum 토큰 | 코어 | `app/Console/Commands/PlaywrightIssueToken.php` | `php artisan playwright:issue-token --permissions=core.xxx` |
| 모듈 권한 (`sirsoft-ecommerce.*`) | 모듈 | 코어 커맨드의 `--permissions=` 임의 식별자 | 동일 (Permission::firstOrCreate 자동 생성) |
| 모듈 도메인 데이터 (상품/주문) | 모듈 | `modules/_bundled/{id}/src/Console/Commands/PlaywrightSeed{id}.php` | `php artisan playwright:seed-{id}` |
| 플러그인 도메인 데이터 (결제 키) | 플러그인 | `plugins/_bundled/{id}/src/Console/Commands/PlaywrightSeed{id}.php` | 동일 |
| 외부 의존 (토스 결제창 응답) | spec 안 mock | `page.route(...)` | 호출 없음 |

**핵심 원칙**: 코어는 모듈 도메인을 모른다. 모듈 도메인 시드를 코어에 두면 의존 역전.

## §5. fixture 패턴

### 5.1 코어 fixture (`tests/Playwright/fixtures/auth.ts`)

```typescript
export function issueToken(...permissions: string[]): string {
  return execSync(`php artisan playwright:issue-token ${permissions.map(p => `--permissions=${p}`).join(' ')}`, {
    cwd: process.env.G7_ROOT || process.cwd(),
    env: { ...process.env, G7_PLAYWRIGHT_BYPASS: '1' },  // ② 옵트인 자동 부착
  }).toString().trim();
}

export async function authenticatePage(page: Page, token: string): Promise<void> {
  await page.addInitScript((t) => localStorage.setItem('auth_token', t), token);
}

export const test = base.extend<AuthFixtures>({
  editToken: async ({}, use) => use(issueToken('core.templates.layouts.edit')),
  readOnlyToken: async ({}, use) => use(issueToken('core.templates.read')),
});
```

### 5.2 확장 fixture — 권한 + 시드 분리

```typescript
// modules/_bundled/sirsoft-ecommerce/tests/Playwright/fixtures/ecommerce-auth.ts
import { issueToken, authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';

export const test = base.extend<EcommerceAuthFixtures>({
  settingsToken: async ({}, use) =>
    use(issueToken('sirsoft-ecommerce.settings.read', 'sirsoft-ecommerce.settings.update')),
});
```

```typescript
// 권한 + 시드 조합 — mergeTests 사용
import { mergeTests } from '@playwright/test';
import { test as authTest } from '../../fixtures/ecommerce-auth';
import { test as seedTest } from '../../fixtures/ecommerce-seed';

const test = mergeTests(authTest, seedTest);

test('이커머스 상품 목록', async ({ page, settingsToken, seededEcommerce }) => {
  await authenticatePage(page, settingsToken);
  await page.goto('/admin/ecommerce/products');
  await expect(page.getByTestId('product-list-row')).toHaveCount(seededEcommerce.productIds.length);
});
```

## §6. 도메인 매트릭스 (4 카테고리)

### 6.1 인증 가드 매트릭스 (access_outcome × user_permission)

`access-check` 응답을 page.route() 로 mock → 토큰 실제 권한과 무관하게 모든 분기 cover.

```typescript
await page.route('**/api/admin/templates/layouts/access-check', route =>
  route.fulfill({ status: 401, body: JSON.stringify({ message: 'Unauthenticated.' }) })
);
await page.goto('/?mode=edit&template=sirsoft-basic');
await expect(page.getByTestId('wysiwyg-access-denied-unauthenticated')).toBeVisible();
```

### 6.2 UI 인터랙션 매트릭스 (anchor × modifier_key)

PreviewCanvas 의 `data-testid="preview-canvas-container"` 안에 동적 anchor 주입 + 클릭 시뮬레이션:

```typescript
await page.evaluate(({ href, modifier }) => {
  const host = document.querySelector('[data-testid="preview-canvas-container"]');
  const a = document.createElement('a');
  a.setAttribute('href', href);
  host.appendChild(a);
  a.dispatchEvent(new MouseEvent('click', {
    bubbles: true, cancelable: true,
    ctrlKey: modifier === 'ctrl',
    shiftKey: modifier === 'shift',
    button: modifier === 'middle_button' ? 1 : 0,
  }));
}, { href, modifier });
```

검증 신호: `evt.defaultPrevented === true` (intercept) / `false` (allow).

### 6.3 핸들러 동작 매트릭스 (suppressed_handler)

ActionDispatcher 의 `setPreviewMode(true)` + `setPreviewSuppressedHandlerCallback` 으로 분기 진입 검증:

```typescript
await page.evaluate(({ handler }) => {
  const dispatcher = (window as any).__templateApp.getActionDispatcher();
  let captured: any = null;
  dispatcher.setPreviewMode(true);
  dispatcher.setPreviewSuppressedHandlerCallback((name) => { captured = name; });
  return dispatcher.dispatchAction({ handler }, {}).then(() => captured);
}, { handler: 'navigate' });
```

### 6.4 외부 의존 시나리오 — mock-first

토스페이먼츠 SDK / API mock 패턴은 `tests/Playwright/README.md` "외부 의존 시나리오" 참조.

## §7. 시나리오 매니페스트와 1:1 매핑

기존 PHPUnit/Vitest 와 동일하게 `tests/scenarios/<feature>.yaml` 의 `cross_product` axis 가 spec 의 `test.describe.parallel(axisName)` 으로 변환되어야 한다.

| YAML axis | spec 파일 | 케이스 |
|---|---|---|
| `cross_product[0]` (access_outcome × user_permission) | `auth-guard.spec.ts` | 12 |
| `cross_product[1]` (anchor_kind × modifier_key) | `anchor-intercept.spec.ts` | 45 |
| `cross_product[2]` (suppressed_handler) | `handler-suppression.spec.ts` | 6 |
| `cross_product[3]` (url_template_param × anchor_kind) | `url-template-param.spec.ts` | 27 |

각 test 케이스의 docblock 에 마킹:

```typescript
test('unauthenticated_401 × no_token', async ({ page }) => {
  // @scenario access_outcome=unauthenticated_401, user_permission=no_token
  // @effects access_denied_screen_renders, editor_does_not_mount_on_denial
  // ...
});
```

`audit-branch.cjs` 의 `test-scenario-coverage` 룰이 매니페스트 axes ↔ docblock 매칭을 자동 검증.

## §8. 회귀 테스트 4단계 (CLAUDE.md 의무 절차 준수)

버그 수정 시 다음 4단계를 스킵 불가:

1. **실패하는 회귀 spec 작성** — 버그 재현 케이스 spec 작성
2. **baseline fail 확인** — 수정 전 실제 fail 확인 (테스트가 의도된 분기를 cover 함을 입증)
3. **코드 수정**
4. **green 전환** — 회귀 spec PASS 확인

`testing-guide.md` 의 PHPUnit/Vitest 4단계와 동일 원칙.

## §9. 무관 에러 처리 분기

E2E spec 작성 중 발견한 무관 에러는 같은 세션에서 처리:

- **테스트 stale** (logic 정상, 테스트가 오래됨) → 테스트 수정
- **로직 회귀** (테스트 정상, 로직이 의도와 어긋남) → 코드 수정
- **데이터 구조 불일치** (양쪽 모두 오래됨) → PO 보고 + 보류

상세: `testing-guide.md` "무관 에러 처리 분기".

## §10. 트러블슈팅

| 증상 | 원인 | 해결 |
|---|---|---|
| `Tests timed out — networkidle` | Reverb WebSocket 지속 연결 | `waitForLoadState('domcontentloaded')` + `waitForFunction` 사용 |
| 401 무한 redirect (`/login?redirect=...`) | 토큰이 testing DB(`g7_testing`) 에만 있고 production 서버(`g7`)는 못 찾음 | `G7_PLAYWRIGHT_BYPASS=1` 로 호출 (production DB 에 토큰 발급) |
| `preview-canvas-container 없음` | 위지윅 편집기 마운트 실패 (access-check 거부) | `page.route()` 로 access-check 200 mock |
| `defaultPrevented` 가 항상 false | onClickCapture 가 도달 안 함 | anchor 가 `preview-canvas-container` 자손인지 확인 |
| `location.href` setter override 실패 | Chromium 에서 `window.location` 은 non-configurable | best-effort 캡처. SSoT 신호는 `defaultPrevented` |
| 외부 origin navigation 으로 trace 복잡 | spec 이 외부 도메인을 로드 | `page.route('https://example.com/**', route => route.fulfill(...))` |
| Sanctum 토큰 누적 DB 오염 | 매 spec 마다 새 user/role 생성 | `globalTeardown.ts` 에서 `php artisan playwright:cleanup-tokens` (별도 커맨드 신설) |
| Windows 라인엔딩 | git autocrlf 충돌 | `.gitattributes` 에 `*.spec.ts text eol=lf` |

## §11. 디버깅 — Claude MCP 역할

Playwright = **결정론적 회귀 게이트** / Claude `chrome-devtools-mcp` = **라이브 진단**.

워크플로우:

1. Playwright spec 이 fail
2. `npx playwright show-trace test-results/<...>/trace.zip` 으로 timeline 확인
3. 단계별 DOM 스냅샷 + 네트워크 분석으로도 원인 불명 시 → Claude MCP `chrome-devtools-mcp` 로 동일 URL 라이브 진단
4. 진단 결과로 spec 보강 또는 코드 수정 → 다시 Playwright 로 결정론 검증

Claude MCP 는 단독 게이트가 아닌 **진단 보조 도구**. 모든 회귀는 Playwright spec 으로 승격되어야 CI/커밋 게이트에서 효력 발생.

## 참조

- 빠른 시작: `tests/Playwright/README.md`
- 모듈 sample skeleton: `modules/_bundled/sirsoft-ecommerce/tests/Playwright/README.md`
- 시나리오 매니페스트: `tests/scenarios/wysiwyg-editor-access-guard.yaml`
- PHPUnit/Vitest 가이드: `docs/testing-guide.md`, `docs/frontend/layout-testing.md`
- 가드 구현: `app/Console/Commands/PlaywrightIssueToken.php`, `app/Providers/SettingsServiceProvider.php::applyDebugConfig`
- 회귀 테스트: `tests/Unit/Providers/SettingsServiceProviderDebugConfigTest.php`
