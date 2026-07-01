import { describe, it, expect } from 'vitest';
import {
  buildPageCandidates,
  buildDataSourceCandidates,
  buildDataSourceOptions,
  friendlyDataSourceName,
  dataSourceExtensionBadge,
  buildStateKeyCandidates,
  buildModalCandidates,
} from '../../spec/candidatePools';
import type { RouteTreeNode } from '../../LayoutEditorContext';
import type { EditorSpec } from '../../spec/specTypes';

/**
 * candidatePools — 후보 풀 도출 순수 함수 단위.
 *
 * EditorCanvasOverlay 내부 useMemo 에서 추출한 도출 로직의 입출력을 고정해, 셸/캔버스가
 * 같은 후보를 받음을 보증한다(회귀 0 잠금 — 캔버스 동작 무변경).
 */

const node = (p: Partial<RouteTreeNode>): RouteTreeNode => ({
  path: '/',
  layoutName: 'x',
  label: '/',
  labelSource: 'path',
  source: { kind: 'template', identifier: 'sirsoft-basic' },
  kind: 'route',
  ...p,
});

describe('buildPageCandidates', () => {
  const identity = (k: string) => k; // $t 미해석 — 키 원문 반환

  it('실제 라우트만 수집하고 base/modal/extension/redirect 는 제외', () => {
    const tree: RouteTreeNode[] = [
      node({ path: '/', label: '/' }),
      node({ path: '/about', label: '/about' }),
      node({ path: '/redir', isRedirect: true }),
      node({ path: '__base__', kind: 'base' }),
      node({ path: 'modal-x', kind: 'modal' }),
      node({ path: '__extension__/e1', kind: 'extension' }),
    ];
    const out = buildPageCandidates(tree, identity);
    expect(out.map((c) => c.value)).toEqual(['/', '/about']);
  });

  it('중첩 트리를 평탄화하고 중복 path 는 1건', () => {
    const tree: RouteTreeNode[] = [
      node({ path: '/parent', children: [node({ path: '/child' }), node({ path: '/parent' })] }),
    ];
    const out = buildPageCandidates(tree, identity);
    expect(out.map((c) => c.value)).toEqual(['/parent', '/child']);
  });

  it('$t: 라벨 해석 성공 시 친화명(path) 형식, 실패 시 path 만', () => {
    const tree: RouteTreeNode[] = [
      node({ path: '/p1', label: '$t:user.home' }),
      node({ path: '/p2', label: '$t:user.unresolved' }),
    ];
    const resolve = (k: string) => (k === 'user.home' ? '홈' : k); // unresolved 는 키 원문
    const out = buildPageCandidates(tree, resolve);
    expect(out).toEqual([
      { value: '/p1', label: '홈 (/p1)' },
      { value: '/p2', label: '/p2' },
    ]);
  });

  it('빈 트리 → 빈 배열', () => {
    expect(buildPageCandidates(undefined, identity)).toEqual([]);
    expect(buildPageCandidates([], identity)).toEqual([]);
  });
});

describe('buildDataSourceCandidates', () => {
  it('raw.data_sources 각 id 를 value=label=id 로', () => {
    const raw = { data_sources: [{ id: 'products' }, { id: 'orders' }] };
    expect(buildDataSourceCandidates(raw)).toEqual([
      { value: 'products', label: 'products' },
      { value: 'orders', label: 'orders' },
    ]);
  });

  it('id 없거나 빈 문자열 항목 제외', () => {
    const raw = { data_sources: [{ id: '' }, {}, { id: 'ok' }] };
    expect(buildDataSourceCandidates(raw)).toEqual([{ value: 'ok', label: 'ok' }]);
  });

  it('data_sources 부재/비배열 → 빈 배열', () => {
    expect(buildDataSourceCandidates(null)).toEqual([]);
    expect(buildDataSourceCandidates({})).toEqual([]);
    expect(buildDataSourceCandidates({ data_sources: 'x' })).toEqual([]);
  });
});

describe('friendlyDataSourceName', () => {
  // resolve 가 `$t:` 키를 친화명으로 바꾼다고 가정한 mock.
  const resolve = (key: string): string => (key === 'editor.data_source.products' ? '상품 목록' : key);

  it('label_key($t:) 해석 성공 시 친화명', () => {
    expect(friendlyDataSourceName({ id: 'products', label_key: '$t:editor.data_source.products' }, resolve)).toBe('상품 목록');
  });

  it('label_key 미지정 → null(폴백=id)', () => {
    expect(friendlyDataSourceName({ id: 'products' }, resolve)).toBeNull();
  });

  it('해석 실패(키 원문/`$t:` 잔존/id 동일) → null', () => {
    expect(friendlyDataSourceName({ id: 'orders', label_key: '$t:editor.data_source.orders' }, resolve)).toBeNull();
    expect(friendlyDataSourceName({ id: 'orders', label_key: '$t:orders' }, (k) => k)).toBeNull();
  });
});

