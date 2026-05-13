/**
 * validationUtils.ts
 *
 * G7 위지윅 레이아웃 편집기의 유효성 검증 유틸리티
 *
 * 역할:
 * - 레이아웃 JSON 스키마 검증
 * - 컴포넌트 정의 검증
 * - 데이터 바인딩 표현식 검증
 * - 액션 핸들러 검증
 * - 다크 모드 클래스 검증
 */

import type {
  LayoutData,
  ComponentDefinition,
  ValidationResult,
  ValidationError,
  ValidationWarning,
  ComponentMetadata,
  ActionDefinition,
  DataSource,
} from '../types/editor';
import {
  flattenComponents,
  isIdDuplicate,
  collectAllIds,
} from './layoutUtils';

// ============================================================================
// 상수 정의
// ============================================================================

/**
 * 유효한 레이아웃 name 패턴 (a-z, 0-9, _, /, -, . 만 허용)
 */
const VALID_LAYOUT_NAME_PATTERN = /^[a-z0-9_/\-\.]+$/;

/**
 * 유효한 컴포넌트 타입
 */
const VALID_COMPONENT_TYPES = ['basic', 'composite', 'layout', 'extension_point'];

/**
 * 다크 모드 필수 쌍 (배경색)
 */
const DARK_MODE_BG_PAIRS: Record<string, string> = {
  'bg-white': 'dark:bg-gray-800',
  'bg-gray-50': 'dark:bg-gray-900',
  'bg-gray-100': 'dark:bg-gray-700',
  'bg-gray-200': 'dark:bg-gray-600',
};

/**
 * 다크 모드 필수 쌍 (텍스트색)
 */
const DARK_MODE_TEXT_PAIRS: Record<string, string> = {
  'text-gray-900': 'dark:text-white',
  'text-gray-800': 'dark:text-gray-100',
  'text-gray-700': 'dark:text-gray-200',
  'text-gray-600': 'dark:text-gray-300',
  'text-gray-500': 'dark:text-gray-400',
};

/**
 * 다크 모드 필수 쌍 (테두리색)
 */
const DARK_MODE_BORDER_PAIRS: Record<string, string> = {
  'border-gray-200': 'dark:border-gray-700',
  'border-gray-300': 'dark:border-gray-600',
  'border-gray-400': 'dark:border-gray-500',
};

/**
 * 지원되는 내장 액션 핸들러
 */
const BUILT_IN_HANDLERS = [
  'navigate',
  'navigateBack',
  'navigateForward',
  'apiCall',
  'login',
  'logout',
  'setState',
  'setError',
  'openModal',
  'closeModal',
  'showAlert',
  'toast',
  'switch',
  'sequence',
  'parallel',
  'refetchDataSource',
  'remount',
  'reloadRoutes',
  'reloadTranslations',
  'refresh',
  'stopPropagation',
  'preventDefault',
  'showErrorPage',
  'loadScript',
  'callExternal',
  'callExternalEmbed',
  'saveToLocalStorage',
  'loadFromLocalStorage',
  'custom',
];

/**
 * 데이터 소스 타입
 */
const VALID_DATA_SOURCE_TYPES = ['api', 'static', 'route_params', 'query_params', 'websocket'];

/**
 * 로딩 전략
 */
const VALID_LOADING_STRATEGIES = ['blocking', 'progressive', 'background'];

// ============================================================================
// 레이아웃 검증
// ============================================================================

/**
 * 레이아웃 전체 검증
 *
 * @param layoutData 검증할 레이아웃 데이터
 * @param componentRegistry 등록된 컴포넌트 메타데이터 (옵션)
 * @returns 검증 결과
 */
export function validateLayout(
  layoutData: LayoutData,
  componentRegistry?: ComponentMetadata[]
): ValidationResult {
  const errors: ValidationError[] = [];
  const warnings: ValidationWarning[] = [];

  // 필수 필드 검증
  validateRequiredFields(layoutData, errors);

  // 레이아웃 이름 검증
  validateLayoutName(layoutData.layout_name, errors);

  // 버전 검증
  validateVersion(layoutData.version, errors, warnings);

  // 컴포넌트 검증
  if (layoutData.components) {
    validateComponents(layoutData.components, errors, warnings, componentRegistry);
  }

  // 데이터 소스 검증
  if (layoutData.data_sources) {
    validateDataSources(layoutData.data_sources, errors, warnings);
  }

  // 모달 검증
  if (layoutData.modals) {
    validateModals(layoutData.modals, errors, warnings, componentRegistry);
  }

  // init_actions 검증
  if (layoutData.init_actions) {
    validateInitActions(layoutData.init_actions, errors, warnings);
  }

  // ID 중복 검증 (components가 배열인 경우에만)
  if (Array.isArray(layoutData.components)) {
    validateUniqueIds(layoutData.components, errors);
  }

  return {
    valid: errors.length === 0,
    errors,
    warnings,
  };
}

