/**
 * extensionPaletteComponents.test.tsx — 확장이 팔레트에 등재한 자기 컴포넌트가 팔레트에 나오는가
 *
 * Hello 모듈은 `gnuboard7-hello_module.MemoCard` 를 자기 editor-spec
 * 팔레트에 등재했지만 「요소 추가」 창 어디에도 나오지 않았다. 팔레트 목록의 원천이 편집 대상 템플릿의
 * components.json 하나였고(확장 컴포넌트 없음), 놓을 자리(nesting)는 템플릿이 자기 이름으로만 써서
 * 둘 다 확장 이름을 걸러 냈다. 확장 components.json 을 읽어 목록에 더하고, 템플릿이 일반 블록(Div)을
 * 받는 자리에 확장 컴포넌트를 함께 받게 한다.
 *
 * @since engine-v1.76.0
 */

import React from 'react';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import {
  collectExtensionPaletteComponents,
  withExtensionComponentPlacement,
  toExtensionManifestEntries,
  mergeExtensionManifestEntries,
  loadExtensionPaletteManifestEntries,
} from '../../spec/extensionPaletteComponents';
import { loadEditorSpecBundle } from '../../spec/editorSpecLoader';
import { ComponentPalette, type ComponentManifest } from '../../components/ComponentPalette';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';
import type { EditorSpec, NestingSpec } from '../../spec/specTypes';

const MEMO = 'gnuboard7-hello_module.MemoCard';

const moduleSpec: EditorSpec = {
  componentPalette: {
    groups: [{ label: '$t:gnuboard7-hello_module.editor.palette.group', kind: 'content', components: [MEMO] }],
    entries: {
      [MEMO]: {
        label: '$t:gnuboard7-hello_module.editor.palette.memo_card.label',
        requiresDefaultNode: true,
        defaultNode: { type: 'composite', name: MEMO, props: { title: '제목', body: '본문' } },
      },
    },
  },
};

const templateNesting: NestingSpec = {
  draggable: ['Div', 'Ul', 'Li', 'Span'],
  containers: {
    Div: { accepts: ['Div', 'Ul', 'Span'] },
    Ul: { accepts: ['Li'] },
    Li: { accepts: ['Div', 'Span'] },
    Span: { accepts: ['Span'] },
  },
};

const extensionManifest = {
  identifier: 'gnuboard7-hello_module',
  components: {
    basic: [],
    composite: [
      { name: 'MemoCard', type: 'composite', props: { title: { type: 'string' }, body: { type: 'string' } } },
      { name: 'NotInPalette', type: 'composite' },
    ],
    layout: [],
  },
};

const templateManifest: ComponentManifest = {
  templateId: 'tpl',
  components: {
    basic: [
      { name: 'Div', type: 'basic' },
      { name: 'Ul', type: 'basic' },
      { name: 'Li', type: 'basic' },
      { name: 'Span', type: 'basic' },
    ],
    composite: [],
  },
};

function withTranslation(node: React.ReactElement): React.ReactElement {
  return (
    <TranslationProvider translationEngine={new TranslationEngine()} translationContext={{ templateId: 'tpl', locale: 'ko' }}>
      {node}
    </TranslationProvider>
  );
}

function jsonResponse(body: unknown, ok = true): Response {
  return { ok, status: ok ? 200 : 404, json: async () => body } as unknown as Response;
}

describe('확장 팔레트 컴포넌트 — 등재 이름 모으기', () => {
  /**
   * @scenario case=collect
   * @effects extension_palette_names_collected_per_extension
   */
  it('entries 키와 묶음 목록에서 확장 이름만 확장 식별자별로 모은다', () => {
    const spec: EditorSpec = {
      componentPalette: {
        groups: [
          { label: 'tpl', components: ['Div', 'Button'] },
          { label: 'ext', components: ['acme-shop.ProductCard'] },
        ],
        entries: { [MEMO]: { label: 'x' }, Div: { label: 'y' } },
      },
    };

    const map = collectExtensionPaletteComponents(spec);

    expect(Array.from(map.keys()).sort()).toEqual(['acme-shop', 'gnuboard7-hello_module']);
    expect(Array.from(map.get('gnuboard7-hello_module') ?? [])).toEqual([MEMO]);
    expect(Array.from(map.get('acme-shop') ?? [])).toEqual(['acme-shop.ProductCard']);
    expect(collectExtensionPaletteComponents(null).size).toBe(0);
  });
});

