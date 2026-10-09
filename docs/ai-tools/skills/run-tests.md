<!-- 자동 생성 문서입니다. 직접 고치지 마세요. -->

# 테스트 실행 (run-tests)

## 언제

- 코드 변경 후 그 변경에 걸리는 테스트를 검증할 때, 사용자가 테스트 실행을 지시할 때, 커밋 전 점검 때.
- 재검증은 수정·새 실패·사용자 재실행 요청이 있을 때만 한다. 결과 열람만을 위한 재실행은 하지 않고 저장된 출력을 읽는다.
- `backend` = PHPUnit, `frontend` = Vitest(핸들러·컴포넌트·레이아웃 렌더링 `layouts` 패턴), `e2e` = Playwright(references/e2e.md(아래 「e2e」 절)). "전체" 타입은 없다 — 변경 범위에 걸리는 테스트만 고른다(테스트 실행 범위, run-only-needed-test-scope-never-full-suite).

## 입력

- 인자: 타입 + 범위(`--filter='A|B'`, Vitest 패턴, 구체 spec 파일). 인자가 없으면 변경 파일에서 대상을 도출한다 — 변경 파일 → 테스트 파일 매핑과 다계층 묶음 표는 references/scope.md(아래 「scope」 절).
- 신규 기능이면 시나리오 매니페스트(`tests/scenarios/`, 확장 `tests/scenarios/`).
- 규정: [testing-guide.md](../../testing-guide.md), [layout-testing.md](../../frontend/layout-testing.md), [e2e-testing.md](../../testing/e2e-testing.md). Windows 실행 형식의 원본은 operations.md Windows 환경 테스트 규칙.

## 절차

1. (backend) `.env.testing` 을 확인한다. 없으면 생성 필요를 알리고 기존 사용자 허가를 확인한다. 허가가 없으면 생성에 필요한 확인만 요청하고, 허가 후 `.env.testing.example` 을 복사해 `php artisan key:generate --env=testing` 을 실행한다. 존재 여부와 관계없이 테스트 DB가 개발 DB와 다른지 확인한 뒤 실행하며 접속 정보는 출력에 노출하지 않는다.
2. 대상을 고른다 — 변경 파일에 걸리는 테스트와 그 변경이 영향을 주는 호출처·구독자·데이터 흐름의 연관 테스트(run-every-test-class-that-pokes-the-changed-contract). 새 화면·기존 화면 수정·데이터 흐름 변경은 백엔드 + 프론트엔드 + 레이아웃 렌더링(+해당 시 E2E)이 한 묶음이며 단일 계층으로 완료를 선언하지 않는다.
3. 실행한다. Windows 에서 Vitest 는 PowerShell 래퍼로, PHPUnit 은 Bash 로 직접 실행한다. 스위트는 한 번에 하나씩 순차로 띄우고 앞 스위트가 끝난 뒤 다음을 시작한다(never-run-two-phpunit-suites-concurrently, never-run-two-e2e-suites-concurrently). 진행 중인 스위트는 진행률 확인 목적으로도 중단하지 않는다(never-interrupt-running-test-db-corruption). 실행 중에는 훅·라우트 캐시를 다시 굽는 artisan 을 돌리지 않는다(no-cache-regeneration-while-phpunit-runs). 출력은 처음부터 상세 오류까지 `<agent-tmp>/test-<name>.txt` 로 저장한다(save-test-output-to-file, test-show-full-error-output-first-run).

   ```bash
   ```

   ```powershell
   ```

