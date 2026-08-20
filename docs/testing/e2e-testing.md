# 그누보드7 Playwright E2E 테스트 가이드

## TL;DR (5초 요약)

```text
- 도구: Playwright 1.49+ (TypeScript, Vitest 와 동일 스택)
- 인증: PlaywrightIssueToken artisan 커맨드가 Sanctum 토큰 발급 (CLI + G7_PLAYWRIGHT_BYPASS=1 + APP_DEBUG 3중 가드)
- Base URL: PLAYWRIGHT_BASE_URL 환경변수 우선, .env APP_URL 차순위 (하드코딩 회피)
- 로케일: 모든 config 에 use.locale = 'ko-KR' 고정 (미지정 시 Accept-Language 가 en-US 로 나가 화면이 영어로 렌더 → 한국어 단언 전멸)
- 코어/확장 분리: 코어 = tests/Playwright/, 확장 = {확장 디렉토리}/tests/Playwright/
- 데이터 생성: 데이터를 소유한 영역에 시드 커맨드 배치 (코어 ↔ 모듈 의존 역전 회피)
- 편집기 저장 spec: 제품 화면 대신 전용 시드 화면(e2e_sandbox) 대상 — globalSetup 이 매 실행 원본으로 덮어씀 (§4.1)
- 외부 의존: mock-first 전략 (page.route() 로 결제창/외부 API 가로채기)
- 브라우저: 일상 E2E 는 Chromium 전용 — 확대 조건은 §3 브라우저 범위
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

확장은 `tests/Playwright/playwright.config.ts` 에 자체 config 를 가지므로 **확장 디렉토리에서 직접 실행**한다.
config 가 확장 루트가 아니라 `tests/Playwright/` 아래에 있으므로 `npx playwright test` 를 인자 없이 부르면
config 를 찾지 못한다 — `npm run test:e2e` (config 경로가 이미 묶여 있음) 를 쓴다.

```powershell
cd modules/_bundled/sirsoft-ecommerce
npm run test:e2e                                    # 확장 전체
npm run test:e2e -- specs/admin/some.spec.ts        # 단일 spec
npm run test:e2e:ui                                 # UI 디버깅 모드
```

base URL 은 코어 `.env` 의 `APP_URL` 에서 자동 해석된다 (`PLAYWRIGHT_BASE_URL` 로 덮어쓸 수 있다).

### 로케일 고정 (`locale: 'ko-KR'`)

코어와 확장의 모든 config 은 `use.locale` 을 `'ko-KR'` 로 고정한다. 생략하면 한국어 문구를 단언하는
spec 이 전부 실패한다.

엔진의 로케일 우선순위는 `localStorage.g7_locale` → 서버가 내려준 값 → `'ko'` 이고
([TemplateApp.ts](../../resources/js/core/TemplateApp.ts) 생성자), 서버값은 `SetLocale` 미들웨어가
**미로그인 요청에서 `Accept-Language` 헤더로** 결정한다. Playwright 의 `locale` 옵션이 그 헤더를
만들므로, 지정하지 않으면 첫 페이지 로드가 `en-US` 로 나가 화면이 영어로 렌더되고 엔진이 그 값을
`localStorage` 에 저장한다. 이후 인증해도 저장된 값이 최우선이라 세션 전체가 영어로 고정된다.

`getByText('전체회원수')` 처럼 한국어만 단언하는 곳은 물론이고, `getByRole('button', { name: /저장/ })`
같은 접근 가능한 이름 조회도 함께 깨진다.

### 실행 시 유의 (경험칙)

| 항목 | 내용 |
| --- | --- |
| 산출물 위치 | 리포트·trace 는 **코어 루트**의 `test-results/{type}/{id}/`, `playwright-report/{type}/{id}/` 에 쌓인다. 확장 디렉토리 안에 쌓으면 Windows 에서 `{module\|template}:update` 의 디렉토리 이동이 열린 핸들에 걸려 실패한다 |
| 레이아웃 수정 후 | 레이아웃 JSON 을 고쳤으면 `{type}:update {id} --force` **+ `template:cache-clear {템플릿}`** 까지 해야 브라우저에 반영된다. 레이아웃은 템플릿 캐시(`/api/layouts/{template}/...`)로 서빙되므로 `cache:clear` 만으로는 갱신되지 않는다 |
| 공유 상태 | 같은 관리자 설정 화면을 건드리는 spec 이 병렬로 돌면 서로의 저장 상태를 덮어써 실패할 수 있다. 실행 옵션에 맡기지 않고 그 `describe` 에 `test.describe.configure({ mode: 'serial' })` 를 둔다 |
| 워커 수 | 관리자 SPA 는 번들이 크고 레이아웃을 여러 번 받아온다. 개발 머신에서 2워커 이상이면 `page.waitForLoadState` 가 30초를 넘겨 **비결정적으로** 실패한다(실측: 같은 스위트가 회차마다 다른 5~7건 실패, 테스트당 8초 → 25초). 판정은 `--workers=1` 결과로 한다 |
| 편집기 spec 만 몰아 실행할 때 | 레이아웃 편집기 spec 만 골라 돌리면 워커 전부가 동시에 편집기 페이지를 연다 — 전체 스위트에서는 가벼운 spec 이 섞여 그 집중이 생기지 않는다. 실측: 편집기 7파일 27건을 7워커로 돌리면 `g7le-preview-frame` 대기가 전부 30초 타임아웃(27/27 실패), 같은 코드로 2워커는 26/27 통과. 편집기만 선택 실행할 때는 `--workers=2` 이하로 둔다 |

### 브라우저 범위

일상 E2E 는 Chromium 전용이 정책이다. 코어 config 와 확장 config 모두 `projects` 에
chromium 하나만 두며, `npx playwright install chromium` 만 안내하는 것(§2)이 곧 이 정책이다.
문서상의 지원 브라우저 선언(`docs/requirements.md` §7)은 이 자동 검증 범위와 구분해서 읽는다 —
Firefox/Safari 는 프론트엔드 의존성(React 19, Tailwind CSS 4)의 호환 범위 기준 지원이며
상시 자동 테스트 대상이 아니다.

이 정책의 근거는 위험 표면의 위치다. 엔진과 레이아웃에는 브라우저별 분기 코드가 없어 JS
레벨의 브라우저 편차 위험이 낮고, 실질 위험은 CSS 렌더링과 contenteditable(위지윅) 거동에
몰려 있다. 그 두 축은 브라우저 프로젝트를 늘리는 것보다 해당 spec 을 두껍게 쓰는 편이
비용 대비 회수가 크다.

Firefox/WebKit 프로젝트를 상시 스위트에 넣지 않은 이유:

- 프로젝트 간 병렬 실행이 공유 서버 상태를 경합시킨다 — 시드 화면 PUT 저장(§4.1)과 관리자
  설정 저장 spec 은 같은 레코드를 건드리므로 브라우저별 실행이 서로의 저장을 덮어쓴다.
- 시나리오 매니페스트에 브라우저 축을 추가하면 cross product 가 브라우저 수만큼 배가된다.
- 총 실행 시간이 프로젝트 수에 비례해 늘어, 개발 머신의 워커 예산(위 "실행 시 유의")과
  정면으로 충돌한다.

향후 도입할 경우 아래 4가지를 조건으로 한다:

- 상시 스위트가 아니라 **별도 opt-in 스위트**로만 실행한다 (기본 실행 대상에 넣지 않는다).
- 대상은 `@smoke` 로 한정한다 — 레이아웃 편집기 저장 spec 을 배제해 시드 화면 경합을 피한다.
- `--workers=1` 로 고정한다.
- CDP 를 쓰는 spec(`specs/smoke/localinit-multi-progressive-datasource.spec.ts` 의
  `newCDPSession`)은 `test.skip(browserName !== 'chromium')` 로 제외한다.

## §4. 데이터 생성 위치 — 책임 분리 매트릭스

| 데이터 종류 | 책임 영역 | 위치 | 호출 |
|---|---|---|---|
| 코어 권한/역할/유저/Sanctum 토큰 | 코어 | `app/Console/Commands/PlaywrightIssueToken.php` | `php artisan playwright:issue-token --permissions=core.xxx` (권한 경계 검증은 `--no-admin-role` 추가 — §5.1) |
| 편집기 저장 spec 대상 시드 화면 | 코어 | `app/Console/Commands/PlaywrightSeedLayout.php` | `php artisan playwright:seed-layout [--remove]` (globalSetup/globalTeardown 자동 호출) |
| 모듈 권한 (`sirsoft-ecommerce.*`) | 모듈 | 코어 커맨드의 `--permissions=` 임의 식별자 | 동일 (Permission::firstOrCreate 자동 생성) |
| 모듈 도메인 데이터 (상품/주문) | 모듈 | `modules/_bundled/{id}/src/Console/Commands/PlaywrightSeed{id}.php` | `php artisan playwright:seed-{id}` |
| 플러그인 도메인 데이터 (결제 키) | 플러그인 | `plugins/_bundled/{id}/src/Console/Commands/PlaywrightSeed{id}.php` | 동일 |
| 외부 의존 (토스 결제창 응답) | spec 안 mock | `page.route(...)` | 호출 없음 |

**핵심 원칙**: 코어는 모듈 도메인을 모른다. 모듈 도메인 시드를 코어에 두면 의존 역전.

### 4.1 저장(PUT)하는 spec 은 제품 화면을 대상으로 두지 않는다

레이아웃 편집기 spec 이 저장까지 수행하면 그 편집 결과는 **그대로 영속된다**. 대상이 제품 화면
(`home` / `admin_dashboard` 등)이면 실행할 때마다 노드가 누적돼 개발 사이트에 그대로 노출된다.

실측(2026-07-30): `home` 에 빈 표 7개가 쌓여 20,321 → 33,696 bytes, 관리자 대시보드에 빈
DonutChart 5개. 누적되면 캔버스 구조가 회차마다 달라져 같은 파일의 다른 테스트도 간헐 실패한다.

spec 안에 "추가한 노드를 삭제하고 다시 저장" 원복을 넣는 것으로는 해결되지 않는다 — 원복 실행
후에도 레이아웃이 오염 시점과 정확히 같은 크기로 되돌아왔다(편집기가 그 시점에 들고 있던 문서를
통째로 다시 저장). 원복은 그 자체가 또 한 번의 저장이라, 실패하면 잔여물이 남는다.

그래서 **저장 대상 자체를 전용 시드 화면으로 분리**한다.

| 항목 | 값 |
|---|---|
| 레이아웃 이름 | `e2e_sandbox` |
| 라우트 | 사용자 템플릿 `/e2e-sandbox`, 관리자 템플릿 `*/admin/e2e-sandbox` |
| fixture 원본 | `tests/Playwright/fixtures/seed-layouts/{템플릿}.e2e_sandbox.json` |
| 설치 위치 | **활성 템플릿 디렉토리만** (`_bundled` 배포 원본 무변경, 활성 디렉토리는 Git 무시 → 릴리스 미포함) |
| 설치/제거 | `php artisan playwright:seed-layout [--remove]` (CLI + `G7_PLAYWRIGHT_BYPASS=1` 가드) |
| 자동 호출 | `globalSetup` 설치 / `globalTeardown` 제거 |
| spec 헬퍼 | `tests/Playwright/fixtures/seed-layout.ts` — `sandboxRouteParam()`, `SANDBOX_ROOT_ID` |

설치는 3종을 함께 처리한다: 레이아웃 파일 + `routes.json` 라우트(마커 기반 멱등) + DB 행 upsert.
편집기의 라우트 트리는 `routes.json` 에서, 조회/저장은 DB 행을 대상으로 하므로 셋 중 하나만
빠지면 `?route=` 로 열 수 없거나 404 가 된다.

`routes.json` 은 재직렬화하면 원본의 주석 그룹 사이 빈 줄 같은 서식이 사라지므로, 설치 시 원본을
`routes.json.playwright-backup` 으로 보관하고 제거 때 그 파일을 그대로 되돌린다(바이트 동일 복원).
백업이 이미 있으면 덮어쓰지 않는다 — 비정상 종료로 시드가 남은 상태의 파일을 "원본" 으로 굳히지
않기 위함이다.

시드 설치는 **시드 행 하나만** 건드린다. `template:refresh-layout`(전체 재동기화)을 쓰지 않는
이유는 그 경로가 파일에 없는 DB 레이아웃을 지우고 모든 레이아웃을 파일 기준으로 되돌리기
때문이다 — 편집기 UI 로 저장한 변경은 파일이 아니라 DB 에만 있으므로 E2E 를 돌릴 때마다 사람이
편집기로 만든 결과가 사라진다.

시드 화면은 매 실행 fixture 원본으로 덮어써지므로 회차 간 누적이 성립하지 않는다. 따라서 저장
spec 에 원복 절차를 둘 필요가 없다.

```typescript
import { SANDBOX_ROOT_ID, sandboxRouteParam } from '../../fixtures/seed-layout';
import { editorPath } from '../../fixtures/layout-editor';