/**
 * 필수 필드 검증
 */
function validateRequiredFields(
  layoutData: LayoutData,
  errors: ValidationError[]
): void {
  if (!layoutData.version) {
    errors.push({
      code: 'MISSING_VERSION',
      message: 'Layout version is required',
      path: 'version',
      severity: 'error',
    });
  }

  if (!layoutData.layout_name) {
    errors.push({
      code: 'MISSING_LAYOUT_NAME',
      message: 'Layout name is required',
      path: 'layout_name',
      severity: 'error',
    });
  }

  if (!layoutData.components && !layoutData.extends) {
    errors.push({
      code: 'MISSING_COMPONENTS',
      message: 'Components array is required (or extends must be specified)',
      path: 'components',
      severity: 'error',
    });
  }
}

/**
 * 레이아웃 이름 검증
 */
function validateLayoutName(
  name: string | undefined,
  errors: ValidationError[]
): void {
  if (!name) return;

  if (!VALID_LAYOUT_NAME_PATTERN.test(name)) {
    errors.push({
      code: 'INVALID_LAYOUT_NAME',
      message: `Layout name '${name}' contains invalid characters. Only a-z, 0-9, _, /, -, . are allowed.`,
      path: 'layout_name',
      severity: 'error',
    });
  }
}

/**
 * 버전 검증
 */
function validateVersion(
  version: string | undefined,
  errors: ValidationError[],
  warnings: ValidationWarning[]
): void {
  if (!version) return;

  // 시맨틱 버전 형식 검증 (프리릴리스 + 빌드 메타데이터 지원)
  const semverPattern =
    /^\d+\.\d+\.\d+(-[a-zA-Z0-9]+(\.[a-zA-Z0-9]+)*)?(\+[a-zA-Z0-9]+(\.[a-zA-Z0-9]+)*)?$/;
  if (!semverPattern.test(version)) {
    warnings.push({
      code: 'INVALID_VERSION_FORMAT',
      message: `Version '${version}' does not follow semantic versioning (X.Y.Z[-prerelease][+build])`,
      path: 'version',
      severity: 'warning',
    });
  }
}

// ============================================================================
// 컴포넌트 검증
// ============================================================================

/**
 * 컴포넌트 배열 검증
 */
function validateComponents(
  components: (ComponentDefinition | string)[],
  errors: ValidationError[],
  warnings: ValidationWarning[],
  componentRegistry?: ComponentMetadata[],
  parentPath: string = 'components'
): void {
  for (let i = 0; i < components.length; i++) {
    const component = components[i];
    const path = `${parentPath}[${i}]`;

    if (typeof component === 'string') {
      // 문자열 직접 배치 금지
      errors.push({
        code: 'STRING_IN_CHILDREN',
        message: 'Direct string in children is not allowed. Use text property or Span component.',
        path,
        severity: 'error',
      });
      continue;
    }

    validateComponent(component, errors, warnings, componentRegistry, path);

    // 자식 컴포넌트 재귀 검증
    if (component.children) {
      validateComponents(
        component.children,
        errors,
        warnings,
        componentRegistry,
        `${path}.children`
      );
    }
  }
}

/**
 * 개별 컴포넌트 검증
 */
