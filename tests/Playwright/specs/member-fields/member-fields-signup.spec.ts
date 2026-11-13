/**
 * E2E: 운영자가 만든 가입 항목이 가입 화면에 그대로 나온다 (#490 계획서 10절 ①②).
 *
 * ① 운영자가 환경설정 > 회원 > 가입 항목에서 「항목 추가」 창으로 필수 한 줄 글 항목을 만든다 → 비로그인 가입 화면에 그 칸이
 *    별표와 함께 나온다 → 비우고 제출하면 그 칸이 오류 상태가 되고 서버 오류는 칸 아래(`#mf_error_{키}`)에 붙는다 → 채우고 가입하면
 *    201 과 함께 로그인 화면으로 가고, 값이 회원에 저장된다.
 * ② 운영자 추가 항목 두 개의 순서를 표에서 손잡이로 끌어 바꾼다 → 순서 저장 요청(`PUT …/fields/order`)이 끈 순서를 싣는다 →
 *    가입 화면의 칸 순서가 그 순서를 따른다.
 *
 * 브라우저 기본 검증: 필수 칸은 입력칸에 `required` 가 붙어 있어 비운 채 제출하면 브라우저가 먼저 막는다. 그래서 ①은 먼저
 * 「요청이 나가지 않고 그 칸이 값 누락 상태」를 보고, 이어서 폼의 브라우저 검증을 끄고(`noValidate`) 서버 422 가 칸 아래에
 * 붙는지를 본다(브라우저 검증을 하지 않는 환경과 같은 경로).
 *
 * 사이트 전역을 바꾼다: 운영자 추가 항목 3개(키 접두 `e2e490_`)와 항목 순서, 가입한 시험 회원 1명. afterAll 이 항목과 회원을
 * 지우고 시작 때의 항목 순서를 다시 저장한다. 지난 실행이 끊겨 남긴 접두 항목과 회원은 beforeAll 이 먼저 지운다.
 *
 * 축 요약(마커 아님 — 평문): case=signup_custom_required, signup_order_drag
 */
import type { APIRequestContext, Locator, Page } from '@playwright/test';
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import {
  E2E_EMAIL_PREFIX,
  E2E_FIELD_PREFIX,
  E2E_MEMBER_PASSWORD,
  MEMBER_FIELDS_PATH,
  createMemberField,
  deleteE2eMember,
  deleteMemberField,
  dismissCookieBanner,
  findMemberByEmail,
  gotoMemberSettingsTab,
  listMemberFields,
  openApiContext,
  purgeE2eMemberFields,
  purgeE2eMembers,
  restoreMemberFieldOrder,
  uniqueTag,
} from '../../fixtures/member-fields';

test.describe.configure({ mode: 'serial', timeout: 120_000 });

const tag = uniqueTag();

/** ① 화면으로 만드는 필수 항목 */
const requiredKey = `${E2E_FIELD_PREFIX}sg_${tag}`;
const requiredName = `E2E 가입 필수 ${tag}`;
const requiredValue = `E2E 값 ${tag}`;

/** ② 순서를 바꿀 두 항목 (API 로 만든다) */
const orderKeyA = `${E2E_FIELD_PREFIX}oa_${tag}`;
const orderKeyB = `${E2E_FIELD_PREFIX}ob_${tag}`;

/** ① 에서 가입하는 회원 */
const signupEmail = `${E2E_EMAIL_PREFIX}${tag}.signup@example.test`;

let api: APIRequestContext;
let adminToken: string;
let originalOrder: number[] = [];
const createdFieldIds: number[] = [];

test.beforeAll(async ({ playwright }, testInfo) => {
  // 이 훅은 회원 생성, 첫 로그인 비밀번호 변경, 설정 저장을 잇달아 부른다 — 느린 개발 사이트에서 기본 30초를 넘는다
  test.setTimeout(180_000);
  api = await openApiContext(playwright, testInfo);
  adminToken = issueToken('core.settings.read', 'core.settings.update', 'core.users.read', 'core.users.delete');
  await purgeE2eMemberFields(api, adminToken);
  await purgeE2eMembers(api, adminToken);
  originalOrder = (await listMemberFields(api, adminToken)).fields.map((field) => field.id);
});