describe('확장 팔레트 컴포넌트 — 놓을 자리', () => {
  /**
   * @scenario case=root_placement
   * @effects extension_components_placed_where_template_accepts_block
   */
  it('템플릿이 Div 를 끌어 놓을 수 있게 했으면 확장 컴포넌트도 끌어 놓을 수 있다', () => {
    const placed = withExtensionComponentPlacement(templateNesting, [MEMO]);

    expect(placed?.draggable).toContain(MEMO);
    // 입력은 바꾸지 않는다(병합 스펙의 다른 소비자가 같은 객체를 볼 수 있다)
    expect(templateNesting.draggable).not.toContain(MEMO);
  });

  /**
   * @scenario case=block_container
   * @effects extension_components_placed_where_template_accepts_block
   */
  it('Div 를 자식으로 받는 컨테이너는 확장 컴포넌트도 받는다', () => {
    const placed = withExtensionComponentPlacement(templateNesting, [MEMO]);

    expect(placed?.containers?.Div.accepts).toContain(MEMO);
    expect(placed?.containers?.Li.accepts).toContain(MEMO);
    expect(templateNesting.containers?.Div.accepts).not.toContain(MEMO);
  });

  /**
   * @scenario case=other_container
   * @effects extension_components_placed_where_template_accepts_block
   */
  it('Div 를 받지 않는 컨테이너(목록·인라인)는 확장 컴포넌트를 받지 않는다', () => {
    const placed = withExtensionComponentPlacement(templateNesting, [MEMO]);

    expect(placed?.containers?.Ul.accepts).toEqual(['Li']);
    expect(placed?.containers?.Span.accepts).toEqual(['Span']);
  });

  /**
   * @scenario case=template_declared
   * @effects template_declared_extension_placement_kept
   */
  it('템플릿이 그 이름을 컨테이너에 직접 적었으면 그 선언을 따른다', () => {
    const declared: NestingSpec = {
      ...templateNesting,
      containers: { ...templateNesting.containers, Li: { accepts: ['Div', 'Span', MEMO] } },
    };

    const placed = withExtensionComponentPlacement(declared, [MEMO]);

    expect(placed?.containers?.Li.accepts).toEqual(['Div', 'Span', MEMO]);
    expect(placed?.containers?.Div.accepts).not.toContain(MEMO);
    // 끌어 놓기 목록에는 그때도 더한다 — 놓기 판정(canDrop)이 끌어 놓기 목록을 먼저 보므로,
    // 빠지면 템플릿이 적어 둔 Li 에도 놓을 수 없다.
    expect(placed?.draggable).toContain(MEMO);
  });

  /**
   * @scenario case=no_nesting
   * @effects template_declared_extension_placement_kept
   */
  it('nesting 이 없거나 확장 이름이 없으면 입력을 그대로 돌려준다', () => {
    expect(withExtensionComponentPlacement(undefined, [MEMO])).toBeUndefined();
    expect(withExtensionComponentPlacement(templateNesting, ['Div'])).toBe(templateNesting);
    expect(withExtensionComponentPlacement(templateNesting, [])).toBe(templateNesting);
  });
});

describe('확장 팔레트 컴포넌트 — 목록 항목', () => {
  /**
   * @scenario case=manifest_entry
   * @effects extension_palette_components_listed_from_extension_manifest
   */
  it('확장 components.json 항목을 등록 이름으로 바꾸고 팔레트에 등재한 것만 고른다', () => {
    const entries = toExtensionManifestEntries('gnuboard7-hello_module', extensionManifest, new Set([MEMO]), templateManifest);

    expect(entries.map((e) => e.name)).toEqual([MEMO]);
    expect(entries[0].type).toBe('composite');
    expect(Object.keys(entries[0].props ?? {})).toEqual(['title', 'body']);

    const merged = mergeExtensionManifestEntries(templateManifest, entries);
    expect(merged?.components?.composite?.map((e) => e.name)).toEqual([MEMO]);
    expect(templateManifest.components?.composite).toEqual([]);
    expect(mergeExtensionManifestEntries(templateManifest, [])).toBe(templateManifest);
  });

  /**
   * @scenario case=template_override
   * @effects template_override_metadata_used
   */
  it('템플릿이 그 컴포넌트를 대체했으면 대체본의 설명과 속성을 쓴다', () => {
    const overriding = {
      ...templateManifest,
      overrides: [
        { target: 'gnuboard7-hello_module', name: 'MemoCard', type: 'composite', description: '템플릿 서식', props: { title: { type: 'string' } } },
      ],
    } as unknown as ComponentManifest;

    const [entry] = toExtensionManifestEntries('gnuboard7-hello_module', extensionManifest, new Set([MEMO]), overriding);

    expect(entry.description).toBe('템플릿 서식');
    expect(Object.keys(entry.props ?? {})).toEqual(['title']);
  });
});

