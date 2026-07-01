/**
 * editorSpecLoader.test.ts — 활성 확장 editor-spec 병합
 *
 * 검증:
 *  - 템플릿 + 활성 모듈/플러그인 스펙 fetch + 네임스페이스 병합
 *  - record 블록 key 병합 (템플릿이 마지막 = 최우선)
 *  - componentPalette.groups / states.groups concat
 *  - nesting draggable union + containers key 병합
 *  - sampleData byDataSourceId key 병합
 *  - sampleGlobalSources 순서 (모듈 → 플러그인 → 템플릿), sampleGlobal 미정의 소스 제외
 *  - 비활성 확장 미fetch (활성 식별자 목록에 없으면 fetch 자체를 안 함)
 *  - 모든 스펙 부재 → spec null
 *  - 조회 헬퍼
 */

import { describe, it, expect, vi } from 'vitest';
import {
  loadEditorSpecBundle,
  getComponentCapability,
  getControl,
  getActionRecipe,
  getConditionRecipe,
  getStateGroupsForScope,
  getInitActionRecipes,
  getComputedRecipes,
  getErrorRecipes,
  getLoadingComponents,
} from '../../spec/editorSpecLoader';
import type { EditorSpec } from '../../spec/specTypes';

/** 식별자 → spec 매핑으로 fetch 를 모킹 */
function makeFetcher(specs: Record<string, EditorSpec | null>): typeof fetch {
  return vi.fn(async (url: string | URL | Request) => {
    const u = String(url);
    const m = /\/(templates|modules|plugins)\/([^/]+)\/editor-spec/.exec(u);
    const id = m ? decodeURIComponent(m[2]) : '';
    if (!(id in specs)) {
      return { ok: false, status: 404, json: async () => ({}) } as unknown as Response;
    }
    return {
      ok: true,
      status: 200,
      json: async () => ({ success: true, data: { identifier: id, spec: specs[id] } }),
    } as unknown as Response;
  }) as unknown as typeof fetch;
}

