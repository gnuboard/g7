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
`localStorage` 에 저장한다. 비로그인 화면은 저장된 값이 최우선이라 세션 전체가 영어로 고정된다. 로그인 상태에서는 부팅 때 회원 언어(DB `users.language`)로 맞추므로(engine-v1.79.0+), 로케일별 실측은 테스트 계정의 회원 언어를 바꿔서 정한다.

`getByText('전체회원수')` 처럼 한국어만 단언하는 곳은 물론이고, `getByRole('button', { name: /저장/ })`
같은 접근 가능한 이름 조회도 함께 깨진다.

### 브라우저 UA 고정 (`userAgent`)

코어와 확장의 모든 config 은 `use.userAgent` 에 실제 데스크탑 Chrome UA 를 지정한다.

Playwright 의 기본 UA 에는 `HeadlessChrome` 이 들어 있고, `SeoMiddleware` 의 봇 판정이 그것을
검색엔진 크롤러로 본다. 그러면 공개 사용자 경로 요청이 SPA 가 아니라 **검색엔진용 정적 HTML** 을
받는다 — `window.G7Core` 도 엔진 스크립트도 없는 화면이다.

이 상태가 위험한 이유는 실패가 아니라 **통과**로 나타나기 때문이다. 서버가 심은 글꼴·아이콘은
그대로 정상이라 "페이지가 잘 뜬다" 로 보이고, 정작 재려던 SPA 동작(테마 적용·핸들러 등록·확장
번들 로드·상태 바인딩)은 한 번도 실행되지 않은 채 단언이 통과한다. 실측(2026-08-26)에서
사용자 홈을 재던 spec 들이 전부 이 경로였다.

봇 화면을 의도적으로 검증하는 spec 은 요청마다 검색엔진 UA(`Googlebot` 등)를 헤더로 지정해 그 경로를
유발하므로, 설정의 UA 를 실제 브라우저 값으로 고정해도 그 검증은 그대로 동작한다. `?_escaped_fragment_=`
쿼리는 더 이상 봇 신호가 아니다 — 그 쿼리만 붙이면 SPA 를 받는다.

측정으로 확인하려면 `typeof window.G7Core` 가 `'object'` 인지 본다 — `'undefined'` 면 SPA 가 아니라
봇 화면을 재고 있는 것이다.

### 실행 시 유의 (경험칙)

