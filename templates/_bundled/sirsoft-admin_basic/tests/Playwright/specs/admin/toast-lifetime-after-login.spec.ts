/**
 * sirsoft-admin_basic — 로그인 화면에서 띄운 알림이 관리자 화면으로 넘어가도 한 번, 정해진 시간만 보인다.
 *
 * 결함: 관리자가 정해 준 비밀번호로 처음 로그인해 「새 비밀번호를 설정해 주세요」 단계에서 비밀번호를 바꾸면, 로그인 화면이 띄운
 *   「비밀번호를 변경했습니다…」 알림이 첫 화면(`/admin`)을 거쳐 대시보드로 한 번 더 넘어가는 동안 Toast 가 다시 마운트됐다.
 *   새 Toast 는 마운트 시각부터 5초를 다시 셌고, 앞 Toast 가 남긴 타이머가 원래 시각에 상태에서 항목을 빼 숨김 동작 없이 사라지거나,
 *   표시 시간이 지난 항목이 늦게 그려진 화면에서 다시 나타날 수 있었다.
 * 고침: Toast 가 항목의 만든 시각(`createdAt`)부터 세어 남은 시간만 보이고, 이미 지난 항목은 그리지 않고 상태에서 지운다.
 *
 * 단위 시험(`src/components/composite/__tests__/Toast.lifetime.test.tsx`)은 다시 마운트·빈 화면·지난 항목을 재고,
 * 이 spec 은 실제 로그인 화면 → 첫 화면 → 대시보드 흐름에서 알림이 보인 구간과 상태 항목을 잰다.
 *
 * 계정: 관리자 토큰(전체 권한)으로 「일반 관리자」(`manager`, 대시보드 권한 있음) 역할 회원을 만든다 — 화면의 「회원 추가」와 같은 API 다.
 *   관리자가 정해 준 비밀번호라 첫 로그인에 변경 단계가 떠야 한다(뜨지 않으면 이 사이트 정책에서는 잴 수 없어 실패로 알린다).
 *   끝나면 그 회원을 지운다(지우기 실패는 시험 실패).
 *
 * 기록: 문서마다 심는 스크립트가 50ms 간격으로 「보이는 알림 수(role=alert, 크기 > 0, opacity-100)」·상태 항목 수·경로·본문 글자 수를
 *   `sessionStorage` 에 남긴다(전체 페이지 이동이 끼어도 이어진다). 측정값은 시험 주석(annotations)으로도 남긴다.
 *   쓰기는 페이지 스크립트보다 먼저 잡아 둔 원래 `Storage.prototype.setItem` 으로 한다 — 설치된 GDPR 플러그인
 *   (`sirsoft-gdpr` storageInterceptor)이 동의 전에는 허용목록 밖 키의 쓰기를 조용히 버리고 사용자 조작 뒤 500ms 만
 *   통과시키므로, 페이지의 `sessionStorage.setItem` 을 그대로 쓰면 클릭 직후 0.5초 분량만 남고 기록이 끊긴다.
 *
 * 대기: 관리자 화면은 실시간 연결 등으로 네트워크가 조용해지지 않아 `networkidle` 을 쓰지 않는다. 서버가 느린 환경(응답 3~5초)에 맞춰
 *   대기는 넉넉히 잡는다(실행 예: `--timeout=120000`).
 *
 * 시나리오 매니페스트: `tests/scenarios/toast-lifetime-across-remount.yaml`
 * @effects toast_keeps_lifetime_across_remount, toast_expired_item_never_reappears, toast_shows_only_remaining_time_from_created_at
 */
import { test, expect, issueToken } from '../../fixtures/admin-template-auth';
import type { APIRequestContext } from '@playwright/test';

const ROLE = 'manager';
const INITIAL_PASSWORD = 'ToastLife!Passw0rd#g7';
/** 주소·요소 대기 상한(ms) — 느린 서버 기준 */
const WAIT = 60_000;
/** `_admin_base`·`admin_login` 의 Toast 기본 표시 시간(ms) */
const TOAST_DURATION = 5_000;
/**
 * 표본 간격(50ms) + 타이머 지연 여유. 숨김은 만든 시각 + 표시 시간에 시작한다(그 뒤 300ms 는 흐려지는 동작이라 「보임」 으로 세지 않는다).
 * 종전 Toast 는 다시 마운트되면 앞 Toast 타이머가 상태에서 빼는 만든 뒤 5.3초까지 그대로 보였다.
 */
const TOLERANCE = 200;
const MESSAGES = ['비밀번호를 변경했습니다', 'Your password has been changed'];

let adminToken: string;
let account: { uuid: string; email: string } | null = null;

/** [시각(epoch ms), 보이는 알림 수, 상태 항목 수, 경로, 본문 글자 수] */
type Sample = [number, number, number, string, number];

interface Trace {
  docs: number;
  armed: boolean;
  samples: Sample[];
  items: Record<string, number | null>;
}

/**
 * 관리자 토큰을 실은 요청 머리.
 *
 * @returns 머리
 */