describe('dataSourceExtensionBadge', () => {
  const t = (key: string): string =>
    key === 'layout_editor.data_sources.source.plugin' ? '플러그인' : key === 'layout_editor.data_sources.source.module' ? '모듈' : key;

  it('확장(plugin) 주입 → "플러그인: 이름 (식별자)"', () => {
    const entry = {
      id: 'gdprMyConsent',
      __source: { kind: 'extension', extensionSourceType: 'plugin', extensionIdentifier: 'sirsoft-gdpr', extensionName: 'GDPR' },
    };
    expect(dataSourceExtensionBadge(entry, t)).toBe('플러그인: GDPR (sirsoft-gdpr)');
  });

  it('확장(module) + 이름=식별자 → "모듈: 식별자"', () => {
    const entry = {
      id: 'boards',
      __source: { kind: 'extension', extensionSourceType: 'module', extensionIdentifier: 'sirsoft-board', extensionName: 'sirsoft-board' },
    };
    expect(dataSourceExtensionBadge(entry, t)).toBe('모듈: sirsoft-board');
  });

  it('비-확장(__source 부재/kind 비-extension) → null', () => {
    expect(dataSourceExtensionBadge({ id: 'x' }, t)).toBeNull();
    expect(dataSourceExtensionBadge({ id: 'x', __source: { kind: 'base' } }, t)).toBeNull();
  });
});

describe('buildDataSourceOptions', () => {
  const resolve = (key: string): string => (key === 'editor.data_source.products' ? '상품 목록' : key);
  const t = (key: string): string =>
    key === 'layout_editor.data_sources.source.plugin' ? '플러그인' : key === 'layout_editor.data_sources.source.module' ? '모듈' : key;

  it('각 data_source 를 {id, friendly, source} 로 — 친화명·확장 출처 동반', () => {
    const raw = {
      data_sources: [
        { id: 'products', label_key: '$t:editor.data_source.products' },
        { id: 'gdprMyConsent', __source: { kind: 'extension', extensionSourceType: 'plugin', extensionIdentifier: 'sirsoft-gdpr', extensionName: 'GDPR' } },
        { id: 'plain' },
      ],
    };
    expect(buildDataSourceOptions(raw, resolve, t)).toEqual([
      { id: 'products', friendly: '상품 목록', source: null },
      { id: 'gdprMyConsent', friendly: null, source: '플러그인: GDPR (sirsoft-gdpr)' },
      { id: 'plain', friendly: null, source: null },
    ]);
  });

  it('id 중복/빈 항목 제외', () => {
    const raw = { data_sources: [{ id: 'a' }, { id: 'a' }, { id: '' }, {}] };
    expect(buildDataSourceOptions(raw, resolve, t)).toEqual([{ id: 'a', friendly: null, source: null }]);
  });

  it('data_sources 부재/비배열 → 빈 배열', () => {
    expect(buildDataSourceOptions(null, resolve, t)).toEqual([]);
    expect(buildDataSourceOptions({}, resolve, t)).toEqual([]);
    expect(buildDataSourceOptions({ data_sources: 'x' }, resolve, t)).toEqual([]);
  });
});

describe('buildStateKeyCandidates', () => {
  it('states.groups[].items[].initialState local+global 키 합집합', () => {
    const spec: EditorSpec = {
      states: {
        groups: [
          { items: [{ id: 'a', initialState: { local: { foo: 1 }, global: { bar: 2 } } }] },
          { items: [{ id: 'b', initialState: { local: { foo: 9, baz: 3 } } }] },
        ],
      },
    };
    const out = buildStateKeyCandidates(spec).map((c) => c.value).sort();
    expect(out).toEqual(['bar', 'baz', 'foo']);
  });

  it('states 부재 → 빈 배열', () => {
    expect(buildStateKeyCandidates(null)).toEqual([]);
    expect(buildStateKeyCandidates({})).toEqual([]);
  });
});

describe('buildModalCandidates', () => {
  it('raw.modals 각 id, 중복 제거 (label 도출 데이터 없으면 id 폴백)', () => {
    const raw = { modals: [{ id: 'm1' }, { id: 'm2' }, { id: 'm1' }] };
    expect(buildModalCandidates(raw)).toEqual([
      { value: 'm1', label: 'm1' },
      { value: 'm2', label: 'm2' },
    ]);
  });

  it('modals 부재/비배열 → 빈 배열', () => {
    expect(buildModalCandidates(null)).toEqual([]);
    expect(buildModalCandidates({ modals: 0 })).toEqual([]);
  });

  it('label = meta.editor_label($t: 키) → title → id 우선순위 (좌측 트리 동형)', () => {
    const raw = {
      modals: [
        { id: 'login_required_modal', meta: { editor_label: '$t:user.modal_label.shop_detail.login_required_modal' } },
        { id: 'plain_title_modal', title: '평문 제목 모달' },
        { id: 'id_only_modal' },
      ],
    };
    // resolveLabel 미주입 → $t: 키 원문(슬라이스)·평문 title·id 폴백.
    expect(buildModalCandidates(raw)).toEqual([
      { value: 'login_required_modal', label: 'user.modal_label.shop_detail.login_required_modal' },
      { value: 'plain_title_modal', label: '평문 제목 모달' },
      { value: 'id_only_modal', label: 'id_only_modal' },
    ]);
  });

  it('resolveLabel 주입 시 $t: 키를 사용자 언어로 해석', () => {
    const raw = {
      modals: [{ id: 'login_required_modal', meta: { editor_label: '$t:user.modal_label.shop_detail.login_required_modal' } }],
    };
    const resolveLabel = (key: string) =>
      key === 'user.modal_label.shop_detail.login_required_modal' ? '로그인 안내' : key;
    expect(buildModalCandidates(raw, resolveLabel)).toEqual([
      { value: 'login_required_modal', label: '로그인 안내' },
    ]);
  });
});
