/**
 * Step 2 웹 노출 게이트·판정 회귀 테스트 (B-4 · D3 · D21).
 *
 * 서버는 프로브 대상만 내려주고 판정은 브라우저가 한다 — 그래서 게이트의 실체는 전부
 * 이 클라이언트 코드다. 판정(judgeExposureResults)·프로브 1건(probeExposureTarget)·버튼
 * 게이팅(updateStep2Gate)을 프로덕션 소스의 `region:web-exposure` 구간 그대로 평가해
 * 검증한다 (installer-ui-state.test.ts 와 같은 방식 — 사본 금지).
 *
 * @scenario probe_outcome=exposed, judgement=exposed, gate_state=pending, server_pass=true
 *
 * @effects exposure_judgement_prefers_exposed_over_unknown, step2_gate_blocks_until_exposure_judged,
 *          exposure_probe_distinguishes_denied_from_unreachable, step2_gate_treats_missing_server_item_as_undetermined,
 *          symlink_probe_result_is_shown_to_the_operator,
 *          exposure_unmeasured_family_blocks_safe_verdict,
 *          exposure_probe_treats_redirect_as_undetermined,
 *          exposure_card_renders_even_when_server_item_is_missing,
 *          exposure_manual_confirm_path_survives_the_link_notice,
 *          exposure_positive_control_detects_broken_delegation,
 *          exposure_project_root_is_chosen_by_measurement_not_guess,
 *          exposure_probe_treats_marker_less_200_as_undetermined,
 *          exposure_manual_confirm_wording_matches_the_listed_addresses,
 *          exposure_verdict_is_recorded_for_later_audit,
 *          exposure_card_busy_state_clears_only_after_the_verdict
 */
import { readFileSync } from 'fs';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const INSTALLER_SOURCE = path.resolve(
  __dirname,
  '../../../../public/install/assets/js/installer.js',
);

type ExposureRegion = {
  setStep2GateState: (serverAllPassed: boolean, exposureState: string) => void;
  setStep2ExposureState: (exposureState: string) => void;
  updateStep2Gate: () => void;
  getProjectRootUrl: () => string;
  getProjectRootCandidates: () => string[];
  probeExposureControl: (
    root: string,
    control: { path: string; marker: string; label: string },
  ) => Promise<{ path: string; label: string; reachable: boolean }>;
  probeAbsentControl: (
    root: string,
    control: { path: string; label: string } | null,
  ) => Promise<{ status: number; length: number } | null>;
  resolveProjectRoot: (
    controls: Array<{ path: string; marker: string; label: string }>,
  ) => Promise<{ root: string; controlResults: Array<{ reachable: boolean }> }>;
  initialExposureState: (exposure: unknown) => string;
  storageLinkNoticeNeeded: (exposure: unknown) => boolean;
  judgeExposureResults: (
    results: Array<{ exposed: boolean; judged: boolean }>,
    unmeasuredCount?: number,
    controlResults?: Array<{ reachable: boolean }>,
  ) => string;
  probeExposureTarget: (
    root: string,
    target: { path: string; marker: string },
  ) => Promise<{
    path: string;
    exposed: boolean;
    judged: boolean;
    status?: number;
    length?: number | null;
  }>;
  refreshWebExposureCard: (exposure: unknown) => Promise<void>;
  renderServerProtectionGuide: (item: unknown) => string;
};

/**
 * `region:web-exposure` ~ `endregion:web-exposure` 구간을 잘라 평가한다.
 *
 * 구간이 참조하는 화면 공용 헬퍼(`lang`·`renderSingleItemCard`·`escapeHtml`·`escapeAttribute`)는
 * 인자로 주입한다 — 구간 안에 사본을 두면 프로덕션 소스를 그대로 평가한다는 이 하네스의
 * 전제가 깨지고, 구간을 그 셋까지 포함하도록 넓히면 파일 절반이 평가 대상이 된다.
 */