function auth(): Record<string, string> {
  return { Authorization: `Bearer ${adminToken}`, Accept: 'application/json' };
}

/**
 * 역할 하나를 가진 관리자 회원을 만든다(관리자가 정해 준 비밀번호).
 *
 * @param request API 문맥
 * @returns 만든 회원 uuid·이메일
 */
async function createAdmin(request: APIRequestContext): Promise<{ uuid: string; email: string }> {
  const roles = await (await request.get('/api/admin/roles/active', { headers: auth() })).json();
  const rows = (roles?.data?.data ?? roles?.data ?? []) as Array<{ id: number; identifier: string }>;
  const role = rows.find((r) => r.identifier === ROLE);
  if (!role) throw new Error(`역할 ${ROLE} 이 없습니다`);

  const email = `toast-life-${Date.now()}@playwright.local`;
  const res = await request.post('/api/admin/users', {
    headers: auth(),
    data: { name: '알림 시간 시험', email, password: INITIAL_PASSWORD, password_confirmation: INITIAL_PASSWORD, status: 'active', role_ids: [role.id] },
  });
  const body = await res.json();
  if (!res.ok()) throw new Error(`회원 만들기 실패 ${res.status()}: ${JSON.stringify(body).slice(0, 400)}`);

  return { uuid: body?.data?.uuid ?? body?.data?.data?.uuid, email };
}

/**
 * 표본에서 알림이 보인 구간을 뽑는다.
 *
 * @param samples 표본
 * @returns [시작, 끝] 목록(epoch ms)
 */
function visibleSegments(samples: Sample[]): Array<[number, number]> {
  const segments: Array<[number, number]> = [];
  let start: number | null = null;
  let last = 0;
  for (const [t, visible] of samples) {
    if (visible > 0) {
      if (start === null) start = t;
      last = t;
    } else if (start !== null) {
      segments.push([start, last]);
      start = null;
    }
  }
  if (start !== null) segments.push([start, last]);

  return segments;
}

test.describe.configure({ mode: 'serial' });

