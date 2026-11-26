/**
 * @file StyleTracker.issueId.test.ts
 * @description StyleTracker 가 감지한 스타일 이슈도 G7DevToolsCore 에 id 를 가진 채 쌓인다.
 * StyleTracker 는 id 없는 이슈(Omit<StyleIssue, 'id'>)를 넘기는데 코어는 받은 그대로 저장해 id 가 비었다(타입 검사로 드러남).
 * (매니페스트 devtools-style-issue-id.yaml)
 *
 * @vitest-environment jsdom
 */
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { G7DevToolsCore } from '../G7DevToolsCore';
import { StyleTracker } from '../StyleTracker';

/** 싱글톤을 비우고 디버그 모드로 켠 코어와 추적기를 만든다 */
const freshTracker = (): { core: G7DevToolsCore; tracker: StyleTracker } => {
  (G7DevToolsCore as unknown as { instance: unknown }).instance = undefined;
  (StyleTracker as unknown as { instance: unknown }).instance = null;
  (window as unknown as { G7Core: unknown }).G7Core = {
    state: { get: () => ({ settings: { advanced: { debug_mode: true } } }) },
  };
  const core = G7DevToolsCore.getInstance();
  core.initialize();
  const tracker = StyleTracker.getInstance();
  tracker.enable();
  return { core, tracker };
};

describe('StyleTracker 스타일 이슈 id', () => {
  let tracker: StyleTracker;
  let core: G7DevToolsCore;

  beforeEach(() => {
    ({ core, tracker } = freshTracker());
  });

  afterEach(() => {
    tracker.disable();
    delete (window as unknown as { G7Core?: unknown }).G7Core;
  });

  // @scenario source=style_tracker
  // @effects devtools_style_issue_has_id
  it('추적기가 감지한 이슈(다크 모드 누락, className)도 id 를 가진다', () => {
    const element = document.createElement('div');
    element.className = 'bg-white';
    document.body.appendChild(element);

    tracker.trackComponent('comp-dark', 'Div', element);

    const issue = core
      .getStyleValidationInfo()
      .issues.find((item) => item.componentId === 'comp-dark' && item.property === 'className');
    expect(issue).toBeDefined();
    expect(typeof issue!.id).toBe('string');
    expect(issue!.id.length).toBeGreaterThan(0);

    element.remove();
  });

  // @scenario source=core
  // @effects devtools_style_issue_has_id
  it('코어가 직접 감지한 이슈(다크 모드 누락, background-color)는 원래대로 id 를 가진다', () => {
    const element = document.createElement('div');
    element.className = 'bg-white';
    document.body.appendChild(element);

    tracker.trackComponent('comp-core', 'Div', element);

    const issue = core
      .getStyleValidationInfo()
      .issues.find((item) => item.componentId === 'comp-core' && item.property === 'background-color');
    expect(issue).toBeDefined();
    expect(typeof issue!.id).toBe('string');

    element.remove();
  });
});
