/**
 * @file orderListPresetSaveFooter.test.tsx
 * @description 주문 목록 프리셋 저장 창 — 바닥 단추가 Modal 고정 하단에서 본문 입력을 따라간다
 *
 * 저장·취소 단추를 본문 안 `ecommerce-modal-footer-buttons` 줄로 그리던 창이라 640px 이하에서 단추가
 * 세로로 쌓이지 않았다. 단추를 Modal 고정 하단(`<footer>` → props.footer)으로 옮겼다. 고정 하단은 Modal 이
 * 자기 렌더러로 그리므로, 본문 입력이 쓰는 이 창의 `_local.presetName` 을 고정 하단 단추가 읽어야 한다 —
 * 종전 Modal 은 고정 하단을 빈 데이터 컨텍스트·자기 상태 범위로 그려 이름을 쳐도 저장 단추가 풀리지 않았다.
 *
 * 진짜 관리자 Modal 과 엔진으로 그린다(가짜 Modal 은 고정 하단을 그리지 않아 배선을 잴 수 없다).
 *
 * 조합 요약(마커 아님, 평문): modal=order_list_preset_save, footer=props_footer, typed_name=yes|no
 * 효과 요약(마커 아님, 평문): preset_save_footer_follows_body_input, preset_save_footer_closes_modal
 */
import React from 'react';
import { describe, it, expect, afterEach } from 'vitest';
import { fireEvent, screen } from '@testing-library/react';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { Modal } from '../../../../../../../templates/_bundled/sirsoft-admin_basic/src/components/composite/Modal';
import presetModal from '../../../layouts/admin/partials/admin_ecommerce_order_list/_modal_preset_save.json';

const TestDiv: React.FC<any> = ({ className, children }) => <div className={className}>{children}</div>;
const TestSpan: React.FC<any> = ({ children, text }) => <span>{children ?? text}</span>;
const TestIcon: React.FC<any> = ({ name }) => <i data-icon={name} />;
const TestButton: React.FC<any> = ({ onClick, disabled, className, children, text }) => (
  <button type="button" onClick={onClick} disabled={disabled} className={className}>
    {children ?? text}
  </button>
);
const TestInput: React.FC<any> = ({ value, onChange, onInput, placeholder }) => (
  <input data-testid="preset-name" placeholder={placeholder} value={value ?? ''} onChange={onChange} onInput={onInput} />
);
/** 진짜 Modal — 하네스 G7Core 에 없는 엔진 렌더러 접근자만 잇는다(운영 G7CoreGlobals.initSlotAPI 와 같은 값) */
const HarnessModal: React.FC<any> = (props) => {
  const g7 = (window as any).G7Core;
  if (g7 && typeof g7.getDynamicRenderer !== 'function') {
    g7.getDynamicRenderer = () => (window as any).__DynamicRenderer ?? null;
  }
  return props.isOpen ? <Modal {...props} onClose={props.onClose ?? (() => {})} /> : null;
};

function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const basic = (name: string, component: React.FC<any>) => ({ component, metadata: { name, type: 'basic' } });
  (registry as any).registry = {
    Div: basic('Div', TestDiv),
    Span: basic('Span', TestSpan),
    Icon: basic('Icon', TestIcon),
    Button: basic('Button', TestButton),
    Input: basic('Input', TestInput),
    Modal: { component: HarnessModal, metadata: { name: 'Modal', type: 'composite' } },
    Fragment: { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

/** 저장 단추는 관리자 주 버튼 사본 의미 클래스(`ecommerce-btn-primary`)를 쓴다 — 파랑 인라인에서 바뀌었다 */
const isPrimary = (b: Element) => b.className.split(/\s+/).includes('ecommerce-btn-primary');
const saveButton = () =>
  Array.from(document.querySelectorAll('.modal-footer-bar button')).find(isPrimary) as HTMLButtonElement | undefined;
const cancelButton = () =>
  Array.from(document.querySelectorAll('.modal-footer-bar button')).find((b) => !isPrimary(b)) as HTMLButtonElement | undefined;

describe('주문 목록 프리셋 저장 창 — 고정 하단', () => {
  let testUtils: ReturnType<typeof createLayoutTest> | null = null;

  afterEach(() => {
    testUtils?.cleanup();
    testUtils = null;
  });

  async function renderOpen() {
    testUtils = createLayoutTest(
      {
        version: '1.0.0',
        layout_name: 'order_list_preset_save_footer_test',
        data_sources: [],
        initGlobal: { isSaving: false, savingPresetConditions: {} },
        modals: [presetModal],
        components: [{ id: 'main', type: 'basic', name: 'Div', children: [] }],
      } as any,
      { componentRegistry: setupRegistry(), includeModals: true, translations: {}, locale: 'ko' }
    );
    await testUtils.render();
    testUtils.openModal('modal_preset_save');
    await testUtils.rerender();
  }

  /**
   * @scenario modal=order_list_preset_save, footer=props_footer, typed_name=no
   * @effects preset_save_footer_follows_body_input
   */
  it('저장·취소 단추가 본문이 아니라 고정 하단(.modal-footer-bar)에 그려진다', async () => {
    await renderOpen();

    expect((presetModal as any).props.footer).toHaveLength(2);
    expect(saveButton(), '고정 하단에 저장 단추가 없습니다').toBeTruthy();
    expect(cancelButton()).toBeTruthy();
    expect(document.querySelector('.modal-body-content')?.querySelectorAll('button').length ?? 0).toBe(0);
  });

  /**
   * @scenario modal=order_list_preset_save, footer=props_footer, typed_name=yes
   * @effects preset_save_footer_follows_body_input
   */
  it('이름을 치기 전에는 저장 단추가 잠기고, 본문에 이름을 치면 고정 하단 저장 단추가 풀린다', async () => {
    await renderOpen();
    expect(saveButton()!.disabled).toBe(true);

    fireEvent.input(screen.getByTestId('preset-name'), { target: { value: '자주 쓰는 조건' } });
    await testUtils!.rerender();

    expect(saveButton()!.disabled, '고정 하단이 본문 입력(_local.presetName)을 읽지 못했습니다').toBe(false);
  });

  /**
   * @scenario modal=order_list_preset_save, footer=props_footer, typed_name=no
   * @effects preset_save_footer_closes_modal
   */
  it('취소 단추는 창을 닫는다', async () => {
    await renderOpen();
    expect(testUtils!.getModalStack()).toContain('modal_preset_save');

    fireEvent.click(cancelButton()!);
    await testUtils!.rerender();

    expect(testUtils!.getModalStack()).not.toContain('modal_preset_save');
  });
});
