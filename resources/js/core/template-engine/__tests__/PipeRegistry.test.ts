/**
 * PipeRegistry 테스트
 *
 * 파이프 함수 레지스트리 및 파이프 파싱 기능 테스트
 */

import { describe, it, expect, beforeEach } from 'vitest';
import {
  PipeRegistry,
  pipeRegistry,
  parsePipeExpression,
  splitPipes,
  hasPipes,
  executePipeChain,
} from '../PipeRegistry';

describe('PipeRegistry', () => {
  beforeEach(() => {
    // 테스트 전 커스텀 파이프 초기화
    pipeRegistry.clearCustomPipes();
  });

  describe('hasPipes', () => {
    it('단일 파이프를 감지해야 함', () => {
      expect(hasPipes('value | date')).toBe(true);
      expect(hasPipes('post.created_at | datetime')).toBe(true);
    });

    it('여러 파이프를 감지해야 함', () => {
      expect(hasPipes('value | truncate(100) | uppercase')).toBe(true);
    });

    it('|| 연산자는 파이프로 인식하지 않아야 함', () => {
      expect(hasPipes('a || b')).toBe(false);
      expect(hasPipes('value || defaultValue')).toBe(false);
    });

    it('문자열 내부의 |는 무시해야 함', () => {
      expect(hasPipes("'a | b'")).toBe(false);
      expect(hasPipes('"pipe|test"')).toBe(false);
    });

    it('괄호 내부의 |는 무시해야 함', () => {
      expect(hasPipes('func(a | b)')).toBe(false);
      expect(hasPipes('arr[a | b]')).toBe(false);
    });

    it('파이프와 || 혼합 시 파이프 감지', () => {
      expect(hasPipes('(a || b) | date')).toBe(true);
    });
  });

  describe('splitPipes', () => {
    it('단일 파이프를 분리해야 함', () => {
      const [expr, pipes] = splitPipes('value | date');
      expect(expr).toBe('value');
      expect(pipes).toEqual(['date']);
    });

    it('여러 파이프를 분리해야 함', () => {
      const [expr, pipes] = splitPipes('value | truncate(100) | uppercase');
      expect(expr).toBe('value');
      expect(pipes).toEqual(['truncate(100)', 'uppercase']);
    });

    it('파이프가 없으면 빈 배열 반환', () => {
      const [expr, pipes] = splitPipes('simple.path');
      expect(expr).toBe('simple.path');
      expect(pipes).toEqual([]);
    });

    it('|| 연산자는 분리하지 않아야 함', () => {
      const [expr, pipes] = splitPipes('a || b');
      expect(expr).toBe('a || b');
      expect(pipes).toEqual([]);
    });

    it('복잡한 표현식과 파이프 조합', () => {
      const [expr, pipes] = splitPipes('(a ?? b) | date');
      expect(expr).toBe('(a ?? b)');
      expect(pipes).toEqual(['date']);
    });

    it('문자열 내부의 |는 분리하지 않아야 함', () => {
      const [expr, pipes] = splitPipes("'a | b' | uppercase");
      expect(expr).toBe("'a | b'");
      expect(pipes).toEqual(['uppercase']);
    });
  });

  describe('parsePipeExpression', () => {
    it('인자 없는 파이프 파싱', () => {
      const parsed = parsePipeExpression('date');
      expect(parsed.name).toBe('date');
      expect(parsed.args).toEqual([]);
    });

    it('숫자 인자 파싱', () => {
      const parsed = parsePipeExpression('truncate(100)');
      expect(parsed.name).toBe('truncate');
      expect(parsed.args).toEqual([100]);
    });

    it('여러 인자 파싱', () => {
      const parsed = parsePipeExpression('truncate(100, ...)');
      expect(parsed.name).toBe('truncate');
      expect(parsed.args).toEqual([100, '...']);
    });

    it('문자열 인자 파싱 (따옴표 없이)', () => {
      const parsed = parsePipeExpression('default(Unknown)');
      expect(parsed.name).toBe('default');
      expect(parsed.args).toEqual(['Unknown']);
    });

    it('빈 괄호 파싱', () => {
      const parsed = parsePipeExpression('uppercase()');
      expect(parsed.name).toBe('uppercase');
      expect(parsed.args).toEqual([]);
    });
  });

  describe('내장 파이프 - date', () => {
    it('ISO 날짜 문자열을 포맷해야 함', () => {
      const result = pipeRegistry.execute('date', '2024-01-15T10:30:00Z');
      expect(result).toMatch(/2024-01-15/);
    });

    it('커스텀 포맷 지원', () => {
      const result = pipeRegistry.execute('date', '2024-01-15', ['YYYY/MM/DD']);
      expect(result).toBe('2024/01/15');
    });

    it('null/undefined 시 빈 문자열 반환', () => {
      expect(pipeRegistry.execute('date', null)).toBe('');
      expect(pipeRegistry.execute('date', undefined)).toBe('');
    });
  });

  describe('내장 파이프 - datetime', () => {
    it('날짜+시간 포맷', () => {
      const result = pipeRegistry.execute('datetime', '2024-01-15T14:30:00');
      expect(result).toContain('2024-01-15');
      expect(result).toContain('14:30');
    });
  });

  describe('내장 파이프 - relativeTime', () => {
    it('방금 전 표시', () => {
      const now = new Date();
      const result = pipeRegistry.execute('relativeTime', now.toISOString());
      expect(result).toBe('방금 전');
    });

    it('분 전 표시', () => {
      const fiveMinutesAgo = new Date(Date.now() - 5 * 60 * 1000);
      const result = pipeRegistry.execute('relativeTime', fiveMinutesAgo.toISOString());
      expect(result).toContain('분 전');
    });

    it('영어 로케일 지원', () => {
      const fiveMinutesAgo = new Date(Date.now() - 5 * 60 * 1000);
      const result = pipeRegistry.execute('relativeTime', fiveMinutesAgo.toISOString(), ['en']);
      expect(result).toContain('minutes ago');
    });
  });

  describe('내장 파이프 - number', () => {
    it('천단위 구분자 추가', () => {
      const result = pipeRegistry.execute('number', 1234567);
      expect(result).toContain('1');
      // 로케일에 따라 구분자가 다를 수 있음
    });

    it('소수점 자릿수 지정', () => {
      const result = pipeRegistry.execute('number', 1234.5, [2]);
      // 로케일에 따라 천단위 구분자가 포함될 수 있음 (예: "1,234.50")
      expect(result).toMatch(/1[,.]?234/);
      expect(result).toContain('50');
    });

    it('NaN 시 원본 반환', () => {
      expect(pipeRegistry.execute('number', 'abc')).toBe('abc');
    });
  });

  describe('내장 파이프 - truncate', () => {
    it('긴 문자열 자르기', () => {
      const longText = 'Lorem ipsum dolor sit amet consectetur adipiscing elit';
      const result = pipeRegistry.execute('truncate', longText, [20]);
      expect(result.length).toBe(23); // 20 + '...'
      expect(result.endsWith('...')).toBe(true);
    });

    it('짧은 문자열은 그대로 반환', () => {
      const shortText = 'Hello';
      const result = pipeRegistry.execute('truncate', shortText, [20]);
      expect(result).toBe('Hello');
    });

    it('커스텀 접미사 지원', () => {
      const text = 'Long text here';
      const result = pipeRegistry.execute('truncate', text, [4, ' [더보기]']);
      expect(result).toBe('Long [더보기]');
    });
  });

  describe('내장 파이프 - default', () => {
    it('null 시 기본값 반환', () => {
      expect(pipeRegistry.execute('default', null, ['Unknown'])).toBe('Unknown');
    });

    it('undefined 시 기본값 반환', () => {
      expect(pipeRegistry.execute('default', undefined, ['Unknown'])).toBe('Unknown');
    });

    it('빈 문자열 시 기본값 반환', () => {
      expect(pipeRegistry.execute('default', '', ['Unknown'])).toBe('Unknown');
    });

    it('값이 있으면 원본 반환', () => {
      expect(pipeRegistry.execute('default', 'Hello', ['Unknown'])).toBe('Hello');
    });
  });

  describe('내장 파이프 - join', () => {
    it('배열을 문자열로 결합', () => {
      const result = pipeRegistry.execute('join', ['a', 'b', 'c']);
      expect(result).toBe('a, b, c');
    });

    it('커스텀 구분자 지원', () => {
      const result = pipeRegistry.execute('join', ['a', 'b', 'c'], [' | ']);
      expect(result).toBe('a | b | c');
    });

    it('배열이 아니면 빈 문자열 반환', () => {
      expect(pipeRegistry.execute('join', 'not array')).toBe('');
    });
  });

  describe('내장 파이프 - first/last', () => {
    it('first: 첫 번째 요소 반환', () => {
      expect(pipeRegistry.execute('first', [1, 2, 3])).toBe(1);
    });

    it('last: 마지막 요소 반환', () => {
      expect(pipeRegistry.execute('last', [1, 2, 3])).toBe(3);
    });

    it('배열이 아니면 undefined 반환', () => {
      expect(pipeRegistry.execute('first', 'not array')).toBeUndefined();
      expect(pipeRegistry.execute('last', {})).toBeUndefined();
    });
  });

  describe('내장 파이프 - filterBy', () => {
    it('필드 기반 필터링', () => {
      const columns = [
        { field: 'name', header: '이름' },
        { field: 'price', header: '가격' },
        { field: 'stock', header: '재고' },
      ];
      const visibleColumns = ['name', 'stock'];
      const result = pipeRegistry.execute('filterBy', columns, [visibleColumns, 'field']);
      expect(result).toEqual([
        { field: 'name', header: '이름' },
        { field: 'stock', header: '재고' },
      ]);
    });

    it('값 기반 필터링 (필드 없음)', () => {
      const items = [1, 2, 3, 4, 5];
      const allowList = [2, 4];
      const result = pipeRegistry.execute('filterBy', items, [allowList]);
      expect(result).toEqual([2, 4]);
    });

    it('빈 allowList 시 원본 반환', () => {
      const items = [{ id: 1 }, { id: 2 }];
      const result = pipeRegistry.execute('filterBy', items, [null, 'id']);
      expect(result).toEqual([{ id: 1 }, { id: 2 }]);
    });

    it('배열이 아닌 입력 시 빈 배열 반환', () => {
      expect(pipeRegistry.execute('filterBy', 'not array', [['a'], 'field'])).toEqual([]);
      expect(pipeRegistry.execute('filterBy', null, [['a'], 'field'])).toEqual([]);
    });

    it('일치하는 항목이 없으면 빈 배열 반환', () => {
      const items = [{ id: 1 }, { id: 2 }];
      const allowList = [3, 4];
      const result = pipeRegistry.execute('filterBy', items, [allowList, 'id']);
      expect(result).toEqual([]);
    });
  });

  describe('내장 파이프 - keys/values', () => {
    it('keys: 객체 키 배열 반환', () => {
      const result = pipeRegistry.execute('keys', { a: 1, b: 2 });
      expect(result).toEqual(['a', 'b']);
    });

    it('values: 객체 값 배열 반환', () => {
      const result = pipeRegistry.execute('values', { a: 1, b: 2 });
      expect(result).toEqual([1, 2]);
    });

    it('null/undefined 시 빈 배열 반환', () => {
      expect(pipeRegistry.execute('keys', null)).toEqual([]);
      expect(pipeRegistry.execute('values', undefined)).toEqual([]);
    });
  });

  describe('내장 파이프 - localized', () => {
    it('현재 로케일 값 반환', () => {
      const multilang = { ko: '안녕', en: 'Hello' };
      expect(pipeRegistry.execute('localized', multilang)).toBe('안녕');
    });

    it('지정 로케일 값 반환', () => {
      const multilang = { ko: '안녕', en: 'Hello' };
      expect(pipeRegistry.execute('localized', multilang, ['en'])).toBe('Hello');
    });

    it('문자열은 그대로 반환', () => {
      expect(pipeRegistry.execute('localized', 'Simple string')).toBe('Simple string');
    });

    it('로케일 없으면 ko > en > 첫 번째 값 순으로 폴백', () => {
      expect(pipeRegistry.execute('localized', { en: 'Hello', ja: 'こんにちは' })).toBe('Hello');
    });
  });

  describe('executePipeChain', () => {
    it('파이프 체인 순차 실행', () => {
      const result = executePipeChain('hello world this is long text', ['truncate(10)', 'uppercase']);
      expect(result).toBe('HELLO WORL...');
    });

    it('단일 파이프 실행', () => {
      const result = executePipeChain('hello', ['uppercase']);
      expect(result).toBe('HELLO');
    });

    it('빈 파이프 배열 시 원본 반환', () => {
      const result = executePipeChain('hello', []);
      expect(result).toBe('hello');
    });
  });

  describe('커스텀 파이프 등록', () => {
    it('커스텀 파이프 등록 및 실행', () => {
      pipeRegistry.register('double', (value: number) => value * 2);
      expect(pipeRegistry.execute('double', 5)).toBe(10);
    });

    it('커스텀 파이프 해제', () => {
      pipeRegistry.register('temp', (value) => value);
      expect(pipeRegistry.has('temp')).toBe(true);
      pipeRegistry.unregister('temp');
      expect(pipeRegistry.has('temp')).toBe(false);
    });

    it('내장 파이프 덮어쓰기 불가', () => {
      pipeRegistry.register('date', (value) => 'custom');
      // 여전히 내장 파이프가 사용됨
      expect(pipeRegistry.execute('date', '2024-01-01')).not.toBe('custom');
    });
  });

  describe('파이프 목록 조회', () => {
    it('등록된 파이프 목록 반환', () => {
      const list = pipeRegistry.list();
      expect(list.length).toBeGreaterThan(0);
      expect(list.some((p) => p.name === 'date')).toBe(true);
      expect(list.some((p) => p.name === 'number')).toBe(true);
    });
  });
});
