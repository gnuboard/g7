/**
 * TemplateApp.isAllowedScriptSrc 테스트 (KVE-2026-1915 B-2 + 신뢰 출처 허용목록)
 *
 * 배포 번들에서는 minify 로 이 함수를 이름으로 호출할 수 없어 E2E 는 결과(네트워크에
 * 허용목록 밖 스크립트가 없음)만 관찰한다. 결정 로직 자체는 여기서 잠근다.
 *
 * 효과 요약(마커 아님 — 평문): trusted_script_host_allowlist_wired,
 * untrusted_external_script_blocked. 실제 마커는 그 효과를 단언하는 개별 테스트에만 둔다 —
 * 파일 레벨에 몰아 적으면 테스트를 전부 지워도 커버리지가 green 으로 남는다.
 *
 * 레이아웃 `scripts[].src` 로더는 same-origin path 이거나, 확장이 manifest 로 선언해
 * 코어가 `window.G7Config.trustedScriptHosts` 로 노출한 신뢰 호스트에 속한 외부 스크립트만
 * 로드한다. 그 외 외부 origin(미선언 원격 코드)은 차단한다.
 *
 * 회귀 배경: 초기 B-2 는 모든 외부 origin 을 무조건 차단해 CKEditor5(cdn.ckeditor.com)·
 * Daum 우편번호(t1.daumcdn.net) 등 번들 확장의 CDN 스크립트까지 막아 기능이 깨졌다.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TemplateApp } from '../TemplateApp';
import type { TemplateAppConfig } from '../TemplateApp';
import { AuthManager } from '../auth/AuthManager';

const mockApiClient = {
  post: vi.fn().mockResolvedValue({}),
  get: vi.fn().mockResolvedValue({}),
  removeToken: vi.fn(),
  setToken: vi.fn(),
  getToken: vi.fn().mockReturnValue(null),
  setOnUnauthorized: vi.fn(),
};

vi.mock('../api/ApiClient', () => ({
  getApiClient: () => mockApiClient,
}));

const { sharedActionDispatcher } = vi.hoisted(() => ({
  sharedActionDispatcher: {
    setNavigate: vi.fn(),
    setGlobalState: vi.fn(),
    setDefaultContext: vi.fn(),
    setGlobalStateUpdater: vi.fn(),
    registerHandler: vi.fn(),
    customHandlers: new Map(),
  },
}));

vi.mock('../template-engine', () => ({
  initTemplateEngine: vi.fn().mockResolvedValue(undefined),
  renderTemplate: vi.fn().mockResolvedValue(undefined),
  destroyTemplate: vi.fn(),
  getActionDispatcher: vi.fn().mockReturnValue(sharedActionDispatcher),
  getState: vi.fn().mockReturnValue({
    actionDispatcher: sharedActionDispatcher,
    reactRoot: null,
    currentLayoutJson: null,
  }),
}));

vi.mock('../template-engine/TransitionManager', () => ({
  transitionManager: {
    setPending: vi.fn(),
    getIsPending: vi.fn(() => false),
    subscribe: vi.fn(() => vi.fn()),
    clearSubscribers: vi.fn(),
  },
}));

vi.mock('../routing/Router', () => ({
  Router: vi.fn(function (this: any) {
    this.loadRoutes = vi.fn().mockResolvedValue(undefined);
    this.on = vi.fn();
    this.navigateToCurrentPath = vi.fn();
    this.getRoutes = vi.fn().mockReturnValue([]);
  }),
}));

vi.mock('../template-engine/LayoutLoader', async () => {
  const actual = await vi.importActual<any>('../template-engine/LayoutLoader');
  return {
    ...actual,
    LayoutLoader: vi.fn(function (this: any) {
      this.loadLayout = vi.fn().mockResolvedValue({ components: [] });
    }),
  };
});

vi.mock('../template-engine/ComponentRegistry', () => {
  const mockInstance = {
    loadComponents: vi.fn().mockResolvedValue(undefined),
    getComponent: vi.fn().mockReturnValue(() => null),
    hasComponent: vi.fn().mockReturnValue(true),
    getInstance: vi.fn(),
  };
  mockInstance.getInstance.mockReturnValue(mockInstance);
  return {
    ComponentRegistry: {
      getInstance: vi.fn(() => mockInstance),
    },
  };
});

describe('TemplateApp.isAllowedScriptSrc (신뢰 출처 허용목록)', () => {
  let app: TemplateApp;

  const build = (trustedScriptHosts: string[] = []): TemplateApp => {
    (window as any).G7Config = { trustedScriptHosts };
    const config: TemplateAppConfig = {
      templateId: 'sirsoft-admin_basic',
      templateType: 'admin',
      locale: 'ko',
      debug: false,
    };
    return new TemplateApp(config);
  };

  const allowed = (a: TemplateApp, src: string): boolean =>
    (a as any).isAllowedScriptSrc(src);

  beforeEach(() => {
    document.body.innerHTML = '<div id="app"></div>';
    Object.defineProperty(window, 'location', {
      value: {
        href: '',
        origin: 'https://g7.test',
        protocol: 'https:',
        pathname: '/',
        search: '',
      },
      writable: true,
      configurable: true,
    });
    (AuthManager as any).instance = undefined;
    (window as any).G7Core = { devTools: { trackAuthEvent: vi.fn() } };
    vi.clearAllMocks();
  });

  afterEach(() => {
    delete (window as any).G7Core;
    delete (window as any).G7Config;
  });

  /** @effects trusted_script_host_allowlist_wired */
  it('same-origin 절대 경로는 항상 허용된다', () => {
    app = build([]);
    expect(allowed(app, '/api/modules/x/widget.js')).toBe(true);
  });

  /** @effects untrusted_external_script_blocked */
  it('신뢰 목록이 비면 외부 origin 스크립트는 차단된다', () => {
    app = build([]);
    expect(allowed(app, 'https://cdn.ckeditor.com/ckeditor5/x.js')).toBe(false);
  });

  /** @effects trusted_script_host_allowlist_wired */
  it('선언된 신뢰 호스트의 scheme URL 스크립트는 허용된다', () => {
    app = build(['cdn.ckeditor.com']);
    expect(allowed(app, 'https://cdn.ckeditor.com/ckeditor5/43.3.1/ckeditor5.umd.js')).toBe(true);
  });

  /** @effects trusted_script_host_allowlist_wired */
  it('선언된 신뢰 호스트의 protocol-relative 스크립트도 허용된다', () => {
    app = build(['t1.daumcdn.net']);
    expect(allowed(app, '//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js')).toBe(true);
  });

  /** @effects untrusted_external_script_blocked */
  it('신뢰 목록에 없는 외부 호스트는 선언 여부와 무관하게 차단된다', () => {
    app = build(['cdn.ckeditor.com']);
    expect(allowed(app, 'https://evil.com/x.js')).toBe(false);
    expect(allowed(app, '//evil.com/x.js')).toBe(false);
  });

  /** @effects trusted_script_host_allowlist_wired */
  it('호스트 대소문자는 정규화되어 매칭된다', () => {
    app = build(['cdn.ckeditor.com']);
    expect(allowed(app, 'https://CDN.CKEditor.COM/x.js')).toBe(true);
  });

  /** @effects untrusted_external_script_blocked */
  it('javascript:·data: 등 비 http(s) scheme 은 신뢰 호스트여도 차단된다', () => {
    app = build(['cdn.ckeditor.com']);
    expect(allowed(app, 'javascript:alert(1)')).toBe(false);
    expect(allowed(app, 'data:text/javascript,alert(1)')).toBe(false);
  });

  /** @effects untrusted_external_script_blocked */
  it('빈 문자열·공백은 차단된다', () => {
    app = build(['cdn.ckeditor.com']);
    expect(allowed(app, '')).toBe(false);
    expect(allowed(app, '   ')).toBe(false);
  });

  // ==========================================
  // authority 우회 (KVE-2026-1915 후속)
  // ==========================================
  //
  // 브라우저 URL 파서는 (a) 파싱 전에 ASCII tab·개행을 제거하고 (b) special scheme 에서
  // 백슬래시를 슬래시와 동등하게 처리한다. 따라서 아래 형태들은 `//` 로 시작하지 않고
  // scheme 도 없으며 `/` 로 시작하지만, 실제로는 외부 origin 으로 해석되어 원격 스크립트가
  // 로드된다. 접두 문자열 검사만으로는 막을 수 없다.
  //
  // 실측(node `new URL(src, 'https://g7.test/')`): 6형태 전부 origin `https://evil.com`.

  const BS = String.fromCharCode(92);
  const TAB = String.fromCharCode(9);
  const LF = String.fromCharCode(10);
  const CR = String.fromCharCode(13);

  it.each([
    ['슬래시-백슬래시-슬래시', '/' + BS + '/evil.com/x.js'],
    ['슬래시-백슬래시', '/' + BS + 'evil.com/x.js'],
    ['슬래시-이중백슬래시', '/' + BS + BS + 'evil.com/x.js'],
    ['슬래시-탭-슬래시', '/' + TAB + '/evil.com/x.js'],
    ['슬래시-LF-슬래시', '/' + LF + '/evil.com/x.js'],
    ['슬래시-CR-슬래시', '/' + CR + '/evil.com/x.js'],
  ])('authority 우회(%s)는 차단된다', (_name, src) => {
    app = build([]);
    expect(allowed(app, src)).toBe(false);
  });

  /** @effects trusted_script_host_allowlist_wired, untrusted_external_script_blocked */
  it('authority 우회로 도달한 호스트는 신뢰 목록에 있어야만 허용된다', () => {
    // 정규화 후 호스트가 추출되므로, 신뢰 선언된 호스트면 허용·아니면 차단 (브라우저 해석과 일치)
    app = build(['cdn.ckeditor.com']);
    expect(allowed(app, '/' + BS + '/evil.com/x.js')).toBe(false);
    expect(allowed(app, '/' + BS + '/cdn.ckeditor.com/x.js')).toBe(true);
  });

  /** @effects trusted_script_host_allowlist_wired */
  it('경로 중간의 백슬래시·탭은 authority 를 만들지 않으므로 허용된다', () => {
    // 과차단 회귀 방지 — 브라우저도 same-origin 으로 해석한다(실측)
    app = build([]);
    expect(allowed(app, '/js/a' + BS + 'b.js')).toBe(true);
    expect(allowed(app, '/js/c' + TAB + 'd.js')).toBe(true);
  });

  // 신뢰 호스트를 userinfo 로 위장하는 형태. 백슬래시가 슬래시로 접히면서
  // `https://evil.com/@cdn.ckeditor.com/x.js` 가 되어 실제 출처는 evil.com 이다.
  // 저장측(TrustedScriptHosts::hostOf)이 정규화 없이 parse_url 로 읽으면 이 형태의
  // 호스트를 `cdn.ckeditor.com` 으로 보게 되므로, 세 계층이 같은 판정을 내는지 고정한다.
  /** @effects untrusted_external_script_blocked */
  it('신뢰 호스트를 userinfo 로 위장한 src 는 차단된다', () => {
    app = build(['cdn.ckeditor.com']);
    expect(allowed(app, 'https://evil.com' + BS + '@cdn.ckeditor.com/x.js')).toBe(false);
    expect(allowed(app, '//evil.com' + BS + '@cdn.ckeditor.com/x.js')).toBe(false);
  });

  // 브라우저는 선행 슬래시 런을 authority 시작으로 접는다(`///host` ≡ `//host`).
  // 정규화가 이를 접지 않으면 런타임만 host 를 뽑고 저장측·정적검사는 못 뽑는 갈림이 생긴다.
  /** @effects untrusted_external_script_blocked */
  it('선행 슬래시가 3개 이상이어도 authority 로 해석된다', () => {
    app = build(['cdn.ckeditor.com']);
    expect(allowed(app, '///cdn.ckeditor.com/x.js')).toBe(true);
    expect(allowed(app, '///evil.com/x.js')).toBe(false);
  });
});
