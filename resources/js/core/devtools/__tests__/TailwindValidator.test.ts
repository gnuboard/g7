/**
 * @file TailwindValidator.test.ts
 * @description DevTools Tailwind 검증기의 폭 접두 경고 — 레이아웃과 부품의 Tailwind 폭 접두(`sm:` `md:` `lg:` `xl:` `2xl:`
 * `max-*:` `min-[..]:` `max-[..]:`)는 금지 규정이고(docs/frontend/responsive-layout.md 2절), `portable:` `desktop:` 은 이
 * 프로젝트에 없는 변형이다. 검증기는 이것을 유효 변형으로 넘기지 않고, CSS 에 규칙이 있어도 경고하며 엔진 `responsive` 의 대응
 * 구간을 권한다. (매니페스트 devtools-style-tracker-guidance.yaml)
 *
 * @vitest-environment jsdom
 */
import { describe, it, expect } from 'vitest';
import { getTailwindValidator } from '../TailwindValidator';
import { G7DevToolsCore } from '../G7DevToolsCore';

describe('TailwindValidator 폭 접두 경고', () => {
  // @scenario guidance=tailwind_breakpoint_variant
  // @effects devtools_guidance_recommends_engine_responsive
  it('폭 접두 클래스는 유효로 세지 않고 엔진 responsive 대응 구간을 권한다 — 확장 접두와 상태 변형이 섞여도 같다', async () => {
    const validator = getTailwindValidator();
    validator.clearCache();
    const result = await validator.validateClasses(['flex', 'dark:bg-gray-800', 'hover:bg-gray-50', 'md:flex', 'ecommerce:md:w-20', 'dark:lg:hidden', '!xl:block', 'max-md:hidden', 'min-[900px]:grid', 'max-[600px]:p-2', 'desktop:flex']);
    const byClass = Object.fromEntries((result.breakpointVariants ?? []).map((item) => [item.className, item]));

    expect(Object.keys(byClass).sort()).toEqual(['!xl:block', 'dark:lg:hidden', 'desktop:flex', 'ecommerce:md:w-20', 'max-[600px]:p-2', 'max-md:hidden', 'md:flex', 'min-[900px]:grid'].sort());
    expect(result.valid).toBe(false);
    expect(byClass['md:flex'].suggestion).toContain('<responsive on="768-"');
    expect(byClass['dark:lg:hidden'].suggestion).toContain('<responsive on="desktop"');
    expect(byClass['!xl:block'].suggestion).toContain('<responsive on="1280-"');
    expect(byClass['max-md:hidden'].suggestion).toContain('<responsive on="mobile"');
    expect(byClass['min-[900px]:grid'].suggestion).toContain('<responsive on="900-"');
    // Tailwind 의 max-[N] 은 N 미만이다
    expect(byClass['max-[600px]:p-2'].suggestion).toContain('<responsive on="-599"');
    expect(byClass['desktop:flex'].suggestion).toContain('<responsive on="desktop"');
    for (const item of result.breakpointVariants ?? []) {
      expect(item.suggestion).toContain('responsive');
    }
    // 상태 변형은 폭 판정이 아니라 경고하지 않는다
    expect(byClass['dark:bg-gray-800']).toBeUndefined();
    expect(byClass['hover:bg-gray-50']).toBeUndefined();
  });

  // @scenario guidance=tailwind_breakpoint_variant
  // @effects devtools_guidance_recommends_engine_responsive
  it('빌드 CSS 에 규칙이 있어도 폭 접두는 경고한다 — 빠른 검증도 따로 알린다', async () => {
    const style = document.createElement('style');
    style.textContent = '.flex{display:flex} .md\:flex{display:flex}';
    document.head.appendChild(style);
    try {
      const validator = getTailwindValidator();
      validator.clearCache();
      const result = await validator.validateClasses(['flex', 'md:flex']);
      expect(result.validClasses).toBe(1);
      expect(result.breakpointVariants?.map((item) => item.className)).toEqual(['md:flex']);

      const quick = validator.quickValidate(['flex', 'md:flex']);
      expect(quick.valid).toEqual(['flex']);
      expect(quick.breakpoint).toEqual(['md:flex']);
    } finally {
      style.remove();
    }
  });

  // @scenario guidance=tailwind_breakpoint_variant
  // @effects devtools_guidance_recommends_engine_responsive
  it('컴포넌트 스타일 분석의 반응형 클래스 목록도 같은 판정으로 모은다 — 확장 접두, max 짝, 임의 값 경계를 놓치지 않는다', () => {
    const core = G7DevToolsCore.getInstance() as unknown as { analyzeTailwindClasses: (classes: string[]) => { responsiveClasses: string[] } };
    const analysis = core.analyzeTailwindClasses(['flex', 'md:flex', 'ecommerce:md:w-20', 'max-md:hidden', 'min-[900px]:grid', 'dark:bg-gray-800']);
    expect(analysis.responsiveClasses).toEqual(['md:flex', 'ecommerce:md:w-20', 'max-md:hidden', 'min-[900px]:grid']);
  });
});
