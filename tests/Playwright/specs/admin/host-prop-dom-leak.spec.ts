/**
 * E2E: 호스트 DOM 으로 새면 안 되는 props (engine-v1.55.1)
 *
 * @scenario leak_path=extension_point_host, prop_shape=object
 * @effects extension_point_host_clean, icon_not_autobound, address_search_still_works
 *
 * 배경: 브라우저 실측에서 관리자 두 화면에 객체 값이 DOM 속성으로 문자열화되어 남아 있었다.
 *   - 쇼핑몰 설정 기본정보: `<div id="address_search_slot" targetfields="[object Object]">`
 *     → `extension_point` 컨테이너가 주입 자식용 페이로드를 호스트 `<div>` 에 그대로 spread
 *   - 상품 등록/수정: `<i class="fas fa-images" value="[object Object],[object Object]">`
 *     → Form 자동 바인딩이 `name` prop 만 보고 잡아, 아이콘 글리프 이름 `images` 가
 *       같은 이름의 폼 필드(이미지 배열)와 겹쳐 그 값이 `value` 로 주입
 *
 * 단위 테스트(`DynamicRenderer.hostPropLeak.test.tsx`)가 두 경로를 각각 잠그지만,
 * 실제로 이 값이 화면에 남았는지는 조립된 레이아웃 + 실제 폼 상태에서만 드러난다.
 * 여기서는 **누출 0** 과 **기능 보존**(주소 검색 팝업이 실제로 열림)을 함께 본다 —
 * 누출만 막고 소비 경로를 끊으면 조용한 기능 손실이 되기 때문이다.
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

/** 페이지 전체에서 `[object Object]` 가 박힌 속성을 수집한다. */
async function collectObjectLeaks(page: import('@playwright/test').Page): Promise<string[]> {
  return page.evaluate(() => {
    const hits: string[] = [];
    document.querySelectorAll('*').forEach((el) => {
      for (const attr of Array.from(el.attributes)) {
        if (attr.value.includes('[object Object]')) {
          hits.push(`${el.tagName.toLowerCase()}[${attr.name}]`);
        }
      }
    });
    return hits;
  });
}

// @scenario screen=ecommerce_settings, leak_axis=extension_point
// @effects extension_point_host_clean
test('@smoke engine-v1.55.1 - 확장 삽입 영역 호스트에 객체 props 가 남지 않는다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await page.goto('/admin/ecommerce/settings');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const slot = page.locator('#address_search_slot');
  await expect(slot).toBeAttached({ timeout: 20_000 });

  // 호스트는 단순 래퍼 — 주입 자식용 페이로드(targetFields/readOnlyFields)를 실어서는 안 된다.
  await expect(slot).not.toHaveAttribute('targetfields', /.*/);
  await expect(slot).not.toHaveAttribute('readonlyfields', /.*/);

  expect(await collectObjectLeaks(page)).toEqual([]);
});

// @scenario screen=ecommerce_settings, leak_axis=extension_point
// @effects address_search_still_works
test('engine-v1.55.1 - 호스트 정리 후에도 주소 검색은 그대로 열린다', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update');
  await authenticatePage(page, token);

  await page.goto('/admin/ecommerce/settings');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const searchButton = page.locator('#address_search_slot button').first();
  await expect(searchButton).toBeAttached({ timeout: 20_000 });
  await searchButton.click();

  // 주입 자식은 `{{extensionPointProps.*}}` 로 페이로드를 읽는다 — 호스트 DOM 속성과 무관하므로
  // 호스트를 정리해도 팝업은 그대로 떠야 한다. 뜨지 않으면 소비 경로를 끊은 것이다.
  await expect(page.locator('#daum-postcode-layer')).toBeAttached({ timeout: 20_000 });
});

// @scenario screen=board_post_detail, leak_axis=extension_point
// @effects html_content_host_clean
test('@smoke engine-v1.55.1 - 본문 표시 영역 호스트에 원시값 페이로드가 남지 않는다', async ({ page }) => {
  const token = issueToken('sirsoft-board.archive.admin.posts.read');
  await authenticatePage(page, token);

  await page.goto('/admin/board/archive/post/219');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const host = page.locator('#normal_content');
  await expect(host).toBeAttached({ timeout: 20_000 });

  // `content`·`isHtml` 은 주입 자식이 읽는 페이로드다 — 호스트 속성이 되어서는 안 된다.
  await expect(host).not.toHaveAttribute('content', /.*/);
  await expect(host).not.toHaveAttribute('ishtml', /.*/);

  // 그리고 본문은 그대로 그려져야 한다(페이로드 소비 경로 보존).
  await expect(host).not.toBeEmpty();

  expect(await collectObjectLeaks(page)).toEqual([]);
});

// @scenario screen=board_post_form, leak_axis=extension_point
// @effects html_editor_host_clean
test('engine-v1.55.1 - 글 편집기 영역을 정리해도 편집기는 그대로 뜨고 입력이 반영된다', async ({ page }) => {
  const token = issueToken('sirsoft-board.archive.admin.posts.read', 'sirsoft-board.archive.admin.posts.write');
  await authenticatePage(page, token);

  await page.goto('/admin/board/archive/create');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const editor = page.locator('.ck-content').first();
  await expect(editor).toBeAttached({ timeout: 30_000 });

  await editor.click();
  await editor.fill('E2E 확장점 호스트 정리 검증');

  // 편집기가 페이로드(name/value/placeholder)를 계속 받는지 = 입력이 폼 상태에 반영되는지
  await expect
    .poll(async () => page.evaluate(() => {
      const local = (window as any).G7Core?.state?.getLocal?.();
      return String(local?.form?.content ?? '');
    }), { timeout: 15_000 })
    .toContain('E2E 확장점 호스트 정리 검증');

  expect(await collectObjectLeaks(page)).toEqual([]);
});

// @scenario screen=ecommerce_product_form, leak_axis=form_autobinding
// @effects icon_not_autobound
test('@smoke engine-v1.55.1 - 아이콘 이름이 폼 필드와 겹쳐도 값이 주입되지 않는다', async ({ page }) => {
  const token = issueToken(
    'sirsoft-ecommerce.products.read',
    'sirsoft-ecommerce.products.update',
    'sirsoft-ecommerce.products.create'
  );
  await authenticatePage(page, token);

  // 상품 등록 화면은 `_local.form.images` 배열을 들고, 이미지 카드 헤더의 아이콘은 name="images" 다.
  await page.goto('/admin/ecommerce/products/create');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const imagesIcon = page.locator('i.fa-images').first();
  await expect(imagesIcon).toBeAttached({ timeout: 20_000 });

  // 아이콘은 글리프만 그린다 — 폼 값이 실려서는 안 된다.
  await expect(imagesIcon).not.toHaveAttribute('value', /.*/);

  expect(await collectObjectLeaks(page)).toEqual([]);
});
