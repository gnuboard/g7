/**
 * $get 헬퍼 함수 테스트
 *
 * 깊은 객체 경로 접근 및 폴백 값 기능 테스트
 */

import { describe, it, expect, beforeEach } from 'vitest';
import { DataBindingEngine } from '../DataBindingEngine';

describe('$get 헬퍼 함수', () => {
  let engine: DataBindingEngine;

  beforeEach(() => {
    engine = new DataBindingEngine();
  });

  describe('기본 동작', () => {
    it('단일 키로 객체 접근', () => {
      const context = {
        product: {
          name: '상품명',
          price: 10000,
        },
      };

      const result = engine.evaluateExpression("$get(product, 'name')", context);
      expect(result).toBe('상품명');
    });

    it('배열 키로 깊은 경로 접근', () => {
      const context = {
        product: {
          prices: {
            KRW: { formatted: '10,000원' },
            USD: { formatted: '$10.00' },
          },
        },
      };

      const result = engine.evaluateExpression("$get(product.prices, ['KRW', 'formatted'])", context);
      expect(result).toBe('10,000원');
    });

    it('동적 키로 접근', () => {
      const context = {
        currency: 'USD',
        product: {
          prices: {
            KRW: { formatted: '10,000원' },
            USD: { formatted: '$10.00' },
          },
        },
      };

      const result = engine.evaluateExpression("$get(product.prices, [currency, 'formatted'])", context);
      expect(result).toBe('$10.00');
    });

    it('폴백 값 반환 (존재하지 않는 경로)', () => {
      const context = {
        product: {
          prices: {
            KRW: { formatted: '10,000원' },
          },
        },
      };

      const result = engine.evaluateExpression("$get(product.prices, ['EUR', 'formatted'], 'N/A')", context);
      expect(result).toBe('N/A');
    });
  });

  describe('null/undefined 처리', () => {
    it('null 객체에서 폴백 반환', () => {
      const context = {
        product: null,
      };

      const result = engine.evaluateExpression("$get(product, 'name', 'Default Name')", context);
      expect(result).toBe('Default Name');
    });

    it('undefined 객체에서 폴백 반환', () => {
      const context = {};

      const result = engine.evaluateExpression("$get(product, 'name', 'Default Name')", context);
      expect(result).toBe('Default Name');
    });

    it('중간 경로가 null인 경우 폴백 반환', () => {
      const context = {
        product: {
          prices: null,
        },
      };

      const result = engine.evaluateExpression("$get(product.prices, ['KRW', 'formatted'], 'N/A')", context);
      expect(result).toBe('N/A');
    });

    it('중간 경로가 undefined인 경우 폴백 반환', () => {
      const context = {
        product: {},
      };

      const result = engine.evaluateExpression("$get(product.prices, ['KRW', 'formatted'], 'N/A')", context);
      expect(result).toBe('N/A');
    });

    it('최종 값이 null인 경우 폴백 반환', () => {
      const context = {
        product: {
          name: null,
        },
      };

      const result = engine.evaluateExpression("$get(product, 'name', 'Default')", context);
      expect(result).toBe('Default');
    });

    it('최종 값이 undefined인 경우 폴백 반환', () => {
      const context = {
        product: {
          name: undefined,
        },
      };

      const result = engine.evaluateExpression("$get(product, 'name', 'Default')", context);
      expect(result).toBe('Default');
    });
  });

  describe('엣지 케이스', () => {
    it('빈 경로 배열은 객체 자체 반환', () => {
      const context = {
        product: { name: '상품' },
      };

      const result = engine.evaluateExpression('$get(product, [])', context);
      expect(result).toEqual({ name: '상품' });
    });

    it('폴백 없이 존재하지 않는 경로 접근 시 undefined 반환', () => {
      const context = {
        product: {},
      };

      const result = engine.evaluateExpression("$get(product, 'name')", context);
      expect(result).toBeUndefined();
    });

    it('숫자 인덱스로 배열 접근', () => {
      const context = {
        items: ['a', 'b', 'c'],
      };

      const result = engine.evaluateExpression('$get(items, 1)', context);
      expect(result).toBe('b');
    });

    it('배열 인덱스로 깊은 배열 접근', () => {
      const context = {
        matrix: [
          [1, 2, 3],
          [4, 5, 6],
        ],
      };

      const result = engine.evaluateExpression('$get(matrix, [1, 2])', context);
      expect(result).toBe(6);
    });

    it('null 키로 접근 시 폴백 반환', () => {
      const context = {
        product: { name: '상품' },
        key: null,
      };

      const result = engine.evaluateExpression("$get(product, key, 'fallback')", context);
      expect(result).toBe('fallback');
    });

    it('undefined 키로 접근 시 폴백 반환', () => {
      const context = {
        product: { name: '상품' },
      };

      const result = engine.evaluateExpression("$get(product, undefinedKey, 'fallback')", context);
      expect(result).toBe('fallback');
    });

    it('빈 문자열 키로 접근', () => {
      const context = {
        product: {
          '': 'empty key value',
        },
      };

      const result = engine.evaluateExpression("$get(product, '')", context);
      expect(result).toBe('empty key value');
    });

    it('숫자 0 키로 접근', () => {
      const context = {
        items: { 0: 'zero' },
      };

      const result = engine.evaluateExpression('$get(items, 0)', context);
      expect(result).toBe('zero');
    });
  });

  describe('다중 통화 패턴 (실제 사용 사례)', () => {
    it('다중 통화 가격 포맷 접근', () => {
      const context = {
        _global: {
          preferredCurrency: 'USD',
        },
        product: {
          multi_currency_selling_price: {
            KRW: { formatted: '10,000원' },
            USD: { formatted: '$10.00' },
            EUR: { formatted: '€8.50' },
          },
          selling_price_formatted: '10,000원',
        },
      };

      const result = engine.evaluateExpression(
        "$get(product.multi_currency_selling_price, [_global.preferredCurrency, 'formatted'], product.selling_price_formatted)",
        context
      );
      expect(result).toBe('$10.00');
    });

    it('선호 통화가 없을 때 기본 가격 사용', () => {
      const context = {
        _global: {
          preferredCurrency: 'JPY', // 존재하지 않는 통화
        },
        product: {
          multi_currency_selling_price: {
            KRW: { formatted: '10,000원' },
            USD: { formatted: '$10.00' },
          },
          selling_price_formatted: '10,000원',
        },
      };

      const result = engine.evaluateExpression(
        "$get(product.multi_currency_selling_price, [_global.preferredCurrency, 'formatted'], product.selling_price_formatted)",
        context
      );
      expect(result).toBe('10,000원');
    });

    it('선호 통화가 null일 때 기본값 사용', () => {
      const context = {
        _global: {
          preferredCurrency: null,
        },
        defaultCurrency: 'KRW',
        product: {
          multi_currency_selling_price: {
            KRW: { formatted: '10,000원' },
          },
          selling_price_formatted: '10,000원',
        },
      };

      const result = engine.evaluateExpression(
        "$get(product.multi_currency_selling_price, [_global.preferredCurrency ?? defaultCurrency, 'formatted'], product.selling_price_formatted)",
        context
      );
      expect(result).toBe('10,000원');
    });
  });

  describe('다국어 패턴 (실제 사용 사례)', () => {
    it('다국어 객체에서 현재 로케일 값 접근', () => {
      const context = {
        _global: { locale: 'en' },
        product: {
          name: {
            ko: '상품명',
            en: 'Product Name',
          },
        },
      };

      const result = engine.evaluateExpression(
        "$get(product.name, _global.locale, product.name.ko)",
        context
      );
      expect(result).toBe('Product Name');
    });

    it('지원하지 않는 로케일에서 폴백', () => {
      const context = {
        _global: { locale: 'ja' }, // 지원하지 않는 로케일
        product: {
          name: {
            ko: '상품명',
            en: 'Product Name',
          },
        },
      };

      const result = engine.evaluateExpression(
        "$get(product.name, _global.locale, product.name.ko)",
        context
      );
      expect(result).toBe('상품명');
    });
  });

  describe('중첩 설정 패턴 (실제 사용 사례)', () => {
    it('중첩된 설정 값 접근', () => {
      const context = {
        _global: {
          settings: {
            drivers: {
              mail: {
                host: 'smtp.example.com',
                port: 587,
              },
            },
          },
        },
      };

      const result = engine.evaluateExpression(
        "$get(_global.settings, ['drivers', 'mail', 'host'], 'localhost')",
        context
      );
      expect(result).toBe('smtp.example.com');
    });

    it('설정이 없을 때 기본값 반환', () => {
      const context = {
        _global: {
          settings: {},
        },
      };

      const result = engine.evaluateExpression(
        "$get(_global.settings, ['drivers', 'mail', 'host'], 'localhost')",
        context
      );
      expect(result).toBe('localhost');
    });
  });

  describe('동적 키 패턴 (실제 사용 사례)', () => {
    it('선택된 플랜의 월간 가격 접근', () => {
      const context = {
        selectedPlan: 'pro',
        prices: {
          basic: { monthly: 9.99, yearly: 99.99 },
          pro: { monthly: 29.99, yearly: 299.99 },
          enterprise: { monthly: 99.99, yearly: 999.99 },
        },
      };

      const result = engine.evaluateExpression(
        "$get(prices, [selectedPlan, 'monthly'], 0)",
        context
      );
      expect(result).toBe(29.99);
    });

    it('존재하지 않는 플랜에서 기본값 반환', () => {
      const context = {
        selectedPlan: 'unlimited', // 존재하지 않는 플랜
        prices: {
          basic: { monthly: 9.99 },
          pro: { monthly: 29.99 },
        },
      };

      const result = engine.evaluateExpression(
        "$get(prices, [selectedPlan, 'monthly'], 0)",
        context
      );
      expect(result).toBe(0);
    });
  });

  describe('falsy 값 처리', () => {
    it('값이 0인 경우 0 반환 (폴백 아님)', () => {
      const context = {
        product: { stock: 0 },
      };

      const result = engine.evaluateExpression("$get(product, 'stock', 999)", context);
      expect(result).toBe(0);
    });

    it('값이 빈 문자열인 경우 빈 문자열 반환 (폴백 아님)', () => {
      const context = {
        product: { description: '' },
      };

      const result = engine.evaluateExpression("$get(product, 'description', 'No description')", context);
      expect(result).toBe('');
    });

    it('값이 false인 경우 false 반환 (폴백 아님)', () => {
      const context = {
        settings: { enabled: false },
      };

      const result = engine.evaluateExpression("$get(settings, 'enabled', true)", context);
      expect(result).toBe(false);
    });
  });

  describe('iteration 컨텍스트 (캐싱 방지)', () => {
    it('각 row별 다른 가격 반환', () => {
      const products = [
        {
          multi_currency_price: { KRW: { formatted: '10,000원' } },
        },
        {
          multi_currency_price: { KRW: { formatted: '20,000원' } },
        },
        {
          multi_currency_price: { KRW: { formatted: '30,000원' } },
        },
      ];

      const results = products.map(row => {
        const context = {
          row,
          currency: 'KRW',
        };
        return engine.evaluateExpression(
          "$get(row.multi_currency_price, [currency, 'formatted'], 'N/A')",
          context
        );
      });

      expect(results[0]).toBe('10,000원');
      expect(results[1]).toBe('20,000원');
      expect(results[2]).toBe('30,000원');
    });
  });
});
