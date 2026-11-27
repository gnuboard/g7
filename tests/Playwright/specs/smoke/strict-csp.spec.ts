/**
 * Smoke: strict CSP(`script-src 'self'`, unsafe-inline·unsafe-eval 없음)에서 셸이 동작한다 (공개 #158)
 *        + 엄격한 스타일 정책(`style-src-elem` / `style-src` + `style-src-attr 'none'`)에서도 화면이 그대로다 (공개 #163)
 *
 * 운영자가 웹서버에 `Content-Security-Policy: script-src 'self'` 를 걸면 인라인 `<script>` 와
 * on* 속성이 실행되지 않는다. 종전에는 G7Config 대입·부트스트랩·번들 `<script onload>` 가
 * 인라인이라 관리자·사용자 화면 전체가 백지였고 폴백 안내조차 뜨지 않았다.
 *
 * 스타일 정책을 함께 걸면 `<style>` 요소(기본·엄격)와 태그의 style 속성(엄격)이 적용되지 않는다.
 * 종전에는 지연 안내 숨김 규칙이 `<style>` 블록에 있어 정상 화면 아래에 「화면을 불러오지 못했습니다」가
 * 늘 보였고, 오류 화면은 스타일 없이(제목 16px) 그려졌다.
 *
 * 서버 응답을 바꾸지 않고 **문서 응답에만** CSP 헤더를 덧붙여 "운영자 웹서버 헤더 한 줄"을
 * 재현한다. 위반은 `securitypolicyviolation` 이벤트로 센다.
 *
 * 검증 축:
 *   (A) 관리자·사용자 진입 화면 렌더 + 위반 0
 *   (B) 컴포넌트 번들 상시 실패 → 폴백 안내 + 새로고침 버튼(리스너)으로 새 문서 로드
 *   (C) 셸 JS 자체 미도착 → 20초 뒤 CSS 지연 안내문(#g7-shell-stall) 표시
 *   (S) 스타일 정책 2강도(기본·엄격) × 화면(진입 3종 · 로그인 후 환경설정 · 링크 클릭 화면 이동 · 레이아웃 API 500 오류 화면)
 *       — 위반 0, 지연 안내 `display: none`, 오류 화면 제목 `24px`
 *       + 글쓰기 편집기(CKEditor 5)의 가운데 정렬이 편집 화면에 보인다(계산된 text-align center, 저장 형식 불변)
 *       + 태그 입력(react-select)의 칸이 flex, 목록이 칸 아래 떠 있는 상자, 화면 낭독용 안내 문구가 1px 클립으로 숨는다
 *         (emotion 규칙을 생성형 시트로 싣는다 — `templates/_bundled/sirsoft-admin_basic/src/utils/adoptedEmotionCache.ts`)
 *
 * 소유 매니페스트: tests/scenarios/strict-csp-compat.yaml (A·B·C), tests/scenarios/strict-style-csp-compat.yaml (S).
 * 조합·효과는 테스트별 마킹에 적는다.
 */
import { test, expect, type Page } from '@playwright/test';
import { adminToken } from '../../fixtures/admin-login';
import { authenticatePage } from '../../fixtures/auth';

/** 운영자 웹서버 상당 strict 스크립트 정책 (S9 — Monaco·이미지 압축 blob 워커 허용) */
const STRICT_POLICY = "script-src 'self' 'report-sample'; worker-src 'self' blob:; object-src 'none'; base-uri 'self'";

/**
 * 스타일 정책 2강도 — 코어 설정(환경설정 > 보안 > 스타일 정책)이 내는 지시어와 같은 모양이다.
 *
 * 허용 호스트는 `'self'` 뿐이다. 번들 템플릿(sirsoft-basic·sirsoft-admin_basic)의 externals 는 전부
 * 템플릿 자산 경로(같은 출처)로 실려 외부 CSS 호스트가 없다(template.json 과 셸 HTML 의 `<link>` 로 확인).
 * 외부 CSS 호스트를 쓰는 템플릿을 활성화한 사이트에서 돌리려면 여기에 그 호스트를 더해야 한다.
 */