function validateComponent(
  component: ComponentDefinition,
  errors: ValidationError[],
  warnings: ValidationWarning[],
  componentRegistry?: ComponentMetadata[],
  path: string = 'component'
): void {
  // 필수 필드 검증
  if (!component.id) {
    errors.push({
      code: 'MISSING_COMPONENT_ID',
      message: 'Component id is required',
      path: `${path}.id`,
      severity: 'error',
    });
  }

  if (!component.type) {
    errors.push({
      code: 'MISSING_COMPONENT_TYPE',
      message: 'Component type is required',
      path: `${path}.type`,
      severity: 'error',
    });
  } else if (!VALID_COMPONENT_TYPES.includes(component.type)) {
    errors.push({
      code: 'INVALID_COMPONENT_TYPE',
      message: `Invalid component type '${component.type}'. Valid types: ${VALID_COMPONENT_TYPES.join(', ')}`,
      path: `${path}.type`,
      severity: 'error',
    });
  }

  if (!component.name) {
    errors.push({
      code: 'MISSING_COMPONENT_NAME',
      message: 'Component name is required',
      path: `${path}.name`,
      severity: 'error',
    });
  }

  // 컴포넌트 레지스트리 검증
  if (componentRegistry && component.name) {
    const metadata = componentRegistry.find((c) => c.name === component.name);
    if (!metadata) {
      warnings.push({
        code: 'UNKNOWN_COMPONENT',
        message: `Component '${component.name}' is not registered in the component registry`,
        path: `${path}.name`,
        severity: 'warning',
      });
    }
  }

  // 다크 모드 클래스 검증
  if (component.props?.className) {
    validateDarkModeClasses(
      component.props.className,
      `${path}.props.className`,
      warnings
    );
  }

  // 액션 검증
  if (component.actions) {
    validateActions(component.actions, errors, warnings, `${path}.actions`);
  }

  // if 조건 검증
  if (component.if) {
    validateExpression(component.if, `${path}.if`, errors, warnings);
  }

  // iteration 검증
  if (component.iteration) {
    validateIteration(component.iteration, `${path}.iteration`, errors);
  }

  // data_binding 검증
  if (component.data_binding) {
    for (const [key, expr] of Object.entries(component.data_binding)) {
      validateExpression(expr, `${path}.data_binding.${key}`, errors, warnings);
    }
  }

  // lifecycle 검증
  if (component.lifecycle) {
    if (component.lifecycle.onMount) {
      validateActions(component.lifecycle.onMount, errors, warnings, `${path}.lifecycle.onMount`);
    }
    if (component.lifecycle.onUnmount) {
      validateActions(component.lifecycle.onUnmount, errors, warnings, `${path}.lifecycle.onUnmount`);
    }
  }
}

// ============================================================================
// 다크 모드 검증
// ============================================================================

/**
 * 다크 모드 클래스 쌍 검증
 */
function validateDarkModeClasses(
  className: string,
  path: string,
  warnings: ValidationWarning[]
): void {
  if (!className || typeof className !== 'string') return;

  // 바인딩 표현식인 경우 스킵
  if (className.includes('{{')) return;

  const classes = className.split(/\s+/);

  // 배경색 검증
  for (const [light, dark] of Object.entries(DARK_MODE_BG_PAIRS)) {
    if (classes.includes(light) && !classes.includes(dark)) {
      warnings.push({
        code: 'MISSING_DARK_MODE_CLASS',
        message: `Background class '${light}' should have dark mode pair '${dark}'`,
        path,
        severity: 'warning',
      });
    }
  }

  // 텍스트색 검증
  for (const [light, dark] of Object.entries(DARK_MODE_TEXT_PAIRS)) {
    if (classes.includes(light) && !classes.includes(dark)) {
      warnings.push({
        code: 'MISSING_DARK_MODE_CLASS',
        message: `Text class '${light}' should have dark mode pair '${dark}'`,
        path,
        severity: 'warning',
      });
    }
  }

  // 테두리색 검증
  for (const [light, dark] of Object.entries(DARK_MODE_BORDER_PAIRS)) {
    if (classes.includes(light) && !classes.includes(dark)) {
      warnings.push({
        code: 'MISSING_DARK_MODE_CLASS',
        message: `Border class '${light}' should have dark mode pair '${dark}'`,
        path,
        severity: 'warning',
      });
    }
  }
}

// ============================================================================
// 표현식 검증
// ============================================================================

/**
 * 바인딩 표현식 검증
 */