describe('확장 팔레트 컴포넌트 — 받아 오기', () => {
  /**
   * @scenario case=fetch_kind
   * @effects extension_palette_components_listed_from_extension_manifest
   */
  it('팔레트에 등재한 확장만, 모듈·플러그인을 가려 받는다', async () => {
    const fetcher = vi.fn(async () => jsonResponse(extensionManifest));
    const spec: EditorSpec = {
      componentPalette: { entries: { [MEMO]: { label: 'x' }, 'acme-pay.Badge': { label: 'y' }, 'gone-ext.Card': { label: 'z' } } },
    };

    await loadExtensionPaletteManifestEntries(spec, templateManifest, {
      moduleIdentifiers: ['gnuboard7-hello_module', 'sirsoft-board'],
      pluginIdentifiers: ['acme-pay'],
      urlFor: (kind, id) => `/api/${kind}/${id}/components`,
      fetcher: fetcher as unknown as typeof fetch,
    });

    const urls = fetcher.mock.calls.map((call) => String((call as unknown[])[0])).sort();
    // 등재하지 않은 확장(sirsoft-board)·활성 목록에 없는 확장(gone-ext)은 요청하지 않는다
    expect(urls).toEqual(['/api/modules/gnuboard7-hello_module/components', '/api/plugins/acme-pay/components']);
  });

  /**
   * @scenario case=fetch_failure
   * @effects extension_palette_components_listed_from_extension_manifest
   */
  it('받지 못하면 그 확장 항목만 빠지고 편집기는 그대로다', async () => {
    const failing = vi.fn(async () => jsonResponse({}, false));
    const throwing = vi.fn(async () => {
      throw new Error('network');
    });
    const options = { moduleIdentifiers: ['gnuboard7-hello_module'], pluginIdentifiers: [], urlFor: () => '/x' };

    await expect(
      loadExtensionPaletteManifestEntries(moduleSpec, templateManifest, { ...options, fetcher: failing as unknown as typeof fetch }),
    ).resolves.toEqual([]);
    await expect(
      loadExtensionPaletteManifestEntries(moduleSpec, templateManifest, { ...options, fetcher: throwing as unknown as typeof fetch }),
    ).resolves.toEqual([]);
  });
});

