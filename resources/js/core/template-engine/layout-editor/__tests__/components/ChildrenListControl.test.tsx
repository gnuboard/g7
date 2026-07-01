/**
 * ChildrenListControl.test.tsx — `children` 노드 에디터 RTL
 *
 * 검증:
 *  - node.children 항목 행 렌더(텍스트 입력 = I18nTextField 미리보기 / 구조 자식 라벨)
 *  - 항목 추가(params.childComponent defaultNode) → onPatchNode({...node, children})
 *  - 삭제/정렬 → children 배열 패치(PATCH_LAYOUT 생존: 노드 전체 교체)
 *  - 항목 텍스트 평문 입력(I18nTextField) → createCustomKey → 항목 text 가 `$t:custom.*` 로 치환
 *  - 기존 커스텀 키 입력 → updateCustomKeyValue(현재 로케일 값만, text 유지)
 *  - 기존 커스텀 키 → 🌐 펼침 시 ko/en/ja 일괄 편집 폼(I18nTextField B) 노출
 *  - childComponent 미선언 capability → 안내(misconfigured)
 *
 * 7-b: 항목 텍스트 편집은 속성 패널 propControl 과 **동일 공통 위젯**(I18nTextField)·동일
 * `$t:custom.*` SSoT(useCustomTranslation → useInlineEdit CRUD)로 일원화됐다. 따라서 본 스위트는
 * I18nTextField 미리보기 입력칸(`*-preview`)·🌐 토글(`*-toggle`)·펼침 폼(`*-expand`)을 대상으로 한다.
 *
 * @effects children_capability_declared_on_ul_ol_nav_form_li_both_templates, children_editor_registered_via_registercoreeditors_kind_agnostic, property_modal_dispatches_children_node_editor_in_props_tab_by_kind_not_name, add_item_appends_childcomponent_defaultnode_to_children, add_item_patches_whole_node_so_props_survive_patch_layout, remove_item_drops_only_that_child_from_children, move_up_down_swaps_adjacent_children_with_boundary_guard, canvas_drag_drop_and_list_editor_share_same_children_array, plain_item_blur_creates_custom_key_and_replaces_text_with_dollar_t_custom, existing_custom_key_blur_updates_current_locale_value_keeps_key, existing_custom_key_expand_shows_ko_en_ja_bulk_form, structural_child_without_text_shows_component_name_label_only, unchanged_blur_is_noop_no_crud_call, missing_child_component_capability_shows_misconfigured_notice, live_add_li_edit_text_reorder_save_persists_to_user_page
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, waitFor } from '@testing-library/react';

// useLayoutEditor — layoutName/locale/templateIdentifier 공급 모킹.
// I18nTextField/useCustomTranslation 도 동일 컨텍스트를 읽으므로 한 곳에서 공급한다.
vi.mock('../../LayoutEditorContext', () => ({
  useLayoutEditor: () => ({
    state: {
      templateIdentifier: 'sirsoft-basic',
      locale: 'ko',
      selectedRoute: { path: '/login', layoutName: 'login' },
    },
  }),
}));

// 커스텀 키 CRUD — 네트워크 없이 결과 주입. useCustomTranslation(7-a SSoT)·I18nTextField·
// ChildrenListControl 가 모두 이 모듈을 통해 키를 생성/갱신한다.
const createCustomKey = vi.fn();
const updateCustomKeyValue = vi.fn();
const bustTranslationCache = vi.fn().mockResolvedValue(undefined);
const findCustomKeyRow = vi.fn();
vi.mock('../../hooks/useInlineEdit', () => ({
  createCustomKey: (...a: unknown[]) => createCustomKey(...a),
  updateCustomKeyValue: (...a: unknown[]) => updateCustomKeyValue(...a),
  bustTranslationCache: (...a: unknown[]) => bustTranslationCache(...a),
  findCustomKeyRow: (...a: unknown[]) => findCustomKeyRow(...a),
  // I18nTextField 펼침=통합 TranslationField. 그 동기화 구독이 읽는 이벤트명 export.
  EDITOR_TRANSLATIONS_REFRESHED_EVENT: 'g7le:editor-translations-refreshed',
}));

// TranslationEngine — `$t:custom.*` 키를 현재 로케일 값으로 해석(시작값 표시).
vi.mock('../../../TranslationEngine', () => ({
  TranslationEngine: {
    getInstance: () => ({
      translate: (key: string) => (key === 'custom.login.1' ? '기존 항목' : key),
    }),
  },
}));

// LocaleSwitcher — 활성 로케일 목록/라벨(펼침 폼 ko/en/ja).
vi.mock('../../components/LocaleSwitcher', () => ({
  readSupportedLocales: () => ['ko', 'en', 'ja'],
  localeDisplayLabel: (loc: string) => ({ ko: '한국어', en: 'English', ja: '日本語' }[loc] ?? loc),
}));

// authToken — 펼침 폼 저장 헤더(네트워크 미발생 — fetch 미사용 경로만 테스트).
vi.mock('../../utils/authToken', () => ({
  buildAuthHeaders: (h: Record<string, string>) => h,
}));

import { ChildrenListControl } from '../../components/property-controls/ChildrenListControl';
import type { EditorNode } from '../../utils/layoutTreeUtils';

const t = (k: string, p?: Record<string, string | number>) =>
  p ? `${k}:${Object.values(p).join(',')}` : k;

const baseProps = {
  spec: {
    componentPalette: {
      entries: { Li: { defaultNode: { type: 'basic', name: 'Li', text: '' } } },
    },
  } as never,
  manifest: { components: { basic: [{ name: 'Li', type: 'basic', props: {} }] } } as never,
  t,
  templateIdentifier: 'sirsoft-basic',
};

afterEach(() => cleanup());
beforeEach(() => {
  createCustomKey.mockReset();
  updateCustomKeyValue.mockReset();
  bustTranslationCache.mockClear();
  findCustomKeyRow.mockReset();
});

function ulNode(children: EditorNode[]): EditorNode {
  return { type: 'basic', name: 'Ul', children };
}

/** 항목 idx 의 I18nTextField 미리보기 입력칸. */
function previewInput(idx: number): HTMLInputElement {
  return screen.getByTestId(`g7le-children-i18n-${idx}-preview`) as HTMLInputElement;
}