const STYLE_LEVELS: ReadonlyArray<{ id: 'basic' | 'strict'; label: string; directives: string }> = [
  { id: 'basic', label: '기본', directives: "style-src-elem 'self'" },
  { id: 'strict', label: '엄격', directives: "style-src 'self'; style-src-attr 'none'" },
];

/**
 * 스크립트 strict 정책에 스타일 지시어를 이어 붙인 정책 문자열을 만든다.
 *
 * @param styleDirectives 스타일 지시어(강도별)
 * @return 문서 응답에 덧붙일 정책 문자열
 */
function withStylePolicy(styleDirectives: string): string {
  return `${STRICT_POLICY}; ${styleDirectives}`;
}

/**
 * 스타일 정책을 강제하면 깨지는 것으로 분류한 외부 라이브러리 화면.
 *
 * 라이브러리가 스스로 `<style>` 요소·style 속성을 넣는 화면이라 코어·번들 코드로는 위반을 없앨 수 없다
 * (수정 범위 밖으로 확정). 조용히 건너뛰지 않고 `test.fixme` 로 걸어 결과 보고서에 남긴다 —
 * 라이브러리 교체·설정으로 위반이 사라지면 이 목록에서 빼고 (S) 화면 목록으로 옮긴다.
 */
const EXTERNAL_LIBRARY_SCREENS: ReadonlyArray<{ name: string; reason: string }> = [
  // 아래 사유는 Chrome 156 실측값이다(환경설정에서 강도별로 강제한 뒤 화면을 열어 측정. CKEditor 는 2026-11-11, 나머지는 2026-11-10).
  {
    // 편집 화면의 서식(정렬·색·표·이미지 크기)은 고쳐서 아래 실제 테스트가 잠근다 — 남은 것은 색상 선택기뿐이다
    name: '편집기 색상 선택기 (CKEditor 5 — 글자색·배경색·표 색의 「색상 선택기」)',
    reason: '두 강도 모두 깨진다 — 임의 색을 끌어 고르는 판이 자기 안에 넣는 <style> 요소 2개가 막혀 접힌다(180×100 → 172×30, style-src-elem 2건). 정해진 색 타일과 16진수 입력으로는 색을 고를 수 있다. 엄격에서는 저장용 HTML 을 만들 때 나는 style 속성 위반이 편집 중 계속 보고돼(글자 10자에 약 400건, 화면에는 영향 없음) 위반 0건 단언을 걸 수 없다',
  },
  {
    name: '레이아웃 코드 편집기 (Monaco Editor)',
    reason: '두 강도 모두 깨진다 — Monaco Editor 의 <style> 요소 3개가 막혀 구문 색·배경 테마가 빠진다(style-src-elem 3건). 엄격은 style 속성 위반 127건이 더해진다',
  },
  {
    name: '주소 검색 (다음 우편번호 SDK)',
    reason: '화면은 정상이다(레이어 500×600 표시) — 엄격에서 우편번호 스크립트의 style 속성 위반 1건이 위반 로그에 남아 위반 0건 단언을 걸 수 없다',
  },
];

/** 코어/컴포넌트 번들이 끝내 부재할 때 사용자에게 보여야 할 폴백 안내 */
const FALLBACK_UI = /화면을 불러오지 못했습니다|Failed to load the page/;

/** 수집한 위반 1건 */
type Violation = { d: string; b: string; s: string; f: string };

/**
 * 문서 응답에 CSP 헤더를 덧붙이고 위반 수집기를 심는다.
 *
 * @param page Playwright page
 * @param policy 덧붙일 정책 문자열
 * @return {Promise<void>}
 */
