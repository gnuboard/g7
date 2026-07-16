/**
 * s7-property-controls.test.tsx — Phase 5 S7 컨트롤 RTL
 *
 *  - RecipePickerControls (page/datasource/state-key/i18n-text) — 후보 select + 자유입력 폴백.
 *  - ActionRecipeEditor — events 슬롯, 동작 추가/제거, 파라미터 위젯 디스패치, 중첩 onSuccess.
 *  - ConditionBuilder — A~H 친화 목록, AND/OR 결합, 미리보기, 고급식 보존.
 *  - FlexEditor — 컨테이너/아이템/auto(make-flex) 모드.
 */

import React from 'react';
import { describe, it, expect, vi, beforeAll, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, waitFor } from '@testing-library/react';

// I18nTextControl 은 7-b 에서 공통 위젯 I18nTextField(useCustomTranslation SSoT)로 승격됐다 —
// 컨텍스트/CRUD/해석/로케일 모킹 필요(레시피 메시지도 $t:custom.* 다국어 자동화).
vi.mock('../../LayoutEditorContext', () => ({
  useLayoutEditor: () => ({
    state: { templateIdentifier: 'sirsoft-basic', locale: 'ko', selectedRoute: { path: '/x', layoutName: 'x' } },
  }),
}));
const s7CreateCustomKey = vi.fn();
vi.mock('../../hooks/useInlineEdit', () => ({
  createCustomKey: (...a: unknown[]) => s7CreateCustomKey(...a),
  updateCustomKeyValue: vi.fn(),
  findCustomKeyRow: vi.fn(),
  bustTranslationCache: vi.fn().mockResolvedValue(undefined),
  EDITOR_TRANSLATIONS_REFRESHED_EVENT: 'g7le:editor-translations-refreshed',
}));
vi.mock('../../../TranslationEngine', () => ({
  TranslationEngine: { getInstance: () => ({ translate: (k: string) => k }) },
}));
vi.mock('../../components/LocaleSwitcher', () => ({
  readSupportedLocales: () => ['ko', 'en', 'ja'],
  localeDisplayLabel: (loc: string) => loc,
}));
vi.mock('../../utils/authToken', () => ({ buildAuthHeaders: (h: Record<string, string>) => h }));

import {
  PagePickerControl,
  DataSourcePickerControl,
  I18nTextControl,
} from '../../components/property-controls/RecipePickerControls';
import { ActionRecipeEditor } from '../../components/property-controls/ActionRecipeEditor';
import { ConditionBuilder } from '../../components/property-controls/ConditionBuilder';
import { FlexEditor } from '../../components/property-controls/FlexEditor';
import { registerCoreWidgets } from '../../spec/registerCoreWidgets';
import type { EditorSpec } from '../../spec/specTypes';
import type { EditorNode } from '../../utils/layoutTreeUtils';

const t = (k: string) => k;
afterEach(() => cleanup());
beforeAll(() => registerCoreWidgets());

const SPEC: EditorSpec = {
  controls: {
    flexDirection: {
      label: '$t:editor.control.flex_direction.label',
      widget: 'segmented',
      group: 'flex-direction',
      options: [
        { value: 'row', label: 'row', apply: { type: 'classToken', tokens: ['flex-row'] } },
        { value: 'column', label: 'col', apply: { type: 'classToken', tokens: ['flex-col'] } },
      ],
    },
    flexEnable: { label: 'enable', widget: 'toggle', group: 'flex-enable', onValue: 'flex', apply: { type: 'classToken', tokens: ['flex'] } },
    flexItemGrow: { label: 'grow', widget: 'segmented', group: 'g', options: [{ value: 'fill', apply: { type: 'classToken', tokens: ['flex-1'] } }] },
  },
  actionRecipes: {
    goToPage: { label: '$t:editor.action.go_to_page.label', params: [{ key: 'url', widget: 'page-picker' }], build: { handler: 'navigate', params: { path: '{{url}}' } } },
    callServerThen: {
      label: 'call',
      params: [
        { key: 'endpoint', widget: 'text' },
        { key: 'onSuccess', widget: 'action-list' },
      ],
      build: { handler: 'apiCall', target: '{{endpoint}}', onSuccess: '{{onSuccess}}' },
    },
  },
  conditionRecipes: {
    operators: [
      { value: 'isLoggedIn', label: 'logged in', expr: '_global?.currentUser?.uuid' },
      { value: 'dsHasData', label: 'has data', params: [{ key: 'src', widget: 'datasource-picker' }], expr: '({src}?.data?.length ?? 0) > 0' },
    ],
  },
};

