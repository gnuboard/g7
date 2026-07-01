/**
 * 본인인증 이력 화면 — 필터 체크박스 렌더 + 채널명 다국어 라벨 회귀 (#415)
 *
 * 배경 (엔진 v1.50.2 + 채널 라벨 수정):
 *  - 결함 #1/#2: 상태/발생유형 필터의 배열 리터럴 source(`['requested'...]`)가
 *    isComplexExpression 정규식에 `[]{}` 누락으로 resolve() 경로 → undefined 가 되어
 *    체크박스가 전혀 렌더되지 않던 결함.
 *  - 결함 #3: 채널 필터의 `Array.from(new Set(...))` 가 reserved 화이트리스트에
 *    Set 누락으로 `new undefined()` 에러 → 빈 배열 → 체크박스 미렌더.
 *  - 채널명 버그: 채널이 `email`/`ipin` 영문 코드(식별자)로 표시되던 것을, 각 provider 가
 *    getChannelLabels() 로 제공하는 다국어 라벨로 표시하도록 수정.
 *
 * 검증은 로케일 비의존으로 구성한다 — Playwright 발급 토큰 세션은 기본 영어 로케일로
 * 부팅되므로 한국어 텍스트 매칭은 깨진다. 대신 (1) 체크박스 그룹 개수, (2) 채널 식별자
 * (email/ipin) 가 라벨로 노출되지 않음 을 단언한다. 식별자 부재는 로케일과 무관하게
 * 채널명 버그를 잠근다.
 *
 * 단위 테스트(엔진 회귀 + 레이아웃 렌더) green 상태에서도 실제 브라우저 렌더 결함을
 * 놓칠 수 있어(위지윅 발행 회귀 #238 교훈) 실제 페이지에서 검증한다.
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const IDENTITY_LOGS_PERMISSION = 'core.admin.identity.logs.read';

/**
 * 본인인증 이력 화면에 진입하고 필터/그리드가 마운트될 때까지 대기한다.
 * 로케일 비의존 — URL 이 /admin/login 으로 튕기지 않고 체크박스가 1개 이상 그려지면 마운트 완료로 본다.
 */
async function gotoIdentityLogs(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/admin/identity/logs');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForFunction(
    () => !window.location.pathname.includes('/admin/login'),
    { timeout: 15_000 },
  );
  expect(page.url(), '권한 토큰임에도 로그인으로 리다이렉트됨').not.toMatch(/\/admin\/login/);
  // 데이터소스(identityProviders/identityPurposes/identityLogs) 로딩 완료 대기.
  // transition_overlay 진행 중에는 목적/채널 체크박스가 아직 안 그려진다
  // (상태/발생유형은 정적 배열이라 먼저 렌더되지만, 목적/채널은 API 의존).
  // "페이지 로딩 중" progressbar 가 사라질 때까지 대기한다.
  await page
    .getByRole('progressbar')
    .first()
    .waitFor({ state: 'detached', timeout: 20_000 })
    .catch(() => { /* progressbar 가 처음부터 없으면 무시 */ });
  // 필터 영역 체크박스가 그려질 때까지 대기 (상태/목적/채널 — 결함 수정 후 다수 렌더)
  await page.waitForFunction(
    () => document.querySelectorAll('input[type="checkbox"]').length > 0,
    { timeout: 15_000 },
  );
}

/** 채널 필터 영역 라벨 텍스트(식별자 또는 라벨)를 수집한다. */
async function collectFilterCheckboxLabels(page: import('@playwright/test').Page): Promise<string[]> {
  return page.evaluate(() => {
    const out: string[] = [];
    document.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
      const wrap = (cb as HTMLElement).closest('label');
      const text = wrap?.innerText?.trim() ?? '';
      if (text) out.push(text);
    });
    return out;
  });
}

test('@smoke 본인인증 이력 — 상태/목적 필터 체크박스가 다수 렌더된다 (결함 #1)', async ({ page }) => {
  const token = issueToken(IDENTITY_LOGS_PERMISSION);
  await authenticatePage(page, token);
  await gotoIdentityLogs(page);

  // 결함 #1 이전: 상태/목적 필터가 '전체' 체크박스만 렌더되어 총 체크박스가 매우 적었다.
  // 수정 후: 상태 7종 + (전체) + 목적 6종 + (전체) + 채널 → 10개 이상.
  // 데이터소스 비동기 로딩이 있으므로 polling 으로 안정화될 때까지 대기한다.
  await page.waitForFunction(
    () => document.querySelectorAll('input[type="checkbox"]').length >= 10,
    { timeout: 15_000 },
  ).catch(() => { /* 실패 시 아래 단언이 실제 개수로 명확히 보고 */ });
  const count = await page.evaluate(
    () => document.querySelectorAll('input[type="checkbox"]').length,
  );
  expect(count, '필터 체크박스가 거의 렌더되지 않음 — 배열 리터럴 source 미렌더 결함 #1 회귀').toBeGreaterThanOrEqual(10);
});