test('편집 후 저장 → PUT 200', async ({ page }) => {
  await gotoEditor(page, sandboxRouteParam());          // 사용자 템플릿
  const container = await editorPath(page, '', SANDBOX_ROOT_ID);  // 고정 id 컨테이너
  // ... 편집 + 저장
});
```

**저장하지 않는(읽기 전용) spec 은 계속 제품 화면을 대상으로 둔다** — 오염 위험이 없고, 실제
제품 레이아웃에 대한 검증이 유지되는 편이 낫다.

예외: **팔레트로 노드를 추가한 뒤 그 노드를 선택해야 하는** spec 은 저장하지 않아도 시드 화면을
쓴다. 제품 화면에서는 삽입 위치가 "선택 가능한 Div 후보 순회" 로 정해지는데, 그 위치가 모듈이 주입한
잠금 서브트리 안이면 선택이 조상 노드로 escalate 되어 추가한 노드를 지목할 수 없다(실측: 관리자
대시보드에 BarChart 추가 시 오버레이 타입 라벨이 `↑Div` + ⓘ 미표시, 같은 절차를 시드 컨테이너에서
하면 `↑BarChart` + ⓘ 표시). 시드 화면의 컨테이너는 고정 id 라 삽입 위치가 결정적이다.

### 4.2 편집기 spec 작성 시 자주 틀리는 측정 기준

전수 실행에서 드러난 실패의 상당수가 제품 결함이 아니라 **측정 방법**의 문제였다. 아래는 실측으로
확인된 것들이다.

| 하지 말 것 | 이유 (실측) | 대신 |
|---|---|---|
| 전환 오버레이 가림을 `toBeVisible()` 로 판정 | Playwright 의 가시성 판정은 **가림(occlusion)을 보지 않는다**. 오버레이는 타겟 안/head 에 덧붙는 방식이고 콘텐츠는 DOM 에 남으므로, 덮여 있어도 visible 로 판정된다 | 오버레이 엘리먼트의 attach/detach 시각으로 측정 (`#g7-skeleton-overlay` 또는 `style#g7-transition-overlay`) |
| "캔버스 텍스트 길이가 늘어난다" 로 본체 렌더 판정 | 탭/상태를 바꾸면 **줄어들 수도** 있다. 실측: my_comments 서브탭이 정상 렌더되는데 881 → 669 로 감소(항목당 길이가 짧아서) | 그 데이터에만 있는 고유 문구 존재로 판정 |
| 상태/옵션의 **총 개수**를 단언 | 상태 그룹은 편집기 스펙에서 계속 늘어난다. 체크아웃 상태가 3 → 4 로 늘면서 `toHaveCount(3)` 이 깨졌다 | 그 테스트가 실제로 쓰는 값의 존재로 판정 |
| `waitForLoadState('networkidle')` | 관리자 SPA 는 실시간 연결·주기 폴링이 붙어 500ms 무통신 구간이 오지 않을 수 있다. 실측 30초 타임아웃 | 다음 단계에 필요한 구체 신호(클릭할 버튼의 가시성 등) |
| `/admin/layout-editor/{id}` 에 모듈/플러그인 식별자 | 그 세그먼트는 **템플릿 식별자** 자리다. 모듈 라우트는 해당 타입 템플릿 트리에 병합되므로 템플릿으로 진입해야 한다 | 템플릿 식별자 (모듈 admin 라우트 → admin 템플릿) |
| 테스트 예산과 내부 대기를 같은 값으로 | `test` 기본 예산 30초 안에서 30초 `waitForResponse` 를 걸면 앞 단계가 조금만 늦어도 구조적으로 완주 불가 | 내부 대기를 줄이거나 `test.setTimeout()` 상향 |

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

