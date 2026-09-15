/**
 * E2E: 토스페이먼츠 설정 — 주문서형 결제수단 체크박스 라벨 렌더 (#454)
 *
 * @scenario toss_payment_methods_vbank_escrow
 * @effects enabled_methods_persisted
 *
 * 배경(회귀):
 *   Checkbox 컴포넌트는 `label` prop 을 받지만 렌더하지 않는다 (부모 Label 이 표시를 담당).
 *   레이아웃이 `label` prop 에만 의존하면 체크박스만 9개 뜨고 이름이 전부 빈 칸이 된다.
 *   레이아웃 구조 테스트(JSON walk)는 이를 잡지 못했고 브라우저에서만 드러났다 —
 *   따라서 "라벨 텍스트가 실제로 화면에 보이는가" 는 E2E 로 고정한다.
 *
 * 검증:
 *  1. 주문서형 결제 토글을 켜면 결제수단 9종이 노출되고, 각 체크박스에 라벨 텍스트가 보인다
 *  2. 라벨 클릭으로 체크 상태가 토글된다 (Label 래핑이 input 과 연결됨)
 *  3. 체크 해제 → 저장 → 재진입 시 상태가 유지된다 (폼 자동바인딩 회귀 가드)
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const SETTINGS_PATH = '/admin/plugins/sirsoft-tosspayments/settings';

/** 결제수단 체크박스 9종의 name (레이아웃의 method_* 와 1:1) */
const METHOD_NAMES = [
  'method_card',
  'method_virtual_account',
  'method_transfer',
  'method_mobile_phone',
  'method_tosspay',
  'method_kakaopay',
  'method_naverpay',
  'method_payco',
  'method_samsungpay',
] as const;

/** 설정 화면 진입 + 결제수단 그룹이 마운트될 때까지 대기 */
async function gotoSettings(page: import('@playwright/test').Page): Promise<void> {
  await page.goto(SETTINGS_PATH);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  expect(page.url()).not.toMatch(/\/admin\/login/);

  // 주문서형 결제가 꺼져 있으면 결제수단 그룹이 if 로 숨겨진다 → 켜서 노출시킨다.
  //
  // 체크박스는 저장값이 폼에 시드되기 **전에** 이미 DOM 에 붙는다. 그래서 마운트만 기다린 뒤
  // 상태를 읽으면 시드 전 기본값(꺼짐)을 보게 되고, 그 판단으로 토글을 누르면 직후 도착한
  // 시드가 값을 되돌려 조작이 씹힌 것처럼 보인다. 결과가 나타날 때까지 폴링하고, 시드가
  // 토글을 되돌렸으면 다시 누른다 — 시드 전/후 어느 시점에 진입해도 같은 상태로 수렴한다.
  const orderSheetToggle = page.locator('input[type=checkbox][name="order_sheet_mode"]');
  await expect(orderSheetToggle).toBeAttached({ timeout: 20_000 });

  const methodCard = page.locator('input[type=checkbox][name="method_card"]');

  await expect
    .poll(
      async () => {
        if ((await methodCard.count()) > 0) return true;
        if (!(await orderSheetToggle.isChecked())) {
          // Toggle 컴포넌트는 input 을 시각적으로 감추고 형제 트랙을 노출하므로 force 클릭이 필요하다.
          await orderSheetToggle.click({ force: true });
        }

        return false;
      },
      { message: '주문서형 결제수단 그룹이 끝내 렌더되지 않았다', timeout: 25_000 },
    )
    .toBe(true);

  // 그룹이 뜬 뒤에도 한 번 더 안정화를 확인한다 — 시드가 늦게 도착해 값이 한 번 더 바뀌는
  // 구간에서 상태를 읽으면 토글 방향이 뒤집힌다.
  await expect
    .poll(
      async () => {
        const first = await methodCard.isChecked();
        await page.waitForTimeout(400);

        return first === (await methodCard.isChecked());
      },
      { message: '결제수단 체크 상태가 계속 흔들린다', timeout: 15_000 },
    )
    .toBe(true);
}

// @scenario order_sheet_mode=on, method_labels=rendered
// @effects enabled_methods_persisted
test('@smoke #454 - 결제수단 9종 체크박스에 라벨 텍스트가 표시된다', async ({ page }) => {
  const token = issueToken('core.plugins.update');
  await authenticatePage(page, token);

  await gotoSettings(page);

  for (const name of METHOD_NAMES) {
    const checkbox = page.locator(`input[type=checkbox][name="${name}"]`);
    await expect(checkbox).toBeAttached();

    // Checkbox 는 label prop 을 렌더하지 않으므로, 부모 Label 이 텍스트를 갖고 있어야 한다.
    const wrapper = page.locator(`label:has(input[type=checkbox][name="${name}"])`);
    await expect(wrapper, `${name} 라벨 래퍼 누락`).toBeVisible();

    const text = (await wrapper.innerText()).trim();
    expect(text, `${name} 라벨 텍스트가 비어 있음 (label prop 은 렌더되지 않는다)`).not.toBe('');
  }
});

