/**
 * Layout Editor — 맞춤 줌: 창 1280~1440 에서 미리보기 때문에 문서 가로 스크롤이 생기지 않는다.
 *
 * 회귀 원인(#869 P3): 창 1280 에서 편집기 문서 폭이 1608px(라우트 트리 280 + 캔버스 여백 48 + 데스크톱
 * 미리보기 1280)이었다. 배율은 줌 100% 면 1 로 고정됐고, 줌을 내려도 frame 래퍼가 `transform: scale`
 * 로만 줄어 레이아웃 폭이 1280 으로 남았다. 오버레이 레이어도 transform 안에 있어 축소 시 선택 박스가
 * 노드와 어긋났다.
 *
 * 단위 시험(useDevicePreview.fitZoom, PreviewCanvas.fitZoom)은 계산식과 DOM 구조까지만 잠근다.
 * 실제 문서 폭(scrollWidth)과 화면상 박스 정합은 레이아웃 엔진이 있는 브라우저에서만 증명된다.
 *
 * 셸 최소 너비는 1000 이고 툴바는 셸보다 넓으면 두 줄로 감긴다(2026-11-08 설계 확정). 그래서 창 1024~1920 에서
 * 문서 가로 스크롤은 0 이다. 「실제 크기」를 켜면 배율 1 이고 넘친 폭은 캔버스 안에서만 가로로 스크롤한다.
 *
 * 축 요약(마커 아님 — 평문): editor_window_width, preview_device, overlay_alignment.
 * 효과 요약(마커 아님 — 평문): editor_document_no_preview_horizontal_scroll, editor_overlay_selection_box_aligned_when_scaled.
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';

async function gotoEditor(page: Page, route = '%2F'): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto(`/admin/layout-editor/sirsoft-basic?route=${route}`);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });
  await page.waitForFunction(() => document.querySelectorAll('[data-editor-path]').length > 0, {
    timeout: 20_000,
  });
  await page.waitForTimeout(300); // 측정(ResizeObserver → rAF) 뒤 레이아웃 확정
}

async function measureDocument(page: Page): Promise<{
  scrollWidth: number;
  clientWidth: number;
  shellMinWidth: number;
  stageWidth: number;
  canvasInner: number;
}> {
  return page.evaluate(() => {
    const canvas = document.querySelector('[data-testid="g7le-preview-canvas"]') as HTMLElement;
    const stage = document.querySelector('[data-testid="g7le-preview-stage"]') as HTMLElement;
    const shell = document.querySelector('[data-testid="g7le-chrome"]') as HTMLElement;
    const cs = getComputedStyle(canvas);
    return {
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
      shellMinWidth: parseFloat(getComputedStyle(shell).minWidth),
      stageWidth: stage.getBoundingClientRect().width,
      canvasInner: canvas.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight),
    };
  });
}

test.describe('레이아웃 편집기 — 맞춤 줌(문서 가로 스크롤 제거)', () => {
  for (const width of [1024, 1280, 1366, 1440]) {
    /** @effects editor_document_no_preview_horizontal_scroll */
    test(`창 ${width}: 데스크톱 미리보기가 캔버스에 맞춰 줄고 문서 폭은 셸 폭을 넘지 않는다`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await gotoEditor(page);
      const m = await measureDocument(page);

      // 미리보기(무대)는 캔버스 안쪽 폭 안에 들어간다.
      expect(m.stageWidth).toBeLessThanOrEqual(m.canvasInner + 1);
      // 문서 폭 초과분은 셸 최소 너비 몫(창 안쪽 폭이 셸 최소 너비보다 좁은 만큼)뿐이다.
      const shellSurplus = Math.max(0, m.shellMinWidth - m.clientWidth);
      expect(m.scrollWidth - m.clientWidth).toBeLessThanOrEqual(shellSurplus + 1);
      if (m.clientWidth >= m.shellMinWidth) {
        expect(m.scrollWidth).toBeLessThanOrEqual(m.clientWidth);
      }
    });
  }

  /** @effects editor_document_no_preview_horizontal_scroll */
  test('창 1920: 셸 최소 너비 이상이면 문서 가로 스크롤 0, 툴바가 셸 밖으로 넘치지 않는다', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 900 });
    await gotoEditor(page);
    const m = await measureDocument(page);
    expect(m.scrollWidth).toBeLessThanOrEqual(m.clientWidth);
    const overflow = await page.getByTestId('g7le-toolbar').evaluate((el) => el.scrollWidth - el.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
  });

  /** @effects editor_actual_size_toggle_shows_one_to_one */
  test('실제 크기를 켜면 배율 1, 문서 가로 스크롤 없이 캔버스 안에서만 오른쪽 끝까지 스크롤된다', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await gotoEditor(page);
    await page.getByTestId('g7le-zoom-actual-size').click();
    await page.waitForTimeout(300);
    const transform = await page.getByTestId('g7le-preview-frame-wrapper').evaluate((el) => (el as HTMLElement).style.transform);
    expect(transform).toBe('scale(1)');
    const doc = await measureDocument(page);
    expect(doc.scrollWidth).toBeLessThanOrEqual(doc.clientWidth);
    const reach = await page.getByTestId('g7le-preview-canvas').evaluate((canvas) => {
      canvas.scrollLeft = canvas.scrollWidth;
      const frame = canvas.querySelector('[data-testid="g7le-preview-frame"]') as HTMLElement;
      const c = canvas.getBoundingClientRect();
      const f = frame.getBoundingClientRect();
      return { scrollable: canvas.scrollWidth > canvas.clientWidth, frameRight: f.right, canvasRight: c.right };
    });
    expect(reach.scrollable).toBe(true);
    // 오른쪽 끝까지 스크롤하면 frame 오른쪽 끝이 캔버스 안에 들어온다(시작 정렬이라 왼쪽도 닿는다).
    expect(reach.frameRight).toBeLessThanOrEqual(reach.canvasRight + 1);
    await page.getByTestId('g7le-zoom-actual-size').click();
  });

  /** @effects editor_toolbar_wraps_lines_without_squish */
  test('창 1024: 툴바가 두 줄로 감기고 항목은 창 1920 과 같은 크기다(찌그러짐 없음)', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 900 });
    await gotoEditor(page);
    // 가운데 빈칸(flex-grow 1 spacer)은 줄 폭에 따라 늘어나므로 비교에서 뺀다 — 단추 폭·높이만 같으면 된다.
    const wide = await page.getByTestId('g7le-toolbar').evaluate((tb) =>
      Array.from(tb.children)
        .filter((c) => getComputedStyle(c).flexGrow === '0')
        .map((c) => c.getBoundingClientRect())
        .filter((r) => r.width > 0)
        .map((r) => `${Math.round(r.width)}x${Math.round(r.height)}`),
    );
    await page.setViewportSize({ width: 1024, height: 900 });
    await page.waitForTimeout(300);
    const narrow = await page.getByTestId('g7le-toolbar').evaluate((tb) => {
      const rects = Array.from(tb.children)
        .filter((c) => getComputedStyle(c).flexGrow === '0')
        .map((c) => c.getBoundingClientRect())
        .filter((r) => r.width > 0);
      return {
        sizes: rects.map((r) => `${Math.round(r.width)}x${Math.round(r.height)}`),
        rows: new Set(rects.map((r) => Math.round(r.top))).size,
      };
    });
    expect(narrow.sizes).toEqual(wide);
    expect(narrow.rows).toBeGreaterThanOrEqual(2);
    const m = await measureDocument(page);
    expect(m.scrollWidth).toBeLessThanOrEqual(m.clientWidth);
  });

  /**
   * 「두 줄로 감기」(설계 확정 2026-11-08)의 「줄」은 툴바(`g7le-toolbar`) 자체의 flex 줄이다. 기기 줄
   * (`g7le-chrome-device-toolbar`)은 감기 전부터 있던 별도 줄이라 세지 않는다. 줄은 세로로 겹치는 항목을
   * 한 줄로 묶어 센다 — 항목 높이가 달라(`align-items:center`) top 값만 세면 한 줄이 둘로 세어진다.
   */
  for (const locale of ['ko', 'en', 'ja'] as const) {
    /** @effects editor_toolbar_wraps_lines_without_squish */
    test(`창 1024, 계정 언어 ${locale}: 툴바 자체는 두 줄을 넘지 않는다`, async ({ page }) => {
      // 계정 언어 바꾸기 + 편집기 적재(자산 IIFE 로드가 무겁다) + 되돌리기 — 기본 30초로는 모자란다.
      test.setTimeout(120_000);
      const token = issueToken('core.templates.layouts.edit');
      const setLanguage = (language: string) =>
        page.request.patch('/api/admin/users/me/language', {
          headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
          data: { language },
        });
      expect((await setLanguage(locale)).ok()).toBe(true);
      try {
        await authenticatePage(page, token);
        await page.setViewportSize({ width: 1024, height: 900 });
        await page.goto('/admin/layout-editor/sirsoft-basic?route=%2F');
        // 첫 시험은 편집 대상 템플릿 자산을 처음 받아 30초를 넘기기도 한다(실측 2026-11-08).
        await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 60_000 });
        await page.waitForTimeout(500);
        const lang = await page.evaluate(() => document.documentElement.lang);
        expect(lang).toBe(locale);
        const lines = await page.getByTestId('g7le-toolbar').evaluate((tb) => {
          const rects = Array.from(tb.children)
            .filter((c) => getComputedStyle(c).flexGrow === '0')
            .map((c) => c.getBoundingClientRect())
            .filter((r) => r.width > 0 && r.height > 0)
            .sort((a, b) => a.top - b.top);
          let count = 0;
          let lineBottom = -Infinity;
          for (const r of rects) {
            if (r.top >= lineBottom - 1) {
              count += 1;
              lineBottom = r.bottom;
            } else {
              lineBottom = Math.max(lineBottom, r.bottom);
            }
          }
          return count;
        });
        expect(lines).toBeLessThanOrEqual(2);
        const m = await measureDocument(page);
        expect(m.scrollWidth).toBeLessThanOrEqual(m.clientWidth);
      } finally {
        await setLanguage('ko');
      }
    });
  }

  for (const mode of ['fit', 'zoom70', 'actual'] as const) {
    /** @effects editor_insertion_affordances_stay_inside_canvas */
    test(`창 1280 ${mode}: 너비 전체 노드를 골라도 삽입 단추가 캔버스 안이고 문서 가로 넘침 0`, async ({ page }) => {
      await page.setViewportSize({ width: 1280, height: 900 });
      await gotoEditor(page);
      if (mode === 'zoom70') await page.getByTestId('g7le-zoom-slider').fill('0.7');
      if (mode === 'actual') await page.getByTestId('g7le-zoom-actual-size').click();
      await page.waitForTimeout(300);
      // 너비가 frame 과 같은 첫 노드(Header 등)를 고른다.
      const path = await page.evaluate(() => {
        const frame = document.querySelector('[data-testid="g7le-preview-frame"]') as HTMLElement;
        const fw = frame.getBoundingClientRect().width;
        const nodes = Array.from(frame.querySelectorAll<HTMLElement>('[data-editor-path]'));
        const target = nodes.find((n) => {
          const r = n.getBoundingClientRect();
          return r.height > 20 && Math.abs(r.width - fw) < 2;
        });
        return target?.getAttribute('data-editor-path') ?? null;
      });
      expect(path).not.toBeNull();
      await page.evaluate((p) => {
        const el = document.querySelector(`[data-editor-path="${p}"]`) as HTMLElement;
        const r = el.getBoundingClientRect();
        for (const type of ['pointerdown', 'pointerup', 'click']) {
          el.dispatchEvent(new MouseEvent(type, { bubbles: true, clientX: r.left + 10, clientY: r.top + 10 }));
        }
      }, path);
      await page.waitForSelector('[data-testid="g7le-insertion-affordances"]', { timeout: 5_000 });
      const out = await page.evaluate(() => {
        const canvas = document.querySelector('[data-testid="g7le-preview-canvas"]') as HTMLElement;
        const c = canvas.getBoundingClientRect();
        const scroller = getComputedStyle(canvas).overflowX === 'auto';
        const left = c.left - (scroller ? canvas.scrollLeft : 0);
        const right = left + (scroller ? canvas.scrollWidth : canvas.clientWidth);
        const outside = Array.from(document.querySelectorAll<HTMLElement>('[data-testid^="g7le-insertion-"]'))
          .filter((b) => b.tagName === 'BUTTON')
          .map((b) => b.getBoundingClientRect())
          .filter((r) => r.left < left - 1 || r.right > right + 1).length;
        return { outside, overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth };
      });
      expect(out.outside).toBe(0);
      expect(out.overflow).toBeLessThanOrEqual(0);
    });
  }

  /** @effects editor_overlay_selection_box_aligned_when_scaled */
  test('축소 상태에서 선택 박스가 노드의 화면 사각형과 맞는다', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await gotoEditor(page);

    // 축소가 실제로 걸렸는지 먼저 본다(아니면 이 케이스는 정합을 증명하지 못한다).
    const scale = await page
      .getByTestId('g7le-preview-frame-wrapper')
      .evaluate((el) => {
        const m = /scale\(([\d.]+)\)/.exec((el as HTMLElement).style.transform);
        return m ? parseFloat(m[1]) : 1;
      });
    expect(scale).toBeLessThan(1);

    const path = await page.evaluate(() => {
      const nodes = Array.from(document.querySelectorAll<HTMLElement>('[data-testid="g7le-preview-frame"] [data-editor-path]'));
      const target = nodes.find((n) => {
        const r = n.getBoundingClientRect();
        return r.width > 60 && r.height > 30;
      });
      return target?.getAttribute('data-editor-path') ?? null;
    });
    expect(path).not.toBeNull();

    await page.evaluate((p) => {
      const el = document.querySelector(`[data-editor-path="${p}"]`) as HTMLElement;
      el.scrollIntoView({ block: 'center' });
      const r = el.getBoundingClientRect();
      const cx = r.left + Math.min(r.width / 2, 10);
      const cy = r.top + Math.min(r.height / 2, 10);
      for (const type of ['pointerover', 'pointermove', 'pointerdown', 'pointerup', 'click']) {
        el.dispatchEvent(new MouseEvent(type, { bubbles: true, clientX: cx, clientY: cy }));
      }
    }, path);
    await page.waitForSelector('[data-testid="g7le-overlay-selected"]', { timeout: 5_000 });

    const boxes = await page.evaluate((p) => {
      const el = document.querySelector(`[data-editor-path="${p}"]`) as HTMLElement;
      const sel = document.querySelector('[data-testid="g7le-overlay-selected"]') as HTMLElement;
      const a = el.getBoundingClientRect();
      const b = sel.getBoundingClientRect();
      return { node: [a.left, a.top, a.width, a.height], box: [b.left, b.top, b.width, b.height] };
    }, path);
    // 선택 박스 테두리 두께만큼 여유(2px). 종전에는 배율을 두 번 맞아 크기가 scale² 로 어긋났다.
    for (let i = 0; i < 4; i++) {
      expect(Math.abs(boxes.box[i] - boxes.node[i])).toBeLessThanOrEqual(2);
    }
  });
});
