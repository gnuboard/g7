/**
 * 그누보드7 템플릿 엔진 - 파이프 함수 레지스트리
 *
 * Angular/Vue 스타일의 파이프 함수를 지원합니다.
 * `{{value | pipeName(arg1, arg2)}}` 형태로 값을 변환할 수 있습니다.
 *
 * @packageDocumentation
 *
 * @example
 * ```typescript
 * // 레이아웃 JSON에서 사용
 * "text": "{{post.created_at | date}}"
 * "text": "{{post.created_at | datetime}}"
 * "text": "{{post.created_at | relativeTime}}"
 * "text": "{{item.count | number}}"
 * "text": "{{description | truncate(100)}}"
 * ```
 */

import { createLogger } from '../utils/Logger';

const logger = createLogger('PipeRegistry');

/**
 * 파이프 함수 타입
 *
 * 첫 번째 인자는 변환할 값, 나머지는 파이프 인자
 */
export type PipeFunction = (value: any, ...args: any[]) => any;

/**
 * 파이프 정의 인터페이스
 */
interface PipeDefinition {
  fn: PipeFunction;
  description?: string;
}

/**
 * 날짜 포맷 함수 (간단한 구현)
 *
 * dayjs나 date-fns 없이 기본적인 날짜 포맷을 지원합니다.
 */
function formatDateSimple(date: Date, format: string): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  const hours = String(date.getHours()).padStart(2, '0');
  const minutes = String(date.getMinutes()).padStart(2, '0');
  const seconds = String(date.getSeconds()).padStart(2, '0');

  return format
    .replace('YYYY', String(year))
    .replace('YY', String(year).slice(-2))
    .replace('MM', month)
    .replace('M', String(date.getMonth() + 1))
    .replace('DD', day)
    .replace('D', String(date.getDate()))
    .replace('HH', hours)
    .replace('H', String(date.getHours()))
    .replace('mm', minutes)
    .replace('m', String(date.getMinutes()))
    .replace('ss', seconds)
    .replace('s', String(date.getSeconds()));
}

/**
 * 상대 시간 계산 함수
 *
 * @param date - 비교할 날짜
 * @param locale - 로케일 (기본값: 'ko')
 * @returns 상대 시간 문자열 (예: "3분 전", "2시간 전")
 */
function getRelativeTime(date: Date, locale: string = 'ko'): string {
  const now = new Date();
  const diffMs = now.getTime() - date.getTime();
  const diffSec = Math.floor(diffMs / 1000);
  const diffMin = Math.floor(diffSec / 60);
  const diffHour = Math.floor(diffMin / 60);
  const diffDay = Math.floor(diffHour / 24);
  const diffWeek = Math.floor(diffDay / 7);
  const diffMonth = Math.floor(diffDay / 30);
  const diffYear = Math.floor(diffDay / 365);

  // 한국어
  if (locale === 'ko') {
    if (diffSec < 60) return '방금 전';
    if (diffMin < 60) return `${diffMin}분 전`;
    if (diffHour < 24) return `${diffHour}시간 전`;
    if (diffDay < 7) return `${diffDay}일 전`;
    if (diffWeek < 4) return `${diffWeek}주 전`;
    if (diffMonth < 12) return `${diffMonth}개월 전`;
    return `${diffYear}년 전`;
  }

  // 영어 (기본)
  if (diffSec < 60) return 'just now';
  if (diffMin < 60) return `${diffMin} minute${diffMin === 1 ? '' : 's'} ago`;
  if (diffHour < 24) return `${diffHour} hour${diffHour === 1 ? '' : 's'} ago`;
  if (diffDay < 7) return `${diffDay} day${diffDay === 1 ? '' : 's'} ago`;
  if (diffWeek < 4) return `${diffWeek} week${diffWeek === 1 ? '' : 's'} ago`;
  if (diffMonth < 12) return `${diffMonth} month${diffMonth === 1 ? '' : 's'} ago`;
  return `${diffYear} year${diffYear === 1 ? '' : 's'} ago`;
}

/**
 * 값을 Date 객체로 변환
 *
 * @param value - 변환할 값 (Date, 문자열, 숫자)
 * @returns Date 객체 또는 null
 */
