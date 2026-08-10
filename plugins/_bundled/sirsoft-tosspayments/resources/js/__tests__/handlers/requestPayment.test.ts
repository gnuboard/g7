// e2e:allow 정식 출시 시 제거된 번들 플러그인의 복원 — 신규 프론트 동작 없음 (결제창 진입은 PG 샌드박스 의존)
/**
 * requestPayment 핸들러 테스트
 *
 * 토스페이먼츠 결제창 호출 핸들러의 에러 처리 및 모달 열기 동작을 검증합니다.
 *
 * @effects sdk_method_mapped_from_enabled_methods, virtual_account_payload_built,
 *          escrow_products_attached, escrow_flag_off_true_or_key_absent,
 *          non_krw_domestic_method_blocked,
 *          escrow_products_attached_to_virtual_account_sdk_payload,
 *          escrow_products_absent_when_escrow_off,
 *          escrow_products_absent_for_card
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { requestPaymentHandler } from '../../handlers/requestPayment';

const CLIENT_CONFIG_DATA = {
    data: {
        client_key: 'test_key',
        sdk_url: 'https://example.com/sdk.js',
        callback_urls: { success: '/success', fail: '/fail' },
    },
};

describe('requestPaymentHandler', () => {
    let mockG7Core: {
        api: { get: ReturnType<typeof vi.fn>; post: ReturnType<typeof vi.fn> };
        state: { setLocal: ReturnType<typeof vi.fn> };
        modal: { open: ReturnType<typeof vi.fn> };
    };

    beforeEach(() => {
        mockG7Core = {
            api: {
                get: vi.fn().mockResolvedValue(CLIENT_CONFIG_DATA),
                post: vi.fn().mockResolvedValue({ success: true }),
            },
            state: { setLocal: vi.fn() },
            modal: { open: vi.fn() },
        };
        (window as any).G7Core = mockG7Core;
        (window as any).TossPayments = undefined;
    });

    afterEach(() => {
        delete (window as any).G7Core;
        delete (window as any).TossPayments;
    });

    it('pgPaymentData가 없으면 조기 반환한다', async () => {
        const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

        await requestPaymentHandler({ params: {} });

        expect(consoleSpy).toHaveBeenCalledWith(
            expect.stringContaining('pgPaymentData is required')
        );
        expect(mockG7Core.state.setLocal).not.toHaveBeenCalled();
        expect(mockG7Core.modal.open).not.toHaveBeenCalled();

        consoleSpy.mockRestore();
    });

    it('SDK 에러 시 에러 메시지를 setState하고 모달을 연다', async () => {
        const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

        // TossPayments SDK Mock — requestPayment에서 에러 발생
        const mockPayment = {
            requestPayment: vi.fn().mockRejectedValue(new Error('Payment failed')),
        };
        (window as any).TossPayments = vi.fn().mockReturnValue({
            payment: vi.fn().mockReturnValue(mockPayment),
        });
        (window as any).TossPayments.ANONYMOUS = 'ANONYMOUS';

        await requestPaymentHandler({
            params: {
                pgPaymentData: {
                    order_number: 'ORD-001',
                    order_name: 'Test Order',
                    amount: 10000,
                },
            },
        });

        // setState로 에러 메시지 설정
        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({
            paymentErrorMessage: 'Payment failed',
            isSubmittingOrder: false,
        });

        // 모달 열기
        expect(mockG7Core.modal.open).toHaveBeenCalledWith('tosspayments_payment_error_modal');

        consoleSpy.mockRestore();
    });

    describe('USER_CANCEL 처리', () => {
        const setupUserCancel = () => {
            const cancelError = new Error('User cancelled');
            (cancelError as any).code = 'USER_CANCEL';

            const mockPayment = {
                requestPayment: vi.fn().mockRejectedValue(cancelError),
            };
            (window as any).TossPayments = vi.fn().mockReturnValue({
                payment: vi.fn().mockReturnValue(mockPayment),
            });
            (window as any).TossPayments.ANONYMOUS = 'ANONYMOUS';
        };

        const callWithCancel = () => {
            setupUserCancel();

            return requestPaymentHandler({
                params: {
                    pgPaymentData: {
                        order_number: 'ORD-002',
                        order_name: 'Test Order',
                        amount: 5000,
                    },
                },
            });
        };

        it('사용자 취소 시 cancel-payment API를 호출한다', async () => {
            const consoleInfoSpy = vi.spyOn(console, 'info').mockImplementation(() => {});
            const consoleErrorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

            await callWithCancel();

            expect(mockG7Core.api.post).toHaveBeenCalledWith(
                '/modules/sirsoft-ecommerce/orders/ORD-002/cancel-payment',
                {
                    cancel_code: 'USER_CANCEL',
                    cancel_message: 'User cancelled',
                }
            );

            consoleInfoSpy.mockRestore();
            consoleErrorSpy.mockRestore();
        });

        it('사용자 취소 시 로딩 상태를 해제한다', async () => {
            const consoleInfoSpy = vi.spyOn(console, 'info').mockImplementation(() => {});
            const consoleErrorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

            await callWithCancel();

            expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({
                isSubmittingOrder: false,
            });

            consoleInfoSpy.mockRestore();
            consoleErrorSpy.mockRestore();
        });

        it('사용자 취소 시 취소 안내 모달을 연다', async () => {
            const consoleInfoSpy = vi.spyOn(console, 'info').mockImplementation(() => {});
            const consoleErrorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

            await callWithCancel();

            expect(mockG7Core.modal.open).toHaveBeenCalledWith('tosspayments_payment_cancel_modal');
            // 에러 모달은 열리지 않아야 함
            expect(mockG7Core.modal.open).not.toHaveBeenCalledWith('tosspayments_payment_error_modal');

            consoleInfoSpy.mockRestore();
            consoleErrorSpy.mockRestore();
        });

        it('cancel-payment API 실패 시에도 모달은 정상 표시한다', async () => {
            const consoleInfoSpy = vi.spyOn(console, 'info').mockImplementation(() => {});
            const consoleErrorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
            const consoleWarnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

            // cancel-payment API만 실패
            mockG7Core.api.post.mockRejectedValue(new Error('Network error'));

            await callWithCancel();

            // API 실패해도 모달과 상태 리셋은 정상 동작
            expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({
                isSubmittingOrder: false,
            });
            expect(mockG7Core.modal.open).toHaveBeenCalledWith('tosspayments_payment_cancel_modal');
            expect(consoleWarnSpy).toHaveBeenCalledWith(
                expect.stringContaining('Failed to record cancellation'),
                expect.any(Error)
            );

            consoleInfoSpy.mockRestore();
            consoleErrorSpy.mockRestore();
            consoleWarnSpy.mockRestore();
        });
    });

    // ===== SDK 파라미터 매핑 (주문서형 결제수단 → SDK method) =====
    describe('결제수단 → SDK 파라미터 매핑', () => {
        let capturedPayload: any;

        /**
         * clientConfig 로 SDK 를 스텁하고 requestPayment 인자를 캡처한다.
         */
        const setupSdk = (config: any) => {
            capturedPayload = undefined;
            mockG7Core.api.get.mockResolvedValue({ data: config });

            const mockPayment = {
                requestPayment: vi.fn().mockImplementation((payload: any) => {
                    capturedPayload = payload;
                    return Promise.resolve();
                }),
            };
            (window as any).TossPayments = vi.fn().mockReturnValue({
                payment: vi.fn().mockReturnValue(mockPayment),
            });
            (window as any).TossPayments.ANONYMOUS = 'ANONYMOUS';
        };

        const baseConfig = (overrides: any = {}) => ({
            client_key: 'ck',
            sdk_url: 'https://example.com/sdk.js',
            callback_urls: { success: '/success', fail: '/fail' },
            order_sheet_mode: true,
            enabled_methods: [
                { id: 'toss_card', method: 'CARD', easy_pay_provider: null, core_payment_method: 'card' },
                { id: 'toss_virtual_account', method: 'VIRTUAL_ACCOUNT', easy_pay_provider: null, core_payment_method: 'vbank' },
                { id: 'toss_transfer', method: 'TRANSFER', easy_pay_provider: null, core_payment_method: 'bank' },
                { id: 'toss_kakaopay', method: 'CARD', easy_pay_provider: '카카오페이', core_payment_method: 'card' },
            ],
            vbank: { valid_hours: 24, cash_receipt_type: '' },
            use_escrow: 'off',
            ...overrides,
        });

        const pgData = (overrides: any = {}) => ({
            order_number: 'ORD-100',
            order_name: '주문',
            amount: 10000,
            currency: 'KRW',
            ...overrides,
        });

        it('order_sheet_mode off 이면 CARD 통합결제창', async () => {
            setupSdk(baseConfig({ order_sheet_mode: false }));

            await requestPaymentHandler({ params: { pgPaymentData: pgData(), paymentMethod: 'toss_virtual_account' } });

            expect(capturedPayload.method).toBe('CARD');
            expect(capturedPayload.virtualAccount).toBeUndefined();
        });

        it('가상계좌 선택 시 VIRTUAL_ACCOUNT + virtualAccount 페이로드', async () => {
            setupSdk(baseConfig({ vbank: { valid_hours: 48, cash_receipt_type: '소득공제' } }));

            await requestPaymentHandler({ params: { pgPaymentData: pgData(), paymentMethod: 'toss_virtual_account' } });

            expect(capturedPayload.method).toBe('VIRTUAL_ACCOUNT');
            expect(capturedPayload.virtualAccount.validHours).toBe(48);
            expect(capturedPayload.virtualAccount.cashReceipt).toEqual({ type: '소득공제' });
        });

        it('간편결제 선택 시 CARD + easyPay provider', async () => {
            setupSdk(baseConfig());

            await requestPaymentHandler({ params: { pgPaymentData: pgData(), paymentMethod: 'toss_kakaopay' } });

            expect(capturedPayload.method).toBe('CARD');
            expect(capturedPayload.easyPay).toEqual({ provider: '카카오페이' });
        });

        it('에스크로 on 이면 가상계좌 useEscrow=true', async () => {
            setupSdk(baseConfig({ use_escrow: 'on' }));

            await requestPaymentHandler({ params: { pgPaymentData: pgData(), paymentMethod: 'toss_virtual_account' } });

            expect(capturedPayload.virtualAccount.useEscrow).toBe(true);
        });

        it('에스크로 buyer_choice 이면 useEscrow 키 부재', async () => {
            setupSdk(baseConfig({ use_escrow: 'buyer_choice' }));

            await requestPaymentHandler({ params: { pgPaymentData: pgData(), paymentMethod: 'toss_virtual_account' } });

            expect('useEscrow' in capturedPayload.virtualAccount).toBe(false);
        });

        it('가상계좌 + 에스크로 on 시 escrowProducts 부착 (E2)', async () => {
            setupSdk(baseConfig({ use_escrow: 'on' }));

            await requestPaymentHandler({
                params: {
                    pgPaymentData: pgData({
                        escrow_products: [{ id: '1', name: '상품', code: '1', unitPrice: 10000, quantity: 1 }],
                    }),
                    paymentMethod: 'toss_virtual_account',
                },
            });

            expect(capturedPayload.method).toBe('VIRTUAL_ACCOUNT');
            expect(capturedPayload.virtualAccount.useEscrow).toBe(true);
            expect(capturedPayload.escrowProducts).toHaveLength(1);
            expect(capturedPayload.escrowProducts[0].unitPrice).toBe(10000);
        });

        it('가상계좌 + 에스크로 buyer_choice 시에도 escrowProducts 부착 (E3 — 구매자가 선택할 수 있어야 하므로)', async () => {
            setupSdk(baseConfig({ use_escrow: 'buyer_choice' }));

            await requestPaymentHandler({
                params: {
                    pgPaymentData: pgData({
                        escrow_products: [{ id: '1', name: '상품', code: '1', unitPrice: 10000, quantity: 1 }],
                    }),
                    paymentMethod: 'toss_virtual_account',
                },
            });

            expect('useEscrow' in capturedPayload.virtualAccount).toBe(false);
            expect(capturedPayload.escrowProducts).toHaveLength(1);
        });

        it('가상계좌 + 에스크로 off 이면 escrowProducts 미부착', async () => {
            setupSdk(baseConfig({ use_escrow: 'off' }));

            await requestPaymentHandler({
                params: {
                    pgPaymentData: pgData({
                        escrow_products: [{ id: '1', name: '상품', code: '1', unitPrice: 10000, quantity: 1 }],
                    }),
                    paymentMethod: 'toss_virtual_account',
                },
            });

            expect(capturedPayload.virtualAccount.useEscrow).toBe(false);
            expect(capturedPayload.escrowProducts).toBeUndefined();
        });

        it('카드 결제는 에스크로 on 이어도 escrowProducts 미부착 (E1)', async () => {
            setupSdk(baseConfig({ use_escrow: 'on' }));

            await requestPaymentHandler({
                params: {
                    pgPaymentData: pgData({
                        escrow_products: [{ id: '1', name: '상품', code: '1', unitPrice: 10000, quantity: 1 }],
                    }),
                    paymentMethod: 'toss_card',
                },
            });

            expect(capturedPayload.method).toBe('CARD');
            expect(capturedPayload.escrowProducts).toBeUndefined();
        });

        it('계좌이체 + 에스크로 시 escrowProducts 부착', async () => {
            setupSdk(baseConfig({ use_escrow: 'on' }));

            await requestPaymentHandler({
                params: {
                    pgPaymentData: pgData({
                        escrow_products: [{ id: '1', name: '상품', code: '1', unitPrice: 10000, quantity: 1 }],
                    }),
                    paymentMethod: 'toss_transfer',
                },
            });

            expect(capturedPayload.method).toBe('TRANSFER');
            expect(capturedPayload.transfer.useEscrow).toBe(true);
            expect(capturedPayload.escrowProducts).toHaveLength(1);
        });

        it('카드 결제는 useEscrow 미전달 (E1)', async () => {
            setupSdk(baseConfig({ use_escrow: 'on' }));

            await requestPaymentHandler({ params: { pgPaymentData: pgData(), paymentMethod: 'toss_card' } });

            expect(capturedPayload.method).toBe('CARD');
            expect(capturedPayload.card).toBeDefined();
            expect(capturedPayload.card.useEscrow).toBeUndefined();
        });

        it('비KRW + 가상계좌 선택 시 차단 (카드만 허용)', async () => {
            mockG7Core.t = vi.fn().mockReturnValue('KRW only');
            setupSdk(baseConfig());

            await requestPaymentHandler({
                params: { pgPaymentData: pgData({ currency: 'USD' }), paymentMethod: 'toss_virtual_account' },
            });

            // 결제창 호출 안 함 (차단)
            expect(capturedPayload).toBeUndefined();
            expect(mockG7Core.modal.open).toHaveBeenCalledWith('tosspayments_payment_error_modal');
        });

        it('비KRW + 카드 선택은 허용', async () => {
            setupSdk(baseConfig());

            await requestPaymentHandler({
                params: { pgPaymentData: pgData({ currency: 'USD' }), paymentMethod: 'toss_card' },
            });

            expect(capturedPayload.method).toBe('CARD');
            expect(capturedPayload.amount.currency).toBe('USD');
        });
    });
});
