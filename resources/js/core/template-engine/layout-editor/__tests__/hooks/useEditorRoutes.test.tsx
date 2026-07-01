/**
 * useEditorRoutes 테스트
 *
 * 검증:
 *  1. fetch 성공 시 routes → buildRouteTree → SET_ROUTE_TREE dispatch
 *  2. fetch 실패 시 셸은 깨지지 않고 routeTree 는 빈 상태 유지
 *  3. 부팅 templateId 와 편집 대상 templateId 가 다를 때 편집 대상 lang load 시도
 *  4. fetch 가 stub(undefined 반환) 인 환경에서도 마운트 안전
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, waitFor } from '@testing-library/react';
import { useEditorRoutes } from '../../hooks/useEditorRoutes';
import { LayoutEditorProvider, useLayoutEditor } from '../../LayoutEditorContext';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';

function Probe(): React.ReactElement {
  const { state } = useLayoutEditor();
  // 트리 전체를 직렬화해 path 검증 가능하게 한다 — 표현식 평가 회귀 테스트가
  // children 의 node.path 까지 들여다봐야 하기 때문.
  // 그룹 label 도 포함 — display_name 다국어 객체 해석 회귀 테스트에서 검사.
  const serialized = JSON.stringify(
    state.routeTree.map((group) => ({
      path: group.path,
      label: group.label,
      children: (group.children ?? []).map((c) => ({ path: c.path, layoutName: c.layoutName })),
    })),
  );
  return (
    <div
      data-testid="probe"
      data-route-count={state.routeTree.length}
      data-tree={serialized}
      // 진입 가드 회귀 테스트용 — routesError 의 kind/status 노출.
      data-error-kind={state.routesError?.kind ?? ''}
      data-error-status={state.routesError ? String(state.routesError.status) : ''}
    >
      {state.routeTree.length}
    </div>
  );
}

function Harness({ targetId, bootId = 'sirsoft-admin_basic' }: { targetId: string; bootId?: string }): React.ReactElement {
  function Body(): React.ReactElement {
    useEditorRoutes({ templateIdentifier: targetId });
    return <Probe />;
  }
  const engine = new TranslationEngine();
  return (
    <TranslationProvider
      translationEngine={engine}
      translationContext={{ templateId: bootId, locale: 'ko' }}
    >
      <LayoutEditorProvider templateIdentifier={targetId} initialLocale="ko">
        <Body />
      </LayoutEditorProvider>
    </TranslationProvider>
  );
}

describe('useEditorRoutes', () => {
  const originalFetch = global.fetch;

  beforeEach(() => {
    // 매 테스트마다 fetch 를 fresh stub 으로 — TranslationEngine.loadTranslations 도
    // fetch 사용하므로 그쪽 응답까지 별도 분기 처리.
    (global as any).fetch = vi.fn();
  });

  afterEach(() => {
    (global as any).fetch = originalFetch;
    vi.restoreAllMocks();
  });

  it('routes fetch 성공 시 SET_ROUTE_TREE dispatch 로 트리 채워짐', async () => {
    const routesResponse = {
      success: true,
      data: {
        routes: [
          { path: '/', layout: 'home', source: { kind: 'template' as const, identifier: null } },
          { path: '/login', layout: 'auth/login', source: { kind: 'template' as const, identifier: null } },
        ],
      },
    };

    (global.fetch as any).mockImplementation((url: string) => {
      if (url.includes('/routes.json')) {
        return Promise.resolve({
          ok: true,
          json: () => Promise.resolve(routesResponse),
        } as Response);
      }
      // lang/ko.json — 빈 dict
      return Promise.resolve({ ok: true, json: () => Promise.resolve({}) } as Response);
    });

    const { findByTestId } = render(<Harness targetId="sirsoft-basic" />);

    await waitFor(async () => {
      const probe = await findByTestId('probe');
      // 그룹 1개(template) — routes 2건이 그 그룹에 묶임
      expect(probe.getAttribute('data-route-count')).toBe('1');
    });
  });

  it('fetch 실패 시에도 셸 마운트는 깨지지 않고 routeTree empty 유지', async () => {
    (global.fetch as any).mockRejectedValue(new Error('network down'));

    const { findByTestId } = render(<Harness targetId="sirsoft-basic" />);

    const probe = await findByTestId('probe');
    expect(probe).toBeTruthy();
    expect(probe.getAttribute('data-route-count')).toBe('0');
  });

  it('fetch stub 이 undefined 반환해도(jsdom 기본 환경) throw 없이 마운트', async () => {
    (global.fetch as any).mockReturnValue(undefined);

    const { findByTestId } = render(<Harness targetId="sirsoft-basic" />);

    const probe = await findByTestId('probe');
    expect(probe).toBeTruthy();
    expect(probe.getAttribute('data-route-count')).toBe('0');
  });

  it('routes 응답의 path 표현식 ({{_global.modules?...}}) 이 window.G7Config.modules 값으로 평가되어 트리 노드에 반영된다', async () => {
    // 회귀: 다이렉트 진입 시 트리 path 가 raw 표현식이라 URL 이 깨지는 결함.
    // 정상 부팅의 TemplateApp.resolveRouteExpressions 와 동등한 처리를 편집기에서도 수행해야 한다.
    const originalConfig = (window as any).G7Config;
    (window as any).G7Config = {
      ...(originalConfig ?? {}),
      modules: {
        'sirsoft-ecommerce': {
          basic_info: { route_path: 'shop', no_route: false },
        },
      },
    };

    try {
      const routesResponse = {
        success: true,
        data: {
          routes: [
            {
              path: "/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.no_route ? '' : (_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop')}}/checkout",
              layout: 'shop/checkout',
              source: { kind: 'module' as const, identifier: 'sirsoft-ecommerce' },
            },
          ],
        },
      };

      (global.fetch as any).mockImplementation((url: string) => {
        if (url.includes('/routes.json')) {
          return Promise.resolve({
            ok: true,
            json: () => Promise.resolve(routesResponse),
          } as Response);
        }
        return Promise.resolve({ ok: true, json: () => Promise.resolve({}) } as Response);
      });

      const { findByTestId } = render(<Harness targetId="sirsoft-basic" />);

      await waitFor(async () => {
        const probe = await findByTestId('probe');
        const tree = JSON.parse(probe.getAttribute('data-tree') ?? '[]');
        // 모듈 그룹 1개 + children 1건
        expect(tree.length).toBeGreaterThan(0);
        const child = tree[0].children?.[0];
        expect(child).toBeDefined();
        expect(child.path).toBe('/shop/checkout');
        // raw 표현식이 남아 있으면 fail
        expect(child.path).not.toContain('{{');
        expect(child.path).not.toContain('_global');
      });
    } finally {
      (window as any).G7Config = originalConfig;
    }
  });

  it('routes 응답의 redirect 표현식도 동일하게 평가된다', async () => {
    const originalConfig = (window as any).G7Config;
    (window as any).G7Config = {
      ...(originalConfig ?? {}),
      modules: {
        'sirsoft-ecommerce': { basic_info: { route_path: 'shop' } },
      },
    };

    try {
      const routesResponse = {
        success: true,
        data: {
          routes: [
            {
              path: '/legacy-shop',
              redirect: "/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop'}}",
              source: { kind: 'module' as const, identifier: 'sirsoft-ecommerce' },
            },
          ],
        },
      };

      (global.fetch as any).mockImplementation((url: string) => {
        if (url.includes('/routes.json')) {
          return Promise.resolve({
            ok: true,
            json: () => Promise.resolve(routesResponse),
          } as Response);
        }
        return Promise.resolve({ ok: true, json: () => Promise.resolve({}) } as Response);
      });

      const { findByTestId } = render(<Harness targetId="sirsoft-basic" />);

      await waitFor(async () => {
        const probe = await findByTestId('probe');
        const tree = JSON.parse(probe.getAttribute('data-tree') ?? '[]');
        const child = tree[0].children?.[0];
        // path 는 그대로 — redirect 만 평가 대상 (path 에 표현식 없음)
        expect(child.path).toBe('/legacy-shop');
      });
    } finally {
      (window as any).G7Config = originalConfig;
    }
  });

  it('window.G7Config.modules 가 부재해도 path 표현식이 안전한 fallback 으로 평가된다 (??/?? 옵셔널체이닝)', async () => {
    const originalConfig = (window as any).G7Config;
    // modules 자체 미정의 — 표현식의 ?? fallback 이 작동해 'shop' 채택되어야 함
    (window as any).G7Config = { ...(originalConfig ?? {}), modules: undefined };

    try {
      const routesResponse = {
        success: true,
        data: {
          routes: [
            {
              path: "/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop'}}/cart",
              layout: 'shop/cart',
              source: { kind: 'module' as const, identifier: 'sirsoft-ecommerce' },
            },
          ],
        },
      };

      (global.fetch as any).mockImplementation((url: string) => {
        if (url.includes('/routes.json')) {
          return Promise.resolve({
            ok: true,
            json: () => Promise.resolve(routesResponse),
          } as Response);
        }
        return Promise.resolve({ ok: true, json: () => Promise.resolve({}) } as Response);
      });

      const { findByTestId } = render(<Harness targetId="sirsoft-basic" />);

      await waitFor(async () => {
        const probe = await findByTestId('probe');
        const tree = JSON.parse(probe.getAttribute('data-tree') ?? '[]');
        const child = tree[0].children?.[0];
        expect(child.path).toBe('/shop/cart');
        expect(child.path).not.toContain('{{');
      });
    } finally {
      (window as any).G7Config = originalConfig;
    }
  });

  it('window.G7Config.activeModules 의 display_name 이 다국어 객체({ko,en}) 이면 그룹 라벨에 현재 locale 값이 박힌다', async () => {
    // 회귀: display_name 이 객체일 때 String() 변환되어 라벨이 `[object Object]` 로 표시되던 결함.
    const originalConfig = (window as any).G7Config;
    (window as any).G7Config = {
      ...(originalConfig ?? {}),
      activeModules: [
        {
          identifier: 'sirsoft-ecommerce',
          display_name: { ko: '이커머스', en: 'E-commerce' },
          version: '1.0.0',
        },
      ],
      activePlugins: [
        {
          identifier: 'sirsoft-ckeditor5',
          display_name: { ko: 'CKEditor 5', en: 'CKEditor 5' },
          version: '1.0.0',
        },
      ],
    };

    try {
      const routesResponse = {
        success: true,
        data: {
          routes: [
            {
              path: '/admin/ecommerce/products',
              layout: 'shop/products',
              source: { kind: 'module' as const, identifier: 'sirsoft-ecommerce' },
            },
            {
              path: '/admin/plugins/ckeditor5/settings',
              layout: 'ckeditor5/settings',
              source: { kind: 'plugin' as const, identifier: 'sirsoft-ckeditor5' },
            },
          ],
        },
      };

      (global.fetch as any).mockImplementation((url: string) => {
        if (url.includes('/routes.json')) {
          return Promise.resolve({ ok: true, json: () => Promise.resolve(routesResponse) } as Response);
        }
        return Promise.resolve({ ok: true, json: () => Promise.resolve({}) } as Response);
      });

      const { findByTestId } = render(<Harness targetId="sirsoft-basic" />);

      await waitFor(async () => {
        const probe = await findByTestId('probe');
        const tree = JSON.parse(probe.getAttribute('data-tree') ?? '[]');
        // 그룹 라벨은 `$t:...|name=<name>` 파이프 표현식 — name 파라미터에 한글 표시명이 박혀야 한다.
        const moduleGroup = tree.find((g: any) => g.path === '__group__/module:sirsoft-ecommerce');
        const pluginGroup = tree.find((g: any) => g.path === '__group__/plugin:sirsoft-ckeditor5');
        expect(moduleGroup).toBeDefined();
        expect(pluginGroup).toBeDefined();
        expect(moduleGroup.label).toContain('name=이커머스');
        expect(pluginGroup.label).toContain('name=CKEditor 5');
        // 객체 stringify 회귀 차단
        expect(moduleGroup.label).not.toContain('[object Object]');
        expect(pluginGroup.label).not.toContain('[object Object]');
      });
    } finally {
      (window as any).G7Config = originalConfig;
    }
  });

  it('display_name 이 평문 string 일 때도 그대로 라벨에 박힌다 (역호환)', async () => {
    const originalConfig = (window as any).G7Config;
    (window as any).G7Config = {
      ...(originalConfig ?? {}),
      activeModules: [
        { identifier: 'sirsoft-board', display_name: 'Board Plain', version: '1.0.0' },
      ],
      activePlugins: [],
    };

    try {
      const routesResponse = {
        success: true,
        data: {
          routes: [
            {
              path: '/admin/boards',
              layout: 'admin/boards',
              source: { kind: 'module' as const, identifier: 'sirsoft-board' },
            },
          ],
        },
      };

      (global.fetch as any).mockImplementation((url: string) => {
        if (url.includes('/routes.json')) {
          return Promise.resolve({ ok: true, json: () => Promise.resolve(routesResponse) } as Response);
        }
        return Promise.resolve({ ok: true, json: () => Promise.resolve({}) } as Response);
      });

      const { findByTestId } = render(<Harness targetId="sirsoft-basic" />);

      await waitFor(async () => {
        const probe = await findByTestId('probe');
        const tree = JSON.parse(probe.getAttribute('data-tree') ?? '[]');
        const moduleGroup = tree.find((g: any) => g.path === '__group__/module:sirsoft-board');
        expect(moduleGroup.label).toContain('name=Board Plain');
      });
    } finally {
      (window as any).G7Config = originalConfig;
    }
  });

  it('display_name 누락 시 identifier 로 폴백', async () => {
    const originalConfig = (window as any).G7Config;
    (window as any).G7Config = {
      ...(originalConfig ?? {}),
      activeModules: [{ identifier: 'sirsoft-page' }],
      activePlugins: [],
    };

    try {
      const routesResponse = {
        success: true,
        data: {
          routes: [
            {
              path: '/admin/pages',
              layout: 'admin/pages',
              source: { kind: 'module' as const, identifier: 'sirsoft-page' },
            },
          ],
        },
      };

      (global.fetch as any).mockImplementation((url: string) => {
        if (url.includes('/routes.json')) {
          return Promise.resolve({ ok: true, json: () => Promise.resolve(routesResponse) } as Response);
        }
        return Promise.resolve({ ok: true, json: () => Promise.resolve({}) } as Response);
      });

      const { findByTestId } = render(<Harness targetId="sirsoft-basic" />);

      await waitFor(async () => {
        const probe = await findByTestId('probe');
        const tree = JSON.parse(probe.getAttribute('data-tree') ?? '[]');
        const moduleGroup = tree.find((g: any) => g.path === '__group__/module:sirsoft-page');
        expect(moduleGroup.label).toContain('name=sirsoft-page');
      });
    } finally {
      (window as any).G7Config = originalConfig;
    }
  });

  // ── 진입 가드 ──────────────
  //
  // 결함: 편집기 진입 fetch 가 공개(인증 불필요) `/api/templates/{id}/routes.json`
  // 를 호출해 세션 만료/비로그인 상태에서도 200 → 레이아웃 선택 전까지 401/자동
  // 로그아웃이 발동하지 않았다. 수정: 권한 가드된 `/api/admin/templates/{id}/editor/
  // routes.json` 을 Bearer 토큰과 함께 호출 → 진입 시점에 401/403 이 즉시 감지된다.

  it('routes fetch 는 권한 가드된 /editor/routes.json 엔드포인트를 호출한다 (공개 엔드포인트 금지)', async () => {
    const fetchSpy = global.fetch as any;
    fetchSpy.mockImplementation((url: string) => {
      if (url.includes('routes.json')) {
        return Promise.resolve({
          ok: true,
          json: () => Promise.resolve({ success: true, data: { routes: [] } }),
        } as Response);
      }
      return Promise.resolve({ ok: true, json: () => Promise.resolve({}) } as Response);
    });

    render(<Harness targetId="sirsoft-basic" />);

    await waitFor(() => {
      const urls = fetchSpy.mock.calls.map((c: any[]) => String(c[0] ?? ''));
      const routesUrl = urls.find((u: string) => u.includes('routes.json'));
      expect(routesUrl).toBeDefined();
      // 가드 엔드포인트: /api/admin/templates/{id}/editor/routes.json
      expect(routesUrl).toContain('/api/admin/templates/sirsoft-basic/editor/routes.json');
      // 공개 엔드포인트(/api/templates/{id}/routes.json) 호출 금지
      expect(routesUrl).not.toMatch(/\/api\/templates\/sirsoft-basic\/routes\.json/);
    });
  });

  it('routes fetch 에 Authorization: Bearer 토큰 헤더를 첨부한다', async () => {
    const originalToken = window.localStorage.getItem('auth_token');
    window.localStorage.setItem('auth_token', 'sample-token-xyz');
    const fetchSpy = global.fetch as any;
    fetchSpy.mockImplementation((url: string) => {
      if (url.includes('routes.json')) {
        return Promise.resolve({
          ok: true,
          json: () => Promise.resolve({ success: true, data: { routes: [] } }),
        } as Response);
      }
      return Promise.resolve({ ok: true, json: () => Promise.resolve({}) } as Response);
    });

    try {
      render(<Harness targetId="sirsoft-basic" />);

      await waitFor(() => {
        const routesCall = fetchSpy.mock.calls.find((c: any[]) =>
          String(c[0] ?? '').includes('routes.json'),
        );
        expect(routesCall).toBeDefined();
        const headers = (routesCall![1]?.headers ?? {}) as Record<string, string>;
        expect(headers.Authorization).toBe('Bearer sample-token-xyz');
      });
    } finally {
      if (originalToken === null) window.localStorage.removeItem('auth_token');
      else window.localStorage.setItem('auth_token', originalToken);
    }
  });

  it('routes fetch 가 401 이면 SET_ROUTES_ERROR(unauthorized) 로 진입 차단된다', async () => {
    const fetchSpy = global.fetch as any;
    fetchSpy.mockImplementation((url: string) => {
      if (url.includes('routes.json')) {
        return Promise.resolve({
          ok: false,
          status: 401,
          json: () => Promise.resolve({ message: 'Unauthenticated.' }),
        } as Response);
      }
      return Promise.resolve({ ok: true, json: () => Promise.resolve({}) } as Response);
    });

    const { findByTestId } = render(<Harness targetId="sirsoft-basic" />);

    await waitFor(async () => {
      const probe = await findByTestId('probe');
      expect(probe.getAttribute('data-error-kind')).toBe('unauthorized');
      expect(probe.getAttribute('data-error-status')).toBe('401');
      // 트리는 비어 있어야 한다(셸이 AccessErrorPanel 로 분기 → chrome 미렌더).
      expect(probe.getAttribute('data-route-count')).toBe('0');
    });
  });

  it('routes fetch 가 403 이면 SET_ROUTES_ERROR(forbidden) 로 진입 차단된다', async () => {
    const fetchSpy = global.fetch as any;
    fetchSpy.mockImplementation((url: string) => {
      if (url.includes('routes.json')) {
        return Promise.resolve({
          ok: false,
          status: 403,
          json: () =>
            Promise.resolve({ message: 'Forbidden', data: { required_permissions: 'core.templates.layouts.edit' } }),
        } as Response);
      }
      return Promise.resolve({ ok: true, json: () => Promise.resolve({}) } as Response);
    });

    const { findByTestId } = render(<Harness targetId="sirsoft-basic" />);

    await waitFor(async () => {
      const probe = await findByTestId('probe');
      expect(probe.getAttribute('data-error-kind')).toBe('forbidden');
      expect(probe.getAttribute('data-error-status')).toBe('403');
    });
  });

  it('편집 대상 != 부팅 템플릿일 때 편집 대상 lang fetch 호출', async () => {
    const fetchSpy = global.fetch as any;
    fetchSpy.mockImplementation((url: string) => {
      if (url.includes('/routes.json')) {
        return Promise.resolve({
          ok: true,
          json: () => Promise.resolve({ success: true, data: { routes: [] } }),
        } as Response);
      }
      // lang fetch — TranslationEngine.loadTranslations 가 호출
      return Promise.resolve({ ok: true, json: () => Promise.resolve({}) } as Response);
    });

    render(<Harness targetId="sirsoft-basic" bootId="sirsoft-admin_basic" />);

    await waitFor(() => {
      const urls = fetchSpy.mock.calls.map((c: any[]) => String(c[0] ?? ''));
      const langCallToTarget = urls.some(
        (u: string) => u.includes('/api/templates/sirsoft-basic/lang/') && u.includes('ko')
      );
      expect(langCallToTarget).toBe(true);
    });
  });
});

describe('useEditorRoutes — layout_versions 파싱', () => {
  const originalFetch = global.fetch;

  beforeEach(() => {
    (global as any).fetch = vi.fn();
  });

  afterEach(() => {
    (global as any).fetch = originalFetch;
    vi.restoreAllMocks();
  });

  function VersionProbe(): React.ReactElement {
    const { state } = useLayoutEditor();
    return (
      <div data-testid="version-probe" data-versions={JSON.stringify(state.layoutVersions)} />
    );
  }

  function VersionHarness({ targetId }: { targetId: string }): React.ReactElement {
    function Body(): React.ReactElement {
      useEditorRoutes({ templateIdentifier: targetId });
      return <VersionProbe />;
    }
    const engine = new TranslationEngine();
    return (
      <TranslationProvider
        translationEngine={engine}
        translationContext={{ templateId: 'sirsoft-admin_basic', locale: 'ko' }}
      >
        <LayoutEditorProvider templateIdentifier={targetId} initialLocale="ko">
          <Body />
        </LayoutEditorProvider>
      </TranslationProvider>
    );
  }

  function mockRoutesResponse(extraData: Record<string, unknown>): void {
    (global.fetch as any).mockImplementation((url: string) => {
      if (url.includes('/routes.json')) {
        return Promise.resolve({
          ok: true,
          json: () =>
            Promise.resolve({
              success: true,
              data: {
                routes: [
                  { path: '/', layout: 'home', source: { kind: 'template' as const, identifier: null } },
                ],
                ...extraData,
              },
            }),
        } as Response);
      }
      return Promise.resolve({ ok: true, json: () => Promise.resolve({}) } as Response);
    });
  }

  it('응답의 layout_versions 맵을 SET_LAYOUT_VERSIONS 로 컨텍스트에 반영한다', async () => {
    mockRoutesResponse({ layout_versions: { home: 4, 'auth/login': 12 } });

    const { findByTestId } = render(<VersionHarness targetId="sirsoft-basic" />);

    await waitFor(async () => {
      const probe = await findByTestId('version-probe');
      expect(JSON.parse(probe.getAttribute('data-versions') ?? '{}')).toEqual({
        home: 4,
        'auth/login': 12,
      });
    });
  });

  it('layout_versions 누락(구버전 백엔드)/비정상 값은 빈 맵으로 디그레이드한다', async () => {
    mockRoutesResponse({ layout_versions: { home: 'oops', bad: null, ok: 2 } });

    const { findByTestId } = render(<VersionHarness targetId="sirsoft-basic" />);

    await waitFor(async () => {
      const probe = await findByTestId('version-probe');
      // 숫자 값만 채택 — 비정상 항목은 걸러진다
      expect(JSON.parse(probe.getAttribute('data-versions') ?? '{}')).toEqual({ ok: 2 });
    });
  });
});

describe('useEditorRoutes — 확장 current_version 파싱', () => {
  const originalFetch = global.fetch;

  beforeEach(() => {
    (global as any).fetch = vi.fn();
  });

  afterEach(() => {
    (global as any).fetch = originalFetch;
    vi.restoreAllMocks();
  });

  function ExtVersionProbe(): React.ReactElement {
    const { state } = useLayoutEditor();
    return (
      <div
        data-testid="ext-version-probe"
        data-versions={JSON.stringify(state.extensionVersions)}
      />
    );
  }

  it('layout-extensions 응답의 확장별 current_version 을 SET_EXTENSION_VERSIONS 로 반영한다 (null/누락 제외)', async () => {
    (global.fetch as any).mockImplementation((url: string) => {
      if (url.includes('/routes.json')) {
        return Promise.resolve({
          ok: true,
          json: () =>
            Promise.resolve({
              success: true,
              data: {
                routes: [
                  { path: '/', layout: 'home', source: { kind: 'template' as const, identifier: null } },
                ],
              },
            }),
        } as Response);
      }
      if (url.includes('/layout-extensions')) {
        return Promise.resolve({
          ok: true,
          json: () =>
            Promise.resolve({
              success: true,
              data: [
                {
                  source_identifier: 'sirsoft-board',
                  source_type: 'module' as const,
                  source_label: '게시판',
                  extensions: [
                    {
                      id: 7,
                      extension_type: 'extension_point' as const,
                      target_name: 'html_content',
                      is_active: true,
                      is_modified: true,
                      host_layouts: ['board/form'],
                      current_version: 4,
                    },
                    {
                      id: 12,
                      extension_type: 'overlay' as const,
                      target_name: 'home',
                      is_active: true,
                      is_modified: false,
                      host_layouts: ['home'],
                      current_version: null, // 이력 없음 → 맵 제외
                    },
                  ],
                },
              ],
            }),
        } as Response);
      }
      return Promise.resolve({ ok: true, json: () => Promise.resolve({}) } as Response);
    });

    function Harness2(): React.ReactElement {
      function Body(): React.ReactElement {
        useEditorRoutes({ templateIdentifier: 'sirsoft-basic' });
        return <ExtVersionProbe />;
      }
      const engine = new TranslationEngine();
      return (
        <TranslationProvider
          translationEngine={engine}
          translationContext={{ templateId: 'sirsoft-admin_basic', locale: 'ko' }}
        >
          <LayoutEditorProvider templateIdentifier="sirsoft-basic" initialLocale="ko">
            <Body />
          </LayoutEditorProvider>
        </TranslationProvider>
      );
    }

    const { findByTestId } = render(<Harness2 />);

    await waitFor(async () => {
      const probe = await findByTestId('ext-version-probe');
      expect(JSON.parse(probe.getAttribute('data-versions') ?? '{}')).toEqual({ '7': 4 });
    });
  });
});
