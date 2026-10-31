/**
 * §9 실측 — 알림 템플릿 수정 창의 수신자 검색 (T-D1 · T-D2 · T-D4)
 *
 * 계획 §D-4 는 **사용자 검색을 고치기 전에 먼저 재라**고 못박는다. 배선(`onSearch` 미발화)과
 * 상태 반영(응답은 오는데 옵션이 안 뜸)과 권한(4xx)은 고치는 자리가 서로 다르고, 재지 않고
 * 고르면 "안 되는 이유" 를 짐작으로 고르게 된다.
 *
 * 그래서 이 spec 이 재는 것은 계획이 지정한 네 값이다:
 *   ① `/api/admin/users/search` 요청 유무  ② 상태코드  ③ 응답 본문  ④ `userSearchResults` 길이
 *
 * 대조군(T-D2)을 같은 창에서 같은 방식으로 잰다 — 역할 칸이 되는데 사용자 칸만 안 되면
 * 원인이 사용자 칸에 있고, 둘 다 안 되면 공통(모달·토큰·디바운스)이다.
 *
 * Chrome MCP·Playwright MCP 브라우저가 모두 프로필 점유 상태라(계획 §9-4: 프로세스를 죽이지
 * 않는다) 저장소 자신의 Playwright 로 잰다.
 *
 * @effects notification_template_recipient_search_live_measurement
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/admin-template-auth';

/** 측정값을 한 줄 JSON 으로 남긴다 — 이력 문서의 「증거」 칸에 그대로 옮기기 위해서다. */
function record(axis: string, value: unknown): void {
  console.log(`[MEASURE] ${axis} ${JSON.stringify(value)}`);
}

/**
 * 알림 정의 탭을 열고 첫 정의의 [편집] 을 눌러 템플릿 수정 창을 띄운다.
 *
 * @param page 페이지
 * @return 정의 목록 응답 상태코드
 */
async function openTemplateModal(page: any): Promise<number> {
  const waitList = page.waitForResponse(
    (r: any) => r.url().includes('/api/admin/notification-definitions') && r.request().method() === 'GET',
    { timeout: 30_000 },
  );
  await page.goto('/admin/settings?tab=notification_definitions');
  const response = await waitList;

  // [편집] 은 정의 카드마다 하나다. 첫 카드가 그려질 때까지 기다렸다가 누른다.
  const editButton = page.locator('button:has-text("편집")').first();
  await editButton.waitFor({ state: 'visible', timeout: 30_000 });
  await editButton.click();
  // 모달 컨테이너는 레이아웃 id 를 DOM 으로 내보내지 않는다 — 역할(dialog)로 잡는다.
  await page.getByRole('dialog').first().waitFor({ state: 'visible', timeout: 15_000 });

  return response.status();
}

/**
 * 수신자 규칙을 원하는 타입 한 줄로 바꾼다 (**사전 조작** — 판정 근거가 아니다).
 *
 * 수신자 줄은 [추가] 버튼으로도 만들 수 있지만, 그 경로는 타입 Select 까지 눌러야 해서
 * 재려는 축(검색 배선) 밖의 조작이 측정에 섞인다. 상태만 세우고 **검색은 실제 입력으로** 한다.
 *
 * @param page 페이지
 * @param type 수신자 타입
 */
async function seedRecipient(page: any, type: string): Promise<void> {
  await page.evaluate((recipientType: string) => {
    const G7Core = (window as any).G7Core;
    const current = G7Core?.state?.get?.()?.notification_template_form_modal ?? {};
    G7Core?.state?.set?.({
      notification_template_form_modal: {
        ...current,
        recipients: [{ type: recipientType, value: [] }],
        roleSearchResults: [],
        userSearchResults: [],
      },
    });
  }, type);

  await page.waitForFunction(
    (recipientType: string) =>
      ((window as any).G7Core?.state?.get?.()?.notification_template_form_modal?.recipients ?? [])[0]?.type ===
      recipientType,
    type,
    { timeout: 10_000 },
  );
}

/**
 * 열려 있는 SearchableDropdown 의 검색창에 글자를 넣고 디바운스가 끝날 때까지 기다린다.
 *
 * 값 주입이 아니라 **한 글자씩 실제 타이핑**이다 — `fill` 은 한 번의 change 로 끝나 디바운스
 * 경로를 실제와 다르게 태우고, 동일 값 재주입은 `_valueTracker` 가 onChange 를 삼킨다.
 *
 * @param page 페이지
 * @param keyword 입력할 검색어
 */
