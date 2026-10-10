/**
 * Layout Editor — 미리보기 렌더 환경 격리 (공개 이슈 #141)
 *
 * 미리보기는 iframe 이 아니라 관리자 문서 안의 div 라서, CSS 폭 `@media`·`vw` 와 셀 렌더 함수의
 * `responsive` 분기가 브라우저 창 폭을 따랐다. 편집기는 생존 동안 폭 조건을 미리보기 프레임 컨테이너
 * 기준으로 나누고(언마운트 때 원복), 셀·슬롯은 미리보기 렌더 환경(기기 폭·콘텐츠 언어)을 따른다.
 *
 * 브라우저만 판정할 수 있는 것(컨테이너 쿼리·실제 캐스케이드·실제 Monaco 이전의 CSS)을 실제 Chromium 에서 잰다.
 */
import type { Page } from '@playwright/test';
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const EDITOR = '/admin/layout-editor/sirsoft-admin_basic';
const GRID = '.g7le-preview-frame .grid.md\\:grid-cols-2.lg\\:grid-cols-4';
const FLEX = '.g7le-preview-frame .flex-col.md\\:flex-row';

/**
 * 편집기를 라우트로 연다.
 *
 * @param page 페이지
 * @param route 라우트 패턴(예: `* /admin/dashboard` 의 공백 없는 형태)
 * @param ready 준비 완료로 볼 선택자
 */
async function openEditor(page: Page, route: string, ready = '.g7le-preview-frame [data-editor-path]'): Promise<void> {
  await page.goto(`${EDITOR}?route=${encodeURIComponent(route)}`);
  // 첫 일치 요소가 크기 0 인 빈 앵커(확장 주입 자리)일 수 있어 「보임」이 아니라 「붙음」을 기다린다
  await page.waitForSelector(ready, { state: 'attached', timeout: 45_000 });
  await page.waitForTimeout(800);
}

/** 기기 토글 클릭(합성 — 캔버스가 포인터를 가로채는 경우 대비) */
async function clickDevice(page: Page, device: string): Promise<void> {
  await page.evaluate((d) => {
    document
      .querySelector(`[data-testid="g7le-device-${d}"]`)
      ?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
  }, device);
  await page.waitForTimeout(700);
}

/** 사용자 지정 폭 입력 */
async function setCustomWidth(page: Page, width: number): Promise<void> {
  await page.evaluate((w) => {
    const input = document.querySelector('[data-testid="g7le-custom-width-input"]') as HTMLInputElement;
    const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!;
    setter.call(input, String(w));
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }, width);
  await page.waitForTimeout(800);
}

/** 대시보드 열 수·방향·프레임 내용 폭 */
async function dashboardShape(page: Page): Promise<{ frame: number; cols: number; dir: string }> {
  return page.evaluate(
    ([grid, flex]) => {
      const frame = document.querySelector('.g7le-preview-frame') as HTMLElement;
      return {
        frame: frame.clientWidth,
        cols: getComputedStyle(document.querySelector(grid)!).gridTemplateColumns.split(' ').length,
        dir: getComputedStyle(document.querySelector(flex)!).flexDirection,
      };
    },
    [GRID, FLEX],
  );
}