| 항목 | 내용 |
| --- | --- |
| 산출물 위치 | 리포트·trace 는 **코어 루트**의 `test-results/{type}/{id}/`, `playwright-report/{type}/{id}/` 에 쌓인다. 확장 디렉토리 안에 쌓으면 Windows 에서 `{module\|template}:update` 의 디렉토리 이동이 열린 핸들에 걸려 실패한다 |
| 레이아웃 수정 후 | 레이아웃 JSON 을 고쳤으면 `{type}:update {id} --force` **+ `template:cache-clear {템플릿}`** 까지 해야 브라우저에 반영된다. 레이아웃은 템플릿 캐시(`/api/layouts/{template}/...`)로 서빙되므로 `cache:clear` 만으로는 갱신되지 않는다 |
| 공유 상태 | 같은 관리자 설정 화면을 건드리는 spec 이 병렬로 돌면 서로의 저장 상태를 덮어써 실패할 수 있다. 실행 옵션에 맡기지 않고 그 `describe` 에 `test.describe.configure({ mode: 'serial' })` 를 둔다 |
| 워커 수 | 관리자 SPA 는 번들이 크고 레이아웃을 여러 번 받아온다. 개발 머신에서 2워커 이상이면 `page.waitForLoadState` 가 30초를 넘겨 **비결정적으로** 실패한다(실측: 같은 스위트가 회차마다 다른 5~7건 실패, 테스트당 8초 → 25초). 판정은 `--workers=1` 결과로 한다 |
| 편집기 spec 만 몰아 실행할 때 | 레이아웃 편집기 spec 만 골라 돌리면 워커 전부가 동시에 편집기 페이지를 연다 — 전체 스위트에서는 가벼운 spec 이 섞여 그 집중이 생기지 않는다. 실측: 편집기 7파일 27건을 7워커로 돌리면 `g7le-preview-frame` 대기가 전부 30초 타임아웃(27/27 실패), 같은 코드로 2워커는 26/27 통과. 편집기만 선택 실행할 때는 `--workers=2` 이하로 둔다 |
| 봇 렌더 spec 의 IP 예산 | 검색봇 화면의 미스 렌더는 **IP 당 분당 상한**(`G7_SEO_RENDER_MISSES_PER_MINUTE`, 기본 60)을 쓴다. 자동 검사는 전부 같은 IP 에서 나가므로 봇 경로 spec 을 많이 몰아 돌리면 예산을 넘긴 요청이 SEO HTML 대신 SPA(`X-SEO-Cache: BYPASS`)를 받아 본문 단언이 실패한다. 오류가 아니라 200 이라 원인이 드러나지 않으므로, 봇 spec 이 간헐 실패하면 응답 헤더가 `BYPASS` 인지 먼저 본다 (실측: `seo-bot-rendering` 12건 + `page-og-image` 2건은 기본값에서 여유가 있다) |

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
| 로그인 2단계 인증 계정·인증번호 | 코어 | `app/Console/Commands/PlaywrightSeedTwoFactor.php` | `php artisan playwright:seed-two-factor --ensure-user=… \| --plant=<challenge_id>` (§4.2) |
| 인증 앱(TOTP) 자격증명·현재 코드 | 플러그인 | `plugins/_bundled/sirsoft-totp/src/Console/Commands/PlaywrightSeedTotp.php` | `php artisan playwright:seed-totp --enroll=<email> \| --code=<secret>` (§4.2) |
| 모듈 권한 (`sirsoft-ecommerce.*`) | 모듈 | 그 모듈이 정의한 권한(설치 때 생성)·게시판별 권한(게시판 생성 때 생성) | 코어 커맨드의 `--permissions=` — **DB 에 있는 식별자만** 받는다(없으면 발급 거절 — 아래 「없는 권한 식별자」) |
| 모듈 도메인 데이터 (상품/주문) | 모듈 | `modules/_bundled/{id}/src/Console/Commands/PlaywrightSeed{id}.php` | `php artisan playwright:seed-{id}` |
| 플러그인 도메인 데이터 (결제 키) | 플러그인 | `plugins/_bundled/{id}/src/Console/Commands/PlaywrightSeed{id}.php` | 동일 |
| 외부 의존 (토스 결제창 응답) | spec 안 mock | `page.route(...)` | 호출 없음 |

**핵심 원칙**: 코어는 모듈 도메인을 모른다. 모듈 도메인 시드를 코어에 두면 의존 역전.

### 없는 권한 식별자는 발급 때 거절된다

`playwright:issue-token` 은 `--permissions` 의 식별자가 하나라도 DB 에 없으면 **아무것도 만들지 않고 실패 코드(1)로
끝나며**, 없는 식별자를 오류 줄에 나열한다. 계정·테스트 역할·토큰·권한 행 모두 생기지 않는다. 예전에는 없는 식별자를
받으면 이름·설명이 식별자 그대로인 권한 행을 만들었는데, 그 행은 실제 라우트가 검사하는 권한이 아니어서 spec 은
「권한을 받았다」고 믿지만 실제로는 함께 붙은 `admin` 역할 덕에 통과하는 거짓 녹색이 됐고, 관리자 권한 트리에도 식별자가
그대로 보였다.

- 식별자는 그 권한을 정의한 코어 설정·확장(`getPermissions()`)에 있는 이름을 쓴다(예: `core.settings.read`,
  `sirsoft-ecommerce.products.read`). 확장 권한은 그 확장이 설치돼 있어야 발급된다.
- 게시판별 권한(`sirsoft-board.{slug}.admin.posts.read` 등)은 **그 게시판이 만들어질 때** 생긴다. spec 안에서 게시판을
  만든다면 발급 시점에는 아직 없으므로, 그 식별자를 넘기지 말고 함께 붙는 `admin` 역할(게시판 생성 때 그 게시판의 관리자
  권한을 받는다)에 맡기거나, 게시판을 만든 뒤 토큰을 따로 받는다.
- 잘못된 범위 접미사(`:self`·`:role` 외)는 종전처럼 예외로 거절된다.

### 발급분 회수 (`playwright:cleanup`)

`playwright:issue-token` 은 호출마다 `playwright_test_{uniqid}` 역할과 신규 유저를 새로 만든다 —
병렬 실행이 서로의 권한 집합을 밟지 않게 하려는 의도된 격리다. 회수 수단이 없으면 이 발급분이
영구 누적되어 `/api/admin/roles/active` 응답이 부풀고, 그 목록을 당기는 관리자 화면(게시판 폼 등)
진입이 함께 느려진다.