describe('ChildrenListControl — 구조 자식 편집', () => {
  it('children 텍스트 항목을 I18nTextField 미리보기 행으로 렌더한다', () => {
    const node = ulNode([
      { type: 'basic', name: 'Li', text: '첫째' },
      { type: 'basic', name: 'Li', text: '둘째' },
    ]);
    render(
      <ChildrenListControl
        {...baseProps}
        node={node}
        params={{ childComponent: 'Li' }}
        onPatchNode={vi.fn()}
      />,
    );
    // 행 래퍼 존재 + 위젯 미리보기 시작값 = 평문 그대로.
    expect(screen.getByTestId('g7le-children-text-0')).toBeInTheDocument();
    expect(previewInput(0).value).toBe('첫째');
    expect(previewInput(1).value).toBe('둘째');
  });

  it('빈 children → 안내 표시', () => {
    render(
      <ChildrenListControl
        {...baseProps}
        node={ulNode([])}
        params={{ childComponent: 'Li' }}
        onPatchNode={vi.fn()}
      />,
    );
    expect(screen.getByTestId('g7le-children-empty')).toBeInTheDocument();
  });

  it('"추가" → params.childComponent defaultNode 가 children 끝에 append (노드 전체 교체)', () => {
    const onPatchNode = vi.fn();
    const node = ulNode([{ type: 'basic', name: 'Li', text: '첫째' }]);
    render(
      <ChildrenListControl {...baseProps} node={node} params={{ childComponent: 'Li' }} onPatchNode={onPatchNode} />,
    );
    fireEvent.click(screen.getByTestId('g7le-children-add'));
    expect(onPatchNode).toHaveBeenCalledTimes(1);
    const patched = onPatchNode.mock.calls[0][0] as EditorNode;
    expect(patched.name).toBe('Ul');
    const kids = patched.children as EditorNode[];
    expect(kids).toHaveLength(2);
    expect(kids[1].name).toBe('Li');
    expect(kids[1].text).toBe('layout_editor.list_editor.new_item');
  });

  it('text 미선언 defaultNode(Input 등 void 컴포넌트) → 기본 텍스트 시드 금지 — React #137 회귀', () => {
    // Form→Input: Input defaultNode 는 text 를 선언하지 않는다(void element).
    // 시드를 강제하면 <input>새 항목</input> 렌더 → React error #137 크래시.
    const onPatchNode = vi.fn();
    const spec = {
      componentPalette: {
        entries: {
          Input: {
            defaultNode: { type: 'basic', name: 'Input', props: { type: 'text' } },
          },
        },
      },
    } as never;
    const formNode: EditorNode = { type: 'basic', name: 'Form', children: [] };
    render(
      <ChildrenListControl
        {...baseProps}
        spec={spec}
        node={formNode}
        params={{ childComponent: 'Input' }}
        onPatchNode={onPatchNode}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-children-add'));
    const kids = (onPatchNode.mock.calls[0][0] as EditorNode).children as EditorNode[];
    expect(kids).toHaveLength(1);
    expect(kids[0].name).toBe('Input');
    expect(kids[0].text).toBeUndefined();
  });

  it('manifest 폴백(스펙 defaultNode 부재)도 text 미선언이면 시드 금지', () => {
    const onPatchNode = vi.fn();
    const spec = { componentPalette: { entries: {} } } as never;
    const manifest = {
      components: { basic: [{ name: 'Input', type: 'basic', props: {} }] },
    } as never;
    const formNode: EditorNode = { type: 'basic', name: 'Form', children: [] };
    render(
      <ChildrenListControl
        {...baseProps}
        spec={spec}
        manifest={manifest}
        node={formNode}
        params={{ childComponent: 'Input' }}
        onPatchNode={onPatchNode}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-children-add'));
    const kids = (onPatchNode.mock.calls[0][0] as EditorNode).children as EditorNode[];
    expect(kids[0].text).toBeUndefined();
  });

  it('삭제 → 해당 항목 제거된 children 으로 패치', () => {
    const onPatchNode = vi.fn();
    const node = ulNode([
      { type: 'basic', name: 'Li', text: '첫째' },
      { type: 'basic', name: 'Li', text: '둘째' },
    ]);
    render(
      <ChildrenListControl {...baseProps} node={node} params={{ childComponent: 'Li' }} onPatchNode={onPatchNode} />,
    );
    fireEvent.click(screen.getByTestId('g7le-children-remove-0'));
    const kids = (onPatchNode.mock.calls[0][0] as EditorNode).children as EditorNode[];
    expect(kids).toHaveLength(1);
    expect(kids[0].text).toBe('둘째');
  });

  it('아래로 이동 → 항목 순서 교환', () => {
    const onPatchNode = vi.fn();
    const node = ulNode([
      { type: 'basic', name: 'Li', text: 'A' },
      { type: 'basic', name: 'Li', text: 'B' },
    ]);
    render(
      <ChildrenListControl {...baseProps} node={node} params={{ childComponent: 'Li' }} onPatchNode={onPatchNode} />,
    );
    fireEvent.click(screen.getByTestId('g7le-children-down-0'));
    const kids = (onPatchNode.mock.calls[0][0] as EditorNode).children as EditorNode[];
    expect(kids.map((k) => k.text)).toEqual(['B', 'A']);
  });

  it('행은 2줄 구조다 (입력 윗줄 / 액션 버튼 아랫줄) — 겹침·가로 스크롤 차단', () => {
    const node = ulNode([{ type: 'basic', name: 'Li', text: 'A' }]);
    render(
      <ChildrenListControl {...baseProps} node={node} params={{ childComponent: 'Li' }} onPatchNode={vi.fn()} />,
    );
    const row = screen.getByTestId('g7le-children-row-0');
    expect(row.style.flexDirection).toBe('column');
    // 텍스트 입력과 액션 버튼은 서로 다른 줄(부모)에 있어 겹치지 않는다.
    const textLine = (screen.getByTestId('g7le-children-text-0').parentElement) as HTMLElement;
    const actionLine = (screen.getByTestId('g7le-children-up-0').parentElement) as HTMLElement;
    expect(actionLine).not.toBe(textLine);
    expect(actionLine.style.justifyContent).toBe('flex-end');
    expect(actionLine).toContainElement(screen.getByTestId('g7le-children-remove-0'));
  });

  it('경계 가드 — 첫 행 위로/마지막 행 아래로 비활성', () => {
    const node = ulNode([
      { type: 'basic', name: 'Li', text: 'A' },
      { type: 'basic', name: 'Li', text: 'B' },
    ]);
    render(
      <ChildrenListControl {...baseProps} node={node} params={{ childComponent: 'Li' }} onPatchNode={vi.fn()} />,
    );
    expect(screen.getByTestId('g7le-children-up-0')).toBeDisabled();
    expect(screen.getByTestId('g7le-children-down-1')).toBeDisabled();
  });

  it('텍스트 없는 구조 자식(자손도 텍스트 없음) → 컴포넌트명 라벨(정렬/삭제만)', () => {
    const node = ulNode([{ type: 'basic', name: 'Div', children: [{ type: 'basic', name: 'Img', props: { src: 'x' } }] }]);
    render(
      <ChildrenListControl {...baseProps} node={node} params={{ childComponent: 'Li' }} onPatchNode={vi.fn()} />,
    );
    expect(screen.getByTestId('g7le-children-label-0').textContent).toBe('Div');
    expect(screen.queryByTestId('g7le-children-i18n-0-preview')).not.toBeInTheDocument();
  });

  it('자손에 텍스트가 있는 항목(Li > [Span("•"), Span("텍스트")]) → 의미 텍스트 자손을 편집 대상으로', () => {
    const node = ulNode([
      {
        type: 'basic',
        name: 'Li',
        children: [
          { type: 'basic', name: 'Span', text: '•' },
          { type: 'basic', name: 'Span', text: 'Respectful conversations' },
        ],
      },
    ]);
    render(
      <ChildrenListControl {...baseProps} node={node} params={{ childComponent: 'Li' }} onPatchNode={vi.fn()} />,
    );
    // 장식용 '•'(1글자)는 건너뛰고 의미 텍스트를 위젯 미리보기 시작값으로.
    expect(previewInput(0).value).toBe('Respectful conversations');
    expect(screen.queryByTestId('g7le-children-label-0')).not.toBeInTheDocument();
  });

  it('자손 텍스트 편집 평문 입력 → createCustomKey 후 **그 자손** text 만 `$t:custom.*` 로 치환', async () => {
    createCustomKey.mockResolvedValue({
      kind: 'ok',
      resource: { id: 5, translation_key: 'custom.login.3', values: { ko: '새 규칙' }, lock_version: 0 },
    });
    const onPatchNode = vi.fn();
    const node = ulNode([
      {
        type: 'basic',
        name: 'Li',
        children: [
          { type: 'basic', name: 'Span', text: '•' },
          { type: 'basic', name: 'Span', text: 'Old rule' },
        ],
      },
    ]);
    render(
      <ChildrenListControl {...baseProps} node={node} params={{ childComponent: 'Li' }} onPatchNode={onPatchNode} />,
    );
    const input = previewInput(0);
    fireEvent.change(input, { target: { value: '새 규칙' } });
    fireEvent.blur(input);
    await waitFor(() => expect(createCustomKey).toHaveBeenCalled());
    await waitFor(() => expect(onPatchNode).toHaveBeenCalled());
    const patched = onPatchNode.mock.calls[onPatchNode.mock.calls.length - 1][0] as EditorNode;
    const li = (patched.children as EditorNode[])[0];
    const spans = li.children as EditorNode[];
    // 불릿 Span 은 그대로, 텍스트 Span 만 키 토큰으로 치환.
    expect(spans[0].text).toBe('•');
    expect(spans[1].text).toBe('$t:custom.login.3');
  });
});

describe('ChildrenListControl — 항목 다국어(I18nTextField SSoT)', () => {
  it('평문 항목 입력(변경) → createCustomKey 후 항목 text 를 `$t:custom.*` 로 치환', async () => {
    createCustomKey.mockResolvedValue({
      kind: 'ok',
      resource: { id: 7, translation_key: 'custom.login.9', values: { ko: '바뀐값' }, lock_version: 0 },
    });
    const onPatchNode = vi.fn();
    const node = ulNode([{ type: 'basic', name: 'Li', text: '원래값' }]);
    render(
      <ChildrenListControl {...baseProps} node={node} params={{ childComponent: 'Li' }} onPatchNode={onPatchNode} />,
    );
    const input = previewInput(0);
    fireEvent.change(input, { target: { value: '바뀐값' } });
    fireEvent.blur(input);
    // useCustomTranslation 은 layoutName('login') 을 컨텍스트에서 자체 해석.
    await waitFor(() => expect(createCustomKey).toHaveBeenCalledWith('sirsoft-basic', 'login', 'ko', '바뀐값'));
    await waitFor(() => expect(onPatchNode).toHaveBeenCalled());
    const lastCall = onPatchNode.mock.calls[onPatchNode.mock.calls.length - 1][0] as EditorNode;
    const kids = lastCall.children as EditorNode[];
    expect(kids[0].text).toBe('$t:custom.login.9');
  });

  it('기존 커스텀 키 항목 입력(변경) → updateCustomKeyValue(현재 로케일), text 키 유지', async () => {
    updateCustomKeyValue.mockResolvedValue({ kind: 'ok' });
    const onPatchNode = vi.fn();
    const node = ulNode([{ type: 'basic', name: 'Li', text: '$t:custom.login.1' }]);
    render(
      <ChildrenListControl {...baseProps} node={node} params={{ childComponent: 'Li' }} onPatchNode={onPatchNode} />,
    );
    const input = previewInput(0);
    // 시작값 = TranslationEngine 해석값 '기존 항목'(raw 키 미노출).
    expect(input.value).toBe('기존 항목');
    fireEvent.change(input, { target: { value: '수정값' } });
    fireEvent.blur(input);
    await waitFor(() =>
      expect(updateCustomKeyValue).toHaveBeenCalledWith('sirsoft-basic', 'custom.login.1', 'ko', '수정값'),
    );
    // 커스텀 키는 그대로 — createCustomKey 미호출, 노드 text(키) 불변(onPatchNode 가 와도 동일 토큰).
    expect(createCustomKey).not.toHaveBeenCalled();
  });

  it('기존 커스텀 키 → 🌐 펼침 시 ko/en/ja 일괄 편집 폼 노출(I18nTextField B)', async () => {
    findCustomKeyRow.mockResolvedValue({
      id: 1,
      lock_version: 0,
      values: { ko: '기존 항목', en: 'Existing', ja: '' },
    });
    const node = ulNode([{ type: 'basic', name: 'Li', text: '$t:custom.login.1' }]);
    render(
      <ChildrenListControl {...baseProps} node={node} params={{ childComponent: 'Li' }} onPatchNode={vi.fn()} />,
    );
    fireEvent.click(screen.getByTestId('g7le-children-i18n-0-toggle'));
    // 펼침=통합 TranslationField. 펼침부 testid 는 `g7le-translation-*`(고정).
    await waitFor(() => expect(screen.getByTestId('g7le-translation-field')).toBeInTheDocument());
    expect(screen.getByTestId('g7le-translation-input-ko')).toHaveValue('기존 항목');
    expect(screen.getByTestId('g7le-translation-input-en')).toHaveValue('Existing');
    // ja 미번역 → 마크 표시.
    expect(screen.getByTestId('g7le-translation-missing-ja')).toBeInTheDocument();
  });

  it('변경 없는 blur → CRUD 미호출(no-op)', async () => {
    const node = ulNode([{ type: 'basic', name: 'Li', text: '그대로' }]);
    render(
      <ChildrenListControl {...baseProps} node={node} params={{ childComponent: 'Li' }} onPatchNode={vi.fn()} />,
    );
    const input = previewInput(0);
    fireEvent.focus(input);
    fireEvent.change(input, { target: { value: '그대로' } });
    fireEvent.blur(input);
    await new Promise((r) => setTimeout(r, 10));
    expect(createCustomKey).not.toHaveBeenCalled();
    expect(updateCustomKeyValue).not.toHaveBeenCalled();
  });

  it('바인딩식 항목 → 읽기전용 디그레이드(편집 차단)', () => {
    const node = ulNode([{ type: 'basic', name: 'Li', text: '{{post.title}}' }]);
    render(
      <ChildrenListControl {...baseProps} node={node} params={{ childComponent: 'Li' }} onPatchNode={vi.fn()} />,
    );
    // I18nTextField C — 바인딩 배지(미리보기 입력칸 미노출).
    expect(screen.getByTestId('g7le-children-i18n-0-binding')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-children-i18n-0-preview')).not.toBeInTheDocument();
  });
});

describe('ChildrenListControl — 잘못된 capability', () => {
  it('childComponent 미선언 → 안내(misconfigured)', () => {
    render(
      <ChildrenListControl {...baseProps} node={ulNode([])} params={{}} onPatchNode={vi.fn()} />,
    );
    expect(screen.getByTestId('g7le-children-editor-misconfigured')).toBeInTheDocument();
  });
});

describe('ChildrenListControl — 스펙 주도 childTemplate/itemFields', () => {
  // Form 의 "라벨+입력칸 묶음" 행 — 양 템플릿 capability 가 선언하는 형태.
  const bundleParams = {
    childComponent: 'Input',
    childLabel: '입력 항목',
    childTemplate: {
      type: 'basic',
      name: 'Div',
      children: [
        {
          type: 'basic',
          name: 'Label',
          children: [{ type: 'basic', name: 'Span', text: '' }],
        },
        {
          type: 'basic',
          name: 'Input',
          props: { type: 'text', placeholder: '$t:layout_editor.palette.input.default_placeholder' },
        },
      ],
    },
    itemFields: [
      { kind: 'text', label: '라벨' },
      { kind: 'prop', prop: 'placeholder', label: '안내 문구' },
    ],
  };

  function formNode(children: EditorNode[]): EditorNode {
    return { type: 'basic', name: 'Form', children };
  }

  it('"추가" → childTemplate 골격(라벨+입력칸 묶음)이 append, 라벨 Span 에만 기본 텍스트 시드', () => {
    const onPatchNode = vi.fn();
    render(
      <ChildrenListControl {...baseProps} node={formNode([])} params={bundleParams} onPatchNode={onPatchNode} />,
    );
    fireEvent.click(screen.getByTestId('g7le-children-add'));
    const kids = (onPatchNode.mock.calls[0][0] as EditorNode).children as EditorNode[];
    expect(kids).toHaveLength(1);
    const bundle = kids[0]!;
    expect(bundle.name).toBe('Div');
    const label = (bundle.children as EditorNode[])[0]!;
    const input = (bundle.children as EditorNode[])[1]!;
    // 라벨 Span(text:"") → "새 항목" 시드. Input 은 text 미선언 그대로(React #137 차단).
    expect((label.children as EditorNode[])[0]!.text).toBe('layout_editor.list_editor.new_item');
    expect(input.text).toBeUndefined();
    expect((input.props as Record<string, unknown>).placeholder).toBe(
      '$t:layout_editor.palette.input.default_placeholder',
    );
  });

  it('추가 버튼 명칭 = childLabel (childComponent 폴백 아님)', () => {
    render(
      <ChildrenListControl {...baseProps} node={formNode([])} params={bundleParams} onPatchNode={vi.fn()} />,
    );
    expect(screen.getByTestId('g7le-children-add').textContent).toBe(
      'layout_editor.list_editor.add_child:입력 항목',
    );
  });

  it('itemFields 선언 항목 행 → 라벨 + 안내 문구 두 필드(캡션 포함) 렌더', () => {
    const item: EditorNode = {
      type: 'basic',
      name: 'Div',
      children: [
        { type: 'basic', name: 'Label', children: [{ type: 'basic', name: 'Span', text: '이메일' }] },
        { type: 'basic', name: 'Input', props: { placeholder: '이메일을 입력하세요' } },
      ],
    };
    render(
      <ChildrenListControl {...baseProps} node={formNode([item])} params={bundleParams} onPatchNode={vi.fn()} />,
    );
    // 라벨 필드(텍스트 자손) + 캡션.
    expect(screen.getByTestId('g7le-children-i18n-0-preview')).toHaveValue('이메일');
    expect(screen.getByTestId('g7le-children-caption-text-0').textContent).toBe('라벨');
    // 안내 문구 필드(prop 자손) + 캡션.
    expect(screen.getByTestId('g7le-children-prop-placeholder-0-preview')).toHaveValue('이메일을 입력하세요');
    expect(screen.getByTestId('g7le-children-caption-prop-placeholder-0').textContent).toBe('안내 문구');
  });

  it('prop 필드 평문 입력 → createCustomKey 후 그 자손의 props[prop] 만 키 토큰으로 치환', async () => {
    createCustomKey.mockResolvedValue({
      kind: 'ok',
      resource: { id: 9, translation_key: 'custom.login.11', values: { ko: '새 안내' }, lock_version: 0 },
    });
    const onPatchNode = vi.fn();
    const item: EditorNode = {
      type: 'basic',
      name: 'Div',
      children: [
        { type: 'basic', name: 'Label', children: [{ type: 'basic', name: 'Span', text: '이메일' }] },
        { type: 'basic', name: 'Input', props: { type: 'email', placeholder: '옛 안내' } },
      ],
    };
    render(
      <ChildrenListControl {...baseProps} node={formNode([item])} params={bundleParams} onPatchNode={onPatchNode} />,
    );
    const input = screen.getByTestId('g7le-children-prop-placeholder-0-preview') as HTMLInputElement;
    fireEvent.change(input, { target: { value: '새 안내' } });
    fireEvent.blur(input);
    await waitFor(() => expect(createCustomKey).toHaveBeenCalled());
    await waitFor(() => expect(onPatchNode).toHaveBeenCalled());
    const patched = onPatchNode.mock.calls[onPatchNode.mock.calls.length - 1][0] as EditorNode;
    const row0 = (patched.children as EditorNode[])[0]!;
    const labelSpan = ((row0.children as EditorNode[])[0]!.children as EditorNode[])[0]!;
    const inputNode = (row0.children as EditorNode[])[1]!;
    // 라벨 텍스트는 불변, placeholder 만 키 토큰.
    expect(labelSpan.text).toBe('이메일');
    expect((inputNode.props as Record<string, unknown>).placeholder).toBe('$t:custom.login.11');
    expect((inputNode.props as Record<string, unknown>).type).toBe('email');
  });

  it('placeholder 없는 항목(체크박스 행 등) → 라벨 필드만 렌더(안내 문구 필드 미노출)', () => {
    const item: EditorNode = {
      type: 'basic',
      name: 'Div',
      children: [{ type: 'basic', name: 'Label', children: [{ type: 'basic', name: 'Span', text: '약관 동의' }] }],
    };
    render(
      <ChildrenListControl {...baseProps} node={formNode([item])} params={bundleParams} onPatchNode={vi.fn()} />,
    );
    expect(screen.getByTestId('g7le-children-i18n-0-preview')).toHaveValue('약관 동의');
    expect(screen.queryByTestId('g7le-children-prop-placeholder-0-preview')).not.toBeInTheDocument();
  });

  it('편집 가능 필드가 전무한 구조 자식(text·placeholder 모두 없음) → 컴포넌트명 라벨만', () => {
    const item: EditorNode = { type: 'basic', name: 'Input', props: { type: 'checkbox' } };
    render(
      <ChildrenListControl {...baseProps} node={formNode([item])} params={bundleParams} onPatchNode={vi.fn()} />,
    );
    expect(screen.getByTestId('g7le-children-label-0').textContent).toBe('Input');
  });

  it('itemFields 미선언 → 종전 동작(텍스트 1필드, 캡션 없음) 그대로', () => {
    const node = ulNode([{ type: 'basic', name: 'Li', text: '첫째' }]);
    render(
      <ChildrenListControl {...baseProps} node={node} params={{ childComponent: 'Li' }} onPatchNode={vi.fn()} />,
    );
    expect(screen.getByTestId('g7le-children-i18n-0-preview')).toHaveValue('첫째');
    expect(screen.queryByTestId('g7le-children-caption-text-0')).not.toBeInTheDocument();
  });
});

//  (목록 칸 결선 회귀) — ChildrenListControl 은 NodeEditorProps.candidates 를 항목
// 텍스트의 I18nTextField 로 흘려야 `+데이터` 칩 삽입(키화) 입구가 뜬다. ArrayCellTreeEditor 가
// 본 컨트롤에 {...props} 통째 위임하므로 셀 트리 항목까지 동일 경로로 후보가 닿는다.
describe('ChildrenListControl — 항목 후보 풀 전달', () => {
  const scalarCandidate = {
    expression: '{{user.name}}', source: 'data_source' as const, sourceId: 'user',
    path: 'name', shape: 'scalar' as const, preview: '홍길동',
  };

  it('candidates 전달 시 평문 항목 텍스트 칸에 +데이터 버튼 노출', () => {
    const node = ulNode([{ type: 'basic', name: 'Li', text: '첫째' }]);
    render(
      <ChildrenListControl
        {...baseProps}
        node={node}
        params={{ childComponent: 'Li' }}
        onPatchNode={vi.fn()}
        candidates={[scalarCandidate]}
      />,
    );
    expect(screen.getByTestId('g7le-children-i18n-0-plus-data-btn')).toBeInTheDocument();
  });

  it('candidates 미전달 시 +데이터 버튼 미노출 (디그레이드)', () => {
    const node = ulNode([{ type: 'basic', name: 'Li', text: '첫째' }]);
    render(
      <ChildrenListControl {...baseProps} node={node} params={{ childComponent: 'Li' }} onPatchNode={vi.fn()} />,
    );
    expect(screen.queryByTestId('g7le-children-i18n-0-plus-data-btn')).not.toBeInTheDocument();
  });
});

/*
 * 시나리오 cross-product 커버리지 마킹 — test-scenario-coverage 룰용.
 * 본 단위 스위트(ChildrenListControl)가 control 로직을, coverage 스위트가 컴포넌트별 capability
 * 선언을, children-list-editor.spec.ts(E2E)가 라이브 영속을 잠근다. 아래 마킹은 axes cross
 * product 와 effects 를 그 테스트군에 귀속시킨다.
 */
// @scenario component=Ul, item_text_kind=plain_new, operation=add, template=sirsoft-basic
// @scenario component=Ul, item_text_kind=plain_new, operation=add, template=sirsoft-admin_basic
// @scenario component=Ul, item_text_kind=plain_new, operation=remove, template=sirsoft-basic
// @scenario component=Ul, item_text_kind=plain_new, operation=remove, template=sirsoft-admin_basic
// @scenario component=Ul, item_text_kind=plain_new, operation=move_up, template=sirsoft-basic
// @scenario component=Ul, item_text_kind=plain_new, operation=move_up, template=sirsoft-admin_basic
// @scenario component=Ul, item_text_kind=plain_new, operation=move_down, template=sirsoft-basic
// @scenario component=Ul, item_text_kind=plain_new, operation=move_down, template=sirsoft-admin_basic
// @scenario component=Ul, item_text_kind=plain_new, operation=edit_text, template=sirsoft-basic
// @scenario component=Ul, item_text_kind=plain_new, operation=edit_text, template=sirsoft-admin_basic
// @scenario component=Ul, item_text_kind=existing_custom_key, operation=edit_text, template=sirsoft-basic
// @scenario component=Ul, item_text_kind=existing_custom_key, operation=edit_text, template=sirsoft-admin_basic
// @scenario component=Ol, item_text_kind=plain_new, operation=add, template=sirsoft-admin_basic
// @scenario component=Ol, item_text_kind=plain_new, operation=remove, template=sirsoft-admin_basic
// @scenario component=Ol, item_text_kind=plain_new, operation=move_up, template=sirsoft-admin_basic
// @scenario component=Ol, item_text_kind=plain_new, operation=move_down, template=sirsoft-admin_basic
// @scenario component=Ol, item_text_kind=plain_new, operation=edit_text, template=sirsoft-admin_basic
// @scenario component=Ol, item_text_kind=existing_custom_key, operation=edit_text, template=sirsoft-admin_basic
// @scenario component=Nav, item_text_kind=plain_new, operation=add, template=sirsoft-basic
// @scenario component=Nav, item_text_kind=plain_new, operation=add, template=sirsoft-admin_basic
// @scenario component=Nav, item_text_kind=plain_new, operation=remove, template=sirsoft-basic
// @scenario component=Nav, item_text_kind=plain_new, operation=remove, template=sirsoft-admin_basic
// @scenario component=Nav, item_text_kind=plain_new, operation=move_up, template=sirsoft-basic
// @scenario component=Nav, item_text_kind=plain_new, operation=move_up, template=sirsoft-admin_basic
// @scenario component=Nav, item_text_kind=plain_new, operation=move_down, template=sirsoft-basic
// @scenario component=Nav, item_text_kind=plain_new, operation=move_down, template=sirsoft-admin_basic
// @scenario component=Nav, item_text_kind=plain_new, operation=edit_text, template=sirsoft-basic
// @scenario component=Nav, item_text_kind=plain_new, operation=edit_text, template=sirsoft-admin_basic
// @scenario component=Nav, item_text_kind=existing_custom_key, operation=edit_text, template=sirsoft-basic
// @scenario component=Nav, item_text_kind=existing_custom_key, operation=edit_text, template=sirsoft-admin_basic
// @scenario component=Form, item_text_kind=plain_new, operation=add, template=sirsoft-basic
// @scenario component=Form, item_text_kind=plain_new, operation=add, template=sirsoft-admin_basic
// @scenario component=Form, item_text_kind=plain_new, operation=remove, template=sirsoft-basic
// @scenario component=Form, item_text_kind=plain_new, operation=remove, template=sirsoft-admin_basic
// @scenario component=Form, item_text_kind=plain_new, operation=move_up, template=sirsoft-basic
// @scenario component=Form, item_text_kind=plain_new, operation=move_up, template=sirsoft-admin_basic
// @scenario component=Form, item_text_kind=plain_new, operation=move_down, template=sirsoft-basic
// @scenario component=Form, item_text_kind=plain_new, operation=move_down, template=sirsoft-admin_basic
// @scenario component=Form, item_text_kind=plain_new, operation=edit_text, template=sirsoft-basic
// @scenario component=Form, item_text_kind=plain_new, operation=edit_text, template=sirsoft-admin_basic
// @scenario component=Form, item_text_kind=existing_custom_key, operation=edit_text, template=sirsoft-basic
// @scenario component=Form, item_text_kind=existing_custom_key, operation=edit_text, template=sirsoft-admin_basic
// @scenario component=Li, item_text_kind=plain_new, operation=add, template=sirsoft-basic
// @scenario component=Li, item_text_kind=plain_new, operation=add, template=sirsoft-admin_basic
// @scenario component=Li, item_text_kind=plain_new, operation=remove, template=sirsoft-basic
// @scenario component=Li, item_text_kind=plain_new, operation=remove, template=sirsoft-admin_basic
// @scenario component=Li, item_text_kind=plain_new, operation=move_up, template=sirsoft-basic
// @scenario component=Li, item_text_kind=plain_new, operation=move_up, template=sirsoft-admin_basic
// @scenario component=Li, item_text_kind=plain_new, operation=move_down, template=sirsoft-basic
// @scenario component=Li, item_text_kind=plain_new, operation=move_down, template=sirsoft-admin_basic
// @scenario component=Li, item_text_kind=plain_new, operation=edit_text, template=sirsoft-basic
// @scenario component=Li, item_text_kind=plain_new, operation=edit_text, template=sirsoft-admin_basic
// @scenario component=Li, item_text_kind=existing_custom_key, operation=edit_text, template=sirsoft-basic
// @scenario component=Li, item_text_kind=existing_custom_key, operation=edit_text, template=sirsoft-admin_basic
