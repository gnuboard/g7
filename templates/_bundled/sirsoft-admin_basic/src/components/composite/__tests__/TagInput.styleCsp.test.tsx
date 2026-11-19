/**
 * @file TagInput.styleCsp.test.tsx
 * @description 태그 입력 — 스타일 정책(`style-src-elem 'self'` / `style-src 'self'; style-src-attr 'none'`)을 강제해도
 *   react-select 의 배치 규칙이 적용된다
 *
 * react-select 는 emotion 으로 배치 규칙(목록 `position:absolute`, 화면 낭독용 안내 문구 `clip` 숨김, 칸 `display:flex`)을
 * 런타임에 넣는다. emotion 기본 캐시는 `<style data-emotion>` 요소를 `<head>` 에 만들고, 스타일 정책이 그 요소를 막아
 * 목록이 문서 흐름에 1438×1606 으로 펼쳐지고 안내 문구가 칸 위에 그대로 보였다(Chrome 실측). 태그 입력은 emotion 규칙을
 * 생성형 스타일시트(`new CSSStyleSheet()` + `document.adoptedStyleSheets` — CSSOM 대입이라 정책에 걸리지 않는다)로 싣는다.
 * 이 묶음의 기본 환경(jsdom)은 생성형 시트가 없어 emotion 기본 캐시로 내려가므로, 생성형 시트와 그 계산 스타일을
 * 지원하는 happy-dom 에서 잰다.
 *
 * @vitest-environment happy-dom
 * @scenario-manifest templates/_bundled/sirsoft-admin_basic/tests/scenarios/tag-input-component-baseline.yaml
 */

import { describe, it, expect, afterEach } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { TagInput, TagOption } from '../TagInput';
import { createAdoptedEmotionCache, TAG_INPUT_EMOTION_KEY } from '../../../utils/adoptedEmotionCache';

const roleOptions: TagOption[] = [
  { value: '1', label: '관리자' },
  { value: '2', label: '콘텐츠 관리자' },
  { value: '3', label: '마케팅 담당자' },
];

/** 생성형 시트들에 실린 규칙 본문 전부. */
const adoptedRuleTexts = (): string[] =>
  ((document as any).adoptedStyleSheets as CSSStyleSheet[]).flatMap((sheet) =>
    Array.from(sheet.cssRules).map((rule) => rule.cssText),
  );

afterEach(() => {
  cleanup();
});

describe('[case:components-misc-tag-input-style-csp] TagInput — 스타일 정책 강제에서도 배치 규칙이 적용된다', () => {
  // @scenario area=style_csp
  // @effects tag_input_emotion_rules_in_adopted_sheet, tag_input_creates_no_style_element
  it('목록을 열어도 <style> 요소를 만들지 않고, 배치 규칙은 생성형 시트로 적용된다', async () => {
    const user = userEvent.setup();
    render(<TagInput value={[]} options={roleOptions} onChange={() => {}} aria-label="권한 그룹" />);

    await user.click(screen.getByRole('combobox'));
    await user.keyboard('관리');

    const menu = document.body.querySelector<HTMLElement>('.tag-input__menu');
    expect(menu, '목록이 열려야 한다').not.toBeNull();

    // emotion 기본 캐시는 <style data-emotion> 를 만든다 — 스타일 정책이 이 요소를 막는다
    expect(document.querySelectorAll('style').length, '<style> 요소는 스타일 정책(style-src-elem)에 막힌다').toBe(0);

    // 배치 규칙이 생성형 시트에 실려 있다
    const rules = adoptedRuleTexts();
    expect(rules.some((text) => text.startsWith(`.${TAG_INPUT_EMOTION_KEY}-`))).toBe(true);

    // 목록은 칸 아래 떠 있는 상자, 안내 문구는 1px 클립으로 숨는다(끔과 같은 모양)
    expect(getComputedStyle(menu!).position).toBe('absolute');
    const liveRegion = document.querySelector<HTMLElement>('[id$="-live-region"]');
    expect(liveRegion).not.toBeNull();
    expect(getComputedStyle(liveRegion!).position).toBe('absolute');
    expect(getComputedStyle(liveRegion!).overflow).toBe('hidden');
    const control = document.querySelector<HTMLElement>('.tag-input__control');
    expect(getComputedStyle(control!).display).toBe('flex');
  });

  // @scenario area=style_csp
  // @effects tag_input_emotion_rules_in_adopted_sheet
  it('여러 태그 입력이 한 생성형 시트를 같이 쓰고, 남이 넣은 생성형 시트는 그대로 둔다', async () => {
    const foreign = new CSSStyleSheet();
    (document as any).adoptedStyleSheets = [...(document as any).adoptedStyleSheets, foreign];

    const user = userEvent.setup();
    render(
      <>
        <TagInput value={[]} options={roleOptions} onChange={() => {}} aria-label="첫째" />
        <TagInput value={['a']} options={[]} onChange={() => {}} creatable aria-label="둘째" />
      </>,
    );
    await user.click(screen.getByRole('combobox', { name: '첫째' }));

    const sheets = (document as any).adoptedStyleSheets as CSSStyleSheet[];
    expect(sheets).toContain(foreign);
    const own = sheets.filter((sheet) => Array.from(sheet.cssRules).some((rule) => rule.cssText.startsWith(`.${TAG_INPUT_EMOTION_KEY}-`)));
    expect(own).toHaveLength(1);
    expect(document.querySelectorAll('style').length).toBe(0);
  });

  // @scenario area=style_csp
  // @effects tag_input_emotion_rules_in_adopted_sheet
  it('다른 코드가 생성형 시트 배열을 통째로 덮어써도 다음 규칙을 넣을 때 자기 시트를 다시 붙인다', () => {
    const cache = createAdoptedEmotionCache('reattach-probe');
    expect(cache).not.toBeNull();

    cache!.sheet.insert('.reattach-probe-a{color:red}');
    (document as any).adoptedStyleSheets = [];
    cache!.sheet.insert('.reattach-probe-b{color:blue}');

    const texts = adoptedRuleTexts();
    expect(texts.some((text) => text.startsWith('.reattach-probe-a'))).toBe(true);
    expect(texts.some((text) => text.startsWith('.reattach-probe-b'))).toBe(true);
  });

  // @scenario area=style_csp
  // @effects tag_input_emotion_fallback_without_adopted
  it('생성형 시트를 지원하지 않는 환경이면 캐시를 만들지 않는다(react-select 기본 캐시로 그린다)', () => {
    // 문서 인스턴스에 「없음」 을 덮어 구형 브라우저를 흉내 낸다 — 지우면 프로토타입의 실제 속성이 돌아온다
    Object.defineProperty(document, 'adoptedStyleSheets', { configurable: true, get: () => undefined, set: () => {} });
    try {
      expect(createAdoptedEmotionCache('fallback-probe')).toBeNull();
    } finally {
      delete (document as any).adoptedStyleSheets;
    }
    expect(Array.isArray((document as any).adoptedStyleSheets)).toBe(true);
  });
});
