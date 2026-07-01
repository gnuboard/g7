// e2e:allow 속성 위젯 레지스트리 타입/등록 — 칩·키화 결선용 후보 풀 prop 추가. 칩 입력기·contentEditable·합성 클릭 의존으로 Playwright 부적합, 단위(widgetRegistry/OptionsListControl 등)+Chrome MCP 매트릭스로 검증 (계획 정책)
/**
 * widgetRegistry.ts — 컨트롤 위젯 레지스트리
 *
 * `editor-spec.json` 의 컨트롤이 선언한 `widget` 종류를 실제 React 컨트롤
 * 컴포넌트로 매핑한다. 코어는 위젯 컴포넌트만 제공하고, 어떤 코드 형식을
 * 생성할지는 컨트롤의 `apply` 레시피가 정한다(원칙 4.8). recipeEngine 이
 * apply ↔ reverseResolve 를 담당하고, 본 레지스트리는 "값을 입력받는 UI" 만
 * 디스패치한다.
 *
 * Phase 4 위젯: `segmented` / `slider` / `select` / `toggle` / `color` /
 * `image` / `tag-input`. 나머지(`flex`/`page-picker`/`condition-builder` 등)는
 * Phase 5/6 에서 등록 자리를 채운다.
 *
 * 등록 API — 확장/후속 Phase 가 신규 위젯을 추가할 수 있도록 `registerWidget`
 * 을 노출한다(원칙 4.1 — 코어는 메커니즘만). 미등록 위젯은 `getWidget` 이 null
 * 을 돌려주고, ControlRenderer 가 "지원하지 않는 위젯" 폴백을 렌더한다.
 *
 * @since engine-v1.50.0
 */

import type React from 'react';
import type { EditorControlSpec } from './specTypes';

/**
 * 위젯 컴포넌트가 받는 공통 props.
 *
 * 값 표현은 위젯마다 다르다(segmented=옵션 value, color=HEX 문자열, tag-input=
 * 문자열 배열 등). 코어 위젯은 `value`/`onChange` 만으로 동작하고, 코드 생성은
 * ControlRenderer 가 recipeEngine 으로 처리한다.
 */
export interface WidgetProps {
  /** 컨트롤 정의 — 옵션/스케일/라벨 등 */
  control: EditorControlSpec;
  /** 현재값 (reverseResolve 결과). undefined = 기본/미적용 */
  value: unknown;
  /** 값 변경 — undefined 전달 시 "기본/미적용" 으로 해석 */
  onChange: (value: unknown) => void;
  /** 다국어 해석 함수 — 라벨/옵션 라벨 `$t:` 키 해석 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /**
   * tag-input 등 후보 목록이 필요한 위젯에 호출자가 공급(a-2).
   * 권한 키 후보 = 코어 + 활성 확장 권한.
   */
  candidates?: Array<{ value: string; label: string }>;
  /**
   * 데이터 연결 검색 후보 풀. 위 `candidates`(tag-input용
   * `{value,label}[]`)와 타입이 달라 별도 prop. 목록형 위젯(OptionsListControl 등)이 각 항목의
   * 텍스트 필드(`I18nTextField`)에 `+데이터` 칩 삽입(키화) 후보로 흘려보낸다. ControlRenderer 가
   * 모달의 `bindingCandidates` 를 그대로 주입(미전달 시 빈 검색 — 디그레이드). i18n-text propControl
   * 분기(ControlRenderer L132)와 동일 후보 풀을 일반 위젯에도 닿게 한다.
   */
  bindingCandidates?: import('./bindingCandidates').BindingCandidate[];
  /**
   * 다크 scope 에서 **자유값 입력만** 막아야 하는 위젯(color/dimension)에 전달되는
   * 부분 게이트 플래그.
   *
   * ControlRenderer 의 전면 darkReadonly(인라인 styleProp 전체 차단)와 달리, 색/크기
   * 컨트롤이 classToken(프리셋 토큰 + tokenTemplate 자유값) 으로 선언된 경우 프리셋은
   * 다크에서도 적용 가능하지만 자유값(HEX/임의 px)은 `dark:text-[#hex]` 가 빌드 불가라
   * 막아야 한다. 위젯은 이 플래그가 true 면 자유 입력칸을 disabled + 프리셋 안내한다.
   */
  freeValueDisabled?: boolean;
}

/** 위젯 컴포넌트 타입 */
export type WidgetComponent = React.ComponentType<WidgetProps>;

/** 내부 레지스트리 — widget 이름 → 컴포넌트 */
const registry = new Map<string, WidgetComponent>();

/**
 * 위젯 등록. 같은 이름 재등록은 덮어쓰기(후속 Phase/확장이 코어 위젯을
 * 교체할 수 있도록 — 단, 코어 기본 위젯은 부팅 시 1회 등록).
 *
 * @param name 위젯 이름 (`segmented` 등)
 * @param component 위젯 컴포넌트
 */
export function registerWidget(name: string, component: WidgetComponent): void {
  registry.set(name, component);
}

/**
 * 등록된 위젯 컴포넌트 조회. 미등록이면 null(ControlRenderer 폴백).
 *
 * @param name 위젯 이름
 * @return 위젯 컴포넌트 또는 null
 */
export function getWidget(name: string | undefined): WidgetComponent | null {
  if (!name) return null;
  return registry.get(name) ?? null;
}

/** 등록된 위젯 이름 목록 (진단/테스트용) */
export function getRegisteredWidgetNames(): string[] {
  return Array.from(registry.keys());
}

/** 레지스트리 초기화 (테스트 격리용) */
export function clearWidgetRegistry(): void {
  registry.clear();
}
