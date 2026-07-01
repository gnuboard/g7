/**
 * textComponents.ts — 텍스트(보간) 데이터 연결 대상 컴포넌트 집합 코어 SSoT
 *
 * 9-a 권고(쟁점 2 정정): 텍스트 보유 컴포넌트 **집합 한정** + 컴팩트 "+데이터 삽입"
 * 토글. `text` prop 에 데이터 연결(보간)을 다루는 [속성] 탭 "텍스트 데이터 연결" 영역은
 * 본 집합에 속한 컴포넌트(또는 string `text` 를 실제 보유한 노드)에만 노출한다.
 *
 * 왜 코어 SSoT 인가: 9-a 전수 스캔에서 보간 `text` 의 99%가 동일한 기본 텍스트 컴포넌트
 * (Span/P/H1~H6/A/Button/Li/Label) 에 몰려 있고, 이는 라이브러리 중립적인 "텍스트를 그리는
 * HTML 래핑 컴포넌트" 라는 보편 집합이다. coreProps(요소 id)와 같은 코어 제공 모델로 둔다
 * (`coreProps.ts` 선례 — 컨트롤만 제공, 값은 표준 node.props/text). 템플릿이 자체 텍스트
 * 컴포넌트를 더 노출하려면 capability `textBinding:true`(또는 코어 폴백)로 opt-in 한다.
 *
 * @since engine-v1.50.0
 */

import type { EditorNode } from '../utils/layoutTreeUtils';

/**
 * 코어가 텍스트 데이터 연결 대상으로 인정하는 기본 컴포넌트 이름 집합(12종).
 *
 * 9-a 분포: Span/P 가 절대다수, 그 외 H1~H6/A/Button/Li/Label. 라이브러리 중립적인
 * "텍스트 그리는 컴포넌트" 보편 집합이다. 대소문자는 컴포넌트 등록명 그대로(파스칼).
 */
export const CORE_TEXT_COMPONENTS: ReadonlySet<string> = new Set([
  'Span',
  'P',
  'H1',
  'H2',
  'H3',
  'H4',
  'H5',
  'H6',
  'A',
  'Button',
  'Li',
  'Label',
]);

/**
 * 이 노드가 텍스트(보간) 데이터 연결 대상인지 판정한다.
 *
 * 판정 순서(어느 하나라도 충족하면 대상):
 *  1. capability 의 `textBinding === false` → 명시 opt-out(대상 아님).
 *  2. capability 의 `textBinding === true` → 명시 opt-in(템플릿 자체 텍스트 컴포넌트).
 *  3. 컴포넌트 이름이 코어 텍스트 집합(`CORE_TEXT_COMPONENTS`)에 속함.
 *  4. (폴백) 노드가 string `text` prop 을 실제 보유 → 갓 추가한 평문 텍스트도 입구 제공.
 *
 * iteration 노드(데이터 행마다 반복 렌더)는 단일 텍스트 편집 대상이 아니므로 제외한다
 * (인라인 편집 차단과 동일 기준 — `useInlineEdit.isDataBoundNode`). 반복 소스 바인딩은
 * IterationBindingSection 이 담당한다(축 분리).
 *
 * @param node 대상 노드
 * @param capability 그 컴포넌트의 capability(textBinding 선언 조회)
 * @returns 텍스트 데이터 연결 대상이면 true
 */
export function isTextBindableNode(
  node: EditorNode | null | undefined,
  capability: { textBinding?: unknown } | null | undefined,
): boolean {
  if (!node) return false;
  // iteration 노드는 텍스트 인라인 바인딩 대상 아님(반복 소스는 별도 축).
  if (node.iteration && typeof node.iteration === 'object') return false;

  const tb = capability?.textBinding;
  if (tb === false) return false;
  if (tb === true) return true;

  const name = typeof node.name === 'string' ? node.name : '';
  if (CORE_TEXT_COMPONENTS.has(name)) return true;

  // 폴백 — string text 를 실제 보유한 노드(평문/보간 무관). 갓 추가한 텍스트도 입구 확보.
  return typeof node.text === 'string';
}
