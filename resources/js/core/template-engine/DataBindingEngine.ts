/**
 * 그누보드7 템플릿 엔진 - 데이터 바인딩 엔진
 *
 * {{variable}} 문법을 파싱하고 실제 데이터로 치환하는 엔진
 *
 * @package G7
 * @subpackage Template Engine
 * @since 1.0.0
 */

import { createLogger } from '../utils/Logger';
import { TranslationEngine } from './TranslationEngine';
import { hasPipes, splitPipes, executePipeChain } from './PipeRegistry';
import { RAW_PREFIX, wrapRaw, wrapRawDeep } from './rawMarkers';
import type { G7DevToolsInterface } from './G7CoreGlobals';

const logger = createLogger('DataBindingEngine');

/**
 * G7Core.devTools 인터페이스 가져오기
 *
 * G7DevToolsCore.getInstance() 직접 호출 대신 G7Core.devTools를 사용합니다.
 * DevTools가 비활성화되거나 초기화되지 않은 경우 안전하게 undefined 반환
 */
function getDevTools(): G7DevToolsInterface | undefined {
  try {
    const G7Core = (window as any).G7Core;
    return G7Core?.devTools;
  } catch {
    return undefined;
  }
}

/**
 * 바인딩 컨텍스트 인터페이스
 *
 * 데이터 바인딩 시 사용되는 컨텍스트 객체
 */
export interface BindingContext {
  [key: string]: any;
}

/**
 * 해석된 값 타입
 *
 * 바인딩 결과로 반환되는 값
 */
export type ResolvedValue = string | number | boolean | null | undefined | object | Array<any>;

/**
 * 바인딩 옵션 인터페이스
 */
export interface BindingOptions {
  /**
   * 기본값 (변수를 찾을 수 없을 때 반환)
   */
  defaultValue?: any;

  /**
   * null-safe 모드 활성화
   */
  nullSafe?: boolean;

  /**
   * 순환 참조 감지 활성화
   */
  detectCircular?: boolean;

  /**
   * 최대 중첩 깊이 (순환 참조 방지)
   */
  maxDepth?: number;

  /**
   * 캐시 사용 여부 (기본값: true)
   * false로 설정하면 캐싱을 건너뜀
   * 반복 렌더링(DataGrid의 cellChildren 등)에서 같은 경로가 다른 값을 가져야 할 때 사용
   */
  skipCache?: boolean;
}

/**
 * 캐시 엔트리 인터페이스
 */
interface CacheEntry {
  /**
   * 캐시된 값
   */
  value: ResolvedValue;

  /**
   * 캐시 생성 시간
   */
  timestamp: number;
}

/**
 * 데이터 바인딩 엔진 에러 클래스
 */
export class DataBindingError extends Error {
  constructor(
    message: string,
    public readonly path: string,
    public readonly context?: any,
  ) {
    super(message);
    this.name = 'DataBindingError';
  }
}

/**
 * 데이터 바인딩 엔진 클래스
 *
 * {{variable}} 문법을 파싱하고 실제 데이터로 치환
 */