// @scenario label_click=toggles_checkbox
test('#454 - 라벨을 클릭하면 체크 상태가 토글된다', async ({ page }) => {
  const token = issueToken('core.plugins.update');
  await authenticatePage(page, token);

  await gotoSettings(page);

  const checkbox = page.locator('input[type=checkbox][name="method_card"]');
  const label = page.locator('label:has(input[type=checkbox][name="method_card"])');

  const before = await checkbox.isChecked();
  await label.click();
  await expect(checkbox).toBeChecked({ checked: !before });

  // 원복 (다음 테스트 간섭 방지)
  await label.click();
  await expect(checkbox).toBeChecked({ checked: before });
});

// @scenario save=enabled_methods, payload=boolean
// @effects enabled_methods_persisted
test('#454 - 결제수단 저장 페이로드가 boolean 으로 전송된다 (빈 문자열 회귀 가드)', async ({ page }) => {
  const token = issueToken('core.plugins.update');
  await authenticatePage(page, token);

  await gotoSettings(page);

  // 저장 요청 본문을 모아 둔다. 단일 `waitForRequest` 술어로 잡으면
  // gotoSettings 가 주문서형 토글을 켜며 먼저 보내는 저장과 순서가 엉켜 놓칠 수 있으므로,
  // 설정 PUT 을 전부 수집한 뒤 결제수단 키를 담은 것을 고른다 (상태 의존 flaky 방지).
  const settingsPuts: string[] = [];
  page.on('request', (req) => {
    if (req.method() === 'PUT' && req.url().includes('/plugins/sirsoft-tosspayments/settings')) {
      settingsPuts.push(req.postData() ?? '');
    }
  });

  // 토글해서 dirty 를 만든다 (값 자체는 무엇이든 boolean 이어야 한다).
  const target = page.locator('input[type=checkbox][name="method_samsungpay"]');
  const before = await target.isChecked();
  await target.click();
  await expect(target).toBeChecked({ checked: !before });

  await expect(page.locator('#save_button')).toBeEnabled({ timeout: 10_000 });
  await page.locator('#save_button').click();

  await expect
    .poll(() => settingsPuts.filter((payload) => payload.includes('method_card')).length, {
      message: '결제수단을 담은 설정 저장 요청이 나가지 않았다',
      timeout: 20_000,
    })
    .toBeGreaterThan(0);

  const body = JSON.parse(settingsPuts.find((payload) => payload.includes('method_card')) ?? '{}');

  // 회귀: 저장값이 null/미정의였던 항목이 빈 문자열('')로 전송되면 서버가 null 로 저장하고,
  // 그 null 이 기본값(false)을 덮어 화면에서 다시 켤 수 없게 고착된다.
  for (const name of METHOD_NAMES) {
    expect(typeof body[name], `${name} 이 boolean 이 아님 (실제: ${JSON.stringify(body[name])})`).toBe('boolean');
  }

  // 원상 복구
  await gotoSettings(page);
  const restored = page.locator('input[type=checkbox][name="method_samsungpay"]');
  if ((await restored.isChecked()) !== before) {
    await restored.click();
    await expect(page.locator('#save_button')).toBeEnabled({ timeout: 10_000 });
    await page.locator('#save_button').click();
    await page.waitForTimeout(2_000);
  }
});

// @scenario save=enabled_methods, reload=persisted
// @effects enabled_methods_persisted
test('#454 - 결제수단 체크 해제 후 저장하면 재진입 시 유지된다', async ({ page }) => {
  const token = issueToken('core.plugins.update');
  await authenticatePage(page, token);

  await gotoSettings(page);

  const target = page.locator('input[type=checkbox][name="method_samsungpay"]');
  // 저장 버튼은 세션 로케일에 따라 라벨이 달라지므로 id 로 잡는다 (name 기반은 en 세션에서 깨진다).
  const saveButton = page.locator('#save_button');

  // 체크 해제 상태를 만든다 (이미 해제되어 있으면 켰다가 다시 끈다 — dirty 를 확실히 만든다).
  if (!(await target.isChecked())) {
    await target.click();
    await expect(target).toBeChecked();
  }
  await target.click();
  await expect(target).not.toBeChecked();

  // disabled 는 {{!_local.hasChanges}} 바인딩 — 활성화되었다는 것은 폼 dirty 감지가 살아있다는 뜻.
  await expect(saveButton).toBeEnabled({ timeout: 10_000 });
  await saveButton.click();

  await page.waitForTimeout(2_000);
  await gotoSettings(page);
  await expect(page.locator('input[type=checkbox][name="method_samsungpay"]')).not.toBeChecked({ timeout: 10_000 });

  // 원상 복구 — 다른 시나리오/실환경에 해제 상태를 남기지 않는다.
  const restored = page.locator('input[type=checkbox][name="method_samsungpay"]');
  await restored.click();
  await expect(restored).toBeChecked();
  await expect(page.locator('#save_button')).toBeEnabled({ timeout: 10_000 });
  await page.locator('#save_button').click();
  await page.waitForTimeout(2_000);
});