4. 저장된 출력을 grep/sed 로 분석한다. `OK, but issues!` 안의 warnings·deprecations·skips·intermittent 까지 전수 확인한다(unrelated-test-errors). 실패는 에러 메시지 → 테스트 코드 → 구현 코드 → 테스트 데이터(Factory·Mock) 순으로 보고, git 히스토리·호출처 분석으로 테스트 stale 인지 로직 회귀인지 판정한다(test-failure-root-cause-analysis). skip 으로 회피하지 않는다(no-skip-without-root-fix). 유형별 대응(Assertion·Exception·401/403·404·422·Mock·Factory)은 실패 유형별 대응 표. 수정 후에는 실패한 테스트만 다시 실행한다. 중단된 스위트의 실패 건은 재실행 없이 특정한다(locate-failures-in-aborted-phpunit-run).
6. references/scope.md 보고 양식(아래 「scope」 절) 으로 보고한다. FAIL/ERROR/RISKY 는 전수 나열하고 항목마다 연관 여부·판정 근거·조치 분기를 적으며, 대표 1건만 적고 나머지를 생략하지 않는다. 계획서(`<agent-plans>/[화면명]/PROGRESS.md`) 기반 작업이면 회차마다 재검증 이력 표에 한 행(백엔드·프론트엔드·레이아웃 통과/전체, 실패 원인·대응 유형)을 더한다.

## 완료 조건

- 관련 계층 전부의 스위트가 범위를 좁힌 명령으로 순차 실행됐고 출력 파일이 남아 있다.
- 실패·오류·리스키가 0건이거나, 각 항목이 3필드(연관 여부·판정 근거·조치 분기)로 보고되고 같은 세션에서 처리됐다. 외부 환경 의존·계획서에 명시된 의도적 깨진 상태만 제외한다.
- 신규 기능이면 `test-scenario-coverage` 위반 0건.
- 계획서 기반 작업이면 PROGRESS.md 재검증 이력에 이번 회차 행이 있다.
- 테스트 작성이 아니라 테스트 통과가 완료다.

## 참조

- references/scope.md(아래 「scope」 절) — 변경 파일 → 테스트 매핑, 다계층 묶음, 보고 양식
- references/e2e.md(아래 「e2e」 절) — Playwright 실행 명령, 코어/확장 분리, 환경 요건, trace 분석
- operations.md — .env.testing, Windows 환경 테스트 규칙, 테스트 실행 범위
- [testing-guide.md](../../testing-guide.md), [layout-testing.md](../../frontend/layout-testing.md), [e2e-testing.md](../../testing/e2e-testing.md)
- testing.md — phpunit-parallel-and-output, no-skip-without-root-fix
- 계획: plan-tests

---

## e2e

`/run-tests e2e` 에서 Playwright(TypeScript) spec 을 실행하고 결과를 분석하는 절차다. 순차 실행·중단 금지·출력 저장·무관 에러 처리 규율은 SKILL.md 3~4단계와 같다.

### 요약

- 도구: Playwright 1.49+ (Chromium). 설치: `npx playwright install chromium`.
- Base URL: `PLAYWRIGHT_BASE_URL` 환경변수 필수(또는 `.env` `APP_URL` 이 활성 호스트). `localhost` 류는 fallback 으로 부적합하며 인프라에 도메인을 하드코딩하지 않는다(e2e-infra-no-hardcoded-domain).
- 인증: `PlaywrightIssueToken` 이 `G7_PLAYWRIGHT_BYPASS=1` 로 토큰을 발급하며 모든 fixture 가 자동 부착한다.
- 결과 저장: `<agent-tmp>/playwright-<타입>.txt`.
- 코어/확장 분리: 코어는 루트에서, 확장은 해당 확장 디렉토리에서 자체 `playwright.config.ts` 로 실행한다.

### 실행 명령

| 타입 | 명령 | 대상 |
| --- | --- | --- |
| smoke | `npm run test:e2e:smoke` | `@smoke` 태그 spec(homepage / login / admin-dashboard). 스크립트가 `--grep @smoke` 를 품고 있어 스코핑으로 인정 |
| 특정 spec | `npx playwright test tests/Playwright/specs/wysiwyg/auth-guard.spec.ts` | 단일 파일 |
| 태그·이름 | `npx playwright test --grep '@feature'` | grep 으로 좁힌 집합 |
| ui | `npm run test:e2e:ui` | UI 모드(단계별 디버깅, 게이트 아님) |

`npm run test:e2e` / `npx playwright test` 단독 실행은 무필터이므로 차단된다. 전체 실행이 필요하면 사용자에게 소요 시간과 함께 먼저 제안한다.

값은 `.env` `APP_URL` 과 같은 활성 호스트(vhost 경유 URL)다. 아래 `<활성 호스트>` 를 그 값으로 바꾼다.