#### 권한 경계를 검증할 때는 `issueScopedToken`

`issueToken` 은 커맨드 기본 동작대로 사이트의 `admin` 역할을 함께 부여한다. `admin` 역할은 전체
권한을 보유하므로, `--permissions` 로 권한을 좁혀 넘겨도 **화면은 항상 최대 권한으로 렌더된다**.
읽기 전용 분기·권한 미보유 분기처럼 권한 경계 자체를 검증하려면 `issueScopedToken` 을 쓴다 —
`--no-admin-role` 을 붙여 지정한 권한만 가진 계정을 만든다.

```typescript
// ❌ 읽기 전용 분기를 만들 수 없다 — admin 역할이 update 권한까지 함께 부여된다
await authenticatePage(page, issueToken('sirsoft-ecommerce.settings.read'));
await expect(page.locator('input[name="..."]')).toBeDisabled();   // 실패: enabled

// ✅ 지정한 권한만 가진 계정
await authenticatePage(page, issueScopedToken('sirsoft-ecommerce.settings.read'));
await expect(page.locator('input[name="..."]')).toBeDisabled();   // 통과
```

권한을 좁힌 계정은 코어 메뉴·알림 데이터소스에도 접근하지 못해 콘솔에 403 이 남을 수 있다.
그 화면 자체의 검증과 무관한 잡음이므로, 콘솔 에러 0 을 단언하는 테스트에는 이 토큰을 쓰지 않는다.
권한 밖 탭·라우트로 이동하면 403 에러 페이지로 전환되므로, 왕복 시나리오는 그 권한으로 접근
가능한 대상만 경유해야 한다.

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

정적 검사가 매니페스트 axes ↔ docblock 매칭을 자동 검증.

## §8. 회귀 테스트 4단계

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
- **데이터 구조 불일치** (양쪽 모두 오래됨) → 보고 + 보류

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
