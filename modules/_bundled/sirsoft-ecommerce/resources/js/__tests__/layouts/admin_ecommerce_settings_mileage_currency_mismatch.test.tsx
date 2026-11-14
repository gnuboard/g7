/**
 * @file admin_ecommerce_settings_mileage_currency_mismatch.test.tsx
 * @description 마일리지 탭 — 통화 규칙 첫 행이 쇼핑몰 기본 통화와 어긋났을 때의 안내와 복구 단추
 *
 * 첫 행은 통화 코드 입력과 삭제가 잠겨 있고, 서버는 첫 행이 기본 통화여야 마일리지 탭 저장을 받는다.
 * 수정 전 버전에서 기본 통화를 바꾼 상점은 이 둘 사이에 갇혀 탭을 다시는 저장할 수 없었다(공유 개발
 * 서버 실측: 기본 통화 JPY + 규칙 [KRW] → 저장마다 422). 이 조각은 어긋남을 알리고, 단추 한 번으로
 * 기본 통화 행을 첫 행에 세운다(있던 행은 옮기고 없으면 「통화 추가」와 같은 기본값으로 만든다).
 *
 * 굽기 산출 JSON 을 그대로 렌더하고 단추를 실제로 눌러 엔진이 바꾼 `_local.form` 을 읽는다.
 */

import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { fireEvent, screen } from '@testing-library/react';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import {
  buildSettingsLayout,
  loadSettingsPartial,
  setupRegistry,
  settle,
  translations,
  watch,
  type Json,
} from './support/checkoutFieldsHarness';

const notice = loadSettingsPartial('_tab_mileage_currency_mismatch.json');

const rule = (code: string, overrides: Json = {}): Json => ({
  currency_code: code,
  point_value: 1,
  min_use_amount: 1000,
  use_unit: 10,
  max_use_type: 'fixed',
  max_use_percent: 30,
  max_use_value: 50000,
  earn_rounding_unit: '1',
  earn_rounding_method: 'floor',
  ...overrides,
});

let active: ReturnType<typeof createLayoutTest> | null = null;

/** 안내 조각을 주어진 폼과 권한으로 렌더한다. */
async function renderNotice(opts: { base: string; rules: Json[]; canUpdate?: boolean }) {
  active = createLayoutTest(buildSettingsLayout([notice]) as any, {
    componentRegistry: setupRegistry(),
    translations: translations('ko'),
    locale: 'ko',
    queryParams: { tab: 'mileage' },
    initialState: {
      _local: {
        form: {
          language_currency: { default_currency: opts.base },
          mileage: { enabled: false, currency_rules: JSON.parse(JSON.stringify(opts.rules)) },
        },
      },
      _global: { activeEcommerceSettingsTab: 'mileage' },
    },
    initialData: { settings: { data: { abilities: { can_update: opts.canUpdate ?? true } } } },
  } as any);
  await active.render();
  watch(active);

  return active;
}

const codes = (t: ReturnType<typeof createLayoutTest>) =>
  (t.getState()._local.form.mileage.currency_rules as Json[]).map((r) => r.currency_code);

vi.setConfig({ testTimeout: 30_000 });

beforeEach(() => {
  (window as any).__templateApp = { getConfig: () => ({ templateId: 'test-template', locale: 'ko' }) };
});

afterEach(() => {
  active?.cleanup();
  active = null;
  watch(null);
  delete (window as any).__templateApp;
});

describe('마일리지 통화 규칙 — 기본 통화 어긋남 안내와 복구', () => {
  // @scenario trigger=mileage_tab_opened, base_rule=absent
  // @effects misalignment_notice_names_both_currencies
  it('첫 행이 기본 통화가 아니면 두 통화를 밝힌 안내와 복구 단추가 보인다', async () => {
    await renderNotice({ base: 'JPY', rules: [rule('KRW')] });

    const box = screen.getByTestId('mileage-currency-mismatch');
    expect(box.textContent).toContain('KRW');
    expect(box.textContent).toContain('JPY');
    expect(screen.getByTestId('mileage-currency-align')).not.toBeDisabled();
  });

  // @scenario trigger=mileage_tab_opened, base_rule=present_not_first
  // @effects misalignment_notice_names_both_currencies
  it('기본 통화 행이 첫 행이 아닌 자리에 있어도 안내가 보인다', async () => {
    await renderNotice({ base: 'JPY', rules: [rule('KRW'), rule('JPY')] });

    expect(screen.getByTestId('mileage-currency-mismatch').textContent).toContain('KRW');
  });

  // @scenario trigger=mileage_tab_opened, base_rule=first
  // @effects misalignment_notice_hidden_when_aligned
  it('첫 행이 기본 통화면(대소문자 무관) 안내가 없다', async () => {
    await renderNotice({ base: 'jpy', rules: [rule('JPY'), rule('KRW')] });

    expect(screen.queryByTestId('mileage-currency-mismatch')).toBeNull();
  });

  // @scenario trigger=mileage_tab_opened, base_rule=no_rules
  // @effects misalignment_notice_hidden_when_aligned
  it('규칙이 하나도 없으면 맞출 것이 없어 안내가 없다', async () => {
    await renderNotice({ base: 'JPY', rules: [] });

    expect(screen.queryByTestId('mileage-currency-mismatch')).toBeNull();
  });

  // @scenario trigger=align_clicked, base_rule=absent
  // @effects align_button_prepends_default_rule, align_button_marks_changes
  it('기본 통화 행이 없으면 단추가 기본값 행을 첫 행에 만들고 옛 행은 뒤에 남긴다', async () => {
    const t = await renderNotice({ base: 'JPY', rules: [rule('KRW'), rule('USD', { min_use_amount: 5 })] });

    fireEvent.click(screen.getByTestId('mileage-currency-align'));
    await settle();

    expect(codes(t)).toEqual(['JPY', 'KRW', 'USD']);
    const rules = t.getState()._local.form.mileage.currency_rules as Json[];
    expect(rules[0]).toMatchObject({
      point_value: 1,
      min_use_amount: 0,
      use_unit: 1,
      max_use_type: 'percent',
      max_use_percent: 30,
      max_use_value: 0,
      earn_rounding_unit: '1',
      earn_rounding_method: 'floor',
    });
    expect(rules[2].min_use_amount).toBe(5);
    expect(t.getState()._local.hasChanges).toBe(true);
    expect(screen.queryByTestId('mileage-currency-mismatch')).toBeNull();
  });

  // @scenario trigger=align_clicked, base_rule=present_not_first
  // @effects align_button_moves_existing_rule
  it('기본 통화 행이 뒤에 있으면 그 행을 첫 행으로 옮길 뿐 새로 만들지 않는다', async () => {
    const t = await renderNotice({ base: 'JPY', rules: [rule('KRW'), rule('JPY', { use_unit: 7 })] });

    fireEvent.click(screen.getByTestId('mileage-currency-align'));
    await settle();

    expect(codes(t)).toEqual(['JPY', 'KRW']);
    expect((t.getState()._local.form.mileage.currency_rules as Json[])[0].use_unit).toBe(7);
  });

  // @scenario trigger=mileage_tab_opened, base_rule=absent
  // @effects align_button_locked_without_update_permission
  it('수정 권한이 없으면 안내는 보이되 단추는 잠긴다', async () => {
    await renderNotice({ base: 'JPY', rules: [rule('KRW')], canUpdate: false });

    expect(screen.getByTestId('mileage-currency-mismatch')).toBeTruthy();
    expect(screen.getByTestId('mileage-currency-align')).toBeDisabled();
  });
});
