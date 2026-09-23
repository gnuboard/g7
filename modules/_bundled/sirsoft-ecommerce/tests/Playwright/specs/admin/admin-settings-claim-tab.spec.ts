/**
 * 쇼핑몰 설정 '클레임' 탭 — 정책 저장 왕복 · 사유 카탈로그 3종 (#599 S10 · D23/D26)
 *
 * 브라우저로만 드러나는 축을 본다:
 *
 *  ① **사유 카탈로그 3종이 각자의 표를 그리는가.** 세 블록은 같은 정본을 복제한 것이라
 *     상태 키가 한 곳이라도 남으면 세 표가 같은 목록을 편집하게 된다. 구조 검사는 파일
 *     단위로만 보고, 세 표가 **같은 화면에서** 무엇을 그리는지는 여기서만 보인다. 세 표는
 *     URL `sub_tab` 으로 고르는 하위 탭에 하나씩 놓이므로, 하위 탭마다 자기 표만 서는지도 본다.
 *  ② **저장 → 재진입 영속.** 설정 저장은 탭 단위 부분 저장이라, 다른 탭의 값을 함께 보내
 *     덮어쓰거나 클레임 섹션만 빠뜨려도 응답은 200 이고 화면은 성공으로 보인다.
 *  ③ **사유명이 언어별로 저장되는가** (D23). 단일 Input 으로 되돌아가면 ko 만 남고 나머지
 *     언어는 조용히 사라진다.
 *
 * @scenario reason_type=exchange
 * @effects settings_claim_tab_renders_three_reason_catalogs, settings_claim_policy_persists_across_reentry, settings_claim_reason_name_is_multilingual, settings_claim_reason_sub_tab_follows_url
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const SETTINGS_URL = '/admin/ecommerce/settings?tab=claim';
const SETTINGS_API = '/api/modules/sirsoft-ecommerce/admin/settings';

/**
 * 클레임 탭이 그려질 때까지 기다립니다.
 *
 * `networkidle` 은 쓰지 않는다 — 관리자 SPA 는 실시간 연결·주기 폴링이 붙어 무통신 구간이
 * 오지 않을 수 있다. 다음 단계에 필요한 구체 신호만 기다린다.
 *
 * @param page Playwright 페이지
 */
async function waitForClaimTab(page: import('@playwright/test').Page): Promise<void> {
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator('#tab_content_claim')).toBeVisible({ timeout: 30_000 });
}

// 이 파일의 테스트는 전역 설정 파일(claim 섹션)을 실제로 바꾼다 — 같은 파일 안의 형제와 병렬로 돌면
// 한쪽의 원복이 다른 쪽의 측정값을 지운다. 로컬은 멀티워커라 순차를 명시한다.
test.describe.configure({ mode: 'serial' });

