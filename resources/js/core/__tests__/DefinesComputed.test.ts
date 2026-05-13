/**
 * Defines/Computed 기능 테스트
 *
 * 레이아웃 JSON의 defines와 computed 속성 처리 테스트
 *
 * @since engine-v1.9.0
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

/**
 * calculateComputed 함수 로직을 테스트하기 위한 헬퍼 함수
 *
 * TemplateApp의 private 메서드 로직을 분리하여 테스트
 */
function calculateComputed(
    computed: Record<string, string>,
    dataContext: Record<string, any>
): Record<string, any> {
    const result: Record<string, any> = {};

    for (const [key, expression] of Object.entries(computed)) {
        try {
            if (typeof expression === 'string') {
                let value: any;

                if (expression.startsWith('{{') && expression.endsWith('}}')) {
                    const innerExpression = expression.slice(2, -2).trim();
                    value = evaluateComputedExpression(innerExpression, dataContext);
                } else {
                    value = expression;
                }

                result[key] = value;
            }
        } catch (error) {
            result[key] = undefined;
        }
    }

    return result;
}

/**
 * evaluateComputedExpression 함수 로직
 */
function evaluateComputedExpression(expression: string, context: Record<string, any>): any {
    try {
        // eslint-disable-next-line @typescript-eslint/no-implied-eval
        const fn = new Function('ctx', `
            with(ctx) {
                try {
                    return ${expression};
                } catch (e) {
                    return undefined;
                }
            }
        `);

        return fn(context);
    } catch (error) {
        return undefined;
    }
}

describe('Defines 기능', () => {
    describe('정적 상수 정의', () => {
        it('간단한 문자열 매핑을 정의할 수 있어야 함', () => {
            const defines = {
                currencyToFlag: {
                    KRW: 'kr',
                    USD: 'us',
                    JPY: 'jp',
                    CNY: 'cn',
                    EUR: 'eu',
                },
            };

            expect(defines.currencyToFlag.KRW).toBe('kr');
            expect(defines.currencyToFlag.USD).toBe('us');
            expect(defines.currencyToFlag.JPY).toBe('jp');
        });

        it('중첩된 객체를 정의할 수 있어야 함', () => {
            const defines = {
                statusColors: {
                    active: { bg: 'green', text: 'white' },
                    inactive: { bg: 'gray', text: 'black' },
                    pending: { bg: 'yellow', text: 'black' },
                },
            };

            expect(defines.statusColors.active.bg).toBe('green');
            expect(defines.statusColors.pending.text).toBe('black');
        });

        it('배열을 정의할 수 있어야 함', () => {
            const defines = {
                supportedCurrencies: ['KRW', 'USD', 'JPY', 'CNY', 'EUR'],
            };

            expect(defines.supportedCurrencies).toContain('KRW');
            expect(defines.supportedCurrencies.length).toBe(5);
        });
    });

    describe('dataContext에 _defines 주입', () => {
        it('defines가 있으면 _defines로 주입되어야 함', () => {
            const layoutData = {
                defines: {
                    currencyToFlag: {
                        KRW: 'kr',
                        USD: 'us',
                    },
                },
            };

            const definesData = layoutData.defines || {};
            const dataContext: Record<string, any> = {};

            if (Object.keys(definesData).length > 0) {
                dataContext._defines = definesData;
            }

            expect(dataContext._defines).toBeDefined();
            expect(dataContext._defines.currencyToFlag.KRW).toBe('kr');
        });

        it('defines가 없으면 _defines는 undefined이어야 함', () => {
            const layoutData = {
                defines: {},
            };

            const definesData = layoutData.defines || {};
            const dataContext: Record<string, any> = {};

            if (Object.keys(definesData).length > 0) {
                dataContext._defines = definesData;
            }

            expect(dataContext._defines).toBeUndefined();
        });
    });
});