describe('[case:wysiwyg-5] 확장 팔레트 컴포넌트 — 스펙 로더와 팔레트', () => {
  /**
   * @scenario case=loader_merge
   * @effects extension_components_placed_where_template_accepts_block
   */
  it('스펙 로더가 병합 뒤 확장 컴포넌트에 Div 자리를 준다', async () => {
    const fetcher = vi.fn(async (url: string | URL | Request) => {
      const u = String(url);
      if (u.includes('/templates/tpl/')) return jsonResponse({ success: true, data: { identifier: 'tpl', spec: { nesting: templateNesting } } });
      if (u.includes('/modules/gnuboard7-hello_module/')) return jsonResponse({ success: true, data: { identifier: 'gnuboard7-hello_module', spec: moduleSpec } });
      return jsonResponse({}, false);
    });

    const { spec } = await loadEditorSpecBundle({
      templateIdentifier: 'tpl',
      activeModuleIdentifiers: ['gnuboard7-hello_module'],
      activePluginIdentifiers: [],
      fetcher: fetcher as unknown as typeof fetch,
    });

    expect(spec?.nesting?.draggable).toContain(MEMO);
    expect(spec?.nesting?.containers?.Div.accepts).toContain(MEMO);
    expect(spec?.nesting?.containers?.Ul.accepts).not.toContain(MEMO);
  });

  /**
   * @scenario case=palette_render
   * @effects extension_palette_components_listed_from_extension_manifest
   * @effects extension_components_placed_where_template_accepts_block
   */
  it('팔레트가 확장 묶음에 MemoCard 를 보여 주고 누르면 등재한 골격을 넣는다', () => {
    const entries = toExtensionManifestEntries('gnuboard7-hello_module', extensionManifest, new Set([MEMO]), templateManifest);
    const manifest = mergeExtensionManifestEntries(templateManifest, entries);
    const nesting = withExtensionComponentPlacement(templateNesting, [MEMO]) ?? null;
    const onInsert = vi.fn();

    const { unmount } = render(
      withTranslation(
        <ComponentPalette
          manifest={manifest}
          nesting={nesting}
          componentPalette={moduleSpec.componentPalette}
          targetParentPath={[0]}
          targetContainerName="Div"
          insertionIndex={1}
          onInsert={onInsert}
          onClose={vi.fn()}
        />,
      ),
    );

    expect(screen.getByTestId('g7le-palette-category-content')).toBeTruthy();
    fireEvent.click(screen.getByTestId(`g7le-palette-item-${MEMO}`));
    expect(onInsert).toHaveBeenCalledTimes(1);
    const [node, parentPath, index] = onInsert.mock.calls[0];
    expect(node).toEqual({ type: 'composite', name: MEMO, props: { title: '제목', body: '본문' } });
    expect(parentPath).toEqual([0]);
    expect(index).toBe(1);
    unmount();

    // 목록(Ul) 안에는 놓을 수 없다 — Div 를 받지 않는 자리
    render(
      withTranslation(
        <ComponentPalette
          manifest={manifest}
          nesting={nesting}
          componentPalette={moduleSpec.componentPalette}
          targetParentPath={[1]}
          targetContainerName="Ul"
          insertionIndex={0}
          onInsert={vi.fn()}
          onClose={vi.fn()}
        />,
      ),
    );
    expect(screen.queryByTestId(`g7le-palette-item-${MEMO}`)).toBeNull();
  });
});

describe('확장 팔레트 컴포넌트 — 번들 실물(Hello 모듈 + 관리자 기본 템플릿)', () => {
  const ROOT = resolve(__dirname, '../../../../../../..');
  const readJson = (rel: string) => JSON.parse(readFileSync(resolve(ROOT, rel), 'utf8'));

  /**
   * @scenario case=bundled_hello
   * @effects bundled_hello_memocard_reaches_palette
   */
  it('Hello 모듈이 등재한 MemoCard 가 관리자 기본 템플릿 편집기의 루트와 Div 안 팔레트에 나온다', () => {
    const helloSpec = readJson('modules/_bundled/gnuboard7-hello_module/editor-spec.json') as EditorSpec;
    const helloManifest = readJson('modules/_bundled/gnuboard7-hello_module/components.json');
    const adminNesting = readJson('templates/_bundled/sirsoft-admin_basic/editor-spec/nesting.json') as NestingSpec;
    const adminManifest = readJson('templates/_bundled/sirsoft-admin_basic/components.json') as ComponentManifest;

    const names = collectExtensionPaletteComponents(helloSpec).get('gnuboard7-hello_module') ?? new Set<string>();
    expect(Array.from(names)).toEqual([MEMO]);

    const manifest = mergeExtensionManifestEntries(
      adminManifest,
      toExtensionManifestEntries('gnuboard7-hello_module', helloManifest, names, adminManifest),
    );
    const nesting = withExtensionComponentPlacement(adminNesting, names) ?? null;

    for (const target of [null, 'Div']) {
      const { unmount } = render(
        withTranslation(
          <ComponentPalette
            manifest={manifest}
            nesting={nesting}
            componentPalette={helloSpec.componentPalette}
            targetParentPath={target ? [0] : null}
            targetContainerName={target}
            insertionIndex={0}
            onInsert={vi.fn()}
            onClose={vi.fn()}
          />,
        ),
      );
      expect(screen.getByTestId(`g7le-palette-item-${MEMO}`)).toBeTruthy();
      unmount();
    }
  });
});