function toDate(value: any): Date | null {
  if (value == null) return null;
  if (value instanceof Date) return value;
  if (typeof value === 'number') return new Date(value);
  if (typeof value === 'string') {
    const parsed = new Date(value);
    return isNaN(parsed.getTime()) ? null : parsed;
  }
  return null;
}

/**
 * 내장 파이프 함수들
 *
 * 모든 파이프 함수는 순수 함수입니다.
 * - 입력값을 변경하지 않습니다.
 * - 전역 상태에 접근하지 않습니다.
 * - null/undefined 입력을 안전하게 처리합니다.
 */
const builtInPipes: Record<string, PipeDefinition> = {
  // ============================================
  // 날짜 파이프
  // ============================================

  /**
   * date - 날짜 포맷 (날짜만)
   *
   * @example
   * "{{post.created_at | date}}" → "2024-01-15"
   * "{{post.created_at | date('YYYY/MM/DD')}}" → "2024/01/15"
   */
  date: {
    fn: (value: any, format: string = 'YYYY-MM-DD'): string => {
      const date = toDate(value);
      if (!date) return '';
      return formatDateSimple(date, format);
    },
    description: '날짜 포맷 (기본: YYYY-MM-DD)',
  },

  /**
   * datetime - 날짜+시간 포맷
   *
   * @example
   * "{{post.created_at | datetime}}" → "2024-01-15 14:30"
   * "{{post.created_at | datetime('YYYY/MM/DD HH:mm:ss')}}" → "2024/01/15 14:30:45"
   */
  datetime: {
    fn: (value: any, format: string = 'YYYY-MM-DD HH:mm'): string => {
      const date = toDate(value);
      if (!date) return '';
      return formatDateSimple(date, format);
    },
    description: '날짜+시간 포맷 (기본: YYYY-MM-DD HH:mm)',
  },

  /**
   * relativeTime - 상대 시간 표시
   *
   * @example
   * "{{post.created_at | relativeTime}}" → "3분 전", "2시간 전", "1일 전"
   */
  relativeTime: {
    fn: (value: any, locale: string = 'ko'): string => {
      const date = toDate(value);
      if (!date) return '';
      return getRelativeTime(date, locale);
    },
    description: '상대 시간 표시 (예: "3분 전")',
  },

  // ============================================
  // 숫자 파이프
  // ============================================

  /**
   * number - 숫자 포맷 (천단위 구분, 소수점)
   *
   * @example
   * "{{item.count | number}}" → "1,234"
   * "{{item.price | number(2)}}" → "1,234.56"
   */
  number: {
    fn: (value: any, decimals?: number): string => {
      const num = Number(value);
      if (isNaN(num)) return String(value ?? '');

      const options: Intl.NumberFormatOptions = {};
      if (decimals !== undefined) {
        options.minimumFractionDigits = decimals;
        options.maximumFractionDigits = decimals;
      }

      return num.toLocaleString(undefined, options);
    },
    description: '숫자 포맷 (천단위 구분, 선택적 소수점)',
  },

  // ============================================
  // 문자열 파이프
  // ============================================

  /**
   * truncate - 문자열 자르기
   *
   * @example
   * "{{description | truncate(100)}}" → "Lorem ipsum dolor sit amet..."
   * "{{description | truncate(50, ' [더보기]')}}" → "Lorem ipsum... [더보기]"
   */
  truncate: {
    fn: (value: any, length: number = 100, suffix: string = '...'): string => {
      if (typeof value !== 'string') return String(value ?? '');
      if (value.length <= length) return value;
      return value.slice(0, length) + suffix;
    },
    description: '문자열 자르기 (기본: 100자, 접미사: "...")',
  },

  /**
   * uppercase - 대문자 변환
   *
   * @example
   * "{{name | uppercase}}" → "HELLO"
   */
  uppercase: {
    fn: (value: any): string => {
      if (typeof value !== 'string') return String(value ?? '');
      return value.toUpperCase();
    },
    description: '대문자 변환',
  },

  /**
   * lowercase - 소문자 변환
   *
   * @example
   * "{{name | lowercase}}" → "hello"
   */
  lowercase: {
    fn: (value: any): string => {
      if (typeof value !== 'string') return String(value ?? '');
      return value.toLowerCase();
    },
    description: '소문자 변환',
  },

  /**
   * stripHtml - HTML 태그 제거
   *
   * @example
   * "{{content | stripHtml}}" → "Hello World" (from "<p>Hello <b>World</b></p>")
   */
  stripHtml: {
    fn: (value: any): string => {
      if (typeof value !== 'string') return String(value ?? '');
      return value.replace(/<[^>]*>/g, '');
    },
    description: 'HTML 태그 제거',
  },

  // ============================================
  // 기본값 파이프
  // ============================================

  /**
   * default - 기본값 설정 (null, undefined, 빈문자열 시)
   *
   * @example
   * "{{name | default('Unknown')}}" → "Unknown" (name이 없을 때)
   */
  default: {
    fn: (value: any, defaultValue: any = ''): any => {
      if (value == null || value === '') return defaultValue;
      return value;
    },
    description: '기본값 설정 (null, undefined, 빈문자열 시)',
  },

  /**
   * fallback - 폴백 값 설정 (null, undefined 시만)
   *
   * @example
   * "{{data | fallback([])}}" → [] (data가 null/undefined일 때)
   */
  fallback: {
    fn: (value: any, fallbackValue: any): any => {
      return value ?? fallbackValue;
    },
    description: '폴백 값 설정 (null, undefined 시만)',
  },

  // ============================================
  // 배열 파이프
  // ============================================

  /**
   * first - 배열의 첫 번째 요소
   *
   * @example
   * "{{items | first}}" → 첫 번째 아이템
   */
  first: {
    fn: (value: any): any => {
      if (!Array.isArray(value)) return undefined;
      return value[0];
    },
    description: '배열의 첫 번째 요소',
  },

  /**
   * last - 배열의 마지막 요소
   *
   * @example
   * "{{items | last}}" → 마지막 아이템
   */
  last: {
    fn: (value: any): any => {
      if (!Array.isArray(value)) return undefined;
      return value[value.length - 1];
    },
    description: '배열의 마지막 요소',
  },

  /**
   * join - 배열을 문자열로 결합
   *
   * @example
   * "{{tags | join(', ')}}" → "태그1, 태그2, 태그3"
   */
  join: {
    fn: (value: any, separator: string = ', '): string => {
      if (!Array.isArray(value)) return '';
      return value.join(separator);
    },
    description: '배열을 문자열로 결합 (기본 구분자: ", ")',
  },

  /**
   * length - 배열/문자열 길이
   *
   * @example
   * "{{items | length}}" → 5
   * "{{text | length}}" → 10
   */
  length: {
    fn: (value: any): number => {
      if (Array.isArray(value) || typeof value === 'string') {
        return value.length;
      }
      return 0;
    },
    description: '배열/문자열 길이',
  },

  /**
   * filterBy - 배열 필터링 (포함 여부)
   *
   * 배열의 각 요소에서 지정된 필드 값이 allowList 배열에 포함되어 있는지 확인하여 필터링합니다.
   *
   * @example
   * "{{columns | filterBy(visibleColumns, 'field')}}"
   * // columns 배열에서 item.field 값이 visibleColumns 배열에 포함된 항목만 반환
   *
   * "{{items | filterBy(selectedIds, 'id')}}"
   * // items 배열에서 item.id 값이 selectedIds 배열에 포함된 항목만 반환
   */
  filterBy: {
    fn: (value: any, allowList: any[], field?: string): any[] => {
      if (!Array.isArray(value)) return [];
      if (!Array.isArray(allowList)) return value;

      return value.filter((item) => {
        const itemValue = field ? item?.[field] : item;
        return allowList.includes(itemValue);
      });
    },
    description: '배열 필터링 (allowList에 포함된 항목만 반환)',
  },

  // ============================================
  // 객체 파이프
  // ============================================

  /**
   * keys - 객체의 키 배열
   *
   * @example
   * "{{object | keys}}" → ["key1", "key2"]
   */
  keys: {
    fn: (value: any): string[] => {
      if (value == null || typeof value !== 'object') return [];
      return Object.keys(value);
    },
    description: '객체의 키 배열',
  },

  /**
   * values - 객체의 값 배열
   *
   * @example
   * "{{object | values}}" → [value1, value2]
   */
  values: {
    fn: (value: any): any[] => {
      if (value == null || typeof value !== 'object') return [];
      return Object.values(value);
    },
    description: '객체의 값 배열',
  },

  /**
   * json - JSON 문자열로 변환
   *
   * @example
   * "{{object | json}}" → '{"key": "value"}'
   */
  json: {
    fn: (value: any, indent?: number): string => {
      try {
        return JSON.stringify(value, null, indent);
      } catch {
        return '';
      }
    },
    description: 'JSON 문자열로 변환',
  },

  // ============================================
  // 다국어 파이프
  // ============================================

  /**
   * localized - 다국어 객체에서 현재 로케일 값 추출
   *
   * @example
   * "{{product.name | localized}}" → "상품명" (현재 로케일이 ko인 경우)
   *
   * @note 이 파이프는 컨텍스트에서 $locale 값을 참조해야 합니다.
   *       evaluateExpression에서 컨텍스트와 함께 호출됩니다.
   */
  localized: {
    fn: (value: any, locale?: string): string => {
      if (value == null) return '';
      if (typeof value === 'string') return value;
      if (typeof value !== 'object') return String(value);

      // 로케일 우선순위: 인자 > ko > en > 첫 번째 값
      const targetLocale = locale || 'ko';
      return value[targetLocale] || value.ko || value.en || Object.values(value)[0] || '';
    },
    description: '다국어 객체에서 로케일에 맞는 값 추출',
  },
};

