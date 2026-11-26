/**
 * editorSpecLoader.bundleRequest.test.ts — 편집기 스펙을 묶음 한 요청으로 받는지
 *
 * 배경 (설계 확정 2026-11-08): 편집기를 열 때 활성 확장마다 editor-spec 을 따로 요청해
 * 개발 서버에서 27건(건당 2~5.7초)이 나갔다. 로더가 묶음 엔드포인트
 * (`/api/templates/{id}/editor-spec-bundle`)를 한 번만 부르고, 항목 단위 부분 실패를 견디는지 잠근다.
 *
 * @since engine-v1.95.0
 */

import { describe, it, expect, vi } from 'vitest';
import { loadEditorSpecBundle } from '../../spec/editorSpecLoader';
import type { EditorSpec } from '../../spec/specTypes';
import { EDITOR_SPEC_BUNDLE_URL, makeBundleFetcher } from './editorSpecBundleFetcher';

/** 개발 서버와 같은 규모 — 모듈 6 + 플러그인 20 + 템플릿 1 = 27 */
const MODULE_IDS = Array.from({ length: 6 }, (_, i) => `vendor-module${i}`);
const PLUGIN_IDS = Array.from({ length: 20 }, (_, i) => `vendor-plugin${i}`);

/** 확장마다 고유 컨트롤 하나를 둔 스펙 목록 */
function specsFor(ids: string[]): Record<string, EditorSpec> {
  const out: Record<string, EditorSpec> = {};
  for (const id of ids) out[id] = { controls: { [`${id}-ctl`]: { widget: 'text' } } } as EditorSpec;
  return out;
}

function callUrls(fetcher: typeof fetch): string[] {
  return (fetcher as unknown as ReturnType<typeof vi.fn>).mock.calls.map((c) => String(c[0]));
}