test.afterAll(async () => {
  // 이 훅은 회원 생성, 첫 로그인 비밀번호 변경, 설정 저장을 잇달아 부른다 — 느린 개발 사이트에서 기본 30초를 넘는다
  test.setTimeout(180_000);
  const errors: unknown[] = [];
  const run = async (step: () => Promise<void>) => {
    try {
      await step();
    } catch (error) {
      errors.push(error);
    }
  };

  await run(async () => {
    const member = await findMemberByEmail(api, adminToken, signupEmail);
    if (member?.uuid) await deleteE2eMember(api, adminToken, String(member.uuid));
  });
  // 화면으로 만든 항목은 응답을 놓쳤을 수도 있으니 접두로 한 번 더 찾는다.
  for (const id of createdFieldIds) await run(() => deleteMemberField(api, adminToken, id));
  await run(() => purgeE2eMemberFields(api, adminToken));
  await run(() => restoreMemberFieldOrder(api, adminToken, originalOrder));
  await api?.dispose();

  if (errors.length > 0) {
    throw errors[0];
  }
});

/**
 * 체크 칸을 누르고 켜질 때까지 기다린다.
 *
 * 화면의 체크 칸은 `checked` 를 상태에 묶은 제어형이다. 누른 직후에는 React 가 옛 값으로 되돌렸다가 엔진 상태가 바뀐 뒤 켜지므로,
 * 누른 직후 상태를 재는 `locator.check()` 는 「상태가 바뀌지 않았다」로 실패한다.
 *
 * @param box 체크 칸(또는 라디오) 위치
 */
async function clickUntilChecked(box: Locator): Promise<void> {
  if (await box.isChecked()) return;
  await box.click();
  await expect(box).toBeChecked({ timeout: 5_000 });
}

/**
 * 가입 화면을 열고 가입 항목 계약(`GET /api/auth/register/fields`)을 받는다. 가입이 닫힌 사이트면 건너뛴다.
 *
 * @param page 비로그인 페이지
 * @returns 가입 화면이 받은 항목 계약
 */
async function openRegister(page: Page): Promise<Array<Record<string, any>>> {
  const fieldsResponse = page.waitForResponse(
    (r) => r.url().includes('/api/auth/register/fields') && r.request().method() === 'GET',
    { timeout: 60_000 },
  );
  await page.goto('/register');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await dismissCookieBanner(page);

  if (await page.locator('#registration_closed_card').isVisible().catch(() => false)) {
    test.skip(true, '가입이 닫힌 사이트라 가입 화면 시험을 잴 수 없다');
  }

  const body = await (await fieldsResponse).json();
  await page.locator('#registerForm').waitFor({ state: 'visible', timeout: 30_000 });

  return (body?.data?.fields ?? []) as Array<Record<string, any>>;
}

/**
 * 가입 화면에서 시험이 다루지 않는 필수 항목을 채운다(이름처럼 사이트 설정상 필수인 칸).
 *
 * 다른 작업자가 바꾼 사이트 설정 때문에 필수가 된 칸이 있어도 가입이 그 칸에서 막히지 않게 한다. 자동으로 채울 수 없는
 * 유형이면 그 사실을 오류로 알린다(조용히 통과시키지 않는다).
 *
 * @param page 가입 화면
 * @param fields 가입 항목 계약
 * @param skipKeys 시험이 직접 다루는 키
 */
