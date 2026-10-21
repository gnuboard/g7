/**
 * @file unplacedBundledNotice.test.tsx
 * @description 미배치 공식 번들 안내 블록 — 모듈·플러그인·템플릿·언어팩 4화면 (공개 #146)
 *
 * 실제 굽기 산출 JSON 에서 안내 블록과 데이터 소스를 꺼내 띄운다.
 * - 화면별로 자기 유형(`items.{modules|plugins|templates|language_packs}`)만 읽고, 항목이 있을 때만 그린다
 * - 제목(버전·개수)·항목·복사용 명령·role="status"·닫기 버튼 aria-label 을 그린다
 * - [닫기] 는 그 유형 dismiss API 를 POST 하고 성공 시 안내 소스를 다시 받으며, 실패 시 오류 토스트를 띄운다
 * - 템플릿 화면은 user/admin 탭 바깥(탭 위)에 둬 두 탭이 같은 안내를 본다
 * - 다국어 키가 ko·en 에 모두 있다
 *
 * @scenario-manifest tests/scenarios/core-update-bundled-sources.yaml
 */

import React from 'react';
import fs from 'fs';
import path from 'path';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen, fireEvent, waitFor } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

type Json = Record<string, any>;

const read = (relative: string): Json => JSON.parse(fs.readFileSync(path.resolve(__dirname, relative), 'utf-8'));

/** 화면별 레이아웃(안내 블록 위치)과 데이터 소스 위치, 유형 */
const SCREENS = [
  { name: '모듈', type: 'module', plural: 'modules', block: '../../layouts/admin_module_list.json', ds: '../../layouts/admin_module_list.json' },
  { name: '플러그인', type: 'plugin', plural: 'plugins', block: '../../layouts/admin_plugin_list.json', ds: '../../layouts/admin_plugin_list.json' },
  { name: '템플릿', type: 'template', plural: 'templates', block: '../../layouts/admin_template_list.json', ds: '../../layouts/admin_template_list.json' },
  { name: '언어팩', type: 'language_pack', plural: 'language_packs', block: '../../layouts/partials/admin_settings/_tab_language_packs.json', ds: '../../layouts/admin_settings.json' },
] as const;

const ko = read('../../lang/partial/ko/extensions.json');
const en = read('../../lang/partial/en/extensions.json');

/** 트리 전체를 돌며 조건에 맞는 노드를 모두 모은다. */
function collect(node: unknown, match: (n: Json) => boolean, found: Json[] = []): Json[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, match, found));
    return found;
  }
  if (node && typeof node === 'object') {
    const obj = node as Json;
    if (match(obj)) found.push(obj);
    Object.values(obj).forEach((value) => {
      if (value && typeof value === 'object') collect(value, match, found);
    });
  }
  return found;
}

/** id 로 노드 하나를 찾고, 그 노드에 이르는 조상 경로도 돌려준다. */
function findWithAncestors(node: unknown, id: string, trail: Json[] = []): { node: Json; ancestors: Json[] } | null {
  if (Array.isArray(node)) {
    for (const child of node) {
      const hit = findWithAncestors(child, id, trail);
      if (hit) return hit;
    }
    return null;
  }
  if (node && typeof node === 'object') {
    const obj = node as Json;
    if (obj.id === id) return { node: obj, ancestors: trail };
    for (const value of Object.values(obj)) {
      if (value && typeof value === 'object') {
        const hit = findWithAncestors(value, id, obj.type ? [...trail, obj] : trail);
        if (hit) return hit;
      }
    }
  }
  return null;
}

const TestDiv: React.FC<any> = ({ className, role, children, id }) => <div className={className} role={role} data-testid={id}>{children}</div>;
const TestText: React.FC<any> = ({ className, children, text }) => <span className={className}>{children || text}</span>;
const TestCode: React.FC<any> = ({ className, children, text, id }) => <code className={className} data-testid={id}>{children || text}</code>;
const TestIcon: React.FC<any> = ({ name }) => <i data-icon={name} />;
const TestButton: React.FC<any> = ({ id, onClick, children, 'aria-label': ariaLabel }) => (
  <button type="button" data-testid={id} aria-label={ariaLabel} onClick={onClick}>{children}</button>
);