describe('RecipePickerControls', () => {
  it('page-picker 자유입력(후보 없음) → onChange', () => {
    const onChange = vi.fn();
    render(<PagePickerControl control={{}} value={undefined} onChange={onChange} t={t} />);
    // 자유입력 폴백은 데이터칩 입력기(DataChipValueInput)로 승격됨 — 평문 분기
    // input testid = `${prefix}-input-input`(컨테이너 prefix = `g7le-widget-page-picker-input`).
    fireEvent.change(screen.getByTestId('g7le-widget-page-picker-input-input'), { target: { value: '/board' } });
    expect(onChange).toHaveBeenCalledWith('/board');
  });

  it('datasource-picker 후보 select → onChange(id)', () => {
    const onChange = vi.fn();
    render(
      <DataSourcePickerControl
        control={{}}
        value={undefined}
        onChange={onChange}
        t={t}
        candidates={[{ value: 'posts', label: 'posts' }]}
      />,
    );
    fireEvent.change(screen.getByTestId('g7le-widget-datasource-picker'), { target: { value: 'posts' } });
    expect(onChange).toHaveBeenCalledWith('posts');
  });

  // (이슈2)목록 선택 모드에서 [✎] 명시 버튼으로 텍스트 편집 복귀(드롭다운
  // "직접 입력" 옵션 의존 제거). 후보 매칭 값이면 select 모드 → [✎] 클릭 → 자유 입력(칩) 모드.
  it('(이슈2) 목록 선택 모드 → [✎] 버튼으로 텍스트 편집 복귀', () => {
    const onChange = vi.fn();
    render(
      <DataSourcePickerControl
        control={{}}
        value={'posts'}
        onChange={onChange}
        t={t}
        candidates={[{ value: 'posts', label: 'posts' }]}
      />,
    );
    // 후보 매칭 → select 모드.
    expect(screen.getByTestId('g7le-widget-datasource-picker')).toBeInTheDocument();
    // [✎] 텍스트 편집 복귀 버튼 존재 + 클릭 시 자유 입력(칩) 모드 전환.
    const toCustom = screen.getByTestId('g7le-widget-datasource-picker-to-custom');
    expect(toCustom).toBeInTheDocument();
    fireEvent.click(toCustom);
    // select 사라지고 자유 입력기(DataChipValueInput) 등장.
    expect(screen.queryByTestId('g7le-widget-datasource-picker')).not.toBeInTheDocument();
    expect(screen.getByTestId('g7le-widget-datasource-picker-input-input')).toBeInTheDocument();
  });

  it('i18n-text 입력 — 공통 위젯(I18nTextField) 평문 입력 → createCustomKey 토큰을 onChange', async () => {
    s7CreateCustomKey.mockReset();
    s7CreateCustomKey.mockResolvedValue({ kind: 'ok', resource: { translation_key: 'custom.x.1' } });
    const onChange = vi.fn();
    render(<I18nTextControl control={{}} value={''} onChange={onChange} t={t} />);
    // 위젯 컨테이너 + 미리보기 입력칸(I18nTextField).
    expect(screen.getByTestId('g7le-widget-i18n-text')).toBeInTheDocument();
    const preview = screen.getByTestId('g7le-widget-i18n-text-field-preview');
    fireEvent.change(preview, { target: { value: '안녕하세요' } });
    fireEvent.blur(preview);
    // 평문 → 키 생성 → 토큰을 onChange(레시피 파라미터 메시지에 기록).
    await waitFor(() => expect(s7CreateCustomKey).toHaveBeenCalled());
    await waitFor(() => expect(onChange).toHaveBeenCalledWith('$t:custom.x.1'));
  });
});