async function typeIntoOpenDropdown(page: any, keyword: string, awaitPath?: string): Promise<void> {
  const input = page.locator('[role="listbox"] input[type="text"]').first();
  await input.waitFor({ state: 'visible', timeout: 10_000 });

  // **그 검색어의 응답**을 기다린다 — 디바운스 때문에 앞 검색어의 응답이 뒤늦게 도착하면
  // 측정값이 한 박자 밀린 상태로 남는다(1차 실행에서 `adm` 의 결과가 그다음 축에 찍혔다).
  const settled = awaitPath
    ? page
        .waitForResponse(
          (r: any) => r.url().includes(awaitPath) && r.url().includes(`=${encodeURIComponent(keyword)}`),
          { timeout: 15_000 }
        )
        .catch(() => null)
    : Promise.resolve(null);

  await input.click();
  await input.fill('');
  await input.pressSequentially(keyword, { delay: 80 });
  await settled;
  // 응답 뒤 상태 반영·재렌더가 끝나는 시간.
  await page.waitForTimeout(500);
}

/** @effects notification_template_recipient_search_live_measurement */
test('T-D1 사용자 검색 — 요청 유무·상태코드·응답·상태 길이 네 값', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update', 'core.users.read', 'core.permissions.read');
  await authenticatePage(page, token);

  const consoleErrors: string[] = [];
  page.on('console', (msg: any) => {
    if (msg.type() === 'error') consoleErrors.push(msg.text());
  });

  // 요청은 URL 로만 세지 않는다 — 상태코드와 본문까지 같은 자리에서 잡아야 네 값이 한 줄로 남는다.
  const searchCalls: Array<{ url: string; status: number; body: string }> = [];
  page.on('response', async (response: any) => {
    if (!response.url().includes('/api/admin/users/search')) return;
    let body = '';
    try {
      body = (await response.text()).slice(0, 400);
    } catch {
      body = '(본문 읽기 실패)';
    }
    searchCalls.push({ url: response.url(), status: response.status(), body });
  });

  const listStatus = await openTemplateModal(page);
  await seedRecipient(page, 'specific_users');

  // 수신자 줄의 목록형 버튼은 두 개다 — [0] 타입 Select, [1] 검색 드롭다운. 첫 번째를 잡으면
  // 타입 Select 가 열리고 검색창이 없어 그대로 멈춘다(1차 실행에서 실제로 그랬다).
  const trigger = page.getByRole('dialog').locator('button[aria-haspopup="listbox"]').nth(1);
  const triggerPresent = await trigger
    .waitFor({ state: 'visible', timeout: 10_000 })
    .then(() => true)
    .catch(() => false);
  record('T-D1/trigger', { listStatus, triggerPresent });

  if (!triggerPresent) {
    record('T-D1/blocked', '사용자 칸 트리거가 렌더되지 않아 검색을 재지 못했다');

    return;
  }

  await trigger.click();
  await typeIntoOpenDropdown(page, 'heu', '/api/admin/users/search');

  const state = await page.evaluate(() => {
    const modal = (window as any).G7Core?.state?.get?.()?.notification_template_form_modal ?? {};
    const listbox = document.querySelector('[role="listbox"]');

    return {
      userSearchResultsLength: (modal.userSearchResults ?? []).length,
      listboxPresent: listbox !== null,
      searchInputPresent: document.querySelector('[role="listbox"] input[type="text"]') !== null,
      expandedButtons: Array.from(document.querySelectorAll('[role="dialog"] button[aria-haspopup="listbox"]')).map(
        (b) => b.getAttribute('aria-expanded')
      ),
      optionCount: listbox?.querySelectorAll('[role="option"]').length ?? 0,
      listboxText: (listbox?.textContent ?? '').slice(0, 200),
    };
  });

  record('T-D1/user_search', {
    requestCount: searchCalls.length,
    calls: searchCalls,
    ...state,
    consoleErrors: consoleErrors.length,
  });
  record('T-D1/console', consoleErrors.slice(0, 5));

  // 단정은 하지 않는다 — 이 spec 은 **계측 도구**이며 수정 방향을 가르는 값을 남기는 것이 목적이다.
  expect(listStatus).toBe(200);
});

