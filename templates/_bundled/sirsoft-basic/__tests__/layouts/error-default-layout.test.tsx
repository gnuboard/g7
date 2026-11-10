/**
 * @file error-default-layout.test.tsx
 * @description 코드별 오류 레이아웃이 없는 코드(400 등)가 공통 오류 화면(`errors/default`)으로 그려지고 받은 코드가 보이는지 (sirsoft-basic 1.4.0, #869)
 *
 * 엔진은 오류 코드에 맞는 레이아웃이 없으면 `error_config.layouts.default` → 엔진 내장 오류 화면 순서로 대신 그린다(engine-v1.89.0).
 * 이 템플릿에 `default` 가 없으면 400·422·429·502·504 는 내장 화면이 머리글과 바닥글까지 `#app` 전체를 덮었다.
 * 이 파일은 실제 `template.json` 과 실제 오류 레이아웃 JSON 을 엔진 `ErrorPageHandler` 에 물려 어느 레이아웃이 그려지는지 보고,
 * 그 레이아웃을 받은 코드(`errorCode`)로 렌더해 코드와 일반 문구, 새로고침과 홈 단추가 보이는지 확인한다.
 *
 * @vitest-environment jsdom
 * @scenario-manifest tests/scenarios/error-default-layout.yaml
 * @since 1.4.0
 */

import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect, vi, afterEach } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { ErrorPageHandler, type RenderOptions } from '@core/template-engine/ErrorPageHandler';

import templateJson from '../../template.json';
import koError from '../../lang/partial/ko/error.json';
import enError from '../../lang/partial/en/error.json';

/** 오류 레이아웃 JSON — 엔진 LayoutLoader 대신 디스크의 짝 `.json` 을 이름으로 찾는다 */
const errorLayouts = import.meta.glob('../../layouts/errors/*.json', { eager: true, import: 'default' }) as Record<string, unknown>;

/** ja 언어팩의 같은 조각 */
const jaError = JSON.parse(
  fs.readFileSync(path.resolve(__dirname, '../../../../../lang-packs/_bundled/g7-template-sirsoft-basic-ja/frontend/partial/error.json'), 'utf8'),
) as Record<string, any>;

/** 공통 오류 화면이 맡아야 하는 코드(코드별 레이아웃이 없는 대표 코드) */
const UNMAPPED_CODES = [400, 422, 429, 502, 504];

const originalFetch = globalThis.fetch;

afterEach(() => {
  globalThis.fetch = originalFetch;
});

/**
 * 실제 template.json 과 오류 레이아웃으로 엔진 오류 화면 처리기를 돌립니다.
 *
 * @param code 오류 코드
 * @returns 성공 여부, 적재한 레이아웃 이름, 렌더 호출, 내장 화면 호출
 */
async function runHandler(code: number) {
  const loaded: string[] = [];
  const rendered: RenderOptions[] = [];
  const builtin = vi.fn();
  globalThis.fetch = vi.fn(async () => ({
    ok: true,
    statusText: 'OK',
    json: async () => ({ success: true, data: templateJson }),
  })) as unknown as typeof fetch;

  const handler = new ErrorPageHandler({
    templateId: 'sirsoft-basic',
    layoutLoader: {
      loadLayout: async (_templateId: string, name: string) => {
        loaded.push(name);
        return (errorLayouts[`../../layouts/${name}.json`] as any) ?? null;
      },
    } as any,
    locale: 'ko',
    debug: false,
    renderFunction: async (options) => {
      rendered.push(options);
    },
    dataSourceManager: {
      filterByCondition: (sources: unknown[]) => sources,
      fetchDataSources: async () => ({}),
      setGlobalHeaders: () => undefined,
    } as any,
    renderBuiltinFallback: builtin,
  });

  const ok = await handler.renderError(code);
  return { ok, loaded, rendered, builtin };
}

/**
 * 이 화면이 쓰는 기본 컴포넌트를 시험용으로 등록합니다.
 *
 * @returns 레지스트리
 */
