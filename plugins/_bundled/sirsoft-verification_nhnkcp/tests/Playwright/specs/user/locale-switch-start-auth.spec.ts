/**
 * E2E: 언어 전환 후 본인확인 시작 버튼
 *
 * 로케일을 바꾸면 코어가 ActionDispatcher 를 새로 만들고, 확장은
 * `window.__[Plugin].initPlugin()` 재호출로 핸들러를 다시 등록한다. 이 진입점을
 * 노출하지 않으면 언어를 한 번 바꾼 뒤부터 「본인확인 시작」이 완전히 무반응이 된다 —
 * 콘솔 에러도, 토스트도, 네트워크 요청도 남지 않아 단위 테스트로는 잡히지 않는다.
 *
 * 실제로 언어를 바꾼 뒤 가입을 제출해 인증창(팝업)이 열리는 데까지 확인한다.
 * KCP 표준창 내부는 자동화 대상이 아니다 — 통신사 인증 완주는 실 휴대폰이 필요하다.
 *
 * @scenario locale=switched,environment=desktop
 * @effects locale_switch_preserves_start_auth_handler
 */
import { test, expect } from '@playwright/test';

const REGISTER_PATH = '/register';
const KCP_STANDARD_WINDOW = /testcert\.kcp\.co\.kr|cert\.kcp\.co\.kr/;

/** 매 실행마다 다른 이메일 — 가입 중복으로 428 이전 단계에서 막히지 않게 한다. */
function uniqueEmail(): string {
  return `kcp.locale.${process.env.PLAYWRIGHT_RUN_ID ?? Date.now()}@example.com`;
}

test.describe('언어 전환 후 본인확인 시작', () => {
  // @scenario locale=switched,environment=desktop
  // @effects locale_switch_preserves_start_auth_handler
  test('언어를 바꾼 뒤에도 시작 버튼이 인증창을 연다', async ({ page, context }) => {
    await page.goto(REGISTER_PATH);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 쿠키 배너가 폼을 가리면 입력이 막힌다 — 떠 있으면 먼저 치운다.
    const cookieAccept = page.getByRole('button', { name: /Accept All|모두 동의/ });
    if (await cookieAccept.count()) {
      await cookieAccept.first().click().catch(() => {});
    }

    // 언어 전환 — 이 시점에 ActionDispatcher 가 교체되고 확장 핸들러가 재등록된다.
    await page.getByRole('button', { name: /Language|언어/ }).first().click();
    await page.getByRole('option', { name: /한국어/ }).first().click();
    await expect(page.getByRole('heading', { name: '회원가입' })).toBeVisible({ timeout: 20_000 });

    await page.getByPlaceholder('이메일 주소를 입력하세요').fill(uniqueEmail());
    await page.getByPlaceholder('비밀번호 (8자 이상)').fill('Testpass123!@');
    await page.getByPlaceholder('비밀번호를 다시 입력하세요').fill('Testpass123!@');
    await page.getByPlaceholder('이름을 입력하세요').fill('언어전환검수');

    // 필수 동의 2건 (약관 / 개인정보)
    const requiredConsents = page.locator('form input[type="checkbox"][required], form input[type="checkbox"]');
    await requiredConsents.nth(0).check();
    await requiredConsents.nth(1).check();

    // 제출 → 코어가 428 로 본인확인을 요구하고 모달이 열린다.
    await page.getByRole('button', { name: '회원가입' }).last().click();

    const startButton = page.getByRole('button', { name: /본인확인 시작/ });
    await expect(startButton).toBeVisible({ timeout: 20_000 });

    // 핸들러가 살아 있어야만 표준창 팝업이 열린다 (미등록이면 아무 일도 일어나지 않는다).
    const [popup] = await Promise.all([
      context.waitForEvent('page', { timeout: 20_000 }),
      startButton.click(),
    ]);

    // 팝업은 먼저 about:blank 로 열리고, 그 창을 target 으로 표준창 form 이 전송된다.
    // 따라서 로드 완료가 아니라 목적지 URL 확정을 기다려야 한다.
    await popup.waitForURL(KCP_STANDARD_WINDOW, { timeout: 30_000 });
    expect(popup.url()).toMatch(KCP_STANDARD_WINDOW);

    await popup.close();
  });
});
