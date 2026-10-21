/**
 * 사용자 화면 모달 입력칸 — 매우 빠른 연속 입력(키 간격 12ms)에서도 글자를 잃지 않는다 (7.1.1).
 * 템플릿 sirsoft-basic (유저 화면 `/mypage/addresses` 의 [새 주소 추가] 창).
 *
 * 결함(7.1.0~): `_global` 값을 쓰는 모달 입력칸에 아주 빠르게 치면, 다시 그리기가 미뤄지는 사이 React 가 제어 입력을
 *   직전 값으로 되돌려 한 글자가 빠졌다(「받는이」 → 「받는」 등, 12ms 4/50~14/100, 30ms 이상·모달 밖 0).
 *
 * 판정: 받는 분·배송지명·상세 주소 칸에 같은 문장을 키 간격 12ms 로 N회 다시 쳐서 매번 그대로 남는지 본다. 경합 결함이라 1회
 *   통과는 근거가 아니다 — 회차 수를 두고(기본 20) 잃은 회차가 0 이어야 한다. 저장 단추는 누르지 않고 창을 닫는다.
 *   쓰기 요청은 모두 가로챈다(서버 불변). 계정은 실행 환경변수로만 받는다.
 *
 * @scenario-manifest tests/scenarios/e2e-modal-input-fast-typing.yaml
 *
 * 실행:
 *   $env:PLAYWRIGHT_BASE_URL='https://example.com'; npm run test:e2e -- modal-input-fast-typing --workers=1
 *   (회차 수: $env:G7_E2E_F48_ROUNDS='50')
 */
import { test, expect, type Locator, type Page } from '@playwright/test';
import {
  ACCOUNT_SKIP_REASON,
  authenticateWith,
  dismissCookieBanner,
  envAccountToken,
  guardWrites,
  hasEnvAccount,
  waitForSpa,
} from '../fixtures/env-account';

test.describe.configure({ timeout: 300_000 });

/** 회차 수 */
const ROUNDS = Number(process.env.G7_E2E_F48_ROUNDS || 20);

/** 키 간격(ms) — 실측 재현 조건 */
const KEY_DELAY_MS = 12;

/** 공백·`&`·한글이 섞인 문장(실측에서 빠진 글자 종류) */
const TEXT = 'E2E & 받는이 ab cd 12';

/**
 * [새 주소 추가] 창을 연다.
 *
 * @param page Playwright 페이지
 * @returns 창 입력칸을 찾을 범위
 */
async function openAddModal(page: Page): Promise<Locator> {
  await page.getByRole('button', { name: '새 주소 추가' }).first().click();
  // 창 안 입력칸은 이 화면에서 창이 열렸을 때만 있다 — 창 컨테이너 역할(role)에 기대지 않고 칸 이름으로 찾는다
  await expect(page.getByRole('textbox', { name: '받는 분', exact: true })).toBeVisible({ timeout: 15_000 });

  return page.locator('body');
}

/**
 * 칸을 비우고 키 간격 12ms 로 문장을 친 뒤 값을 읽는다.
 *
 * @param input 입력칸
 * @returns 친 뒤의 값
 */
async function retype(input: Locator): Promise<string> {
  await input.click();
  await input.press('Control+A');
  await input.press('Delete');
  await expect(input).toHaveValue('');
  await input.pressSequentially(TEXT, { delay: KEY_DELAY_MS });
  // 미뤄진 다시 그리기가 끝날 때까지 — 값이 그 사이 되돌려지면 여기서 드러난다
  await input.page().waitForTimeout(300);

  return input.inputValue();
}

test.describe('모달 입력칸 빠른 연속 입력', () => {
  test.skip(!hasEnvAccount(), ACCOUNT_SKIP_REASON);

  /**
   * @scenario case=address_modal_fast_typing
   * @effects modal_input_keeps_every_char_at_12ms, modal_input_no_server_write
   */
  test(`배송지 [새 주소 추가] — 받는 분·배송지명·상세 주소 ${ROUNDS}회 × 12ms 입력이 그대로 남는다`, async ({ page, request }) => {
    const token = await envAccountToken(request);
    await authenticateWith(page, token!);
    const blocked = await guardWrites(page);

    await page.goto('/mypage/addresses');
    await waitForSpa(page);
    await dismissCookieBanner(page);
    const dialog = await openAddModal(page);

    const fields: Array<{ name: string; input: Locator }> = [
      { name: '받는 분', input: dialog.getByRole('textbox', { name: '받는 분', exact: true }) },
      { name: '배송지명', input: dialog.getByRole('textbox', { name: '배송지명', exact: true }) },
      { name: '상세 주소', input: dialog.getByRole('textbox', { name: '상세 주소', exact: true }) },
    ];
    const losses: string[] = [];
    for (let i = 0; i < ROUNDS; i += 1) {
      for (const f of fields) {
        if ((await f.input.count()) === 0) continue;
        const value = await retype(f.input);
        if (value !== TEXT) losses.push(`${i + 1}회 ${f.name}: 「${value}」`);
      }
    }

    expect(losses, `${ROUNDS}회 × 칸별 손실`).toEqual([]);
    await page.keyboard.press('Escape');
    expect(blocked.filter((b) => b.includes('/addresses')), '배송지 저장 요청 없음').toEqual([]);
  });

  /**
   * @scenario case=report_modal_fast_typing
   * @effects modal_input_keeps_every_char_at_12ms, modal_input_no_server_write
   */
  test(`게시글 신고 창 — 상세 사유 ${ROUNDS}회 × 12ms 입력이 그대로 남는다`, async ({ page, request }) => {
    const token = await envAccountToken(request);
    await authenticateWith(page, token!);
    const blocked = await guardWrites(page);
    const auth = { Accept: 'application/json', Authorization: `Bearer ${token}` };

    // 신고 단추가 보이는 글(다른 회원의 글·신고 사용 게시판·아직 신고 안 함)을 공개 목록에서 찾는다 — 없으면 사유와 함께 건너뛴다
    const boards = ((await (await request.get('/api/modules/sirsoft-board/boards', { headers: auth })).json())?.data ?? []) as Array<{ slug: string }>;
    let found = false;
    for (const b of boards.slice(0, 5)) {
      const res = await request.get(`/api/modules/sirsoft-board/boards/${b.slug}/posts`, { headers: auth });
      if (!res.ok()) continue;
      const posts = ((await res.json())?.data?.data ?? []) as Array<{ id: number }>;
      for (const p of posts.slice(0, 3)) {
        await page.goto(`/board/${b.slug}/${p.id}`);
        await waitForSpa(page);
        const reportButton = page.getByRole('button', { name: '신고', exact: true });
        try {
          await reportButton.first().waitFor({ state: 'visible', timeout: 8_000 });
        } catch {
          continue;
        }
        await dismissCookieBanner(page);
        await reportButton.first().click();
        found = true;
        break;
      }
      if (found) break;
    }
    test.skip(!found, '신고 단추가 보이는 게시글(다른 회원 글·신고 사용 게시판)이 없어 잴 수 없다');

    const detail = page.getByRole('textbox', { name: '상세 사유', exact: true });
    await expect(detail).toBeVisible({ timeout: 15_000 });
    const losses: string[] = [];
    for (let i = 0; i < ROUNDS; i += 1) {
      const value = await retype(detail);
      if (value !== TEXT) losses.push(`${i + 1}회: 「${value}」`);
    }

    expect(losses, `${ROUNDS}회 손실`).toEqual([]);
    await page.keyboard.press('Escape');
    expect(blocked.filter((b) => b.includes('/reports')), '신고 접수 요청 없음').toEqual([]);
  });
});