function validateExpression(
  expression: string,
  path: string,
  errors: ValidationError[],
  warnings: ValidationWarning[]
): void {
  if (!expression || typeof expression !== 'string') return;

  // {{}} 표현식 패턴 확인 (빈 표현식도 매칭하기 위해 * 사용)
  const bindingPattern = /\{\{([^}]*)\}\}/g;
  const matches = expression.matchAll(bindingPattern);

  for (const match of matches) {
    const innerExpression = match[1].trim();

    // 빈 표현식 체크
    if (!innerExpression) {
      errors.push({
        code: 'EMPTY_EXPRESSION',
        message: 'Empty binding expression {{}}',
        path,
        severity: 'error',
      });
      continue;
    }

    // 위험한 패턴 체크
    const dangerousPatterns = [
      'eval',
      'Function',
      'constructor',
      '__proto__',
      'prototype',
      'window.',
      'document.',
      'process.',
    ];

    for (const pattern of dangerousPatterns) {
      if (innerExpression.includes(pattern)) {
        errors.push({
          code: 'DANGEROUS_EXPRESSION',
          message: `Potentially dangerous pattern '${pattern}' in expression`,
          path,
          severity: 'error',
        });
      }
    }
  }

  // $t: 다국어 표현식 검증
  if (expression.startsWith('$t:')) {
    const keyPart = expression.slice(3);

    // defer 접두사 처리
    const actualKey = keyPart.startsWith('defer:') ? keyPart.slice(6) : keyPart;

    // 파라미터 분리
    const pipeIndex = actualKey.indexOf('|');
    const translationKey = pipeIndex !== -1 ? actualKey.slice(0, pipeIndex) : actualKey;

    // 빈 키 체크
    if (!translationKey) {
      errors.push({
        code: 'EMPTY_TRANSLATION_KEY',
        message: 'Empty translation key in $t: expression',
        path,
        severity: 'error',
      });
    }
  }
}

/**
 * iteration 설정 검증
 */
function validateIteration(
  iteration: { source: string; item_var: string; index_var?: string },
  path: string,
  errors: ValidationError[]
): void {
  if (!iteration.source) {
    errors.push({
      code: 'MISSING_ITERATION_SOURCE',
      message: 'Iteration source is required',
      path: `${path}.source`,
      severity: 'error',
    });
  }

  if (!iteration.item_var) {
    errors.push({
      code: 'MISSING_ITERATION_ITEM_VAR',
      message: 'Iteration item_var is required',
      path: `${path}.item_var`,
      severity: 'error',
    });
  }

  // 예약어 체크
  const reservedWords = ['_global', '_local', '_computed', 'route', 'query', 'form'];
  if (reservedWords.includes(iteration.item_var)) {
    errors.push({
      code: 'RESERVED_ITEM_VAR',
      message: `Item variable name '${iteration.item_var}' is reserved`,
      path: `${path}.item_var`,
      severity: 'error',
    });
  }

  if (iteration.index_var && reservedWords.includes(iteration.index_var)) {
    errors.push({
      code: 'RESERVED_INDEX_VAR',
      message: `Index variable name '${iteration.index_var}' is reserved`,
      path: `${path}.index_var`,
      severity: 'error',
    });
  }
}

// ============================================================================
// 액션 검증
// ============================================================================

/**
 * 액션 배열 검증
 */
function validateActions(
  actions: ActionDefinition[],
  errors: ValidationError[],
  warnings: ValidationWarning[],
  path: string
): void {
  for (let i = 0; i < actions.length; i++) {
    const action = actions[i];
    const actionPath = `${path}[${i}]`;

    validateAction(action, errors, warnings, actionPath);
  }
}

/**
 * 개별 액션 검증
 */
function validateAction(
  action: ActionDefinition,
  errors: ValidationError[],
  warnings: ValidationWarning[],
  path: string
): void {
  if (!action.handler) {
    errors.push({
      code: 'MISSING_ACTION_HANDLER',
      message: 'Action handler is required',
      path: `${path}.handler`,
      severity: 'error',
    });
    return;
  }

  // 핸들러 타입 검증
  const handlerName = action.handler.includes('.')
    ? action.handler.split('.').pop()!
    : action.handler;

  // 내장 핸들러 또는 모듈 핸들러 (vendor.handler 형식)
  const isBuiltIn = BUILT_IN_HANDLERS.includes(handlerName);
  const isModuleHandler = action.handler.includes('.');

  if (!isBuiltIn && !isModuleHandler) {
    warnings.push({
      code: 'UNKNOWN_HANDLER',
      message: `Unknown action handler '${action.handler}'. Is this a custom handler?`,
      path: `${path}.handler`,
      severity: 'warning',
    });
  }

  // 핸들러별 필수 파라미터 검증
  validateActionParams(action, errors, path);

  // onSuccess/onError 재귀 검증
  if (action.onSuccess) {
    validateActions(action.onSuccess, errors, warnings, `${path}.onSuccess`);
  }

  if (action.onError) {
    validateActions(action.onError, errors, warnings, `${path}.onError`);
  }

  // sequence/parallel의 actions 검증
  if (action.handler === 'sequence' || action.handler === 'parallel') {
    if (action.params?.actions) {
      validateActions(action.params.actions, errors, warnings, `${path}.params.actions`);
    }
  }

  // switch의 cases 검증
  if (action.handler === 'switch' && action.cases) {
    for (const [key, caseActions] of Object.entries(action.cases)) {
      if (Array.isArray(caseActions)) {
        validateActions(caseActions as ActionDefinition[], errors, warnings, `${path}.cases.${key}`);
      }
    }
  }
}

