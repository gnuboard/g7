/**
 * @file popup-hidden-slide-media.spec.ts
 * @description 숨은 장 내용 지연과 PC 이미지 sizes — 팝업 3개면 첫 방문에 지금 장·다음 장만 내용을 그리고, 셋째 장 본문 이미지는
 * 그 장으로 넘길 때 처음 받는다. PC 창은 본문 상한(fit)을 잰 뒤 이미지를 붙여 같은 이미지를 두 규격으로 요청하지 않는다.
 * (매니페스트 popup-layer.yaml)
 */
import { test, expect, createHtmlPopup, createImagePopup, gotoUser, layer } from '../fixtures/popup-auth';

const BODY_IMG = '/api/modules/sirsoft-ecommerce/product-image/026938c07cab?size=lg';

test.describe('팝업 숨은 장 내용 지연', () => {
  // @scenario surface=hidden_slides_render_only_current_and_next
  // @effects hidden_slide_media_deferred
  test('셋째 장 본문 이미지는 그 장으로 넘길 때 처음 받고, 지금 장 이미지는 한 규격만 받는다', async ({ page, request, managerToken, created }) => {
    created.push(await createImagePopup(request, managerToken));
    created.push(await createImagePopup(request, managerToken));
    created.push(await createHtmlPopup(request, managerToken, { body_html: { ko: `<p><img src="${BODY_IMG}" alt="본문"></p>` } }));

    const bodyRequests: string[] = [];
    const popupImages: string[] = [];
    page.on('request', (req) => {
      if (req.url().includes('/product-image/026938c07cab')) bodyRequests.push(req.url());
      if (/sirsoft-popup\/images\//.test(req.url())) popupImages.push(req.url());
    });

    await page.setViewportSize({ width: 1440, height: 770 });
    await gotoUser(page, '/page/about');
    await expect(layer(page)).toBeVisible();

    const ids = created.map(String);
    const filled = async () => page.evaluate((list) => list.map((id) => !!document.querySelector(`.g7pp-slide[data-popup-id="${id}"] .g7pp-media, .g7pp-slide[data-popup-id="${id}"] .g7pp-html`)), ids);
    expect(await filled()).toEqual([true, true, false]);
    expect(bodyRequests).toHaveLength(0);

    // 지금 장 이미지는 한 장에 한 번만(규격을 바꿔 다시 받지 않는다)
    const firstImageUrls = popupImages.filter((u) => u.includes(popupImages[0]?.split('?')[0] ?? '#'));
    expect(firstImageUrls).toHaveLength(1);

    const pause = layer(page).locator('.g7pp-pager__pause');
    if (await pause.count()) await pause.click();
    await layer(page).locator('.g7pp-dot').nth(2).click();
    await expect.poll(async () => (await filled())[2]).toBe(true);
    await expect.poll(() => bodyRequests.length).toBe(1);
    // 한 번 그린 장은 그대로 둔다
    expect((await filled())[0]).toBe(true);
  });
});
