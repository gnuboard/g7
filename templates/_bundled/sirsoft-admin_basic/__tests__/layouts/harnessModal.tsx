/**
 * 레이아웃 시험 하네스에 진짜 Modal 을 올리는 도우미 (#869 P1)
 *
 * 확인 창의 바닥 단추가 Modal 고정 하단(`props.footer`)으로 옮겨 갔다. 고정 하단은 Modal 이 자기 렌더러로
 * 그리므로, 가짜 Modal(본문만 그리는 것)로는 단추가 보이지 않고 배선(데이터 컨텍스트·상태 범위)도 확인되지
 * 않는다. 이 도우미는 번들 Modal 을 그대로 쓰고, 하네스 G7Core 에 없는 `getDynamicRenderer` 만 이어 준다.
 */
import React from 'react';
import { Modal } from '../../src/components/composite/Modal';

/** 하네스 G7Core 에 엔진 렌더러 접근자를 잇는다(운영 G7CoreGlobals.initSlotAPI 와 같은 값) */
function ensureDynamicRendererAccessor(): void {
  const g7 = (window as any).G7Core;
  if (g7 && typeof g7.getDynamicRenderer !== 'function') {
    g7.getDynamicRenderer = () => (window as any).__DynamicRenderer ?? null;
  }
}

/**
 * 진짜 Modal 을 감싼 하네스용 부품.
 *
 * @param testId 감싸개 data-testid — 종전 가짜 Modal 이 주던 이름을 그대로 쓴다
 * @param forceOpen 하네스의 열림 판정과 무관하게 연다(종전 가짜 Modal 이 늘 그리던 시험용)
 */
export function makeHarnessModal(options: { testId?: (props: any) => string; forceOpen?: boolean } = {}): React.FC<any> {
  const HarnessModal: React.FC<any> = (props) => {
    ensureDynamicRendererAccessor();
    const isOpen = options.forceOpen ? true : !!props.isOpen;
    if (!isOpen) return null;
    return (
      <div data-testid={options.testId?.(props)}>
        <Modal {...props} isOpen onClose={props.onClose ?? (() => {})} />
      </div>
    );
  };
  return HarnessModal;
}
