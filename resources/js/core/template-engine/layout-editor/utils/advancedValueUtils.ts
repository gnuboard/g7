/**
 * advancedValueUtils.ts — "고급 값"(코드 편집기 작성) 판정
 *
 * 속성 편집 모달이 친화 컨트롤로 다루지 않고 무손실 보존만 하는 속성을 식별한다
 * (원칙 4.4). 두 부류:
 *
 *  1. 순수 개발자 속성 — `data_binding`/`isolatedScopeId`/`parentFormContextProp`/
 *     `skipBindingKeys`/`isolatedState`/`component_layout`/`sortable`/`itemTemplate`.
 *  2. 복잡 표현식을 담은 값 — 파이프 함수(`| date`)/`$switch`/`$get`/`{{...}}` 바인딩.
 *
 * 이들은 `[고급]` 탭에 읽기 전용 목록으로 표시되고 저장 시 그대로 직렬화된다.
 *
 * **`text` 의 보간 예외**: `text` 에 박힌 `{{...}}` 보간 중 편집기가 [속성] 탭
 * "텍스트 데이터 연결"(InlineBindingSection)로 직접 제어하는 **단일 경로 조각**은 "코드 편집기
 * 작성 고급값"이 아니다 — 레이아웃 편집기로 추가/교체/해제하는 정당한 변경이다. 따라서 text 의
 * 보간이 **전부 parseable(단일 경로)**이면 고급 대상에서 제외한다(배지/목록 미노출). 삼항/필터/
 * Math 등 **복합 조각이 하나라도** 있으면 그 부분만은 코드 편집 위임이라 고급으로 유지한다.
 * (text 외 prop 의 복잡 표현식은 종전대로 — 9-b 는 text 내부 보간만 편집 표면을 신설했다.)
 *
 * @since engine-v1.50.0
 */

import type { EditorNode } from './layoutTreeUtils';
import { toInlineBindingRows, isParamizedKeyText } from '../spec/inlineBindingUtils';

/** 본질적 개발자 영역 속성 키 (b) */
export const DEVELOPER_ONLY_PROP_KEYS = [
  'data_binding',
  'isolatedScopeId',
  'parentFormContextProp',
  'skipBindingKeys',
  'isolatedState',
  'component_layout',
  'sortable',
  'itemTemplate',
] as const;

/** 복잡 표현식 패턴 — 파이프/$switch/$get/바인딩 */
const COMPLEX_EXPR_RE = /\{\{|\$switch|\$get|\|\s*(date|number|truncate|currency|relative)\b/;

/** 값에 복잡 표현식이 포함되는지 (문자열/중첩 객체·배열 재귀 검사) */
export function containsComplexExpression(value: unknown, depth = 0): boolean {
  if (depth > 6) return false; // 무한 재귀 가드
  if (typeof value === 'string') return COMPLEX_EXPR_RE.test(value);
  if (Array.isArray(value)) return value.some((v) => containsComplexExpression(v, depth + 1));
  if (value && typeof value === 'object') {
    return Object.values(value as Record<string, unknown>).some((v) =>
      containsComplexExpression(v, depth + 1),
    );
  }
  return false;
}

/**
 * `text` 문자열이 "코드 편집 위임" 보간을 포함하는지.
 *
 * text 의 표현식은 모두 `{{...}}` 보간 안에 담긴다(파이프/삼항/Math 포함). 편집기 [속성]
 * 탭 "텍스트 데이터 연결"이 단일 경로 조각은 직접 제어하므로, 보간이 **전부 parseable**이면
 * 고급(코드 편집기 작성)이 아니다. **복합 조각(isComplex)이 하나라도** 있으면 그 부분은
 * 편집기가 못 다루는 코드 영역이라 고급으로 본다. 보간이 0 개면 순수 평문 → 고급 아님.
 *
 * @param text 검사할 text 값
 * @return 복합 보간 조각이 하나라도 있으면 true
 */
export function textHasComplexBinding(text: string): boolean {
  // named-param 키화 형태(`$t:...|pN={{expr}}`) 예외 — 사용자가 기성 다국어키
  // 텍스트를 인라인 편집해 데이터를 칩으로 연결하면 node.text 가 이 형태가 된다. 그 `|pN={{}}`
  // 보간은 편집기 [번역] 탭 칩(InlineParamChipEditor)이 직접 제어하는 정당한 데이터 연결이지
  // 코드 편집 산물이 아니다. expr 에 파이프(`| date`)가 있어도 칩으로 표현되므로 "고급"이 아니다
  // (이 형태를 고급으로 판정하면 [번역] 탭이 "코드 편집기 고급 설정 포함"으로 차단돼 로케일별
  // 칩 편집이 불가해진다). 따라서 param 부착 키 텍스트는 complex 에서 제외한다.
  if (isParamizedKeyText(text)) return false;
  const rows = toInlineBindingRows(text);
  if (rows.length === 0) return false; // 보간 0 = 순수 평문.
  return rows.some((r) => r.isComplex);
}

/** 고급 항목 1건 — `[고급]` 탭의 읽기 전용 목록 표시용 */
export interface AdvancedValueEntry {
  /** 속성 경로 (`props.data_binding` / `text` 등) */
  key: string;
  /** 분류 — 개발자 속성 / 복잡 표현식 */
  kind: 'developer_prop' | 'complex_expression';
}

/**
 * 노드에서 고급 값(코드 편집기 작성) 항목을 수집한다.
 *
 * @param node 대상 노드
 * @return 고급 항목 목록 (없으면 빈 배열)
 */
export function collectAdvancedValues(node: EditorNode): AdvancedValueEntry[] {
  const out: AdvancedValueEntry[] = [];
  const props = (node.props ?? {}) as Record<string, unknown>;

  // 1. 순수 개발자 속성 — 노드 직속 또는 props 안
  for (const key of DEVELOPER_ONLY_PROP_KEYS) {
    if (key in node && (node as Record<string, unknown>)[key] !== undefined) {
      out.push({ key, kind: 'developer_prop' });
    } else if (key in props && props[key] !== undefined) {
      out.push({ key: `props.${key}`, kind: 'developer_prop' });
    }
  }

  // 2. 복잡 표현식 — text + 각 prop 값
  //    text 는 9-b 예외: 보간이 전부 parseable(편집기 데이터 연결로 제어 가능)이면 고급 아님.
  //    복합 조각(삼항/필터/Math)이 하나라도 있을 때만 고급으로 유지(코드 편집 위임).
  if (typeof node.text === 'string') {
    if (textHasComplexBinding(node.text)) {
      out.push({ key: 'text', kind: 'complex_expression' });
    }
  } else if (containsComplexExpression(node.text)) {
    out.push({ key: 'text', kind: 'complex_expression' });
  }
  for (const [k, v] of Object.entries(props)) {
    if (k === 'style' || k === 'className') continue; // 스타일은 스타일 탭이 다룸
    if (containsComplexExpression(v)) {
      out.push({ key: `props.${k}`, kind: 'complex_expression' });
    }
  }

  return out;
}

/** 노드에 고급 값이 하나라도 있는지 (모달 상단 배지 표시 판정) */
export function hasAdvancedValues(node: EditorNode): boolean {
  return collectAdvancedValues(node).length > 0;
}
