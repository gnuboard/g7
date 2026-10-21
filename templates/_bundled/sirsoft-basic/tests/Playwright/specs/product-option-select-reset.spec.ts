/**
 * 상품 상세 2단 옵션 — 줄이 담기면 두 칸이 비고, 줄을 만진 뒤에도 앞 선택이 되살아나지 않는다 (7.1.1).
 * 템플릿 sirsoft-basic (유저 화면 `/shop/products/{id}` 의 구매 카드, 비회원).
 *
 * 결함: 옵션 2단(색상·사이즈) 상품에서 두 칸을 골라 줄이 담기면 핸들러가 `_local.currentSelection` 을 `{}` 로 비우는데,
 *   간헐적으로 상태는 `{}` 인데 색상 칸만 앞 값을 보이는 채로 남았다. 그 상태에서 줄을 다시 그리는 동작(수량 「+」·추가옵션
 *   변경 등)을 하면 `G7Core.state.getLocal().currentSelection` 이 그 칸 값으로 되살아났고, 이어 윗칸만 골라도 옛 아랫칸 값과
 *   합쳐져 고르지 않은 조합이 담길 수 있었다. 원인은 엔진의 지역 상태 두 저장소 병합(engine-v1.81.2 에서 엔진이 고쳤다).
 *   수정 전 번들 실측(비회원): 추가옵션 변경 61회 중 5회(8%), 전 동작 106회 중 「줄 추가 직후 칸 앞 값」 6 · 「되살아남」 6 ·
 *   「2번째 줄 뒤 칸 앞 값」 5. 결정적으로 재현되는 간격은 없다(150ms~2.5초 모두 비슷한 발생률).
 *
 * 간헐 결함이므로 동작마다 같은 조작을 N 회(기본 10, `G7_E2E_OPTION_REPEAT`) 새 탭으로 되풀이하고, 한 번이라도 아래 판정이 어긋나면
 *   그 시험을 실패로 둔다. 발생 횟수는 시험 주석(annotation)과 표준 출력에 남긴다. 판정(출시 전 실측과 같은 조작 순서):
 *   - 줄이 담긴 직후·줄 동작 뒤·두 번째 줄이 담긴 뒤: 두 칸이 「{윗칸 이름} 선택」·「상위 옵션을 먼저 선택하세요」 이고
 *     `getLocal().currentSelection` 이 `{}`
 *   - 윗칸만 다시 골랐을 때: 줄 수·내용이 그대로이고 `currentSelection` 이 그 윗칸 값 하나뿐, 아랫칸은 「{아랫칸 이름} 선택」
 *   - 아랫칸까지 고르면 그 조합 한 줄만 더 담긴다
 *
 * 데이터: 서버의 실제 옵션 2단 상품을 공개 API(GET)로 찾는다(`G7_E2E_PRODUCT` 로 상품 id 를 고정할 수 있다). 없으면 사유와 함께
 *   건너뛴다. 추가옵션 축은 기본값이 아닌 선택지가 있는 추가옵션 그룹을 가진 상품일 때만 돈다. 이 화면은 옵션을 골라도 장바구니에
 *   쓰지 않지만(줄은 지역 상태), GET·HEAD 가 아닌 `/api/` 요청은 모두 가로채 가짜 200 으로 답한다 — 서버 장바구니·기록 불변.
 *
 * 수정 전 대조: `G7_E2E_ENGINE_BUNDLE=<수정 전 엔진 번들 경로>` 로 돌리면 이 브라우저만 그 번들로 바꿔 끼운다
 *   (`tests/Playwright/fixtures/env-account.ts`). 간헐이라 수정 전 번들에서도 한 시험이 green 일 수 있다 — 파일 전체(동작 5종 × N)로 본다.
 *
 * @scenario-manifest tests/scenarios/e2e-product-option-select-reset.yaml
 *
 * 실행:
 *   $env:PLAYWRIGHT_BASE_URL='https://example.com'; [$env:G7_E2E_OPTION_REPEAT='20']
 *   npx playwright test --config=tests/Playwright/playwright.config.ts tests/Playwright/specs/product-option-select-reset.spec.ts --workers=1
 */
