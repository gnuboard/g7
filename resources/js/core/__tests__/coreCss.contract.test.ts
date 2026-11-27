/**
 * 코어 정적 CSS 계약 — 셸에 실리는 `g7-core.css` 가 코어 런타임이 기대는 규칙을 담고 전역 선택자를 갖지 않는다 (공개 #163)
 *
 * - `@keyframes g7-spin` — 기본 스피너와 번들 템플릿 `PageLoading`(`animation: 'g7-spin …'`)이 이 이름에 기댄다.
 *   종전에는 스피너 덮개가 `<style>` 로 넣었다.
 * - `#g7-shell-stall` 숨김 규칙 — 없으면 정상 화면 아래에 「화면을 불러오지 못했습니다」 안내가 늘 보인다.
 * - 전역 선택자(`*`·`html`·`body`)·`@layer` 금지 — 템플릿 CSS 와 함께 실리므로 템플릿 여백이 무너진다.
 *
 * [case:network-strict-style-csp-inline-style-blocked]
 *
 * @scenario-manifest tests/scenarios/strict-style-csp-compat.yaml
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const read = (name: string): string => readFileSync(resolve(__dirname, '../../../../public/assets/core/css', name), 'utf8');
const stripComments = (css: string): string => css.replace(/\/\*[\s\S]*?\*\//g, '');

describe('코어 정적 CSS 계약', () => {
  const core = stripComments(read('g7-core.css'));

  /**
   * @scenario case=core_css_contract
   * @effects core_css_has_runtime_rules
   */
  it('g7-core.css 가 런타임이 기대는 규칙을 담는다', () => {
    for (const needle of [
      '@keyframes g7-spin{to{transform:rotate(360deg)}}',
      '@keyframes g7ShellStall',
      'html.g7-shell-ok #g7-shell-stall{display:none}',
      'html:not(.g7-shell-ok) #g7-shell-stall{animation:g7ShellStall 0s 20s forwards}',
      '.g7-embed{',
      '.g7-embed[data-aspect="9:16"]{',
      '.g7-embed[data-aspect="4:3"]{',
      '.g7-embed[data-aspect="1:1"]{',
      '.g7-embed>iframe{',
      '.g7-spinner-overlay{',
      '.g7-spinner{',
      '.g7-system-banner{',
      '.g7-boot-fallback{',
      '.g7-error__title{',
    ]) {
      expect(core, needle).toContain(needle);
    }
    // 기본 스피너 색은 종전대로 CSS 변수로 바꿀 수 있다
    expect(core).toContain('var(--g7-overlay-bg, rgb(249,250,251))');
    expect(core).toContain('var(--g7-spinner-color, #9ca3af)');
  });

  /**
   * @scenario case=core_css_contract
   * @effects core_css_has_no_global_selectors
   */
  it('g7-core.css 에 전역 선택자·@layer 가 없다', () => {
    const selectors = core
      .replace(/@(media|keyframes)[^{]*\{/g, '')
      .split('}')
      .map((block) => block.split('{')[0].trim())
      .filter((selector) => selector !== '' && !/^(to|from|\d+%)$/.test(selector));

    expect(selectors.length).toBeGreaterThan(30);
    for (const selector of selectors) {
      for (const part of selector.split(',')) {
        const first = part.trim();
        expect(/^(\*|html|body)(\s*$|[\s>+~]*$)/.test(first), `전역 선택자: ${first}`).toBe(false);
        expect(/^(#g7-|\.g7-|html(\.|:not\()[^ ]* #g7-)/.test(first), `g7- 접두사가 아닌 선택자: ${first}`).toBe(true);
      }
    }
    expect(core).not.toContain('@layer');
  });

  /**
   * @scenario case=core_css_contract
   * @effects standalone_css_has_standalone_classes
   */
  it('g7-standalone.css 는 독립 화면 클래스를 담는다(전역 리셋 포함 — 셸에는 싣지 않는다)', () => {
    const standalone = stripComments(read('g7-standalone.css'));

    for (const needle of ['.error-container', '.error-code', '.error-btn', '.error-message--mono', '.maintenance-card', '.credential-reason']) {
      expect(standalone, needle).toContain(needle);
    }
    expect(standalone).toMatch(/\*\s*\{/);
  });
});
