/**
 * E2E: 휴대폰에서 PC 화면이 한 번 그려진 뒤에도 엔진 폭이 기기 폭으로 돌아오는지 (배포 번들 기준, engine-v1.97.1)
 *
 * 배경: 휴대폰에서 [카카오 로그인] → 카카오톡 앱 → 브라우저로 돌아오면 사이트가 PC 화면으로 축소되어 굳었다.
 * PC 화면이 그려지면 모바일 브라우저가 전체가 보이도록 화면을 축소해 `innerWidth` 가 부풀고(390 → 909),
 * 엔진이 다음 resize 에서 그 값을 다시 읽어 태블릿·PC 폭에 머물렀다(새로고침 전까지 풀리지 않음).
 * 실제 Chrome 모바일 흉내에서만 「PC DOM → innerWidth 부풂」 고리가 생기므로 단위 시험과 별도로 잠근다.
 *
 * 재는 조합: case=background_return — 효과 표식은 각 test 앞에 있다.
 */
import { test, expect } from '@playwright/test';

test.use({
  viewport: { width: 390, height: 844 },
  deviceScaleFactor: 3,
  isMobile: true,
  hasTouch: true,
});

/** 엔진 폭·보이는 영역 폭·레이아웃 폭을 읽는다. */
const readWidths = () => {
  const RM = (window as any).G7Core.ResponsiveManager;

  return {
    engine: RM.getWidth() as number,
    portable: RM.matches('portable') as boolean,
    iw: window.innerWidth,
    cw: document.documentElement.clientWidth,
  };
};

test.describe('휴대폰 화면 축소 뒤 엔진 폭 복귀', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/');
    await page.waitForFunction(() => Boolean((window as any).G7Core?.ResponsiveManager?.getWidth), null, {
      timeout: 30_000,
    });
  });

  // @scenario case=background_return
  // @effects engine_width_corrects_after_zoomed_out_resize
  test('화면 축소로 innerWidth 가 부푼 상태(980)에서 resize 가 와도 레이아웃 폭(390)으로 판정한다', async ({ page }) => {
    expect((await page.evaluate(readWidths)).engine).toBe(390);

    // 화면 축소 상태 흉내 — Chrome DevTools 모바일 흉내 실측에서는 PC DOM 이 그려지면 innerWidth 가 390 → 909 로 부풀었다.
    // Playwright 모바일 흉내는 이 축소를 만들지 않으므로 보이는 영역 폭만 바꿔 같은 상황을 만든다(레이아웃 폭·CSS 폭은 실제 390).
    const after = await page.evaluate(async () => {
      const original = Object.getOwnPropertyDescriptor(window, 'innerWidth')!;
      Object.defineProperty(window, 'innerWidth', { configurable: true, get: () => 980 });
      window.dispatchEvent(new Event('resize'));
      await new Promise((r) => setTimeout(r, 600));
      const RM = (window as any).G7Core.ResponsiveManager;
      const measured = { engine: RM.getWidth() as number, portable: RM.matches('portable') as boolean, cw: document.documentElement.clientWidth };
      Object.defineProperty(window, 'innerWidth', original);
      window.dispatchEvent(new Event('resize'));

      return measured;
    });

    // 수정 전: 부푼 innerWidth(980)를 읽어 태블릿 폭으로 판정했다
    expect(after.cw).toBe(390);
    expect(after.engine).toBe(390);
    expect(after.portable).toBe(true);
  });

  // @scenario case=background_return
  // @effects engine_width_remeasures_on_visible
  test('화면이 다시 보이면(visibilitychange) resize 없이도 기기 폭으로 다시 잰다', async ({ page }) => {
    await page.evaluate(() => (window as any).G7Core.ResponsiveManager._setWidthForTesting(1280));
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    await page.waitForTimeout(600);

    // 수정 전: visibilitychange 를 듣지 않아 1280 에 머물렀다
    expect((await page.evaluate(readWidths)).engine).toBe(390);
  });
});
