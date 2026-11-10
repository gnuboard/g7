/**
 * 사용자 템플릿 Modal — 고정 하단(`footer`) 표면 (#869, 설계 확정 2026-11-08)
 *
 * 종전 사용자 Modal 은 `footer` 를 구조분해하지 않아 `<footer>` 로 적은 단추가 조용히 사라졌고, 그래서 사용자 확인 창은
 * 단추를 본문 안 `justify-end` 줄로 그려 640px 이하에서 세로로 쌓이지 않았다. 관리자 Modal 과 같은 방식으로
 * 고정 하단을 그린다 — 본문 스크롤 밖, 본문 자식과 같은 데이터 컨텍스트·상태 범위(엔진이 넘기는
 * `__componentContext.parentDataContext`·`__componentContext`), 렌더 환경 우선, 640px 이하(렌더 폭)에서 세로 쌓기.
 */
import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Modal } from '../Modal';

const baseProps = { isOpen: true, onClose: vi.fn(), title: '확인', children: <div>본문</div> };

/** 넘어온 componentDef 의 text 를 그리고 props 를 기록하는 가짜 엔진 렌더러 */
function installFakeRenderer(width = 1024) {
  const original = (window as any).G7Core;
  const seen: any[] = [];
  (window as any).G7Core = {
    getDynamicRenderer: () => (props: any) => {
      seen.push(props);
      return <button className="btn">{props.componentDef.text}</button>;
    },
    getComponentRegistry: () => 'host-registry',
    getDataBindingEngine: () => ({}),
    getTranslationEngine: () => ({}),
    getActionDispatcher: () => 'host-dispatcher',
    useResponsive: () => ({ width }),
  };
  return { seen, restore: () => { (window as any).G7Core = original; } };
}