```powershell
$env:PLAYWRIGHT_BASE_URL='https://<활성 호스트>'
bash
```

### 코어/확장 분리

| 영역 | 실행 위치 |
| --- | --- |
| 코어 | 프로젝트 루트 — `npx playwright test <spec>` |
| 모듈 | `cd modules/_bundled/{id} && npx playwright test --config=tests/Playwright/playwright.config.ts <spec>` |
| 플러그인 | `cd plugins/_bundled/{id} && npx playwright test --config=tests/Playwright/playwright.config.ts <spec>` |
| 템플릿 | `cd templates/_bundled/{id} && npx playwright test --config=tests/Playwright/playwright.config.ts <spec>` |

확장은 자체 `playwright.config.ts` 를 가지므로 별도 설정 동기가 필요 없다. 상세: e2e-testing.md §3.

### 다계층 검증

| 작업 유형 | PHPUnit | Vitest | Playwright |
| --- | --- | --- | --- |
| 새 화면 구현 | API 엔드포인트 | 컴포넌트 | E2E 통합 spec |
| 위지윅 편집기 변경 | LayoutAccessCheck | wysiwyg 단위 | wysiwyg 매트릭스 |
| 인증/권한 변경 | 권한 가드 | AuthManager | smoke + auth-guard |

Vitest green 만으로 프론트 변경의 완료를 단정하지 않는다 — 사용자 화면에서 기능이 작동하는지 spec 으로 확인하는 것까지가 한 묶음이며, 시나리오 매니페스트의 axis cross product 와 effects 를 spec docblock 으로 전수 커버한다(plan-tests references/e2e.md).

### 실패 분석

1. `<agent-tmp>/playwright-<타입>.txt` 에서 fail 케이스를 식별한다.
2. `npx playwright show-trace test-results/<...>/trace.zip` 으로 timeline 을 단계별로 확인한다(DOM 스냅샷 + 네트워크).
3. 원인 불명이면 `chrome-devtools-mcp` 로 동일 URL 을 라이브 진단한다. MCP 는 진단 보조이며 단독 게이트가 아니다 — 모든 회귀는 Playwright spec 으로 승격돼야 커밋 게이트에서 효력이 있다(e2e-testing.md §11).
4. 진단 결과로 spec 을 보강하거나 코드를 수정하고, 실패한 spec 만 다시 실행한다.

Playwright spec 과 Chrome MCP 실측을 같은 개발 서버에서 동시에 돌리면 SPA 로딩 locator 가 전건 타임아웃된다(playwright-and-chrome-mcp-on-same-dev-server-time-out).

### 자주 만나는 증상

| 증상 | 원인 | 해결 |
| --- | --- | --- |
| `Tests timed out — networkidle` | Reverb WebSocket 지속 연결 | `waitForLoadState('domcontentloaded')` + `waitForFunction` |
| 401 무한 redirect | 토큰이 testing DB 에만 있음 | `G7_PLAYWRIGHT_BYPASS=1` 로 호출 |
| `preview-canvas-container 없음` | 위지윅 편집기 access-check 거부 | `page.route()` 로 access-check 200 mock |
| 외부 origin 으로 trace 복잡 | spec 이 외부 도메인 로드 | `page.route('https://example.com/**', route => route.fulfill(...))` |
| Sanctum 토큰·`playwright_test_*` 역할 누적 | spec 마다 새 user/role | 수동 회수 `$env:G7_PLAYWRIGHT_BYPASS='1'; php artisan playwright:cleanup`(`--dry-run`·`--keep-hours=N`). `tests/Playwright/global-teardown.ts` 는 시드 레이아웃만 지우고 토큰은 회수하지 않는다 |

전체 표는 e2e-testing.md §10. fixture 의 동기 재시도 대기는 `execSync sleep` 대신 Atomics 를 쓴다(playwright-retry-wait-use-atomics-not-execsync-sleep). 이커머스 spec 의 함정은 ecommerce-playwright-e2e-traps.

### 참고

