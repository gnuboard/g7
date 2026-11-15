/**
 * 코어 본문 서식 지연 장치(`G7Core.inlineStyle`) 대역 — 템플릿 시험 전용
 *
 * 코어 계약을 그대로 흉내 낸다: 받은 DOMPurify 인스턴스로 정화하고, 살아남은 style 속성을 `data-g7-style` 로 옮기며,
 * 원문에 적혀 있던 `data-g7-style` 과 설정이 금지한 style 은 버린다. `apply` 는 지연 속성을 `el.style.cssText` 로 입히고 뗀다.
 */
import { vi } from 'vitest';

/** 지연 속성 이름 (코어 `G7Core.inlineStyle.ATTR`) */
export const DEFERRED_ATTR = 'data-g7-style';

/**
 * 설정이 style 속성을 금지하는지 판정한다.
 *
 * @param config DOMPurify 설정
 * @returns 금지하면 true
 */
function forbidsStyle(config: Record<string, any>): boolean {
  if (Array.isArray(config.FORBID_ATTR) && config.FORBID_ATTR.includes('style')) {
    return true;
  }

  return Array.isArray(config.ALLOWED_ATTR) && !config.ALLOWED_ATTR.includes('style');
}

/**
 * 장치 대역을 만든다.
 *
 * @returns sanitize·apply 가 vi.fn 인 대역
 */
export function createInlineStyleMock() {
  return {
    ATTR: DEFERRED_ATTR,
    sanitize: vi.fn((purifyInstance: any, html: string, config: Record<string, any> = {}): string => {
      const holder = document.createElement('div');
      holder.innerHTML = purifyInstance.sanitize(html, config) as string;
      holder.querySelectorAll(`[${DEFERRED_ATTR}]`).forEach((el) => el.removeAttribute(DEFERRED_ATTR));
      holder.querySelectorAll('[style]').forEach((el) => {
        if (!forbidsStyle(config)) {
          el.setAttribute(DEFERRED_ATTR, el.getAttribute('style') ?? '');
        }
        el.removeAttribute('style');
      });

      return holder.innerHTML;
    }),
    apply: vi.fn((root: Element | null): number => {
      if (!root) {
        return 0;
      }
      const targets = Array.from(root.querySelectorAll<HTMLElement>(`[${DEFERRED_ATTR}]`));
      targets.forEach((el) => {
        el.style.cssText = el.getAttribute(DEFERRED_ATTR) ?? '';
        el.removeAttribute(DEFERRED_ATTR);
      });

      return targets.length;
    }),
  };
}

/**
 * 장치 대역을 `window.G7Core.inlineStyle` 에 깐다(다른 G7Core 값은 보존).
 *
 * @param extra 함께 깔 G7Core 값
 * @returns 깐 대역
 */
export function installInlineStyleMock(extra: Record<string, unknown> = {}) {
  const mock = createInlineStyleMock();
  (window as any).G7Core = { ...((window as any).G7Core ?? {}), ...extra, inlineStyle: mock };

  return mock;
}
