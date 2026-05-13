/**
 * requestPayment 핸들러 테스트
 *
 * 토스페이먼츠 결제창 호출 핸들러의 에러 처리 및 모달 열기 동작을 검증합니다.
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
});
