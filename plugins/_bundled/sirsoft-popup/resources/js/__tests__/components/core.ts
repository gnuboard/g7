/**
 * @file core.ts
 * @description 컴포넌트 테스트용 G7Core 대역 — `t()` 는 플러그인 ko.json 을 읽어 실제 화면 문구를 돌려준다.
 */
import ko from '../../../lang/ko.json';

/** 플러그인 번역 키 접두 */
const PREFIX = 'sirsoft-popup.';

/**
 * ko.json 에서 번역 문구를 찾습니다(없으면 키).
 *
 * @param key 전체 번역 키 (`sirsoft-popup.…`)
 * @param params 치환 값 (`{{name}}`)
 * @returns 문구
 */
export function koT(key: string, params?: Record<string, string | number>): string {
  const found = key.startsWith(PREFIX)
    ? key.slice(PREFIX.length).split('.').reduce<unknown>((node, part) => (node as Record<string, unknown> | undefined)?.[part], ko)
    : undefined;
  if (typeof found !== 'string') {
    return key;
  }

  return Object.entries(params ?? {}).reduce((text, [name, value]) => text.split(`{{${name}}}`).join(String(value)), found);
}

/**
 * window.G7Core 대역을 설치합니다.
 *
 * @param extra 더할 멤버 (dispatch 등)
 */
export function installCore(extra: Record<string, unknown> = {}): void {
  (window as any).G7Core = { t: koT, ...extra };
}

/**
 * 창 폭을 정합니다 — 레이어는 엔진 훅이 없을 때 `window.innerWidth` 로 기기를 판정한다.
 *
 * @param mobile 모바일 폭(375)이면 true, 아니면 데스크톱 폭(1280)
 */
export function setViewportMobile(mobile: boolean): void {
  Object.defineProperty(window, 'innerWidth', { value: mobile ? 375 : 1280, configurable: true, writable: true });
}

/** {@link recordInlineStyle} 가 돌려주는 기록기 */
export interface InlineStyleRecorder {
  /** 요소에 마지막으로 써 넣은 값 (쓴 적 없으면 '') */
  of: (el: HTMLElement, name: string) => string;
  /** 가로챈 속성을 되돌린다 */
  restore: () => void;
}

/**
 * 인라인 스타일 속성에 써 넣는 값을 기록합니다 — 쓰기는 그대로 jsdom 에 넘긴다.
 *
 * 루트 Vitest 의 jsdom 27(cssstyle 5.3)은 단위가 섞인 `min()`·`max()`·`clamp()` 를 받지 못해 값을 버린다
 * (`left = 'min(80px, 100vw)'` → `''`). 브라우저는 받는 값이라 `el.style.left` 로는 레이어가 쓴 식을 볼 수 없으므로,
 * React 가 `style[name] = value` 로 쓰는 순간을 가로채 본다.
 *
 * @param names 기록할 속성 이름 (camelCase, 예: left·top)
 * @returns 기록기 — 시험 끝에 restore() 를 부른다
 */
export function recordInlineStyle(names: string[]): InlineStyleRecorder {
  const written = new WeakMap<object, Record<string, string>>();
  const proto: object | null = Object.getPrototypeOf(document.createElement('div').style);
  const restores = names.map((name) => {
    let owner = proto;
    let desc: PropertyDescriptor | undefined;
    while (owner && !(desc = Object.getOwnPropertyDescriptor(owner, name))) {
      owner = Object.getPrototypeOf(owner);
    }
    if (!owner || !desc?.set) {
      throw new Error(`style.${name} 에 setter 가 없습니다`);
    }
    const original = desc;
    Object.defineProperty(owner, name, {
      ...original,
      set(this: object, value: unknown) {
        written.set(this, { ...(written.get(this) ?? {}), [name]: String(value) });
        original.set!.call(this, value);
      },
    });

    return () => Object.defineProperty(owner as object, name, original);
  });

  return {
    of: (el, name) => written.get(el.style)?.[name] ?? '',
    restore: () => restores.forEach((undo) => undo()),
  };
}
