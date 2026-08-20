/**
 * E2E: 레이아웃 외부 스크립트 신뢰 출처 허용목록 (배포 번들 기준, KVE-2026-1915)
 *
 * 배경: B-2 원격 스크립트 차단이 same-origin 이 아닌 모든 스크립트를 무조건 막아
 * 번들 확장(CKEditor5 cdn.ckeditor.com, Daum 우편번호 t1.daumcdn.net)의 CDN 스크립트까지
 * 깨뜨렸다. 확장이 manifest(`trusted_script_hosts`)로 선언한 호스트를 코어가 집계해
 * `window.G7Config.trustedScriptHosts` 로 노출하고, 런타임 스크립트 로더가 그 목록만
 * 예외로 허용하도록 고쳤다.
 *
 * 이 스펙은 서버→블레이드→배포 번들로 이어지는 주입 배선이 실제로 연결돼 있는지
 * (G7Config.trustedScriptHosts 노출), 활성 확장의 선언이 그 목록에 반영되는지, 그리고
 * 실제 화면 로드에서 신뢰 목록 밖 외부 스크립트가 하나도 실행되지 않는지(차단 관찰)를 잠근다.
 *
 * 차단 결정 함수(isAllowedScriptSrc) 자체는 minify 된 배포 번들에서 이름으로 호출할 수 없어
 * 단위 스위트(TemplateApp.scriptSrc.test.ts)가 고정한다. 이 스펙은 그 결정의
 * 관찰 가능한 결과 — "실제 페이지에 same-origin/신뢰호스트 외 스크립트가 로드되지 않음" —
 * 을 네트워크 레벨에서 무조건 측정한다.
 *
 * authority 우회(`/\/evil.com/x.js` · 탭·개행 삽입)도 이 관찰에 포함된다. 그 형태는
 * 브라우저가 외부 origin 으로 해석하므로, 판정이 뚫리면 네트워크에 cross-origin script
 * 요청으로 나타나 아래 offenders 단언에 걸린다 — 요청 URL 은 이미 해석된 절대 URL 이라
 * 우회 문자열 형태와 무관하게 origin 으로 판정된다.
 *
 * 시나리오 축(case)·효과는 매니페스트 tests/scenarios/trusted-script-hosts.yaml 참조.
 * 각 test 의 `// @scenario case=…` 라인 마커가 축 조합을, `// @effects …` 가 효과를 커버한다.
 *
 * 효과 목록을 이 파일 레벨에 몰아 적지 않는다 — 커버리지 룰은 마커 레벨을 구분하지 않으므로,
 * 파일 레벨 목록이 있으면 해당 test 를 지워도 효과가 "언급됨" 으로 집계돼 삭제가 무증상 green
 * 이 된다. 마커는 test 에만 둔다.
 */
import { test, expect } from '../fixtures/auth';

test.describe('레이아웃 외부 스크립트 신뢰 출처 허용목록', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/admin/login');
    await page.waitForFunction(
      () => typeof (window as any).G7Config !== 'undefined',
      null,
      { timeout: 30_000 },
    );
  });

  // @scenario case=trusted_hosts_exposed_in_config
  // @effects trusted_script_host_allowlist_wired
  test('배포 번들에 G7Config.trustedScriptHosts 가 배열로 노출된다', async ({ page }) => {
    const shape = await page.evaluate(() => {
      const hosts = (window as any).G7Config?.trustedScriptHosts;
      return { isArray: Array.isArray(hosts), allStrings: Array.isArray(hosts) && hosts.every((h: unknown) => typeof h === 'string') };
    });

    expect(shape.isArray).toBe(true);
    expect(shape.allStrings).toBe(true);
  });

  // @scenario case=active_extension_host_reflected
  // @effects trusted_script_host_allowlist_wired
  test('활성 확장이 선언한 신뢰 호스트가 허용목록에 반영된다', async ({ page }) => {
    const result = await page.evaluate(() => {
      const cfg = (window as any).G7Config ?? {};
      const hosts: string[] = Array.isArray(cfg.trustedScriptHosts) ? cfg.trustedScriptHosts : [];
      const plugins: Array<{ identifier?: string }> = Array.isArray(cfg.activePlugins) ? cfg.activePlugins : [];
      const has = (id: string) => plugins.some((p) => p.identifier === id);
      return {
        ckeditorActive: has('sirsoft-ckeditor5'),
        daumActive: has('sirsoft-daum_postcode'),
        hasCkeditorHost: hosts.includes('cdn.ckeditor.com'),
        hasDaumHost: hosts.includes('t1.daumcdn.net'),
      };
    });

    // 확장이 활성일 때만 그 선언 호스트가 목록에 있어야 한다(비활성이면 스킵 — 서버 상태 독립).
    if (result.ckeditorActive) {
      expect(result.hasCkeditorHost).toBe(true);
    }
    if (result.daumActive) {
      expect(result.hasDaumHost).toBe(true);
    }
  });

  // @scenario case=untrusted_external_script_not_loaded
  // @effects untrusted_external_script_blocked
  test('실제 화면 로드에서 same-origin·신뢰호스트 외 스크립트는 하나도 로드되지 않는다', async ({ page }) => {
    // 새 로드의 모든 script 네트워크 요청을 수집한다
    const scriptUrls: string[] = [];
    page.on('request', (req) => {
      if (req.resourceType() === 'script') {
        scriptUrls.push(req.url());
      }
    });

    // beforeEach 가 이미 진입한 화면을 다시 로드해 엔진 부팅부터의 스크립트 요청을 포착한다
    await page.reload({ waitUntil: 'networkidle' });

    const cfg = await page.evaluate(() => ({
      origin: window.location.origin,
      hosts: (Array.isArray((window as any).G7Config?.trustedScriptHosts)
        ? (window as any).G7Config.trustedScriptHosts
        : []) as string[],
    }));
    const trusted = new Set(cfg.hosts.map((h) => h.toLowerCase()));

    // 측정 대상이 실제로 존재하는지(공허한 통과 방지) 확인 — 최소한 번들 스크립트는 로드된다
    expect(scriptUrls.length).toBeGreaterThan(0);

    const offenders = scriptUrls.filter((url) => {
      let u: URL;
      try {
        u = new URL(url);
      } catch {
        return false; // 파싱 불가 URL 은 판정 대상 아님
      }
      if (u.protocol !== 'http:' && u.protocol !== 'https:') {
        return false; // data:/blob: 등은 별개 축
      }
      const sameOrigin = u.origin === cfg.origin;
      const trustedHost = trusted.has(u.hostname.toLowerCase());
      return ! sameOrigin && ! trustedHost;
    });

    expect(offenders, `허용되지 않은 외부 스크립트가 로드됨: ${offenders.join(', ')}`).toEqual([]);
  });
});