function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const basic = (name: string, component: React.FC<any>) => ({ component, metadata: { name, type: 'basic' } });
  (registry as any).registry = {
    Div: basic('Div', TestDiv),
    P: basic('P', TestText),
    Span: basic('Span', TestText),
    Code: basic('Code', TestCode),
    Icon: basic('Icon', TestIcon),
    Button: basic('Button', TestButton),
    Fragment: { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

/** 화면의 안내 블록과 데이터 소스만 담은 시험 레이아웃을 만든다(데이터 소스의 탭 조건은 뗀다). */
function buildLayout(screen: (typeof SCREENS)[number]): Json {
  const wrapper = findWithAncestors(read(screen.block), 'unplaced_bundled_notice_wrapper')!.node;
  const ds = collect(read(screen.ds).data_sources ?? [], (n) => n.id === 'unplaced_bundled_notice')[0];
  const { if: _cond, ...dsWithoutIf } = ds;

  return {
    version: '1.0.0',
    layout_name: `unplaced_notice_${screen.type}`,
    data_sources: [dsWithoutIf],
    components: [wrapper],
  };
}

function noticeResponse(plural: string, items: Json[]): Json {
  const all: Json = { modules: [], plugins: [], templates: [], language_packs: [] };
  all[plural] = items;
  return {
    success: true,
    data: {
      items: all,
      hash: 'h1',
      core_version: '7.1.2',
      command: 'php artisan core:bundled-sources place',
      commands: items.length ? { [plural]: `php artisan core:bundled-sources place ${items.map((i) => i.identifier).join(' ')}` } : {},
    },
  };
}

const ITEMS = [
  { identifier: 'g7test-a', name: '가', version: '1.0.0' },
  { identifier: 'g7test-b', name: '나', version: '2.0.0' },
];

describe('미배치 공식 번들 안내 — 렌더 (#146)', () => {
  let registry: ComponentRegistry;
  let testUtils: ReturnType<typeof createLayoutTest> | undefined;

  beforeEach(() => {
    registry = setupRegistry();
  });

  afterEach(() => {
    testUtils?.cleanup();
    testUtils = undefined;
  });

  for (const scr of SCREENS) {
    /**
     * @effects notice_block_renders_per_screen_type
     */
    it(`${scr.name} 화면은 자기 유형 항목이 있으면 제목·항목·명령·role=status·닫기 라벨을 그린다`, async () => {
      testUtils = createLayoutTest(buildLayout(scr), { translations: { extensions: ko }, locale: 'ko', componentRegistry: registry });
      testUtils.mockApi('unplaced_bundled_notice', { response: noticeResponse(scr.plural, ITEMS) });
      await testUtils.render();

      const wrapper = screen.getByTestId('unplaced_bundled_notice_wrapper');
      expect(wrapper.getAttribute('role')).toBe('status');
      expect(wrapper.textContent).toContain('코어 7.1.2 배포본에 있지만 이 프로젝트에 배치하지 않은 공식');
      expect(wrapper.textContent).toContain('2개');
      expect(wrapper.textContent).toContain('g7test-a');
      expect(wrapper.textContent).toContain('— 나 (2.0.0)');
      expect(screen.getByTestId('unplaced_bundled_notice_command').textContent).toBe('php artisan core:bundled-sources place g7test-a g7test-b');
      expect(screen.getByTestId('unplaced_bundled_notice_dismiss').getAttribute('aria-label')).toBe('이 안내 닫기');
      expect(wrapper.textContent).not.toMatch(/\$t:|extensions\.unplaced_bundled/);
    });

    /**
     * @effects notice_block_renders_per_screen_type
     */
    it(`${scr.name} 화면은 다른 유형에만 항목이 있으면 그리지 않는다`, async () => {
      const other = SCREENS.find((s) => s.plural !== scr.plural)!;
      testUtils = createLayoutTest(buildLayout(scr), { translations: { extensions: ko }, locale: 'ko', componentRegistry: registry });
      testUtils.mockApi('unplaced_bundled_notice', { response: noticeResponse(other.plural, ITEMS) });
      await testUtils.render();

      expect(screen.queryByTestId('unplaced_bundled_notice_wrapper')).toBeNull();
    });
  }

  /**
   * @scenario domain=plugin
   * @effects notice_dismiss_posts_refetches_and_toasts_on_failure
   */
  it('[닫기] 는 그 유형 dismiss API 를 POST 한다', async () => {
    const scr = SCREENS[1];
    testUtils = createLayoutTest(buildLayout(scr), { translations: { extensions: ko }, locale: 'ko', componentRegistry: registry });
    testUtils.mockApi('unplaced_bundled_notice', { response: noticeResponse(scr.plural, ITEMS) });
    testUtils.mockApi('extensions', { response: { success: true, data: { type: 'plugin', hash: 'h1' } } });
    await testUtils.render();

    fireEvent.click(screen.getByTestId('unplaced_bundled_notice_dismiss'));

    await waitFor(() => {
      const calls = (globalThis.fetch as any).mock.calls as Array<[string, RequestInit | undefined]>;
      expect(calls.some(([url, init]) => String(url).includes('/api/admin/extensions/unplaced-bundled/plugin/dismiss') && init?.method === 'POST')).toBe(true);
    });
  });

  /**
   * @scenario domain=plugin
   * @effects notice_dismiss_posts_refetches_and_toasts_on_failure
   */
  it('[닫기] 가 실패하면 오류 토스트를 띄우고 안내는 그대로 둔다', async () => {
    const scr = SCREENS[1];
    testUtils = createLayoutTest(buildLayout(scr), { translations: { extensions: ko }, locale: 'ko', componentRegistry: registry });
    testUtils.mockApi('unplaced_bundled_notice', { response: noticeResponse(scr.plural, ITEMS) });
    testUtils.mockApiError('extensions', 403, '권한이 없습니다.');
    await testUtils.render();

    fireEvent.click(screen.getByTestId('unplaced_bundled_notice_dismiss'));

    // 코어 `toast` 핸들러는 전역 `toasts` 에 쌓는다(하네스 getToasts() 는 커스텀 핸들러만 잡는다)
    await waitFor(() => {
      const toasts = (testUtils!.getState()._global?.toasts ?? []) as Array<{ type?: string; message?: string }>;
      expect(toasts.some((t) => t.type === 'error' && String(t.message).includes('권한이 없습니다.'))).toBe(true);
    });
    expect(screen.getByTestId('unplaced_bundled_notice_wrapper')).toBeTruthy();
  });
});

describe('미배치 공식 번들 안내 — 배선 (실제 굽기 산출 JSON)', () => {
  for (const scr of SCREENS) {
    /**
     * @effects notice_dismiss_posts_refetches_and_toasts_on_failure
     */
    it(`${scr.name} 화면: 데이터 소스·조건·dismiss 대상·성공 재조회·실패 토스트가 자기 유형으로 묶여 있다`, () => {
      const ds = collect(read(scr.ds).data_sources ?? [], (n) => n.id === 'unplaced_bundled_notice')[0];
      expect(ds.endpoint).toBe('/api/admin/extensions/unplaced-bundled');
      expect(ds.method).toBe('GET');

      const wrapper = findWithAncestors(read(scr.block), 'unplaced_bundled_notice_wrapper')!.node;
      expect(wrapper.if).toContain(`items?.${scr.plural}?.length`);
      SCREENS.filter((s) => s.plural !== scr.plural).forEach((s) => {
        expect(JSON.stringify(wrapper)).not.toContain(`items?.${s.plural}`);
      });

      const call = collect(wrapper, (n) => n.handler === 'apiCall')[0];
      expect(call.target).toBe(`/api/admin/extensions/unplaced-bundled/${scr.type}/dismiss`);
      expect(call.params?.method ?? call.method).toBe('POST');
      expect(collect(call.onSuccess ?? [], (n) => n.handler === 'refetchDataSource' && n.params?.dataSourceId === 'unplaced_bundled_notice')).toHaveLength(1);
      expect(collect(call.onError ?? [], (n) => n.handler === 'toast' && n.params?.type === 'error')).toHaveLength(1);
    });
  }

  /**
   * @scenario domain=template
   * @effects notice_block_renders_per_screen_type
   */
  it('템플릿 화면은 안내를 user/admin 탭 바깥에 둬 두 탭이 같은 안내를 본다', () => {
    const hit = findWithAncestors(read('../../layouts/admin_template_list.json'), 'unplaced_bundled_notice_wrapper')!;
    hit.ancestors.forEach((a) => expect(String(a.if ?? '')).not.toMatch(/activeTemplateTab|route\.type/));
  });

  /**
   * @scenario domain=language_pack
   * @effects notice_block_renders_per_screen_type
   */
  it('설정 화면의 안내 소스는 언어팩 탭일 때만 받는다', () => {
    const ds = collect(read('../../layouts/admin_settings.json').data_sources ?? [], (n) => n.id === 'unplaced_bundled_notice')[0];
    expect(ds.if).toContain("=== 'language_packs'");
  });

  /**
   * @effects notice_i18n_keys_exist
   */
  it('안내 다국어 키가 ko·en 에 모두 있다', () => {
    const keys = ['title_modules', 'title_plugins', 'title_templates', 'title_language_packs', 'description', 'command_label', 'dismiss'];
    for (const lang of [ko, en]) {
      keys.forEach((key) => expect(lang.unplaced_bundled?.[key], key).toBeTruthy());
      ['title_modules', 'title_plugins', 'title_templates', 'title_language_packs'].forEach((key) => {
        expect(lang.unplaced_bundled[key]).toContain('{{version}}');
        expect(lang.unplaced_bundled[key]).toContain('{{count}}');
      });
    }
  });
});