/** @effects notification_template_recipient_search_live_measurement */
test('T-D2 역할 검색 대조군 — adm 과 heu 각각', async ({ page }) => {
  const token = issueToken('core.settings.read', 'core.settings.update', 'core.users.read', 'core.permissions.read');
  await authenticatePage(page, token);

  const consoleErrors: string[] = [];
  page.on('console', (msg: any) => {
    if (msg.type() === 'error') consoleErrors.push(msg.text().slice(0, 300));
  });
  page.on('pageerror', (error: any) => consoleErrors.push(`pageerror: ${String(error).slice(0, 300)}`));

  const roleCalls: Array<{ keyword: string; url: string; status: number; count: number }> = [];
  let currentKeyword = '';
  page.on('response', async (response: any) => {
    if (!response.url().includes('/api/admin/roles')) return;
    let count = -1;
    try {
      const json = JSON.parse(await response.text());
      count = (json?.data?.data ?? json?.data ?? []).length ?? -1;
    } catch {
      count = -1;
    }
    roleCalls.push({ keyword: currentKeyword, url: response.url(), status: response.status(), count });
  });

  const listStatus = await openTemplateModal(page);
  await seedRecipient(page, 'role');

  // 수신자 줄의 목록형 버튼은 두 개다 — [0] 타입 Select, [1] 검색 드롭다운. 첫 번째를 잡으면
  // 타입 Select 가 열리고 검색창이 없어 그대로 멈춘다(1차 실행에서 실제로 그랬다).
  const trigger = page.getByRole('dialog').locator('button[aria-haspopup="listbox"]').nth(1);
  const triggerPresent = await trigger
    .waitFor({ state: 'visible', timeout: 10_000 })
    .then(() => true)
    .catch(() => false);
  record('T-D2/trigger', { listStatus, triggerPresent });

  if (!triggerPresent) {
    record('T-D2/blocked', '역할 칸 트리거가 렌더되지 않아 검색을 재지 못했다');

    return;
  }

  // 검색어마다 **새로 연다** — 1차 실행에서 `adm` 응답이 온 뒤 목록이 사라져(두 번째 입력이
  // 검색창을 찾지 못했다) 한 번 열어 두고 두 번 치는 방식으로는 둘째 축을 잴 수 없었다.
  for (const keyword of ['adm', 'heu']) {
    currentKeyword = keyword;

    const stillOpen = await page
      .locator('[role="listbox"] input[type="text"]')
      .first()
      .isVisible()
      .catch(() => false);
    if (!stillOpen) {
      const reopened = await trigger
        .click({ timeout: 5_000 })
        .then(() => true)
        .catch(() => false);
      record(`T-D2/reopen/${keyword}`, { stillOpen, reopened });
    }

    const typed = await typeIntoOpenDropdown(page, keyword, '/api/admin/roles')
      .then(() => true)
      .catch(() => false);
    if (!typed) {
      record(`T-D2/role_search/${keyword}`, { typed: false, reason: '검색창이 열리지 않아 입력하지 못했다' });
      continue;
    }

    const state = await page.evaluate(() => {
      const modal = (window as any).G7Core?.state?.get?.()?.notification_template_form_modal ?? {};
      const listbox = document.querySelector('[role="listbox"]');
      const triggerButton = document.querySelector('[role="dialog"] button[aria-haspopup="listbox"]:nth-of-type(1)');

      const results = modal.roleSearchResults ?? [];
      const rcpt = (modal.recipients ?? [])[0] ?? {};
      // 화면이 실제로 만드는 `options` 를 그대로 다시 만들어 본다 — 상태만 봐서는
      // 선택값 스프레드(첫 번째 조각)에서 생기는 라벨을 놓친다.
      const composed = [
        ...((rcpt.value ?? []) as any[]).map((rid: any, i: number) => ({
          value: rid,
          label: (rcpt.display_names ?? [])[i] ?? rid,
        })),
        ...results,
      ];
      const oddLabels = composed
        .map((o: any, i: number) => ({ i, type: typeof o?.label, value: o?.value, label: o?.label }))
        .filter((o: any) => o.type !== 'string')
        .slice(0, 5);

      return {
        roleSearchResultsLength: results.length,
        oddLabels,
        listboxPresent: listbox !== null,
        searchInputPresent: document.querySelector('[role="listbox"] input[type="text"]') !== null,
        expandedButtons: Array.from(document.querySelectorAll('[role="dialog"] button[aria-haspopup="listbox"]')).map(
          (b) => b.getAttribute('aria-expanded')
        ),
        optionCount: listbox?.querySelectorAll('[role="option"]').length ?? 0,
        listboxText: (listbox?.textContent ?? '').slice(0, 160),
        triggerLabel: (triggerButton?.textContent ?? '').trim().slice(0, 40),
        recipient: { type: rcpt.type, value: rcpt.value, display_names: rcpt.display_names },
        sampleResults: results.slice(0, 2),
      };
    });
    record(`T-D2/role_search/${keyword}`, state);
  }

  record('T-D2/requests', roleCalls);
  record(
    'T-D2/console',
    consoleErrors.filter((e) => !e.includes('bizppurio')).slice(0, 6)
  );
  expect(listStatus).toBe(200);
});