import { test, expect, type APIRequestContext, type BrowserContext, type Page } from '@playwright/test';
import { guardWrites, waitForSpa } from '../fixtures/env-account';

/** 동작마다 되풀이할 횟수 */
const REPEAT = Math.max(1, Number(process.env.G7_E2E_OPTION_REPEAT ?? 10) || 10);

/** 칸을 고른 뒤·줄 동작 뒤 판정까지 기다리는 시간(ms) — 기본 간격(GAP·C2S·ACT_WAIT 1~1.1초)과 같다 */
const SETTLE_MS = Math.max(0, Number(process.env.G7_E2E_OPTION_SETTLE_MS ?? 1100) || 0);

/** 판정이 맞을 때까지 더 기다리는 상한(ms) — 남은 앞 값은 머무는 결함이라 이 안에 풀리지 않는다 */
const CHECK_TIMEOUT_MS = 2500;

/** 상품 API */
const PRODUCTS_API = '/api/modules/sirsoft-ecommerce/products';

/** 줄 동작 */
type LineAction = 'none' | 'plus' | 'minus' | 'addopt' | 'delete';

/** 고를 조합 두 개와 화면 문구 */
interface OptionPlan {
  /** 상품 상세 경로 */
  url: string;
  /** 윗칸·아랫칸 그룹 이름(화면 로케일) */
  groups: [string, string];
  /** 첫 줄로 담을 조합 [윗칸, 아랫칸] */
  first: [string, string];
  /** 두 번째 줄로 담을 조합 — 윗칸·아랫칸 모두 첫 조합과 다르다 */
  second: [string, string];
  /** 줄 안 추가옵션 — 기본값이 아닌 선택지(없으면 null) */
  addOption: { groupId: number; valueId: number; valueName: string } | null;
}

/** 한 시점의 화면·상태 */
interface Snapshot {
  /** `getLocal().currentSelection` JSON */
  selection: string;
  /** 담긴 줄 — `윗칸/아랫칸` */
  rows: string[];
  /** 담긴 줄의 수량 */
  quantities: number[];
  /** 첫 줄의 추가옵션 선택 */
  firstAddSelections: Record<string, unknown> | null;
  /** 두 칸에 보이는 글자 */
  cells: string[];
}

let planPromise: Promise<OptionPlan | null> | null = null;

/**
 * 서버의 실제 옵션 2단 상품을 공개 API 로 찾는다(GET 만).
 *
 * 조건: 판매 중 · 옵션 그룹 2개 · 구매 가능한 조합(재고 3 이상·활성·품절 아님) 중 윗칸·아랫칸이 모두 다른 두 조합 · 최소 구매 1 ·
 * 1회 최대 구매가 없거나 2 이상(수량 「+」). 추가옵션 그룹에 기본값 아닌 선택지가 있는 상품을 앞세운다.
 *
 * @param request Playwright API 요청 컨텍스트
 * @returns 고를 조합(없으면 null)
 */