function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const basic = (tag: string, name: string) => ({
    component: (props: any) => React.createElement(tag, { id: props.id, className: props.className, type: props.type, href: props.href }, props.children ?? props.text),
    metadata: { name, type: 'basic' },
  });
  (registry as any).registry = {
    Div: basic('div', 'Div'),
    H1: basic('h1', 'H1'),
    P: basic('p', 'P'),
    A: basic('a', 'A'),
    Button: basic('button', 'Button'),
    Span: basic('span', 'Span'),
    Icon: { component: () => null, metadata: { name: 'Icon', type: 'basic' } },
    Fragment: { component: ({ children }: { children?: React.ReactNode }) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

/**
 * 레이아웃 JSON 안의 모든 노드를 모읍니다.
 *
 * @param tree 레이아웃 JSON
 * @returns 노드 목록
 */
function nodes(tree: unknown): any[] {
  const out: any[] = [];
  const walk = (n: unknown): void => {
    if (Array.isArray(n)) n.forEach(walk);
    else if (n && typeof n === 'object') {
      out.push(n);
      Object.values(n).forEach(walk);
    }
  };
  walk(tree);
  return out;
}

describe('sirsoft-basic 공통 오류 화면 (errors/default)', () => {
  /**
   * @scenario surface=error_config
   * @effects error_default_registered
   */
  it('template.json 의 error_config 가 공통 오류 레이아웃을 default 로 등록한다', () => {
    expect((templateJson as any).error_config.layouts.default).toBe('errors/default');
    expect(errorLayouts['../../layouts/errors/default.json']).toBeDefined();
  });

  /**
   * @scenario surface=engine_fallback
   * @effects unmapped_code_renders_default_layout
   */
  it.each(UNMAPPED_CODES)('코드 %i 는 엔진 내장 화면이 아니라 errors/default 로 그리고 코드를 errorCode 로 넘긴다', async (code) => {
    const { ok, loaded, rendered, builtin } = await runHandler(code);

    expect(builtin).not.toHaveBeenCalled();
    expect(ok).toBe(true);
    expect(loaded).toEqual(['errors/default']);
    expect(rendered).toHaveLength(1);
    expect(rendered[0].dataContext?.errorCode).toBe(code);
  });

  /**
   * @scenario surface=engine_fallback
   * @effects mapped_code_keeps_own_layout
   */
  it('코드 레이아웃이 있는 404 는 그대로 errors/404 로 그린다', async () => {
    const { loaded, builtin } = await runHandler(404);

    expect(builtin).not.toHaveBeenCalled();
    expect(loaded).toEqual(['errors/404']);
  });

  /**
   * @scenario surface=render
   * @effects default_layout_shows_received_code
   */
  it('400 을 받은 공통 오류 화면이 코드와 일반 문구, 새로고침과 홈 단추를 보인다', async () => {
    const { rendered } = await runHandler(400);
    const layout = rendered[0]?.layoutJson as any;
    expect(layout).toBeDefined();

    const t = createLayoutTest(layout, {
      componentRegistry: setupRegistry(),
      translations: { error: koError },
      locale: 'ko',
      initialData: { errorCode: rendered[0].dataContext?.errorCode },
    });
    await t.render();

    expect(document.getElementById('error_code')?.textContent).toBe('400');
    const text = document.body.textContent ?? '';
    expect(text).toContain(koError.default.title);
    expect(text).toContain(koError.default.description);
    expect(document.getElementById('refresh_button')?.textContent).toBe(koError.default.refresh);
    expect(Array.from(document.querySelectorAll('a')).map((a) => a.textContent)).toContain(koError.default.back_to_home);
    t.cleanup();
  });

  /**
   * @scenario surface=layout_contract
   * @effects default_layout_has_no_fixed_code
   */
  it('레이아웃은 코드를 박지 않고 새로고침은 refresh, 홈은 navigate 로 건다', () => {
    const layout = errorLayouts['../../layouts/errors/default.json'] as any;
    const text = JSON.stringify(layout);

    expect(text).not.toMatch(/"text":\s*"\d{3}"/);
    expect(layout.meta.error_code).toBeUndefined();
    expect(text).toContain('{{errorCode}}');
    const all = nodes(layout);
    expect(all.find((n) => n.id === 'refresh_button')?.actions?.[0]?.handler).toBe('refresh');
    expect(all.find((n) => n.name === 'A')?.actions?.[0]?.handler).toBe('navigate');
  });

  /**
   * @scenario surface=lang_keys
   * @effects default_layout_lang_keys
   */
  it('공통 오류 화면 문구가 ko·en·ja 에 같은 키로 있다', () => {
    for (const lang of [koError, enError, jaError] as Array<Record<string, any>>) {
      expect(Object.keys(lang.default).sort()).toEqual(['back_to_home', 'description', 'refresh', 'title']);
      for (const value of Object.values(lang.default)) {
        expect(typeof value === 'string' && value.length > 0).toBe(true);
      }
    }
    expect(jaError.default.title).not.toBe(enError.default.title);
    expect(jaError.default.title).not.toBe(koError.default.title);
  });
});