- 가이드: e2e-testing.md, 빠른 시작: `tests/Playwright/README.md`
- 시나리오 매니페스트 매핑: e2e-testing.md §7, plan-tests references/e2e.md

---

## scope

`/run-tests` 2단계에서 실행 대상을 도출할 때 쓰는 매핑 표와, 6단계 보고 양식이다.

### 변경 파일 → 테스트 파일 매핑

| 수정 대상 | 테스트 위치 |
| --- | --- |
| `app/{Models,Services,Enums}/*.php` | `tests/Unit/{Models,Services,Enums}/*Test.php` |
| `app/Http/Controllers/**` | `tests/Feature/**/*Test.php` |
| `database/migrations/*.php`, `{modules,plugins}/_bundled/{id}/database/migrations/*.php` | 그 테이블을 쓰는 모델·서비스 테스트(스키마 변경 검증) — 업그레이드 스텝이 있으면 그 스텝 테스트 포함 |
| `modules/_bundled/{id}/src/**`, `plugins/_bundled/{id}/src/**` | 해당 확장 `tests/{Feature,Unit}/` |
| `resources/js/core/**/*.ts` | `resources/js/core/**/__tests__/*.test.ts` |
| `resources/layouts/**/*.{json,g7.html}` | `resources/js/core/template-engine/__tests__/layouts/*.test.tsx` |
| `modules/_bundled/{id}/resources/layouts/**/*.{json,g7.html}` | `modules/_bundled/{id}/resources/js/__tests__/layouts/*.test.tsx` |
| `templates/_bundled/{id}/layouts/**/*.{json,g7.html}`, `.../src/components/**/*.tsx` | `templates/_bundled/{id}/__tests__/layouts/`, `.../src/**/__tests__/` |
| `plugins/_bundled/{id}/resources/layouts/**/*.{json,g7.html}` | `plugins/_bundled/{id}/resources/js/__tests__/layouts/*.test.tsx` |
| `(templates\|modules\|plugins)/_bundled/**/extensions/**/*.{json,g7.html}` | 해당 확장의 `__tests__/layouts/*.test.tsx` (확장 주입 fragment) |

확장 테스트는 확장 디렉토리에서 수집한다. 코어와 확장 테스트를 한 `--filter` 로 섞어 수집하면 모듈 마이그레이션이 누락된다(test-filter-cross-pollination).

### 다계층 묶음

| 작업 유형 | 백엔드 | 프론트엔드 | 레이아웃 렌더링 | E2E |
| --- | --- | --- | --- | --- |
| 새 화면 구현 | API 엔드포인트 | 컴포넌트·핸들러 | 레이아웃 JSON 렌더링 | 통합 spec |
| 기존 화면 수정 | 변경된 API | 변경된 컴포넌트 | 렌더링 회귀 | 영향 흐름 spec |
| 데이터 흐름 변경 | Service/Repository | 상태 관리 | 데이터 바인딩 렌더링 | 해당 시 |

레이아웃 렌더링 테스트는 `createLayoutTest()` 기반 Vitest 라 브라우저가 필요 없다 — "인프라 부족" 을 이유로 건너뛰지 않는다.

### 보고 양식

```text
### 테스트 결과
- 타입·실행 명령·출력 파일
- 총 / 통과 / 실패 / 오류 / 리스키·스킵 건수
- 다계층 확인: 백엔드 [실행/미해당], 프론트엔드 [..], 레이아웃 렌더링 [..], E2E [..]
- 시나리오 매니페스트 충족도(해당 시): cross product N/M, effects N/M, 누락 목록, audit PASS/FAIL
- FAIL/ERROR/RISKY 전수 나열 — 항목마다 파일:라인, 원인, 연관 여부(YES/NO/UNCLEAR), 판정 근거(커밋·blame·git log -S), 조치 분기(테스트 수정 / 로직 수정 + 회귀 테스트 / 사용자 확인 요청)
```

대표 1건만 적고 나머지를 생략하지 않는다. 제외 허용 범위와 분기 기준은 testing-guide.md 무관 에러 처리.

### 참고

- 실행 규율 원본: SKILL.md 3~4단계, operations.md
- E2E 전용 절차: references/e2e.md(아래 「e2e」 절)