/**
 * 커스텀 파이프 레지스트리
 */
const customPipes: Map<string, PipeDefinition> = new Map();

/**
 * 파이프 레지스트리 클래스
 *
 * 파이프 함수 등록, 조회, 실행을 관리합니다.
 */
export class PipeRegistry {
  private static instance: PipeRegistry | null = null;

  /**
   * 싱글톤 인스턴스 반환
   */
  public static getInstance(): PipeRegistry {
    if (!PipeRegistry.instance) {
      PipeRegistry.instance = new PipeRegistry();
    }
    return PipeRegistry.instance;
  }

  /**
   * 파이프 함수 등록
   *
   * @param name - 파이프 이름
   * @param fn - 파이프 함수
   * @param description - 설명 (선택)
   *
   * @example
   * ```typescript
   * PipeRegistry.getInstance().register('currency', (value, symbol = '$') => {
   *   return `${symbol}${Number(value).toFixed(2)}`;
   * }, '통화 포맷');
   * ```
   */
  public register(name: string, fn: PipeFunction, description?: string): void {
    if (builtInPipes[name]) {
      logger.warn(`[PipeRegistry] 내장 파이프를 덮어쓸 수 없습니다: ${name}`);
      return;
    }

    customPipes.set(name, { fn, description });
    logger.log(`[PipeRegistry] 커스텀 파이프 등록됨: ${name}`);
  }

