/**
 * Layout Editor — SaveFeedbackBanner 6 kind 매트릭스.
 *
 * useLayoutDocument.save 가 반환하는 SaveResult kind 별로 SaveFeedbackBanner 가
 * 올바른 testid 의 배너/모달을 렌더하는지 통합 검증.
 *
 * kind:
 *  - success                       → `g7le-save-banner-success`         (palette-i18n-and-globals.spec.ts 에서 가드)
 *  - validation_failed (422)       → `g7le-save-banner-validation`      (본 spec)
 *  - concurrent_modification (409) → `g7le-save-banner-concurrent`      (본 spec)
 *  - blocked_inactive_extension    → `g7le-save-banner-blocked`         (단위 테스트 영역 — 본 spec 에서는 스킵, 후속)
 *  - network_error                 → `g7le-save-banner-network`         (본 spec)
 *  - guard_no_document             → `g7le-save-banner-guard-no-document` (본 spec)
 *
 * 모킹: page.route() 로 PUT `/api/admin/templates/{id}/layouts/{name}` 응답을
 * 422/409/abort 로 분기시켜 클라이언트 분기 코드를 트리거. 가드 분기는
 * 라우트 미선택 상태에서 저장 클릭으로 자연 발생.
 *
 * @scenario save_kinds_422_409_network_guard
 * @effects banner_visible_per_kind
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const EDITOR_URL = '/admin/layout-editor/sirsoft-basic?route=%2F';
const SAVE_URL_PATTERN = /\/api\/admin\/templates\/sirsoft-basic\/layouts\//;

async function bootstrap(page: import('@playwright/test').Page) {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
}

async function waitForEditorReady(page: import('@playwright/test').Page) {
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForFunction(
    () => !document.querySelector('[data-testid="g7le-toolbar-add-element"]')?.hasAttribute('disabled'),
    { timeout: 30_000 },
  );
}

async function addDivAndSave(page: import('@playwright/test').Page) {
  // 툴바 버튼은 admin chrome 로케일로 렌더되므로(환경에 따라 영어) testid 로 조작.
  await page.getByTestId('g7le-toolbar-add-element').click();
  await page.waitForSelector('[data-testid="g7le-palette-item-Div"]', { timeout: 10_000 });
  await page.getByTestId('g7le-palette-item-Div').click();
  // history push 대기
  await expect(page.getByTestId('g7le-toolbar-undo')).toBeEnabled({ timeout: 5_000 });
  await page.getByTestId('g7le-toolbar-save').click();
}

test.describe('@layout-editor SaveFeedbackBanner kinds', () => {
  test('validation_failed (422) → 검증 배너 + 필드별 메시지', async ({ page }) => {
    await bootstrap(page);
    await page.route(SAVE_URL_PATTERN, async (route) => {
      if (route.request().method() !== 'PUT') return route.fallback();
      await route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({
          message: 'Validation failed',
          errors: {
            'components.0.name': ['컴포넌트 이름이 필요합니다.'],
            'components.0.props.text': ['텍스트는 1자 이상이어야 합니다.'],
          },
        }),
      });
    });

    await page.goto(EDITOR_URL);
    await waitForEditorReady(page);
    await addDivAndSave(page);

    await expect(page.getByTestId('g7le-save-banner-validation')).toBeVisible({ timeout: 5_000 });
    const errorsList = page.getByTestId('g7le-save-banner-validation-errors');
    await expect(errorsList).toBeVisible();
    await expect(errorsList).toContainText('components.0.name');
    await expect(errorsList).toContainText('컴포넌트 이름이 필요합니다.');

    // validation_failed 는 자동 dismiss 없음 — 5초 후에도 visible
    await page.waitForTimeout(5500);
    await expect(page.getByTestId('g7le-save-banner-validation')).toBeVisible();
  });

  test('concurrent_modification (409) → 모달 + version_info + 3 액션 버튼', async ({ page }) => {
    await bootstrap(page);
    await page.route(SAVE_URL_PATTERN, async (route) => {
      if (route.request().method() !== 'PUT') return route.fallback();
      await route.fulfill({
        status: 409,
        contentType: 'application/json',
        body: JSON.stringify({
          message: 'Concurrent modification detected',
          current_version: 42,
          your_version: 38,
        }),
      });
    });

    await page.goto(EDITOR_URL);
    await waitForEditorReady(page);
    await addDivAndSave(page);

    const modal = page.getByTestId('g7le-save-banner-concurrent');
    await expect(modal).toBeVisible({ timeout: 5_000 });
    await expect(modal).toHaveAttribute('role', 'dialog');
    // version_info 에 current/yours 값이 표시되어야 함
    await expect(modal).toContainText('42');
    await expect(modal).toContainText('38');
    // 3 액션 버튼
    await expect(page.getByTestId('g7le-save-banner-concurrent-load-latest')).toBeVisible();
    await expect(page.getByTestId('g7le-save-banner-concurrent-keep-mine')).toBeVisible();
    await expect(page.getByTestId('g7le-save-banner-concurrent-cancel')).toBeVisible();

    // 취소 클릭 시 모달 닫힘
    await page.getByTestId('g7le-save-banner-concurrent-cancel').click();
    await expect(modal).toBeHidden();
  });

  test('network_error → 네트워크 에러 배너 + message', async ({ page }) => {
    await bootstrap(page);
    await page.route(SAVE_URL_PATTERN, async (route) => {
      if (route.request().method() !== 'PUT') return route.fallback();
      // fetch 자체 실패 시뮬레이션 (TypeError: Failed to fetch)
      await route.abort('failed');
    });

    await page.goto(EDITOR_URL);
    await waitForEditorReady(page);
    await addDivAndSave(page);

    await expect(page.getByTestId('g7le-save-banner-network')).toBeVisible({ timeout: 5_000 });
  });

  test('guard_no_document → 라우트 미선택 상태 저장 시 가드 배너', async ({ page }) => {
    await bootstrap(page);
    // 라우트 쿼리 없이 진입 (홈 자동선택 회피)
    await page.goto('/admin/layout-editor/sirsoft-basic');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForSelector('[data-testid="g7le-toolbar-save"]', { timeout: 30_000 });

    // 라우트 미선택 — document 미로드 상태에서 저장 클릭
    await page.getByTestId('g7le-toolbar-save').click();

    await expect(page.getByTestId('g7le-save-banner-guard-no-document')).toBeVisible({ timeout: 5_000 });
  });

  // blocked_inactive_extension — sessionAddedPaths 가드는 클라이언트 내부 분기.
  // resolveActiveExtensions 콜백을 mock 하려면 useLayoutDocument hook 자체를 wrap 해야 하므로
  // 단위 테스트(useLayoutDocument.test.ts) 영역으로 위임. 본 spec 에서는 스킵하고 후속에서
  // dev-only fixture 또는 단위 테스트로 커버.
  test.skip('blocked_inactive_extension → 비활성 확장 path 차단 배너 (후속 — 단위 테스트로 커버)', async () => {
    // 본 분기는 useLayoutDocument 의 options.resolveActiveExtensions 콜백이
    // 비활성 확장 path 를 반환할 때만 발생. Playwright 통합 환경에서는 활성 확장이
    // 항상 정상이므로 자연 발생하지 않음. 단위 테스트로 커버.
  });
});