describe('Modal (sirsoft-basic) — footer', () => {
  let restore: (() => void) | null = null;
  afterEach(() => {
    restore?.();
    restore = null;
  });

  /**
   * 고정 하단 단추가 조건으로 모두 빠지면 바닥 줄(테두리와 여백)도 보이지 않는다(#869 C1, 관리자 Modal 과 같은 규칙).
   */
  // @scenario area=footer, width=desktop
  // @effects user_modal_footer_hidden_when_all_buttons_filtered
  it('고정 하단 단추가 모두 그려지지 않으면 바닥 줄이 비고 숨김 규칙을 갖는다', () => {
    const original = (window as any).G7Core;
    (window as any).G7Core = {
      getDynamicRenderer: () => () => null,
      getComponentRegistry: () => ({}),
      getDataBindingEngine: () => ({}),
      getTranslationEngine: () => ({}),
      getActionDispatcher: () => ({}),
      useResponsive: () => ({ width: 1024 }),
    };
    restore = () => { (window as any).G7Core = original; };

    render(<Modal {...baseProps} footer={[{ type: 'basic', name: 'Button', text: '숨은 단추', if: '{{false}}' }]} />);

    const bar = document.querySelector('.modal-footer-bar') as HTMLElement;
    expect(bar).not.toBeNull();
    expect(bar.matches(':empty')).toBe(true);
    expect(bar.className.split(/\s+/), '빈 바닥 줄을 숨기는 규칙이 없습니다').toContain('empty:hidden');
  });

  // @scenario area=footer, width=desktop
  // @effects user_modal_footer_rendered_outside_scroll_area
  it('footer 단추를 본문 스크롤 영역 밖 고정 하단에 그린다', () => {
    const fake = installFakeRenderer();
    restore = fake.restore;
    render(<Modal {...baseProps} footer={[{ type: 'basic', name: 'Button', text: '취소' }, { type: 'basic', name: 'Button', text: '확인' }] as any} />);

    const bar = document.querySelector('.modal-footer-bar');
    expect(bar, '고정 하단이 그려지지 않았습니다 — 단추가 조용히 사라집니다').not.toBeNull();
    expect(bar!.textContent).toContain('취소');
    expect(bar!.textContent).toContain('확인');
    expect(screen.getByText('본문').parentElement!.textContent).not.toContain('확인');
  });

  // @scenario area=footer, width=desktop
  // @effects user_modal_footer_reads_modal_node_context
  it('footer 는 Modal 노드의 데이터 컨텍스트·컴포넌트 컨텍스트와 렌더 환경으로 그린다', () => {
    const fake = installFakeRenderer();
    restore = fake.restore;
    const nodeContext = { _global: { isSubmitting: true }, _local: { form: { reason: '방금 친 값' } } };
    const componentContext = {
      state: {},
      setState: vi.fn(),
      parentDataContext: nodeContext,
      renderEnvRef: { current: { componentRegistry: 'env-registry', actionDispatcher: 'env-dispatcher', isEditMode: true } },
    };
    render(<Modal {...baseProps} footer={[{ type: 'basic', name: 'Button', text: '확인' }] as any} __componentContext={componentContext as any} />);

    expect(fake.seen.length).toBeGreaterThan(0);
    expect(fake.seen[0].dataContext).toBe(nodeContext);
    expect(fake.seen[0].parentComponentContext).toBe(componentContext);
    expect(fake.seen[0].registry).toBe('env-registry');
    expect(fake.seen[0].actionDispatcher).toBe('env-dispatcher');
    expect(fake.seen[0].isEditMode).toBe(true);
    // 컴포넌트 컨텍스트에 노드 경로가 없으면(편집기 밖) componentPath 를 넘기지 않는다
    expect(fake.seen[0].componentPath).toBeUndefined();
  });

  // @scenario area=footer, width=desktop
  // @effects user_modal_footer_items_carry_editor_path
  it('노드 경로가 있으면(편집기 미리보기) footer 단추마다 `${경로}.props.footer.${i}` 편집 경로를 넘긴다', () => {
    const fake = installFakeRenderer();
    restore = fake.restore;
    const componentContext = {
      componentPath: '2.children.0',
      renderEnvRef: { current: { isEditMode: true } },
    };
    render(
      <Modal
        {...baseProps}
        footer={[{ type: 'basic', name: 'Button', text: '취소' }, { type: 'basic', name: 'Button', text: '확인' }] as any}
        __componentContext={componentContext as any}
      />,
    );
    expect(fake.seen.map((p) => p.componentPath)).toEqual(['2.children.0.props.footer.0', '2.children.0.props.footer.1']);
  });

  // @scenario area=footer, width=narrow
  // @effects user_modal_footer_stacks_at_narrow_width
  it('렌더 폭 640px 이하에서는 단추를 세로로 쌓고 폭을 채우며, 641px 부터는 가로로 둔다', () => {
    let fake = installFakeRenderer(640);
    restore = fake.restore;
    const { unmount } = render(<Modal {...baseProps} footer={[{ type: 'basic', name: 'Button', text: '확인' }] as any} />);
    const narrow = document.querySelector('.modal-footer-bar')!.className.split(/\s+/);
    expect(narrow).toEqual(expect.arrayContaining(['flex-col', 'gap-2', '[&_button]:w-full']));
    unmount();
    fake.restore();

    fake = installFakeRenderer(641);
    restore = fake.restore;
    render(<Modal {...baseProps} footer={[{ type: 'basic', name: 'Button', text: '확인' }] as any} />);
    expect(document.querySelector('.modal-footer-bar')!.className.split(/\s+/)).not.toContain('flex-col');
  });

  // @scenario area=footer, width=desktop
  // @effects user_modal_footer_rendered_outside_scroll_area
  it('footer 가 없거나 비었거나 G7Core 가 없으면 하단 영역을 그리지 않고 깨지지도 않는다', () => {
    const { rerender } = render(<Modal {...baseProps} />);
    expect(document.querySelector('.modal-footer-bar')).toBeNull();
    rerender(<Modal {...baseProps} footer={[]} />);
    expect(document.querySelector('.modal-footer-bar')).toBeNull();

    const original = (window as any).G7Core;
    delete (window as any).G7Core;
    expect(() => rerender(<Modal {...baseProps} footer={[{ type: 'basic', name: 'Button', text: '확인' }] as any} />)).not.toThrow();
    expect(screen.getByText('본문')).toBeInTheDocument();
    (window as any).G7Core = original;
  });
});