describe('loadEditorSpecBundle — 활성 확장 네임스페이스 병합', () => {
  it('템플릿 + 모듈 + 플러그인 스펙을 단일 병합본으로 합친다', async () => {
    const fetcher = makeFetcher({
      'sirsoft-basic': {
        controls: { color: { widget: 'color-picker' } },
        actionRecipes: { goToPage: {} },
        componentPalette: { groups: [{ label: '$t:tpl', components: ['Div'] }] },
        states: { groups: [{ scope: { kind: 'route', match: '/' }, items: [{ id: 's1' }] }] },
        sampleData: { byDataSourceId: { current_user: { data: {} } } },
        nesting: { draggable: ['Div'], containers: { Div: { accepts: ['Span'] } } },
      },
      'shop-mod': {
        controls: { spacing: { widget: 'spacing' } },
        componentPalette: { groups: [{ label: '$t:mod', components: ['ProductCard'] }] },
        states: { groups: [{ scope: { kind: 'route', match: '/shop' }, items: [{ id: 's2' }] }] },
        sampleData: { byDataSourceId: { products: { data: [] } } },
        nesting: { draggable: ['ProductCard'], containers: { Section: { accepts: [] } } },
      },
      'pay-plg': {
        actionRecipes: { pay: {} },
      },
    });

    const { spec } = await loadEditorSpecBundle({
      templateIdentifier: 'sirsoft-basic',
      activeModuleIdentifiers: ['shop-mod'],
      activePluginIdentifiers: ['pay-plg'],
      fetcher,
    });

    expect(spec).not.toBeNull();
    // record 블록 key 병합
    expect(Object.keys(spec!.controls!)).toEqual(expect.arrayContaining(['color', 'spacing']));
    expect(Object.keys(spec!.actionRecipes!)).toEqual(expect.arrayContaining(['goToPage', 'pay']));
    // palette groups / states groups concat
    expect(spec!.componentPalette!.groups).toHaveLength(2);
    expect(spec!.states!.groups).toHaveLength(2);
    // nesting union + key 병합
    expect(spec!.nesting!.draggable).toEqual(expect.arrayContaining(['Div', 'ProductCard']));
    expect(Object.keys(spec!.nesting!.containers!)).toEqual(expect.arrayContaining(['Div', 'Section']));
    // sampleData byDataSourceId 병합
    expect(Object.keys(spec!.sampleData!.byDataSourceId!)).toEqual(
      expect.arrayContaining(['current_user', 'products']),
    );
  });

  it('actionChipCandidates 는 컨텍스트별 배열을 concat 한다(코어/확장 응답 칩 누적, key override 아님)', async () => {
    const fetcher = makeFetcher({
      tpl: {
        actionChipCandidates: {
          response: [{ path: 'data.tpl_field', labelKey: 'tpl.field', shape: 'scalar' }],
        },
      },
      mod: {
        actionChipCandidates: {
          response: [
            { path: 'data.pg_payment_handler', labelKey: 'mod.pg', shape: 'scalar' },
            { path: 'data.pg_payment_data', labelKey: 'mod.pgd', shape: 'object' },
          ],
          error: [{ path: 'data.domain_error', labelKey: 'mod.err', shape: 'scalar' }],
        },
      },
    });
    const { spec } = await loadEditorSpecBundle({
      templateIdentifier: 'tpl',
      activeModuleIdentifiers: ['mod'],
      activePluginIdentifiers: [],
      fetcher,
    });
    // 병합 순서: 모듈 → 템플릿. response 배열에 모듈 2종 + 템플릿 1종 = 3종(concat, 덮어쓰기 아님).
    const resp = spec!.actionChipCandidates!.response!;
    expect(resp.map((c) => c.path)).toEqual(
      expect.arrayContaining(['data.pg_payment_handler', 'data.pg_payment_data', 'data.tpl_field']),
    );
    expect(resp).toHaveLength(3);
    // error 컨텍스트는 모듈만 — 별도 배열로 보존(컨텍스트 간 누수 없음).
    expect(spec!.actionChipCandidates!.error!.map((c) => c.path)).toEqual(['data.domain_error']);
  });

  it('record 블록 충돌 시 템플릿(마지막 소스)이 이긴다', async () => {
    const fetcher = makeFetcher({
      tpl: { controls: { color: { widget: 'template-color' } } },
      mod: { controls: { color: { widget: 'module-color' } } },
    });
    const { spec } = await loadEditorSpecBundle({
      templateIdentifier: 'tpl',
      activeModuleIdentifiers: ['mod'],
      activePluginIdentifiers: [],
      fetcher,
    });
    expect(spec!.controls!.color.widget).toBe('template-color');
  });

  it('sampleGlobalSources 는 모듈 → 플러그인 → 템플릿 순이며 sampleGlobal 미정의 소스는 제외된다', async () => {
    const fetcher = makeFetcher({
      tpl: { sampleGlobal: { currentUser: { uuid: 'x' } } },
      mod: { sampleGlobal: { cart: {} } },
      plg: { actionRecipes: {} }, // sampleGlobal 없음 → 제외
    });
    const { sampleGlobalSources } = await loadEditorSpecBundle({
      templateIdentifier: 'tpl',
      activeModuleIdentifiers: ['mod'],
      activePluginIdentifiers: ['plg'],
      fetcher,
    });
    expect(sampleGlobalSources.map((s) => s.id)).toEqual(['mod', 'tpl']);
    expect(sampleGlobalSources[0].kind).toBe('module');
    expect(sampleGlobalSources[1].kind).toBe('template');
  });

  it('비활성 확장은 fetch 하지 않는다 (활성 식별자 목록 기준)', async () => {
    const fetcher = makeFetcher({ tpl: { controls: {} }, 'inactive-mod': { controls: {} } });
    await loadEditorSpecBundle({
      templateIdentifier: 'tpl',
      activeModuleIdentifiers: [], // inactive-mod 미포함
      activePluginIdentifiers: [],
      fetcher,
    });
    const calls = (fetcher as unknown as ReturnType<typeof vi.fn>).mock.calls.map((c) => String(c[0]));
    expect(calls.some((u) => u.includes('inactive-mod'))).toBe(false);
    expect(calls.some((u) => u.includes('/templates/tpl/editor-spec'))).toBe(true);
  });

  it('모든 스펙이 부재(404/null)면 spec=null 을 반환한다', async () => {
    const fetcher = makeFetcher({}); // 전부 404
    const { spec, sampleGlobalSources } = await loadEditorSpecBundle({
      templateIdentifier: 'none',
      activeModuleIdentifiers: [],
      activePluginIdentifiers: [],
      fetcher,
    });
    expect(spec).toBeNull();
    expect(sampleGlobalSources).toEqual([]);
  });
});

