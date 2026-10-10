/**
 * sirsoft-admin_basic — 코드 편집기가 동봉한 Monaco 판을 실제로 불러오는가 (#126)
 *
 * Monaco 상향은 소스 상수·복사 스크립트 경로·의존성 핀·테스트 어서션·`dist/vendor/` 디렉토리
 * 다섯 곳을 동시에 맞춰야 한다. 하나라도 어긋나면 자산 경로가 404 가 되어 편집기가 평문
 * 입력(textarea) 폴백으로 떨어지는데, **빌드도 단위 테스트도 통과한다** — 브라우저를 열기
 * 전에는 드러나지 않는다.
 *
 * 0.56.0 은 그 위에 두 가지 파괴 변경을 얹었다.
 *   1. `editor.main` 이 더 이상 `m` 을 내보내지 않는다 → 구 `@monaco-editor/loader` 는
 *      `undefined` 를 monaco 인스턴스로 넘겨 편집기가 통째로 죽는다.
 *   2. JSON 언어 서비스가 `monaco.languages.json` 에서 최상위 `monaco.json` 으로 옮겼다 →
 *      구 경로만 부르면 JSON 파일을 열 때 예외가 난다.
 *
 * 둘 다 "편집기 화면은 열리는데 내용이 없다 / 콘솔에만 예외가 난다" 로 나타나므로, 실제
 * 브라우저에서 자산 응답 코드와 편집기 마운트를 함께 재야 한다.
 *
 * 저장을 하지 않으므로 실제 파일을 건드리지 않는다.
 *
 * @scenario asset_class=vendored, outcome=loaded
 * @effects vendored_asset_declared_path_exists_on_disk, runtime_asset_served_same_origin,
 *          vendored_editor_mounts_without_fallback, vendored_asset_version_matches_declaration,
 *          vendored_amd_loader_runs_exactly_once
 *
 * 실행: `code-editor-*.spec.ts` 는 `--workers=1` 로 돌린다 — 사유는
 * `code-editor-file-crud.spec.ts` 헤더 참조(같은 시드 화면을 공유해 병렬에서 서로를 밟는다).
 */
import { test, expect, authenticatePage } from '../../fixtures/admin-template-auth';
import type { Page } from '@playwright/test';

/** 이 템플릿이 동봉한 Monaco 버전 — `CodeEditor.tsx` 의 `MONACO_VERSION` 과 같아야 한다 */
const MONACO_VERSION = '0.56.0';

/** 동봉 자산 경로 조각 (확장자 모드 · 쿼리 모드 양쪽에서 공통으로 나타난다) */
const MONACO_PATH_FRAGMENT = `vendor/monaco-editor/${MONACO_VERSION}/vs`;

/**
 * 코드 편집기 화면을 엽니다.
 *
 * @param page Playwright page
 * @param token 인증 토큰
 */
async function openCodeEditor(page: Page, token: string): Promise<void> {
  await authenticatePage(page, token);
  await page.goto('/admin/templates/sirsoft-admin_basic/edit');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  // 좌측 목록은 `role="tree"` 파일 트리다(#476) — 고정 폭 카드가 아니라 트리가 뜨는 것이 진입 신호다.
  await page.waitForSelector('[role="tree"] [role="treeitem"]', { timeout: 30_000 });
}

// 동봉 Monaco 첫 로드는 수 MB AMD 청크를 받는다 — 여러 워커가 같은 dev 서버에서 동시에 받으면
// 마운트 대기(30초)가 기본 테스트 예산(30초)을 그대로 먹어 예산 초과로 끝난다. 대기보다 예산을 크게 둔다.
test.beforeEach(({}, testInfo) => testInfo.setTimeout(120_000));