/**
 * 액션 파라미터 검증
 */
function validateActionParams(
  action: ActionDefinition,
  errors: ValidationError[],
  path: string
): void {
  const handler = action.handler;

  switch (handler) {
    case 'navigate':
      if (!action.params?.path && !action.target) {
        errors.push({
          code: 'MISSING_NAVIGATE_PATH',
          message: 'Navigate action requires path parameter or target',
          path,
          severity: 'error',
        });
      }
      break;

    case 'apiCall':
      if (!action.target) {
        errors.push({
          code: 'MISSING_API_TARGET',
          message: 'apiCall action requires target (endpoint)',
          path,
          severity: 'error',
        });
      }
      break;

    case 'openModal':
      if (!action.target) {
        errors.push({
          code: 'MISSING_MODAL_ID',
          message: 'openModal action requires target (modal id)',
          path,
          severity: 'error',
        });
      }
      break;

    case 'refetchDataSource':
      if (!action.params?.dataSourceId) {
        errors.push({
          code: 'MISSING_DATASOURCE_ID',
          message: 'refetchDataSource action requires dataSourceId parameter',
          path,
          severity: 'error',
        });
      }
      break;

    case 'toast':
      if (!action.params?.message && !action.target) {
        errors.push({
          code: 'MISSING_TOAST_MESSAGE',
          message: 'toast action requires message parameter',
          path,
          severity: 'error',
        });
      }
      break;

    case 'switch':
      if (!action.params?.value && !action.cases) {
        errors.push({
          code: 'MISSING_SWITCH_VALUE',
          message: 'switch action requires value parameter and cases',
          path,
          severity: 'error',
        });
      }
      break;
  }
}

// ============================================================================
// 데이터 소스 검증
// ============================================================================

/**
 * 데이터 소스 배열 검증
 */
function validateDataSources(
  dataSources: DataSource[],
  errors: ValidationError[],
  warnings: ValidationWarning[]
): void {
  const ids = new Set<string>();

  for (let i = 0; i < dataSources.length; i++) {
    const ds = dataSources[i];
    const path = `data_sources[${i}]`;

    // ID 필수
    if (!ds.id) {
      errors.push({
        code: 'MISSING_DATASOURCE_ID',
        message: 'Data source id is required',
        path: `${path}.id`,
        severity: 'error',
      });
    } else {
      // ID 중복 체크
      if (ids.has(ds.id)) {
        errors.push({
          code: 'DUPLICATE_DATASOURCE_ID',
          message: `Duplicate data source id '${ds.id}'`,
          path: `${path}.id`,
          severity: 'error',
        });
      }
      ids.add(ds.id);
    }

    // 타입 필수
    if (!ds.type) {
      errors.push({
        code: 'MISSING_DATASOURCE_TYPE',
        message: 'Data source type is required',
        path: `${path}.type`,
        severity: 'error',
      });
    } else if (!VALID_DATA_SOURCE_TYPES.includes(ds.type)) {
      errors.push({
        code: 'INVALID_DATASOURCE_TYPE',
        message: `Invalid data source type '${ds.type}'. Valid types: ${VALID_DATA_SOURCE_TYPES.join(', ')}`,
        path: `${path}.type`,
        severity: 'error',
      });
    }

    // API 타입 필수 필드
    if (ds.type === 'api' && !ds.endpoint) {
      errors.push({
        code: 'MISSING_DATASOURCE_ENDPOINT',
        message: 'API data source requires endpoint',
        path: `${path}.endpoint`,
        severity: 'error',
      });
    }

    // 로딩 전략 검증
    if (ds.loading_strategy && !VALID_LOADING_STRATEGIES.includes(ds.loading_strategy)) {
      warnings.push({
        code: 'INVALID_LOADING_STRATEGY',
        message: `Invalid loading strategy '${ds.loading_strategy}'. Valid strategies: ${VALID_LOADING_STRATEGIES.join(', ')}`,
        path: `${path}.loading_strategy`,
        severity: 'warning',
      });
    }

    // errorCondition 검증
    if ((ds as any).errorCondition) {
      const ec = (ds as any).errorCondition;
      if (!ec.if) {
        errors.push({
          code: 'MISSING_ERROR_CONDITION_IF',
          message: 'errorCondition requires "if" expression',
          path: `${path}.errorCondition.if`,
          severity: 'error',
        });
      }
      if (ec.errorCode === undefined || typeof ec.errorCode !== 'number') {
        errors.push({
          code: 'MISSING_ERROR_CONDITION_CODE',
          message: 'errorCondition requires numeric "errorCode"',
          path: `${path}.errorCondition.errorCode`,
          severity: 'error',
        });
      }
      if (!ds.errorHandling) {
        warnings.push({
          code: 'ERROR_CONDITION_WITHOUT_HANDLING',
          message: 'errorCondition is defined but errorHandling is missing. The error condition will match but no handler will execute.',
          path: `${path}.errorCondition`,
          severity: 'warning',
        });
      }
    }
  }
}