test('@smoke 본인인증 이력 — 발생 유형(고급 검색) 필터 체크박스가 렌더된다 (결함 #2)', async ({ page }) => {
  const token = issueToken(IDENTITY_LOGS_PERMISSION);
  await authenticatePage(page, token);
  await gotoIdentityLogs(page);

  const before = await page.evaluate(
    () => document.querySelectorAll('input[type="checkbox"]').length,
  );

  // 고급 검색 토글을 펼치면 발생 유형(origin_type) 7종 체크박스가 추가로 나타나야 한다.
  // 로케일 비의존: 펼침 토글은 마지막 toggle 성격 버튼 — chevron 아이콘을 가진 버튼을 클릭한다.
  await page.evaluate(() => {
    const btns = Array.from(document.querySelectorAll('button'));
    // '고급 검색' / 'Advanced' 텍스트 또는 chevron 아이콘 보유 버튼
    const target = btns.find((b) => /고급|advanced/i.test(b.textContent || '')
      || b.querySelector('[class*="chevron"]'));
    (target as HTMLButtonElement | undefined)?.click();
  });

  await page.waitForFunction(
    (prev) => document.querySelectorAll('input[type="checkbox"]').length > prev,
    before,
    { timeout: 10_000 },
  );

  const after = await page.evaluate(
    () => document.querySelectorAll('input[type="checkbox"]').length,
  );
  // origin_type 7종이 추가됨 → 최소 7개 증가
  expect(after - before, '고급 검색 펼침 후 발생 유형 체크박스가 추가되지 않음 — 결함 #2 회귀').toBeGreaterThanOrEqual(7);
});

test('@smoke 본인인증 이력 — 채널 필터가 식별자가 아닌 라벨로 표시된다 (결함 #3 + 채널명 버그)', async ({ page }) => {
  const token = issueToken(IDENTITY_LOGS_PERMISSION);
  await authenticatePage(page, token);
  await gotoIdentityLogs(page);

  // 채널 체크박스는 identityProviders API 로딩 후 그려진다 — 이메일/Email 라벨 등장 대기.
  await page.waitForFunction(
    () => {
      const labels: string[] = [];
      document.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
        const t = (cb as HTMLElement).closest('label')?.innerText?.trim() ?? '';
        if (t) labels.push(t);
      });
      return labels.includes('이메일') || labels.includes('Email');
    },
    { timeout: 15_000 },
  ).catch(() => { /* 실패 시 아래 단언이 명확히 보고 */ });

  const labels = await collectFilterCheckboxLabels(page);

  // 채널 식별자(email/ipin) 가 필터 라벨로 직접 노출되면 안 된다 (채널명 버그 회귀).
  // 로케일 무관: 식별자는 어떤 로케일에서도 라벨이 아니다.
  expect(labels, "채널 필터가 raw 식별자 'email' 로 표시됨 (채널명 버그 회귀)").not.toContain('email');
  expect(labels, "채널 필터가 raw 식별자 'ipin' 로 표시됨 (채널명 버그 회귀)").not.toContain('ipin');

  // 결함 #3: 채널 체크박스 자체가 렌더되어야 한다. 채널 라벨은 provider 가 제공하므로
  // 코어 이메일 채널 라벨(ko='이메일' / en='Email')이 존재해야 한다.
  const hasEmailChannel = labels.some((l) => l === '이메일' || l === 'Email');
  expect(hasEmailChannel, '채널 필터에 이메일 채널 라벨이 렌더되지 않음 — 결함 #3 회귀').toBe(true);
});

