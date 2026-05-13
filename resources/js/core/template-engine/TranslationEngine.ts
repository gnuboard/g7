/**
 * TranslationEngine.ts
 *
 * G7 템플릿 엔진의 다국어 번역 처리 엔진
 *
 * 주요 기능:
 * - $t:key 문법 파싱 및 번역 텍스트 치환
 * - 파라미터 치환 (파이프 방식, 객체 방식)
 * - 다국어 파일 로드 및 캐싱
 * - 폴백 규칙 (ko → en → 키 자체)
 *
 * @module TranslationEngine
 */

import { createLogger } from '../utils/Logger';

const logger = createLogger('TranslationEngine');

// ============================================================================
// 타입 정의
// ============================================================================

/**
 * 다국어 번역 딕셔너리
 */
export interface TranslationDictionary {
  [key: string]: string | TranslationDictionary;
}

/**
 * 번역 파라미터
 */
export interface TranslationParams {
  [key: string]: string | number;
}

/**
 * 번역 옵션
 */
export interface TranslationOptions {
  /** 기본 로케일 (기본값: 'ko') */
  defaultLocale?: string;
  /** 폴백 로케일 (기본값: 'en') */
  fallbackLocale?: string;
  /** 캐시 만료 시간 (밀리초, 기본값: 300000 = 5분) */
  cacheTTL?: number;
}

/**
 * 캐시 엔트리
 */
interface CacheEntry {
  data: TranslationDictionary;
  timestamp: number;
}

/**
 * 번역 컨텍스트
 */
export interface TranslationContext {
  /** 템플릿 ID */
  templateId: string;
  /** 현재 로케일 */
  locale: string;
  /** API 베이스 URL */
  apiBaseUrl?: string;
}

/**
 * 번역 에러 클래스
 */
export class TranslationError extends Error {
  constructor(
    message: string,
    public key?: string,
    public locale?: string
  ) {
    super(message);
    this.name = 'TranslationError';
  }
}

// ============================================================================
// TranslationEngine 클래스
// ============================================================================

/**
 * 다국어 번역 처리 엔진 (싱글톤)
 *
 * 로케일 변경 시에도 이미 로드된 번역을 재사용합니다.
 *
 * @example
 * const engine = TranslationEngine.getInstance();
 * await engine.loadTranslations('template-1', 'ko');
 * const text = engine.translate('$t:dashboard.title'); // "대시보드"
 */
export class TranslationEngine {
  /** 싱글톤 인스턴스 */
  private static instance: TranslationEngine | null = null;

  /**
   * 번역 문법 패턴: $t:key, $t:defer:key, 또는 $t:key|param=value (공백 포함 값 지원).
   *
   * `defer:` prefix 는 인라인 형태(`{{id}} — $t:defer:key|...`) 에서도 흡수되어야 하므로
   * 키 그룹 앞에 옵셔널 매칭을 둔다 — 키 문자 클래스 [a-zA-Z0-9._-] 가 콜론을 미포함하여
   * 과거 `$t:defer` 만 매칭되고 나머지가 raw 로 남던 회귀 차단 (이슈 #302).
   */
  private static readonly TRANSLATION_PATTERN = /\$t:(?:defer:)?([a-zA-Z0-9._-]+)(\|(?:(?!\$t:).)+)?/g;

  /**
   * 파라미터 값 내 중첩 $t: 토큰 패턴
   *
   * `|status=$t:enums.issuing` 형태에서 `=$t:key` 부분을 매칭합니다.
   * TRANSLATION_PATTERN이 `$t:` 앞에서 매칭을 중단하므로,
   * 메인 해석 전에 파라미터 값 위치의 $t: 토큰을 먼저 번역합니다.
   *
   * @since engine-v1.25.0
   */
  private static readonly NESTED_TRANSLATION_PARAM_PATTERN = /=(\$t:([a-zA-Z0-9._-]+))(?=[|&\s]|$)/g;

  /** 파라미터 파싱 패턴 (|나 &로 구분된 key=value 형식) */
  private static readonly PARAM_PATTERN = /([^=|&]+)=([^|&]+)/g;

  /** 다국어 번역 캐시 */
  private cache: Map<string, CacheEntry> = new Map();

  /**
   * 싱글톤 인스턴스를 반환합니다.
   *
   * @param options 번역 옵션 (최초 생성 시에만 적용)
   */
  static getInstance(options: TranslationOptions = {}): TranslationEngine {
    if (!TranslationEngine.instance) {
      TranslationEngine.instance = new TranslationEngine(options);
    }
    return TranslationEngine.instance;
  }