// ============================================================================
// 모달 검증
// ============================================================================

/**
 * 모달 배열 검증
 */
function validateModals(
  modals: any[],
  errors: ValidationError[],
  warnings: ValidationWarning[],
  componentRegistry?: ComponentMetadata[]
): void {
  const ids = new Set<string>();

  for (let i = 0; i < modals.length; i++) {
    const modal = modals[i];
    const path = `modals[${i}]`;

    // ID 필수
    if (!modal.id) {
      errors.push({
        code: 'MISSING_MODAL_ID',
        message: 'Modal id is required',
        path: `${path}.id`,
        severity: 'error',
      });
    } else {
      // ID 중복 체크
      if (ids.has(modal.id)) {
        errors.push({
          code: 'DUPLICATE_MODAL_ID',
          message: `Duplicate modal id '${modal.id}'`,
          path: `${path}.id`,
          severity: 'error',
        });
      }
      ids.add(modal.id);
    }

    // 모달 컴포넌트 검증
    if (modal.components) {
      validateComponents(
        modal.components,
        errors,
        warnings,
        componentRegistry,
        `${path}.components`
      );
    }
  }
}

// ============================================================================
// init_actions 검증
// ============================================================================

/**
 * 초기화 액션 검증
 */
function validateInitActions(
  initActions: any[],
  errors: ValidationError[],
  warnings: ValidationWarning[]
): void {
  for (let i = 0; i < initActions.length; i++) {
    const action = initActions[i];
    const path = `init_actions[${i}]`;

    validateAction(action, errors, warnings, path);
  }
}

// ============================================================================
// ID 중복 검증
// ============================================================================

/**
 * 컴포넌트 ID 중복 검증
 *
 * @description
 * LayoutData.components는 LayoutComponent[] 타입이지만,
 * 실제 JSON 스키마에서는 id 필드가 포함되어 있음.
 * 타입 호환성을 위해 unknown[]로 받고 내부에서 처리.
 */
function validateUniqueIds(
  components: unknown[],
  errors: ValidationError[]
): void {
  // unknown[] 타입을 (ComponentDefinition | string)[]로 캐스팅
  // collectAllIds는 id 필드가 있는 객체만 처리함
  const ids = collectAllIds(components as (ComponentDefinition | string)[]);
  const seen = new Set<string>();

  for (const id of ids) {
    if (seen.has(id)) {
      errors.push({
        code: 'DUPLICATE_COMPONENT_ID',
        message: `Duplicate component id '${id}'`,
        path: `components`,
        severity: 'error',
      });
    }
    seen.add(id);
  }
}

// ============================================================================
// 유틸리티 함수
// ============================================================================

/**
 * 검증 결과 포맷팅
 */
export function formatValidationResult(result: ValidationResult): string {
  const lines: string[] = [];

  if (result.valid) {
    lines.push('Validation passed');
  } else {
    lines.push('Validation failed');
  }

  if (result.errors.length > 0) {
    lines.push('\nErrors:');
    for (const error of result.errors) {
      lines.push(`  [${error.code}] ${error.message} (at ${error.path})`);
    }
  }

  if (result.warnings.length > 0) {
    lines.push('\nWarnings:');
    for (const warning of result.warnings) {
      lines.push(`  [${warning.code}] ${warning.message} (at ${warning.path})`);
    }
  }

  return lines.join('\n');
}

/**
 * 빠른 유효성 체크 (에러만)
 */
export function quickValidate(layoutData: LayoutData): boolean {
  const result = validateLayout(layoutData);
  return result.valid;
}

// ============================================================================
// Export
// ============================================================================

export default {
  validateLayout,
  validateComponent,
  validateExpression,
  validateDarkModeClasses,
  validateAction,
  validateDataSources,
  formatValidationResult,
  quickValidate,
};
