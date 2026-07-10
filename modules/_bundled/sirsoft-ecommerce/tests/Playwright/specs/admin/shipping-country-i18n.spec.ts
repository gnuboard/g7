/**
 * 배송설정 배송국가 — 다국어 표시/입력 회귀 (#459).
 *
 * 배경: 배송국가명이 UI 로케일과 무관하게 항상 한국어로 렌더됐다. 원인은 두 겹이었다.
 *  1) 레이아웃이 `name[_global.locale]` 로 직접 색인 — 로케일 키 부재 시 폴백 없음.
 *  2) 엔진 `$localized()` 가 `context.$locale || 'ko'` 로 로케일을 확정 — `init_actions`
 *     같은 ActionDispatcher 경로는 `$locale` 을 넘기지 않으므로 **항상** ko 를 반환했다
 *     (engine-v1.52.1 에서 `window.__templateApp.getConfig()` 회수로 수정).
 * 또한 국가 추가 폼의 이름 입력칸이 ko/en 2칸으로 하드코딩돼 있어 언어를 추가해도
 * 그 언어의 이름을 넣을 수 없었다 → `$locales` 순회로 전환.
 *
 * 측정 규율 (이 spec 을 고칠 사람에게):
 *  - **국가명 문자열을 하드코딩하지 않는다**("South Korea" 등). 시드 데이터가 바뀌면
 *    거짓 실패하고, 무엇보다 "ko 가 아닌 무언가"를 렌더한다는 사실만으로는 회귀를 못 잡는다.
 *    API 응답에서 그 로케일의 기대값을 **런타임에 도출**해 화면과 대조한다.
 *  - **로케일 목록을 하드코딩하지 않는다**. 언어는 계속 추가된다. 설치된 언어는
 *    `$locales` 에서 읽는다.
 *  - 회귀(= ko 고정) 판정은 "en 값과 같다" 로는 부족하다. ko 와 en 이 같은 국가(예: 코드
 *    폴백)만 골라 보면 통과해 버린다. **ko 값과 다른 국가가 하나라도 있는지** 먼저 확인한
 *    뒤, 그 국가에서 화면값 === en 값을 단언한다.
 *
 * @scenario shipping_country_i18n locale=en|ja × surface=settings_list|add_form
 * @effects country_names_localized_by_active_locale,
 *          country_names_not_pinned_to_ko,
 *          add_form_inputs_follow_installed_locales,
 *          add_button_enabled_by_any_locale_name
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import type { Page } from '@playwright/test';

const SHIPPING_SETTINGS_URL = '/admin/ecommerce/settings?tab=shipping';

/** 활성 배송국가의 원본 다국어 이름(name 객체)을 앱 상태에서 읽는다. */
async function activeCountryNames(page: Page): Promise<Array<Record<string, string>>> {
  return page.evaluate(() => {
    const core = (window as any).G7Core;
    const shipping = core?.state?.getGlobal?.()?.modules?.['sirsoft-ecommerce']?.shipping;
    return (shipping?.available_countries ?? [])
      .filter((c: any) => c.is_active)
      .map((c: any) => (typeof c.name === 'object' && c.name ? c.name : {}));
  });
}

/** 현재 UI 로케일. */
async function activeLocale(page: Page): Promise<string> {
  return page.evaluate(() => (window as any).__templateApp?.getConfig?.()?.locale ?? '');
}

/** 헤더 배송국가 셀렉터가 파생한 표시명 (init_actions/ActionDispatcher 경로 — 회귀 지점). */
async function derivedHeaderNames(page: Page): Promise<string[]> {
  return page.evaluate(() =>
    ((window as any).G7Core?.state?.getGlobal?.()?.availableShippingCountries ?? []).map((c: any) => c.name),
  );
}

/**
 * 설치된 언어의 `{ 코드: 표시명 }` 맵. 하드코딩 금지 — 언어는 계속 추가된다.
 * 레이아웃의 이름 입력칸 라벨이 쓰는 것과 동일한 출처(`appConfig.localeNames`)를 읽는다.
 */
async function localeDisplayNames(page: Page): Promise<Record<string, string>> {
  return page.evaluate(() => (window as any).G7Core?.state?.getGlobal?.()?.appConfig?.localeNames ?? {});
}

/** 국가 표(`_local.form.shipping`)가 시드됐는지. */
async function formSeeded(page: Page): Promise<boolean> {
  return page.evaluate(() => {
    const form = (window as any).G7Core?.state?.getLocal?.()?.form;
    return Array.isArray(form?.shipping?.available_countries) && form.shipping.available_countries.length > 0;
  });
}

/**
 * 배송설정 탭이 데이터를 받아 국가 목록을 **화면에** 그릴 때까지 기다린다.
 *
 * 상태(available_countries)만 기다리면 DOM 이 아직 비어 있어 body 텍스트가 `""` 다.
 *
 * 재진입 1회 허용 — 사전 결함(#459 검수 중 발견): settings 데이터소스의 `initLocal: "form"`
 * 시드가 간헐적으로 유실돼 `_local.form` 이 비고 표가 "No shipping countries" 로 렌더된다.
 * 이때 `_global.modules[...]` 와 데이터소스에는 국가가 정상 적재돼 있다(= 로딩 실패가 아님).
 * 이 spec 의 대상은 다국어 표시이므로, 시드 유실 시 1회 재진입해 본 검증을 이어간다.
 * **이 재진입은 그 결함의 회피가 아니라 격리다** — 결함 자체는 별도 이슈로 추적한다.
 */