describe('loadEditorSpecBundle — 묶음 한 요청', () => {
  // @scenario case=many_extensions_one_request
  // @effects editor_requests_editor_spec_once
  it('활성 확장 26개 + 템플릿이어도 요청은 묶음 엔드포인트 한 번이다', async () => {
    const fetcher = makeBundleFetcher({
      ...specsFor(MODULE_IDS),
      ...specsFor(PLUGIN_IDS),
      tpl: { controls: { 'tpl-ctl': { widget: 'text' } } } as EditorSpec,
    });

    const { spec } = await loadEditorSpecBundle({
      templateIdentifier: 'tpl',
      activeModuleIdentifiers: MODULE_IDS,
      activePluginIdentifiers: PLUGIN_IDS,
      apiBaseUrl: '/api',
      fetcher,
    });

    const urls = callUrls(fetcher);
    expect(urls).toHaveLength(1);
    const m = EDITOR_SPEC_BUNDLE_URL.exec(urls[0]);
    expect(m?.[1]).toBe('tpl');
    const params = new URLSearchParams(m?.[2] ?? '');
    // 병합 순서(모듈 → 플러그인)가 요청 순서로 그대로 전달된다
    expect(params.getAll('modules[]')).toEqual(MODULE_IDS);
    expect(params.getAll('plugins[]')).toEqual(PLUGIN_IDS);
    // 27개 출처 모두 병합됐다
    expect(Object.keys(spec?.controls ?? {})).toHaveLength(27);
  });

  // @scenario case=no_extensions_one_request
  // @effects editor_requests_editor_spec_once
  it('활성 확장이 없으면 쿼리 없이 템플릿만 묶음으로 받는다', async () => {
    const fetcher = makeBundleFetcher({ tpl: { controls: { a: { widget: 'text' } } } as EditorSpec });
    const { spec } = await loadEditorSpecBundle({
      templateIdentifier: 'tpl',
      activeModuleIdentifiers: [],
      activePluginIdentifiers: [],
      apiBaseUrl: '/api',
      fetcher,
    });
    expect(callUrls(fetcher)).toEqual(['/api/templates/tpl/editor-spec-bundle']);
    expect(spec?.controls?.a).toBeDefined();
  });

  // @scenario case=item_exception
  // @effects editor_spec_bundle_partial_failure_keeps_other_specs
  it('한 확장이 500·404 로 표시돼도 나머지 확장과 템플릿은 병합하고 출처 순서를 지킨다', async () => {
    const fetcher = makeBundleFetcher(
      {
        'mod-ok': { sampleGlobal: { a: 1 }, controls: { ok: { widget: 'text' } } } as EditorSpec,
        'mod-broken': { controls: { broken: { widget: 'text' } } } as EditorSpec,
        // plg-inactive 는 specs 에 없음 → 404
        tpl: { sampleGlobal: { t: 1 }, controls: { tplc: { widget: 'text' } } } as EditorSpec,
      },
      { 'mod-broken': 500 },
    );

    const { spec, sampleGlobalSources } = await loadEditorSpecBundle({
      templateIdentifier: 'tpl',
      activeModuleIdentifiers: ['mod-ok', 'mod-broken'],
      activePluginIdentifiers: ['plg-inactive'],
      fetcher,
    });

    expect(callUrls(fetcher)).toHaveLength(1);
    expect(spec?.controls?.ok).toBeDefined();
    expect(spec?.controls?.tplc).toBeDefined();
    expect(spec?.controls?.broken).toBeUndefined();
    expect(sampleGlobalSources.map((s) => `${s.kind}:${s.id}`)).toEqual(['module:mod-ok', 'template:tpl']);
  });

  // @scenario case=request_failed
  // @effects editor_spec_bundle_request_failure_degrades_without_retry_storm
  it('묶음 요청이 계속 5xx 면 한 번만 다시 묻고 코어 시드만 남긴다(개별 요청으로 쏟아내지 않음)', async () => {
    const fetcher = vi.fn(async () => ({ ok: false, status: 500, json: async () => ({}) }) as unknown as Response);
    const coreSeed = { actionRecipes: { core: { handler: 'navigate' } } } as unknown as EditorSpec;

    const { spec, sampleGlobalSources } = await loadEditorSpecBundle({
      templateIdentifier: 'tpl',
      activeModuleIdentifiers: ['mod-a'],
      activePluginIdentifiers: ['plg-a'],
      fetcher: fetcher as unknown as typeof fetch,
      coreSeed,
      bundleRetryDelayMs: 0,
    });

    expect(fetcher).toHaveBeenCalledTimes(2);
    expect(callUrls(fetcher as unknown as typeof fetch).every((u) => EDITOR_SPEC_BUNDLE_URL.test(u))).toBe(true);
    expect(spec?.actionRecipes?.core).toBeDefined();
    expect(sampleGlobalSources).toEqual([]);
  });

  // @scenario case=transient_failure
  // @effects editor_spec_bundle_retries_once_on_transient_failure
  it('5xx 나 응답 없음이 한 번이면 다시 물어 스펙을 받고, 4xx 는 다시 묻지 않는다', async () => {
    const ok = makeBundleFetcher({ tpl: { controls: { t: { widget: 'text' } } } as EditorSpec });
    const flaky = vi.fn()
      .mockImplementationOnce(async () => {
        throw new TypeError('Failed to fetch');
      })
      .mockImplementation(ok as unknown as (...args: unknown[]) => Promise<Response>);
    const first = await loadEditorSpecBundle({
      templateIdentifier: 'tpl',
      activeModuleIdentifiers: [],
      activePluginIdentifiers: [],
      fetcher: flaky as unknown as typeof fetch,
      bundleRetryDelayMs: 0,
    });
    expect(flaky).toHaveBeenCalledTimes(2);
    expect(first.spec?.controls?.t).toBeDefined();

    const rejected = vi.fn(async () => ({ ok: false, status: 429, json: async () => ({}) }) as unknown as Response);
    await loadEditorSpecBundle({
      templateIdentifier: 'tpl',
      activeModuleIdentifiers: [],
      activePluginIdentifiers: [],
      fetcher: rejected as unknown as typeof fetch,
      bundleRetryDelayMs: 0,
    });
    expect(rejected).toHaveBeenCalledTimes(1);
  });

  // @scenario case=malformed_identifier
  // @effects editor_spec_bundle_skips_malformed_identifier
  it('서버가 거절할 형식의 식별자는 묶음에서 빼, 나머지 확장과 템플릿 스펙을 지킨다', async () => {
    const fetcher = makeBundleFetcher({
      'mod-ok': { controls: { ok: { widget: 'text' } } } as EditorSpec,
      tpl: { controls: { tplc: { widget: 'text' } } } as EditorSpec,
    });

    const { spec } = await loadEditorSpecBundle({
      templateIdentifier: 'tpl',
      activeModuleIdentifiers: ['mod-ok', '../etc', 'a/b'],
      activePluginIdentifiers: ['x..y'],
      fetcher,
    });

    const params = new URLSearchParams(EDITOR_SPEC_BUNDLE_URL.exec(callUrls(fetcher)[0])?.[2] ?? '');
    expect(params.getAll('modules[]')).toEqual(['mod-ok']);
    expect(params.getAll('plugins[]')).toEqual([]);
    expect(spec?.controls?.ok).toBeDefined();
    expect(spec?.controls?.tplc).toBeDefined();
  });
});
