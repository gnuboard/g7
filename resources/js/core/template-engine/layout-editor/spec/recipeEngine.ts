/**
 * recipeEngine.ts — 컨트롤 레시피 ↔ 레이아웃 JSON 패치 변환
 *
 * 속성 편집 모달의 컨트롤이 만든 값을 노드 패치로 바꾸고(`applyRecipe`), 기존
 * 노드에서 컨트롤의 현재값을 역해석한다(`reverseResolve`). 레시피 `apply` 종류는
 * **스타일 시스템 무관한 프리미티브**다(원칙 4.8):
 *
 *   | apply 종류    | 동작                                                      |
 *   | ------------- | --------------------------------------------------------- |
 *   | `classToken`  | `props.className` 의 클래스 토큰 추가/교체(같은 group 제거) |
 *   | `styleProp`   | `props.style` 에 CSS 속성 설정(인라인 스타일)              |
 *   | `propValue`   | 특정 prop 값 설정(컴포넌트가 스타일을 prop 으로 받는 경우)  |
 *   | `cssVar`      | `props.style` 에 CSS 변수 설정                            |
 *
 * 코어는 어떤 CSS 프레임워크도 가정하지 않는다 — 컨트롤이 선언한 `apply` 가
 * 코드 형식을 정한다(원칙 4.8). 역해석 실패 값은 보존하고 호출자가 "고급 값"
 * 으로 분류한다(원칙 4.4).
 *
 * 모든 함수는 순수 함수 — 입력 노드를 변경하지 않고 새 사본을 반환한다.
 *
 * @since engine-v1.50.0
 */

import type { EditorNode } from '../utils/layoutTreeUtils';
import type { EditorControlSpec } from './specTypes';
import {
  BASE_SCOPE,
  addDarkPrefix,
  getScopedProps,
  hasDarkPrefix,
  isDarkEditable,
  stripDarkPrefix,
  withScopedProps,
  type StyleScope,
} from './styleScope';

/** apply 프리미티브 종류 */
export type RecipeApplyType = 'classToken' | 'styleProp' | 'propValue' | 'cssVar';

/**
 * 컨트롤/옵션의 `apply` 선언. 컨트롤 자체에 직접 두거나(`color`/`image`/`width`
 * 등 자유값 컨트롤), 옵션마다 둘 수 있다(`segmented`/`select` 등 택1 컨트롤).
 */
export interface RecipeApply {
  type: RecipeApplyType;
  /** classToken — 적용할 클래스 토큰 목록 */
  tokens?: string[];
  /**
   * classToken — 자유값을 임의값 클래스로 합성하는 템플릿(예: `text-[{value}]`).
   * `{value}` 가 컨트롤 값으로 치환된다. 이 기법을 지원하는 스타일 시스템 한정.
   */
  tokenTemplate?: string;
  /** styleProp — 단일 CSS 속성명 (camelCase — React style 키) */
  prop?: string;
  /** styleProp — 다중 CSS 속성명 (배경 이미지 등 묶음 적용) */
  props?: string[];
  /**
   * styleProp/propValue — 고정 값. 옵션이 값을 직접 지정할 때(예: 정렬 옵션이
   * `textAlign: 'center'`). 미지정 시 컨트롤 호출 값(value)을 그대로 사용.
   */
  value?: unknown;
  /** styleProp 다중 속성 묶음의 각 속성별 고정 값 (props 와 동일 길이) */
  values?: Record<string, unknown>;
  /** propValue — 설정할 prop 경로(점 표기 미지원 — 단일 키) */
  propKey?: string;
  /** cssVar — CSS 변수명 (`--brand-color` 등) */
  varName?: string;
}

/**
 * 옵션 1건 — `segmented`/`select` 등 택1 위젯의 선택지.
 */
export interface RecipeOptionSpec {
  value: unknown;
  label?: string;
  apply?: RecipeApply;
}

/** 컨트롤 + 옵션을 합쳐 본 엔진이 읽는 정규화 형태 */
interface NormalizedControl {
  /** 같은 group 의 기존 토큰을 교체하는 키 (classToken 중복 방지) */
  group?: string;
  /** 자유값 컨트롤(color/image/width 등)의 단일 apply */
  apply?: RecipeApply;
  /** 택1 컨트롤의 옵션 목록 */
  options?: RecipeOptionSpec[];
  /** slider/select 의 단계 스케일(값 목록) — reverseResolve 폴백에 사용 */
  scale?: unknown[];
  /**
   * 이 group 에 속하는 **상호배타 토큰 전체 목록**.
   *
   * 옵션 토큰만으로 group 을 판정하면, 노드 기본 className 이 같은 group 의 **옵션에 없는**
   * 토큰(예: 옵션은 normal/semibold/bold 인데 기본이 `font-medium`)을 쓰면 그 토큰이 제거되지
   * 않아 새 토큰과 공존 → CSS 우선순위로 서식이 안 먹는 것처럼 보였다. 컨트롤이 이 목록으로
   * group 의 전체 패밀리(예: `["font-thin","font-light","font-normal","font-medium",
   * "font-semibold","font-bold",...]`)를 선언하면, 그 중 어떤 토큰이든 교체 대상이 된다.
   *
   * **라이브러리 중립**: 어떤 토큰이 한 group 인지는 템플릿의 스타일 라이브러리 지식이므로
   * **템플릿 editor-spec 이 선언**한다(코어는 목록을 읽어 적용만 — Tailwind 등 특정 어휘를
   * 코어에 박지 않는다, `feedback_layout_editor_no_css_lib_dependency`).
   */
  groupTokens?: string[];
  /**
   * 같은 group 충돌 토큰을 식별하는 추가 prefix 목록 (§항목B — spacing 위젯).
   *
   * `tokenTemplate: "{value}"` 처럼 위젯이 토큰 전체를 합성하는 컨트롤은 옵션/고정
   * tokens 가 없어 group 매칭이 불가하다(prefix='' → 전체 매칭 위험). 이때 컨트롤이
   * 방향 변형 prefix(예: 여백 `["p-","px-","py-","pt-","pr-","pb-","pl-"]`)를 직접
   * 선언하면, 그 prefix 로 시작하는 기존 토큰만 교체 대상으로 본다(전/후 방향 전환 시
   * 이전 방향 토큰 제거). buildGroupTokenMatcher 의 빈 prefix 전체 매칭은 배제한다.
   */
  groupPrefixes?: string[];
}