describe('방안 B — 출처별 bySource 보존', () => {
  it('같은 id 를 여러 출처가 정의해도 각 출처 키(template / {kind}:{id})로 분리 보존', async () => {
    const fetcher = makeFetcher({
      'shop-mod': { sampleData: { byDataSourceId: { settings: { data: { currency: 'KRW' } } } } },
      'gdpr-plg': { sampleData: { byDataSourceId: { settings: { data: { gdpr_enabled: true } } } } },
      tpl: { sampleData: { byDataSourceId: { settings: { data: { site_name: '코어' } } } } },
    });
    const { spec } = await loadEditorSpecBundle({
      templateIdentifier: 'tpl',
      activeModuleIdentifiers: ['shop-mod'],
      activePluginIdentifiers: ['gdpr-plg'],
      fetcher,
    });
    // 평탄 byDataSourceId 는 템플릿(마지막 소스)이 덮어씀 (하위호환 폴백)
    expect(spec!.sampleData!.byDataSourceId!.settings).toEqual({ data: { site_name: '코어' } });
    // 출처별 보존 — 각 키에 자기 shape 유지
    expect(spec!.sampleData!.bySource!['module:shop-mod'].settings).toEqual({ data: { currency: 'KRW' } });
    expect(spec!.sampleData!.bySource!['plugin:gdpr-plg'].settings).toEqual({ data: { gdpr_enabled: true } });
    expect(spec!.sampleData!.bySource!.template.settings).toEqual({ data: { site_name: '코어' } });
  });

  it('같은 출처(kind+identifier)의 여러 id 는 한 키에 누적된다', async () => {
    const fetcher = makeFetcher({
      'board-mod': { sampleData: { byDataSourceId: { posts: { data: [1] }, boards: { data: [2] } } } },
      tpl: { controls: {} },
    });
    const { spec } = await loadEditorSpecBundle({
      templateIdentifier: 'tpl',
      activeModuleIdentifiers: ['board-mod'],
      activePluginIdentifiers: [],
      fetcher,
    });
    expect(spec!.sampleData!.bySource!['module:board-mod']).toEqual({
      posts: { data: [1] },
      boards: { data: [2] },
    });
  });
});

describe('editorSpecLoader 조회 헬퍼', () => {
  const spec: EditorSpec = {
    controls: { color: { widget: 'color-picker' } },
    componentCapabilities: { Button: { label: '$t:button' } },
    actionRecipes: { goToPage: { handler: 'navigate' } },
    conditionRecipes: { isLoggedIn: {} },
    states: {
      groups: [
        { scope: { kind: 'route', match: '/mypage' }, items: [{ id: 'a' }] },
        { scope: { kind: 'route', match: '/other' }, items: [{ id: 'b' }] },
      ],
    },
  };

  it('getComponentCapability / getControl / getActionRecipe / getConditionRecipe', () => {
    expect(getComponentCapability(spec, 'Button')?.label).toBe('$t:button');
    expect(getComponentCapability(spec, 'Missing')).toBeNull();
    expect(getControl(spec, 'color')?.widget).toBe('color-picker');
    expect(getActionRecipe(spec, 'goToPage')).toEqual({ handler: 'navigate' });
    expect(getConditionRecipe(spec, 'isLoggedIn')).toEqual({});
    expect(getActionRecipe(null, 'x')).toBeNull();
  });

  it('getStateGroupsForScope 는 scope 매칭 그룹만 반환한다', () => {
    const groups = getStateGroupsForScope(spec, { kind: 'route', match: '/mypage' });
    expect(groups).toHaveLength(1);
    expect(groups[0].items![0].id).toBe('a');
    expect(getStateGroupsForScope(spec, { kind: 'route', match: '/nope' })).toHaveLength(0);
  });
});