async function findOptionPlan(request: APIRequestContext): Promise<OptionPlan | null> {
  const headers = { Accept: 'application/json', 'Accept-Language': 'ko-KR,ko;q=0.9' };
  const pinned = process.env.G7_E2E_PRODUCT;
  let ids: string[] = [];
  if (pinned) {
    ids = [pinned];
  } else {
    const list = await request.get(`${PRODUCTS_API}?per_page=40`, { headers });
    if (!list.ok()) return null;
    const json = await list.json();
    const rows: any[] = json?.data?.data ?? json?.data ?? [];
    ids = rows.filter((r) => r?.sales_status === 'on_sale' && r?.has_options).map((r) => String(r.id));
  }

  let fallback: OptionPlan | null = null;
  for (const id of ids) {
    const res = await request.get(`${PRODUCTS_API}/${id}`, { headers });
    if (!res.ok()) continue;
    const d = (await res.json())?.data;
    const groups: any[] = d?.option_groups ?? [];
    if (d?.sales_status !== 'on_sale' || groups.length !== 2 || (d?.options ?? []).length < 2) continue;
    if (Number(d?.min_purchase_qty ?? 1) > 1) continue;
    const maxQty = Number(d?.max_purchase_qty ?? 0);
    if (maxQty > 0 && maxQty < 2) continue;
    const g0 = String(groups[0]?.name_localized ?? '');
    const g1 = String(groups[1]?.name_localized ?? '');
    if (!g0 || !g1) continue;
    const buyable = (d.options as any[])
      .filter((o) => o?.is_active !== false && !o?.is_sold_out && Number(o?.stock_quantity ?? 0) >= 3)
      .map((o) => [o?.option_values_localized?.[g0], o?.option_values_localized?.[g1]] as [string, string])
      .filter(([a, b]) => !!a && !!b);
    const first = buyable[0];
    const second = first ? buyable.find(([a, b]) => a !== first[0] && b !== first[1]) : undefined;
    if (!first || !second) continue;

    let addOption: OptionPlan['addOption'] = null;
    for (const group of (d?.additional_options ?? []) as any[]) {
      const value = (group?.values ?? []).find((v: any) => v?.is_active !== false && !v?.is_default && !v?.allow_custom_text);
      if (value) {
        addOption = { groupId: Number(group.id), valueId: Number(value.id), valueName: String(value.name) };
        break;
      }
    }
    const plan: OptionPlan = { url: `/shop/products/${d.id}`, groups: [g0, g1], first: [...first], second: [...second], addOption };
    if (addOption) return plan;
    fallback ??= plan;
  }

  return fallback;
}

/**
 * 고를 조합을 한 번만 찾아 둔다(시험마다 같은 상품).
 *
 * @param request Playwright API 요청 컨텍스트
 * @returns 고를 조합(없으면 null)
 */
function optionPlan(request: APIRequestContext): Promise<OptionPlan | null> {
  planPromise ??= findOptionPlan(request);

  return planPromise;
}

/**
 * 정규식 메타문자를 이스케이프한다.
 *
 * @param text 원문
 * @returns 이스케이프한 문자열
 */