```powershell
$env:G7_PLAYWRIGHT_BYPASS='1'; php artisan playwright:cleanup --dry-run      # 대상만 센다
$env:G7_PLAYWRIGHT_BYPASS='1'; php artisan playwright:cleanup --keep-hours=2 # 최근 2시간분은 남긴다
```

회수 대상은 `playwright_test_` 로 시작하는 역할과, 그 역할 외에 `admin` 밖에 갖지 않은 계정
(+ 그 계정의 토큰)뿐이다. 사람이 쓰는 계정에 테스트 역할이 섞여 있으면 그 계정은 건너뛴다.
권한(`permissions`) 행은 발급 커맨드가 만들지 않는 공용 행이므로 원칙적으로 남긴다. 다만 예전 발급 커맨드가 DB 에
없던 식별자로 만들어 둔 행 — 이름·설명(ko·en)이 식별자 그대로, 코어 소유, 부모·자식 없음, 코어 정의(`config('core.permissions')`)
밖, 회수하는 테스트 역할 말고는 붙은 역할 없음, `--keep-hours` 밖 — 은 함께 회수한다(`--dry-run` 이 권한 수까지 센다).
사람이 읽는 이름을 가진 권한·사람 역할이 쓰는 권한은 남는다.
가드는 발급 커맨드와 같다 (CLI 한정 + `G7_PLAYWRIGHT_BYPASS=1`).
### 4.2 로그인 2단계 인증 픽스처

2단계 인증은 **사이트 설정과 메일 발송**에 의존하므로 브라우저만으로는 재현할 수 없다.
`tests/Playwright/fixtures/two-factor.ts` 가 세 가지를 가역적으로 준비한다.

| 준비 항목 | 방법 | 원복 |
|---|---|---|
| 보안 설정 `security.two_factor_auth` | 관리자 토큰으로 `/api/admin/settings` GET → POST (탭 전체를 되돌려 보낸다) | 읽어 둔 탭 전체 값을 그대로 되쓴다 |
| 메일 발송 | 받아서 버리는 로컬 SMTP 싱크를 띄우고 `mail.json` 의 `host`/`port`/`encryption` 을 줄 단위 치환 | 백업 파일에서 바이트 그대로 복원 |
| 인증번호 | `php artisan playwright:seed-two-factor --plant=<challenge_id> --code=135790` | 없음 (challenge 는 일회성) |
| 테스트 계정 | `--ensure-user=` 로 생성 | `--purge-users` 로 종료 시 즉시 제거 |

**메일을 `log` 메일러로 돌리지 않는 이유**: `log` 는 이 제품의 설정 스키마에 없다. 저장 검증이
등록된 메일 드라이버(`smtp`·`mailgun`·`ses`)만 허용하고, 설정 파일을 직접 고쳐도 드라이버 해석
단계에서 `smtp` 로 되돌아간다(실측 확인). 그래서 `tests/Playwright/fixtures/smtp-sink.ts` 가
Node 기본 `net` 만으로 최소 SMTP 서버를 127.0.0.1 에 잠깐 띄우고 그쪽으로 돌린다 —
인증도 TLS 도 요구하지 않으며 받은 메일은 어디에도 남기지 않는다.

**메일 설정만 파일을 직접 다루는 이유**: 원래 값(빈 SMTP 호스트)은 저장 검증(`smtp` 이면 host
필수)을 통과하지 못해 **API 로는 되돌릴 수 없다**. 보안 설정은 API 로, 메일 설정은 파일
백업·복원으로 왕복한다.

`playwright:seed-two-factor` 계약:

| 옵션 | 하는 일 |
|---|---|
| `--ensure-user=<접미사>` | `playwright_2fa_<접미사>@example.test` 계정을 알려진 비밀번호로 생성/갱신하고 이메일을 출력. 잠금·실패 카운트도 초기화한다 |
| `--admin` | 위 계정에 admin 역할 부여 (없으면 기존 역할을 떼어 관리자 거부 경로를 재현할 수 있게 한다) |
| `--password=` | `--ensure-user` 가 설정할 비밀번호 (기본 `Passw0rd!2fa`) |
| `--plant=<uuid>` | 그 challenge 에 알려진 인증번호의 해시를 심고 코드를 출력 |
| `--code=` | `--plant` 가 심을 인증번호 (기본 `135790`) |
| `--gc-hours=` | 이 시간보다 오래된 테스트 계정 정리 (기본 6, `0` 이면 정리 안 함) |
| `--purge-users` | 나이와 무관하게 테스트 계정을 전부 제거 — 실측 종료 직후 호출한다. 이 계정들은 알려진 비밀번호를 갖고 관리자용은 관리자 역할까지 가지므로, 나이 기준 정리를 기다리는 사이가 그대로 열린 문이 된다. 삭제는 운영자 삭제와 같은 경로(`UserService::deleteUser`)를 타서, 확장이 `core.user.before_delete` 에서 자기 행을 치운다 |

인증 앱 자격증명은 그 플러그인 소유라 코어 커맨드가 다루지 않는다 — `playwright:seed-totp --enroll=<email>` 이
확정된 자격증명과 고정 백업 코드를 심고 시크릿을 출력하며, `--code=<secret>` 이 그 순간의 코드를 계산한다
(fixture `enrollTotpFor()`·`totpCodeFor()`).

가드는 `playwright:issue-token` 과 동형이다 — CLI 한정 + `G7_PLAYWRIGHT_BYPASS=1` + `APP_DEBUG` 강제.

인증번호는 해시로만 저장되어 되읽을 수 없다. 메일함을 실제로 여는 대신 알려진 값을 심는 이유이며,
방식은 PHPUnit `TwoFactorAuthTest::issuedCode()` 와 같다 — 검증 대상은 코드 생성이 아니라
로그인 흐름(코드 확인 전 토큰 미발급 / 확인 후 발급)이다.

**원복 실패를 삼키지 않는다.** 되돌리지 못한 채 끝나면 사이트가 2단계 인증이 켜진 상태로 남아
이후 **모든 로그인이 막힌다**. 그래서 이 spec 은 `test.describe.configure({ mode: 'serial' })`
로 한 워커에서만 실행하고, `afterAll` 의 원복 실패는 그대로 던진다.

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
| fixture 원본 | `tests/Playwright/fixtures/seed-layouts/{user\|admin}.e2e_sandbox.json` (활성 템플릿 종류별) |
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

#### 조회 범위(scope)를 검증할 때는 `식별자:self` · `식별자:role`

권한은 기본적으로 전체 범위로 부여된다. 「본인만」·「같은 역할만」으로 제한된 위임 관리자 세션이
필요하면 식별자 뒤에 범위를 붙인다 — 커맨드가 그 범위(`role_permissions.scope_type`)로 부여한다.
범위를 거는 보조 경로(최근 목록·통계·대시보드 카드 등)가 주 경로와 같은 범위를 따르는지 잠글 때 쓴다.
`self`·`role` 외의 값은 커맨드가 거부한다.

```typescript
// 회원 조회를 본인 범위로만 가진 계정 — 회원 목록·최근 가입·통계가 모두 본인 1명이어야 한다
const token = issueScopedToken('core.users.read:self', 'core.dashboard.read');
```

#### 정해 둔 관리자 계정으로 재야 할 때는 `adminToken` (`tests/Playwright/fixtures/admin-login.ts`)

`issueToken` 은 실행마다 `playwright_test_*` 계정을 만든다. 테스트 데이터를 정해 둔 계정으로만 남겨야 하는 실행에서는
`G7_E2E_ADMIN_EMAIL` 과 `G7_E2E_ADMIN_PASSWORD_FILE`(비밀번호를 담은 파일 경로 — 비밀번호를 명령줄·로그에 남기지 않는다)을
주면 `adminToken(request, ...permissions)` 이 그 계정으로 `POST /api/auth/admin/login` 해 토큰을 받는다. 둘 중 하나라도
없으면 `issueToken(...permissions)` 로 돌아간다.

```typescript
const token = await adminToken(request, 'core.templates.layouts.edit');
await authenticatePage(page, token);
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

자동 검증이 매니페스트 axes ↔ docblock 매칭을 자동 검증.

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
| Sanctum 토큰·역할 누적 DB 오염 (`roles/active` 가 느려짐) | 매 spec 마다 새 user/role 생성 | `php artisan playwright:cleanup` — §4 "발급분 회수" 참조 |
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