/** EditorControlSpec → 정규화 (작성자 자유 필드 보존하되 엔진이 쓰는 키만 추출) */
function normalize(control: EditorControlSpec): NormalizedControl {
  const gp = (control as { groupPrefixes?: unknown }).groupPrefixes;
  return {
    group: typeof control.group === 'string' ? control.group : undefined,
    apply: (control.apply && typeof control.apply === 'object'
      ? (control.apply as unknown as RecipeApply)
      : undefined) as RecipeApply | undefined,
    options: Array.isArray(control.options)
      ? (control.options as RecipeOptionSpec[])
      : undefined,
    scale: Array.isArray((control as { scale?: unknown[] }).scale)
      ? ((control as { scale?: unknown[] }).scale as unknown[])
      : undefined,
    groupPrefixes: Array.isArray(gp)
      ? (gp.filter((p) => typeof p === 'string' && p.length > 0) as string[])
      : undefined,
    groupTokens: Array.isArray((control as { groupTokens?: unknown }).groupTokens)
      ? ((control as { groupTokens?: unknown }).groupTokens as unknown[]).filter(
          (tk): tk is string => typeof tk === 'string' && tk.length > 0,
        )
      : undefined,
  };
}

/**
 * props 사본 + style 사본을 보장하며 반환 (입력 불변).
 *
 * 임의의 props 컨테이너(base `node.props` 또는 `responsive[bp].props`)에 적용
 * 가능하도록 props 컨테이너를 직접 받는다. `styleScope.withScopedProps` 가
 * scope 컨테이너를 넘겨 재사용한다.
 */
export function clonePropsWithStyle(container: Record<string, unknown> | undefined): {
  props: Record<string, unknown>;
  style: Record<string, unknown>;
} {
  const props: Record<string, unknown> = { ...(container ?? {}) };
  const style: Record<string, unknown> =
    props.style && typeof props.style === 'object' && !Array.isArray(props.style)
      ? { ...(props.style as Record<string, unknown>) }
      : {};
  props.style = style;
  return { props, style };
}

/** className 문자열을 토큰 배열로 분해 */
export function tokenize(className: unknown): string[] {
  if (typeof className !== 'string') return [];
  return className.split(/\s+/).filter((t) => t.length > 0);
}

/**
 * 같은 group 에 속하는 기존 토큰을 제거할 판정 함수 생성.
 *
 * group 의 모든 옵션 토큰 + tokenTemplate prefix 를 수집해, 그 집합/접두에
 * 속하는 토큰을 교체 대상으로 본다. group 미선언 컨트롤은 정확히 동일 토큰만 제거.
 *
 * **다크 scope(`dark=true`)**: 라이트/다크 토큰이 한 className 에 공존하므로, 한쪽
 * 편집이 다른쪽을 건드리지 않도록 분리한다.
 *  - base(dark=false): `dark:` prefix 없는 토큰만 group 으로 본다.
 *  - dark(dark=true): `dark:` prefix 있는 토큰만, prefix 를 벗긴 후 group 집합/접두와 대조.
 *
 * @param control 정규화 컨트롤
 * @param dark 다크 scope 여부 (기본 false = base/라이트)
 * @return group 토큰 판정 함수
 */
function buildGroupTokenMatcher(
  control: NormalizedControl,
  dark = false,
): (token: string) => boolean {
  const exact = new Set<string>();
  const prefixes: string[] = [];

  const collect = (apply?: RecipeApply): void => {
    if (!apply) return;
    for (const tok of apply.tokens ?? []) exact.add(tok);
    if (apply.tokenTemplate && apply.tokenTemplate.includes('{value}')) {
      // `text-[{value}]` → prefix `text-[`
      prefixes.push(apply.tokenTemplate.split('{value}')[0] ?? '');
    }
  };

  collect(control.apply);
  for (const opt of control.options ?? []) collect(opt.apply);
  // 컨트롤이 직접 선언한 group prefix (spacing 등 토큰 합성 위젯) — 빈 prefix 는 normalize
  // 에서 이미 걸러졌다.
  for (const p of control.groupPrefixes ?? []) prefixes.push(p);
  // 컨트롤이 선언한 group 패밀리 전체 토큰 — 옵션에 없는 기본 className 토큰(예: font-medium)도
  // 같은 group 으로 인식해 교체 대상에 포함.
  for (const tok of control.groupTokens ?? []) exact.add(tok);

  return (token: string): boolean => {
    if (dark) {
      // 다크 scope — `dark:` 토큰만 대상, prefix 벗긴 뒤 group 대조
      if (!hasDarkPrefix(token)) return false;
      const bare = stripDarkPrefix(token);
      if (exact.has(bare)) return true;
      return prefixes.some((p) => p.length > 0 && bare.startsWith(p));
    }
    // base — `dark:` 토큰은 제외
    if (hasDarkPrefix(token)) return false;
    if (exact.has(token)) return true;
    return prefixes.some((p) => p.length > 0 && token.startsWith(p));
  };
}