async function gotoShippingSettings(page: Page, token: string): Promise<void> {
  await authenticatePage(page, token);
  await page.setViewportSize({ width: 1280, height: 900 });

  for (let attempt = 0; attempt < 2; attempt++) {
    await page.goto(SHIPPING_SETTINGS_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await expect.poll(async () => (await activeCountryNames(page)).length, { timeout: 30_000 }).toBeGreaterThan(0);

    const seeded = await expect
      .poll(async () => formSeeded(page), { timeout: 15_000 })
      .toBe(true)
      .then(() => true)
      .catch(() => false);
    if (seeded) break;
    if (attempt === 1) throw new Error('_local.form 시드 유실이 2회 연속 발생 (사전 결함)');
  }

  const locale = await activeLocale(page);
  const expected = (await activeCountryNames(page)).map((n) => n[locale]).filter(Boolean);
  if (expected.length > 0) {
    await expect
      .poll(async () => (await page.locator('body').innerText()).includes(expected[0]), { timeout: 30_000 })
      .toBe(true);
  }
}

test.describe('@sirsoft-ecommerce 배송국가 다국어', () => {
  // @scenario shipping_country_i18n:settings_list
  // @effects country_names_localized_by_active_locale, country_names_not_pinned_to_ko
  test('국가 목록이 활성 로케일 값으로 렌더된다 (ko 고정 회귀 차단)', async ({ page, settingsToken }) => {
    await gotoShippingSettings(page, settingsToken);

    const locale = await activeLocale(page);
    const names = await activeCountryNames(page);

    // 이 테스트가 의미를 가지려면 "ko 와 다른 활성 로케일 값" 이 최소 1건 있어야 한다.
    // (전부 같으면 ko 고정 회귀를 구분할 수 없어 거짓 통과한다.)
    const discriminating = names.filter((n) => n[locale] && n.ko && n[locale] !== n.ko);
    test.skip(
      discriminating.length === 0,
      `활성 로케일(${locale}) 값이 ko 와 모두 동일 — 회귀 판별 불가한 시드`,
    );

    const body = await page.locator('body').innerText();
    for (const name of discriminating) {
      expect(body, `활성 로케일(${locale}) 이름이 렌더돼야 한다`).toContain(name[locale]);
      expect(body, `ko 이름(${name.ko})이 렌더되면 ko 고정 회귀`).not.toContain(name.ko);
    }
  });

  // @scenario shipping_country_i18n:header_derivation
  // @effects country_names_localized_by_active_locale, country_names_not_pinned_to_ko
  test('헤더 파생 목록도 활성 로케일 값을 쓴다 (ActionDispatcher 경로 $localized)', async ({
    page,
    settingsToken,
  }) => {
    await gotoShippingSettings(page, settingsToken);

    const locale = await activeLocale(page);
    const names = await activeCountryNames(page);
    const discriminating = names.filter((n) => n[locale] && n.ko && n[locale] !== n.ko);
    test.skip(discriminating.length === 0, `활성 로케일(${locale}) 값이 ko 와 모두 동일`);

    await expect.poll(async () => (await derivedHeaderNames(page)).length, { timeout: 20_000 }).toBeGreaterThan(0);
    const derived = await derivedHeaderNames(page);

    // 파생 목록에 ko 전용 값이 섞이면 회귀. (init_actions 는 $locale 을 넘기지 않는 경로다.)
    const koOnly = discriminating.map((n) => n.ko).filter((ko) => derived.includes(ko));
    expect(koOnly, `헤더 파생값: ${JSON.stringify(derived)}`).toEqual([]);
  });

  // @scenario shipping_country_i18n:add_form
  // @effects add_form_inputs_follow_installed_locales, add_button_enabled_by_any_locale_name
  test('국가 추가 폼의 이름 입력칸이 설치된 언어 수만큼 생기고, 어느 한 언어만 채워도 추가된다', async ({
    page,
    settingsToken,
  }) => {
    await gotoShippingSettings(page, settingsToken);

    const localeNames = await localeDisplayNames(page);
    const locales = Object.keys(localeNames);
    expect(locales.length, '설치된 언어를 못 읽었다').toBeGreaterThan(0);

    await page.getByRole('button', { name: /Add Country|국가 추가|国追加/ }).click();

    // 국가 코드 입력칸(placeholder="KR")이 있는 행이 곧 추가 폼이다.
    const codeInput = page.locator('input[placeholder="KR"]');
    await expect(codeInput).toBeVisible({ timeout: 10_000 });

    // 이름 입력칸은 placeholder 가 `... (언어표시명)` 으로 끝난다.
    // 언어 목록을 하드코딩하지 않고 localeNames 로 셀렉터를 만든다.
    const nameInputs = locales.map((code) => page.locator(`input[placeholder$="(${localeNames[code]})"]`));
    for (const [i, input] of nameInputs.entries()) {
      await expect(input, `${locales[i]} 이름 입력칸이 있어야 한다`).toBeVisible({ timeout: 10_000 });
    }

    const addButton = page.getByRole('button', { name: /^(Add|추가|追加)$/ });
    await expect(addButton).toBeDisabled();

    // 코드만 채워도 여전히 비활성 (이름이 전부 비어 있음)
    await codeInput.fill('zz');
    await expect(addButton).toBeDisabled();

    // 공백만 채워도 비활성 (.trim() 가드)
    await nameInputs[0].fill('   ');
    await expect(addButton, '공백만 입력하면 추가 불가여야 한다').toBeDisabled();
    await nameInputs[0].fill('');

    // 마지막 언어 한 칸만 채워도 활성 — ko 고정 조건이면 여기서 실패한다.
    await nameInputs[nameInputs.length - 1].fill('Zed Test Country');
    await expect(addButton, '어느 한 언어만 채워도 추가 가능해야 한다').toBeEnabled();

    // 저장하지 않고 폼을 닫아 부작용을 남기지 않는다.
    await page.getByRole('button', { name: /^(Cancel|취소|キャンセル)$/ }).first().click();
  });
});