async function fillOtherRequiredFields(page: Page, fields: Array<Record<string, any>>, skipKeys: string[]): Promise<void> {
  for (const field of fields) {
    if (!field.required || field.readonly || field.deferred || skipKeys.includes(field.key)) continue;
    const input = page.locator(`#registerForm [name="${field.name}"]`).first();
    switch (field.type) {
      case 'text':
      case 'textarea':
        await input.fill(field.key === 'name' ? `E2E 가입자 ${tag}` : `E2E ${tag}`);
        break;
      case 'phone':
        await input.fill('010-4900-0490');
        break;
      case 'email':
        await input.fill(`${E2E_EMAIL_PREFIX}${tag}.other@example.test`);
        break;
      case 'url':
        await input.fill('https://example.test/e2e490');
        break;
      case 'number':
        await input.fill(String(field.constraints?.min ?? 1));
        break;
      case 'date':
        await input.fill('2000-01-01');
        break;
      case 'datetime':
        await input.fill('2000-01-01T00:00');
        break;
      case 'boolean':
        await clickUntilChecked(input);
        break;
      case 'choice':
      case 'multi_choice': {
        // 입력 컴포넌트가 선택지 수와 제한 값에 따라 select, 라디오, 체크 칸 중 하나를 그린다(라디오 name 에는 고유 접두가 붙는다).
        // 항목 묶음(#field_{키}) 안의 실제 요소를 보고 첫 선택지를 고른다.
        const box = page.locator(`#registerForm #field_${field.key}`);
        const select = box.locator('select').first();
        if (await select.count() > 0) {
          await select.selectOption(String(field.options?.[0]?.value ?? ''));
        } else {
          await clickUntilChecked(box.locator('input[type="radio"], input[type="checkbox"]').first());
        }
        break;
      }
      default:
        throw new Error(`가입 화면의 필수 항목을 자동으로 채울 수 없습니다: ${field.key}(${field.type}) — 그 항목의 필수를 풀고 다시 돌린다`);
    }
  }
}

/**
 * @scenario case=signup_custom_required
 * @effects operator_adds_required_field_through_modal
 */
test('① 운영자가 「항목 추가」 창으로 필수 한 줄 글 항목을 만든다', async ({ page }) => {
  await authenticatePage(page, adminToken);
  await gotoMemberSettingsTab(page);

  const add = page.getByTestId('member-field-add');
  await expect(add).toBeEnabled({ timeout: 30_000 });
  await add.click();

  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible({ timeout: 10_000 });
  await dialog.getByTestId('member-field-type-tile-text').click();
  await dialog.getByTestId('member-field-form-next').click();

  await dialog.getByTestId('member-field-form-key').fill(requiredKey);
  // 다국어 이름 칸 — 읽기 이름은 「이름」 또는 「이름 (언어)」다. 값은 잠시 모았다가 상태로 올라가므로 저장 전에 짧게 기다린다.
  await dialog.getByRole('textbox', { name: /^이름( \(|$)/ }).first().fill(requiredName);
  await clickUntilChecked(dialog.getByTestId('member-field-form-required'));
  // 가입 뒤 저장 값을 목록 응답에서 읽으려고 「회원 목록 열」도 켠다.
  await clickUntilChecked(dialog.getByTestId('member-field-form-admin_list_column'));
  await page.waitForTimeout(700);

  const [created] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith(MEMBER_FIELDS_PATH) && r.request().method() === 'POST'),
    dialog.getByTestId('member-field-form-save').click(),
  ]);
  const createdBody = await created.json();
  expect(created.status(), JSON.stringify(createdBody).slice(0, 400)).toBe(201);
  createdFieldIds.push(Number(createdBody?.data?.field?.id));
  expect(createdBody?.data?.field?.key).toBe(requiredKey);
  expect(createdBody?.data?.field?.required).toBe(true);
  expect(createdBody?.data?.field?.show_on_signup).toBe(true);

  // 창이 닫히고 표를 다시 받아 새 행이 생긴다.
  await expect(dialog).toBeHidden({ timeout: 10_000 });
  await expect(page.getByTestId(`member-field-table-open-${requiredKey}`)).toBeVisible({ timeout: 20_000 });
});

/**
 * @scenario case=signup_custom_required
 * @effects operator_added_required_field_shows_on_signup, empty_required_field_blocks_submit_with_error_under_field, filled_required_field_completes_signup_with_value
 */
