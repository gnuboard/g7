/**
 * Modal — titlePrefix/titleSuffix/footer 렌더 환경 (이슈 #141 D9)
 *
 * footer 의 DynamicRenderer 는 호스트 레지스트리·dispatcher 로 그려졌고 편집 모드도 몰라, 편집기
 * 미리보기에서 버튼 액션이 바인딩됐다. 받은 렌더 환경을 먼저 쓴다. `footer` 배열은 편집기 경로 문법 밖이라
 * `componentPath` 는 넘기지 않는다(선택 불가 현 상태 유지).
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render } from '@testing-library/react';
import { Modal } from '../Modal';

describe('Modal — footer 렌더 환경 (#141)', () => {
  let originalG7Core: any;
  let received: any[];

  const baseProps = { isOpen: true, onClose: vi.fn(), title: '삭제' };

  /**
   * DynamicRenderer 대역을 가진 G7Core 를 세운다.
   *
   * @param host 호스트 getXxx() 가 돌려줄 값
   */
  const install = (host: Record<string, any>) => {
    (window as any).G7Core = {
      getDynamicRenderer: () => (props: any) => {
        received.push(props);
        return <button>{props.componentDef.text}</button>;
      },
      getComponentRegistry: () => host,
      getDataBindingEngine: () => host,
      getTranslationEngine: () => host,
      getActionDispatcher: () => host,
    };
  };

  beforeEach(() => {
    originalG7Core = (window as any).G7Core;
    received = [];
  });

  afterEach(() => {
    (window as any).G7Core = originalG7Core;
  });

  /**
   * @scenario component=Modal, render_path=footer, edit_mode=true
   * @effects preview_slot_and_footer_use_render_env
   */
  it('footer 는 렌더 환경의 레지스트리·엔진·dispatcher·isEditMode 로 그리고 componentPath 는 넘기지 않는다', () => {
    install({ id: 'host' });
    const env = {
      componentRegistry: { id: 'envRegistry' },
      bindingEngine: { id: 'envBE' },
      translationEngine: { id: 'envTE' },
      actionDispatcher: { id: 'envAD' },
      isEditMode: true,
    };

    render(
      <Modal
        {...baseProps}
        footer={[{ type: 'basic', name: 'Button', text: '확인' }]}
        __componentContext={{ renderEnvRef: { current: env } }}
      />
    );

    expect(received).toHaveLength(1);
    expect(received[0].registry).toBe(env.componentRegistry);
    expect(received[0].bindingEngine).toBe(env.bindingEngine);
    expect(received[0].translationEngine).toBe(env.translationEngine);
    expect(received[0].actionDispatcher).toBe(env.actionDispatcher);
    expect(received[0].isEditMode).toBe(true);
    expect(received[0]).not.toHaveProperty('componentPath');
  });

  /**
   * @scenario component=Modal, render_path=footer, render_env=absent
   * @effects preview_render_env_falls_back_to_host_outside_preview
   */
  it('렌더 환경이 없으면 호스트 값으로 그린다 (편집기 밖 무변화)', () => {
    const host = { id: 'host' };
    install(host);

    render(<Modal {...baseProps} footer={[{ type: 'basic', name: 'Button', text: '확인' }]} />);

    expect(received[0].registry).toBe(host);
    expect(received[0].actionDispatcher).toBe(host);
    expect(received[0].isEditMode).toBeFalsy();
  });
});
