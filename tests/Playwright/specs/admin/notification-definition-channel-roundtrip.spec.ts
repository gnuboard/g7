/**
 * E2E: 알림 정의 채널 탭 왕복 후 목록이 다른 채널을 보게 되는 결함 (#518)
 *
 * 배경 (3차 실측 결함 #1):
 * 채널은 두 곳에서 읽히고 있었다 — URL(`query.channel`)과 페이지 로컬 상태
 * (`_local.activeNotificationChannel`). 채널 탭을 클릭하면 둘 다 갱신되므로 그 순간에는 일치한다.
 * 그러나 다른 설정 탭에 갔다 돌아오면 URL 의 channel 은 사라지고(탭 전환은 목록 상태를 승계하지
 * 않는다) 로컬 상태만 이전 채널로 남는다. 그 사이 목록은 기본 채널(mail)로 다시 불려 오고,
 * 화면은 남아 있는 이전 채널로 템플릿을 찾는다.
 *
 * 결과: 정의 행은 정상으로 그려지지만 **행 안의 제목 줄과 「수신자:」 줄만**
 * 「이 채널에 대한 템플릿이 없습니다」 로 바뀐다. 예외도 콘솔 에러도 4xx/5xx 도 없다.
 *
 * 단위 테스트로는 잡히지 않는다 — 두 값이 어긋나는 것은 렌더 시점 차이 때문이고, 종전 표현식은
 * 데이터소스와 소비자에 **글자까지 같은 식**이 적혀 있어 정적 대조로는 동일해 보였다.
 *
 * 이 스펙은 채널을 URL 단일 출처로 좁힌 뒤의 계약을 고정한다:
 * "마지막으로 요청한 template_channel" 과 "화면이 활성으로 표시하는 채널" 이 항상 같다.
 *
 * @effects notification_channel_tab_roundtrip_keeps_data_and_screen_in_sync,
 *          notification_channel_switch_requests_that_channel,
 *          notification_channel_roundtrip_emits_no_console_errors
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';

const DEFINITIONS_TAB = '/admin/settings?tab=notification_definitions';

const SETTINGS_PERMISSIONS = [
  'core.settings.read',
  'core.settings.update',
  'core.admin.notifications.read',
] as const;

/** 채널 탭 표시명 (lang/ko/notification.php) */
const CHANNEL_MAIL = '메일';
const CHANNEL_DATABASE = '사이트내 알림';

/** 설정 탭 표시명 (templates/.../lang/partial/ko/admin.json) */
const TAB_NOTIFICATION = '알림 설정';
const TAB_IDENTITY = '본인인증';

/**
 * 알림 정의 목록 요청의 `template_channel` 값을 순서대로 수집합니다.
 *
 * 화면이 무엇을 보여주는지와 서버가 무엇을 보냈는지를 대조하려면 실제 요청을 봐야 한다 —
 * 화면만 보면 "템플릿이 없다" 가 데이터 탓인지 표시 탓인지 구분되지 않는다.
 *
 * @param page 대상 페이지
 * @returns 수집된 채널 값 배열 (호출 시점까지 누적)
 */
function trackRequestedChannels(page: Page): string[] {
  const channels: string[] = [];

  page.on('request', request => {
    const url = request.url();

    if (!url.includes('/api/admin/notification-definitions')) {
      return;
    }

    const value = new URL(url).searchParams.get('template_channel');

    if (value) {
      channels.push(value);
    }
  });

  return channels;
}

/**
 * 알림 정의 탭에 진입해 정의 목록이 실제로 그려질 때까지 기다립니다.
 *
 * @param page 대상 페이지
 */
async function gotoDefinitionsTab(page: Page): Promise<void> {
  await page.goto(DEFINITIONS_TAB);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  await expect(channelTab(page, CHANNEL_MAIL)).toBeVisible({ timeout: 20_000 });
  await expect(channelTab(page, CHANNEL_DATABASE)).toBeVisible({ timeout: 20_000 });
}

/**
 * 채널 하위 탭 버튼 locator 를 만듭니다.
 *
 * 접근성 이름에는 아이콘 alt 가 앞에 붙는다(예: `fas fa envelope 메일`). `exact` 로 잡으면
 * 요소를 찾지 못하므로 이름 끝을 정규식으로 맞춘다.
 *
 * @param page 대상 페이지
 * @param label 채널 표시명
 * @returns 채널 탭 버튼 locator
 */
function channelTab(page: Page, label: string) {
  return page.getByRole('button', { name: new RegExp(`${label}$`) });
}

/**
 * 현재 화면이 활성으로 표시 중인 채널 탭의 이름을 읽습니다.
 *
 * 활성 표시는 `border-b-2 border-indigo-500` 계열 클래스로만 구분된다. 설정 탭(TabNavigation)도
 * 같은 색 계열을 쓸 수 있으므로 전체 버튼을 훑지 않고 **채널 후보 두 개만** 검사한다.
 *
 * @param page 대상 페이지
 * @returns 활성 채널 탭의 표시명 (없으면 null)
 */