test('① 가입 화면에 그 칸이 별표와 함께 나오고, 비우면 칸 아래 오류, 채우면 가입된다', async ({ page }) => {
  const fields = await openRegister(page);

  // 가입 화면에 칸이 별표와 함께 나온다.
  const contract = fields.find((field) => field.key === requiredKey);
  expect(contract, '가입 항목 계약에 새 항목이 실린다').toBeTruthy();
  expect(contract?.name).toBe(`custom_fields.${requiredKey}`);
  expect(contract?.required).toBe(true);
  const row = page.locator(`#field_${requiredKey}`);
  await expect(row).toBeVisible({ timeout: 20_000 });
  await expect(row.locator('label')).toContainText(requiredName);
  await expect(row.locator('label .text-red-600')).toBeVisible();

  // 고정 칸과 다른 필수 칸을 채운다 — 새 항목만 비워 둔다.
  await page.locator('#registerForm input[name="email"]').fill(signupEmail);
  await page.locator('#registerForm input[name="password"]').fill(E2E_MEMBER_PASSWORD);
  await page.locator('#registerForm input[name="password_confirmation"]').fill(E2E_MEMBER_PASSWORD);
  await fillOtherRequiredFields(page, fields, [requiredKey]);
  await clickUntilChecked(page.locator('#registerForm input[name="agree_terms"]'));
  await clickUntilChecked(page.locator('#registerForm input[name="agree_privacy"]'));

  const input = page.locator(`#registerForm [name="custom_fields.${requiredKey}"]`);
  const submit = page.locator('#registerForm button[type="submit"]');

  // (가) 가입 폼은 브라우저 기본 검사를 끈다 — 말풍선 대신 서버 오류를 칸 아래에 보인다.
  expect(await page.locator('#registerForm').evaluate((form) => (form as HTMLFormElement).noValidate)).toBe(true);

  // (나) 서버 검증 — 비운 채 제출하면 요청이 나가 422 이고 오류는 그 칸 아래에만 붙는다.
  const [rejected] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/api/auth/register') && r.request().method() === 'POST'),
    submit.click(),
  ]);
  expect(rejected.status()).toBe(422);
  const errors = (await rejected.json())?.errors ?? {};
  const message = String((errors[`custom_fields.${requiredKey}`] ?? [])[0] ?? '');
  expect(message, `오류 키 custom_fields.${requiredKey} — 받은 키: ${Object.keys(errors).join(', ')}`).not.toBe('');
  const errorNode = page.locator(`#mf_error_${requiredKey}`);
  await expect(errorNode).toBeVisible({ timeout: 10_000 });
  await expect(errorNode).toHaveText(message);
  await expect(input).toHaveAttribute('aria-invalid', 'true');
  // 칸에 속한 오류는 위쪽 상자에 다시 뜨지 않는다.
  await expect(page.locator('#register_orphan_errors', { hasText: message })).toHaveCount(0);
  // 422 뒤에도 입력값이 남아 있다.
  await expect(page.locator('#registerForm input[name="email"]')).toHaveValue(signupEmail);

  // (다) 채우고 가입 — 201 과 함께 로그인 화면으로 간다.
  await input.fill(requiredValue);
  const [accepted] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/api/auth/register') && r.request().method() === 'POST'),
    submit.click(),
  ]);
  const acceptedBody = await accepted.json();
  expect(accepted.status(), JSON.stringify(acceptedBody?.errors ?? acceptedBody?.message ?? '').slice(0, 400)).toBe(201);
  const sent = accepted.request().postDataJSON();
  expect(sent?.custom_fields?.[requiredKey]).toBe(requiredValue);
  await page.waitForURL((url) => url.pathname === '/login', { timeout: 20_000 });

  // 값이 회원에 저장됐다(「회원 목록 열」로 켠 항목은 목록 행의 custom_fields 에 실린다).
  const member = await findMemberByEmail(api, adminToken, signupEmail);
  expect(member, '가입한 회원이 목록에 있다').toBeTruthy();
  expect(member?.custom_fields?.[requiredKey]).toBe(requiredValue);
});

