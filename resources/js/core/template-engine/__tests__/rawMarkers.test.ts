import { describe, it, expect } from 'vitest';
import {
  RAW_PREFIX,
  RAW_MARKER_START,
  RAW_MARKER_END,
  wrapRaw,
  unwrapRaw,
  isRawWrapped,
  containsRawMarker,
  wrapRawDeep,
} from '../rawMarkers';

describe('rawMarkers 유틸리티 (engine-v1.27.0)', () => {
  describe('wrapRaw / unwrapRaw', () => {
    it('문자열을 마커로 래핑/언래핑', () => {
      const wrapped = wrapRaw('hello');
      expect(wrapped).toBe('\uFDD0hello\uFDD1');
      expect(unwrapRaw(wrapped)).toBe('hello');
    });

    it('빈 문자열 래핑', () => {
      const wrapped = wrapRaw('');
      expect(wrapped).toBe('\uFDD0\uFDD1');
      expect(unwrapRaw(wrapped)).toBe('');
    });

    it('$t: 토큰 포함 문자열 래핑', () => {
      const wrapped = wrapRaw('$t:admin.dashboard');
      expect(wrapped).toBe('\uFDD0$t:admin.dashboard\uFDD1');
      expect(unwrapRaw(wrapped)).toBe('$t:admin.dashboard');
    });
  });

  describe('isRawWrapped', () => {
    it('래핑된 문자열 감지', () => {
      expect(isRawWrapped('\uFDD0hello\uFDD1')).toBe(true);
    });

    it('래핑되지 않은 문자열', () => {
      expect(isRawWrapped('hello')).toBe(false);
      expect(isRawWrapped('')).toBe(false);
      expect(isRawWrapped('$t:key')).toBe(false);
    });

    it('1글자 문자열', () => {
      expect(isRawWrapped('\uFDD0')).toBe(false);
    });
  });

  describe('containsRawMarker', () => {
    it('마커 포함 문자열', () => {
      expect(containsRawMarker('제목: \uFDD0test\uFDD1 끝')).toBe(true);
    });

    it('마커 미포함 문자열', () => {
      expect(containsRawMarker('일반 텍스트')).toBe(false);
    });
  });

  describe('wrapRawDeep', () => {
    it('문자열 → 래핑', () => {
      expect(wrapRawDeep('hello')).toBe('\uFDD0hello\uFDD1');
    });

    it('숫자/boolean → 그대로', () => {
      expect(wrapRawDeep(42)).toBe(42);
      expect(wrapRawDeep(true)).toBe(true);
    });

    it('null/undefined → 그대로', () => {
      expect(wrapRawDeep(null)).toBe(null);
      expect(wrapRawDeep(undefined)).toBe(undefined);
    });

    it('배열 내 문자열 재귀 래핑', () => {
      const result = wrapRawDeep(['$t:foo', '$t:bar']);
      expect(result).toEqual(['\uFDD0$t:foo\uFDD1', '\uFDD0$t:bar\uFDD1']);
    });

    it('객체 내 문자열 재귀 래핑', () => {
      const result = wrapRawDeep({ label: '$t:foo', count: 5 });
      expect(result).toEqual({ label: '\uFDD0$t:foo\uFDD1', count: 5 });
    });

    it('중첩 객체/배열 재귀 래핑', () => {
      const input = {
        items: [
          { label: '$t:foo', nested: { text: '$t:bar' } },
        ],
        count: 3,
      };
      const result = wrapRawDeep(input);
      expect(result.items[0].label).toBe('\uFDD0$t:foo\uFDD1');
      expect(result.items[0].nested.text).toBe('\uFDD0$t:bar\uFDD1');
      expect(result.count).toBe(3);
    });
  });
});