function loadExposureRegion(): ExposureRegion {
  const src = readFileSync(INSTALLER_SOURCE, 'utf8');

  const openMarker = src.indexOf('region:web-exposure');
  const closeMarker = src.indexOf('endregion:web-exposure');

  expect(openMarker, '노출 게이트 구간 시작 표식이 없다 — 표식을 지우면 이 축이 통째로 사각이 된다').toBeGreaterThan(-1);
  expect(closeMarker, '노출 게이트 구간 종료 표식이 없다').toBeGreaterThan(openMarker);

  const bodyStart = src.indexOf('*/', openMarker) + 2;
  const bodyEnd = src.lastIndexOf('/*', closeMarker);
  const body = src.slice(bodyStart, bodyEnd);

  const lang = (key: string) => `L:${key}`;
  const renderSingleItemCard = (title: string, statusClass: string, currentValue: string) =>
    `<div class="requirement-card" data-status="${statusClass}"><span class="card-title">${title}</span><span class="card-value">${currentValue}</span></div>`;
  const escapeHtml = (text: string) => {
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
  };

  // 속성 자리 전용 이스케이퍼 — 프로덕션 판본과 같은 규칙(따옴표까지 이스케이프).
  const escapeAttribute = (value: string) =>
    escapeHtml(value).replace(/"/g, '&quot;').replace(/'/g, '&#39;');

  // eslint-disable-next-line no-new-func
  return new Function(
    'lang',
    'renderSingleItemCard',
    'escapeHtml',
    'escapeAttribute',
    `
    ${body}
    return {
      setStep2GateState,
      setStep2ExposureState,
      updateStep2Gate,
      getProjectRootUrl,
      getProjectRootCandidates,
      probeExposureControl,
      resolveProjectRoot,
      initialExposureState,
      storageLinkNoticeNeeded,
      judgeExposureResults,
      probeExposureTarget,
      probeAbsentControl,
      refreshWebExposureCard,
      renderServerProtectionGuide,
    };
  `,
  )(lang, renderSingleItemCard, escapeHtml, escapeAttribute) as ExposureRegion;
}

let region: ExposureRegion;

beforeEach(() => {
  document.body.innerHTML = `
    <button id="recheck-btn" class="btn btn-secondary hidden">recheck</button>
    <button id="next-btn" disabled class="btn btn-primary hidden">next</button>
    <input type="hidden" id="web-exposure-verdict" name="web_exposure_verdict" value="pending">
    <div id="web-exposure-card" role="status" aria-live="polite" aria-busy="true"></div>
  `;
  (window as unknown as { INSTALLER_BASE_URL: string }).INSTALLER_BASE_URL = '/install';
  region = loadExposureRegion();
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('getProjectRootUrl — 프로브 기준점은 앱 루트가 아니라 프로젝트 루트다', () => {
  const origin = window.location.origin;

  it.each([
    // [INSTALLER_BASE_URL, 기대 접미사] — /public 꼬리를 벗겨야 문서루트=프로젝트 루트
    // 배치에서 노출된 루트(/)를 프로브한다. 앱 루트(/public)에 대고 재면 영원히 놓친다.
    ['/install', ''],
    ['/public/install', ''],
    ['/g7/public/install', '/g7'],
    ['/g7/install', '/g7'],
    // 대소문자 무시 파일시스템(Windows·IIS·macOS)에서 운영자가 다른 표기로 접속하면 기준
    // URL 이 그 표기 그대로 들어온다. 구분해서 벗기면 프로젝트 루트가 `/Public/Install` 로
    // 잡혀 전 대상이 404 → 「보호 확인됨」 으로 조용히 수렴한다 (미탐).
    ['/Install', ''],
    ['/Public/Install', ''],
    ['/g7/PUBLIC/INSTALL', '/g7'],
  ])('%s → 프로젝트 루트 %s', (baseUrl, expectedSuffix) => {
    (window as unknown as { INSTALLER_BASE_URL: string }).INSTALLER_BASE_URL = baseUrl;

    expect(region.getProjectRootUrl()).toBe(`${origin}${expectedSuffix}`);
  });
});

describe('initialExposureState — 서버 항목 부재는 「안전」이 아니다 (D3 게이트 자동 개방 차단)', () => {
  it('서버가 대상 목록을 내려주면 프로브 대기(pending) — 판정 전까지 fail-closed', () => {
    expect(region.initialExposureState({ targets: [{ path: '/.env.example', marker: 'APP_NAME=' }] })).toBe('pending');
  });

  it('대상이 빈 목록이어도 pending — 판정은 judgeExposureResults 가 unknown 으로 내린다', () => {
    expect(region.initialExposureState({ targets: [] })).toBe('pending');
  });

  // 이 축이 없으면 check-configuration.php 의 `web_exposure` 한 줄을 지우는 것만으로
  // B-4 게이트가 통째로 열리는데 어떤 테스트도 붉어지지 않는다.
  it.each([
    ['항목 자체가 없음', undefined],
    ['null', null],
    ['targets 키 없음', {}],
    ['targets 가 배열이 아님', { targets: 'nope' }],
  ])('서버 응답이 %s 이면 판정 불능(unknown) — 운영자 확인을 요구한다 (D21)', (_label, payload) => {
    expect(region.initialExposureState(payload)).toBe('unknown');
  });
});

describe('storageLinkNoticeNeeded — symlink 실측 결과의 소비 지점 (B-4)', () => {
  // 이 술어가 없으면 서버가 실제 symlink() 를 시도해 얻은 판정이 응답에만 실리고
  // 아무 데서도 쓰이지 않는다 — 그 실측은 없는 것과 같다.
  it('링크 생성 불가면 안내한다 — 공개 파일이 앱 서빙 경로로 나간다는 사실을 알린다', () => {
    expect(region.storageLinkNoticeNeeded({ symlink_supported: false })).toBe(true);
  });

  it.each([
    ['가능', { symlink_supported: true }],
    ['판정 불능', { symlink_supported: null }],
    ['키 없음', {}],
    ['항목 자체가 없음', undefined],
  ])('%s 이면 안내하지 않는다', (_label, payload) => {
    expect(region.storageLinkNoticeNeeded(payload)).toBe(false);
  });
});

describe('양성 대조 — 「열려야 하는 주소」로 위임 부재·기준 URL 오도출을 잡는다 (4차 G3)', () => {
  const control = { path: '/install/assets/css/installer.css', marker: 'G7 Installer Stylesheet', label: 'install/assets' };

  /**
   * fetch 스텁 — 주소별 응답을 표로 준다.
   *
   * @param table 주소 접두 → 응답
   */
  function stubFetchByUrl(table: Array<[string, { ok: boolean; status?: number; body?: string }]>) {
    vi.stubGlobal('fetch', vi.fn(async (url: string) => {
      const hit = table.find(([prefix]) => String(url).startsWith(prefix));
      const res = hit ? hit[1] : { ok: false, status: 404 };

      return {
        ok: res.ok,
        status: res.status ?? (res.ok ? 200 : 404),
        type: 'basic',
        text: async () => res.body ?? '',
      };
    }));
  }

  it('대조가 열리면 reachable — 마커까지 확인한다', async () => {
    stubFetchByUrl([['http://x/install/assets', { ok: true, body: '/**\n * G7 Installer Stylesheet\n */' }]]);

    await expect(region.probeExposureControl('http://x', control)).resolves.toEqual({
      path: control.path,
      label: control.label,
      reachable: true,
    });
  });

  it('대조가 200 이어도 마커가 없으면 reachable 이 아니다 (catch-all·인터스티셜)', async () => {
    stubFetchByUrl([['http://x/install/assets', { ok: true, body: '<html>login</html>' }]]);

    await expect(region.probeExposureControl('http://x', control)).resolves.toMatchObject({ reachable: false });
  });

  it('대조가 열리지 않으면 전 대상이 차단으로 보여도 safe 가 되지 않는다 — 위임 부재(mod_rewrite)', () => {
    // 이것이 이 축의 핵심이다. rewrite 가 없는 서버에서는 폴백 하드닝이 프로브 3계열을
    // 전부 403 으로 만들어 「보호 확인됨」 초록이 됐다 — 정작 그 사이트는 `/` 밖의 전
    // 라우트·전 자산이 404 인 상태다.
    const allBlocked = [
      { exposed: false, judged: true },
      { exposed: false, judged: true },
      { exposed: false, judged: true },
    ];

    expect(region.judgeExposureResults(allBlocked, 0, [{ reachable: true }])).toBe('safe');
    expect(region.judgeExposureResults(allBlocked, 0, [{ reachable: false }])).toBe('unknown');
  });

  it('노출 확정은 대조 실패보다 우선한다 (D3 는 언제나 이긴다)', () => {
    expect(
      region.judgeExposureResults([{ exposed: true, judged: true }], 0, [{ reachable: false }]),
    ).toBe('exposed');
  });

  it('첫 후보에서 대조가 열리지 않으면 후보를 바꾸지 않는다 — 판정 불능으로 떨어뜨린다 (5차 G3, 제품 결정)', async () => {
    // 4차까지는 「대조가 열리는 후보를 찾을 때까지 후보를 바꾼다」였다. 그러면 대조는
    // 게이트가 아니라 **후보 탐색기**가 되어, judgeExposureResults 의 `controls.some(!reachable)`
    // 분기에 **도달 자체가 불가능**해진다. 그 결과 위임(mod_rewrite)이 죽은 레이아웃 ② 가
    // 두 번째 후보에서 통과해 「보호 확인됨」으로 수렴하고 설치가 완주했다 — §4 B-1② 가
    // 「진행을 막는다」고 선언한 바로 그 배치다.
    //
    // 두 실패 모드(「프로젝트 디렉토리 이름이 실제로 public」 ↔ 「위임 사망」)는 관측이
    // 완전히 동일해 화면이 구분할 수 없다. 그래서 구분을 시도하지 않고 **둘 다** 판정
    // 불능(D21 운영자 확인 경로)으로 보낸다. 전자는 막히는 것이 아니라 한 단계를 더 밟는다.
    (window as unknown as { INSTALLER_BASE_URL: string }).INSTALLER_BASE_URL = '/sites/public/install';
    region = loadExposureRegion();

    const origin = window.location.origin;
    expect(region.getProjectRootCandidates()).toEqual([`${origin}/sites`, `${origin}/sites/public`]);

    // 첫 후보(/sites)에서는 대조가 없고, 두 번째(/sites/public)에서만 열린다.
    stubFetchByUrl([
      [`${origin}/sites/public/install/assets`, { ok: true, body: 'G7 Installer Stylesheet' }],
      [`${origin}/sites/install/assets`, { ok: false, status: 404 }],
    ]);

    const resolved = await region.resolveProjectRoot([control]);

    expect(resolved.root).toBe(`${origin}/sites`);
    expect(resolved.controlResults.some((c) => !c.reachable)).toBe(true);
    // 그 결과가 판정으로 이어지는지까지 잠근다 — 대조 실패는 언제나 판정 불능이다.
    expect(region.judgeExposureResults([{ exposed: false, judged: true }], 0, resolved.controlResults))
      .toBe('unknown');
  });

  it('위임이 죽은 레이아웃 ② 가 「보호 확인됨」으로 수렴하지 않는다 (5차 G3 · H1 회귀)', async () => {
    // 재현: 문서루트=프로젝트 루트인데 mod_rewrite 가 없다. `/install/` 이 404 라 운영자는
    // 실제 위치 `/public/install/` 로 들어올 수밖에 없고, 그러면 후보가 둘이 된다.
    // 폴백 하드닝이 프로젝트 루트의 프로브 3계열을 전부 403 으로 만들기 때문에, 대조가
    // 게이트로 서지 않으면 노출 0 + judged 전부 true → `safe` 가 된다.
    (window as unknown as { INSTALLER_BASE_URL: string }).INSTALLER_BASE_URL = '/public/install';
    region = loadExposureRegion();

    const origin = window.location.origin;

    stubFetchByUrl([
      // 위임이 죽어 프로젝트 루트의 인스톨러 자산이 열리지 않는다.
      [`${origin}/install/assets`, { ok: false, status: 404 }],
      // 실제 위치는 정적 파일이라 열린다 — 4차까지는 이것이 후보를 갈아치웠다.
      [`${origin}/public/install/assets`, { ok: true, body: 'G7 Installer Stylesheet' }],
    ]);

    const resolved = await region.resolveProjectRoot([control]);

    expect(resolved.root).toBe(origin);
    expect(
      region.judgeExposureResults(
        // 폴백 하드닝이 만든 「전부 차단」 관측 — 이것만 보면 완벽히 보호된 사이트다.
        [
          { exposed: false, judged: true },
          { exposed: false, judged: true },
          { exposed: false, judged: true },
        ],
        0,
        resolved.controlResults,
      ),
      '위임이 죽은 배치가 safe 로 수렴하면 설치가 완주하고 그 사이트는 첫 화면 외 전부 404 다',
    ).toBe('unknown');
  });

  it('대조 대상이 없으면 첫 후보를 그대로 쓴다 — 근거 없이 후보를 바꾸지 않는다', async () => {
    const resolved = await region.resolveProjectRoot([]);

    expect(resolved.root).toBe(region.getProjectRootCandidates()[0]);
    expect(resolved.controlResults).toEqual([]);
  });
});

describe('judgeExposureResults (D3 · D21)', () => {
  it('노출 1건이면 판정 실패가 섞여 있어도 exposed 다 — 차단이 우선', () => {
    expect(
      region.judgeExposureResults([
        { exposed: false, judged: true },
        { exposed: true, judged: true },
        { exposed: false, judged: false },
      ]),
    ).toBe('exposed');
  });

  it('노출 0 + 판정 실패가 있으면 unknown (D21 경로)', () => {
    expect(
      region.judgeExposureResults([
        { exposed: false, judged: true },
        { exposed: false, judged: false },
      ]),
    ).toBe('unknown');
  });

  it('판정 근거가 없으면(대상 0) unknown — 빈 목록을 안전으로 읽지 않는다', () => {
    expect(region.judgeExposureResults([])).toBe('unknown');
  });

  it('재지 못한 계열이 있으면 노출 0 이어도 safe 가 아니다 — 부분 fail-open 차단 (3차 H3)', () => {
    // 서버가 sentinel 을 만들지 못하면 그 계열은 프로브 결과에 **나타나지 않는다**.
    // 결과 배열만 보면 「전 대상 판정 성공」이라 종전에는 초록이 됐다 — 재지 않은 축까지.
    expect(
      region.judgeExposureResults(
        [
          { exposed: false, judged: true },
          { exposed: false, judged: true },
        ],
        1,
      ),
    ).toBe('unknown');
  });

  it('재지 못한 계열이 0 이면 종전대로 safe', () => {
    expect(
      region.judgeExposureResults([{ exposed: false, judged: true }], 0),
    ).toBe('safe');
  });

  it('전 대상 판정 성공 + 노출 0 이면 safe', () => {
    expect(
      region.judgeExposureResults([
        { exposed: false, judged: true },
        { exposed: false, judged: true },
      ]),
    ).toBe('safe');
  });
});

describe('updateStep2Gate — [다음 단계] 게이팅 (§12 W1)', () => {
  const nextBtn = () => document.getElementById('next-btn') as HTMLButtonElement;
  const recheckBtn = () => document.getElementById('recheck-btn') as HTMLButtonElement;

  it('서버 통과 + safe 면 활성, [다시 검사] 는 숨김', () => {
    region.setStep2GateState(true, 'safe');
    region.updateStep2Gate();

    expect(nextBtn().disabled).toBe(false);
    expect(nextBtn().getAttribute('aria-disabled')).toBe('false');
    expect(recheckBtn().classList.contains('hidden')).toBe(true);
  });

  it.each(['pending', 'exposed', 'unknown'])('서버 통과라도 %s 면 비활성 (fail-closed)', (state) => {
    region.setStep2GateState(true, state);
    region.updateStep2Gate();

    expect(nextBtn().disabled).toBe(true);
    expect(nextBtn().getAttribute('aria-disabled')).toBe('true');
    expect(nextBtn().classList.contains('hidden')).toBe(false); // 버튼은 자리에 있고 상태만 바뀐다
    expect(recheckBtn().classList.contains('hidden')).toBe(false);
  });

  it("판정 불능에서 운영자가 '확인했음' 을 체크하면(confirmed) 진행 허용 (D21)", () => {
    region.setStep2GateState(true, 'unknown');
    region.updateStep2Gate();
    expect(nextBtn().disabled).toBe(true);

    region.setStep2ExposureState('confirmed');
    region.updateStep2Gate();
    expect(nextBtn().disabled).toBe(false);
  });

  it('노출 판정이 safe 여도 서버 미통과면 비활성', () => {
    region.setStep2GateState(false, 'safe');
    region.updateStep2Gate();

    expect(nextBtn().disabled).toBe(true);
  });
});

describe('probeExposureTarget — 마커 기반 판정 (asset-probe 규약)', () => {
  const target = { path: '/.env.example', marker: 'APP_NAME=' };

  const stubFetch = (impl: () => Promise<unknown>) => {
    vi.stubGlobal('fetch', vi.fn(impl));
  };

  it('200 + 마커 포함 본문이면 노출', async () => {
    const body = 'APP_NAME=G7\nAPP_KEY=';
    stubFetch(async () => ({ ok: true, status: 200, text: async () => body }));

    await expect(region.probeExposureTarget('http://x', target)).resolves.toEqual({
      path: '/.env.example',
      exposed: true,
      judged: true,
      // 형태(status·본문 길이)도 함께 싣는다 — 음성 대조와 대조해 판정을 되살리는
      // 근거다(14차 G3). 노출 확정은 그 승격보다 언제나 우선한다.
      status: 200,
      length: body.length,
    });
  });

  it('200 이라도 마커가 없으면 노출로 오판하지 않고, 보호로도 읽지 않는다 (200 인터스티셜)', async () => {
    // 「노출로 오판하지 않는다」는 성질은 그대로다(`exposed: false`). 바뀐 것은 `judged` 다.
    //
    // 종전에는 이것을 `judged: true` 로 두어 **보호의 증명**으로 읽었다. 그런데 200 은 인증
    // 프록시(oauth2-proxy·Azure AD App Proxy)·캡티브 포털·CDN 챌린지가 로그인 화면을 렌더할
    // 때도 나온다 — 파일은 그대로 노출돼 있는데 화면은 「보호 확인됨」 초록이 됐다. 3xx 는
    // `redirect: 'manual'` 로 막아 두고 200 인터스티셜만 그 규칙을 우회하던 자리다.
    // 보호를 증명하는 것은 접근 거부(403)와 부재(404)뿐이라는 규칙을 200 에도 적용한다.
    const body = '<html>installer redirect</html>';
    stubFetch(async () => ({ ok: true, status: 200, text: async () => body }));

    await expect(region.probeExposureTarget('http://x', target)).resolves.toEqual({
      path: '/.env.example',
      exposed: false,
      judged: false,
      status: 200,
      length: body.length,
    });
  });

  it.each([403, 404])('%d 는 차단 확인 — 판정 성공', async (status) => {
    stubFetch(async () => ({ ok: false, status }));

    await expect(region.probeExposureTarget('http://x', target)).resolves.toEqual({
      path: '/.env.example',
      exposed: false,
      judged: true,
      status,
      length: null,
    });
  });

  // 「응답을 못 받았다」와 「보호되어 있다」는 다른 상태다. 비-200 을 전부 차단 확인으로
  // 접으면, 사이트 전체 Basic 인증(401)이나 쿼리스트링을 막는 WAF(400·403 외 상태)에서
  // 전 대상이 「보호 확인됨」으로 초록이 되고 운영자는 노출된 배치를 안전하다고 믿는다.
  // 보호를 증명하는 것은 **접근 거부(403)와 부재(404)** 뿐이다.
  it.each([
    [401, '사이트 전체 Basic 인증 — 인증을 걷으면 그대로 열린다'],
    [407, '프록시 인증'],
    [429, '요청 제한 — 파일이 열리는지 재지 못했다'],
    [500, '서버 오류'],
    [503, '점검 중'],
    [400, '요청 자체가 거부됨 (WAF 의 쿼리스트링 차단 등)'],
  ])('%d 는 판정 불능 — 보호 확인으로 읽지 않는다 (%s)', async (status) => {
    stubFetch(async () => ({ ok: false, status }));

    await expect(region.probeExposureTarget('http://x', target)).resolves.toEqual({
      path: '/.env.example',
      exposed: false,
      judged: false,
      status,
      length: null,
    });
  });

  it('리다이렉트는 판정 불능이다 — 따라가면 로그인 페이지 200 이 「보호 확인됨」이 된다 (3차 L6)', async () => {
    // redirect:'manual' 이면 3xx 는 status 0 · type 'opaqueredirect' 로 도착한다.
    stubFetch(async () => ({ ok: false, status: 0, type: 'opaqueredirect' }));

    await expect(region.probeExposureTarget('http://x', target)).resolves.toEqual({
      path: '/.env.example',
      exposed: false,
      judged: false,
    });
  });

  it('프로브는 리다이렉트를 따라가지 않는다 (요청 옵션 계약)', async () => {
    const spy = vi.fn(async () => ({ ok: false, status: 403 }));
    vi.stubGlobal('fetch', spy);

    await region.probeExposureTarget('http://x', target);

    expect(spy.mock.calls[0][1]).toMatchObject({ redirect: 'manual', cache: 'no-store', credentials: 'omit' });
  });

  it('네트워크 예외는 판정 불능(judged=false) — D21 경로로 넘어간다', async () => {
    stubFetch(async () => {
      throw new TypeError('Failed to fetch');
    });

    await expect(region.probeExposureTarget('http://x', target)).resolves.toEqual({
      path: '/.env.example',
      exposed: false,
      judged: false,
    });
  });
});

describe('refreshWebExposureCard — 카드 렌더와 진행 경로 (D3 · D21)', () => {
  const nextBtn = () => document.getElementById('next-btn') as HTMLButtonElement;
  const confirmBox = () => document.getElementById('web-exposure-confirm') as HTMLInputElement | null;

  const stubFetch = (impl: () => Promise<unknown>) => {
    vi.stubGlobal('fetch', vi.fn(impl));
  };

  const tick = async () => {
    await Promise.resolve();
    await Promise.resolve();
  };

  const target = { path: '/.env.example', marker: 'APP_NAME=' };

  /**
   * 링크 불가 안내와 판정 불능이 **겹치는** 환경이 이 안의 주 대상이다 — 심볼릭 링크를
   * 만들 수 없는 공유 호스팅에서 프로브까지 막히면 두 상태가 동시에 뜬다. 안내를 카드에
   * 나중에 덧붙이면 그 시점에 subtree 가 다시 파싱되어 확인 체크박스의 리스너가 사라지고,
   * [다음 단계] 를 여는 유일한 조작이 무반응이 된다(오류도 로그도 없다).
   */
  it('판정 불능 + 링크 불가 안내가 함께 떠도 확인 체크가 게이트를 연다', async () => {
    region.setStep2GateState(true, 'pending');
    stubFetch(async () => {
      throw new TypeError('Failed to fetch');
    });

    await region.refreshWebExposureCard({ targets: [target], symlink_supported: false });
    await tick();

    const slot = document.getElementById('web-exposure-card') as HTMLElement;
    expect(slot.querySelector('.alert-info'), '링크 불가 안내가 렌더되어야 한다 (B-4 실측의 소비 지점)').not.toBeNull();
    expect(nextBtn().disabled).toBe(true);

    const box = confirmBox();
    expect(box, '판정 불능이면 운영자 확인 체크박스가 있어야 한다 (D21)').not.toBeNull();
    box!.checked = true;
    box!.dispatchEvent(new Event('change'));

    expect(nextBtn().disabled, "'확인했음' 체크가 무반응이다 — 그 환경에서는 설치가 막다른 길이 된다").toBe(false);
  });

  it('링크 안내가 없을 때도 확인 체크가 게이트를 연다 (같은 경로의 대조군)', async () => {
    region.setStep2GateState(true, 'pending');
    stubFetch(async () => ({ ok: false, status: 401 }));

    await region.refreshWebExposureCard({ targets: [target], symlink_supported: true });
    await tick();

    const box = confirmBox();
    expect(box).not.toBeNull();
    box!.checked = true;
    box!.dispatchEvent(new Event('change'));

    expect(nextBtn().disabled).toBe(false);
  });

  /**
   * 서버가 점검 항목 자체를 내려주지 않는 것은 배선 파손이라 판정 불능이다(initialExposureState).
   * 그런데 그 상태에서 카드를 그리지 않으면 사유도, 수동 확인 경로도 없이 [다음 단계] 만
   * 비활성이 되어 운영자가 [다시 검사] 를 영원히 누르게 된다 — D21 은 「차단」이 아니라
   * 「운영자 확인 절차」를 요구한다.
   */
  it('서버가 점검 항목을 내려주지 않아도 확인 경로가 열린다 — 막다른 길 금지', async () => {
    region.setStep2GateState(true, 'unknown');

    await region.refreshWebExposureCard(undefined);
    await tick();

    const box = confirmBox();
    expect(box, '점검 항목 부재도 판정 불능이다 — 확인 경로가 있어야 한다').not.toBeNull();
    box!.checked = true;
    box!.dispatchEvent(new Event('change'));

    expect(nextBtn().disabled).toBe(false);
  });

  it('노출 확정이면 확인 체크를 주지 않는다 — 차단이 우선 (D3)', async () => {
    region.setStep2GateState(true, 'pending');
    stubFetch(async () => ({ ok: true, text: async () => 'APP_NAME=G7' }));

    await region.refreshWebExposureCard({ targets: [target], symlink_supported: false });
    await tick();

    expect(confirmBox(), '노출이 확인된 배치는 체크 하나로 넘어갈 수 없다').toBeNull();
    expect(nextBtn().disabled).toBe(true);
    // 링크 불가 안내는 노출 확정 카드에도 함께 실린다 (서로 다른 축이다).
    expect((document.getElementById('web-exposure-card') as HTMLElement).querySelector('.alert-info')).not.toBeNull();
  });

  it('재지 못한 계열이 있으면 전 대상이 403 이어도 확인 경로로 간다 + 그 이름이 화면에 뜬다 (3차 H3)', async () => {
    region.setStep2GateState(true, 'pending');
    stubFetch(async () => ({ ok: false, status: 403 }));

    await region.refreshWebExposureCard({
      targets: [target],
      unmeasured: [{ label: 'storage/', reason: 'not_writable' }],
      symlink_supported: true,
    });
    await tick();

    const card = document.getElementById('web-exposure-card') as HTMLElement;
    expect(card.textContent, '무엇을 재지 못했는지 모르면 운영자가 근거 없이 확인 체크를 누른다').toContain('storage/');
    expect(confirmBox(), '재지 못한 축이 있으면 운영자 확인 경로가 열려야 한다').not.toBeNull();
    expect(nextBtn().disabled).toBe(true);
  });

  /**
   * 양성 대조의 **배선**을 잠근다 — 판정 함수(`resolveProjectRoot`·`judgeExposureResults`)는
   * 직접 호출로 이미 잠겨 있었지만, 서버 응답의 `controls` 를 그 함수들에 잇는 한 줄은
   * 어떤 테스트도 지나가지 않았다. 그 한 줄이 사라지면 `controls` 가 언제나 빈 배열이라
   * 대조 분기가 통째로 건너뛰어지고, 위임이 죽은 서버(모든 주소가 404·403)가 다시
   * 「보호 확인됨」으로 수렴한다 — 오류도 경고도 없이.
   */
  const control = { path: '/install/assets/css/installer.css', marker: '--g7-installer', label: 'install/assets' };

  const stubByUrl = (impl: (url: string) => unknown) => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url: string) => impl(String(url))),
    );
  };

  it('대조가 열리지 않으면 전 대상이 403 이어도 판정 불능이다 (배선 축)', async () => {
    region.setStep2GateState(true, 'pending');
    stubByUrl((url) =>
      url.includes('/install/assets/')
        ? { ok: false, status: 404 }
        : { ok: false, status: 403 },
    );

    await region.refreshWebExposureCard({ targets: [target], controls: [control], symlink_supported: true });
    await tick();

    const card = document.getElementById('web-exposure-card') as HTMLElement;
    expect(
      card.textContent,
      '대조 주소가 화면에 뜨지 않으면 운영자는 무엇이 잘못됐는지 알 수 없다',
    ).toContain('/install/assets/');
    expect(confirmBox(), '대조 실패는 차단이 아니라 운영자 확인 경로다 (D21)').not.toBeNull();
    expect(nextBtn().disabled).toBe(true);
  });

  it('대조가 열리면 전 대상 403 이 「보호 확인됨」이 된다 (대조군)', async () => {
    region.setStep2GateState(true, 'pending');
    stubByUrl((url) =>
      url.includes('/install/assets/')
        ? { ok: true, status: 200, type: 'basic', text: async () => 'body{--g7-installer:1}' }
        : { ok: false, status: 403 },
    );

    await region.refreshWebExposureCard({ targets: [target], controls: [control], symlink_supported: true });
    await tick();

    expect(confirmBox(), '대조가 성립하면 확인 절차 없이 진행한다').toBeNull();
    expect(nextBtn().disabled).toBe(false);
  });

  it('대조 실패보다 노출 확정이 우선한다', async () => {
    region.setStep2GateState(true, 'pending');
    stubByUrl((url) =>
      url.includes('/install/assets/')
        ? { ok: false, status: 404 }
        : { ok: true, status: 200, type: 'basic', text: async () => 'APP_NAME=G7' },
    );

    await region.refreshWebExposureCard({ targets: [target], controls: [control], symlink_supported: true });
    await tick();

    expect(confirmBox(), '노출이 확정된 배치는 체크 하나로 넘어갈 수 없다').toBeNull();
    expect(nextBtn().disabled).toBe(true);
  });

  it('전 대상 보호 확인이면 게이트가 열린다', async () => {
    region.setStep2GateState(true, 'pending');
    stubFetch(async () => ({ ok: false, status: 403 }));

    await region.refreshWebExposureCard({ targets: [target], symlink_supported: true });
    await tick();

    expect(confirmBox()).toBeNull();
    expect(nextBtn().disabled).toBe(false);
  });

  const verdictField = () => document.getElementById('web-exposure-verdict') as HTMLInputElement;

  /**
   * 주소 목록이 **비어 있는데** 「위 주소를 모두 확인했고…」에 서명시키지 않는다 (8차 P4-4).
   *
   * 문구 선택 조건에 「재지 못한 계열 목록」까지 넣으면, 대상 0 + unmeasured 만 있는 상태에서
   * 화면에 주소가 한 줄도 없는 채로 그 문구가 뜬다. 정작 그 경우를 위해 만든
   * `…_no_list`(무엇을 열어 봐야 하는지 문구가 직접 말한다)에는 영영 도달하지 않는다.
   */
  it('대상 0 + 재지 못한 계열만 있으면 「목록 없음」 확인 문구를 쓴다', async () => {
    region.setStep2GateState(true, 'pending');

    await region.refreshWebExposureCard({
      targets: [],
      unmeasured: [{ label: 'vendor/', reason: 'not_deployed' }],
      symlink_supported: true,
    });
    await tick();

    const card = document.getElementById('web-exposure-card') as HTMLElement;
    const label = card.querySelector('.form-checkbox span')?.textContent ?? '';

    expect(card.querySelector('.exposure-address-list'), '이 경우 주소 목록은 렌더되지 않는다').toBeNull();
    expect(
      label,
      '화면에 주소가 한 줄도 없는데 「위 주소를 모두 확인했고…」에 체크시키면 그 서명은 근거가 없다',
    ).toBe('L:web_exposure_manual_confirm_no_list');
  });

  /**
   * 정제기가 버린 프록시 접두사(`X-Forwarded-Prefix`)는 **화면에 도달한다** (15차 G3 W1-2).
   *
   * 기준 주소가 마운트 밖일 수 있는데 그 사실이 서버 응답에만 실리고 화면이 그리지 않으면
   * 운영자는 「왜 판정 불능인지」를 알 수 없다. 사유 키는 공용 사전(`web_exposure_unmeasured_reason_*`)
   * 으로 해석되며, 라벨(헤더명)이 그대로 뜬다.
   */
  it('버려진 X-Forwarded-Prefix 사유가 재지 못한 항목으로 화면에 뜬다', async () => {
    region.setStep2GateState(true, 'pending');
    stubFetch(async () => ({ ok: false, status: 403 }));

    await region.refreshWebExposureCard({
      targets: [target],
      unmeasured: [{ label: 'X-Forwarded-Prefix', reason: 'forwarded_prefix_rejected' }],
      symlink_supported: true,
    });
    await tick();

    const card = document.getElementById('web-exposure-card') as HTMLElement;
    expect(card.textContent).toContain('X-Forwarded-Prefix');
    expect(card.textContent, '사유 키가 사전으로 해석되어 화면에 실려야 한다').toContain(
      'L:web_exposure_unmeasured_reason_forwarded_prefix_rejected',
    );
    expect(confirmBox(), '접두사를 버린 배치는 자동 판정을 신뢰할 수 없다 — 운영자 확인 경로로 간다').not.toBeNull();
    expect(nextBtn().disabled).toBe(true);
  });

  it('주소 목록이 있으면 종전 확인 문구를 그대로 쓴다 (대조군)', async () => {
    region.setStep2GateState(true, 'pending');
    stubFetch(async () => ({ ok: false, status: 401 }));

    await region.refreshWebExposureCard({
      targets: [target],
      unmeasured: [{ label: 'vendor/', reason: 'not_deployed' }],
      symlink_supported: true,
    });
    await tick();

    const card = document.getElementById('web-exposure-card') as HTMLElement;
    expect(card.querySelector('.form-checkbox span')?.textContent).toBe('L:web_exposure_manual_confirm');
  });

  /**
   * 판정과 운영자 확인이 **기록으로 남는다** (8차 P4-6).
   *
   * 판정 주체가 브라우저이고 게이트도 화면이 잠그므로, 이 값이 폼에 실리지 않으면
   * 「보호 확인됨으로 통과했는지 / 판정 불능에서 운영자가 직접 확인하고 넘어갔는지」를
   * 나중에 되짚을 근거가 어디에도 없다. 서버는 이 필드를 받아 설치 로그에 한 줄 남긴다.
   */
  it('판정 확정 시점에 판정값이 폼에 실린다', async () => {
    region.setStep2GateState(true, 'pending');
    stubFetch(async () => ({ ok: false, status: 403 }));

    await region.refreshWebExposureCard({ targets: [target], symlink_supported: true });
    await tick();

    expect(verdictField().value, '판정이 기록되지 않으면 통과 근거가 사후에 사라진다').toBe('safe');
  });

  it('확인 체크와 해제가 각각 기록된다', async () => {
    region.setStep2GateState(true, 'pending');
    stubFetch(async () => {
      throw new TypeError('Failed to fetch');
    });

    await region.refreshWebExposureCard({ targets: [target], symlink_supported: true });
    await tick();

    expect(verdictField().value).toBe('unknown');

    const box = confirmBox();
    expect(box).not.toBeNull();
    box!.checked = true;
    box!.dispatchEvent(new Event('change'));

    expect(verdictField().value, '운영자가 직접 확인하고 넘어간 사실이 남아야 한다').toBe('confirmed');

    box!.checked = false;
    box!.dispatchEvent(new Event('change'));

    expect(verdictField().value).toBe('unknown');
  });

  /**
   * 필수 게이트 카드의 busy 는 **판정이 난 뒤에** 풀린다 (8차 P4-7).
   *
   * 바깥 결과 영역의 busy 해제는 요구사항 목록을 그린 직후에 일어나는데 이 카드의 판정은
   * 그 뒤에 비동기로 온다. 카드가 자기 busy 를 갖지 않으면 화면 판독기 사용자는
   * 「확인 중…」을 최종 상태로 듣고 [다음 단계] 가 왜 비활성인지 알 수 없다.
   */
  it.each([
    ['보호 확인됨', async () => ({ ok: false, status: 403 })],
    ['판정 불능', async () => ({ ok: false, status: 401 })],
    ['노출 확정', async () => ({ ok: true, status: 200, type: 'basic', text: async () => 'APP_NAME=G7' })],
  ])('%s 판정 뒤 카드의 aria-busy 가 풀린다', async (_label, impl) => {
    region.setStep2GateState(true, 'pending');
    stubFetch(impl as () => Promise<unknown>);

    const slot = document.getElementById('web-exposure-card') as HTMLElement;
    expect(slot.getAttribute('aria-busy'), '판정 전에는 busy 여야 한다').toBe('true');

    await region.refreshWebExposureCard({ targets: [target], symlink_supported: true });
    await tick();

    expect(
      slot.getAttribute('aria-busy'),
      '판정이 끝났는데 busy 가 남으면 결과가 화면 밖으로 전달되지 않는다',
    ).toBe('false');
  });
});

