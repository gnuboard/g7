/**
 * 템플릿 엔진 헬퍼 함수 통합 모듈
 *
 * 템플릿 개발자를 위한 헬퍼 함수들을 통합 export합니다.
 * 향후 추가되는 헬퍼 모듈들은 여기서 re-export하여
 * 일관된 진입점을 제공합니다.
 *
 * @packageDocumentation
 *
 * @example
 * ```typescript
 * // 템플릿 엔진에서 import
 * import { renderItemChildren } from './template-engine/helpers';
 *
 * // 또는 개별 모듈에서 직접 import
 * import { renderItemChildren } from './template-engine/helpers/RenderHelpers';
 * ```
 */

// ============================================
// 렌더링 헬퍼
// ============================================

export {
  renderItemChildren,
  bindComponentActions,
  evaluateIfCondition,
  evaluateRenderCondition,
  resolveClassMap,
  type RenderItemChildrenOptions,
  type BindComponentActionsOptions,
  type EvaluateRenderConditionOptions,
  type ClassMapDefinition,
  type ResolveClassMapOptions,
} from './RenderHelpers';

// ============================================
// 이벤트 헬퍼
// ============================================

export {
  createChangeEvent,
  createClickEvent,
  createSubmitEvent,
  createKeyboardEvent,
  type CreateChangeEventOptions,
  type CreateClickEventOptions,
} from './EventHelpers';

// ============================================
// 스타일 헬퍼
// ============================================

export {
  mergeClasses,
  conditionalClass,
  joinClasses,
} from './StyleHelpers';

// ============================================
// 파이프 함수
// ============================================

export {
  PipeRegistry,
  pipeRegistry,
  hasPipes,
  splitPipes,
  executePipeChain,
  parsePipeExpression,
  type PipeFunction,
  type ParsedPipe,
} from '../PipeRegistry';

// ============================================
// 조건 평가 헬퍼
// ============================================

export {
  evaluateStringCondition,
  evaluateConditionExpression,
  evaluateConditionBranches,
  evaluateConditions,
  isConditionExpression,
  isConditionBranches,
  type ConditionExpression,
  type ConditionBranch,
  type ConditionsProperty,
  type ConditionBranchResult,
  type ActionDefinition,
} from './ConditionEvaluator';

// ============================================
// 향후 추가 예정 헬퍼 모듈 예시
// ============================================

// export { formatValue, formatCurrency, formatDate } from './FormatHelpers';
// export { validateField, validateForm } from './ValidationHelpers';
