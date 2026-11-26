/**
 * @file DevToolsTabsDataShape.test.tsx
 * @description DevTools 탭이 G7DevToolsCore 가 실제로 넣는 값 모양대로 읽는다.
 * Sequence 탭은 없는 필드(id, totalSequences, 'completed' 상태, stateChanges)를 읽어 총 실행 수가 비고, 성공한 실행도 ⏳ 로 보였고,
 * 목록을 눌러도 상세가 열리지 않았다. Stale Closure 탭은 statePath, capturedValue, currentValue 를 경고 최상위에서 읽어
 * 상태 경로와 두 값이 비었다(타입 검사로 드러남). 탭에는 실제 코어가 기록한 값을 그대로 넣는다.
 * (매니페스트 devtools-tabs-data-shape.yaml)
 *
 * @vitest-environment jsdom
 */
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { G7DevToolsCore } from '../G7DevToolsCore';
import { ActionsTab } from '../ui/tabs/ActionsTab';
import { StaleClosureTab } from '../ui/tabs/StaleClosureTab';
import { NamedActionsTab } from '../ui/tabs/NamedActionsTab';

/** 싱글톤을 비우고 디버그 모드로 켠 코어 */
const freshCore = (): G7DevToolsCore => {
  (G7DevToolsCore as unknown as { instance: unknown }).instance = undefined;
  (window as unknown as { G7Core: unknown }).G7Core = {
    state: { get: () => ({ settings: { advanced: { debug_mode: true } } }) },
  };
  const core = G7DevToolsCore.getInstance();
  core.initialize();
  return core;
};

describe('DevTools 탭 데이터 모양', () => {
  let core: G7DevToolsCore;

  beforeEach(() => {
    core = freshCore();
  });

  afterEach(() => {
    cleanup();
    delete (window as unknown as { G7Core?: unknown }).G7Core;
  });

  // @scenario tab=sequence
  // @effects devtools_tab_reads_core_shape
  it('Sequence 탭이 총 실행 수와 성공 표시를 보이고, 목록을 누르면 상세와 바뀐 상태 경로를 연다', () => {
    const sequenceId = core.startSequenceExecution({ eventType: 'click' });
    core.captureSequenceActionBefore(sequenceId, 0, 'setState', { target: 'local' }, { _global: {}, _local: { count: 0 } });
    core.captureSequenceActionAfter(sequenceId, 0, { _global: { theme: 'dark' }, _local: { count: 1 } }, 3);
    core.endSequenceExecution(sequenceId);

    render(<ActionsTab actions={[]} devTools={core} />);
    fireEvent.click(screen.getByText(/Sequence \(/));

    const totalLabel = screen.getByText('총 실행');
    expect(totalLabel.previousElementSibling?.textContent).toBe('1');

    const row = screen.getByText('1개 액션').closest('.g7dt-list-item') as HTMLElement;
    expect(row.textContent).toContain('✅');

    fireEvent.click(row);
    expect(screen.getByText(/Sequence 상세/)).toBeTruthy();
    expect(screen.getByText('_global.theme')).toBeTruthy();
    expect(screen.getByText('_local.count')).toBeTruthy();
  });

  // @scenario tab=stale_closure
  // @effects devtools_tab_reads_core_shape
  it('Stale Closure 탭이 상태 경로와 캡처 값, 현재 값을 보인다', () => {
    core.trackStaleClosureWarning({
      type: 'async-state-capture',
      location: 'submitHandler',
      capturedPath: '_local.form.name',
      capturedValue: 'captured-before',
      capturedAt: Date.now() - 500,
      currentValue: 'current-after',
    });

    render(<StaleClosureTab devTools={core} />);

    const listPath = screen.getByText('_local.form.name');
    fireEvent.click(listPath.closest('.g7dt-list-item') as HTMLElement);

    expect(screen.getAllByText('_local.form.name').length).toBe(2);
    expect(screen.getByText('"captured-before"')).toBeTruthy();
    expect(screen.getByText('"current-after"')).toBeTruthy();
  });

  // 엔진은 type 을 적지 않은 액션(event 만 적은 진입점, 풀리지 않은 actionRef)을 type 없이 기록한다.
  // 필터 글자를 넣으면 탭이 a.type.toLowerCase() 에서 TypeError 로 깨졌다(타입 검사로 드러남).
  // @scenario tab=actions_filter
  // @effects devtools_filter_tolerates_missing_field
  it('Actions 탭 필터가 type 없는 액션이 섞여도 깨지지 않고 맞는 액션만 남긴다', () => {
    core.logAction({ id: 'no-type', type: undefined, startTime: 1, status: 'success' });
    core.logAction({ id: 'typed', type: 'setState', startTime: 2, status: 'success' });

    render(<ActionsTab actions={core.getActionHistory()} devTools={core} />);
    fireEvent.change(screen.getByPlaceholderText('액션 타입 또는 상태로 필터...'), { target: { value: 'setstate' } });

    expect(screen.getAllByText('setState').length).toBeGreaterThan(0);
  });

  // 핸들러 없이 actionRef 만 적은 정의는 resolvedHandler 없이 기록된다. actionRef 이름이 맞지 않는 기록에서
  // log.resolvedHandler.toLowerCase() 가 TypeError 로 탭을 깨뜨렸다.
  // @scenario tab=named_action_refs_filter
  // @effects devtools_filter_tolerates_missing_field
  it('Named Actions 참조 이력 필터가 핸들러 없는 기록이 섞여도 깨지지 않고 맞는 기록만 남긴다', () => {
    core.trackNamedActionRef({ actionRefName: 'chainOnly', resolvedHandler: undefined, timestamp: 1 });
    core.trackNamedActionRef({ actionRefName: 'saveForm', resolvedHandler: 'apiCall', timestamp: 2 });

    render(<NamedActionsTab devTools={core} />);
    fireEvent.click(screen.getByText(/참조 이력 \(/));
    fireEvent.change(screen.getByPlaceholderText('actionRef 검색...'), { target: { value: 'apicall' } });

    expect(screen.getByText('saveForm')).toBeTruthy();
    expect(screen.queryByText('chainOnly')).toBeNull();
  });
});