describe('소스 계약 — 게이트가 조용히 열리는 형태를 금지한다', () => {
  const source = () => readFileSync(INSTALLER_SOURCE, 'utf8');

  it('요구사항 화면은 노출 점검 카드 자리를 조건 없이 렌더한다', () => {
    expect(
      source(),
      '카드 자리를 서버 응답 유무로 분기하면, 항목이 없을 때 판정 불능인데 사유도 확인 경로도 없이 버튼만 비활성이 된다',
    ).not.toContain('if (data.web_exposure) {');
  });

  it('노출 판정의 모듈 초기값은 「안전」이 아니다', () => {
    expect(
      source(),
      '초기값이 safe 면 게이트 갱신 순서가 한 번만 바뀌어도 판정 없이 열린다 — 기본값은 판정 불능이어야 한다',
    ).not.toMatch(/let\s+step2ExposureState\s*=\s*'safe'/);
  });

  /**
   * 카드 자리가 **busy 로 태어난다** — 그렇지 않으면 판정을 기다리는 동안의 「확인 중…」이
   * 최종 상태로 읽힌다. 해제(`aria-busy = false`)는 판정부가 하므로 이 축은 렌더 쪽에만 있다.
   *
   * 그리고 이 자리는 **자기 라이브 리전을 선언하지 않는다.** 조각이 들어가는 바깥 영역
   * (`views/2-requirements.php` 의 `#requirements-result`)이 이미 `role="status"
   * aria-live="polite"` 이고, `aria-atomic` 기본값이 false 라 그 영역이 **변경된 subtree 만**
   * 낭독한다 — 즉 이 카드의 비동기 갱신도 바깥이 읽는다. 안쪽에 한 번 더 선언하면
   * polite 안의 polite 가 되어 화면 판독기 구현에 따라 중복 낭독 또는 안쪽 무시로 갈린다.
   *
   * 두 방향을 함께 잰다. busy 만 재면 리전이 되살아나도 초록이고, 리전 부재만 재면
   * busy 가 사라져도 초록이다 — 어느 한쪽만으로는 반대쪽이 통째로 빠져도 드러나지 않는다.
   * 형제 게이트: `tests/Unit/Installer/InstallerViewAccessibilityContractTest.php`
   * (중첩 금지 축). 두 러너가 갈리면 한쪽이 red 인 채로 상주하므로 계약을 같이 옮긴다.
   */
  it('노출 점검 카드 자리는 busy 로 시작하고, 자기 라이브 리전은 선언하지 않는다', () => {
    const card = source().match(/id="web-exposure-card"[^`]*/);

    expect(card, '카드 자리를 그리는 지점이 사라졌다 — 이 축이 잴 대상이 없다').not.toBeNull();

    expect(
      card?.[0],
      '판정 전의 「확인 중…」이 최종 상태로 낭독되지 않도록 카드는 busy 로 태어나야 한다',
    ).toMatch(/aria-busy="true"/);

    expect(
      card?.[0],
      '바깥 #requirements-result 가 이미 polite 리전이다 — 안쪽에 또 선언하면 polite 안의 polite 가 된다',
    ).not.toMatch(/aria-live=|role="(?:status|alert|log|marquee|timer)"/);
  });

  /**
   * 화면이 서버에 보내는 판정 어휘 == 서버가 닫힌 집합으로 받는 어휘 (16차 G3 D-7).
   *
   * 서버(`installerNormalizeWebExposureVerdict`)는 집합 밖을 「보고되지 않음」으로 접는다 —
   * 화면이 새 값을 보내기 시작해도 오류가 아니라 그 문구가 로그에 남을 뿐이다. 두 어휘를
   * 소스에서 뽑아 대조한다: 화면 쪽은 `region:web-exposure` 안에서 상태를 쓰는 두 지점의
   * 문자열 리터럴(판정 함수의 반환값 포함) + 숨은 필드의 초기값, 서버 쪽은 `match` 팔.
   *
   * @scenario exposure_probe=safe, exposure_probe=exposed, exposure_probe=unknown, exposure_probe=unknown_confirmed
   *
   * @effects installer_web_exposure_verdict_vocabulary_matches_between_screen_and_server
   */
  it('판정 어휘는 화면과 서버가 같은 닫힌 집합이다', () => {
    const src = source();
    const regionStart = src.indexOf('region:web-exposure');
    const regionEnd = src.indexOf('endregion:web-exposure');
    const regionBody = src.slice(regionStart, regionEnd);

    const literals = new Set<string>();
    // 상태를 쓰는 두 지점의 인자 안에 있는 리터럴 — 삼항식(`x ? 'confirmed' : 'unknown'`)도 포함.
    for (const call of regionBody.matchAll(/(?:setStep2ExposureState|recordExposureVerdict)\(([^)]*)\)/g)) {
      for (const lit of call[1].matchAll(/'([a-z]+)'/g)) literals.add(lit[1]);
    }
    // 그 두 지점이 변수(`judgement`)로 받는 값의 출처 — 판정 함수·초기 상태 함수의 반환 리터럴.
    for (const fn of ['judgeExposureResults', 'initialExposureState']) {
      const start = regionBody.indexOf(`function ${fn}(`);
      expect(start, `${fn} 이 구간에서 사라졌다`).toBeGreaterThan(-1);
      const body = regionBody.slice(start, regionBody.indexOf('\n    }\n', start));
      for (const ret of body.matchAll(/return\s+(?:[^;]*?\?\s*)?'([a-z]+)'(?:\s*:\s*'([a-z]+)')?/g)) {
        literals.add(ret[1]);
        if (ret[2]) literals.add(ret[2]);
      }
    }
    // 숨은 필드의 초기값 — 프로브가 끝나기 전 POST 가 서버에 실어 보내는 값.
    const view = readFileSync(
      path.resolve(__dirname, '../../../../public/install/views/2-requirements.php'),
      'utf8',
    );
    const initial = view.match(/id="web-exposure-verdict"[^>]*value="([a-z]+)"/);
    expect(initial, '숨은 판정 필드가 화면에서 사라졌다').not.toBeNull();
    literals.add(initial![1]);

    const php = readFileSync(
      path.resolve(__dirname, '../../../../public/install/includes/request-handler.php'),
      'utf8',
    );
    const fnStart = php.indexOf('function installerNormalizeWebExposureVerdict(');
    expect(fnStart, '서버측 정규화 함수가 사라졌다').toBeGreaterThan(-1);
    const arms = php.slice(fnStart, php.indexOf('}', php.indexOf('match', fnStart))).match(/^\s*((?:'[a-z]+',?\s*)+)=>/m);
    expect(arms, 'match 팔에서 어휘를 읽지 못했다').not.toBeNull();
    const serverSet = new Set(Array.from(arms![1].matchAll(/'([a-z]+)'/g), (m) => m[1]));

    expect(
      [...literals].sort(),
      '화면이 보내는 판정 어휘와 서버가 받는 닫힌 집합이 다르다 — 빠진 값은 「보고되지 않음」으로 접혀 배선 파손과 구분되지 않는다',
    ).toEqual([...serverSet].sort());
  });
});

describe('getAppRootUrl — 앱 루트는 기준 URL 의 install 꼬리를 표기와 무관하게 벗긴다 (16차 G3 A12)', () => {
  /**
   * 형제 `getProjectRootCandidates` 는 `/i` 로 벗기는데 이 함수만 대소문자를 구분했다 —
   * 대소문자 무시 파일시스템에서 `/Install/` 로 접속하면 앱 루트가 `/Install` 이 되어
   * 자산 URL 방식 프로브가 존재하지 않는 주소를 잰다.
   *
   * @scenario base_path=root, base_path=subpath
   *
   * @effects installer_app_root_strip_is_case_insensitive
   */
  it.each([
    ['/install', ''],
    ['/Install', ''],
    ['/g7/INSTALL', '/g7'],
    ['/Public/Install', '/Public'],
  ])('%s → 앱 루트 %s', (baseUrl, expectedSuffix) => {
    const src = readFileSync(INSTALLER_SOURCE, 'utf8');
    const start = src.indexOf('function getAppRootUrl()');
    expect(start, 'getAppRootUrl 이 소스에서 사라졌다').toBeGreaterThan(-1);
    const body = src.slice(start, src.indexOf('\n    }\n', start) + 7);

    (window as unknown as { INSTALLER_BASE_URL: string }).INSTALLER_BASE_URL = baseUrl;
    // eslint-disable-next-line no-new-func
    const getAppRootUrl = new Function(`${body}; return getAppRootUrl;`)() as () => string;

    expect(getAppRootUrl()).toBe(`${window.location.origin}${expectedSuffix}`);
  });
});

describe('서버별 보호 설정 안내', () => {
  // 종전 안내는 「루트 .htaccess 를 확인하라」 한 갈래뿐이었다. 그 파일을 읽는 서버는
  // Apache·LiteSpeed 뿐이고 IIS 는 web.config 를, nginx·Caddy 는 **어떤 동봉 파일도**
  // 읽지 않는다 — 그 서버 운영자는 존재하지 않는 조치를 찾게 된다.
  it.each([
    ['apache', 'L:web_exposure_server_guide_apache'],
    ['litespeed', 'L:web_exposure_server_guide_litespeed'],
    ['iis', 'L:web_exposure_server_guide_iis'],
    ['nginx', 'L:web_exposure_server_guide_nginx'],
  ])('서버가 %s 이면 그 서버의 조치를 지목한다', (kind, expected) => {
    const html = region.renderServerProtectionGuide({ server_kind: kind });

    expect(html).toContain(expected);
    expect(html).toContain('L:web_exposure_server_guide_label');
  });

  it.each([
    ['알 수 없는 값', { server_kind: 'caddy' }],
    ['키 자체가 없음', {}],
    ['항목이 없음', null],
  ])('%s 이면 범용 안내로 떨어진다 — 안내가 사라지지 않는다', (_label, item) => {
    const html = region.renderServerProtectionGuide(item);

    expect(html).toContain('L:web_exposure_server_guide_other');
  });

  it('서버가 밝힌 값을 화면에 그대로 싣지 않는다 — 위조 가능한 문자열이다', () => {
    const html = region.renderServerProtectionGuide({
      server_kind: '<img src=x onerror=alert(1)>',
    });

    expect(html).not.toContain('onerror');
    expect(html).toContain('L:web_exposure_server_guide_other');
  });
});

describe('음성 대조 — 「파일이 없는 주소」와 같은 응답은 보호로 읽는다 (14차 G3)', () => {
  // 레이아웃 ②는 루트 `.htaccess` 가 모든 주소를 앱으로 위임하므로, 파일이 서빙되지 않는
  // 주소도 200 앱 화면으로 돌아온다 — P0 실측: `/vendor/autoload.php` · `/storage/...` 가
  // 둘 다 `HTTP 200 text/html`, 존재하지 않는 주소와 **같은 길이**(12,557). 403/404 만 보호로
  // 읽으면 그 정상 배치가 상시 판정 불능이 되어 D3 자동 차단이 모든 배치에서 D21 로 강등된다.
  const okControl = [{ reachable: true }];

  it('대상이 음성 대조와 같은 형태면 판정 성공으로 승격된다 — safe', () => {
    const results = [
      { exposed: false, judged: false, status: 200, length: 12557 },
      { exposed: false, judged: true, status: 403, length: null },
    ];

    expect(region.judgeExposureResults(results, 0, okControl, { status: 200, length: 12557 })).toBe(
      'safe',
    );
  });

  // @scenario exposure_absent_control=empty_body
  // @effects exposure_absent_control_refuses_to_promote_an_empty_body
  it('빈 본문은 승격 근거가 되지 못한다 — unknown (실행된 PHP 가 200+빈 본문이다)', () => {
    // 길이 0 은 어느 서버에서나 나올 수 있어 「파일 없는 주소와 같은 응답인가」의 답이
    // 되지 못한다. 실제로 `/vendor/autoload.php` 는 서빙이 아니라 **PHP 로 실행되면**
    // 200 + 빈 본문이 되고 마커는 본문에 나타나지 않는다 — 그 상태(프로젝트 루트에서
    // PHP 가 실행된다)가 「보호 확인됨」으로 읽히면 안 된다 (15차 G3 라운드).
    const results = [{ exposed: false, judged: false, status: 200, length: 0 }];

    expect(region.judgeExposureResults(results, 0, okControl, { status: 200, length: 0 })).toBe(
      'unknown',
    );
  });

  // 15차 G3 W1-6 — 빈 본문 가드(`absentSignature.length === 0 || r.length === 0`)와 형태 대조
  // (status + length)를 각각 잰다. 두 길이가 다르면 형태 대조가 이미 unknown 을 내므로, 그
  // 가드가 판정을 실제로 가르는 입력은 **둘 다 0** 인 경우뿐이다 — 그 입력에서 가드를 지우면
  // 같은 형태로 읽혀 safe 가 된다(변이 실측: 가드를 `false` 로 바꾸면 이 케이스가 red).
  it('길이 0 은 승격 근거가 되지 못한다 — 대조군: 같은 길이라도 0 이 아니면 승격된다', () => {
    const empty = [{ exposed: false, judged: false, status: 200, length: 0 }];

    expect(region.judgeExposureResults(empty, 0, okControl, { status: 200, length: 0 })).toBe('unknown');
    // 대조군 — 서명이 0 이 아니면 같은 길이에서 승격된다. 이 쌍이 없으면 「길이 0 판정」이
    // 아니라 「승격 자체가 없다」로도 초록이 된다.
    expect(
      region.judgeExposureResults([{ exposed: false, judged: false, status: 200, length: 7 }], 0, okControl, { status: 200, length: 7 }),
    ).toBe('safe');
  });

  it('대상의 본문만 비어 있으면(길이 0) 서명 길이와 달라 승격하지 않는다 — unknown', () => {
    const results = [{ exposed: false, judged: false, status: 200, length: 0 }];

    expect(region.judgeExposureResults(results, 0, okControl, { status: 200, length: 12557 })).toBe('unknown');
  });

  it('길이가 같아도 status 가 다르면 승격되지 않는다 — unknown', () => {
    // status·length **둘 다** 일치해야 같은 응답이다. 길이만 보면 200 앱 화면과 같은 길이의
    // 다른 상태(예: 500 오류 페이지)가 「파일 없는 주소와 같다」로 읽힌다.
    const results = [{ exposed: false, judged: false, status: 500, length: 12557 }];

    expect(region.judgeExposureResults(results, 0, okControl, { status: 200, length: 12557 })).toBe('unknown');
  });

  it('형태가 다르면 승격되지 않는다 — unknown (본문 길이가 다르면 그 응답은 앱 화면이 아니다)', () => {
    const results = [{ exposed: false, judged: false, status: 200, length: 4096 }];

    expect(region.judgeExposureResults(results, 0, okControl, { status: 200, length: 12557 })).toBe(
      'unknown',
    );
  });

  it('음성 대조를 재지 못했으면 승격하지 않는다 — 종전 보수 판정 유지', () => {
    const results = [{ exposed: false, judged: false, status: 200, length: 12557 }];

    expect(region.judgeExposureResults(results, 0, okControl, null)).toBe('unknown');
  });

  it('노출 확정은 승격보다 우선한다 — 마커가 일치하면 형태와 무관하게 exposed', () => {
    const results = [{ exposed: true, judged: true, status: 200, length: 12557 }];

    expect(region.judgeExposureResults(results, 0, okControl, { status: 200, length: 12557 })).toBe(
      'exposed',
    );
  });

  it('양성 대조가 깨지면 승격 자체에 도달하지 않는다 — 인증 가로채기 배치 차단', () => {
    // 전 주소에 200 로그인 화면을 내리는 배치에서는 대상과 음성 대조가 같은 형태로 보인다.
    // 그러나 그런 서버는 인스톨러 자산도 가로채므로 양성 대조가 먼저 깨지고, 측정 전체가
    // 판정 불능으로 떨어진다 — 두 대조가 함께 서야 자동 판정이 성립한다.
    const results = [{ exposed: false, judged: false, status: 200, length: 12557 }];

    expect(
      region.judgeExposureResults(results, 0, [{ reachable: false }], { status: 200, length: 12557 }),
    ).toBe('unknown');
  });

  it('재지 못한 계열이 있으면 승격돼도 safe 가 아니다 (기존 fail-open 차단 유지)', () => {
    const results = [{ exposed: false, judged: false, status: 200, length: 12557 }];

    expect(region.judgeExposureResults(results, 1, okControl, { status: 200, length: 12557 })).toBe(
      'unknown',
    );
  });

  it('음성 대조 프로브는 status 와 본문 길이를 돌려준다', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        type: 'basic',
        ok: true,
        status: 200,
        text: () => Promise.resolve('x'.repeat(1234)),
      }),
    );

    await expect(
      region.probeAbsentControl('https://host.test', { path: '/g7-exposure-absent-abc', label: 'absent' }),
    ).resolves.toEqual({ status: 200, length: 1234 });
  });

  // 15차 G3 W1-7 — 형태 대조는 **200 계열에서만** 의미가 있다(403/404 는 이미 대상 판정이
  // 보호로 읽는 응답이고, 5xx 는 서버 상태지 「파일 없는 주소의 형태」가 아니다). 종전 주석은
  // 그렇게 적혀 있었는데 코드는 전 status 를 서명으로 받았다.
  it.each([403, 404, 500, 503])('음성 대조가 %d 이면 서명이 아니다 — null', async (status) => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue({
        type: 'basic',
        ok: false,
        status,
        text: () => Promise.resolve('x'.repeat(12557)),
      }),
    );

    await expect(
      region.probeAbsentControl('https://host.test', { path: '/g7-exposure-absent-abc', label: 'absent' }),
    ).resolves.toBeNull();
  });

  it('음성 대조가 리다이렉트·네트워크 실패면 null — 승격 근거가 되지 않는다', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ type: 'opaqueredirect', status: 0 }));
    await expect(
      region.probeAbsentControl('https://host.test', { path: '/g7-exposure-absent-abc', label: 'absent' }),
    ).resolves.toBeNull();

    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('network')));
    await expect(
      region.probeAbsentControl('https://host.test', { path: '/g7-exposure-absent-abc', label: 'absent' }),
    ).resolves.toBeNull();

    await expect(region.probeAbsentControl('https://host.test', null)).resolves.toBeNull();
  });
});