/**
 * classToken 적용 — 같은 group 토큰 제거 후 새 토큰 삽입.
 *
 * 다크 scope 면 emit 토큰에 `dark:` prefix 를 붙이고, group matcher 도 `dark:` 토큰만
 * strip 한다 → 라이트/다크 토큰 공존(한쪽 편집이 다른쪽 보존).
 *
 * @param props props 컨테이너 (변형됨)
 * @param control 정규화 컨트롤
 * @param apply apply 선언
 * @param value 컨트롤 값
 * @param dark 다크 scope 여부 (기본 false)
 */
function applyClassToken(
  props: Record<string, unknown>,
  control: NormalizedControl,
  apply: RecipeApply,
  value: unknown,
  dark = false,
): void {
  const isGroupToken = buildGroupTokenMatcher(control, dark);
  const existing = tokenize(props.className).filter((t) => !isGroupToken(t));

  const next: string[] = [];
  if (Array.isArray(apply.tokens)) {
    next.push(...apply.tokens);
  } else if (apply.tokenTemplate && apply.tokenTemplate.includes('{value}') && value != null) {
    // value 는 다중 토큰(공백 구분)일 수 있다 — spacing 위젯이 상/우/하/좌 측별 토큰을
    // 공백으로 묶어 넘긴다(§항목B). 각 토큰에 템플릿을 적용 후 펼친다.
    const parts = String(value).split(/\s+/).filter((p) => p.length > 0);
    for (const part of parts) next.push(apply.tokenTemplate.replace('{value}', part));
  }

  // 다크 scope — emit 토큰에 `dark:` prefix 부여(멱등)
  const emitted = dark ? next.map(addDarkPrefix) : next;

  const merged = [...existing, ...emitted];
  if (merged.length === 0) {
    delete props.className;
  } else {
    props.className = merged.join(' ');
  }
}

/**
 * `image` 위젯 값 객체(`{ url, size, repeat, position }`) ↔ 배경 CSS 4속성 매핑.
 *
 * ImagePickerControl 은 `apply.values` 를 쓰지 않고 런타임 객체를 value 로 넘긴다
 * (editor-spec 의 backgroundImage 컨트롤도 values 미선언). 이 객체를 4개 background
 * 속성으로 풀어주는 책임은 엔진에 있다. url 은
 * CSS `url(...)` 함수로 래핑한다(이미 래핑돼 있으면 그대로 둔다).
 */
const IMAGE_FIELD_TO_PROP: Record<string, string> = {
  url: 'backgroundImage',
  size: 'backgroundSize',
  repeat: 'backgroundRepeat',
  position: 'backgroundPosition',
};

/** value 가 image 위젯 값 객체(배경 필드를 가진 평범한 객체)인지 */
function isImageValueObject(value: unknown): value is Record<string, unknown> {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
  return Object.keys(IMAGE_FIELD_TO_PROP).some((k) => k in (value as Record<string, unknown>));
}

