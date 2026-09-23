/**
 * 쇼핑몰 환경설정 — 여백·정렬·목록 높이 (#599 검수 지적)
 *
 * 클래스 문자열은 제자리에 있어도 CSS 레이어·우선순위 때문에 화면에서만 무너지는 결함이라,
 * 렌더된 좌표(getBoundingClientRect)와 계산된 스타일(getComputedStyle)로 본다.
 * 이 파일은 설정을 읽기만 한다 — 저장하지 않는다.
 *
 * @scenario screen=claim_tab
 * @effects settings_policy_cards_keep_card_padding, settings_reason_add_button_aligned_right, settings_payment_method_rows_are_spaced, settings_payment_method_list_scrolls_within_bound, settings_business_address_rows_are_spaced
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const SETTINGS_URL = '/admin/ecommerce/settings';

test.describe('쇼핑몰 환경설정 여백·정렬', () => {
  test.use({ viewport: { width: 1440, height: 1000 } });

  /**
   * @scenario screen=claim_tab
   * @effects settings_policy_cards_keep_card_padding
   */
  test('클레임 정책 카드 5장은 상하 여백 16px 을 지키고, 마지막 문구가 테두리에 붙지 않는다', async ({ page, settingsToken }) => {
    test.setTimeout(90_000);
    await authenticatePage(page, settingsToken);
    await page.goto(`${SETTINGS_URL}?tab=claim`);
    await expect(page.locator('#claim_complete_defaults_card')).toBeVisible({ timeout: 30_000 });

    const measured = await page.evaluate(() => {
      const ids = ['claim_requestable_card', 'claim_charge_card', 'claim_auto_approve_card', 'claim_attachment_card', 'claim_complete_defaults_card'];
      const paddings = ids.map((id) => {
        const cs = getComputedStyle(document.getElementById(id)!);
        return [id, cs.paddingTop, cs.paddingBottom];
      });
      const card = document.getElementById('claim_complete_defaults_card')!;
      const lastHint = card.querySelector('#claim_complete_restore_stock_field .form-hint')!;
      return {
        paddings,
        lastHintToBorder: Math.round(card.getBoundingClientRect().bottom - lastHint.getBoundingClientRect().bottom),
      };
    });

    test.info().annotations.push({ type: 'measured', description: JSON.stringify(measured) });
    for (const [id, top, bottom] of measured.paddings) {
      expect([top, bottom], `${id} 의 상하 패딩`).toEqual(['16px', '16px']);
    }
    // 테두리 1px + 패딩 16px — 종전에는 0 이었다(문구가 테두리에 붙음).
    expect(measured.lastHintToBorder).toBeGreaterThanOrEqual(16);
  });

  /**
   * @scenario screen=claim_tab
   * @effects settings_reason_add_button_aligned_right
   */
  test('사유 하위 탭 3종 모두 [사유 추가] 버튼이 카드 오른쪽 끝에 선다', async ({ page, settingsToken }) => {
    test.setTimeout(90_000);
    await authenticatePage(page, settingsToken);

    for (const subTab of ['exchange', 'return', 'refund']) {
      await page.goto(`${SETTINGS_URL}?tab=claim&sub_tab=${subTab}`);
      const panel = page.locator(`#claim_${subTab}_reason_panel`);
      await expect(panel.locator('.card-header button')).toBeVisible({ timeout: 30_000 });

      const m = await panel.evaluate((el) => {
        const card = el.querySelector('.admin-card')!.getBoundingClientRect();
        const header = el.querySelector('.card-header')!;
        const title = header.querySelector('h3')!.getBoundingClientRect();
        const button = header.querySelector('button')!.getBoundingClientRect();
        return { rightInset: Math.round(card.right - button.right), titleToButton: Math.round(button.left - title.right) };
      });

      test.info().annotations.push({ type: 'measured', description: JSON.stringify({ subTab, ...m }) });
      // 카드 안쪽 여백(16px) + 테두리(1px) 만큼만 떨어져 오른쪽 끝에 붙는다. 종전에는 제목 바로 옆(8px)이었다.
      expect(m.rightInset, `${subTab}: 버튼 오른쪽 끝과 카드 오른쪽 테두리 사이`).toBeLessThanOrEqual(24);
      expect(m.titleToButton, `${subTab}: 제목과 버튼 사이`).toBeGreaterThan(100);
    }
  });

  /**
   * @scenario screen=order_settings_tab
   * @effects settings_payment_method_rows_are_spaced, settings_payment_method_list_scrolls_within_bound
   */
  test('결제수단 행은 서로 떨어져 있고, 목록은 500px 안에서 스크롤된다', async ({ page, settingsToken }) => {
    test.setTimeout(90_000);
    await authenticatePage(page, settingsToken);
    await page.goto(`${SETTINGS_URL}?tab=order_settings`);
    // sortable 노드는 자기 요소를 그리지 않고 항목을 감싸개(payment_methods_scroll)에 곧바로 펼친다.
    const list = page.locator('#payment_methods_scroll');
    await expect(list.locator('[data-sortable-item]').first()).toBeVisible({ timeout: 30_000 });

    const m = await list.evaluate((el) => {
      const rows = [...el.querySelectorAll(':scope > [data-sortable-item]')].map((r) => r.getBoundingClientRect());
      const gaps = rows.slice(1).map((r, i) => Math.round(r.top - rows[i].bottom));
      const cs = getComputedStyle(el);
      return { count: rows.length, gaps, maxHeight: cs.maxHeight, overflowY: cs.overflowY, clientHeight: el.clientHeight, scrollHeight: el.scrollHeight };
    });

    test.info().annotations.push({ type: 'measured', description: JSON.stringify(m) });
    expect(m.count, '비교할 행이 둘 이상이어야 간격을 잴 수 있다').toBeGreaterThanOrEqual(2);
    for (const gap of m.gaps) expect(gap).toBeGreaterThanOrEqual(8);
    expect([m.maxHeight, m.overflowY]).toEqual(['500px', 'auto']);
    expect(m.clientHeight).toBeLessThanOrEqual(500);
    if (m.scrollHeight > 500) {
      // 목록이 상한을 넘으면 페이지가 아니라 목록 안에서 스크롤된다.
      expect(m.scrollHeight).toBeGreaterThan(m.clientHeight);
    }
  });

  /**
   * @scenario screen=order_settings_tab
   * @effects settings_payment_method_rows_are_spaced, settings_payment_method_list_scrolls_within_bound
   */
  test('좁은 화면에서도 결제수단 카드는 서로 떨어져 있고 화면 높이 70% 안에서 스크롤된다', async ({ page, settingsToken }) => {
    test.setTimeout(90_000);
    await page.setViewportSize({ width: 390, height: 844 });
    await authenticatePage(page, settingsToken);
    await page.goto(`${SETTINGS_URL}?tab=order_settings`);
    const list = page.locator('#payment_methods_scroll');
    await expect(list.locator('[data-sortable-item] .excel-card').first()).toBeVisible({ timeout: 30_000 });

    const m = await list.evaluate((el) => {
      const rows = [...el.querySelectorAll(':scope > [data-sortable-item]')].map((r) => r.getBoundingClientRect());
      const cs = getComputedStyle(el);
      return {
        count: rows.length,
        gaps: rows.slice(1).map((r, i) => Math.round(r.top - rows[i].bottom)),
        maxHeight: parseFloat(cs.maxHeight),
        overflowY: cs.overflowY,
        clientHeight: el.clientHeight,
      };
    });

    test.info().annotations.push({ type: 'measured', description: JSON.stringify(m) });
    expect(m.count).toBeGreaterThanOrEqual(2);
    for (const gap of m.gaps) expect(gap).toBeGreaterThanOrEqual(8);
    expect(m.overflowY).toBe('auto');
    // 70vh = 844 × 0.7 ≈ 591px
    expect(Math.round(m.maxHeight)).toBe(Math.round(844 * 0.7));
    expect(m.clientHeight).toBeLessThanOrEqual(Math.round(844 * 0.7));
  });

  /**
   * @scenario screen=basic_info_tab
   * @effects settings_business_address_rows_are_spaced
   */
  test('사업장 주소의 우편번호 줄·기본주소·상세주소 사이에 줄 간격이 있다', async ({ page, settingsToken }) => {
    test.setTimeout(90_000);
    await authenticatePage(page, settingsToken);
    await page.goto(`${SETTINGS_URL}?tab=basic_info`);
    const field = page.locator('#field_address');
    await expect(field.locator('input[name="basic_info.detail_address"]')).toBeVisible({ timeout: 30_000 });

    const m = await field.evaluate((el) => {
      const zipRow = el.querySelector('input[name="basic_info.zipcode"]')!.parentElement!.getBoundingClientRect();
      const base = el.querySelector('input[name="basic_info.base_address"]')!.getBoundingClientRect();
      const detail = el.querySelector('input[name="basic_info.detail_address"]')!.getBoundingClientRect();
      return { zipToBase: Math.round(base.top - zipRow.bottom), baseToDetail: Math.round(detail.top - base.bottom) };
    });

    test.info().annotations.push({ type: 'measured', description: JSON.stringify(m) });
    expect(m.zipToBase).toBeGreaterThanOrEqual(8);
    expect(m.baseToDetail).toBeGreaterThanOrEqual(8);
  });
});