  /**
   * 파이프 함수 해제
   *
   * @param name - 파이프 이름
   */
  public unregister(name: string): boolean {
    if (builtInPipes[name]) {
      logger.warn(`[PipeRegistry] 내장 파이프는 해제할 수 없습니다: ${name}`);
      return false;
    }

    const result = customPipes.delete(name);
    if (result) {
      logger.log(`[PipeRegistry] 커스텀 파이프 해제됨: ${name}`);
    }
    return result;
  }

  /**
   * 파이프 함수 조회
   *
   * @param name - 파이프 이름
   * @returns 파이프 함수 또는 undefined
   */
  public get(name: string): PipeFunction | undefined {
    // 내장 파이프 우선 조회
    const builtIn = builtInPipes[name];
    if (builtIn) return builtIn.fn;

    // 커스텀 파이프 조회
    const custom = customPipes.get(name);
    return custom?.fn;
  }

  /**
   * 파이프 존재 여부 확인
   *
   * @param name - 파이프 이름
   */
  public has(name: string): boolean {
    return !!builtInPipes[name] || customPipes.has(name);
  }

  /**
   * 파이프 함수 실행
   *
   * @param name - 파이프 이름
   * @param value - 변환할 값
   * @param args - 파이프 인자들
   * @returns 변환된 값
   *
   * @example
   * ```typescript
   * const result = pipeRegistry.execute('date', '2024-01-15');
   * // 결과: "2024-01-15"
   *
   * const result2 = pipeRegistry.execute('truncate', 'Long text...', [50, '...']);
   * // 결과: "Long text..."
   * ```
   */
  public execute(name: string, value: any, args: any[] = []): any {
    const pipe = this.get(name);

    if (!pipe) {
      logger.warn(`[PipeRegistry] 알 수 없는 파이프: ${name}`);
      return value; // 파이프가 없으면 원본 값 반환
    }

    try {
      return pipe(value, ...args);
    } catch (error) {
      logger.error(`[PipeRegistry] 파이프 실행 오류 (${name}):`, error);
      return value; // 오류 시 원본 값 반환
    }
  }