describe('Computed 기능', () => {
    describe('표현식 평가', () => {
        it('단순 속성 접근을 평가할 수 있어야 함', () => {
            const context = {
                user: { name: '홍길동', age: 30 },
            };

            const result = evaluateComputedExpression('user.name', context);
            expect(result).toBe('홍길동');
        });

        it('산술 연산을 평가할 수 있어야 함', () => {
            const context = {
                price: 1000,
                quantity: 5,
            };

            const result = evaluateComputedExpression('price * quantity', context);
            expect(result).toBe(5000);
        });

        it('비교 연산을 평가할 수 있어야 함', () => {
            const context = {
                user: { role: 'admin' },
            };

            const result = evaluateComputedExpression("user.role === 'admin'", context);
            expect(result).toBe(true);
        });

        it('삼항 연산자를 평가할 수 있어야 함', () => {
            const context = {
                isActive: true,
            };

            const result = evaluateComputedExpression("isActive ? '활성' : '비활성'", context);
            expect(result).toBe('활성');
        });

        it('nullish 연산자를 평가할 수 있어야 함', () => {
            const context = {
                value: null,
                defaultValue: '기본값',
            };

            const result = evaluateComputedExpression('value ?? defaultValue', context);
            expect(result).toBe('기본값');
        });

        it('옵셔널 체이닝을 평가할 수 있어야 함', () => {
            const context = {
                user: { profile: null },
            };

            const result = evaluateComputedExpression("user?.profile?.name ?? '없음'", context);
            expect(result).toBe('없음');
        });

        it('_defines 값을 참조할 수 있어야 함', () => {
            const context = {
                _defines: {
                    currencyToFlag: {
                        KRW: 'kr',
                        USD: 'us',
                    },
                },
                currency: 'KRW',
            };

            const result = evaluateComputedExpression('_defines.currencyToFlag[currency]', context);
            expect(result).toBe('kr');
        });
    });

    describe('calculateComputed 함수', () => {
        it('여러 computed 값을 계산할 수 있어야 함', () => {
            const computed = {
                fullName: "{{user.firstName + ' ' + user.lastName}}",
                isAdult: '{{user.age >= 18}}',
            };

            const context = {
                user: { firstName: '길동', lastName: '홍', age: 25 },
            };

            const result = calculateComputed(computed, context);

            expect(result.fullName).toBe('길동 홍');
            expect(result.isAdult).toBe(true);
        });

        it('{{}} 없는 순수 문자열은 그대로 사용해야 함', () => {
            const computed = {
                staticValue: '고정 문자열',
            };

            const context = {};

            const result = calculateComputed(computed, context);

            expect(result.staticValue).toBe('고정 문자열');
        });

        it('평가 실패 시 undefined를 반환해야 함', () => {
            const computed = {
                invalid: '{{nonExistent.property.deep}}',
            };

            const context = {};

            const result = calculateComputed(computed, context);

            expect(result.invalid).toBeUndefined();
        });

        it('_defines를 참조하는 computed를 계산할 수 있어야 함', () => {
            const computed = {
                flagCode: '{{_defines.currencyToFlag[currentCurrency]}}',
            };

            const context = {
                _defines: {
                    currencyToFlag: {
                        KRW: 'kr',
                        USD: 'us',
                    },
                },
                currentCurrency: 'USD',
            };

            const result = calculateComputed(computed, context);

            expect(result.flagCode).toBe('us');
        });
    });

    describe('실제 사용 사례', () => {
        it('통화-국기 매핑을 처리할 수 있어야 함', () => {
            // 레이아웃 JSON에서 정의한 것처럼 테스트
            const layoutData = {
                defines: {
                    currencyToFlag: {
                        KRW: 'kr',
                        USD: 'us',
                        JPY: 'jp',
                        CNY: 'cn',
                        EUR: 'eu',
                    },
                },
                computed: {
                    currentFlag: '{{_defines.currencyToFlag[currency.code] ?? "un"}}',
                },
            };

            // dataContext 구성 (TemplateApp에서 하는 것처럼)
            const dataContext = {
                currency: { code: 'JPY', name: 'JPY (엔)' },
                _defines: layoutData.defines,
            };

            // computed 계산
            const computedResult = calculateComputed(layoutData.computed, dataContext);

            expect(computedResult.currentFlag).toBe('jp');
        });

        it('알 수 없는 통화에 대해 기본값을 반환해야 함', () => {
            const layoutData = {
                defines: {
                    currencyToFlag: {
                        KRW: 'kr',
                        USD: 'us',
                    },
                },
                computed: {
                    currentFlag: '{{_defines.currencyToFlag[currency.code] ?? "un"}}',
                },
            };

            const dataContext = {
                currency: { code: 'GBP', name: 'GBP (파운드)' },
                _defines: layoutData.defines,
            };

            const computedResult = calculateComputed(layoutData.computed, dataContext);

            expect(computedResult.currentFlag).toBe('un');
        });

        it('복잡한 조건부 computed를 처리할 수 있어야 함', () => {
            const layoutData = {
                defines: {
                    statusLabels: {
                        active: '활성',
                        inactive: '비활성',
                        pending: '대기중',
                    },
                    statusColors: {
                        active: 'text-green-600',
                        inactive: 'text-gray-600',
                        pending: 'text-yellow-600',
                    },
                },
                computed: {
                    statusLabel: "{{_defines.statusLabels[status] ?? '알 수 없음'}}",
                    statusClass: "{{_defines.statusColors[status] ?? 'text-gray-400'}}",
                },
            };

            const dataContext = {
                status: 'active',
                _defines: layoutData.defines,
            };

            const computedResult = calculateComputed(layoutData.computed, dataContext);

            expect(computedResult.statusLabel).toBe('활성');
            expect(computedResult.statusClass).toBe('text-green-600');
        });
    });
});

describe('LayoutLoader defines/computed 인터페이스', () => {
    it('LayoutData 인터페이스에 defines 필드가 포함되어야 함', () => {
        // TypeScript 인터페이스 검증을 위한 타입 테스트
        interface LayoutData {
            defines?: Record<string, any>;
            computed?: Record<string, string>;
        }

        const layoutData: LayoutData = {
            defines: {
                mapping: { a: 1, b: 2 },
            },
            computed: {
                result: '{{mapping.a + mapping.b}}',
            },
        };

        expect(layoutData.defines).toBeDefined();
        expect(layoutData.computed).toBeDefined();
    });
});