function escapeRegExp(text: string): string {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * 쿠키 동의 배너가 지금 떠 있으면 닫는다(기다리지 않는다 — 같은 브라우저 문맥에서는 처음 한 번만 뜬다).
 *
 * @param page Playwright 페이지
 */
async function dismissBannerIfShown(page: Page): Promise<void> {
  const button = page.getByRole('button', { name: /동의하지 않고 계속하기|모두 동의|모두 허용/ }).filter({ visible: true }).first();
  if (await button.count()) await button.click().catch(() => {});
}

/**
 * 새 탭으로 상품 상세를 열고 구매 카드 옵션 칸이 설 때까지 기다린다.
 *
 * @param context 브라우저 문맥
 * @param plan 고를 조합
 * @returns 연 페이지
 */
async function openProduct(context: BrowserContext, plan: OptionPlan): Promise<Page> {
  // 개발 서버가 잠깐 5xx(예: 컴포넌트 manifest 500 → 「초기화 실패」 화면)를 내면 그 탭은 결함 판정 대상이 아니다 — 새 탭으로 두 번까지 다시 연다
  for (let attempt = 1; ; attempt += 1) {
    const page = await context.newPage();
    try {
      await guardWrites(page);
      await page.goto(plan.url);
      await waitForSpa(page);
      await expect(page.locator('[data-testid="option-group-0"]').filter({ visible: true }).first()).toBeVisible({ timeout: 30_000 });
    } catch (error) {
      await page.close();
      if (attempt >= 3) throw error;
      continue;
    }
    await page.waitForTimeout(1500); // 데이터소스·계산값 정착(출시 전 실측과 같은 대기)
    await dismissBannerIfShown(page);

    return page;
  }
}

/**
 * 옵션 칸 하나를 연다.
 *
 * @param page Playwright 페이지
 * @param index 칸 번호(0 윗칸, 1 아랫칸)
 * @returns 칸 단추 locator
 */
function cellButton(page: Page, index: number) {
  return page.locator(`[data-testid="option-group-${index}"]`).filter({ visible: true }).first().locator('button[aria-haspopup="listbox"]');
}

/**
 * 옵션 칸에서 값을 고르고 정착 시간만큼 기다린다.
 *
 * @param page Playwright 페이지
 * @param index 칸 번호
 * @param value 고를 값(화면 라벨과 같다)
 */
async function pick(page: Page, index: number, value: string): Promise<void> {
  const trigger = cellButton(page, index);
  await expect(trigger, `옵션 칸 ${index} 이 열린다`).toBeEnabled({ timeout: 10_000 });
  await trigger.click();
  await page.getByRole('listbox').getByRole('option', { name: value, exact: true }).first().click();
  await page.waitForTimeout(SETTLE_MS);
}

/**
 * 지금 화면·상태를 읽는다.
 *
 * @param page Playwright 페이지
 * @returns 화면·상태
 */
async function snapshot(page: Page): Promise<Snapshot> {
  return page.evaluate(() => {
    const local = (window as any).G7Core?.state?.getLocal?.() ?? {};
    const items: any[] = local.selectedOptionItems ?? [];
    const visible = (el: Element) => !!(el as HTMLElement).offsetParent;
    const cells = [0, 1].map((i) => {
      const box = [...document.querySelectorAll(`[data-testid="option-group-${i}"]`)].find(visible);
      const button = box?.querySelector('button[aria-haspopup="listbox"]') as HTMLElement | null;

      return (button?.innerText ?? '').replace(/\s+/g, ' ').trim();
    });

    return {
      selection: JSON.stringify(local.currentSelection ?? null),
      rows: items.map((item) => Object.values(item?.options ?? {}).join('/')),
      quantities: items.map((item) => Number(item?.quantity ?? 0)),
      firstAddSelections: items[0]?.additionalOptionSelections ?? null,
      cells,
    };
  });
}

/**
 * 판정이 맞을 때까지 잠깐 다시 읽는다 — 끝내 어긋나면 마지막 화면·상태와 함께 false 를 돌려준다(던지지 않는다).
 *
 * @param page Playwright 페이지
 * @param ok 판정
 * @returns 마지막으로 읽은 화면·상태와 판정
 */
async function settle(page: Page, ok: (s: Snapshot) => boolean): Promise<{ snap: Snapshot; ok: boolean }> {
  const deadline = Date.now() + CHECK_TIMEOUT_MS;
  let snap = await snapshot(page);
  while (!ok(snap) && Date.now() < deadline) {
    await page.waitForTimeout(150);
    snap = await snapshot(page);
  }

  return { snap, ok: ok(snap) };
}

/**
 * 줄 하나에 동작을 한다.
 *
 * @param page Playwright 페이지
 * @param action 줄 동작
 * @param plan 고를 조합
 */
async function doLineAction(page: Page, action: LineAction, plan: OptionPlan): Promise<void> {
  const increase = page.locator('button[aria-label="수량 증가"]').filter({ visible: true }).first();
  const decrease = page.locator('button[aria-label="수량 감소"]').filter({ visible: true }).first();
  if (action === 'plus') await increase.click();
  if (action === 'minus') {
    await increase.click();
    await page.waitForTimeout(700);
    await decrease.click();
  }
  if (action === 'addopt' && plan.addOption) {
    const { groupId, valueName } = plan.addOption;
    await page.locator(`[data-testid="add-option-0-${groupId}"]`).filter({ visible: true }).first().locator('button[aria-haspopup="listbox"]').click();
    await page.getByRole('listbox').getByRole('option', { name: new RegExp(`^${escapeRegExp(valueName)}`) }).first().click();
  }
  if (action === 'delete') await page.locator('button[aria-label="삭제"]').filter({ visible: true }).first().click();
  if (action !== 'none') await page.waitForTimeout(SETTLE_MS);
}

/**
 * 한 회차 — 첫 조합으로 줄 → 동작 → 윗칸만 → 아랫칸까지. 어긋난 판정을 문장으로 돌려준다.
 *
 * @param page Playwright 페이지
 * @param action 줄 동작
 * @param plan 고를 조합
 * @returns 어긋난 판정(없으면 빈 배열)
 */
async function runOnce(page: Page, action: LineAction, plan: OptionPlan): Promise<string[]> {
  const [g0, g1] = plan.groups;
  const firstRow = plan.first.join('/');
  const secondRow = plan.second.join('/');
  const blankCells = [`${g0} 선택`, '상위 옵션을 먼저 선택하세요'];
  const isBlank = (s: Snapshot) => s.selection === '{}' && s.cells[0] === blankCells[0] && s.cells[1] === blankCells[1];
  const sameRows = (a: string[], b: string[]) => a.length === b.length && a.every((x, i) => x === b[i]);
  const issues: string[] = [];
  const note = (step: string, snap: Snapshot) =>
    issues.push(`${step}: currentSelection=${snap.selection} 칸=[${snap.cells.join(' | ')}] 줄=[${snap.rows.join(', ')}]`);

  // ① 첫 조합으로 줄 하나
  await pick(page, 0, plan.first[0]);
  await pick(page, 1, plan.first[1]);
  const added = await settle(page, (s) => isBlank(s) && sameRows(s.rows, [firstRow]));
  if (!added.ok) note('줄 추가 직후', added.snap);

  // ② 줄 동작 — 동작이 실제로 일어났는지는 조작 실패라 바로 던진다(결함 판정과 섞지 않는다)
  await doLineAction(page, action, plan);
  const expectedRows = action === 'delete' ? [] : [firstRow];
  const acted = await settle(page, (s) => isBlank(s) && sameRows(s.rows, expectedRows));
  if (action === 'plus') expect(acted.snap.quantities[0], '「+」 뒤 수량 2').toBe(2);
  if (action === 'minus') expect(acted.snap.quantities[0], '「+」·「-」 뒤 수량 1').toBe(1);
  if (action === 'addopt') expect(String(acted.snap.firstAddSelections?.[String(plan.addOption!.groupId)] ?? ''), '추가옵션이 바뀌었다').toBe(String(plan.addOption!.valueId));
  if (action === 'delete') expect(acted.snap.rows, '줄 삭제').toEqual([]);
  if (!acted.ok) note(`${action} 뒤`, acted.snap);

  // ③ 윗칸만 다시 — 줄이 늘거나 바뀌지 않고, 선택은 윗칸 하나뿐
  const rowsBefore = acted.snap.rows;
  await pick(page, 0, plan.second[0]);
  const upper = await settle(
    page,
    (s) =>
      sameRows(s.rows, rowsBefore) &&
      s.selection === JSON.stringify({ [g0]: plan.second[0] }) &&
      s.cells[0] === plan.second[0] &&
      s.cells[1] === `${g1} 선택`,
  );
  if (!upper.ok) note('윗칸만 고른 뒤', upper.snap);

  // ④ 아랫칸까지 — 그 조합 한 줄만 더 담기고 두 칸이 빈다
  await pick(page, 1, plan.second[1]);
  const final = await settle(page, (s) => isBlank(s) && sameRows(s.rows, [...rowsBefore, secondRow]));
  if (!final.ok) note('두 번째 줄 뒤', final.snap);

  return issues;
}

/**
 * 동작 하나를 N 회 되풀이하고, 어긋난 회차를 모아 한 번에 판정한다.
 *
 * @param context 브라우저 문맥
 * @param action 줄 동작
 * @param plan 고를 조합
 */
async function repeatAction(context: BrowserContext, action: LineAction, plan: OptionPlan): Promise<void> {
  const failures: string[] = [];
  for (let round = 1; round <= REPEAT; round += 1) {
    const page = await openProduct(context, plan);
    try {
      const issues = await runOnce(page, action, plan);
      if (issues.length) failures.push(`${round}회차 — ${issues.join(' / ')}`);
    } finally {
      await page.close();
    }
  }
  const summary = `${action}: ${REPEAT}회 중 ${failures.length}회 어긋남 (${plan.url} ${plan.first.join('/')} → ${plan.second.join('/')})`;
  test.info().annotations.push({ type: '반복 결과', description: summary });
  console.log(`[product-option-select-reset] ${summary}`);
  expect(failures, `${summary}\n${failures.join('\n')}`).toEqual([]);
}

test.describe('상품 상세 2단 옵션 — 줄 추가 뒤 옵션 칸 비우기', () => {
  test.beforeEach(async ({ request }) => {
    test.setTimeout(REPEAT * 60_000 + 60_000);
    const plan = await optionPlan(request);
    test.skip(!plan, '옵션 2단(구매 가능한 두 조합) 판매 중 상품이 공개 목록에 없다 — 잴 수 없다');
  });

  /**
   * @scenario action=none
   * @effects option_cells_cleared_after_line_added, upper_only_does_not_add_line, second_line_only_chosen_combo
   */
  test('줄 추가 — 두 칸이 비고 currentSelection 이 {} 이며, 윗칸만 골라서는 줄이 늘지 않는다', async ({ context, request }) => {
    await repeatAction(context, 'none', (await optionPlan(request))!);
  });

  /**
   * @scenario action=plus
   * @effects option_cells_cleared_after_line_added, line_action_does_not_revive_selection, upper_only_does_not_add_line, second_line_only_chosen_combo
   */
  test('줄 「+」 뒤에도 칸·currentSelection 이 비어 있고 윗칸만으로 줄이 늘지 않는다', async ({ context, request }) => {
    await repeatAction(context, 'plus', (await optionPlan(request))!);
  });

  /**
   * @scenario action=minus
   * @effects option_cells_cleared_after_line_added, line_action_does_not_revive_selection, upper_only_does_not_add_line, second_line_only_chosen_combo
   */
  test('줄 「+」·「-」 뒤에도 칸·currentSelection 이 비어 있고 윗칸만으로 줄이 늘지 않는다', async ({ context, request }) => {
    await repeatAction(context, 'minus', (await optionPlan(request))!);
  });

  /**
   * @scenario action=addopt
   * @effects option_cells_cleared_after_line_added, line_action_does_not_revive_selection, upper_only_does_not_add_line, second_line_only_chosen_combo
   */
  test('줄 추가옵션 변경 뒤에도 칸·currentSelection 이 비어 있고 윗칸만으로 줄이 늘지 않는다', async ({ context, request }) => {
    const plan = (await optionPlan(request))!;
    test.skip(!plan.addOption, '고른 상품에 기본값 아닌 추가옵션 선택지가 없다 — 추가옵션 축은 잴 수 없다');
    await repeatAction(context, 'addopt', plan);
  });

  /**
   * @scenario action=delete
   * @effects option_cells_cleared_after_line_added, line_action_does_not_revive_selection, upper_only_does_not_add_line, second_line_only_chosen_combo
   */
  test('줄 삭제 뒤에도 칸·currentSelection 이 비어 있고 윗칸만으로 줄이 담기지 않는다', async ({ context, request }) => {
    await repeatAction(context, 'delete', (await optionPlan(request))!);
  });
});