  /**
   * 등록된 모든 파이프 목록 반환
   */
  public list(): { name: string; description?: string; type: 'built-in' | 'custom' }[] {
    const pipes: { name: string; description?: string; type: 'built-in' | 'custom' }[] = [];

    // 내장 파이프
    for (const [name, def] of Object.entries(builtInPipes)) {
      pipes.push({ name, description: def.description, type: 'built-in' });
    }

    // 커스텀 파이프
    for (const [name, def] of customPipes.entries()) {
      pipes.push({ name, description: def.description, type: 'custom' });
    }

    return pipes.sort((a, b) => a.name.localeCompare(b.name));
  }

  /**
   * 테스트/리셋용: 모든 커스텀 파이프 제거
   */
  public clearCustomPipes(): void {
    customPipes.clear();
  }
}

/**
 * 파이프 레지스트리 싱글톤 인스턴스
 */
export const pipeRegistry = PipeRegistry.getInstance();

/**
 * 파이프 표현식 파싱 결과
 */
export interface ParsedPipe {
  name: string;
  args: any[];
}

/**
 * 파이프 표현식 파싱
 *
 * `value | pipeName(arg1, arg2)` 형태의 문자열을 파싱합니다.
 *
 * @param pipeExpr - 파이프 표현식 (파이프 이름 + 인자, 예: "date", "truncate(100)")
 * @returns 파싱된 파이프 정보
 *
 * @example
 * ```typescript
 * parsePipeExpression("date") // { name: "date", args: [] }
 * parsePipeExpression("truncate(100)") // { name: "truncate", args: [100] }
 * parsePipeExpression("truncate(100, '...')") // { name: "truncate", args: [100, "..."] }
 * ```
 */
export function parsePipeExpression(pipeExpr: string): ParsedPipe {
  const trimmed = pipeExpr.trim();

  // 괄호가 없으면 인자 없음
  const parenIndex = trimmed.indexOf('(');
  if (parenIndex === -1) {
    return { name: trimmed, args: [] };
  }

  const name = trimmed.slice(0, parenIndex).trim();
  const argsStr = trimmed.slice(parenIndex + 1, -1); // 마지막 ')' 제외

  // 빈 괄호
  if (!argsStr.trim()) {
    return { name, args: [] };
  }

  // 인자 파싱 (간단한 CSV 스타일)
  const args: any[] = [];
  let current = '';
  let inString: string | null = null;
  let depth = 0;

  for (let i = 0; i < argsStr.length; i++) {
    const char = argsStr[i];
    const prevChar = i > 0 ? argsStr[i - 1] : '';

    // 이스케이프 문자 처리
    if (prevChar === '\\') {
      current += char;
      continue;
    }

    // 문자열 시작/종료
    if ((char === '"' || char === "'") && !inString) {
      inString = char;
      continue;
    }
    if (char === inString) {
      inString = null;
      continue;
    }

    // 문자열 내부라면 그대로 추가
    if (inString) {
      current += char;
      continue;
    }

    // 중첩 괄호/대괄호 처리
    if (char === '(' || char === '[' || char === '{') {
      depth++;
      current += char;
      continue;
    }
    if (char === ')' || char === ']' || char === '}') {
      depth--;
      current += char;
      continue;
    }

    // 쉼표는 인자 구분자 (깊이 0일 때만)
    if (char === ',' && depth === 0) {
      args.push(parseArgValue(current.trim()));
      current = '';
      continue;
    }

    current += char;
  }

  // 마지막 인자
  if (current.trim()) {
    args.push(parseArgValue(current.trim()));
  }

  return { name, args };
}

/**
 * 인자 값 파싱
 *
 * 문자열, 숫자, 불리언 등을 적절한 타입으로 변환합니다.
 */
function parseArgValue(value: string): any {
  // null/undefined
  if (value === 'null') return null;
  if (value === 'undefined') return undefined;

  // 불리언
  if (value === 'true') return true;
  if (value === 'false') return false;

  // 숫자
  const num = Number(value);
  if (!isNaN(num) && value !== '') return num;

  // 문자열 (따옴표 제거된 상태)
  return value;
}