async function injectCsp(page: Page, policy: string): Promise<void> {
  await page.route('**/*', async (route) => {
    if (route.request().resourceType() !== 'document') {
      return route.fallback();
    }
    const response = await route.fetch();
    await route.fulfill({
      response,
      headers: { ...response.headers(), 'content-security-policy': policy },
    });
  });

  await page.addInitScript(() => {
    (window as any).__cspv = [];
    // 편집기 편집 영역 안(문서에 붙은 요소)에서 난 위반 수 — 편집 화면 서식이 막혔는지 가르는 값
    (window as any).__cspEditable = 0;
    document.addEventListener('securitypolicyviolation', (e) => {
      const target = e.target as Element | null;
      if (target && target.nodeType === 1 && target.isConnected && target.closest('.ck-editor__editable')) {
        (window as any).__cspEditable += 1;
      }
      (window as any).__cspv.push({
        d: e.effectiveDirective,
        b: e.blockedURI,
        s: (e.sample || '').slice(0, 60),
        // 출처 파일은 경로만 남긴다(호스트는 실행 환경마다 달라 결과에 싣지 않는다)
        f: `${(e.sourceFile || '').replace(/^https?:\/\/[^/]+/, '')}:${e.lineNumber || 0}`,
      });
    });
  });
}

/**
 * 문서 응답에 strict 스크립트 정책 헤더를 덧붙이고 위반 수집기를 심는다.
 *
 * @param page Playwright page
 * @return {Promise<void>}
 */
async function injectStrictCsp(page: Page): Promise<void> {
  await injectCsp(page, STRICT_POLICY);
}

/**
 * 위반 목록을 읽는다.
 *
 * @param page Playwright page
 * @return 위반 목록
 */
async function violations(page: Page): Promise<Violation[]> {
  return page.evaluate(() => (window as any).__cspv ?? []);
}

/**
 * 셸 부트스트랩이 끝날 때까지 기다린다.
 *
 * @param page Playwright page
 * @return {Promise<void>}
 */
async function waitForShell(page: Page): Promise<void> {
  await page.waitForFunction(() => (window as any).__g7Bootstrap?.initialized === true, null, { timeout: 30_000 });
}

/**
 * 지연 안내문(#g7-shell-stall)의 계산된 display 값을 읽는다.
 *
 * @param page Playwright page
 * @return display 값. 요소가 없으면 `'(없음)'`
 */
async function stallDisplay(page: Page): Promise<string> {
  return page.evaluate(() => {
    const el = document.getElementById('g7-shell-stall');
    return el ? getComputedStyle(el).display : '(없음)';
  });
}

test.describe('strict CSP 셸 호환 (공개 #158)', () => {
  for (const url of ['/admin/login', '/', '/login']) {
    /**
     * @scenario surface=admin_shell, csp=webserver_strict, failure=none
     * @effects shell_html_has_no_inline_executable, shell_init_on_captured_load
     */
    test(`(A) ${url} 가 strict CSP 에서 렌더되고 위반이 없다`, async ({ page }) => {
      await injectStrictCsp(page);

      const response = await page.goto(url);
      expect(response?.headers()['content-security-policy']).toBe(STRICT_POLICY);

      await waitForShell(page);
      await expect(page.locator('#app input').nth(1)).toBeVisible({ timeout: 30_000 });

      const state = await page.evaluate(() => ({
        config: typeof (window as any).G7Config,
        shellOk: document.documentElement.classList.contains('g7-shell-ok'),
        inputs: document.querySelectorAll('#app input').length,
      }));

      expect(state.config).toBe('object');
      expect(state.shellOk).toBe(true);
      expect(state.inputs).toBeGreaterThanOrEqual(2);
      expect(await violations(page)).toEqual([]);
    });
  }

  /**
   * @scenario surface=bootstrap_fallback, csp=webserver_strict, failure=components_bundle_fail
   * @effects shell_retry_backoff_then_fallback
   */
  test('(B) 컴포넌트 번들 상시 실패 → 폴백 안내, 새로고침 버튼이 새 문서를 연다', async ({ page }) => {
    await injectStrictCsp(page);

    let aborted = 0;
    await page.route(/components\.iife(\.js)?(\?|$)|file=js%2Fcomponents\.iife\.js/, (route) => {
      aborted += 1;
      return route.abort('connectionreset');
    });

    await page.goto('/login');
    await expect(page.locator('#app [data-g7-bootstrap-fallback]')).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('#app')).toContainText(FALLBACK_UI);
    expect(aborted).toBeGreaterThanOrEqual(3);

    const reload = page.locator('#app [data-g7-bootstrap-reload]');
    await expect(reload).toBeVisible();
    expect(await reload.getAttribute('onclick')).toBeNull();

    const navigation = page.waitForEvent('framenavigated');
    await reload.click();
    await navigation;

    expect(await violations(page)).toEqual([]);
  });

  /**
   * @scenario surface=user_shell, csp=webserver_strict, failure=shell_js_fail
   * @effects shell_stall_notice_rendered
   */
  test('(C) 셸 JS 를 못 받으면 20초 뒤 지연 안내문이 보인다', async ({ page }) => {
    test.setTimeout(60_000);
    await injectStrictCsp(page);
    await page.route(/g7-shell-boot\.js/, (route) => route.abort('connectionreset'));

    await page.goto('/login');

    const stall = page.locator('#g7-shell-stall');
    await expect(stall).toBeHidden();
    await expect(stall).toBeVisible({ timeout: 30_000 });
    await expect(stall).toContainText(FALLBACK_UI);
  });
});