export class DataBindingEngine {
  /**
   * 바인딩 패턴 정규식
   *
   * {{variable}}, {{object.property}}, {{array[0]}} 등을 매칭
   */
  private static readonly BINDING_PATTERN = /\{\{([^}]+)\}\}/g;

  /**
   * 경로 파싱 정규식
   *
   * 중첩 객체 접근 (.) 및 배열 인덱스 ([n]) 파싱
   */
  private static readonly PATH_SEGMENT_PATTERN = /([^.[\]]+)|\[(\d+)\]/g;

  /**
   * 캐시 만료 시간 (밀리초)
   *
   * 성능 최적화를 위해 30초로 설정
   * - 바인딩 경로 재계산 빈도 감소
   * - _global 경로는 캐싱되지 않으므로 동적 데이터 처리에 영향 없음
   */
  private static readonly CACHE_EXPIRY = 30000; // 30초

  /**
   * 값 캐시 맵
   *
   * key: 바인딩 경로, value: 캐시 엔트리
   */
  private cache: Map<string, CacheEntry> = new Map();

  /**
   * 표현식 함수 캐시
   *
   * key: 전처리된 표현식, value: 컴파일된 Function
   * - Function 생성자 비용이 높으므로 동일 표현식 재사용 시 캐시에서 조회
   * - 표현식 자체만 캐싱하고, 컨텍스트 값은 실행 시 전달
   */
  private expressionFnCache: Map<string, Function> = new Map();

  /**
   * 렌더 사이클 캐시
   *
   * 같은 렌더 사이클 내에서 동일 표현식 재평가 방지
   * - 렌더 시작 시 startRenderCycle()로 클리어
   * - _local, _global 등 동적 경로도 같은 렌더 내에서는 캐시 사용
   * - 65,496회 → ~5,000회 바인딩 평가 감소 목표
   */
  private renderCycleCache: Map<string, any> = new Map();

  /**
   * 현재 렌더 사이클 ID
   *
   * 매 렌더 시작 시 증가하여 캐시 유효성 판단에 사용
   */
  private currentRenderCycleId: number = 0;

  /**
   * 기본 옵션
   */
  private defaultOptions: BindingOptions = {
    defaultValue: undefined,
    nullSafe: true,
    detectCircular: true,
    maxDepth: 10,
  };

  /**
   * 순환 참조 추적용 Set (각 resolve 호출마다 로컬로 생성)
   */
  // 인스턴스 레벨에서 제거 - 각 resolvePath 호출에서 로컬로 관리

  /**
   * 생성자
   *
   * @param options 기본 옵션
   */
  constructor(options?: Partial<BindingOptions>) {
    if (options) {
      this.defaultOptions = { ...this.defaultOptions, ...options };
    }
  }

  /**
   * 새 렌더 사이클 시작 - 렌더 사이클 캐시 클리어
   *
   * DynamicRenderer 루트 컴포넌트에서 렌더링 시작 시 호출됩니다.
   * 이를 통해 같은 렌더 사이클 내에서 동일 표현식 재평가를 방지하면서도,
   * 새 렌더 시 최신 상태값으로 평가됩니다.
   *
   * @example
   * ```ts
   * // DynamicRenderer.tsx 루트 컴포넌트
   * useLayoutEffect(() => {
   *   if (!parentComponentContext) {
   *     bindingEngine.startRenderCycle();
   *   }
   * });
   * ```
   */
  public startRenderCycle(): void {
    this.currentRenderCycleId++;
    this.renderCycleCache.clear();
  }

  /**
   * 렌더 사이클 캐시 활성화 여부 확인
   *
   * startRenderCycle()이 호출된 적이 있어야 렌더 사이클 캐시 활성화
   * 테스트 환경 등에서 startRenderCycle()을 호출하지 않으면 기존 동작 유지
   */
  private isRenderCycleCacheActive(): boolean {
    return this.currentRenderCycleId > 0;
  }

  /**
   * 렌더 사이클 캐시에서 값 조회
   *
   * @param key 캐시 키 (표현식 문자열)
   * @returns 캐시된 값 또는 undefined (렌더 사이클이 활성화되지 않으면 항상 undefined)
   */
  private getFromRenderCycleCache(key: string): any | undefined {
    if (!this.isRenderCycleCacheActive()) {
      return undefined;
    }
    return this.renderCycleCache.get(key);
  }

  /**
   * 렌더 사이클 캐시에 값 저장
   *
   * @param key 캐시 키 (표현식 문자열)
   * @param value 저장할 값
   * @note 렌더 사이클이 활성화되지 않으면 저장하지 않음
   */
  private saveToRenderCycleCache(key: string, value: any): void {
    if (!this.isRenderCycleCacheActive()) {
      return;
    }
    this.renderCycleCache.set(key, value);
  }

  /**
   * 문자열 내의 모든 바인딩 표현식을 데이터로 치환
   *
   * @param template 바인딩 표현식이 포함된 문자열
   * @param context 데이터 컨텍스트
   * @param options 바인딩 옵션
   * @param trackingInfo DevTools 표현식 추적 정보 (선택적)
   * @returns 치환된 문자열
   *
   * @example
   * const engine = new DataBindingEngine();
   * const result = engine.resolveBindings("Hello {{user.name}}", { user: { name: "홍길동" } });
   * // 결과: "Hello 홍길동"
   */
  public resolveBindings(
    template: string,
    context: BindingContext,
    options?: BindingOptions,
    trackingInfo?: { componentId?: string; componentName?: string; propName?: string },
  ): string {
    const opts = { ...this.defaultOptions, ...options };
    const devTools = getDevTools();

    // $computed alias 추가
    // layout-json-features.md 문서에 따라 $computed.xxx 형식으로 computed 값에 접근
    // 내부적으로 _computed에 저장되므로 $computed를 _computed의 alias로 설정
    const extendedContext: BindingContext = context._computed && !context.$computed
      ? { ...context, $computed: context._computed }
      : context;

    return template.replace(DataBindingEngine.BINDING_PATTERN, (_match, path) => {
      // 공백 제거
      const trimmedPath = path.trim();

      // raw: 접두사 감지 — 번역 면제 바인딩
      // @since engine-v1.27.0
      let isRawBinding = false;
      let effectivePath = trimmedPath;
      if (trimmedPath.startsWith(RAW_PREFIX)) {
        isRawBinding = true;
        effectivePath = trimmedPath.slice(RAW_PREFIX.length);
      }

      // 파이프 함수 처리 (예: {{post.created_at | date}})
      // 파이프가 있으면 먼저 분리하여 처리
      if (hasPipes(effectivePath)) {
        const pipeStartTime = devTools?.isEnabled() ? performance.now() : 0;
        try {
          const [baseExpr, pipes] = splitPipes(effectivePath);

          // 기본 표현식 평가 (파이프 적용 전)
          let value: any;
          let fromCache = false;
          const isBaseExpression = /[?:|&!+\-*/<>=()]/.test(baseExpr) || /\[['"]/.test(baseExpr);
          // 동적 경로 여부 판단
          const isDynamicPipeBase = baseExpr.startsWith('_global') ||
            baseExpr.startsWith('_local') ||
            baseExpr.startsWith('_isolated') ||
            baseExpr.startsWith('$parent');

          if (opts.skipCache) {
            // skipCache: 모든 캐시 무시
            if (isBaseExpression) {
              value = this.evaluateExpression(baseExpr, extendedContext);
            } else {
              value = this.resolvePath(baseExpr, extendedContext, opts);
            }
          } else if (isBaseExpression) {
            // 복잡한 표현식: 렌더 사이클 캐시 사용
            const exprCached = this.getFromRenderCycleCache(`expr:${baseExpr}`);
            if (exprCached !== undefined) {
              value = exprCached;
              fromCache = true;
            } else {
              value = this.evaluateExpression(baseExpr, extendedContext);
              this.saveToRenderCycleCache(`expr:${baseExpr}`, value);
            }
          } else if (isDynamicPipeBase) {
            // 동적 경로: 렌더 사이클 캐시 사용
            const renderCycleCached = this.getFromRenderCycleCache(baseExpr);
            if (renderCycleCached !== undefined) {
              value = renderCycleCached;
              fromCache = true;
            } else {
              value = this.resolvePath(baseExpr, extendedContext, opts);
              this.saveToRenderCycleCache(baseExpr, value);
            }
          } else {
            // 정적 경로: 영구 캐시 사용
            const cached = this.getFromCache(baseExpr);
            if (cached !== undefined) {
              value = cached;
              fromCache = true;
            } else {
              value = this.resolvePath(baseExpr, extendedContext, opts);
              this.saveToCache(baseExpr, value);
            }
          }

          // 파이프 체인 실행
          const result = executePipeChain(value, pipes);

          // DevTools: 파이프 표현식 평가 추적
          if (devTools?.isEnabled()) {
            const duration = performance.now() - pipeStartTime;
            devTools.trackExpressionEval({
              expression: `{{${trimmedPath}}}`,
              result: this.sanitizeResultForTracking(result),
              resultType: this.getResultType(result),
              componentId: trackingInfo?.componentId,
              componentName: trackingInfo?.componentName,
              propName: trackingInfo?.propName,
              fromCache,
              duration,
              method: 'resolveBindings',
              skipCache: opts.skipCache,
            });
          }

          const formatted = this.formatValue(result);
          return isRawBinding ? wrapRaw(formatted) : formatted;
        } catch (error) {
          logger.error('Pipe expression evaluation failed:', effectivePath, error);
          return _match; // 원본 반환
        }
      }

      // 삼항 연산자나 복잡한 표현식 감지 (?, :, ||, &&, !, 산술 연산자, 함수 호출 등)
      // 대괄호 안에 따옴표가 있는 문자열 키 접근도 표현식으로 처리 (예: query['filters[0][field]'])
      // 함수 호출 패턴도 표현식으로 처리 (예: $localized(...))
      // 주의: 단일 | (파이프)는 위에서 먼저 처리되므로 여기서는 || 만 표현식으로 인식
      const isExpression = /[?:&!+\-*/<>=()]/.test(effectivePath) || /\|\|/.test(effectivePath) || /\[['"]/.test(effectivePath);

      if (isExpression) {
        // JavaScript 표현식으로 평가
        const exprStartTime = devTools?.isEnabled() ? performance.now() : 0;
        try {
          let value: any;
          let fromCache = false;

          // skipCache가 아니면 렌더 사이클 캐시 사용
          if (!opts.skipCache) {
            const exprCacheKey = `expr:${effectivePath}`;
            const exprCached = this.getFromRenderCycleCache(exprCacheKey);
            if (exprCached !== undefined) {
              value = exprCached;
              fromCache = true;
            } else {
              value = this.evaluateExpression(effectivePath, extendedContext);
              this.saveToRenderCycleCache(exprCacheKey, value);
            }
          } else {
            value = this.evaluateExpression(effectivePath, extendedContext);
          }

          // DevTools: 복잡한 표현식 평가 추적
          if (devTools?.isEnabled()) {
            const duration = performance.now() - exprStartTime;
            devTools.trackExpressionEval({
              expression: `{{${trimmedPath}}}`,
              result: this.sanitizeResultForTracking(value),
              resultType: this.getResultType(value),
              componentId: trackingInfo?.componentId,
              componentName: trackingInfo?.componentName,
              propName: trackingInfo?.propName,
              fromCache,
              duration,
              method: 'resolveBindings',
              skipCache: opts.skipCache,
            });
          }

          const formatted = this.formatValue(value);
          return isRawBinding ? wrapRaw(formatted) : formatted;
        } catch (error) {
          logger.error('Expression evaluation failed:', effectivePath, error);
          return _match; // 원본 반환
        }
      }

      // skipCache 옵션이면 모든 캐시 사용 안 함
      if (opts.skipCache) {
        const startTime = devTools?.isEnabled() ? performance.now() : 0;
        const value = this.resolvePath(effectivePath, extendedContext, opts);

        // DevTools: 표현식 평가 추적
        if (devTools?.isEnabled()) {
          const duration = performance.now() - startTime;
          devTools.trackExpressionEval({
            expression: `{{${trimmedPath}}}`,
            result: this.sanitizeResultForTracking(value),
            resultType: this.getResultType(value),
            componentId: trackingInfo?.componentId,
            componentName: trackingInfo?.componentName,
            propName: trackingInfo?.propName,
            fromCache: false,
            duration,
            method: 'resolveBindings',
            skipCache: true,
          });
        }

        const formatted = this.formatValue(value);
        return isRawBinding ? wrapRaw(formatted) : formatted;
      }

      // 동적 경로 여부 판단
      const isDynamicPath = effectivePath.startsWith('_global') ||
        effectivePath.startsWith('_local') ||
        effectivePath.startsWith('_isolated') ||
        effectivePath.startsWith('$parent');

      const startTime = devTools?.isEnabled() ? performance.now() : 0;
      let value: any;
      let fromCache = false;

      if (isDynamicPath) {
        // 동적 경로: 렌더 사이클 캐시 사용
        const renderCycleCached = this.getFromRenderCycleCache(effectivePath);
        if (renderCycleCached !== undefined) {
          value = renderCycleCached;
          fromCache = true;
        } else {
          value = this.resolvePath(effectivePath, extendedContext, opts);
          this.saveToRenderCycleCache(effectivePath, value);
        }
      } else {
        // 정적 경로: 영구 캐시 사용
        const cached = this.getFromCache(effectivePath);
        if (cached !== undefined) {
          value = cached;
          fromCache = true;
        } else {
          value = this.resolvePath(effectivePath, extendedContext, opts);
          this.saveToCache(effectivePath, value);
        }
      }

      // DevTools: 표현식 평가 추적
      if (devTools?.isEnabled()) {
        const duration = performance.now() - startTime;
        devTools.trackExpressionEval({
          expression: `{{${trimmedPath}}}`,
          result: this.sanitizeResultForTracking(value),
          resultType: this.getResultType(value),
          componentId: trackingInfo?.componentId,
          componentName: trackingInfo?.componentName,
          propName: trackingInfo?.propName,
          fromCache,
          duration,
          method: 'resolveBindings',
          skipCache: opts.skipCache,
        });
      }

      const formatted = this.formatValue(value);
      return isRawBinding ? wrapRaw(formatted) : formatted;
    });
  }

  /**
   * 단일 바인딩 경로의 값을 해석
   *
   * @param path 바인딩 경로 (예: "user.profile.name")
   * @param context 데이터 컨텍스트
   * @param options 바인딩 옵션
   * @param trackingInfo DevTools 표현식 추적 정보 (선택적)
   * @returns 해석된 값
   *
   * @example
   * const engine = new DataBindingEngine();
   * const value = engine.resolve("user.name", { user: { name: "홍길동" } });
   * // 결과: "홍길동"
   */
  public resolve<T = ResolvedValue>(
    path: string,
    context: BindingContext,
    options?: BindingOptions,
    trackingInfo?: { componentId?: string; componentName?: string; propName?: string },
  ): T {
    const opts = { ...this.defaultOptions, ...options };
    const devTools = getDevTools();
    const startTime = devTools?.isEnabled() ? performance.now() : 0;

    // skipCache 옵션이 true면 모든 캐시 사용 안 함 (반복 렌더링에서 같은 경로가 다른 값을 가져야 할 때)
    if (opts.skipCache) {
      const value = this.resolvePath(path, context, opts);

      // DevTools: 표현식 평가 추적
      if (devTools?.isEnabled()) {
        devTools.trackExpressionEval({
          expression: path,
          result: this.sanitizeResultForTracking(value),
          resultType: this.getResultType(value),
          componentId: trackingInfo?.componentId,
          componentName: trackingInfo?.componentName,
          propName: trackingInfo?.propName,
          fromCache: false,
          duration: performance.now() - startTime,
          method: 'resolve',
          skipCache: true,
        });
      }

      return value as T;
    }

    // 동적 경로 여부 판단
    // - _global, _local, _isolated, $parent: 동적으로 자주 변경되므로 영구 캐시 사용 안 함
    // - 단, 같은 렌더 사이클 내에서는 렌더 사이클 캐시 사용 (성능 최적화)
    const isDynamicPath = path.startsWith('_global') || path.startsWith('_local') || path.startsWith('_isolated') || path.startsWith('$parent');
    let fromCache = false;

    // 1. 렌더 사이클 캐시 확인 (동적 경로용)
    // 같은 렌더 사이클 내에서 동일 표현식 재평가 방지
    if (isDynamicPath) {
      const renderCycleCached = this.getFromRenderCycleCache(path);
      if (renderCycleCached !== undefined) {
        fromCache = true;

        // DevTools: 렌더 사이클 캐시 히트 추적
        if (devTools?.isEnabled()) {
          devTools.trackExpressionEval({
            expression: path,
            result: this.sanitizeResultForTracking(renderCycleCached),
            resultType: this.getResultType(renderCycleCached),
            componentId: trackingInfo?.componentId,
            componentName: trackingInfo?.componentName,
            propName: trackingInfo?.propName,
            fromCache: true,
            duration: performance.now() - startTime,
            method: 'resolve',
            skipCache: opts.skipCache,
          });
        }

        return renderCycleCached as T;
      }
    } else {
      // 2. 영구 캐시 확인 (정적 경로용)
      const cached = this.getFromCache(path);
      if (cached !== undefined) {
        fromCache = true;

        // DevTools: 영구 캐시 히트 추적
        if (devTools?.isEnabled()) {
          devTools.trackExpressionEval({
            expression: path,
            result: this.sanitizeResultForTracking(cached),
            resultType: this.getResultType(cached),
            componentId: trackingInfo?.componentId,
            componentName: trackingInfo?.componentName,
            propName: trackingInfo?.propName,
            fromCache: true,
            duration: performance.now() - startTime,
            method: 'resolve',
            skipCache: opts.skipCache,
          });
        }

        return cached as T;
      }
    }

    // 값 해석
    const value = this.resolvePath(path, context, opts);

    // 캐시 저장
    if (isDynamicPath) {
      // 동적 경로: 렌더 사이클 캐시에 저장
      this.saveToRenderCycleCache(path, value);
    } else {
      // 정적 경로: 영구 캐시에 저장
      this.saveToCache(path, value);
    }

    // DevTools: 표현식 평가 추적
    if (devTools?.isEnabled()) {
      devTools.trackExpressionEval({
        expression: path,
        result: this.sanitizeResultForTracking(value),
        resultType: this.getResultType(value),
        componentId: trackingInfo?.componentId,
        componentName: trackingInfo?.componentName,
        propName: trackingInfo?.propName,
        fromCache,
        duration: performance.now() - startTime,
        method: 'resolve',
        skipCache: opts.skipCache,
      });
    }

    return value as T;
  }

  /**
   * 경로를 파싱하여 중첩된 값을 해석
   *
   * @param path 바인딩 경로
   * @param context 데이터 컨텍스트
   * @param options 바인딩 옵션
   * @param depth 현재 중첩 깊이
   * @param visitedObjects 순환 참조 추적용 Set (내부 재귀 호출용)
   * @returns 해석된 값
   * @throws {DataBindingError} 순환 참조 감지 시 또는 최대 깊이 초과 시
   */
  private resolvePath(
    path: string,
    context: BindingContext,
    options: BindingOptions,
    depth: number = 0,
    visitedObjects: WeakSet<object> = new WeakSet(),
  ): ResolvedValue {
    // 최대 깊이 체크 (순환 참조 방지)
    if (options.maxDepth && depth > options.maxDepth) {
      if (options.detectCircular) {
        throw new DataBindingError(
          `Maximum depth exceeded (${options.maxDepth}). Possible circular reference.`,
          path,
          context,
        );
      }
      return options.defaultValue;
    }

    // 경로 세그먼트 파싱
    const segments = this.parsePath(path);

    // 값 추출
    let current: any = context;

    for (let i = 0; i < segments.length; i++) {
      const segment = segments[i];

      // null/undefined 체크 (null-safe)
      if (current == null) {
        if (options.nullSafe) {
          return options.defaultValue;
        }
        throw new DataBindingError(
          `Cannot read property '${segment}' of ${current}`,
          path,
          context,
        );
      }

      // 순환 참조 감지 (객체인 경우만)
      if (options.detectCircular && typeof current === 'object') {
        if (visitedObjects.has(current)) {
          throw new DataBindingError(
            `Circular reference detected at path: ${segments.slice(0, i + 1).join('.')}`,
            path,
            context,
          );
        }
        visitedObjects.add(current);
      }

      // 다음 값으로 이동
      current = current[segment];
      // 디버깅 로그
      if (path === '_global.toasts') {
        logger.log(`[DataBindingEngine] segment: ${segment}, current:`, current);
      }
    }

    // 값이 없으면 기본값 반환
    return current !== undefined ? current : options.defaultValue;
  }

  /**
   * 경로 문자열을 세그먼트 배열로 파싱
   *
   * @param path 바인딩 경로
   * @returns 세그먼트 배열
   *
   * @example
   * parsePath("user.profile.name") // ["user", "profile", "name"]
   * parsePath("items[0].name")     // ["items", "0", "name"]
   * parsePath("data[1][2]")        // ["data", "1", "2"]
   */
  private parsePath(path: string): string[] {
    const segments: string[] = [];
    let match: RegExpExecArray | null;

    // 정규식 초기화
    DataBindingEngine.PATH_SEGMENT_PATTERN.lastIndex = 0;

    while ((match = DataBindingEngine.PATH_SEGMENT_PATTERN.exec(path)) !== null) {
      // match[1]: 일반 프로퍼티 (예: "user", "name")
      // match[2]: 배열 인덱스 (예: "0", "1")
      const segment = match[1] || match[2];
      if (segment) {
        segments.push(segment);
      }
    }

    return segments;
  }

  /**
   * 값을 문자열로 포맷
   *
   * @param value 해석된 값
   * @returns 문자열 표현
   */
  private formatValue(value: ResolvedValue): string {
    // null 또는 undefined
    if (value == null) {
      return '';
    }

    // 객체 또는 배열
    if (typeof value === 'object') {
      try {
        return JSON.stringify(value);
      } catch (error) {
        return '[Object]';
      }
    }

    // 기본 타입 (string, number, boolean)
    return String(value);
  }

  /**
   * 캐시에서 값 조회
   *
   * @param path 바인딩 경로
   * @returns 캐시된 값 (없으면 undefined)
   */
  private getFromCache(path: string): ResolvedValue | undefined {
    const entry = this.cache.get(path);
    const devTools = getDevTools();

    if (!entry) {
      // DevTools: 캐시 미스 기록
      if (devTools?.isEnabled()) {
        devTools.recordCacheMiss();
      }
      return undefined;
    }

    // 캐시 만료 체크
    const now = Date.now();
    if (now - entry.timestamp > DataBindingEngine.CACHE_EXPIRY) {
      this.cache.delete(path);
      // DevTools: 만료된 캐시는 미스로 기록
      if (devTools?.isEnabled()) {
        devTools.recordCacheMiss();
      }
      return undefined;
    }

    // DevTools: 캐시 히트 기록
    if (devTools?.isEnabled()) {
      devTools.recordCacheHit();
    }

    return entry.value;
  }

  /**
   * 캐시에 값 저장
   *
   * @param path 바인딩 경로
   * @param value 저장할 값
   */
  private saveToCache(path: string, value: ResolvedValue): void {
    this.cache.set(path, {
      value,
      timestamp: Date.now(),
    });
  }

  /**
   * 객체가 액션 정의(ActionDefinition)인지 판별합니다.
   *
   * 액션 정의는 렌더 시점에 resolveObject로 선평가하면 안 됩니다.
   * 내부 템플릿 문자열(예: "{{_local.checkout}}")이 실행 시점에
   * ActionDispatcher.resolveParams()에서 최신 상태로 해소되어야 합니다.
   *
   * 콜백 prop(onAddressSelect 등)에 포함된 액션 정의가 resolveObject를 거치면
   * {{}} 표현식이 렌더 시점 JS 객체로 고정되어 stale 값이 전송됩니다.
   * 컴포넌트 레벨 actions 배열은 bindComponentActions에서 별도 처리되므로 비해당.
   *
   * @param obj 검사할 객체
   * @returns 액션 정의이면 true
   */
  private isActionDefinition(obj: Record<string, any>): boolean {
    if (typeof obj.handler !== 'string') return false;
    return (
      obj.params !== undefined ||
      Array.isArray(obj.actions) ||
      typeof obj.target === 'string' ||
      obj.onSuccess !== undefined ||
      obj.onError !== undefined
    );
  }

  /**
   * 객체 내의 모든 바인딩 표현식을 데이터로 치환
   *
   * @param obj 바인딩 표현식이 포함된 객체
   * @param context 데이터 컨텍스트
   * @param options 바인딩 옵션
   * @returns 치환된 객체
   *
   * @example
   * const engine = new DataBindingEngine();
   * const result = engine.resolveObject(
   *   { style: { color: "{{theme.color}}" } },
   *   { theme: { color: "red" } }
   * );
   * // 결과: { style: { color: "red" } }
   */
  public resolveObject(
    obj: Record<string, any>,
    context: BindingContext,
    options?: BindingOptions & { skipBindingKeys?: string[] },
  ): Record<string, any> {
    // $switch 특수 객체 처리
    // { $switch: "{{key}}", $cases: { ... }, $default: value }
    if (this.isSwitchExpression(obj)) {
      const switchDef = obj as { $switch: string; $cases: Record<string, any>; $default?: any };
      return this.resolveSwitch(switchDef, context, options) as Record<string, any>;
    }

    // 액션 정의 객체 감지 → 선평가 건너뛰기
    // 콜백 prop(onAddressSelect 등)에 포함된 액션 정의는 렌더 시점에 해소하면
    // 내부 {{_local.xxx}} 표현식이 stale 객체로 고정됨.
    // ActionDispatcher.resolveParams()에서 실행 시점에 최신 상태로 해소해야 함.
    if (this.isActionDefinition(obj)) {
      return { ...obj };
    }

    // iteration이 있는 ComponentDefinition 감지 → 선평가 건너뛰기 (engine-v1.19.1)
    // footerCells/footerCardChildren 등 DataGrid prop 내부의 컴포넌트 정의에서
    // iteration이 있으면 source, text, children, props 모두 iteration 변수를 참조할 수 있음.
    // DynamicRenderer 시점에는 iteration 변수가 존재하지 않으므로 선평가하면 에러 발생.
    // renderItemChildren에서 적절한 iteration 컨텍스트와 함께 런타임에 해석되어야 함.
    if ('iteration' in obj) {
      return { ...obj };
    }

    const result: Record<string, any> = {};

    // 기본 skipBindingKeys (하위 호환성)
    // 컴포넌트별 설정은 DynamicRenderer에서 처리
    // resolveObject는 범용 메서드이므로 기본값만 유지
    const DEFAULT_SKIP_BINDING_KEYS = ['cellChildren', 'expandChildren', 'expandContext', 'render'];

    // 옵션으로 전달된 skipBindingKeys와 기본값 병합
    const skipBindingKeys = [
      ...DEFAULT_SKIP_BINDING_KEYS,
      ...(options?.skipBindingKeys || []),
    ];

    for (const [key, value] of Object.entries(obj)) {
      // cellChildren 등 특정 키는 원본 그대로 전달
      if (skipBindingKeys.includes(key)) {
        result[key] = value;
        continue;
      }

      if (typeof value === 'string') {
        // 전체가 {{variable}} 패턴인 경우 원본 값(배열/객체) 반환
        const singleBindingMatch = value.match(/^\{\{([^}]+)\}\}$/);
        if (singleBindingMatch) {
          const path = singleBindingMatch[1].trim();

          // raw: 접두사 감지 @since engine-v1.27.0
          let isRawBinding = false;
          let effectivePath = path;
          if (path.startsWith(RAW_PREFIX)) {
            isRawBinding = true;
            effectivePath = path.slice(RAW_PREFIX.length);
          }

          // 복잡 표현식(연산자 포함)은 evaluateExpression으로 라우팅
          // resolveBindings()와 동일한 isBaseExpression 판별 기준 사용
          const isBaseExpression = /[?:|&!+\-*/<>=()]/.test(effectivePath) || /\[['"]/.test(effectivePath);
          const resolved = isBaseExpression
            ? this.evaluateExpression(effectivePath, context, options)
            : this.resolve(effectivePath, context, options);
          result[key] = isRawBinding && resolved != null ? wrapRawDeep(resolved) : resolved;
        } else {
          // 문자열 보간
          result[key] = this.resolveBindings(value, context, options);
        }
      } else if (Array.isArray(value)) {
        result[key] = value.map((item) =>
          typeof item === 'string'
            ? this.resolveBindings(item, context, options)
            : typeof item === 'object' && item !== null
              ? this.resolveObject(item, context, options)
              : item,
        );
      } else if (value && typeof value === 'object') {
        result[key] = this.resolveObject(value, context, options);
      } else {
        result[key] = value;
      }
    }

    return result;
  }

  /**
   * 캐시 초기화
   *
   * 바인딩 값 캐시와 표현식 함수 캐시를 모두 초기화합니다.
   */
  public clearCache(): void {
    this.cache.clear();
    this.expressionFnCache.clear();
  }

  /**
   * 특정 키로 시작하는 캐시 항목 삭제
   *
   * 데이터가 업데이트될 때 해당 키와 관련된 캐시만 선택적으로 무효화합니다.
   * 예: invalidateCacheByKeys(['users']) 호출 시
   *     'users', 'users.data', 'users.data.data', 'users[0]' 등 삭제
   *
   * @param keys 삭제할 최상위 키 배열
   */
  public invalidateCacheByKeys(keys: string[]): void {
    for (const path of this.cache.keys()) {
      for (const key of keys) {
        if (path === key || path.startsWith(`${key}.`) || path.startsWith(`${key}[`)) {
          this.cache.delete(path);
          break;
        }
      }
    }
  }

  /**
   * 만료된 캐시 항목 제거
   */
  public pruneCache(): void {
    const now = Date.now();

    for (const [path, entry] of this.cache.entries()) {
      if (now - entry.timestamp > DataBindingEngine.CACHE_EXPIRY) {
        this.cache.delete(path);
      }
    }
  }

  /**
   * 캐시 통계 조회
   *
   * @returns 캐시 크기 및 만료된 항목 수
   */
  public getCacheStats(): { size: number; expired: number } {
    const now = Date.now();
    let expired = 0;

    for (const entry of this.cache.values()) {
      if (now - entry.timestamp > DataBindingEngine.CACHE_EXPIRY) {
        expired++;
      }
    }

    return {
      size: this.cache.size,
      expired,
    };
  }

  /**
   * JavaScript 표현식 평가 (공개 메서드)
   *
   * 삼항 연산자, 논리 연산자 등 복잡한 표현식을 평가합니다.
   *
   * @param expr 평가할 표현식 (예: "!_global.sidebarOpen", "_global.sidebarOpen ? 'a' : 'b'")
   * @param context 데이터 컨텍스트
   * @param trackingInfo DevTools 표현식 추적 정보 (선택적)
   * @returns 평가 결과
   * @throws {Error} 표현식 평가 실패 시
   */
  public evaluateExpression(
    expr: string,
    context: BindingContext,
    trackingInfo?: { componentId?: string; componentName?: string; propName?: string; skipCache?: boolean },
  ): any {
    // DevTools: 바인딩 평가 추적
    const devTools = getDevTools();
    const startTime = devTools?.isEnabled() ? performance.now() : 0;

    if (devTools?.isEnabled()) {
      devTools.trackBindingEval();
    }

    try {
      // 1. 표현식 전체에서 변수 접근을 optional chaining으로 변환
      // 예: perm.identifier -> perm?.identifier
      // 이를 통해 iteration 변수가 undefined일 때 에러 방지
      let processedExpr = this.preprocessOptionalChaining(expr);

      // 2. $t: 패턴을 문자열로 자동 변환 (예: $t:common.error -> "$t:common.error")
      // 이를 통해 사용자가 따옴표 없이 $t: 문법을 사용할 수 있음
      processedExpr = this.preprocessTranslationTokens(processedExpr);

      // 표현식에서 사용되는 최상위 변수 추출
      const usedVariables = this.extractVariablesFromExpression(processedExpr);

      // 컨텍스트에 없는 변수를 undefined로 추가
      const extendedContext = { ...context };
      for (const varName of usedVariables) {
        if (!(varName in extendedContext)) {
          extendedContext[varName] = undefined;
        }
      }

      // $computed alias 추가
      // layout-json-features.md 문서에 따라 $computed.xxx 형식으로 computed 값에 접근
      // 내부적으로 _computed에 저장되므로 $computed를 _computed의 alias로 설정
      if (context._computed && !extendedContext.$computed) {
        extendedContext.$computed = context._computed;
      }

      // $localized 헬퍼 함수 추가
      // 다국어 객체에서 현재 로케일에 해당하는 값을 반환
      // 사용법: $localized(value) - 문자열이면 그대로 반환, 객체면 로케일 우선순위에 따라 반환
      const locale = context.$locale || 'ko';
      extendedContext.$localized = (value: any): string => {
        if (value == null) {
          return '';
        }
        if (typeof value === 'string') {
          return value;
        }
        if (typeof value === 'object') {
          // 로케일 우선순위: 현재 로케일 > ko > en > 첫 번째 값
          return value[locale] || value.ko || value.en || Object.values(value)[0] || '';
        }
        return String(value);
      };

      // $uuid 헬퍼 함수 추가
      // UUID v4 형식의 고유 식별자를 생성하여 반환
      // 사용법: $uuid() - "550e8400-e29b-41d4-a716-446655440000" 형식의 문자열 반환
      const G7Core = typeof window !== 'undefined' ? (window as any).G7Core : undefined;
      extendedContext.$uuid = (): string => {
        if (G7Core?.uuid) {
          return G7Core.uuid();
        }
        if (typeof crypto !== 'undefined' && crypto.randomUUID) {
          return crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
          const r = (Math.random() * 16) | 0;
          const v = c === 'x' ? r : (r & 0x3) | 0x8;
          return v.toString(16);
        });
      };

      // $t 헬퍼 함수 추가
      // 번역 키를 사용하여 다국어 텍스트를 반환
      // 사용법: $t('admin.settings.info.write_only') - 번역된 텍스트 반환
      // 컨텍스트에서 번역 관련 정보 추출
      //
      // @since engine-v1.38.2 ActionDispatcher 경로 fallback — bindActionsToProps
      //   이후 createHandler 에서 빌드된 action data context 는 `$templateId`/`$locale`
      //   을 명시적으로 포함하지 않을 수 있다. 이 경우 `window.__templateApp.getConfig()`
      //   로부터 회수하여 `{{$event.target.checked ? '$t:A' : '$t:B'}}` 같은 조건부
      //   $t: 평가가 raw key 를 반환하던 버그를 해결한다.
      let templateId = context.$templateId;
      let resolvedLocale = locale;
      if (!templateId && typeof window !== 'undefined') {
        const templateApp = (window as any).__templateApp;
        const appConfig = templateApp?.getConfig?.();
        if (appConfig?.templateId) {
          templateId = appConfig.templateId;
          if (!context.$locale && appConfig.locale) {
            resolvedLocale = appConfig.locale;
          }
        }
      }
      const translationContext = {
        templateId: templateId || '',
        locale: resolvedLocale,
      };
      extendedContext.$t = (key: string): string => {
        if (!key || typeof key !== 'string') {
          return '';
        }
        try {
          const translationEngine = TranslationEngine.getInstance();
          return translationEngine.translate(key, translationContext);
        } catch (error) {
          logger.warn('$t() translation failed for key:', key, error);
          return key; // 번역 실패 시 키 자체 반환
        }
      };

      // $get 헬퍼 함수 추가
      // 깊은 객체 경로 접근과 폴백 값 지원
      // 사용법: $get(product.prices, [currency, 'formatted'], defaultPrice)
      extendedContext.$get = (obj: any, path: string | string[], fallback: any = undefined): any => {
        // null/undefined 객체는 즉시 폴백 반환
        if (obj === null || obj === undefined) {
          return fallback;
        }

        // 경로를 배열로 정규화
        const keys = Array.isArray(path) ? path : [path];

        // 빈 경로는 객체 자체 반환
        if (keys.length === 0) {
          return obj;
        }

        let current = obj;

        for (const key of keys) {
          // 현재 위치가 null/undefined이면 폴백 반환
          if (current === null || current === undefined) {
            return fallback;
          }

          // 키가 null/undefined이면 폴백 반환
          if (key === null || key === undefined) {
            return fallback;
          }

          // 다음 레벨로 이동
          current = current[key];
        }

        // 최종 값이 null/undefined이면 폴백 반환
        return current ?? fallback;
      };

      // 확장된 컨텍스트의 모든 키를 변수로 사용할 수 있도록 준비
      const contextKeys = Object.keys(extendedContext);
      const contextValues = Object.values(extendedContext);

      // 캐시 키 생성: 전처리된 표현식 + 컨텍스트 키 조합
      // 같은 표현식이라도 컨텍스트 키가 다르면 다른 함수가 필요
      const cacheKey = `${processedExpr}|${contextKeys.join(',')}`;

      // 캐시된 함수 조회 또는 새로 생성
      let evaluator = this.expressionFnCache.get(cacheKey);
      const fromCache = !!evaluator;
      if (!evaluator) {
        // Function 생성자를 사용하여 표현식 평가
        // 예: expr = "!_global.sidebarOpen"
        //     contextKeys = ["_global", "user", ...]
        //     contextValues = [{sidebarOpen: false}, {...}, ...]
        // eslint-disable-next-line no-new-func
        evaluator = new Function(...contextKeys, `return (${processedExpr});`);
        this.expressionFnCache.set(cacheKey, evaluator);
      }

      const result = evaluator(...contextValues);

      // DevTools: 표현식 평가 추적
      if (devTools?.isEnabled()) {
        const duration = performance.now() - startTime;
        devTools.trackExpressionEval({
          expression: expr,
          result: this.sanitizeResultForTracking(result),
          resultType: this.getResultType(result),
          componentId: trackingInfo?.componentId,
          componentName: trackingInfo?.componentName,
          propName: trackingInfo?.propName,
          fromCache,
          duration,
          method: 'evaluateExpression',
          skipCache: trackingInfo?.skipCache,
        });
      }

      return result;
    } catch (error) {
      throw new Error(
        `Failed to evaluate expression "${expr}": ${error instanceof Error ? error.message : String(error)}`,
      );
    }
  }

  /**
   * 결과 값의 타입을 문자열로 반환
   */
  private getResultType(value: any): string {
    if (value === null) return 'null';
    if (value === undefined) return 'undefined';
    if (Array.isArray(value)) return 'array';
    return typeof value;
  }

  /**
   * 추적을 위해 결과 값을 안전하게 정리
   * (순환 참조 제거, 크기 제한 등)
   */
  private sanitizeResultForTracking(value: any): any {
    if (value === null || value === undefined) return value;
    if (typeof value !== 'object') return value;

    try {
      // 객체/배열의 경우 간략화된 정보만 저장
      if (Array.isArray(value)) {
        if (value.length > 10) {
          return `[Array(${value.length})]`;
        }
        return value.slice(0, 10);
      }

      // 객체의 경우 키 개수 제한
      const keys = Object.keys(value);
      if (keys.length > 20) {
        return `{Object(${keys.length} keys)}`;
      }

      // 순환 참조 방지를 위해 JSON 변환 시도
      JSON.stringify(value);
      return value;
    } catch {
      return '[Complex Object]';
    }
  }

  /**
   * 표현식 내 $t: 토큰을 처리
   *
   * 1. 따옴표 안의 $t:key → $t('key') 함수 호출로 변환 (computed expressions용)
   * 2. 따옴표 없는 $t:key → "$t:key" 문자열로 변환 (static JSON용)
   *
   * @param expr 원본 표현식
   * @returns 변환된 표현식
   *
   * @example
   * preprocessTranslationTokens("'$t:common.all'")
   * // 결과: "$t('common.all')"
   *
   * @example
   * preprocessTranslationTokens("_global.msg || $t:common.error")
   * // 결과: '_global.msg || "$t:common.error"'
   */
  private preprocessTranslationTokens(expr: string): string {
    // 1. 먼저 따옴표 안의 $t: 패턴을 $t() 함수 호출로 변환
    // '$t:common.all' → $t('common.all')
    // "$t:common.all" → $t('common.all')
    // '$t:sirsoft-page.admin.key' → $t('sirsoft-page.admin.key') (하이픈 포함 모듈 키 지원)
    // @since engine-v1.28.1 하이픈(-) 지원 추가
    expr = expr.replace(/(['"])(\$t:([a-zA-Z_][a-zA-Z0-9_.\-]*))\1/g, (_match, _quote, _fullKey, keyOnly) => {
      return `$t('${keyOnly}')`;
    });

    // 2. 기존 로직: 따옴표 없는 $t: 패턴을 문자열로 변환
    // $t:key.path → "$t:key.path"
    // @since engine-v1.28.1 하이픈(-) 지원 추가
    return expr.replace(/(?<!['"]\s*)(\$t:[a-zA-Z_][a-zA-Z0-9_.\-]*)(?!\s*['"])/g, '"$1"');
  }

  /**
   * 표현식 전체에서 변수 접근을 optional chaining으로 변환
   *
   * iteration 변수 등이 undefined일 때 에러를 방지합니다.
   * 문자열 리터럴 내부는 변환하지 않습니다.
   *
   * @param expr 원본 표현식
   * @returns 변환된 표현식
   *
   * @example
   * preprocessOptionalChaining("perm.identifier")
   * // 결과: "perm?.identifier"
   *
   * preprocessOptionalChaining("$localized(perm.name) + ' (' + perm.identifier + ')'")
   * // 결과: "$localized(perm?.name) + ' (' + perm?.identifier + ')'"
   *
   * preprocessOptionalChaining("_global.user.name")
   * // 결과: "_global?.user?.name"
   */
  private preprocessOptionalChaining(expr: string): string {
    // 문자열 리터럴을 임시 토큰으로 대체
    const stringLiterals: string[] = [];
    let tokenizedExpr = expr.replace(/(['"])(?:(?!\1|\\).|\\.)*\1/g, (match) => {
      stringLiterals.push(match);
      return `__STRING_LITERAL_${stringLiterals.length - 1}__`;
    });

    // $t: 패턴을 임시 토큰으로 대체 (번역 키 경로는 변환하지 않음)
    // @since engine-v1.28.1 하이픈(-) 지원 추가
    const translationTokens: string[] = [];
    tokenizedExpr = tokenizedExpr.replace(/\$t:[a-zA-Z_][a-zA-Z0-9_.\-]*(?:\|[^'"\s,)]+)?/g, (match) => {
      translationTokens.push(match);
      return `__TRANSLATION_TOKEN_${translationTokens.length - 1}__`;
    });

    // 변수.속성 패턴을 변수?.속성으로 변환 (이미 ?. 인 경우 제외)
    // 단, 숫자 뒤의 .은 변환하지 않음 (예: 1.5)
    tokenizedExpr = tokenizedExpr.replace(/([a-zA-Z_$][a-zA-Z0-9_$]*)\.(?!\?)/g, '$1?.');

    // $t: 패턴 복원
    tokenizedExpr = tokenizedExpr.replace(/__TRANSLATION_TOKEN_(\d+)__/g, (_match, index) => {
      return translationTokens[parseInt(index, 10)];
    });

    // 문자열 리터럴 복원
    tokenizedExpr = tokenizedExpr.replace(/__STRING_LITERAL_(\d+)__/g, (_match, index) => {
      return stringLiterals[parseInt(index, 10)];
    });

    return tokenizedExpr;
  }

  /**
   * 표현식에서 사용되는 최상위 변수명 추출
   *
   * @param expr 표현식
   * @returns 변수명 배열
   */
  private extractVariablesFromExpression(expr: string): string[] {
    // JavaScript 키워드 및 예약어
    const reserved = new Set([
      'true', 'false', 'null', 'undefined', 'NaN', 'Infinity',
      'if', 'else', 'for', 'while', 'do', 'switch', 'case', 'break', 'continue', 'return',
      'function', 'class', 'const', 'let', 'var', 'new', 'delete', 'typeof', 'instanceof',
      'this', 'super', 'import', 'export', 'default', 'try', 'catch', 'finally', 'throw',
      // 내장 객체
      'Math', 'Date', 'JSON', 'Array', 'Object', 'String', 'Number', 'Boolean', 'RegExp',
      // 전역 함수
      'parseInt', 'parseFloat', 'isNaN', 'isFinite', 'encodeURI', 'decodeURI',
      'encodeURIComponent', 'decodeURIComponent',
    ]);

    // 식별자 패턴 (변수명 시작 위치)
    // 식별자는 문자, $, _로 시작하고 문자, 숫자, $, _로 계속됨
    const identifierPattern = /(?<![.\w$])([a-zA-Z_$][a-zA-Z0-9_$]*)/g;

    const variables = new Set<string>();
    let match;

    while ((match = identifierPattern.exec(expr)) !== null) {
      const varName = match[1];
      // 예약어가 아니고, 이미 추출되지 않았다면 추가
      if (!reserved.has(varName)) {
        variables.add(varName);
      }
    }

    return Array.from(variables);
  }

  /**
   * $switch 표현식인지 확인
   *
   * 객체가 $switch 키를 가지고 있으면 $switch 표현식으로 간주합니다.
   *
   * @param obj 확인할 객체
   * @returns $switch 표현식 여부
   *
   * @example
   * isSwitchExpression({ $switch: "{{tab}}", $cases: { ... } }) // true
   * isSwitchExpression({ type: "basic", name: "Div" }) // false
   */
  public isSwitchExpression(obj: any): boolean {
    return (
      obj !== null &&
      typeof obj === 'object' &&
      !Array.isArray(obj) &&
      '$switch' in obj &&
      '$cases' in obj
    );
  }

  /**
   * $switch 표현식 해석
   *
   * 동적 값에 따라 다른 값을 반환합니다.
   * 중첩 삼항 연산자를 대체하는 선언적 분기 표현식입니다.
   *
   * 트러블슈팅 주의사항:
   * - Stale Closure 방지: $switch 키 평가 시 항상 skipCache: true 적용
   * - 상태 변경 후 즉시 반영을 위해 최신 컨텍스트에서 평가
   *
   * @param switchDef $switch 정의 객체
   * @param context 데이터 컨텍스트
   * @param options 바인딩 옵션
   * @returns 매칭되는 case의 값 또는 default 값
   *
   * @example
   * ```typescript
   * const switchDef = {
   *   $switch: "{{tab}}",
   *   $cases: {
   *     products: "shopping-bag",
   *     contents: "book-open",
   *     policies: "file-check"
   *   },
   *   $default: "file-text"
   * };
   *
   * resolveSwitch(switchDef, { tab: "products" });
   * // 결과: "shopping-bag"
   * ```
   */
  public resolveSwitch(
    switchDef: { $switch: string; $cases: Record<string, any>; $default?: any },
    context: BindingContext,
    options?: BindingOptions,
  ): any {
    // 항상 skipCache: true로 평가 (Stale Closure 방지)
    const evalOptions = { ...options, skipCache: true };

    // 1. $switch 키 표현식 평가
    let keyValue: string;
    try {
      const resolved = this.resolveBindings(switchDef.$switch, context, evalOptions);
      // 빈 문자열이거나 undefined/null인 경우 처리
      keyValue = resolved?.toString()?.trim() ?? '';
    } catch (error) {
      logger.warn('resolveSwitch: $switch 키 평가 실패:', switchDef.$switch, error);
      keyValue = '';
    }

    // 2. $cases에서 매칭되는 값 찾기
    let result: any;
    if (keyValue && switchDef.$cases && keyValue in switchDef.$cases) {
      result = switchDef.$cases[keyValue];
    } else if ('$default' in switchDef) {
      // 매칭되는 case가 없으면 $default 사용
      result = switchDef.$default;
    } else {
      // $default도 없으면 undefined 반환
      return undefined;
    }

    // 3. 결과 값도 바인딩 해석 (중첩 표현식 지원)
    if (typeof result === 'string') {
      // 바인딩 표현식이 있으면 해석
      if (result.includes('{{')) {
        return this.resolveBindings(result, context, evalOptions);
      }
      return result;
    } else if (result !== null && typeof result === 'object') {
      // 중첩 객체인 경우 재귀적으로 해석
      return this.resolveObject(result, context, evalOptions);
    }

    return result;
  }
}

/**
 * 전역 데이터 바인딩 엔진 인스턴스
 *
 * @example
 * import { dataBindingEngine } from './DataBindingEngine';
 * const result = dataBindingEngine.resolve("user.name", context);
 */
export const dataBindingEngine = new DataBindingEngine();