/**
 * 표현식에서 파이프를 분리
 *
 * `expression | pipe1 | pipe2(arg)` 형태를 분석하여
 * 기본 표현식과 파이프 목록을 반환합니다.
 *
 * @param expr - 전체 표현식
 * @returns [기본 표현식, 파이프 배열]
 *
 * @example
 * ```typescript
 * splitPipes("post.created_at | date")
 * // ["post.created_at", ["date"]]
 *
 * splitPipes("value | truncate(100) | uppercase")
 * // ["value", ["truncate(100)", "uppercase"]]
 *
 * splitPipes("a || b ? 'yes' : 'no'")
 * // ["a || b ? 'yes' : 'no'", []] - || 는 OR 연산자로 처리됨
 * ```
 */
export function splitPipes(expr: string): [string, string[]] {
  const parts: string[] = [];
  let current = '';
  let inString: string | null = null;
  let depth = 0;

  for (let i = 0; i < expr.length; i++) {
    const char = expr[i];
    const prevChar = i > 0 ? expr[i - 1] : '';
    const nextChar = i < expr.length - 1 ? expr[i + 1] : '';

    // 이스케이프 문자 처리
    if (prevChar === '\\') {
      current += char;
      continue;
    }

    // 문자열 시작/종료
    if ((char === '"' || char === "'") && !inString) {
      inString = char;
      current += char;
      continue;
    }
    if (char === inString) {
      inString = null;
      current += char;
      continue;
    }

    // 문자열 내부라면 그대로 추가
    if (inString) {
      current += char;
      continue;
    }

    // 중첩 괄호/대괄호/중괄호 처리
    if (char === '(' || char === '[' || char === '{') {
      depth++;
      current += char;
      continue;
    }
    if (char === ')' || char === ']' || char === '}') {
      depth--;
      current += char;
      continue;
    }

    // 파이프 구분 (단일 | 이고 || 가 아닌 경우, 깊이 0일 때만)
    if (char === '|' && nextChar !== '|' && prevChar !== '|' && depth === 0) {
      parts.push(current.trim());
      current = '';
      continue;
    }

    current += char;
  }

  // 마지막 부분
  if (current.trim()) {
    parts.push(current.trim());
  }

  // 첫 번째는 기본 표현식, 나머지는 파이프
  if (parts.length === 0) {
    return [expr, []];
  }

  return [parts[0], parts.slice(1)];
}

/**
 * 표현식에 파이프가 포함되어 있는지 확인
 *
 * || (OR 연산자)는 파이프로 인식하지 않습니다.
 *
 * @param expr - 표현식
 * @returns 파이프 포함 여부
 */
export function hasPipes(expr: string): boolean {
  let inString: string | null = null;
  let depth = 0;

  for (let i = 0; i < expr.length; i++) {
    const char = expr[i];
    const prevChar = i > 0 ? expr[i - 1] : '';
    const nextChar = i < expr.length - 1 ? expr[i + 1] : '';

    // 이스케이프 문자 처리
    if (prevChar === '\\') continue;

    // 문자열 시작/종료
    if ((char === '"' || char === "'") && !inString) {
      inString = char;
      continue;
    }
    if (char === inString) {
      inString = null;
      continue;
    }

    // 문자열 내부는 스킵
    if (inString) continue;

    // 중첩 처리
    if (char === '(' || char === '[' || char === '{') {
      depth++;
      continue;
    }
    if (char === ')' || char === ']' || char === '}') {
      depth--;
      continue;
    }

    // 단일 | 감지 (|| 제외, 깊이 0)
    if (char === '|' && nextChar !== '|' && prevChar !== '|' && depth === 0) {
      return true;
    }
  }

  return false;
}

/**
 * 파이프 체인 실행
 *
 * 기본 값에 파이프들을 순차적으로 적용합니다.
 *
 * @param value - 초기 값
 * @param pipes - 파이프 표현식 배열
 * @returns 최종 변환된 값
 */
export function executePipeChain(value: any, pipes: string[]): any {
  const registry = PipeRegistry.getInstance();
  let result = value;

  for (const pipeExpr of pipes) {
    const parsed = parsePipeExpression(pipeExpr);
    result = registry.execute(parsed.name, result, parsed.args);
  }

  return result;
}
