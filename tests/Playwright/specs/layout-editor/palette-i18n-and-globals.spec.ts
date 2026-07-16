/**
 * Layout Editor — Palette i18n + 격리 _global seed 회귀 가드.
 *
 * 결함 K (편집기 chrome 컨텍스트 admin_basic ↔ 편집 대상 basic):
 *  - 편집기는 sirsoft-admin_basic 컨텍스트로 chrome 을 렌더하지만 편집 대상은 임의 템플릿
 *  - PaletteCard 의 entryT 가 호출자의 editorTemplateId 사전으로 entrySpec.label 을 해석해야 함
 *  - admin partial 에만 있는 키 / basic partial 에만 있는 키 양쪽 모두 정상 해석되어야 함
 *
 * 결함 D' (편집 모드 baseline _global 시드):
 *  - 편집 모드는 데이터소스를 sampleProvider 로 우회 — _global.currentUser / settings.general.site_name 등이 빈 상태
 *  - PreviewCanvas 의 useState 초기값 + installPreviewCanvasStore initialGlobalState 양쪽에 coreSampleGlobalSeed 주입
 *  - 헤더 사이트명 / Welcome 헤딩 / 로그인 분기 버튼 visibility 가 정상 분기되어야 함
 *
 * 6 kind 매트릭스 (SaveFeedbackBanner — audit `frontend-change-requires-e2e` 충족):
 *  - success / validation_failed (422) / concurrent_modification (409)
 *  - blocked_inactive_extension / network_error / guard_no_document
 *  본 spec 은 success 1 kind 를 통합 검증하고, 나머지 5 kind 는 향후 mock fetch 분리 spec 으로 인계 — 본 변경 범위 내 wiring 회귀 가드 우선.
 *
 * sampleGlobal deep merge 체인:
 *  - PreviewCanvas 가 coreSampleGlobalSeed + 활성 확장 sampleGlobal 소스를 buildSampleGlobalSeed
 *  로 합성한 baseline 을 격리 store 에 주입한다. 활성 확장이 sampleGlobal 을 작성하기 전
 *    에는 코어 시드만 적용되므로 결함 D' 의 user-visible 동작과 동일 — 본 spec 의 _global 시드
 *    케이스가 그 회귀를 잠근다.
 *  - guest_only 레이아웃은 체인 레벨에서 코어 keyspace currentUser 를 시드에서 제외(흡수).
 *    "이미 로그인되어 있습니다" 토스트 미발화 가드.
 *
 * @scenario palette_i18n + global_seed + sample_global_chain + guest_only_no_currentUser + save_success
 * @effects undo_history_reset + banner_visible + guest_only_no_redirect_toast
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

test.describe('@layout-editor palette i18n + global seed', () => {
  test('편집 대상 templateId 사전으로 PaletteCard entry label 해석 (결함 K)', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    await page.goto('/admin/layout-editor/sirsoft-basic?route=%2F');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    // 툴바 버튼은 admin chrome 로케일로 렌더되므로(환경에 따라 영어) testid 로 대기/클릭.
    await page.waitForSelector('[data-testid="g7le-toolbar-add-element"]', { timeout: 30_000 });

    // 라우트 로드까지 추가 대기 — 라우트 트리 + 매니페스트 + editor-spec fetch 완료
    await page.waitForFunction(
      () => !document.querySelector('[data-testid="g7le-toolbar-add-element"]')?.hasAttribute('disabled'),
      { timeout: 15_000 },
    );

    await page.getByTestId('g7le-toolbar-add-element').click();
    await page.waitForSelector('[data-testid="g7le-palette"]', { timeout: 10_000 });

    // basic partial 에만 있는 키 (admin partial 미보유) — admin chrome 컨텍스트로 해석되면
    // raw key 노출 회귀. 편집 대상(sirsoft-basic) 사전으로 해석되면 활성 로케일의 값이 나온다.
    // admin chrome 로케일이 환경에 따라 ko/en 이므로 양쪽 값을 모두 허용(핵심 가드는 raw key
    // 미노출 — 아래 unresolvedCount). 결함 K 의 본질은 "편집 대상 사전 해석" 이다.
    const passwordCard = page.getByTestId('g7le-palette-item-PasswordInput');
    await expect(passwordCard).toContainText(/비밀번호 입력|Password input/);
    await expect(passwordCard).toHaveAttribute('title', /비밀번호 입력|Password input/);

    const richEditorCard = page.getByTestId('g7le-palette-item-RichTextEditor');
    await expect(richEditorCard).toContainText(/리치 텍스트 에디터|Rich text editor/);

    // basic + admin 양쪽에 있는 공통 키도 회귀 없이 정상
    const divCard = page.getByTestId('g7le-palette-item-Div');
    await expect(divCard).toContainText(/박스|Box/);

    // 미해석 카운트 = 0
    const unresolvedCount = await page.evaluate(() => {
      const cards = document.querySelectorAll('[data-testid^="g7le-palette-item-"]');
      let count = 0;
      cards.forEach(el => {
        const text = el.querySelectorAll('div')[1]?.textContent?.trim() ?? '';
        if (text.includes('layout_editor.palette.')) count++;
      });
      return count;
    });
    expect(unresolvedCount, '미해석 entry label 키가 0건이어야 함 (결함 K 회귀 가드)').toBe(0);
  });

  test('편집 모드 _global 시드 (currentUser + settings.general.site_name) — 결함 D\'', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    await page.goto('/admin/layout-editor/sirsoft-basic?route=%2F');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForSelector('header', { timeout: 30_000 });

    // 캔버스 마운트 + 시드 적용 대기. 편집 모드는 격리 store 를 쓰므로(이중 저장소,
    // engine-v1.43.0+) 격리 _global 은 `__templateApp.getGlobalState()` 로 조회한다
    // (전역 devtools.state 는 격리 store 의 _global 을 반영하지 않음 — guest_only 테스트와 동일 경로).
    await page.waitForFunction(() => {
      const app = (window as any).__templateApp;
      const g = app?.getGlobalState?.();
      return !!g?.currentUser?.name && !!g?.settings?.general?.site_name;
    }, { timeout: 15_000 });

    // 헤더 사이트명 "Site" 폴백이 아닌 시드값
    const headerText = await page.locator('header').first().textContent();
    expect(headerText).toContain('샘플 사이트');
    expect(headerText).not.toMatch(/^Site\s/);

    // currentUser 로그인 분기 — Welcome 히어로의 마이페이지 버튼 텍스트가 채워져 있음
    // (이전: 빈 텍스트). 활성 로케일에 따라 "마이페이지"(ko)/"My Page"(en) 모두 허용.
    const myPageBtn = page.getByRole('button', { name: /마이페이지|My Page/ }).first();
    await expect(myPageBtn).toBeVisible();
  });

  test('guest_only 레이아웃 — sampleGlobal 체인이 currentUser 시드 제외 + 토스트 미발화', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    const toasts: string[] = [];
    page.on('console', (msg) => {
      // 토스트가 _global.toasts 경유로 렌더되면 화면 텍스트로 잡되, 콘솔 navigate suppressed 도 수집
      if (/already logged in|이미 로그인/i.test(msg.text())) toasts.push(msg.text());
    });

    // guest_only 레이아웃(로그인 화면)으로 진입
    await page.goto('/admin/layout-editor/sirsoft-basic?route=%2Flogin');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForSelector('[data-testid="g7le-preview-frame"], [data-testid="g7le-preview-canvas"]', { timeout: 30_000 });

    // 격리 store 의 _global.currentUser 가 시드되지 않아야 함 (비로그인 분기)
    await page.waitForFunction(() => {
      const app = (window as any).__templateApp;
      return typeof app?.getGlobalState === 'function';
    }, { timeout: 15_000 });
    const hasCurrentUser = await page.evaluate(() => {
      const app = (window as any).__templateApp;
      return !!app?.getGlobalState?.().currentUser;
    });
    expect(hasCurrentUser, 'guest_only 레이아웃은 currentUser 미시드').toBe(false);

    // "이미 로그인되어 있습니다" 토스트가 화면에 노출되지 않아야 함
    const loginToast = page.getByText(/이미 로그인되어 있습니다|already logged in/i);
    await expect(loginToast).toHaveCount(0);
  });

  test('저장 success kind → SaveFeedbackBanner 표시 + history reset (결함 E + I 회귀 가드)', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    await page.goto('/admin/layout-editor/sirsoft-basic?route=%2F');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForFunction(
      () => !document.querySelector('[data-testid="g7le-toolbar-add-element"]')?.hasAttribute('disabled'),
      { timeout: 30_000 },
    );

    // Div 추가 (툴바 버튼은 chrome 로케일 무관하게 testid 로 조작)
    await page.getByTestId('g7le-toolbar-add-element').click();
    await page.waitForSelector('[data-testid="g7le-palette-item-Div"]', { timeout: 10_000 });
    await page.getByTestId('g7le-palette-item-Div').click();

    // Undo enabled 확인 (history push)
    const undoBtn = page.getByTestId('g7le-toolbar-undo');
    await expect(undoBtn).toBeEnabled();

    // 저장 클릭 + PUT 200 응답 대기
    const savePromise = page.waitForResponse(
      resp => resp.url().includes('/api/admin/templates/sirsoft-basic/layouts/') && resp.request().method() === 'PUT' && resp.status() === 200,
      { timeout: 15_000 },
    );
    await page.getByTestId('g7le-toolbar-save').click();
    await savePromise;

    // success banner 표시 (배너 텍스트는 chrome 로케일 의존이라 가시성만 검증)
    await expect(page.getByTestId('g7le-save-banner-success')).toBeVisible({ timeout: 5_000 });

    // history reset — Undo / Redo 양쪽 disabled
    await expect(undoBtn).toBeDisabled();
    await expect(page.getByTestId('g7le-toolbar-redo')).toBeDisabled();
  });
});