test.describe('@sirsoft-admin_basic 코드 편집기 동봉 Monaco', () => {
  /**
   * @scenario asset_class=vendored, outcome=loaded
   *
   * @effects runtime_asset_served_same_origin
   */
  test('선언한 버전 경로에서 same-origin 200 으로 로더를 받는다', async ({ page, layoutEditToken }) => {
    const monacoResponses: Array<{ url: string; status: number }> = [];

    page.on('response', (response) => {
      const url = response.url();

      if (url.includes('monaco-editor')) {
        monacoResponses.push({ url, status: response.status() });
      }
    });

    await openCodeEditor(page, layoutEditToken);

    // 편집기는 파일을 고른 뒤에 마운트된다 — 첫 파일을 연다(편집기 정본이 `.g7.html` 이다, #476).
    await page.locator('#editor_card').getByText(/\.g7\.html$/).first().click();

    // 고정 대기(3초)는 동봉 Monaco 첫 로드가 느린 환경에서 "아직 요청 전" 을 "요청 0건" 으로 오판한다 —
    // 자산 요청이 실제로 잡힐 때까지 기다린다(끝내 0이면 그때가 진짜 실패다).
    await expect
      .poll(() => monacoResponses.length, { timeout: 60_000 })
      .toBeGreaterThan(0);

    expect(
      monacoResponses.length,
      'Monaco 자산 요청이 하나도 없다 — 로더 설정이 동봉본을 가리키지 않는다',
    ).toBeGreaterThan(0);

    const origin = new URL(page.url()).origin;
    const foreign = monacoResponses.filter((r) => !r.url.startsWith(origin));

    expect(foreign, `외부 origin 에서 Monaco 를 받고 있다: ${JSON.stringify(foreign)}`).toEqual([]);

    const failed = monacoResponses.filter((r) => r.status >= 400);

    expect(
      failed,
      `동봉 Monaco 자산이 404/오류다 — 버전 기재 다섯 곳 중 하나가 어긋났을 수 있다: ${JSON.stringify(failed)}`,
    ).toEqual([]);

    const declaredPath = monacoResponses.filter((r) => r.url.includes(MONACO_PATH_FRAGMENT));

    expect(
      declaredPath.length,
      `요청 URL 이 선언한 버전 경로(${MONACO_PATH_FRAGMENT})를 지나지 않는다`,
    ).toBeGreaterThan(0);
  });

  /**
   * @scenario asset_class=vendored, outcome=loaded
   *
   * @effects vendored_editor_mounts_without_fallback
   */
  test('편집기가 마운트되고 폴백(textarea)으로 떨어지지 않는다', async ({ page, layoutEditToken }) => {
    const consoleErrors: string[] = [];

    page.on('console', (message) => {
      if (message.type() === 'error') consoleErrors.push(message.text());
    });

    await openCodeEditor(page, layoutEditToken);
    await page.locator('#editor_card').getByText(/\.g7\.html$/).first().click();

    // Monaco 가 살아 있으면 자기 DOM(.monaco-editor)을 그린다. 폴백 경로는 textarea 를 남긴다.
    await expect(
      page.locator('#editor_card .monaco-editor').first(),
      'Monaco 가 마운트되지 않았다 — 자산 확보 실패 또는 로더 비호환',
    ).toBeVisible({ timeout: 30_000 });

    const state = await page.evaluate(() => {
      const monaco = (window as unknown as { monaco?: Record<string, unknown> }).monaco;

      return {
        hasMonaco: Boolean(monaco),
        // 0.55+ 는 최상위 `json`, 그 이전은 `languages.json` — 어느 쪽이든 하나는 있어야
        // JSON 진단 설정이 붙는다. 둘 다 없으면 그 설정이 조용히 건너뛰어진 상태다.
        hasJsonDefaults: Boolean(
          (monaco as { json?: { jsonDefaults?: unknown } })?.json?.jsonDefaults
            ?? (monaco as { languages?: { json?: { jsonDefaults?: unknown } } })?.languages?.json?.jsonDefaults,
        ),
      };
    });

    expect(state.hasMonaco, 'window.monaco 가 없다 — 로더가 인스턴스를 넘기지 못했다').toBe(true);
    expect(state.hasJsonDefaults, 'JSON 언어 서비스 진입점을 찾을 수 없다').toBe(true);

    // 편집기가 살아 있으면 자산 실패 안내가 뜨지 않아야 한다.
    await expect(page.getByText('코드 편집기')).toHaveCount(0);

    const relevant = consoleErrors.filter(
      (text) => /monaco|jsonDefaults|Cannot read properties of undefined/i.test(text),
    );

    expect(relevant, `Monaco 관련 콘솔 오류: ${JSON.stringify(relevant)}`).toEqual([]);
  });

  /**
   * AMD 로더는 **한 판에 한 번만** 실행돼야 한다. (S3-D12)
   *
   * 편집기를 띄우려면 Monaco 를 `window.monaco` 에 미리 심어야 한다 — `@monaco-editor/loader`
   * 가 인스턴스를 `editor.main` 의 `m` 에서 꺼내는데 0.56.0 은 `m` 을 내보내지 않기 때문이다.
   * 그런데 그 심기와 라이브러리 자신의 주입이 겹치면 **로더 스크립트가 두 번 실행**되어
   * `_amdLoaderGlobal` 재선언 SyntaxError 가 나고, 그 판의 `require` 가 통째로 죽는다.
   *
   * 이 축이 없으면 「편집기가 뜬다」만 보고 지나친다 — 로더가 두 번 돌아도 먼저 뜬 편집기는
   * 살아 있어서, 마운트만 재는 축은 **green 으로 통과한다**(2026-09-16 실측: 스크립트 태그가
   * 3개까지 늘어난 상태에서도 편집기는 떴다).
   *
   * @scenario asset_class=vendored, outcome=loaded
   *
   * @effects vendored_amd_loader_runs_exactly_once
   */
  test('AMD 로더 스크립트가 한 번만 실행된다 (중복 주입 금지)', async ({ page, layoutEditToken }) => {
    const fatal: string[] = [];

    page.on('pageerror', (error) => fatal.push(String(error)));

    await openCodeEditor(page, layoutEditToken);
    await page.locator('#editor_card').getByText(/\.g7\.html$/).first().click();

    await expect(
      page.locator('#editor_card .monaco-editor').first(),
      'Monaco 가 마운트되지 않았다',
    ).toBeVisible({ timeout: 30_000 });

    const loaderTags = await page.locator('script[src*="/vs/loader.js"]').count();

    expect(
      loaderTags,
      `AMD 로더 스크립트가 ${loaderTags}개다 — 두 번 실행되면 그 판의 require 가 죽는다`,
    ).toBe(1);

    expect(
      fatal.filter((text) => /_amdLoaderGlobal|has already been declared/.test(text)),
      'AMD 로더가 중복 실행됐다',
    ).toEqual([]);
  });
});