test.describe('엄격한 스타일 CSP 호환 (공개 #163)', () => {
  for (const level of STYLE_LEVELS) {
    const policy = withStylePolicy(level.directives);

    for (const url of ['/admin/login', '/', '/login']) {
      // @scenario case=style_csp_basic_enforced
      // @effects style_csp_screens_render_without_violation, shell_stall_notice_hidden_by_core_css
      // @scenario case=style_csp_strict_enforced
      // @effects style_csp_screens_render_without_violation, shell_stall_notice_hidden_by_core_css
      test(`(S) [스타일 ${level.label}] ${url} 가 렌더되고 위반이 없으며 지연 안내가 숨는다`, async ({ page }) => {
        await injectCsp(page, policy);

        const response = await page.goto(url);
        expect(response?.headers()['content-security-policy']).toBe(policy);

        await waitForShell(page);
        await expect(page.locator('#app input').nth(1)).toBeVisible({ timeout: 30_000 });

        expect(await stallDisplay(page)).toBe('none');
        expect(await violations(page)).toEqual([]);
      });
    }

    // @scenario case=style_csp_basic_enforced
    // @effects style_csp_screens_render_without_violation, shell_stall_notice_hidden_by_core_css
    // @scenario case=style_csp_strict_enforced
    // @effects style_csp_screens_render_without_violation, shell_stall_notice_hidden_by_core_css
    test(`(S) [스타일 ${level.label}] 로그인 후 /admin/settings 가 렌더되고 위반이 없다`, async ({ page, request }) => {
      const token = await adminToken(request, 'core.settings.read', 'core.settings.update');
      await authenticatePage(page, token);
      await injectCsp(page, policy);

      const response = await page.goto('/admin/settings');
      expect(response?.headers()['content-security-policy']).toBe(policy);

      await waitForShell(page);
      // 환경설정 첫 탭(일반)의 입력이 그려질 때까지 — 로그인으로 튕기면 주소 단언에서 걸린다
      await expect(page.locator('#app input:visible').first()).toBeVisible({ timeout: 30_000 });
      expect(new URL(page.url()).pathname, '환경설정이 아니라 로그인 화면으로 이동했다').toBe('/admin/settings');

      expect(await stallDisplay(page)).toBe('none');
      expect(await violations(page)).toEqual([]);
    });

    // @scenario case=style_csp_basic_enforced
    // @effects style_csp_spa_navigation_without_violation
    // @scenario case=style_csp_strict_enforced
    // @effects style_csp_spa_navigation_without_violation
    test(`(S) [스타일 ${level.label}] 링크 클릭 화면 이동(/login → /register) 뒤에도 위반이 없다`, async ({ page }) => {
      await injectCsp(page, policy);

      await page.goto('/login');
      await waitForShell(page);

      // 같은 주소의 링크가 접힌 모바일 메뉴에도 있어 첫 요소는 숨겨져 있다 — 보이는 것만 고른다
      const registerLink = page.locator('#app a[href="/register"]:visible').first();
      await expect(registerLink).toBeVisible({ timeout: 30_000 });

      // 같은 문서 안 이동인지 판정할 표식 — 새 문서가 열리면 사라진다
      await page.evaluate(() => {
        (window as any).__g7SameDocument = true;
      });
      await registerLink.click();
      await page.waitForURL(/\/register(\?|$)/, { timeout: 30_000 });
      await expect(page.locator('#app input').nth(1)).toBeVisible({ timeout: 30_000 });

      expect(await page.evaluate(() => (window as any).__g7SameDocument === true), '새 문서가 열렸다(화면 이동이 아니다)').toBe(true);
      expect(await stallDisplay(page)).toBe('none');
      expect(await violations(page)).toEqual([]);
    });

    // @scenario case=style_csp_basic_enforced
    // @effects error_screen_styled_under_style_csp
    // @scenario case=style_csp_strict_enforced
    // @effects error_screen_styled_under_style_csp
    test(`(S) [스타일 ${level.label}] 레이아웃 API 500 → 오류 화면 제목이 24px 로 그려지고 위반이 없다`, async ({ page }) => {
      await injectCsp(page, policy);
      await page.route('**/api/layouts/**', (route) =>
        route.fulfill({
          status: 500,
          contentType: 'application/json',
          body: JSON.stringify({ success: false, message: 'Server Error' }),
        }),
      );

      await page.goto('/login');

      const title = page.locator('#app .g7-error__title');
      await expect(title).toBeVisible({ timeout: 30_000 });
      expect(await title.evaluate((el) => getComputedStyle(el).fontSize)).toBe('24px');

      expect(await violations(page)).toEqual([]);
    });

    // @scenario case=style_csp_basic_enforced
    // @effects editor_formatting_visible_under_style_csp
    // @scenario case=style_csp_strict_enforced
    // @effects editor_formatting_visible_under_style_csp
    test(`(S) [스타일 ${level.label}] 글쓰기 편집기(CKEditor 5)에서 가운데 정렬이 편집 화면에 보인다`, async ({ page, request }) => {
      // 종전에는 엄격에서 편집 화면 문단의 style 속성이 막혀 정렬을 눌러도 왼쪽 그대로였다(저장값만 맞았다).
      // 편집기 플러그인이 편집 화면의 style 을 CSSOM 으로 입힌다 — 저장되는 HTML 형식은 그대로여야 한다.
      const token = await adminToken(request, 'sirsoft-page.pages.read', 'sirsoft-page.pages.create');
      await authenticatePage(page, token);
      await injectCsp(page, policy);

      const response = await page.goto('/admin/pages/create');
      expect(response?.headers()['content-security-policy']).toBe(policy);
      await waitForShell(page);

      const editable = page.locator('.ck-editor__editable:visible').first();
      await expect(editable, 'CKEditor 편집기가 뜨지 않았다(편집기 플러그인·페이지 모듈이 켜져 있어야 한다)').toBeVisible({ timeout: 30_000 });

      await editable.click();
      await page.keyboard.type('CSP alignment probe');

      // 텍스트 정렬 드롭다운 → 가운데. 아이콘 단추라 이름은 로케일을 따른다(ko·en)
      const toolbar = page.locator('.ck-editor:has(.ck-editor__editable:visible) .ck-toolbar').first();
      await toolbar.locator('.ck-dropdown__button[data-cke-tooltip-text*="정렬"], .ck-dropdown__button[data-cke-tooltip-text*="alignment" i]').first().click();
      await page.locator('.ck-dropdown__panel-visible .ck-button[data-cke-tooltip-text*="가운데"], .ck-dropdown__panel-visible .ck-button[data-cke-tooltip-text*="center" i]').first().click();

      const paragraph = editable.locator('p').first();
      await expect.poll(() => paragraph.evaluate((el) => getComputedStyle(el).textAlign), { timeout: 10_000 }).toBe('center');
      // 속성 문자열만 있고 선언이 0개면 정책에 막힌 것이다
      expect(await paragraph.evaluate((el) => (el as HTMLElement).style.length)).toBeGreaterThan(0);

      // 저장되는 HTML 은 CKEditor 원래 형식 그대로다(공백 없는 선언)
      const data = await editable.evaluate((el) => (el as any).ckeditorInstance.getData());
      expect(data).toBe('<p style="text-align:center;">CSP alignment probe</p>');

      // 편집 화면(문서에 붙은 편집 영역 안 요소)에서 난 위반은 없어야 한다 — 저장용 HTML 생성 때 나는 위반은 재지 않는다
      expect(await page.evaluate(() => (window as any).__cspEditable ?? 0)).toBe(0);
    });

    // @scenario case=style_csp_basic_enforced
    // @effects tag_input_layout_under_style_csp
    // @scenario case=style_csp_strict_enforced
    // @effects tag_input_layout_under_style_csp
    test(`(S) [스타일 ${level.label}] 태그 입력(react-select) 목록이 칸 아래 떠 있고 안내 문구가 숨는다 — 환경설정 보안 탭 허용 호스트 칸`, async ({ page, request }) => {
      // 종전에는 react-select(emotion)가 만드는 <style data-emotion> 이 막혀 목록이 문서 흐름에 펼쳐지고(1438×1606)
      // 화면 낭독용 안내 문구가 칸 위에 그대로 보였다. 정책을 되돌리는 이 칸 자체가 깨졌다.
      // 환경설정은 칸이 그려진 뒤에도 데이터 소스(일정·신뢰 키·개인정보 처리 등)를 받는 동안 받는 중 덮개
      // (`#g7-skeleton-overlay`)가 클릭을 받는다. 이 화면은 첫 진입에 API 16개를 받아, 서버 부하 때는 정책과 무관하게(끔에서도)
      // 칸이 뜨기까지 30초 가까이, 덮개가 걷히기까지 다시 10초 남짓 걸려 기본 30초 제한을 넘긴다(FX-C 단독 재현).
      test.setTimeout(150_000);
      const token = await adminToken(request, 'core.settings.read', 'core.settings.update');
      await authenticatePage(page, token);
      await injectCsp(page, policy);

      const response = await page.goto('/admin/settings?tab=security');
      expect(response?.headers()['content-security-policy']).toBe(policy);
      await waitForShell(page);

      const field = page.locator('#csp_extra_script_hosts');
      const combobox = field.getByRole('combobox');
      await expect(combobox, '보안 탭의 추가 스크립트 허용 호스트 칸이 뜨지 않았다').toBeVisible({ timeout: 60_000 });
      await expect(page.locator('#g7-skeleton-overlay'), '받는 중 덮개가 걷히지 않았다(데이터 소스 대기)').toHaveCount(0, { timeout: 60_000 });
      // 칸은 flex — 규칙이 막히면 block 으로 내려간다
      expect(await field.locator('.tag-input__control').evaluate((el) => getComputedStyle(el).display)).toBe('flex');

      await combobox.click();
      await page.keyboard.type('cdn.example.com');
      const menu = page.locator('.tag-input__menu');
      await expect(menu).toBeVisible({ timeout: 10_000 });
      expect(await menu.evaluate((el) => getComputedStyle(el).position)).toBe('absolute');

      // 화면 낭독용 안내 문구(라이브 영역)는 1px 클립으로 숨는다
      const live = await field.locator('[id$="-live-region"]').evaluate((el) => {
        const rect = el.getBoundingClientRect();

        return { position: getComputedStyle(el).position, width: rect.width, height: rect.height };
      });
      expect(live.position).toBe('absolute');
      expect(live.width).toBeLessThanOrEqual(1);
      expect(live.height).toBeLessThanOrEqual(1);

      // 규칙은 생성형 시트로 실린다 — 정책에 막히는 <style data-emotion> 요소가 없다
      expect(await page.locator('style[data-emotion]').count()).toBe(0);
      expect(await violations(page)).toEqual([]);
      await page.keyboard.press('Escape');
    });

    for (const external of EXTERNAL_LIBRARY_SCREENS) {
      // 외부 라이브러리 화면 — 위반이 라이브러리에서 나와 고칠 수 없다. 사유는 EXTERNAL_LIBRARY_SCREENS 에 적는다.
      test.fixme(`(S) [스타일 ${level.label}] ${external.name} — 외부 라이브러리 화면(${external.reason})`, async () => {});
    }
  }
});