test.describe('@layout-editor 미리보기 렌더 환경 격리 (#141)', () => {
  // 편집기 첫 진입은 편집 대상 템플릿 자산·확장 스펙 20여 건을 받는다 — 기본 30초로는 병렬 실행 때 모자란다
  test.describe.configure({ timeout: 120_000 });

  test.beforeEach(async ({ page }) => {
    await authenticatePage(page, issueToken('core.templates.layouts.edit'));
  });

  /**
   * @scenario preview_device=desktop|tablet|mobile, window_width=wider_than_preview, style_source=css_media
   * @effects preview_browser_css_breakpoints_follow_preview_device
   */
  test('E1 창 1441 에서 기기 전환 시 대시보드 열 수·방향이 기기 폭을 따른다', async ({ page }) => {
    await page.setViewportSize({ width: 1441, height: 900 });
    await openEditor(page, '*/admin/dashboard', GRID);

    await clickDevice(page, 'mobile');
    expect(await dashboardShape(page)).toEqual({ frame: 390, cols: 1, dir: 'column' });
    await clickDevice(page, 'tablet');
    expect(await dashboardShape(page)).toEqual({ frame: 820, cols: 2, dir: 'row' });
    await clickDevice(page, 'desktop');
    expect(await dashboardShape(page)).toEqual({ frame: 1280, cols: 4, dir: 'row' });
  });

  /**
   * @scenario preview_device=custom, style_source=css_media|json_responsive
   * @effects preview_browser_css_and_json_responsive_agree_at_boundaries
   */
  test('E2 사용자 지정 폭 767/768/1023/1024 에서 CSS 판정과 JSON responsive 판정이 같다', async ({ page }) => {
    await page.setViewportSize({ width: 1441, height: 900 });
    await openEditor(page, '*/admin/dashboard', GRID);
    await clickDevice(page, 'custom');

    for (const width of [767, 768, 1023, 1024]) {
      await setCustomWidth(page, width);
      const shape = await dashboardShape(page);
      const envWidth = await page.evaluate(() => {
        for (const el of document.querySelectorAll('.g7le-preview-frame [data-editor-name]')) {
          const key = Object.keys(el).find((k) => k.startsWith('__reactFiber$'));
          let fiber = key ? (el as any)[key] : null;
          for (let d = 0; fiber && d < 5; d++, fiber = fiber.return) {
            const w = fiber.memoizedProps?.__componentContext?.renderEnvRef?.current?.responsiveWidth;
            if (typeof w === 'number') return w;
          }
        }
        return null;
      });
      const jsonPreset = envWidth! < 768 ? 'mobile' : envWidth! < 1024 ? 'tablet' : 'desktop';
      const cssPreset = shape.cols === 1 ? 'mobile' : shape.cols === 2 ? 'tablet' : 'desktop';

      expect(shape.frame).toBe(width);
      expect(envWidth).toBe(width);
      expect(cssPreset).toBe(jsonPreset);
    }
  });

  /**
   * @scenario preview_device=desktop, window_width=narrower_than_preview, style_source=css_media
   * @effects preview_browser_narrow_window_keeps_desktop_preview
   */
  test('E3 창 700 · 데스크톱 미리보기에서 손으로 쓴 max-width 규칙이 표를 숨기지 않는다', async ({ page }) => {
    await page.setViewportSize({ width: 700, height: 900 });
    await openEditor(page, '*/admin/ecommerce/personal-payment/:id');
    await clickDevice(page, 'desktop');

    const result = await page.evaluate(() => {
      const inside = [...document.querySelectorAll('.g7le-preview-frame .table-desktop')].map((e) => getComputedStyle(e).display);
      const probe = document.createElement('div');
      probe.className = 'table-desktop';
      document.body.appendChild(probe);
      const outside = getComputedStyle(probe).display;
      probe.remove();
      return {
        inside,
        outside,
        toolbar: getComputedStyle(document.querySelector('[data-testid="g7le-device-toolbar"]')!).display,
      };
    });

    expect(result.inside.length).toBeGreaterThan(0);
    expect(result.inside.every((d) => d !== 'none')).toBe(true);
    // 프레임 밖은 창 기준 그대로 — 편집기 chrome 도 흔들리지 않는다
    expect(result.outside).toBe('none');
    expect(result.toolbar).toBe('flex');
  });

  /**
   * @scenario preview_device=mobile, render_path=expand_content, window_width=wider_than_preview
   * @effects preview_browser_expand_row_follows_preview_width
   */
  test('E4 상품 확장 행이 모바일 미리보기에서 옵션 표를 그리지 않는다', async ({ page }) => {
    await page.setViewportSize({ width: 1441, height: 900 });
    await openEditor(page, '*/admin/ecommerce/products');

    // 편집 캔버스는 펼침 클릭을 막는다 — DataGrid 의 격리 컨텍스트로 펼침 상태만 세운다(저장과 무관)
    const expanded = await page.evaluate(() => {
      for (const el of document.querySelectorAll('.g7le-preview-frame *')) {
        const key = Object.keys(el).find((k) => k.startsWith('__reactFiber$'));
        let fiber = key ? (el as any)[key] : null;
        for (let d = 0; fiber && d < 6; d++, fiber = fiber.return) {
          const p = fiber.memoizedProps;
          if (p?.expandChildren && Array.isArray(p?.data) && p.__componentContext) {
            p.__componentContext.setState({ expandedRows: [p.data[0].id] });
            return true;
          }
        }
      }
      return false;
    });
    expect(expanded).toBe(true);

    const count = () => page.evaluate(() => document.querySelectorAll('.g7le-preview-frame .overflow-x-auto').length);
    await clickDevice(page, 'mobile');
    expect(await count()).toBe(0);
    await clickDevice(page, 'desktop');
    expect(await count()).toBeGreaterThan(0);
  });

  /**
   * @scenario env_value=translation, render_path=cell_plain
   * @effects preview_browser_cell_defer_translation_follows_content_locale
   */
  test('E5 콘텐츠 언어 en 에서 주문 목록 셀의 $t:defer: 문구가 영어다', async ({ page }) => {
    await page.setViewportSize({ width: 1441, height: 900 });
    await openEditor(page, '*/admin/ecommerce/orders', '.g7le-preview-frame table tbody td');

    const cellText = () =>
      page.evaluate(() => [...document.querySelectorAll('.g7le-preview-frame table tbody td')].map((t) => t.textContent ?? '').join('|'));

    expect(await cellText()).toContain('배송비');

    await page.locator('[data-testid="g7le-locale-en"]').click();
    await expect(page.locator('[data-testid="g7le-locale-en"]')).toHaveAttribute('aria-pressed', 'true');
    await page.waitForSelector('.g7le-preview-frame table tbody td', { timeout: 30_000 });
    await expect.poll(cellText, { timeout: 15_000 }).toContain('Ship.');

    const text = await cellText();
    expect(text).not.toContain('배송비');
    expect(text).not.toMatch(/외 \d+건/);
  });

  /**
   * @scenario style_source=css_media|css_vw, window_width=wider_than_preview
   * @effects preview_browser_runtime_style_isolated
   */
  test('E6 실행 중 주입한 <style> 도 미리보기 안에서는 기기 폭으로 판정된다', async ({ page }) => {
    await page.setViewportSize({ width: 1441, height: 900 });
    await openEditor(page, '*/admin/dashboard', GRID);

    await page.evaluate(() => {
      const style = document.createElement('style');
      style.id = 'e2e-probe-style';
      style.textContent = '@media (min-width:768px){.e2e-probe{color:rgb(1,2,3)}} .e2e-vw{width:50vw}';
      document.head.appendChild(style);
      const inner = document.createElement('div');
      inner.id = 'e2e-inner';
      inner.className = 'e2e-probe e2e-vw';
      document.querySelector('.g7le-preview-frame')!.appendChild(inner);
      const outer = document.createElement('div');
      outer.id = 'e2e-outer';
      outer.className = 'e2e-probe e2e-vw';
      document.body.appendChild(outer);
    });

    const read = () =>
      page.evaluate(() => {
        const inner = document.getElementById('e2e-inner')!;
        const outer = document.getElementById('e2e-outer')!;
        return {
          innerColor: getComputedStyle(inner).color,
          innerWidth: getComputedStyle(inner).width,
          outerColor: getComputedStyle(outer).color,
        };
      });

    await clickDevice(page, 'mobile');
    const mobile = await read();
    expect(mobile.innerColor).not.toBe('rgb(1, 2, 3)');
    expect(mobile.innerWidth).toBe('195px');
    expect(mobile.outerColor).toBe('rgb(1, 2, 3)');

    await clickDevice(page, 'desktop');
    const desktop = await read();
    expect(desktop.innerColor).toBe('rgb(1, 2, 3)');
    expect(desktop.innerWidth).toBe('640px');
  });

  /**
   * @scenario window_width=narrower_than_preview, style_source=css_media
   * @effects preview_browser_no_isolation_trace_after_leaving_editor
   */
  test('E7 편집기를 떠나면 격리 흔적이 없고 편집기 밖은 창 기준 그대로다', async ({ page }) => {
    await page.setViewportSize({ width: 1441, height: 900 });
    await openEditor(page, '*/admin/dashboard', GRID);
    await page.locator('[data-testid="g7le-toolbar-exit"]').click();
    await page.waitForURL((url) => !url.pathname.includes('layout-editor'), { timeout: 30_000 });

    await page.setViewportSize({ width: 700, height: 900 });
    await page.goto('/admin/dashboard');
    await page.waitForSelector('.grid.md\\:grid-cols-2.lg\\:grid-cols-4', { timeout: 30_000 });

    const result = await page.evaluate(() => {
      let markerRules = 0;
      const walk = (rules: CSSRuleList) => {
        for (const rule of Array.from(rules)) {
          if (rule.cssText.includes('g7le-preview')) markerRules++;
          if ((rule as CSSGroupingRule).cssRules) walk((rule as CSSGroupingRule).cssRules);
        }
      };
      for (const sheet of Array.from(document.styleSheets)) {
        try {
          walk(sheet.cssRules);
        } catch {
          // 교차 출처 시트
        }
      }
      return {
        markerRules,
        styleTags: document.querySelectorAll('style[data-g7le-media-isolation]').length,
        editorMarkers: document.querySelectorAll('[data-editor-path],[data-editor-item-path]').length,
        cols: getComputedStyle(document.querySelector('.grid.md\\:grid-cols-2.lg\\:grid-cols-4')!).gridTemplateColumns.split(' ').length,
      };
    });

    expect(result).toEqual({ markerRules: 0, styleTags: 0, editorMarkers: 0, cols: 1 });
  });

  /**
   * @scenario style_source=css_media|css_vw
   * @effects preview_browser_isolation_metrics_within_budget
   */
  test('E8 번들 CSS 에서 변환 불가 0 — 첫 처리 시간을 기록한다', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 1441, height: 900 });
    await openEditor(page, '*/admin/dashboard', GRID);

    const metrics = await page.evaluate(() => (window as any).__g7lePreviewMediaIsolation);
    testInfo.annotations.push({ type: 'g7le-media-isolation', description: JSON.stringify(metrics) });

    expect(metrics.unsupported).toBe(0);
    expect(metrics.converted).toBeGreaterThan(0);
    // 목표 50ms — 넘으면 수치를 이력에 남긴다(첫 실행 1회, 이후 재실행은 이미 처리한 규칙을 건너뛴다)
    expect(metrics.maxMs).toBeLessThan(500);
  });
});