  /**
   * 싱글톤 인스턴스를 리셋합니다. (테스트용)
   */
  static resetInstance(): void {
    TranslationEngine.instance = null;
  }

  /** 현재 로드된 번역 딕셔너리 */
  private translations: Map<string, TranslationDictionary> = new Map();

  /** 기본 옵션 */
  private options: Required<TranslationOptions>;

  /** 확장 기능 캐시 버전 (모듈/플러그인 활성화 시 갱신됨) */
  private cacheVersion: number = 0;

  /**
   * TranslationEngine 생성자
   */
  constructor(options: TranslationOptions = {}) {
    this.options = {
      defaultLocale: options.defaultLocale || 'ko',
      fallbackLocale: options.fallbackLocale || 'en',
      cacheTTL: options.cacheTTL || 300000, // 5분
    };
  }

  /**
   * 캐시 버전 설정
   *
   * 모듈/플러그인 활성화 시 캐시 버전이 변경되어
   * 새로운 다국어 데이터를 서버에서 가져오도록 합니다.
   *
   * @param version 캐시 버전 (타임스탬프)
   *
   * @since engine-v1.38.1 활성 `translations` 맵은 비우지 않음 — 이전에는
   *   `clearCache()` 로 `translations` 까지 비웠기 때문에, `reloadExtensions`
   *   와 같은 병렬 재동기화 중 새 `loadTranslations()` 가 끝나기 전에 실행되는
   *   `translate()` 호출이 빈 사전을 만나 raw $t:key 를 반환하는 경합이 있었음.
   *   이제 TTL 캐시(`this.cache`)만 비우고, 활성 사전(`this.translations`)은
   *   `loadTranslations` 가 새 데이터를 `set()` 으로 원자 교체할 때까지 유지된다.
   */
  setCacheVersion(version: number): void {
    if (this.cacheVersion !== version) {
      logger.log('Cache version updated:', this.cacheVersion, '->', version);
      this.cacheVersion = version;
      // TTL 캐시만 클리어 → getFromCache 미스 → 다음 loadTranslations 가 새 버전 URL 로 fetch.
      // 활성 translations 맵은 fetch 완료 시 원자적으로 교체되므로 건드리지 않음.
      this.cache.clear();
    }
  }

  /**
   * 현재 캐시 버전 반환
   */
  getCacheVersion(): number {
    return this.cacheVersion;
  }

  /**
   * 템플릿의 다국어 파일을 로드합니다.
   *
   * @param templateId 템플릿 ID
   * @param locale 로케일 (예: 'ko', 'en')
   * @param apiBaseUrl API 베이스 URL (기본값: '/api')
   */
  async loadTranslations(
    templateId: string,
    locale: string,
    apiBaseUrl: string = '/api',
    bustCache: boolean = false
  ): Promise<TranslationDictionary> {
    const cacheKey = `${templateId}:${locale}`;

    // 캐시 확인 (bustCache가 true면 캐시 무시)
    if (!bustCache) {
      const cached = this.getFromCache(cacheKey);
      if (cached) {
        this.translations.set(cacheKey, cached);
        return cached;
      }
    }

    try {
      // API 호출 (캐시 버전 쿼리 파라미터 추가, bustCache가 true면 타임스탬프도 추가)
      let url = `${apiBaseUrl}/templates/${templateId}/lang/${locale}.json`;

      // 쿼리 파라미터 구성
      const queryParams: string[] = [];
      if (this.cacheVersion > 0) {
        queryParams.push(`v=${this.cacheVersion}`);
      }
      if (bustCache) {
        queryParams.push(`_=${Date.now()}`);
      }
      if (queryParams.length > 0) {
        url += `?${queryParams.join('&')}`;
      }

      const response = await fetch(url);

      if (!response.ok) {
        throw new TranslationError(
          `Failed to load translations: ${response.statusText}`,
          undefined,
          locale
        );
      }

      const result = await response.json();

      // API가 번역 데이터를 직접 반환 (result.data가 아님)
      const dictionary: TranslationDictionary = result;

      // 캐시 저장
      this.saveToCache(cacheKey, dictionary);
      this.translations.set(cacheKey, dictionary);

      return dictionary;
    } catch (error) {
      throw new TranslationError(
        `Failed to fetch translations for ${locale}: ${
          error instanceof Error ? error.message : String(error)
        }`,
        undefined,
        locale
      );
    }
  }