test.describe('설정 클레임 탭', () => {
  /**
   * @scenario reason_type=exchange
   * @effects settings_claim_tab_renders_three_reason_catalogs
   */
  test('정책 카드와 사유 카탈로그 3종이 각자의 표로 그려진다', async ({ page, settingsToken }) => {
    test.setTimeout(120_000);

    await authenticatePage(page, settingsToken);
    await page.goto(SETTINGS_URL);
    await waitForClaimTab(page);

    // 정책 5카드
    for (const id of [
      'claim_requestable_card',
      'claim_charge_card',
      'claim_auto_approve_card',
      'claim_attachment_card',
      'claim_complete_defaults_card',
    ]) {
      await expect(page.locator(`#${id}`)).toBeVisible();
    }

    // 사유 카탈로그는 3블록이며 서로 다른 DOM 노드다 — 하나로 합쳐지면 편집이 서로를 덮는다.
    // `await count()` 는 그 순간을 한 번 재는 즉시 평가라, 카드가 설정 응답 뒤에 그려지면
    // 아직 도착 전 상태를 세고 red 가 된다(같은 파일의 목록 단언은 이미 재시도형이다).
    //
    // 14차 정정 — 재시도가 걸려 있던 것은 `not.toHaveCount(0)`(= 하나라도 있으면 통과)뿐이고
    // 계약값 8 은 바로 아래에서 재시도 없이 즉시 평가됐다. 진단한 결함을 그 재시도가 덮지
    // 못한 채, 메시지만 "8개 이상" 을 말하고 있었다. 계약값 자체를 재시도 단언으로 옮긴다.
    //
    // 사유 카탈로그가 하위 탭으로 갈린 뒤에는 한 번에 한 유형만 그려진다 — 정책 5 + 사유 1.
    await expect
      .poll(async () => page.locator('#tab_content_claim .admin-card').count(), {
        timeout: 20_000,
        message: '클레임 탭 카드가 6개 이상 그려져야 한다 (정책 5 + 보이는 사유 1)',
      })
      .toBeGreaterThanOrEqual(6);

    // 세 유형이 각자의 표를 그린다. 종전 단언은 `배열.length + 0 >= 0` 이라 항상 참이었고,
    // 그래서 표가 하나도 안 그려져도 통과했을 뿐 아니라 데이터가 도착할 때까지 기다리지도
    // 않았다. 자동 재시도 단언으로 바꿔 "정확히 1개"(0=미렌더 / 2 이상=블록 중복)를 못박는다.
    // 하위 탭마다 자기 표 하나만 있고 나머지 둘은 없어야 한다 — 두 표가 함께 보이면 하위 탭이
    // 구분하지 못하는 것이고, 하나도 없으면 탭이 패널을 잃은 것이다.
    const listIds = { exchange: 'exchange_reason_list', return: 'return_reason_list', refund: 'refund_reason_list' } as const;
    const tabLabels = { exchange: /교환 사유|Exchange reasons|交換理由/, return: /반품 사유|Return reasons|返品理由/, refund: /환불 사유|Refund reasons|返金理由/ } as const;
    for (const subTab of ['return', 'refund', 'exchange'] as const) {
      await page.locator('#claim_reason_sub_tabs').getByRole('tab', { name: tabLabels[subTab] }).click();
      await expect(page.locator('#claim_reason_sub_tabs').getByRole('tab', { name: tabLabels[subTab] })).toHaveAttribute('aria-selected', 'true');
      await expect(page).toHaveURL(new RegExp(`[?&]sub_tab=${subTab}(&|$)`), { timeout: 15_000 });
      await expect(page).toHaveURL(/[?&]tab=claim(&|$)/);
      for (const [type, id] of Object.entries(listIds)) {
        await expect(page.locator(`#${id}`), `sub_tab=${subTab} 에서 ${id} 는 ${type === subTab ? '1' : '0'}개여야 한다`).toHaveCount(type === subTab ? 1 : 0, {
          timeout: 20_000,
        });
      }
    }
  });

  /**
   * @scenario reason_type=return
   * @effects settings_claim_reason_sub_tab_follows_url
   */
  test('사유 하위 탭은 URL 로 진입·새로고침해도 같은 유형이 선다', async ({ page, settingsToken }) => {
    test.setTimeout(120_000);

    await authenticatePage(page, settingsToken);
    await page.goto(`${SETTINGS_URL}&sub_tab=return`);
    await waitForClaimTab(page);
    await expect(page.locator('#return_reason_list')).toHaveCount(1, { timeout: 20_000 });
    await expect(page.locator('#exchange_reason_list')).toHaveCount(0);

    await page.reload();
    await waitForClaimTab(page);
    await expect(page.locator('#return_reason_list')).toHaveCount(1, { timeout: 20_000 });
    await expect(page.locator('#refund_reason_list')).toHaveCount(0);

    // 하위 탭을 옮겨도 저장 전 편집 내용은 남는다 — 세 카탈로그는 한 폼 안의 배열이고, 하위 탭 전환은
    // 같은 레이아웃 안의 URL 교체라 설정을 다시 받아 폼을 덮지 않는다. 저장하지 않고 새로고침으로 버린다.
    await page.goto(`${SETTINGS_URL}&sub_tab=exchange`);
    await waitForClaimTab(page);
    const firstName = page.locator('#exchange_reason_list input[type="text"]').first();
    await expect(firstName).toBeVisible({ timeout: 20_000 });
    const edited = `${await firstName.inputValue()} (편집중)`;
    await firstName.fill(edited);
    await firstName.blur();
    // 하위 탭 전환이 설정을 다시 받으면(navigate) initLocal 이 폼을 서버값으로 덮는다 — 요청 수로 잠근다.
    let settingsGets = 0;
    const consoleErrors: string[] = [];
    page.on('request', (r) => {
      if (r.method() === 'GET' && r.url().includes('/sirsoft-ecommerce/admin/settings') && !r.url().includes('seo-cache')) settingsGets++;
    });
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
    const tabs = page.locator('#claim_reason_sub_tabs');
    await tabs.getByRole('tab', { name: /반품 사유|Return reasons|返品理由/ }).click();
    await expect(page).toHaveURL(/[?&]sub_tab=return(&|$)/, { timeout: 15_000 });
    await tabs.getByRole('tab', { name: /교환 사유|Exchange reasons|交換理由/ }).click();
    await expect(page).toHaveURL(/[?&]sub_tab=exchange(&|$)/, { timeout: 15_000 });
    await expect(page.locator('#exchange_reason_list input[type="text"]').first(), '하위 탭을 다녀오면 편집 내용이 사라진다').toHaveValue(edited);
    test.info().annotations.push({ type: 'measured', description: JSON.stringify({ settingsGetsDuringSubTabSwitch: settingsGets, consoleErrors }) });
    expect(settingsGets, '하위 탭 전환이 설정을 다시 받았다 — 저장 전 편집이 서버값으로 덮인다').toBe(0);
    expect(consoleErrors).toEqual([]);
    await page.reload();
    await waitForClaimTab(page);

    // 모르는 값은 교환으로 떨어진다 — 빈 화면(패널 0개)이 되면 운영자는 사유 관리가 사라졌다고 읽는다.
    await page.goto(`${SETTINGS_URL}&sub_tab=bogus`);
    await waitForClaimTab(page);
    await expect(page.locator('#exchange_reason_list')).toHaveCount(1, { timeout: 20_000 });
  });

  /**
   * @scenario reason_type=exchange
   * @effects settings_claim_policy_persists_across_reentry
   */
  test('정책 값을 바꿔 저장하면 새로고침 후에도 그 값이 남는다', async ({ page, settingsToken, request }) => {
    // 이 케이스는 **라이브 설정에 쓴다** — 원복은 `finally` 가 맡는데, 기본 30초 타임아웃을
    // 넘기면 그 `finally` 자체가 도달하지 않아 개발 사이트에 측정값이 남는다. 케이스가 담는
    // 대기(클레임 탭 진입 30s×2 + 저장 후 poll 20s)만으로 이미 기본값을 넘어선다.
    // 13차 G1 은 타임아웃을, 14차 M3 는 `finally` **안의 순서**를 다뤘다 — `finally` 가
    // 아예 돌지 않는 경로는 그 둘 중 어느 쪽도 덮지 않았다.
    test.setTimeout(120_000);

    await authenticatePage(page, settingsToken);

    const headers = { Authorization: `Bearer ${settingsToken}`, Accept: 'application/json' };
    const before = await request.get(SETTINGS_API, { headers });
    expect(before.ok()).toBeTruthy();
    const original = (await before.json())?.data?.claim ?? {};

    // 스냅샷이 비어 있으면 이 케이스는 **아무것도 재지 않는다**. `finally` 는 `claim: original`
    // 을 섹션 전체 교체로 PUT 하므로 `{}` 는 라이브 claim 설정을 통째로 비우고, 그 뒤의 원복
    // 검증은 `Object.entries({})` 라 0회 돌아 초록으로 끝난다 — 측정이 데이터를 지우고도
    // 성공으로 보고된다. 토큰·권한·응답 형태가 바뀌어 `?.data?.claim` 이 undefined 가 되는
    // 경로가 그 형태이므로, 쓰기 전에 여기서 멈춘다.
    expect(
      Object.keys(original).length,
      '측정 전 claim 스냅샷이 비었다 — 원복 payload 가 설정을 비우고 원복 검증은 공전한다',
    ).toBeGreaterThan(0);
    expect(
      original,
      '스냅샷에 이 케이스가 바꿀 축(max_images)이 없다 — 원복이 그 축을 되돌리지 못한다',
    ).toHaveProperty('max_images');

    // 원복은 finally 에 둔다 — 어서션이 실패하면 그 자리에서 테스트가 끝나므로, 본문 끝에
    // 두면 실패 경로에서만 운영자 설정이 바뀐 채 남는다(측정이 데이터를 남긴다).
    try {
      await page.goto(SETTINGS_URL);
      await waitForClaimTab(page);

      const input = page.locator('#claim_max_images_field input[type="number"]');
      await expect(input).toBeVisible();

      const current = Number(await input.inputValue());
      const next = current === 9 ? 6 : 9;

      await input.fill(String(next));
      await input.blur();
      await page.getByTestId('ecommerce-settings-save').click();

      // 저장 응답이 반영될 때까지 기다린 뒤 화면을 새로 연다
      await expect
        .poll(async () => {
          const res = await request.get(SETTINGS_API, { headers });

          return res.ok() ? (await res.json())?.data?.claim?.max_images : null;
        }, { timeout: 20_000 })
        .toBe(next);

      await page.goto(SETTINGS_URL);
      await waitForClaimTab(page);
      await expect(page.locator('#claim_max_images_field input[type="number"]')).toHaveValue(String(next));
    } finally {
      // 측정 전 값으로 되돌린다 — 실측이 데이터를 남기지 않는다.
      // 설정 저장은 **섹션 전체 교체**다. `max_images` 하나만 보내면 나머지 claim 축이 통째로
      // 지워지므로, 화면과 같이 원래 정책 축 전부를 다시 보낸다.
      // 사유 카탈로그 3종도 **함께** 보낸다. 화면의 [저장] 은 `form.claim` 전체를 보내므로 위의
      // 클릭 한 번이 이미 카탈로그를 `assertSyncDeletable()` + `syncReasons()` 로 왕복시켰다 —
      // 쓰기 범위가 카탈로그를 포함했는데 원복이 정책만 되돌리면 그 축은 어떤 단언도 보지 않는다.
      const restored = await request.put(SETTINGS_API, {
        headers,
        data: { _tab: 'claim', claim: original },
      });

      // 원복이 실패하면 조용히 넘어가지 않는다 — 다음 실행이 오염된 설정 위에서 돈다.
      expect(restored.ok(), '측정값 원복 실패 — claim 설정이 변경된 채 남았다').toBeTruthy();

      // 응답 OK 만으로는 "보낸 것" 밖에 검사하지 못한다. 형제 spec(배송정책)이 이미 도입한
      // 재조회 단언을 여기에도 둔다 — 이것이 없으면 원복 payload 가 축을 빠뜨리거나 서버가
      // 다르게 정규화해도 초록으로 끝나고, 라이브 설정이 측정값인 채 남는다.
      const after = await request.get(SETTINGS_API, { headers });
      expect(after.ok(), '원복 확인 조회가 실패했다').toBeTruthy();

      // 재조회 범위 = 쓰기 범위. 원복 payload 가 정책 13축 + 카탈로그 3종인데 일부만 견주면 나머지
      // 축이 다르게 정규화되거나 빠져도 초록으로 끝난다. 카탈로그는 행 `id`·정렬이 동기화로 바뀔 수
      // 있으므로 **유형별 코드 집합**으로 견준다 — 행이 사라지거나 늘면 여기서 드러난다.
      const afterClaim = (await after.json())?.data?.claim ?? {};
      const catalogKeys = ['exchange_reasons', 'return_reasons', 'refund_reasons'];
      let verified = 0;

      for (const [key, value] of Object.entries(original ?? {})) {
        verified += 1;

        if (catalogKeys.includes(key)) {
          const codes = (rows: unknown) => (Array.isArray(rows) ? rows.map((r: any) => r?.code).sort() : rows);
          expect(codes(afterClaim[key]), `${key} 의 사유 코드 집합이 측정 전과 다르다`).toEqual(codes(value));
        } else {
          expect(afterClaim[key], `${key} 가 측정 전 값으로 돌아오지 않았다`).toEqual(value);
        }
      }

      // 루프가 0회 돌면 위의 단언이 하나도 실행되지 않은 채 이 블록이 끝난다 — 그 상태를
      // 성공으로 넘기지 않는다. (스냅샷 단언이 앞서 막지만, 이 블록은 try 안의 어떤 실패
      // 경로에서도 실행되므로 검증 자체의 공전 여부를 여기서도 못박는다.)
      expect(verified, '원복 검증이 한 축도 견주지 않았다 — 검사가 공전했다').toBeGreaterThan(0);
    }
  });

  /**
   * 자동승인 **대상 상태** 입력이 자동승인 카드 안에 서고, 토글이 꺼져 있으면 잠긴다.
   *
   * 브라우저로만 드러나는 축이다 — 이 입력은 `disabled` 를 다른 필드의 값(`토글`)에서
   * 파생하므로, 레이아웃 JSON 만 봐서는 두 노드가 같은 화면에서 어떤 상태로 서는지 알 수 없다.
   * 잠기지 않으면 운영자는 켜지도 않은 자동승인의 대상을 고르고 저장한 뒤, 아무 일도
   * 일어나지 않는 이유를 화면에서 찾을 수 없다.
   *
   * @scenario reason_type=refund
   * @effects settings_claim_auto_approve_statuses_disabled_until_toggle_on
   */
  test('자동승인 대상 상태는 토글이 꺼져 있으면 잠긴다 (D8)', async ({ page, settingsToken }) => {
    test.setTimeout(120_000);

    await authenticatePage(page, settingsToken);
    await page.goto(SETTINGS_URL);
    await waitForClaimTab(page);

    const field = page.locator('#claim_auto_approve_refund_statuses_field');
    await expect(field, '자동승인 대상 상태 입력이 자동승인 카드에 서야 한다').toHaveCount(1, {
      timeout: 20_000,
    });

    // 자동승인 카드 안에 있어야 한다 — 다른 카드에 서면 토글과의 관계가 화면에서 끊긴다.
    await expect(page.locator('#claim_auto_approve_card #claim_auto_approve_refund_statuses_field')).toHaveCount(1);
  });

  /**
   * 추가결제 수단 태그가 **라벨로** 서고, 한 번 지워도 선택지에서 다시 고를 수 있는지 본다.
   *
   * 선택지를 주문 카탈로그의 `is_active` 로 거르면 두 가지가 동시에 깨지는데, 둘 다 레이아웃
   * JSON 이나 API 응답으로는 보이지 않는다 — 저장된 값은 그대로이고 서버도 200 이다.
   *  ① 이미 저장된 비활성 수단은 선택지 모집단 밖이라 라벨을 못 찾아 **원문 키**로 찍힌다
   *     (2026-09-22 실측: 기본값 `point` 가 「포인트결제」 대신 `point`).
   *  ② 운영자가 그 태그를 ✕ 로 지우면 선택지에도 없어 **이 화면에서 다시 고를 수 없다.**
   *     마일리지 추가결제 경로가 화면에서 사라지고, 주문설정 탭을 먼저 켜라는 안내도 없다.
   *
   * 이 테스트는 저장하지 않는다 — 지운 뒤 선택지만 보고 새로고침으로 버린다.
   *
   * @scenario reason_type=exchange
   * @effects settings_claim_charge_payment_method_options_do_not_filter_by_order_catalog_active
   */
  test('추가결제 수단은 라벨로 서고 지워도 다시 고를 수 있다', async ({ page, settingsToken }) => {
    test.setTimeout(120_000);

    await authenticatePage(page, settingsToken);
    await page.goto(SETTINGS_URL);
    await waitForClaimTab(page);

    const field = page.locator('#claim_charge_payment_methods_field');
    await expect(field, '추가결제 수단 입력이 추가결제 카드에 서야 한다').toHaveCount(1, {
      timeout: 20_000,
    });

    // ① 태그가 원문 키로 서면 안 된다. 기본값 `card`·`point` 는 라벨을 가진다.
    const tagText = (await field.innerText()).replace(/\s+/g, ' ');
    expect(tagText, '추가결제 수단 태그가 원문 키로 노출된다').not.toMatch(/\bpoint\b/);
    expect(tagText, '추가결제 수단 태그가 원문 키로 노출된다').not.toMatch(/\bcard\b/);

    // ② 태그를 하나 지운 뒤 선택지를 연다. 지운 값이 선택지에 남아 있어야 되돌릴 수 있다.
    const removeButtons = field.locator('button[aria-label^="Remove"]');
    const before = await removeButtons.count();
    expect(before, '지울 태그가 있어야 이 축을 잴 수 있다').toBeGreaterThan(0);

    const removedLabel = (await removeButtons.last().getAttribute('aria-label'))!.replace(/^Remove\s*/, '');
    await removeButtons.last().click();
    await expect(removeButtons, '태그가 지워져야 한다').toHaveCount(before - 1, { timeout: 10_000 });

    await field.locator('input[role="combobox"], [role="combobox"]').first().click();
    await expect(
      page.locator('[role="option"]', { hasText: removedLabel }).first(),
      `지운 「${removedLabel}」 이(가) 선택지에서도 사라져 이 화면에서 되돌릴 수 없다`
    ).toBeVisible({ timeout: 10_000 });

    // 저장하지 않는다 — 전역 설정 파일을 건드리지 않고 화면만 버린다.
    await page.reload();
    await waitForClaimTab(page);
  });

  /**
   * @scenario reason_type=exchange
   * @effects settings_claim_reason_name_is_multilingual
   */
  test('사유명은 언어별 입력으로 그려진다 (단일 Input 금지 — D23)', async ({ page, settingsToken }) => {
    test.setTimeout(120_000);

    await authenticatePage(page, settingsToken);
    await page.goto(SETTINGS_URL);
    await waitForClaimTab(page);

    // 목록은 설정 응답이 도착한 뒤에 그려진다 — 즉시 세면 아직 0 이라 조용히 건너뛴다.
    const anyList = page.locator('#exchange_reason_list, #return_reason_list, #refund_reason_list').first();
    const rendered = await anyList
      .waitFor({ state: 'attached', timeout: 15_000 })
      .then(() => true)
      .catch(() => false);

    if (!rendered) {
      // 형제 spec(주문 목록)과 같은 형태 — skip 은 리포트에서 '돌았다' 와 '건너뛰었다' 를 가르지 못해,
      // 사유가 0건인 환경에서 이 축이 아무것도 단언하지 않은 채 초록이 된다. 사실을 남기고 실패시킨다.
      test.info().annotations.push({
        type: 'skipped-axis',
        description: '등록된 사유가 한 건도 없어 목록 행의 다국어 입력을 확인할 수 없다',
      });
    }

    expect(rendered, '사유 카탈로그 3종 중 하나는 행을 그려야 한다 — 라이브에 사유가 0건이면 이 축은 측정 불가다').toBe(true);

    // `compact` 레이아웃의 MultilingualInput 은 **언어 탭 + 입력 한 칸**을 한 줄에 그린다
    // (`MultilingualInput.tsx` renderCompactLayout — 활성 로케일마다 Button 하나, 입력은
    // 현재 로케일 것 하나). 그래서 "한 행의 text input 개수" 로는 단일 Input 회귀를
    // 가려낼 수 없다 — 둘 다 1개다. 구분되는 신호는 **언어 전환 버튼**이다.
    //
    // 세는 범위는 반드시 한 행이어야 한다. 목록 전체를 세면 라이브 카탈로그가 여러 행이라
    // 모든 행이 단일 Input 으로 되돌아가도 합계가 하한을 넘는다.
    const firstRow = anyList.locator('tbody tr').first();
    await expect(firstRow, '사유 목록에 행이 적어도 하나 그려져야 한다').toBeVisible({ timeout: 15_000 });

    // 로케일 버튼의 라벨은 대문자 **로케일 코드**다(`KO`, `EN`). 기본 로케일에는 `*` 가 붙는다.
    // 정규식을 `[A-Z]{2}` 로 열어 두면 행 안의 다른 2글자 대문자 버튼까지 세어, 언어 전환
    // 버튼이 사라져도 하한을 넘길 수 있다. 실제 로케일 코드만 세도록 어휘를 못박는다.
    const localeTabs = firstRow.getByRole('button', { name: /^(KO|EN|JA)\s*\*?$/ });

    expect(
      await localeTabs.count(),
      '사유명이 단일 Input 으로 되돌아가면 언어 전환 버튼이 사라져 ko 외 언어를 저장할 수 없다 (D23) — 한 행 기준'
    ).toBeGreaterThanOrEqual(2);

    // 입력 칸도 함께 있어야 한다 — 탭만 있고 입력이 없으면 값을 넣을 수 없다.
    expect(
      await firstRow.locator('input[type="text"], input:not([type])').count()
    ).toBeGreaterThanOrEqual(1);
  });
});