/**
 * @scenario case=signup_order_drag
 * @effects dragged_order_is_saved_by_order_api
 */
test('② 표에서 손잡이로 끌어 두 항목의 순서를 바꾸면 끈 순서가 저장된다', async ({ page }) => {
  const a = await createMemberField(api, adminToken, { key: orderKeyA, type: 'text', name: { ko: `E2E 순서 A ${tag}`, en: `E2E order A ${tag}` }, show_on_signup: true });
  createdFieldIds.push(a.id);
  const b = await createMemberField(api, adminToken, { key: orderKeyB, type: 'text', name: { ko: `E2E 순서 B ${tag}`, en: `E2E order B ${tag}` }, show_on_signup: true });
  createdFieldIds.push(b.id);

  await authenticatePage(page, adminToken);
  await gotoMemberSettingsTab(page);

  const handleA = page.getByTestId(`member-field-table-handle-${orderKeyA}`);
  const handleB = page.getByTestId(`member-field-table-handle-${orderKeyB}`);
  await expect(handleA).toBeVisible({ timeout: 20_000 });
  await expect(handleB).toBeVisible();

  // 새로 만든 두 항목은 표 맨 뒤에 A, B 순으로 붙어 있다. B 를 A 위로 끈다(사람처럼 가운데로 스크롤한 뒤).
  await handleB.evaluate((el) => el.scrollIntoView({ block: 'center' }));
  const from = (await handleB.boundingBox())!;
  const to = (await handleA.boundingBox())!;
  expect(to.y).toBeLessThan(from.y);

  const orderResponse = page.waitForResponse(
    (r) => r.url().endsWith(`${MEMBER_FIELDS_PATH}/order`) && r.request().method() === 'PUT',
    { timeout: 20_000 },
  );
  const fx = from.x + from.width / 2;
  const fy = from.y + from.height / 2;
  const ty = to.y + to.height / 2 - to.height;
  await page.mouse.move(fx, fy);
  await page.mouse.down();
  for (let step = 1; step <= 12; step++) {
    await page.mouse.move(fx, fy + ((ty - fy) * step) / 12);
  }
  await page.mouse.up();

  const saved = await orderResponse;
  expect(saved.status()).toBe(200);
  const order = (saved.request().postDataJSON()?.order ?? []) as number[];
  expect(order.indexOf(b.id), '끈 항목(B)이 앞으로 간다').toBeGreaterThanOrEqual(0);
  expect(order.indexOf(b.id)).toBeLessThan(order.indexOf(a.id));

  // 표가 서버 순서로 다시 그려져도 B 가 A 위다.
  await expect
    .poll(async () => {
      const ya = (await handleA.boundingBox())?.y ?? 0;
      const yb = (await handleB.boundingBox())?.y ?? 0;
      return yb < ya;
    }, { timeout: 15_000 })
    .toBe(true);
});

/**
 * @scenario case=signup_order_drag
 * @effects signup_screen_follows_saved_order
 */
test('② 가입 화면의 칸 순서가 저장한 순서를 따른다', async ({ page }) => {
  const fields = await openRegister(page);
  const contractOrder = fields.map((field) => field.key);
  expect(contractOrder.indexOf(orderKeyB)).toBeGreaterThanOrEqual(0);
  expect(contractOrder.indexOf(orderKeyB)).toBeLessThan(contractOrder.indexOf(orderKeyA));

  await expect(page.locator(`#field_${orderKeyA}`)).toBeVisible({ timeout: 20_000 });
  const domOrder = await page
    .locator('#member_fields_root [data-member-field]')
    .evaluateAll((els) => els.map((el) => el.getAttribute('data-member-field') ?? ''));
  expect(domOrder.indexOf(orderKeyB)).toBeGreaterThanOrEqual(0);
  expect(domOrder.indexOf(orderKeyB), `화면 순서: ${domOrder.join(', ')}`).toBeLessThan(domOrder.indexOf(orderKeyA));
});
