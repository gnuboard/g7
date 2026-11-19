// e2e:allow 로딩 막대가 <style> 요소를 만들지 않고 keyframes 가 템플릿 CSS 에 있는지 잠근다 — 정책을 강제한 브라우저에서의 위반 0건과 띠 움직임은 출시 전 화면 재실측(엄격한 스타일 정책 강제 + 화면 이동)이 덮는다
/**
 * PageTransitionIndicator — `<style>` 요소 없음 (공개 #163, 엄격한 스타일 정책 호환)
 *
 * 회귀 배경: 로딩 막대가 `@keyframes loading` 을 JSX `<style>` 요소로 실어, 운영자가 `style-src 'self'` 를 걸면
 * 그 요소가 막히고(위반 보고) 띠가 멈췄다. keyframes 를 템플릿 `src/styles/main.css` 최상위로 옮겼다.
 *
 * @scenario-manifest templates/_bundled/sirsoft-admin_basic/tests/scenarios/content-inline-style-deferral.yaml
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { render } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { PageTransitionIndicator } from '../PageTransitionIndicator';

/** vitest 는 템플릿 디렉터리에서 실행한다 */
const TEMPLATE_ROOT = process.cwd();
const read = (path: string): string => readFileSync(resolve(TEMPLATE_ROOT, path), 'utf8');

/**
 * 주석을 걷어낸 소스.
 *
 * @param source 원본
 * @returns 블록·줄 주석을 뺀 소스
 */
const stripComments = (source: string): string => source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

describe('페이지 전환 로딩 막대 — <style> 요소 없음', () => {
  beforeEach(() => {
    (window as any).G7Core = {
      t: (key: string) => key,
      TransitionManager: { getIsPending: () => true, subscribe: () => () => {} },
    };
  });

  afterEach(() => {
    delete (window as any).G7Core;
  });

  /**
   * @scenario surface=page_transition_indicator, core=any
   * @effects no_style_element_rendered
   */
  it('전환 중 렌더에 <style> 요소가 없고 띠는 그대로 그린다', () => {
    const before = document.querySelectorAll('style').length;
    const { container } = render(<PageTransitionIndicator />);

    expect(container.querySelector('[role="progressbar"]')).not.toBeNull();
    expect(container.querySelector('style')).toBeNull();
    expect(document.querySelectorAll('style').length).toBe(before);
    // 띠는 keyframes 이름 `loading` 에 의존한다
    const bar = [...container.querySelectorAll('div')].find((d) => d.className.includes('animate-[loading_'));
    expect(bar).toBeDefined();
    expect((bar as HTMLElement).style.animation).toContain('loading');
  });

  /**
   * @scenario surface=page_transition_indicator, core=any
   * @effects no_style_element_rendered
   */
  it('컴포넌트 소스에 <style 이 없다', () => {
    const source = stripComments(read('src/components/composite/PageTransitionIndicator.tsx'));

    expect(source).not.toMatch(/<style/i);
    expect(source).not.toContain('@keyframes');
  });

  /**
   * @scenario surface=page_transition_indicator, core=any
   * @effects keyframes_in_template_css
   */
  it('`@keyframes loading` 이 main.css 최상위(@layer 밖)에 있다', () => {
    const css = stripComments(read('src/styles/main.css'));

    // 줄 맨 앞에서 시작한다 = 다른 블록(@layer 등) 안에 들여 쓰이지 않았다
    expect(css).toMatch(/^@keyframes loading\s*\{/m);
    const block = css.slice(css.search(/^@keyframes loading\s*\{/m));
    expect(block).toContain('translateX(-100%)');
    expect(block).toContain('translateX(300%)');
  });
});