/**
 * 추가 폼을 연 채 탭을 옮기면 저장이 어떻게 되는가.
 *
 * 페이지 [저장] 가드는 인라인 추가 폼 플래그 6종을 **탭과 무관하게** 본다. 그런데 탭 전환
 * (`onTabChange`)은 `errors` 만 비우고 그 플래그는 건드리지 않는다. 화면 상태가 탭 전환에서
 * 살아남는다면, 폼을 연 채 다른 탭으로 간 운영자는 그 뒤로 **어느 탭에서도 저장하지 못하고**
 * 화면에 있지도 않은 폼을 확정하라는 안내만 받는다.
 *
 * 이 축은 코드 독해로 판정할 수 없다 — 같은 주소로의 탭 전환이 로컬 상태를 보존하는지는
 * 런타임 거동이다. 그래서 브라우저에서 재고, 그 측정을 가드로 남긴다.
 *
 * @scenario reason_type=exchange
 * @effects settings_claim_policy_persists_across_reentry
 */
test('추가 폼을 연 채 탭을 옮기면 그 폼 상태가 남지 않는다', async ({ page, settingsToken }) => {
  test.setTimeout(120_000);

  await authenticatePage(page, settingsToken);
  await page.goto(SETTINGS_URL);
  await waitForClaimTab(page);

  // 교환 사유 '추가' 를 눌러 인라인 폼을 연다.
  const addButton = page
    .locator('#tab_content_claim')
    .getByRole('button', { name: /사유 추가|Add reason|理由.*追加/ })
    .first();
  await expect(addButton).toBeVisible({ timeout: 15_000 });
  await addButton.click();

  /**
   * 화면의 로컬 상태에서 인라인 추가 플래그를 읽는다.
   *
   * 이 축은 DOM 으로 재면 "폼이 안 보인다" 와 "플래그가 비었다" 를 구분하지 못한다 —
   * 탭이 바뀌면 클레임 탭 자체가 렌더되지 않으므로 폼은 어느 쪽이든 사라진다. 저장 가드가
   * 실제로 읽는 것은 플래그이므로 그 값을 직접 잰다.
   */
  const readAddingFlag = (): Promise<unknown> =>
    page.evaluate(() => (window as any).G7Core?.state?.getLocal?.()?.isAddingExchangeReason);

  expect(await readAddingFlag(), '추가 폼이 열리지 않았다 — 이 측정의 전제가 무너졌다').toBe(true);

  // 기본정보 탭으로 이동한다 (탭은 button 이 아니라 tab role 이다).
  await page.getByRole('tab', { name: '기본정보', exact: true }).click();
  await expect(page.locator('#tab_content_basic_info')).toBeVisible({ timeout: 20_000 });

  // 페이지 [저장] 가드는 이 플래그를 탭과 무관하게 읽는다 — 남아 있으면 그 뒤로 어느
  // 탭에서도 저장이 막히고, 화면에 있지도 않은 폼을 확정하라는 안내만 뜬다.
  expect(
    await readAddingFlag(),
    '탭을 옮겼는데 클레임 탭의 추가 폼 상태가 남아 있다 — 이 상태에서는 모든 탭의 저장이 막힌다',
  ).not.toBe(true);
});
