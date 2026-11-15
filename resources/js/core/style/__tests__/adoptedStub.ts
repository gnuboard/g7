/**
 * 시험용 생성형 스타일시트 스텁 — jsdom 에는 `document.adoptedStyleSheets`·`replaceSync` 가 없어
 * `RuntimeStyleSheet` 가 `<style>` 폴백으로 내려간다. 실제 브라우저 경로(adopted 모드)를 밟으려면 이 스텁을 끼운다.
 */
import { vi } from 'vitest';

/** 스텁 시트 — 본문만 기억한다 */
export class FakeCSSStyleSheet {
    cssText = '';

    /**
     * 본문을 교체합니다.
     *
     * @param text CSS 본문
     * @returns 반환값 없음
     */
    replaceSync(text: string): void {
        this.cssText = text;
    }
}

/**
 * 스텁을 끼우고, 원복 함수를 돌려줍니다.
 *
 * @returns 원복 함수
 */
export function installAdoptedStub(): () => void {
    let sheets: unknown[] = [];
    vi.stubGlobal('CSSStyleSheet', FakeCSSStyleSheet);
    Object.defineProperty(document, 'adoptedStyleSheets', {
        configurable: true,
        get: () => sheets,
        set: (value: unknown[]) => { sheets = value; },
    });
    (window as any).__g7RuntimeStyles = undefined;

    return () => {
        vi.unstubAllGlobals();
        delete (document as any).adoptedStyleSheets;
        (window as any).__g7RuntimeStyles = undefined;
    };
}

/**
 * 지금 채택된 시트들의 본문을 돌려줍니다.
 *
 * @returns 본문 목록
 */
export function adoptedCssTexts(): string[] {
    return ((document as any).adoptedStyleSheets as FakeCSSStyleSheet[]).map((sheet) => sheet.cssText);
}