/** url 문자열을 CSS `url(...)` 로 래핑 (이미 url(...) 형태면 그대로) */
function wrapCssUrl(url: string): string {
  return /^url\(/i.test(url.trim()) ? url : `url(${url})`;
}

/** CSS `url(...)` 래핑을 벗겨 내부 url 만 추출 (래핑이 없으면 그대로) */
function unwrapCssUrl(value: string): string {
  const m = /^url\(\s*(['"]?)(.*?)\1\s*\)$/i.exec(value.trim());
  return m ? m[2] : value;
}

/** apply.props 가 image 위젯 배경 묶음(backgroundImage 포함)인지 */
function isImageBundleProps(props: string[]): boolean {
  return props.includes('backgroundImage');
}

/**
 * 레거시 손상값 정화 — 과거(수정 이전) 저장본은 각 background 속성에 image 값 객체가
 * 통째로 들어가 있을 수 있다(`backgroundPosition: { url, size, ... }`). 그런 객체/배열은
 * 유효한 CSS 토큰이 아니므로 스칼라(문자열/숫자)만 채택하고, 객체면 해당 객체에서 같은
 * 의미의 필드를 끌어와 복구하거나 폐기한다.
 */
function sanitizeBgScalar(value: unknown, field: 'size' | 'repeat' | 'position'): unknown {
  if (value === undefined || value === null) return undefined;
  if (typeof value === 'object') {
    // 손상값: image 값 객체가 통째로 들어온 경우 같은 필드를 끌어와 복구
    const v = (value as Record<string, unknown>)[field];
    return typeof v === 'string' || typeof v === 'number' ? v : undefined;
  }
  return value;
}

/**
 * style 에서 image 위젯 값 객체를 역조립. backgroundImage(url 언래핑)/Size/Repeat/
 * Position 중 하나라도 있으면 객체를 만들고, 전부 없으면 undefined.
 *
 * 레거시 손상값(각 속성에 image 값 객체가 통째로 저장된 경우)은 정화해 스칼라만 채택한다.
 */
function readImageObject(style: Record<string, unknown>): Record<string, unknown> | undefined {
  const rawImg = style.backgroundImage;
  const size = sanitizeBgScalar(style.backgroundSize, 'size');
  const repeat = sanitizeBgScalar(style.backgroundRepeat, 'repeat');
  const position = sanitizeBgScalar(style.backgroundPosition, 'position');
  // backgroundImage 손상값(객체)에서 url 복구 시도
  let url: unknown;
  if (typeof rawImg === 'string') url = unwrapCssUrl(rawImg);
  else if (rawImg && typeof rawImg === 'object') url = (rawImg as Record<string, unknown>).url;
  else url = undefined;

  if (url === undefined && size === undefined && repeat === undefined && position === undefined) {
    return undefined;
  }
  const out: Record<string, unknown> = {};
  if (url !== undefined) out.url = typeof url === 'string' ? unwrapCssUrl(url) : url;
  if (size !== undefined) out.size = size;
  if (repeat !== undefined) out.repeat = repeat;
  if (position !== undefined) out.position = position;
  return out;
}

/** styleProp 적용 — 단일/다중 CSS 속성 설정 (value=null/undefined 면 제거) */
function applyStyleProp(
  style: Record<string, unknown>,
  apply: RecipeApply,
  value: unknown,
): void {
  const setOne = (prop: string, v: unknown): void => {
    if (v === undefined || v === null || v === '') {
      delete style[prop];
    } else {
      style[prop] = v;
    }
  };

  if (Array.isArray(apply.props)) {
    // image 위젯 객체값 — 필드별 background 속성으로 분해(+url 래핑). 종전엔 객체를
    // 통째로 4속성에 써서 React 가 무시 → 캔버스 배경 미표시였다.
    if (!apply.values && isImageValueObject(value)) {
      for (const prop of apply.props) {
        const field = Object.keys(IMAGE_FIELD_TO_PROP).find(
          (k) => IMAGE_FIELD_TO_PROP[k] === prop,
        );
        let raw = field ? value[field] : undefined;
        // 손상값 방어 — 스칼라가 아닌 값(객체/배열)은 유효한 CSS 토큰이 아니므로 버린다.
        // (레거시 저장본이 size/repeat/position 에 image 값 객체를 통째로 담았던 경우)
        if (raw !== null && typeof raw === 'object') raw = undefined;
        const v =
          prop === 'backgroundImage' && typeof raw === 'string' && raw !== ''
            ? wrapCssUrl(raw)
            : raw;
        setOne(prop, v);
      }
      return;
    }

    // 다중 속성 — apply.values[prop] 우선, 없으면 value 를 그대로 (배경 이미지 등은
    // 호출자가 values 로 url/size/repeat/position 을 묶어 전달)
    for (const prop of apply.props) {
      const v = apply.values && prop in apply.values ? apply.values[prop] : value;
      setOne(prop, v);
    }
    return;
  }

  if (typeof apply.prop === 'string') {
    setOne(apply.prop, apply.value !== undefined ? apply.value : value);
  }
}

/** cssVar 적용 — props.style 에 `--var: value` 설정 */
function applyCssVar(style: Record<string, unknown>, apply: RecipeApply, value: unknown): void {
  if (typeof apply.varName !== 'string') return;
  const v = apply.value !== undefined ? apply.value : value;
  if (v === undefined || v === null || v === '') {
    delete style[apply.varName];
  } else {
    style[apply.varName] = v;
  }
}

/** propValue 적용 — props[key] 설정 */
function applyPropValue(
  props: Record<string, unknown>,
  apply: RecipeApply,
  value: unknown,
): void {
  if (typeof apply.propKey !== 'string') return;
  const v = apply.value !== undefined ? apply.value : value;
  if (v === undefined || v === null || v === '') {
    delete props[apply.propKey];
  } else {
    props[apply.propKey] = v;
  }
}

/**
 * 컨트롤 값을 노드 패치로 변환. `apply` 타입에 따라 4종 중 하나를 수행한다.
 *
 * 택1 컨트롤(옵션 보유)은 `value` 로 옵션을 찾아 그 옵션의 `apply` 를, 자유값
 * 컨트롤은 컨트롤 자체의 `apply` 를 사용한다. `value === undefined`(=기본/미적용)
 * 면 같은 group 의 토큰/스타일을 제거만 한다(원칙 "기본" 상태).
 *
 * **scope**: scope 미지정 시 `BASE_SCOPE`(라이트 × 공통) — 오늘과
 * 바이트 동일(순수 리팩토링). scope 가 가리키는 위치(base `props` 또는
 * `responsive[bp].props`)에 적용한다. 다크 scope 면 classToken 만 `dark:` prefix 로
 * 편집하고, 인라인 styleProp/cssVar/propValue 는 **no-op**(원본 반환 — 불필요 history
 * push 방지, 무손실 보존).
 *
 * @param node 대상 노드 (변경되지 않음)
 * @param control 컨트롤 정의
 * @param value 컨트롤이 만든 값 (옵션 value 또는 자유값). undefined = 기본/미적용
 * @param scope 활성 StyleScope (기본 BASE_SCOPE = 라이트 × 공통)
 * @return 패치된 노드 사본
 */
export function applyRecipe(
  node: EditorNode,
  control: EditorControlSpec,
  value: unknown,
  scope: StyleScope = BASE_SCOPE,
): EditorNode {
  const normalized = normalize(control);
  const dark = scope.colorScheme === 'dark';

  // 적용할 apply 결정 — 택1 컨트롤은 옵션에서, 자유값 컨트롤은 컨트롤에서.
  //
  // 옵션이 있어도 (1) 일치 옵션이 없거나 (2) 일치 옵션에 apply 가 없으면
  // control-level `apply` 로 폴백한다. dimension/
  // 색상 등 "프리셋 칩 + 자유 입력" 컨트롤은 options 를 단축 프리셋으로만 두고
  // 자유 문자열 값을 control-level `apply: styleProp` 으로 적용한다. 종전엔
  // options 가 있으면 무조건 opt.apply 만 보고 control-level apply 를 무시해
  // (per-option apply 미선언 시) 아무 것도 적용되지 않던 결함을 함께 해소(항목7 동반).
  let apply: RecipeApply | undefined;
  // 택1 컨트롤에서 value 가 알려진 옵션과 일치하는지 — apply 없는 "off" 옵션 판정에 사용.
  let matchedOption = false;
  if (normalized.options) {
    const opt = normalized.options.find((o) => o.value === value);
    matchedOption = opt !== undefined;
    apply = opt?.apply ?? normalized.apply;
  } else {
    apply = normalized.apply;
  }

  // 기본/미적용으로 보고 group 을 비우는 경우:
  //  (1) value 가 미적용 값(undefined/null/'') — "기본" 선택.
  //  (2) 택1 컨트롤에서 알려진 옵션을 골랐으나 그 옵션·control 어디에도 apply 가 없는
  //      경우 — 예: toggle off 옵션(`flexWrap` 의 `nowrap`). 이 옵션은 "group 토큰의
  //      부재"를 의미하므로 같은 group 토큰을 제거해야 한다(§항목 — 줄바꿈 허용 끄기).
  //      종전엔 비-빈 value + apply 없음이 어느 분기도 타지 않아 on 토큰이 잔존했다.
  const clearGroup =
    value === undefined ||
    value === null ||
    value === '' ||
    (matchedOption && apply === undefined);

  // 이 편집이 classToken(className) 을 다루는지 — clearGroup 은 normalized.apply 기준,
  // 그 외는 선택된 apply 기준. 택1 컨트롤은 옵션 apply 가 classToken 일 수 있다.
  const effectiveApply = clearGroup ? normalized.apply : apply;
  const touchesClassToken =
    effectiveApply?.type === 'classToken' ||
    (normalized.options?.some((o) => o.apply?.type === 'classToken') ?? false);

  // 다크 scope + 비-classToken apply 는 no-op (인라인은 `dark:` 불가, 무손실 보존).
  // group 비움(clearGroup) 도 classToken 만 다크에서 의미 — 비-classToken 제거는 base 만.
  // → short-circuit 으로 원본 node 반환(불필요 history push 방지 다크 분기).
  if (dark && !touchesClassToken) {
    return node;
  }

  // B안 className 시드.
  //
  // DynamicRenderer 의 responsive 머지는 props 얕은 머지(`{...base.props, ...override.props}`)
  // 라, className 은 단일 문자열 키이므로 `responsive[bp].props.className` 이 base className 을
  // **통째 대체**한다. 따라서 디바이스 scope 에서 className 토큰 하나만 적용해도 그 디바이스
  // 폭에서 base 의 다른 토큰이 전부 사라진다. 이를 막기 위해, 디바이스 scope 에서 className 을
  // **처음** 편집할 때(그 scope 컨테이너에 아직 className 이 없을 때) base className 전체를 시드해
  // 둔 뒤 그 위에 토큰을 적용/제거한다(라이트·다크 토큰 모두 복사 — 그 디바이스에서 두 색 모드
  // 가 다 유지되도록).
  //
  // 트레이드오프(명시적): 시드는 **편집 시점의 base className 을 복사**하는 것이라, 이후 base
  // 를 바꿔도 디바이스 override 는 그 복사본을 유지한다(기본값 변경과 독립). 되돌리려면 편집기의
  // "기본값으로 초기화"(디바이스 override 제거)를 사용한다. 엔진 머지 동작을 바꾸지 않아 기존
  // responsive 사용처/사용자 페이지에는 영향이 없다.
  const seedClassName =
    scope.breakpoint !== 'base' && touchesClassToken
      ? (() => {
          const base = (node.props ?? {}) as Record<string, unknown>;
          return typeof base.className === 'string' ? base.className : undefined;
        })()
      : undefined;

  return withScopedProps(node, scope, (props, style) => {
    // B안 시드 — scope 컨테이너에 className 이 아직 없고 base 에 className 이 있으면 복사.
    if (seedClassName !== undefined && typeof props.className !== 'string') {
      props.className = seedClassName;
    }

    if (clearGroup) {
      // group 토큰 제거 (classToken 계열) — 다크면 `dark:` 토큰만 대상
      const isGroupToken = buildGroupTokenMatcher(normalized, dark);
      const remaining = tokenize(props.className).filter((t) => !isGroupToken(t));
      if (remaining.length === 0) delete props.className;
      else props.className = remaining.join(' ');
      // styleProp/cssVar/propValue 대상 제거 — 다크 scope 면 인라인 미적용(위 short-circuit)
      if (!dark && normalized.apply) {
        const a = normalized.apply;
        if (a.type === 'styleProp') {
          for (const p of a.props ?? (a.prop ? [a.prop] : [])) delete style[p];
        } else if (a.type === 'cssVar' && a.varName) {
          delete style[a.varName];
        } else if (a.type === 'propValue' && a.propKey) {
          delete props[a.propKey];
        }
      }
    } else if (apply) {
      switch (apply.type) {
        case 'classToken':
          applyClassToken(props, normalized, apply, value, dark);
          break;
        case 'styleProp':
          // 다크는 위 short-circuit 으로 도달 불가 (인라인 no-op)
          applyStyleProp(style, apply, value);
          break;
        case 'cssVar':
          applyCssVar(style, apply, value);
          break;
        case 'propValue':
          applyPropValue(props, apply, value);
          break;
      }
    }
  });
}

/**
 * 역해석 결과 — 컨트롤 현재값 + 매칭 여부.
 *
 * `matched: false` 면 호출자가 "고급 값"(코드 편집기 작성)으로 분류한다.
 * 같은 group 토큰이 2개 이상 충돌하면 `conflict: true` (첫 매칭 우선 + 경고 배지).
 */
export interface ReverseResolution {
  /**
   * 위젯 표시값. scope≠base 면 `scopedValue ?? baseFallback`(오버라이드 없으면 base
   * 상속값을 placeholder 로 보여주기 위함, D6). base scope 면 scopedValue 와 동일.
   */
  value: unknown;
  /** 친화 컨트롤로 역해석됐는지 — false 면 고급값 분류 */
  matched: boolean;
  /** 같은 group 토큰 2개 이상 충돌  */
  conflict?: boolean;
  /**
   * 다크 scope 에서 인라인(styleProp/cssVar/propValue) 컨트롤이라 편집 불가 —
   * 위젯 읽기전용 + "코드 모드에서 편집" 안내(D4).
   */
  darkReadonly?: boolean;
  /**
   * 활성 scope 자체의 값(오버라이드). 없으면 undefined → placeholder 판정에 사용(D6).
   * base scope 면 value 와 동일.
   */
  scopedValue?: unknown;
  /**
   * scope≠base 이고 그 scope 에 오버라이드가 없을 때 base 에서 상속되는 값(D6).
   * 위젯이 흐릿한 placeholder 로 표시한다. base scope 면 undefined.
   */
  baseFallback?: unknown;
  /**
   * scope 컨테이너 자체의 역해석값 (B안 placeholder 보정 **전**, 시드 토큰 포함).
   * `scopedValue` 는 "base 와 다를 때만" 채워지므로(시드/상속 동일값을 placeholder 로
   * 흐리기 위함), 동일값이어도 그 디바이스에 토큰이 물리적으로 존재하는지 알아야 하는
   * 컨트롤(예: flex enable on/off — base=flex 여도 명시 flex 를 인정해야 함, D7/D8)은
   * 이 raw 값을 본다. base scope 면 value 와 동일.
   */
  scopedRawValue?: unknown;
}

/**
 * className 토큰 목록을 scope(dark) 기준으로 필터링·정규화한다.
 *  - base(dark=false): `dark:` 토큰 제외, 그대로.
 *  - dark(dark=true): `dark:` 토큰만 채택, prefix 벗긴 bare 토큰으로 반환.
 *
 * classToken 역해석이 라이트/다크를 독립으로 보도록 만든다.
 *
 * @since engine-v1.50.0
 */
export function scopedClassTokens(className: unknown, dark: boolean): string[] {
  const tokens = tokenize(className);
  if (dark) {
    return tokens.filter(hasDarkPrefix).map(stripDarkPrefix);
  }
  return tokens.filter((t) => !hasDarkPrefix(t));
}

/**
 * 한 group 의 상호배타 토큰(예 테두리/배경 색 패밀리)을 **스킴별**로 교체한다(불변).
 *
 * 셀 색을 라이트/다크 모드별로 분리하기 위한 좌표 변형 헬퍼. 코어
 * `replaceScopedGroupToken` 이 `applyClassToken`/`buildGroupTokenMatcher` 의 스킴 분리
 * 로직을 셀 className 좌표 변형(`setCellColorToken`)에 재사용할 수 있게 추출한 프리미티브다.
 *
 *  - **base(dark=false)**: 비-`dark:` 토큰 중 `groupTokens` 에 속한 것을 제거하고, `token`
 *    이 있으면 그대로(비-`dark:`) 추가한다. `dark:` 토큰은 그대로 보존(다크 편집 독립).
 *  - **dark(dark=true)**: `dark:` 토큰 중 prefix 를 벗긴 bare 가 `groupTokens` 에 속한 것을
 *    제거하고, `token` 이 있으면 `addDarkPrefix` 로 `dark:` 부여해 추가한다. 비-`dark:`
 *    토큰(라이트)은 그대로 보존.
 *
 * `token` 이 빈/undefined 면 group 토큰을 제거만 한다(색 해제). 토큰 어휘(어떤 토큰이 한
 * group 인지)는 호출자(템플릿 카탈로그)가 `groupTokens` 로 공급한다 — 코어는 `dark:`
 * 메커닉만 안다(라이브러리 중립, feedback_layout_editor_no_css_lib_dependency).
 *
 * @param className 현재 className 문자열(없으면 빈 문자열 취급)
 * @param groupTokens 이 group 의 base 형 토큰 전체 목록(예 색 패밀리). bare(비-dark) 형.
 * @param token 적용할 base 형 토큰(빈/undefined = 제거만). bare 형 — dark 면 내부에서 prefix 부여.
 * @param dark 다크 스킴이면 true
 * @return 교체된 새 className 문자열(빈 결과면 '')
 */
export function replaceScopedGroupToken(
  className: unknown,
  groupTokens: string[],
  token: string | undefined | null,
  dark: boolean,
): string {
  const family = new Set(groupTokens.filter((t) => typeof t === 'string' && t.length > 0));
  const isGroup = (tok: string): boolean => {
    if (dark) {
      if (!hasDarkPrefix(tok)) return false;
      return family.has(stripDarkPrefix(tok));
    }
    if (hasDarkPrefix(tok)) return false;
    return family.has(tok);
  };
  const kept = tokenize(className).filter((tok) => !isGroup(tok));
  if (token && token.length > 0) {
    kept.push(dark ? addDarkPrefix(token) : token);
  }
  return kept.join(' ').trim();
}

/**
 * 단일 컨테이너(props/style)에서 컨트롤 현재값을 역해석하는 순수 코어.
 *
 * scope 컨테이너 / base 컨테이너 각각에 동일 로직을 적용하기 위해 분리한다.
 * classToken 은 dark 기준으로 필터링된 토큰 집합에서 해석한다.
 */
function resolveFromContainer(
  normalized: NormalizedControl,
  props: Record<string, unknown>,
  style: Record<string, unknown>,
  dark: boolean,
): ReverseResolution {
  // 택1 컨트롤 — 옵션 apply 와 현재 상태를 대조.
  if (normalized.options && normalized.options.some((o) => o.apply)) {
    const classTokens = scopedClassTokens(props.className, dark);
    const matches: unknown[] = [];
    for (const opt of normalized.options) {
      if (!opt.apply) continue;
      if (optionMatches(opt.apply, classTokens, style, props)) {
        matches.push(opt.value);
      }
    }
    if (matches.length > 0) {
      return {
        value: matches[0],
        matched: true,
        conflict: matches.length > 1 ? true : undefined,
      };
    }
    if (!normalized.apply) {
      return { value: undefined, matched: false };
    }
  }

  const apply = normalized.apply;
  if (!apply) return { value: undefined, matched: false };

  switch (apply.type) {
    case 'styleProp': {
      if (!apply.values && Array.isArray(apply.props) && isImageBundleProps(apply.props)) {
        const img = readImageObject(style);
        return img === undefined
          ? { value: undefined, matched: false }
          : { value: img, matched: true };
      }
      const prop = apply.prop ?? apply.props?.[0];
      if (!prop) return { value: undefined, matched: false };
      const v = style[prop];
      return v === undefined
        ? { value: undefined, matched: false }
        : { value: v, matched: true };
    }
    case 'cssVar': {
      if (!apply.varName) return { value: undefined, matched: false };
      const v = style[apply.varName];
      return v === undefined
        ? { value: undefined, matched: false }
        : { value: v, matched: true };
    }
    case 'propValue': {
      if (!apply.propKey) return { value: undefined, matched: false };
      const v = props[apply.propKey];
      return v === undefined
        ? { value: undefined, matched: false }
        : { value: v, matched: true };
    }
    case 'classToken': {
      const tokens = scopedClassTokens(props.className, dark);
      if (apply.tokenTemplate && apply.tokenTemplate.includes('{value}')) {
        const [pre, post] = apply.tokenTemplate.split('{value}');
        if ((pre ?? '') === '' && (post ?? '') === '' && normalized.groupPrefixes?.length) {
          const matched = tokens.filter((tok) =>
            normalized.groupPrefixes!.some((p) => tok.startsWith(p)),
          );
          return matched.length > 0
            ? { value: matched.join(' '), matched: true }
            : { value: undefined, matched: false };
        }
        for (const tok of tokens) {
          if (tok.startsWith(pre) && tok.endsWith(post ?? '')) {
            const inner = tok.slice(pre.length, post ? tok.length - post.length : undefined);
            return { value: inner, matched: true };
          }
        }
      }
      if (Array.isArray(apply.tokens) && apply.tokens.every((t) => tokens.includes(t))) {
        return { value: true, matched: true };
      }
      return { value: undefined, matched: false };
    }
    default:
      return { value: undefined, matched: false };
  }
}

/** props 에서 style 컨테이너를 안전하게 추출 */
function styleOf(props: Record<string, unknown>): Record<string, unknown> {
  return props.style && typeof props.style === 'object' && !Array.isArray(props.style)
    ? (props.style as Record<string, unknown>)
    : {};
}

/**
 * 기존 노드에서 컨트롤의 현재값을 역해석한다.
 *
 * **각 컨트롤의 `apply` 타입에 맞춰 역해석**한다 — classToken 은
 * className 토큰을, styleProp 은 style 을, cssVar/propValue 는 각자의 대상을
 * 스캔한다(className 만 가정하지 않는다).
 *
 * **scope**: scope 미지정 시 `BASE_SCOPE` — 오늘과 동일(회귀).
 * scope≠base 면 scope 컨테이너(`responsive[bp].props`)에서 읽되, 오버라이드가 없으면
 * base 컨테이너에서 fallback 값을 함께 계산해 placeholder 표시에 쓴다(D6). 다크 scope 의
 * 인라인(styleProp/cssVar/propValue) 컨트롤은 편집 불가 → `darkReadonly: true`(D4).
 *
 * @param node 대상 노드
 * @param control 컨트롤 정의
 * @param scope 활성 StyleScope (기본 BASE_SCOPE)
 * @return 역해석 결과
 */
export function reverseResolve(
  node: EditorNode,
  control: EditorControlSpec,
  scope: StyleScope = BASE_SCOPE,
): ReverseResolution {
  const normalized = normalize(control);
  const dark = scope.colorScheme === 'dark';

  // 다크 scope + 인라인 컨트롤(classToken 아님) → 읽기전용(D4, 무손실 보존)
  if (dark && !isDarkEditable(normalized.apply) && !normalized.options?.some((o) => isDarkEditable(o.apply))) {
    return { value: undefined, matched: false, darkReadonly: true };
  }

  // scope 컨테이너에서 역해석
  const scopedProps = getScopedProps(node, scope);
  const scopedStyle = styleOf(scopedProps);
  const scoped = resolveFromContainer(normalized, scopedProps, scopedStyle, dark);

  if (scope.breakpoint === 'base') {
    // base scope — 오늘과 바이트 동일(회귀 무변경). scopedValue/baseFallback 미부여
    // (placeholder 개념은 scope≠base 전용 — D6). 단, 다크 base 는 색 모드만 분기.
    return scoped;
  }

  // scope 컨테이너 raw 역해석값(B안 보정 전) — flex enable 등 존재성 컨트롤용(D7/D8).
  const scopedRawValue = scoped.matched ? scoped.value : undefined;

  // scope≠base — 오버라이드가 있으면 그 값, 없으면 base fallback 을 placeholder 로(D6).
  // base fallback 은 항상 base scope(라이트/공통)에서 읽되 색 모드는 현 scope 의 색 모드를
  // 유지(다크 디바이스 탭이면 base 노드의 dark: 토큰 상속).
  const baseProps = (node.props ?? {}) as Record<string, unknown>;
  const baseStyle = styleOf(baseProps);
  const baseResolved = resolveFromContainer(normalized, baseProps, baseStyle, dark);

  // B안 시드 보정:
  // 디바이스 scope 컨테이너에는 className 편집 시 base className 전체가 시드된다(얕은 머지
  // 대응). 따라서 scope 컨테이너에 그 컨트롤 group 토큰이 "있다"는 사실만으로는 사용자가
  // 그 디바이스에서 명시 변경했다고 볼 수 없다 — 시드된 base 값일 수 있다. 이를 구분해 D6
  // placeholder 를 정확히 유지하려면, **scope 값이 base 값과 같으면 시드(또는 상속)로 보고
  // placeholder 취급**(흐릿), 다를 때만 진짜 override 로 본다. 이로써 시드된 base 토큰들은
  // 전부 placeholder 로 흐려지고, 사용자가 실제 바꾼 컨트롤만 진하게 표시된다.
  const scopedHasValue = scoped.matched && scoped.value !== undefined;
  const isRealOverride =
    scopedHasValue && !deepEqual(scoped.value, baseResolved.value);

  return {
    value: scopedHasValue ? scoped.value : baseResolved.value,
    matched: isRealOverride ? scoped.matched : baseResolved.matched,
    conflict: isRealOverride ? scoped.conflict : baseResolved.conflict,
    // scopedValue 는 "진짜 override" 일 때만 — 시드/상속 동일값은 placeholder 판정되도록 undefined.
    scopedValue: isRealOverride ? scoped.value : undefined,
    baseFallback: isRealOverride ? undefined : baseResolved.value,
    // raw 값은 보정 없이 scope 컨테이너 자체 — 존재성 컨트롤(flex enable)이 base 동일값도 인정.
    scopedRawValue,
  };
}

/** 얕은 동등 비교 (문자열/숫자/불리언은 ===, 객체/배열은 JSON 직렬화 동등) */
function deepEqual(a: unknown, b: unknown): boolean {
  if (a === b) return true;
  if (a === null || b === null || typeof a !== 'object' || typeof b !== 'object') return false;
  try {
    return JSON.stringify(a) === JSON.stringify(b);
  } catch {
    return false;
  }
}

/** 한 옵션의 apply 가 노드 현재 상태와 일치하는지 */
function optionMatches(
  apply: RecipeApply,
  classTokens: string[],
  style: Record<string, unknown>,
  props: Record<string, unknown>,
): boolean {
  switch (apply.type) {
    case 'classToken':
      return Array.isArray(apply.tokens) && apply.tokens.length > 0
        ? apply.tokens.every((t) => classTokens.includes(t))
        : false;
    case 'styleProp': {
      const prop = apply.prop ?? apply.props?.[0];
      if (!prop) return false;
      const expected = apply.value;
      return expected !== undefined
        ? style[prop] === expected
        : style[prop] !== undefined;
    }
    case 'cssVar':
      return apply.varName ? style[apply.varName] !== undefined : false;
    case 'propValue': {
      if (!apply.propKey) return false;
      return apply.value !== undefined
        ? props[apply.propKey] === apply.value
        : props[apply.propKey] !== undefined;
    }
    default:
      return false;
  }
}
