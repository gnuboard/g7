/**
 * 편집 미리보기의 구좌가 실제 사이트(사이트 홈·쇼핑몰 메인)의 히어로 구좌와 같은 폭·높이·글자 크기·줄바꿈인지.
 * @scenario-manifest tests/scenarios/preview-frame-and-conflict.yaml
 * 효과 요약(마커 아님, 평문): 미리보기 구좌가 실제 사이트 구좌와 같다. 효과 표식은 아래 시험에 있다.
 *
 * 미리보기는 기기 viewport(PC 1366·모바일 390)를 유지하고 그 안에 활성 사용자 템플릿의 본문 상자를 템플릿 CSS 로 그린다.
 * 값은 하드코딩하지 않고 같은 브라우저에서 실제 사이트를 재어 비교한다. 사양이 만든 배너는 fixture 가 지운다.
 */
import { test, expect, authenticatePage, createBanner, gotoUser, ADMIN_LIST, VIEWPORTS } from '../fixtures/hero-auth';
import type { Page } from '@playwright/test';

test.setTimeout(150_000);

/** 구좌·제목·설명의 실측값 */
type Slot = { width: number; height: number; titleFont: string; titleLines: number; descLines: number; full: boolean };

/** 문서 안 히어로 구좌를 잰다(사이트는 페이지 문서, 미리보기는 iframe 문서). 다른 배너가 함께 있으면 그 제목의 슬라이드만 본다. */
const measure = (root: Document, text: string): Slot => {
  const hs = root.querySelector('.hs-slider') as HTMLElement;
  const rect = hs.getBoundingClientRect();
  const title = [...hs.querySelectorAll<HTMLElement>('.hs-slider__title')].find((el) => el.textContent === text) as HTMLElement;
  const desc = (title.parentElement as HTMLElement).querySelector('.hs-slider__description') as HTMLElement;
  const view = root.defaultView!;
  const lines = (el: HTMLElement) => Math.round(el.getBoundingClientRect().height / parseFloat(view.getComputedStyle(el).lineHeight));
  return { width: rect.width, height: rect.height, titleFont: view.getComputedStyle(title).fontSize, titleLines: lines(title), descLines: lines(desc), full: hs.classList.contains('hs-slider--full') };
};

/** 실제 사이트에서 그 배너가 든 구좌를 잰다. */
async function siteSlot(page: Page, path: string, viewport: keyof typeof VIEWPORTS, title: string): Promise<Slot> {
  await page.setViewportSize(VIEWPORTS[viewport]);
  await gotoUser(page, path);
  await expect(page.locator('.hs-slider .hs-slider__title', { hasText: title }).first()).toBeAttached({ timeout: 30_000 });
  await page.waitForTimeout(500);
  return page.evaluate(`(${measure.toString()})(document, ${JSON.stringify(title)})`) as Promise<Slot>;
}

/** 편집 화면 미리보기(해당 기기)에서 구좌를 잰다. */
async function previewSlot(page: Page, id: number, device: 'pc' | 'mobile', title: string): Promise<Slot & { viewport: number }> {
  await page.setViewportSize(VIEWPORTS.desktop);
  await page.goto(`${ADMIN_LIST}/${id}/edit`);
  await expect(page.locator('#footer_save_button')).toBeEnabled({ timeout: 30_000 });
  await page.locator(`#preview_device_${device}`).click();
  await expect.poll(() => page.evaluate(() => document.querySelector('#hero_form_preview .hs-preview__viewport iframe')?.getAttribute('width')), { timeout: 30_000 }).toBe(device === 'pc' ? '1366' : '390');
  // 템플릿 CSS 링크는 위치 설정 응답(틀)이 와야 붙는다 — 서버가 느리면 5초(기본)를 넘긴다(실측 4회).
  await expect.poll(() => page.evaluate(() => (document.querySelector('#hero_form_preview .hs-preview__viewport iframe') as HTMLIFrameElement)?.contentDocument?.querySelectorAll('link[data-hs-template-css]').length ?? 0), { timeout: 30_000 }).toBeGreaterThan(0);
  // 템플릿 CSS 링크가 붙은 것만으로는 부족하다 — 미리보기가 iframe 안 stylesheet 를 모두 받은 뒤 다는 표식을 기다려 잰다
  // (서버가 CSS 를 늦게 주면 적재 전 폭 390 을 읽던 간헐 실패). 표식이 달려야 관리자에게도 미리보기가 보인다.
  await expect(page.locator('#hero_form_preview .hs-preview__viewport[data-hs-styles-ready]')).toBeAttached({ timeout: 30_000 });
  await expect(page.locator('#hero_form_preview .hs-preview__viewport iframe')).toHaveCSS('visibility', 'visible');
  await page.waitForTimeout(300);
  return page.evaluate(`(() => { const f = document.querySelector('#hero_form_preview .hs-preview__viewport iframe'); return { ...(${measure.toString()})(f.contentDocument, ${JSON.stringify(title)}), viewport: Number(f.getAttribute('width')) }; })()`) as Promise<Slot & { viewport: number }>;
}

for (const [position, path] of [['home_hero', '/'], ['shop_hero', '/shop/products']] as const) {
  // @scenario surface=preview_frame_slot_inside_device_viewport
  // 변종: position=home_hero·shop_hero
  // @effects preview_matches_site_slot
  test(`${position} 미리보기 구좌가 실제 사이트 구좌와 같다(PC·모바일)`, async ({ page, request, managerToken, created }) => {
    const title = `E2E 구좌 ${position} 긴 제목으로 줄바꿈이 같은지 확인하는 문장 ${Date.now()}`;
    const id = await createBanner(request, managerToken, {
      position, title: { ko: title },
      description: { ko: '설명 문구도 함께 같은 폭에서 줄을 바꾸는지 확인하기 위한 조금 긴 문장입니다. 사이트와 미리보기가 같아야 합니다.' },
    });
    created.push(id);

    for (const [viewport, device] of [['desktop', 'pc'], ['mobile', 'mobile']] as const) {
      const site = await siteSlot(page, path, viewport, title);
      await authenticatePage(page, managerToken);
      const preview = await previewSlot(page, id, device, title);
      expect(preview.viewport).toBe(VIEWPORTS[viewport].width);
      expect(Math.abs(preview.width - site.width), `${position} ${device} width ${preview.width} vs ${site.width}`).toBeLessThan(0.5);
      expect(Math.abs(preview.height - site.height), `${position} ${device} height`).toBeLessThan(0.5);
      expect(preview.titleFont).toBe(site.titleFont);
      expect(preview.titleLines).toBe(site.titleLines);
      expect(preview.descLines).toBe(site.descLines);
      // 본문 폭이면 구좌는 기기 폭보다 좁다(본문 여백) — 종전처럼 viewport 를 구좌로 쓰지 않는다. 화면 전체면 기기 폭 그대로다
      expect(preview.full).toBe(site.full);
      if (site.full) expect(Math.abs(preview.width - preview.viewport)).toBeLessThan(0.5);
      else expect(preview.width).toBeLessThan(preview.viewport);
    }
  });
}