test.describe('@sirsoft-admin_basic 로그인 뒤 알림 표시 시간', () => {
  test.beforeAll(async ({ playwright }) => {
    adminToken = issueToken('core.users.read', 'core.users.create', 'core.users.delete', 'core.permissions.read');
    const request = await playwright.request.newContext({ ignoreHTTPSErrors: true, baseURL: test.info().project.use.baseURL });
    account = await createAdmin(request);
    await request.dispose();
  });

  test.afterAll(async ({ playwright }) => {
    if (!account?.uuid) return;
    const request = await playwright.request.newContext({ ignoreHTTPSErrors: true, baseURL: test.info().project.use.baseURL });
    const res = await request.delete(`/api/admin/users/${account.uuid}`, { headers: auth() });
    const gone = await request.get(`/api/admin/users/${account.uuid}`, { headers: auth() });
    await request.dispose();
    expect(res.ok(), `시험 계정 지우기 ${res.status()}`).toBe(true);
    expect(gone.status(), '지운 계정 조회').toBe(404);
  });

  // @scenario mount=remount
  // @effects toast_keeps_lifetime_across_remount, toast_expired_item_never_reappears, toast_shows_only_remaining_time_from_created_at
  test('비밀번호 변경 단계에서 바꾸고 로그인하면 「비밀번호를 변경했습니다」 알림이 대시보드까지 한 번, 만든 뒤 5초 안에만 보인다', async ({ browser }) => {
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await context.newPage();
    try {
      await page.addInitScript((messages: string[]) => {
        const KEY = '__g7ToastTrace';
        // 페이지 스크립트(GDPR 플러그인의 setItem 가로채기 포함)보다 먼저 원래 setItem 을 잡아 둔다
        const nativeSetItem = Storage.prototype.setItem;
        const write = (key: string, value: string): void => nativeSetItem.call(sessionStorage, key, value);
        Object.defineProperty(window, '__g7ToastTraceWrite', { value: write, configurable: true });
        let trace: { docs: number; armed: boolean; samples: unknown[]; items: Record<string, number | null> };
        try {
          trace = JSON.parse(sessionStorage.getItem(KEY) ?? '') as typeof trace;
        } catch {
          trace = { docs: 0, armed: false, samples: [], items: {} };
        }
        trace.docs += 1;
        const save = (): void => {
          try {
            write(KEY, JSON.stringify(trace));
          } catch {
            /* 저장소를 못 쓰면 기록만 빠진다 */
          }
        };
        save();
        nativeSetItem.call(localStorage, 'g7_locale', 'ko');
        const matches = (text: unknown): boolean => messages.some((m) => String(text ?? '').includes(m));
        window.setInterval(() => {
          if (!trace.armed) {
            trace.armed = sessionStorage.getItem('__g7ToastArmed') === '1';
            if (!trace.armed) return;
          }
          const alerts = Array.from(document.querySelectorAll('[role="alert"]')).filter((el) => matches(el.textContent));
          const visible = alerts.filter((el) => {
            const rect = el.getBoundingClientRect();
            return rect.width > 0 && rect.height > 0 && (el as HTMLElement).className.includes('opacity-100');
          }).length;
          const toasts = ((window as any).G7Core?.state?.get?.()?.toasts ?? []) as Array<{ id: string; message: unknown; createdAt?: number }>;
          const mine = toasts.filter((t) => matches(t.message));
          mine.forEach((t) => {
            trace.items[t.id] = typeof t.createdAt === 'number' ? t.createdAt : null;
          });
          if (trace.samples.length < 800) {
            trace.samples.push([Date.now(), visible, mine.length, location.pathname, (document.body?.innerText ?? '').length]);
          }
          save();
        }, 50);
      }, MESSAGES);

      await page.goto('/admin/login');
      await page.locator('input[name="email"]').waitFor({ state: 'visible', timeout: WAIT });
      await page.fill('input[name="email"]', account!.email);
      await page.fill('input[name="password"]', INITIAL_PASSWORD);
      await page.click('form button[type="submit"]');

      const changeStep = page.locator('input[name="password_change_new"]');
      await expect(changeStep, '관리자가 정해 준 비밀번호의 첫 로그인 변경 단계').toBeVisible({ timeout: WAIT });
      const next = `ToastLife!Passw0rd#g7-${Date.now()}`;
      await changeStep.fill(next);
      await page.fill('input[name="password_change_confirm"]', next);
      await page.evaluate(() =>
        (window as unknown as { __g7ToastTraceWrite: (k: string, v: string) => void }).__g7ToastTraceWrite('__g7ToastArmed', '1')
      );
      // 시작 표시가 실제로 남았는지 — 남지 않으면 아래 측정이 전부 비어 원인을 알 수 없다
      expect(await page.evaluate(() => sessionStorage.getItem('__g7ToastArmed')), '기록 시작 표시').toBe('1');
      await page.getByRole('button', { name: '변경하고 로그인' }).click();

      // 첫 화면을 거쳐 대시보드가 그려질 때까지
      await page.waitForURL((url) => url.pathname === '/admin/dashboard', { timeout: WAIT });
      await expect(page.locator('a[href="/admin/dashboard"]').first(), '대시보드의 사이드바').toBeVisible({ timeout: WAIT });

      // 알림이 처음 상태에 들어간 시각부터 표시 시간 + 2.5초, 그리고 대시보드가 그려진 뒤 2초를 더 지켜본다
      const readTrace = async (): Promise<Trace> =>
        (await page.evaluate(() => JSON.parse(sessionStorage.getItem('__g7ToastTrace') ?? '{}'))) as Trace;
      let trace = await readTrace();
      const createdAts = Object.values(trace.items ?? {}).filter((v): v is number => typeof v === 'number');
      expect(createdAts.length, '상태에 들어간 「비밀번호를 변경했습니다」 항목(만든 시각 포함)').toBe(1);
      const createdAt = createdAts[0];
      await page.waitForTimeout(Math.max(2_000, createdAt + TOAST_DURATION + 2_500 - Date.now()));
      trace = await readTrace();

      const samples = trace.samples ?? [];
      const segments = visibleSegments(samples);
      const shownMs = segments.reduce((sum, [s, e]) => sum + (e - s), 0);
      const blankOnDashboard = samples.filter(([, , , path, chars]) => path === '/admin/dashboard' && chars === 0).length;
      test.info().annotations.push({
        type: 'toast-trace',
        description: [
          `문서 수 ${trace.docs}(1 이면 전체 페이지 이동 없음)`,
          `항목 ${Object.keys(trace.items).length}건, 만든 시각 ${createdAt}`,
          `보인 구간 ${segments.map(([s, e]) => `${s - createdAt}~${e - createdAt}ms`).join(', ') || '없음'}`,
          `보인 시간 합 ${shownMs}ms`,
          `대시보드 경로에서 본문 글자 수 0 표본 ${blankOnDashboard}개 / 전체 ${samples.length}개`,
        ].join(' · '),
      });

      // 같은 알림이 한 번에 두 개 보이지 않는다
      expect(Math.max(0, ...samples.map(([, visible]) => visible)), '동시에 보인 알림 수 최대').toBeLessThanOrEqual(1);
      // 알림은 보였다
      expect(segments.length, '알림이 보인 구간').toBeGreaterThan(0);
      // 만든 시각 + 표시 시간을 넘겨 보이지 않는다 — 늦게 그려진 화면에서 다시 나타나지 않는다
      const lastShown = segments[segments.length - 1][1];
      expect(lastShown - createdAt, '마지막으로 보인 시각(만든 뒤 ms)').toBeLessThanOrEqual(TOAST_DURATION + TOLERANCE);
      // 대시보드가 그려진 뒤에는 상태 항목도 남지 않는다
      const lastSample = samples[samples.length - 1];
      expect(lastSample?.[2], '끝에 남은 상태 항목 수').toBe(0);
    } finally {
      await context.close();
    }
  });
});
