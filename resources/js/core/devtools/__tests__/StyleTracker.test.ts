/**
 * @file StyleTracker.test.ts
 * @description DevTools 스타일 추적기의 「폭 조건 없이 hidden」 안내 — 폭에 따라 보이고 숨기는 것은 엔진 `responsive`
 * (`<responsive on="…">`)로 쓰는 규정이라(docs/frontend/responsive-layout.md 2절), 안내가 Tailwind 폭 접두(`sm:` `md:` …)나
 * 이 프로젝트에 없는 변형(`desktop:flex`)을 권하지 않는다. (매니페스트 devtools-style-tracker-guidance.yaml)
 *
 * @vitest-environment jsdom
 */
import { describe, it, expect, vi } from 'vitest';
import { StyleTracker } from '../StyleTracker';

/** 지금 레이아웃 — 노드 정의(responsive 덮어쓰기 유무)를 id 로 찾는다 */
const layoutJson = {
  components: [
    { id: 'plain', type: 'basic', name: 'Div', props: { className: 'hidden' } },
    {
      id: 'wrap', type: 'basic', name: 'Div', props: { className: 'flex' },
      children: [
        { id: 'engine', type: 'basic', name: 'Div', props: { className: 'hidden' }, responsive: { desktop: { props: { className: 'block' } } } },
        { id: 'fake_variant', type: 'basic', name: 'Div', props: { className: 'hidden portable:block desktop:flex' } },
      ],
    },
  ],
  modals: [{ id: 'modal_body', type: 'basic', name: 'Div', props: { className: 'hidden' }, responsive: { '-640': { props: { className: 'block' } } } }],
};

/** 감지 결과만 모으는 DevTools 대역을 끼운 추적기 */
const trackerWithIssues = (layout: unknown = { layoutJson }) => {
  const issues: Array<Record<string, unknown>> = [];
  const tracker = StyleTracker.getInstance() as unknown as {
    devTools: { addStyleIssue: (issue: Record<string, unknown>) => void; getCurrentLayout: () => unknown };
    detectIssues: (id: string, name: string, element: HTMLElement, classes: string[], styles: Record<string, string>) => void;
  };
  tracker.devTools = { addStyleIssue: vi.fn((issue) => issues.push(issue)), getCurrentLayout: () => layout };
  return { tracker, issues };
};

const STYLES = { display: 'block', visibility: 'visible', opacity: '1', width: '10px', height: '10px', zIndex: 'auto', position: 'static', backgroundColor: '', color: '' };
/** 그 노드의 폭 조건 안내만 */
const responsiveIssues = (id: string, classes: string[], layout?: unknown) => {
  const { tracker, issues } = trackerWithIssues(layout);
  tracker.detectIssues(id, 'Div', document.createElement('div'), classes, STYLES);
  return issues.filter((item) => item.type === 'responsive-issue');
};

describe('StyleTracker 반응형 안내', () => {
  // @scenario guidance=hidden_without_width_condition
  // @effects devtools_guidance_recommends_engine_responsive
  it('폭 조건 없이 hidden 이면 엔진 responsive 를 권하고 Tailwind 폭 접두는 권하지 않는다', () => {
    const issue = responsiveIssues('plain', ['hidden'])[0];
    expect(issue).toBeDefined();
    const suggestion = String(issue!.suggestion);
    expect(suggestion).toContain('responsive');
    expect(suggestion).toContain('<responsive on=');
    // 구간에서 바뀐 클래스만 덧붙이는 class-merge 를 권한다(engine-v1.98.0 — 종전 「className 통째 교체」 안내 대체)
    expect(suggestion).toContain('class-merge=');
    expect(suggestion).not.toMatch(/(?:^|[\s,(])(?:sm|md|lg|xl|2xl|desktop|portable|mobile|tablet):[a-z]/);
  });

  // @scenario guidance=hidden_engine_responsive_detection
  // @effects devtools_guidance_recommends_engine_responsive
  it('노드 정의에 엔진 responsive 덮어쓰기가 있으면 폭 조건이 있는 것으로 보고 알리지 않는다 — 모달 안 노드도 찾는다', () => {
    expect(responsiveIssues('engine', ['hidden'])).toHaveLength(0);
    expect(responsiveIssues('modal_body', ['hidden'])).toHaveLength(0);
  });

  // @scenario guidance=hidden_engine_responsive_detection
  // @effects devtools_guidance_recommends_engine_responsive
  it('이 프로젝트에 없는 변형(portable:block, desktop:flex)은 폭 조건으로 보지 않는다', () => {
    expect(responsiveIssues('fake_variant', ['hidden', 'portable:block', 'desktop:flex'])).toHaveLength(1);
  });

  // @scenario guidance=hidden_engine_responsive_detection
  // @effects devtools_guidance_recommends_engine_responsive
  it('노드 정의를 찾을 수 없으면(레이아웃 정보 없음, 반복 사본 id, 레이아웃 밖 요소) 판정하지 않는다 — 오판을 내지 않는다', () => {
    expect(responsiveIssues('not_in_layout', ['hidden'])).toHaveLength(0);
    expect(responsiveIssues('plain', ['hidden'], null)).toHaveLength(0);
  });
});