  /**
   * 문자열 내 모든 번역 표현식을 치환합니다.
   *
   * @param text 번역할 텍스트
   * @param context 번역 컨텍스트
   * @param dataContext 데이터 컨텍스트 (파라미터 바인딩용)
   */
  resolveTranslations(
    text: string,
    context: TranslationContext,
    dataContext?: any
  ): string {
    // 1단계: 파라미터 값 내 중첩 $t: 토큰을 먼저 해석 (engine-v1.25.0)
    // 예: |status=$t:enums.coupon_issue_status.issuing → |status=발급중
    // TRANSLATION_PATTERN이 $t: 앞에서 매칭을 중단하므로, 사전 해석 필요
    // 루프: 해석 결과에 다시 =$t:가 포함될 수 있으므로 변화 없을 때까지 반복 (깊이 제한)
    let processed = text;
    const MAX_NESTED_DEPTH = 5;
    let depth = 0;
    while (depth < MAX_NESTED_DEPTH && processed.includes('=$t:')) {
      const prev = processed;
      processed = processed.replace(
        TranslationEngine.NESTED_TRANSLATION_PARAM_PATTERN,
        (_match, _fullToken, key) => {
          return '=' + this.translate(key, context, undefined, dataContext);
        }
      );
      if (processed === prev) break; // 변화 없으면 종료 (무한 루프 방지)
      depth++;
    }

    // 2단계: 메인 $t: 해석
    return processed.replace(
      TranslationEngine.TRANSLATION_PATTERN,
      (_match, key, paramsStr) => {
        // 파라미터에서 trailing 비파라미터 텍스트 분리
        const { cleanedParams, trailing } = this.separateTrailingText(paramsStr);

        // 번역 수행 후 trailing 텍스트 붙이기
        return this.translate(key, context, cleanedParams, dataContext) + trailing;
      }
    );
  }

  /**
   * 번역 키를 실제 번역 텍스트로 변환합니다.
   *
   * @param key 번역 키 (예: 'dashboard.title')
   * @param context 번역 컨텍스트
   * @param paramsStr 파라미터 문자열 (예: '|count=5')
   * @param dataContext 데이터 컨텍스트
   */
  translate(
    key: string,
    context: TranslationContext,
    paramsStr?: string,
    dataContext?: any
  ): string {
    // 번역 텍스트 조회 (폴백 포함)
    const text = this.getTranslation(key, context);

    // 파라미터 문자열의 trailing 비파라미터 텍스트 정리
    const cleanedParamsStr = paramsStr ? this.cleanParamsStr(paramsStr) : paramsStr;

    // 파라미터 치환
    if (cleanedParamsStr) {
      const params = this.parseParams(cleanedParamsStr, dataContext);
      return this.replaceParams(text, params);
    }

    return text;
  }

  /**
   * 번역 텍스트 조회 (폴백 규칙 적용)
   *
   * @param key 번역 키
   * @param context 번역 컨텍스트
   */
  private getTranslation(
    key: string,
    context: TranslationContext
  ): string {
    const { templateId, locale } = context;

    // 1. 현재 로케일에서 조회
    const currentKey = `${templateId}:${locale}`;
    const current = this.translations.get(currentKey);
    if (current) {
      const value = this.getNestedValue(current, key);
      // 빈 문자열도 유효한 번역 값이므로 !== null 체크
      if (value !== null) return value;
    }

    // 2. 폴백 로케일에서 조회
    if (locale !== this.options.fallbackLocale) {
      const fallbackKey = `${templateId}:${this.options.fallbackLocale}`;
      const fallback = this.translations.get(fallbackKey);
      if (fallback) {
        const value = this.getNestedValue(fallback, key);
        if (value !== null) return value;
      }
    }

    // 3. 키 자체 반환 (번역 없음)
    return key;
  }

  /**
   * 중첩된 객체에서 값을 조회합니다.
   *
   * @param obj 번역 딕셔너리
   * @param path 경로 (예: 'dashboard.title')
   */
  private getNestedValue(
    obj: TranslationDictionary,
    path: string
  ): string | null {
    const keys = path.split('.');
    let current: any = obj;

    for (const key of keys) {
      if (current == null || typeof current !== 'object') {
        return null;
      }
      current = current[key];
    }

    // 빈 문자열('')도 유효한 번역 값으로 처리
    return typeof current === 'string' ? current : null;
  }