// ── 페이지 설정 4블록 병합·__source·코어 시드 ──

describe('페이지 설정 4블록 병합 + __source 출처 부착', () => {
  it('initActionRecipes/computedRecipes/errorRecipes/loadingComponents 를 병합하고 __source 를 단다', async () => {
    const fetcher = makeFetcher({
      mod: {
        initActionRecipes: { modAction: { build: { handler: 'navigate' } } },
        computedRecipes: { modCalc: { expr: 'a ?? b' } },
        loadingComponents: { modSpin: { name: 'ModSpinner', role: 'spinner' } },
      },
      tpl: {
        initActionRecipes: { tplAction: { build: { handler: 'toast' } } },
        errorRecipes: { tplErr: { build: { handler: 'showErrorPage' } } },
      },
    });
    const { spec } = await loadEditorSpecBundle({
      templateIdentifier: 'tpl',
      activeModuleIdentifiers: ['mod'],
      activePluginIdentifiers: [],
      fetcher,
    });
    // 병합본에 양쪽 항목이 모두 있다.
    expect(Object.keys(spec!.initActionRecipes!)).toEqual(
      expect.arrayContaining(['modAction', 'tplAction']),
    );
    // __source 출처가 부착된다.
    expect((spec!.initActionRecipes!.modAction as Record<string, unknown>).__source).toEqual({
      kind: 'module',
      id: 'mod',
    });
    expect((spec!.initActionRecipes!.tplAction as Record<string, unknown>).__source).toEqual({
      kind: 'template',
      id: 'tpl',
    });
    expect((spec!.computedRecipes!.modCalc as Record<string, unknown>).__source).toEqual({
      kind: 'module',
      id: 'mod',
    });
    expect(getLoadingComponents(spec)).toEqual([
      expect.objectContaining({ name: 'ModSpinner', role: 'spinner' }),
    ]);
    expect(getInitActionRecipes(spec).modAction).toBeDefined();
    expect(getComputedRecipes(spec).modCalc).toBeDefined();
    expect(getErrorRecipes(spec).tplErr).toBeDefined();
  });

  it('coreSeed 는 base 로 병합되고 __source:core 가 붙으며 확장이 같은 key 를 override 한다', async () => {
    const fetcher = makeFetcher({
      tpl: {
        // 템플릿이 navigate 라벨을 덮는다(같은 key override).
        initActionRecipes: { navigate: { label: '$t:tpl.navigate', build: { handler: 'navigate' } } },
      },
    });
    const { spec } = await loadEditorSpecBundle({
      templateIdentifier: 'tpl',
      activeModuleIdentifiers: [],
      activePluginIdentifiers: [],
      fetcher,
      coreSeed: {
        initActionRecipes: {
          navigate: { label: '$t:core.navigate', build: { handler: 'navigate' } },
          toast: { label: '$t:core.toast', build: { handler: 'toast' } },
        },
      },
    });
    // 코어 시드 toast 는 잔존(코어 출처), navigate 는 템플릿이 덮음(템플릿 출처).
    const toast = spec!.initActionRecipes!.toast as Record<string, unknown>;
    expect(toast.__source).toEqual({ kind: 'core' });
    expect(toast.label).toBe('$t:core.toast');
    const navigate = spec!.initActionRecipes!.navigate as Record<string, unknown>;
    expect(navigate.label).toBe('$t:tpl.navigate');
    expect(navigate.__source).toEqual({ kind: 'template', id: 'tpl' });
  });
});