test('@smoke 본인인증 이력 — 인증수단 탭 전환 시 상태 필터가 초기화된다 (#420-4)', async ({ page }) => {
  // @scenario tab-switch-filter-reset
  // @effects local-filter-reset, query-cleared, datagrid-refetch
  //
  // 배경(라이브 증거): 상태 필터(예: '요청됨') 체크 후 인증수단 탭을 전환하면
  // 체크박스는 체크된 채로 남는데 목록은 필터 없는 전체가 나오던 불일치 결함.
  // 수정: onTabChange 가 sequence[ setState(filter 리셋) → navigate ] 로 동작 →
  // 탭 전환 즉시 체크박스가 풀리고 조회도 전체로 갱신되어 UI=조회 일치.
  //
  // 로케일 비의존: 텍스트 매칭 대신 (1) 상태 체크박스 1개 체크 + 검색 →
  // (2) provider 탭으로 전환 → (3) 개별 체크박스 unchecked + URL 에 statuses 제거 단언.
  //
  // 탭 DOM 구조(라이브 확인): TabNavigation 은 role="tab" 이 아니라
  // <nav id="provider_tabs"><button class="tab-btn-base">…</button></nav> 마크업.
  // 따라서 '#provider_tabs button' 으로 탭을 선택한다.
  //
  // 필터 적용 메커니즘(라이브 확인): 체크박스 클릭은 _local.filter 만 갱신하고
  // URL 에는 반영되지 않는다. '검색' 버튼을 눌러야 _local.filter → URL 쿼리로
  // 직렬화되어 실제 필터가 적용된다. 따라서 baseline 은 체크 + 검색까지 수행한다.
  const token = issueToken(IDENTITY_LOGS_PERMISSION);
  await authenticatePage(page, token);
  await gotoIdentityLogs(page);

  // 탭이 2개 이상이어야 전환 검증이 가능 (전체 + 최소 1개 provider).
  const tabCount = await page.evaluate(
    () => document.querySelectorAll('#provider_tabs button').length,
  );
  test.skip(tabCount < 2, 'provider 탭이 부족해 탭 전환 검증 불가 (provider 미등록 환경)');

  // (1) '전체'가 아닌 상태 체크박스 하나(인덱스 1)를 체크하고 '검색'을 눌러 필터를 적용한다.
  const checkedOk = await page.evaluate(() => {
    const boxes = Array.from(
      document.querySelectorAll('input[type="checkbox"]'),
    ) as HTMLInputElement[];
    const target = boxes[1]; // '상태 전체'(0) 다음의 개별 상태 체크박스
    if (!target) return false;
    target.click();
    const ok = target.checked;
    const searchBtn = Array.from(document.querySelectorAll('button')).find(
      (b) => /검색|search/i.test(b.textContent || '')
        && b.className.includes('btn-primary'),
    ) as HTMLButtonElement | undefined;
    searchBtn?.click();
    return ok;
  });
  expect(checkedOk, '개별 상태 체크박스 체크 실패').toBe(true);

  // 검색 후 URL 에 statuses 필터가 실제 적용될 때까지 대기 (baseline 확정).
  await page.waitForFunction(
    () => window.location.search.includes('statuses'),
    { timeout: 10_000 },
  );

  // (2) provider 탭(전체 다음 = 첫 번째 인증수단)으로 전환한다.
  await page.evaluate(() => {
    const tabs = Array.from(
      document.querySelectorAll('#provider_tabs button'),
    ) as HTMLElement[];
    tabs[1]?.click();
  });

  // 탭 전환 = sequence[setState(filter 리셋) → navigate(replace)] →
  // URL 에서 statuses[] 가 제거될 때까지 대기.
  await page.waitForFunction(
    () => !window.location.search.includes('statuses'),
    { timeout: 10_000 },
  );

  // (3) 전환 후 개별 체크박스가 모두 unchecked 여야 한다 (필터 초기화).
  //     '전체' 토글류(빈 배열 조건)는 체크될 수 있으므로 개별 항목만 본다.
  const anyIndividualChecked = await page.evaluate(() => {
    const boxes = Array.from(
      document.querySelectorAll('input[type="checkbox"]'),
    ) as HTMLInputElement[];
    return boxes.slice(1).some((b) => b.checked);
  });
  expect(
    anyIndividualChecked,
    '탭 전환 후에도 개별 상태 체크박스가 체크된 채 남음 — #420-4 필터 미초기화 회귀',
  ).toBe(false);

  // URL 쿼리에도 필터가 남아있지 않아야 한다 (조회 = 전체).
  expect(page.url(), '탭 전환 후 URL 에 상태 필터가 잔존 — 조회/표시 불일치 회귀')
    .not.toContain('statuses');
});

test('@smoke 본인인증 이력 — DataGrid 채널 컬럼이 식별자가 아닌 라벨로 표시된다 (채널명 버그)', async ({ page }) => {
  const token = issueToken(IDENTITY_LOGS_PERMISSION);
  await authenticatePage(page, token);
  await gotoIdentityLogs(page);

  // 행이 있을 때만 채널 셀의 raw 식별자 부재를 단언 (샘플 데이터 비어있을 수 있음).
  const channelCells = await page.evaluate(() => {
    const out: string[] = [];
    document.querySelectorAll('td, [role="cell"], [class*="cell"]').forEach((c) => {
      const t = (c as HTMLElement).innerText?.trim() ?? '';
      if (t === 'email' || t === 'ipin') out.push(t);
    });
    return out;
  });

  expect(channelCells, "DataGrid 채널 컬럼에 raw 식별자가 표시됨 (채널명 버그 회귀)").toEqual([]);
});