  /**
   * 파라미터 문자열을 파싱합니다.
   *
   * `{{...}}` 내부의 `|`와 `&`는 구분자로 취급하지 않습니다.
   * 이를 통해 `||`, `&&` 등의 JavaScript 연산자를 표현식 내에서 사용할 수 있습니다.
   *
   * @param paramsStr 파라미터 문자열 (예: '|count=5&name=홍길동')
   * @param dataContext 데이터 컨텍스트
   */
  private parseParams(
    paramsStr: string,
    dataContext?: any
  ): TranslationParams {
    const params: TranslationParams = {};

    // 파이프 제거
    const cleanStr = paramsStr.startsWith('|')
      ? paramsStr.slice(1)
      : paramsStr;

    // {{...}} 표현식을 임시로 치환하여 내부의 |와 &를 보호
    // 패턴: {{ 로 시작하고 }} 로 끝나며, 내부에 단독 }는 허용
    const placeholders: string[] = [];
    const protectedStr = cleanStr.replace(/\{\{(?:[^}]|\}(?!\}))*\}\}/g, (match) => {
      placeholders.push(match);
      return `__PLACEHOLDER_${placeholders.length - 1}__`;
    });

    // 파라미터 추출
    const matches = protectedStr.matchAll(TranslationEngine.PARAM_PATTERN);
    for (const match of matches) {
      const [, key, value] = match;
      // placeholder를 원래 표현식으로 복원
      const restoredValue = value.replace(/__PLACEHOLDER_(\d+)__/g, (_, idx) => {
        return placeholders[parseInt(idx, 10)];
      });
      params[key.trim()] = this.resolveParamValue(restoredValue.trim(), dataContext);
    }

    return params;
  }

  /**
   * 파라미터 값을 해석합니다.
   *
   * @param value 파라미터 값 (예: '{{user.name}}' 또는 '홍길동')
   * @param dataContext 데이터 컨텍스트
   */
  private resolveParamValue(value: string, dataContext?: any): string {
    // {{variable}} 패턴 처리
    if (value.startsWith('{{') && value.endsWith('}}')) {
      const expression = value.slice(2, -2).trim();

      // 복잡한 표현식인지 확인 (연산자, 괄호, 메서드 호출 등 포함)
      // 산술 연산자(+, -, *, /, %), 비교 연산자(<, >, =), 논리 연산자, 공백(피연산자 분리)도 포함
      const isComplexExpression = /[|&()[\]!?:+\-*/%<>=\s]/.test(expression);

      if (isComplexExpression && dataContext) {
        // JavaScript 표현식으로 평가
        try {
          const func = new Function(...Object.keys(dataContext), `return ${expression}`);
          const resolved = func(...Object.values(dataContext));
          return String(resolved ?? '');
        } catch (error) {
          logger.error('Expression evaluation failed:', expression, error);
          return '';
        }
      } else {
        // 단순 경로로 처리
        const resolved = this.getNestedDataValue(dataContext, expression);
        return String(resolved ?? '');
      }
    }

    return value;
  }

  /**
   * 파라미터 문자열에서 trailing 비파라미터 텍스트를 분리합니다.
   *
   * 파라미터 형식(key=value)이 아닌 마지막 부분을 trailing으로 분리합니다.
   * 단, 파라미터 값 내의 공백은 값의 일부로 취급합니다.
   *
   * 예: "|value1=A / " -> { cleanedParams: "|value1=A", trailing: " / " }
   * 예: "|name=Admin Basic" -> { cleanedParams: "|name=Admin Basic", trailing: "" }
   * 예: "|name=Admin Basic / " -> { cleanedParams: "|name=Admin Basic", trailing: " / " }
   *
   * `{{...}}` 내부의 텍스트는 trailing으로 처리하지 않습니다.
   *
   * @param paramsStr 파라미터 문자열
   * @returns 정리된 파라미터와 trailing 텍스트
   */
  private separateTrailingText(paramsStr?: string): {
    cleanedParams: string | undefined;
    trailing: string;
  } {
    if (!paramsStr) {
      return { cleanedParams: undefined, trailing: '' };
    }

    // {{...}}로 끝나는 경우 trailing 처리하지 않음
    if (paramsStr.trimEnd().endsWith('}}')) {
      return { cleanedParams: paramsStr, trailing: '' };
    }

    // 파이프 제거 후 분석
    const cleanStr = paramsStr.startsWith('|') ? paramsStr.slice(1) : paramsStr;

    // key=value 패턴이 없으면 전체가 trailing
    if (!cleanStr.includes('=')) {
      return { cleanedParams: undefined, trailing: paramsStr };
    }

    // 마지막 = 이후의 값에서 | 또는 & 뒤에 key= 형식이 아닌 텍스트가 있는지 확인
    // 예: "name=Admin Basic / " 에서 " / "를 분리
    // 단, "name=Admin Basic" 에서 " Basic"은 값의 일부이므로 분리하지 않음

    // trailing은 마지막 파라미터 값 뒤에 separator(|, &) 없이
    // 다른 key=가 오지 않는 텍스트만 해당
    // 예: "|a=1 / " -> trailing은 " / " (= 없고, | & 뒤가 아님)

    // 간단한 휴리스틱: 마지막에 공백 + 특수문자(/, \, 등)로 끝나면 trailing
    // \p{L}을 사용하여 유니코드 문자(한글 등)도 단어 문자로 인식
    const trailingMatch = paramsStr.match(/(\s+[^\p{L}\w=|&\s][^\p{L}\w=]*\s*)$/u);
    if (trailingMatch) {
      return {
        cleanedParams: paramsStr.slice(0, -trailingMatch[1].length),
        trailing: trailingMatch[1],
      };
    }

    return { cleanedParams: paramsStr, trailing: '' };
  }

  /**
   * 파라미터 문자열에서 trailing 비파라미터 텍스트를 제거합니다.
   *
   * @param paramsStr 파라미터 문자열
   * @returns 정리된 파라미터 문자열
   */
  private cleanParamsStr(paramsStr: string): string {
    return this.separateTrailingText(paramsStr).cleanedParams || '';
  }

  /**
   * 데이터 컨텍스트에서 중첩된 값을 조회합니다.
   *
   * @param context 데이터 컨텍스트
   * @param path 경로
   */
  private getNestedDataValue(context: any, path: string): any {
    if (!context) return undefined;

    const keys = path.split('.');
    let current = context;

    for (const key of keys) {
      if (current == null) return undefined;
      current = current[key];
    }

    return current;
  }

  /**
   * 번역 텍스트 내 파라미터를 치환합니다.
   *
   * @param text 번역 텍스트 (예: '총 {count}개의 상품' 또는 '총 {{count}}개의 상품')
   * @param params 파라미터 맵
   */
  private replaceParams(
    text: string,
    params: TranslationParams
  ): string {
    let result = text;

    for (const [key, value] of Object.entries(params)) {
      // {{key}} 형식 (이중 중괄호) 먼저 치환
      const doublePattern = new RegExp(`\\{\\{${key}\\}\\}`, 'g');
      result = result.replace(doublePattern, String(value));

      // {key} 형식 (단일 중괄호) 치환
      const singlePattern = new RegExp(`\\{${key}\\}`, 'g');
      result = result.replace(singlePattern, String(value));
    }

    return result;
  }

  /**
   * 캐시에서 번역 딕셔너리를 조회합니다.
   *
   * @param key 캐시 키
   */
  private getFromCache(key: string): TranslationDictionary | null {
    const entry = this.cache.get(key);
    if (!entry) return null;

    // 캐시 만료 확인
    const now = Date.now();
    if (now - entry.timestamp > this.options.cacheTTL) {
      this.cache.delete(key);
      return null;
    }

    return entry.data;
  }

  /**
   * 번역 딕셔너리를 캐시에 저장합니다.
   *
   * @param key 캐시 키
   * @param data 번역 딕셔너리
   */
  private saveToCache(key: string, data: TranslationDictionary): void {
    this.cache.set(key, {
      data,
      timestamp: Date.now(),
    });
  }

  /**
   * 캐시를 초기화합니다.
   */
  clearCache(): void {
    this.cache.clear();
    this.translations.clear();
  }

  /**
   * 만료된 캐시 엔트리를 제거합니다.
   */
  pruneCache(): void {
    const now = Date.now();
    const expiredKeys: string[] = [];

    for (const [key, entry] of this.cache.entries()) {
      if (now - entry.timestamp > this.options.cacheTTL) {
        expiredKeys.push(key);
      }
    }

    for (const key of expiredKeys) {
      this.cache.delete(key);
    }
  }

  /**
   * 캐시 통계를 조회합니다.
   */
  getCacheStats(): { size: number; expired: number } {
    const now = Date.now();
    let expired = 0;

    for (const entry of this.cache.values()) {
      if (now - entry.timestamp > this.options.cacheTTL) {
        expired++;
      }
    }

    return {
      size: this.cache.size,
      expired,
    };
  }
}

/**
 * 싱글톤 인스턴스 생성 헬퍼
 */
let instance: TranslationEngine | null = null;

export function getTranslationEngine(
  options?: TranslationOptions
): TranslationEngine {
  if (!instance) {
    instance = new TranslationEngine(options);
  }
  return instance;
}