async function activeChannelLabel(page: Page): Promise<string | null> {
  for (const label of [CHANNEL_MAIL, CHANNEL_DATABASE]) {
    const className = (await channelTab(page, label).first().getAttribute('class')) ?? '';

    if (className.includes('border-indigo-500') || className.includes('border-indigo-400')) {
      return label;
    }
  }

  return null;
}

/**
 * 다른 설정 탭에 갔다가 알림 설정 탭으로 돌아옵니다.
 *
 * `page.goto` 를 쓰면 안 된다 — 전체 새로고침은 로컬 상태를 통째로 버리므로 재현 조건이 사라진다.
 * 결함은 SPA 라우팅(탭 클릭)으로 URL 만 바뀌어 로컬 상태가 살아남을 때 드러난다.
 *
 * @param page 대상 페이지
 */
async function settingsTabRoundTrip(page: Page): Promise<void> {
  await page.getByRole('tab', { name: TAB_IDENTITY }).click();
  await expect(page).toHaveURL(/tab=identity/, { timeout: 15_000 });

  await page.getByRole('tab', { name: TAB_NOTIFICATION }).click();
  await expect(page).toHaveURL(/tab=notification_definitions/, { timeout: 15_000 });
  await expect(channelTab(page, CHANNEL_MAIL)).toBeVisible({
    timeout: 20_000,
  });
}

/**
 * 이 스펙은 직렬로 실행한다 — 세 테스트가 모두 관리자 설정 화면을 띄우므로 병렬 세션이
 * 겹치면 목록 적재 대기가 타임아웃되어 제품 결함이 아닌 거짓 실패가 난다.
 */
test.describe.configure({ mode: 'serial' });

test.describe('알림 정의 채널 왕복 (#518)', () => {
  /**
   * @scenario surface=core_settings,action=channel_switch
   *
   * @effects notification_channel_switch_requests_that_channel
   */
  test('채널 탭을 누르면 그 채널로 목록을 다시 불러온다', async ({ page }) => {
    await authenticatePage(page, issueToken(...SETTINGS_PERMISSIONS));

    const requested = trackRequestedChannels(page);
    await gotoDefinitionsTab(page);

    await expect.poll(() => requested.length, { timeout: 20_000 }).toBeGreaterThan(0);
    expect(requested.at(-1)).toBe('mail');

    await channelTab(page, CHANNEL_DATABASE).click();

    await expect(page).toHaveURL(/channel=database/, { timeout: 15_000 });
    await expect.poll(() => requested.at(-1), { timeout: 20_000 }).toBe('database');
    await expect.poll(() => activeChannelLabel(page), { timeout: 15_000 }).toBe(CHANNEL_DATABASE);
  });

  /**
   * @scenario surface=core_settings,action=tab_roundtrip
   *
   * @effects notification_channel_tab_roundtrip_keeps_data_and_screen_in_sync
   */
  test('다른 설정 탭에 갔다 돌아와도 요청한 채널과 화면이 가리키는 채널이 같다', async ({
    page,
  }) => {
    await authenticatePage(page, issueToken(...SETTINGS_PERMISSIONS));

    const requested = trackRequestedChannels(page);
    await gotoDefinitionsTab(page);

    // 기본(mail)이 아닌 채널로 옮겨 둔다 — 왕복 후 어긋남이 드러나려면 두 값이 달라야 한다
    await channelTab(page, CHANNEL_DATABASE).click();
    await expect.poll(() => requested.at(-1), { timeout: 20_000 }).toBe('database');

    await settingsTabRoundTrip(page);

    // 왕복 직후에는 화면이 잠깐 기본값을 보이다 뒤늦게 뒤집힐 수 있었다(실측 ~0.4초).
    // 그래서 한 번 읽고 끝내지 않고, 안정될 때까지 기다린 뒤 두 값을 대조한다.
    await page.waitForTimeout(1_500);

    const lastRequested = requested.at(-1);
    const active = await activeChannelLabel(page);

    const expectedLabel = lastRequested === 'database' ? CHANNEL_DATABASE : CHANNEL_MAIL;

    expect(
      active,
      `목록은 ${lastRequested} 로 불러왔는데 화면은 ${active} 을 활성으로 표시한다 — ` +
        '두 채널이 어긋나면 행마다 「이 채널에 대한 템플릿이 없습니다」 가 뜬다'
    ).toBe(expectedLabel);
  });

  /**
   * @scenario surface=core_settings,action=tab_roundtrip
   *
   * @effects notification_channel_roundtrip_emits_no_console_errors
   */
  test('채널 왕복 중 콘솔 에러가 발생하지 않는다', async ({ page }) => {
    const errors: string[] = [];
    page.on('console', msg => {
      if (msg.type() === 'error') errors.push(msg.text());
    });

    await authenticatePage(page, issueToken(...SETTINGS_PERMISSIONS));
    await gotoDefinitionsTab(page);

    await channelTab(page, CHANNEL_DATABASE).click();
    await expect(page).toHaveURL(/channel=database/, { timeout: 15_000 });
    await settingsTabRoundTrip(page);

    expect(errors).toEqual([]);
  });
});
