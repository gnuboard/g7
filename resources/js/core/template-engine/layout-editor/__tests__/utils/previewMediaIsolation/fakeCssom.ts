/**
 * sheetRewriter 테스트용 CSSOM 대역
 *
 * jsdom 의 CSSOM 은 `@layer`·`@container`·중첩 규칙을 지원하지 않으므로, 재작성기가 읽고 쓰는
 * 표면(selectorText 읽기/쓰기, cssRules, insertRule/deleteRule, media.mediaText)만 흉내 낸다.
 * insertRule 로 들어온 텍스트는 파싱하지 않고 `FakeRawRule` 로 보관한다 — 단언은 그 텍스트로 한다.
 */

export type FakeRule =
  | FakeStyleRule
  | FakeGroupRule
  | FakeMediaRule
  | FakeRawRule
  | FakeNestedDeclarations
  | FakeKeyframesRule;

/** 그룹 규칙 공통(insertRule/deleteRule) */
abstract class FakeContainer {
  cssRules: FakeRule[] = [];

  insertRule(text: string, index = 0): number {
    if (text.includes('THROW')) throw new SyntaxError('bad rule');
    this.cssRules.splice(index, 0, new FakeRawRule(text));
    return index;
  }

  deleteRule(index: number): void {
    this.cssRules.splice(index, 1);
  }
}

export class FakeRawRule {
  constructor(public cssText: string) {}
}

export class FakeNestedDeclarations {
  style: { cssText: string };
  constructor(decls: string) {
    this.style = { cssText: decls };
  }
  get cssText(): string {
    return this.style.cssText;
  }
}

export class FakeKeyframesRule {
  constructor(public name: string, public cssText: string) {}
  findRule(): null {
    return null;
  }
  appendRule(): void {}
}

export class FakeStyleRule extends FakeContainer {
  private _selector: string;
  /** true 면 가드가 붙은 selectorText 쓰기를 무시한다(브라우저 거부 흉내) */
  rejectGuard = false;
  style: { cssText: string };

  constructor(selector: string, decls: string, children: FakeRule[] = []) {
    super();
    this._selector = selector;
    this.style = { cssText: decls };
    this.cssRules = children;
  }

  get selectorText(): string {
    return this._selector;
  }

  set selectorText(value: string) {
    if (this.rejectGuard && value.includes('g7le-preview-frame')) return;
    this._selector = value;
  }

  get cssText(): string {
    const nested = this.cssRules.map((r) => r.cssText).join(' ');
    return `${this._selector} { ${this.style.cssText}${nested ? ` ${nested}` : ''} }`;
  }
}

export class FakeGroupRule extends FakeContainer {
  constructor(public prelude: string, children: FakeRule[]) {
    super();
    this.cssRules = children;
  }
  get cssText(): string {
    return `${this.prelude} { ${this.cssRules.map((r) => r.cssText).join(' ')} }`;
  }
}

export class FakeMediaRule extends FakeContainer {
  media: { mediaText: string };
  constructor(mediaText: string, children: FakeRule[]) {
    super();
    this.media = { mediaText };
    this.cssRules = children;
  }
  get conditionText(): string {
    return this.media.mediaText;
  }
  get cssText(): string {
    return `@media ${this.media.mediaText} { ${this.cssRules.map((r) => r.cssText).join(' ')} }`;
  }
}

export class FakeSheet extends FakeContainer {
  media = { mediaText: '' };
  ownerNode: { hasAttribute: (name: string) => boolean } | null = null;
  constructor(children: FakeRule[]) {
    super();
    this.cssRules = children;
  }
}

/** cssRules 접근이 SecurityError 를 던지는 교차 출처 시트 */
export class FakeCrossOriginSheet {
  media = { mediaText: '' };
  ownerNode = null;
  get cssRules(): never {
    throw new DOMException('cross origin', 'SecurityError');
  }
}

/** 트리를 비교 가능한 문자열로 직렬화 */
export function dump(container: { cssRules: FakeRule[] }): string[] {
  return container.cssRules.map((r) => r.cssText);
}