describe('ActionRecipeEditor', () => {
  const node: EditorNode = { name: 'Button', props: {} };

  it('events 미선언 → 안내', () => {
    render(<ActionRecipeEditor node={node} spec={SPEC} capability={{}} t={t} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-action-no-events')).toBeInTheDocument();
  });

  it('events 슬롯 표시 + 동작 추가 → onPatchNode(navigate)', () => {
    const onPatch = vi.fn();
    render(
      <ActionRecipeEditor node={node} spec={SPEC} capability={{ events: ['onClick'] }} t={t} onPatchNode={onPatch} pageCandidates={[]} />,
    );
    expect(screen.getByTestId('g7le-action-event-onClick')).toBeInTheDocument();
    //  ② — 공용 ActionListBuilder 위임(슬롯 prefix g7le-action-slot-{eventName}).
    fireEvent.click(screen.getByTestId('g7le-action-slot-onClick-add-picker-toggle'));
    fireEvent.click(screen.getByTestId('g7le-init-action-spec-goToPage'));
    expect(onPatch).toHaveBeenCalled();
    const patched = onPatch.mock.calls[0][0] as EditorNode;
    // 액션은 노드 최상위 node.actions 배열 + type:'click' (props.actions/events 아님)
    expect((patched as { props?: { actions?: unknown; events?: unknown } }).props?.actions).toBeUndefined();
    const actions = (patched as { actions?: Array<Record<string, unknown>> }).actions;
    expect(actions?.[0]).toMatchObject({ type: 'click', handler: 'navigate' });
  });

  it('goToPage 동작의 page-picker 가 라우트 후보를 드롭다운으로 노출 + 선택 시 navigate ', () => {
    const onPatch = vi.fn();
    // 이미 goToPage 액션이 있는 노드(역해석으로 친화 편집 진입) — 최상위 actions + type:'click'.
    const withAction: EditorNode = { name: 'Button', actions: [{ type: 'click', handler: 'navigate', params: { path: '/board' } }] };
    render(
      <ActionRecipeEditor
        node={withAction}
        spec={SPEC}
        capability={{ events: ['onClick'] }}
        t={t}
        onPatchNode={onPatch}
        pageCandidates={[{ value: '/board', label: '게시판 (/board)' }, { value: '/login', label: '로그인 (/login)' }]}
      />,
    );
    // [편집] 펼치면 page-picker 가 후보 select 모드로 렌더 + 현재값 /board 가 후보에 매칭
    fireEvent.click(screen.getByTestId('g7le-action-slot-onClick-edit-0'));
    const picker = screen.getByTestId('g7le-widget-page-picker') as HTMLSelectElement;
    const optionValues = Array.from(picker.options).map((o) => o.value);
    expect(optionValues).toContain('/board');
    expect(optionValues).toContain('/login');
    expect(picker.value).toBe('/board');
    // 다른 후보 선택 → navigate path 갱신 (최상위 actions + type 유지)
    fireEvent.change(picker, { target: { value: '/login' } });
    const patched = onPatch.mock.calls[onPatch.mock.calls.length - 1][0] as EditorNode;
    const actions = (patched as { actions?: Array<Record<string, unknown>> }).actions;
    expect(actions?.[0]).toMatchObject({ type: 'click', handler: 'navigate', params: { path: '/login' } });
  });

  it('pageCandidates 미주입 시 page-picker 가 자유 path 입력으로 디그레이드', () => {
    const withAction: EditorNode = { name: 'Button', actions: [{ type: 'click', handler: 'navigate', params: { path: '/board' } }] };
    render(
      <ActionRecipeEditor node={withAction} spec={SPEC} capability={{ events: ['onClick'] }} t={t} onPatchNode={vi.fn()} />,
    );
    // [편집] 펼치면 후보 없음 → 자유입력(input) 폴백
    fireEvent.click(screen.getByTestId('g7le-action-slot-onClick-edit-0'));
    expect(screen.getByTestId('g7le-widget-page-picker-input')).toBeInTheDocument();
  });

  it('레시피 친화 명칭만 노출 — 핸들러 용어 없음(추가 목록 라벨)', () => {
    render(<ActionRecipeEditor node={node} spec={SPEC} capability={{ events: ['onClick'] }} t={t} onPatchNode={vi.fn()} />);
    fireEvent.click(screen.getByTestId('g7le-action-slot-onClick-add-picker-toggle'));
    // 친화 라벨(친화 명칭)만 클릭 대상 — 핸들러명은 회색 보조 표기(g7le-init-action-spec-{id} 라벨 span).
    const goToPageItem = screen.getByTestId('g7le-init-action-spec-goToPage');
    // 친화 라벨 텍스트(label span 첫 자식) 에는 핸들러 용어 없음.
    expect(goToPageItem.querySelector('span')?.textContent).not.toMatch(/navigate|apiCall|handler/);
  });

  it('callServerThen 추가 → 중첩 action-list(onSuccess) 슬롯 렌더', () => {
    // 최상위 actions + type:'click'
    const node2: EditorNode = { name: 'Button', actions: [{ type: 'click', handler: 'apiCall', target: 'x', onSuccess: [] }] };
    render(<ActionRecipeEditor node={node2} spec={SPEC} capability={{ events: ['onClick'] }} t={t} onPatchNode={vi.fn()} />);
    // [편집] 펼치면 params(onSuccess action-list) 노출.
    fireEvent.click(screen.getByTestId('g7le-action-slot-onClick-edit-0'));
    expect(screen.getByTestId('g7le-action-slot-onClick-edit-param-onSuccess')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-action-slot-onClick-edit-onSuccess')).toBeInTheDocument();
  });

  it('legacy props.actions 도 읽어 친화 편집 진입 (역호환) — 저장 시 최상위로 이관', () => {
    const onPatch = vi.fn();
    // 과거 S7 결함으로 props.actions 에 저장된 노드도 빌더가 읽어야 한다.
    const legacy: EditorNode = { name: 'Button', props: { actions: [{ type: 'click', handler: 'navigate', params: { path: '/board' } }] } };
    render(<ActionRecipeEditor node={legacy} spec={SPEC} capability={{ events: ['onClick'] }} t={t} onPatchNode={onPatch} pageCandidates={[{ value: '/board', label: 'b' }, { value: '/login', label: 'l' }]} />);
    fireEvent.click(screen.getByTestId('g7le-action-slot-onClick-edit-0'));
    const picker = screen.getByTestId('g7le-widget-page-picker') as HTMLSelectElement;
    fireEvent.change(picker, { target: { value: '/login' } });
    const patched = onPatch.mock.calls[onPatch.mock.calls.length - 1][0] as EditorNode;
    // 저장 시 최상위 actions 로 이관 + props.actions 제거
    expect((patched as { actions?: unknown[] }).actions).toBeDefined();
    expect((patched as { props?: { actions?: unknown } }).props?.actions).toBeUndefined();
  });

  it('onHover 이벤트 → type:mouseenter 로 저장', () => {
    const onPatch = vi.fn();
    render(<ActionRecipeEditor node={node} spec={SPEC} capability={{ events: ['onHover'] }} t={t} onPatchNode={onPatch} pageCandidates={[]} />);
    fireEvent.click(screen.getByTestId('g7le-action-slot-onHover-add-picker-toggle'));
    fireEvent.click(screen.getByTestId('g7le-init-action-spec-goToPage'));
    const patched = onPatch.mock.calls[0][0] as EditorNode;
    expect((patched as { actions?: Array<Record<string, unknown>> }).actions?.[0]).toMatchObject({ type: 'mouseenter', handler: 'navigate' });
  });
});

describe('ConditionBuilder', () => {
  const node: EditorNode = { name: 'Div', props: {} };

  it('친화 조건 추가(AND) → onPatchNode(최상위 node.if, 단일 {{ }})', () => {
    const onPatch = vi.fn();
    render(<ConditionBuilder node={node} spec={SPEC} t={t} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-condition-add-and'));
    expect(onPatch).toHaveBeenCalled();
    const patched = onPatch.mock.calls[onPatch.mock.calls.length - 1][0] as EditorNode;
    // if 는 노드 최상위 (props.if 아님 — DynamicRenderer 가 effectiveComponentDef.if 평가)
    expect((patched as { if?: string }).if).toBe('{{ _global?.currentUser?.uuid }}');
    expect((patched as { props?: { if?: unknown } }).props?.if).toBeUndefined();
  });

  it('최상위 if 역해석 → 조건 선택 복원', () => {
    const node2: EditorNode = { name: 'Div', if: '{{ _global?.currentUser?.uuid }}', props: {} };
    render(<ConditionBuilder node={node2} spec={SPEC} t={t} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-condition-clause-0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-condition-preview')).toBeInTheDocument();
  });

  it('legacy props.if 역호환 — 읽어서 복원 후 저장 시 최상위로 이관', () => {
    const onPatch = vi.fn();
    const legacy: EditorNode = { name: 'Div', props: { if: '{{ _global?.currentUser?.uuid }}' } };
    render(<ConditionBuilder node={legacy} spec={SPEC} t={t} onPatchNode={onPatch} />);
    // legacy props.if 를 읽어 절 복원
    expect(screen.getByTestId('g7le-condition-clause-0')).toBeInTheDocument();
    // OR 추가 → 저장 시 최상위 if + props.if 제거
    fireEvent.click(screen.getByTestId('g7le-condition-add-or'));
    const patched = onPatch.mock.calls[onPatch.mock.calls.length - 1][0] as EditorNode;
    expect((patched as { if?: string }).if).toContain('{{');
    expect((patched as { props?: { if?: unknown } }).props?.if).toBeUndefined();
  });

  it('고급식(직접 작성) → 보존 + 원문 표시', () => {
    const node2: EditorNode = { name: 'Div', if: '{{ custom.weird && expr }}', props: {} };
    render(<ConditionBuilder node={node2} spec={SPEC} t={t} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-condition-advanced')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-condition-advanced-expr').textContent).toContain('custom.weird');
  });

  it('tablet scope — base if 가 override 없을 때 상속 노출', () => {
    // base 에 recipe 매칭 조건이 있고 tablet override 가 없으면, tablet 탭에서도 base
    // 조건이 절로 복원돼 노출돼야 한다(빈 빌더로 가려지지 않음 — getEffectiveScopedIf).
    const base: EditorNode = {
      name: 'Div',
      if: '{{ _global?.currentUser?.uuid }}', // isLoggedIn 프리셋
      props: {},
    };
    render(
      <ConditionBuilder
        node={base}
        spec={SPEC}
        t={t}
        onPatchNode={vi.fn()}
        scope={{ colorScheme: 'base', breakpoint: 'tablet' }}
      />,
    );
    // 상속된 base 조건이 절로 복원되어 노출(빈 빌더 아님).
    expect(screen.getByTestId('g7le-condition-clause-0')).toBeInTheDocument();
  });

  it('tablet scope 조건 편집 → responsive.tablet.if 로 기록, base.if 불변', () => {
    const onPatch = vi.fn();
    // 상속된 base 조건(isLoggedIn)이 tablet 탭에 노출된 상태에서 OR 절을 추가하면,
    // 그 변경은 tablet override 로 기록되고 base 는 보존돼야 한다(편집 격리).
    const base: EditorNode = { name: 'Div', if: '{{ _global?.currentUser?.uuid }}', props: {} };
    render(
      <ConditionBuilder
        node={base}
        spec={SPEC}
        t={t}
        onPatchNode={onPatch}
        scope={{ colorScheme: 'base', breakpoint: 'tablet' }}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-condition-add-or'));
    const patched = onPatch.mock.calls[onPatch.mock.calls.length - 1][0] as EditorNode;
    // tablet override 로 기록(상속 base + 추가 절 결합식).
    expect(patched.responsive?.tablet?.if).toContain('{{');
    expect(patched.responsive?.tablet?.if).toContain('_global?.currentUser?.uuid');
    expect(patched.if).toBe('{{ _global?.currentUser?.uuid }}'); // base 불변
  });

  it('tablet scope 는 responsive.tablet.if 에서 역해석 (base.if 무시)', () => {
    const node2: EditorNode = {
      name: 'Div',
      if: '{{ irrelevant }}',
      responsive: { tablet: { if: '{{ _global?.currentUser?.uuid }}' } },
      props: {},
    };
    render(
      <ConditionBuilder
        node={node2}
        spec={SPEC}
        t={t}
        onPatchNode={vi.fn()}
        scope={{ colorScheme: 'base', breakpoint: 'tablet' }}
      />,
    );
    expect(screen.getByTestId('g7le-condition-clause-0')).toBeInTheDocument();
  });
});

describe('FlexEditor', () => {
  const node: EditorNode = { name: 'Flex', props: {} };

  it('container 역할 → 컨테이너 컨트롤 노출', () => {
    render(<FlexEditor node={node} spec={SPEC} capability={{ flexEditor: 'container' }} t={t} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-flex-container-section')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-control-flexDirection')).toBeInTheDocument();
  });

  it('auto + 비-flex DOM → "정렬 박스로 만들기" 버튼', () => {
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ display: 'block' } as CSSStyleDeclaration);
    const el = document.createElement('div');
    render(<FlexEditor node={{ name: 'Div', props: {} }} spec={SPEC} capability={{ flexEditor: 'auto' }} t={t} onPatchNode={vi.fn()} liveElement={el} />);
    expect(screen.getByTestId('g7le-flex-enable')).toBeInTheDocument();
    vi.restoreAllMocks();
  });

  it('make-flex 클릭 → onPatchNode(flex 토큰 적용)', () => {
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ display: 'block' } as CSSStyleDeclaration);
    const onPatch = vi.fn();
    const el = document.createElement('div');
    render(<FlexEditor node={{ name: 'Div', props: {} }} spec={SPEC} capability={{ flexEditor: 'auto' }} t={t} onPatchNode={onPatch} liveElement={el} />);
    fireEvent.click(screen.getByTestId('g7le-flex-enable'));
    const patched = onPatch.mock.calls[0][0] as EditorNode;
    expect((patched.props as { className?: string }).className).toContain('flex');
    vi.restoreAllMocks();
  });

  it('item 역할 → 아이템 컨트롤 섹션', () => {
    render(<FlexEditor node={{ name: 'Card', props: {} }} spec={SPEC} capability={{ flexEditor: 'item' }} t={t} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-flex-item-section')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-control-flexItemGrow')).toBeInTheDocument();
  });

  // §항목A — 노드 파생 판정: 노드가 이미 flex 토큰을 가지면 liveElement(DOM)가
  // stale(block)이어도 컨테이너 컨트롤 + 해제 버튼을 노출한다(make-flex 버튼 아님).
  it('auto + 노드에 flex 토큰 (liveElement stale=block) → 컨테이너 컨트롤 + 해제 버튼', () => {
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ display: 'block' } as CSSStyleDeclaration);
    const staleEl = document.createElement('div'); // 패치 직전 DOM 모사(block)
    render(
      <FlexEditor
        node={{ name: 'Div', props: { className: 'w-full flex' } }}
        spec={SPEC}
        capability={{ flexEditor: 'auto' }}
        t={t}
        onPatchNode={vi.fn()}
        liveElement={staleEl}
      />,
    );
    expect(screen.getByTestId('g7le-flex-container-section')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-flex-disable')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-flex-enable')).not.toBeInTheDocument();
    vi.restoreAllMocks();
  });

  it('정렬 박스 해제 클릭 → onPatchNode(flex 토큰 제거)', () => {
    const onPatch = vi.fn();
    render(
      <FlexEditor
        node={{ name: 'Div', props: { className: 'w-full flex' } }}
        spec={SPEC}
        capability={{ flexEditor: 'auto' }}
        t={t}
        onPatchNode={onPatch}
        liveElement={document.createElement('div')}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-flex-disable'));
    const patched = onPatch.mock.calls[0][0] as EditorNode;
    const cls = (patched.props as { className?: string }).className ?? '';
    expect(cls.split(/\s+/)).not.toContain('flex');
    expect(cls).toContain('w-full');
    vi.restoreAllMocks();
  });

  // flexEnable on/off 2상태. SPEC_ONOFF 의 flexEnable 은 options 보유.
  const SPEC_ONOFF: EditorSpec = {
    ...SPEC,
    controls: {
      ...SPEC.controls,
      flexEnable: {
        label: 'enable',
        widget: 'segmented',
        group: 'flex-enable',
        onValue: 'flex',
        apply: { type: 'classToken', tokens: ['flex'] },
        options: [
          { value: 'flex', apply: { type: 'classToken', tokens: ['flex'] } },
          { value: 'block', apply: { type: 'classToken', tokens: ['block'] } },
        ],
      },
    } as EditorSpec['controls'],
  };

  it('mobile scope + base flex → 모바일 해제 시 responsive.mobile.props.className 에 off 토큰(block) (D7)', () => {
    const onPatch = vi.fn();
    render(
      <FlexEditor
        node={{ name: 'Div', props: { className: 'flex' } }}
        spec={SPEC_ONOFF}
        capability={{ flexEditor: 'auto' }}
        t={t}
        onPatchNode={onPatch}
        scope={{ colorScheme: 'base', breakpoint: 'mobile' }}
      />,
    );
    // mobile scope 에 명시 override 없음 → base flex 상속 미인정(D8) → "정렬 박스로 만들기" 버튼.
    // 클릭하면 mobile scope 에 flex 토큰 적용.
    fireEvent.click(screen.getByTestId('g7le-flex-enable'));
    const patched = onPatch.mock.calls[0][0] as EditorNode;
    expect(patched.responsive?.mobile?.props?.className).toContain('flex');
    expect(patched.props?.className).toBe('flex'); // base 불변
  });

  it('mobile scope flex override 후 해제 → off 토큰(block) 기록(base 상속 차단)', () => {
    const onPatch = vi.fn();
    render(
      <FlexEditor
        node={{
          name: 'Div',
          props: { className: 'flex' },
          responsive: { mobile: { props: { className: 'flex' } } },
        }}
        spec={SPEC_ONOFF}
        capability={{ flexEditor: 'auto' }}
        t={t}
        onPatchNode={onPatch}
        scope={{ colorScheme: 'base', breakpoint: 'mobile' }}
      />,
    );
    // mobile 에 flex override 존재 → 컨테이너 + 해제 버튼
    fireEvent.click(screen.getByTestId('g7le-flex-disable'));
    const patched = onPatch.mock.calls[0][0] as EditorNode;
    // off 옵션(block) 명시 기록 → base 의 flex 를 모바일에서 끔
    expect(patched.responsive?.mobile?.props?.className).toContain('block');
    expect(patched.props?.className).toBe('flex'); // base 불변
  });
});
