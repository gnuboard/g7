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
import { readFileSync } from 'fs';
import { resolve } from 'path';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { assertTrustedSdkUrl, KNOWN_SDK_HOSTS, requestPaymentHandler, resolveSelectedMethodId } from '../../handlers/requestPayment';

const CLIENT_CONFIG_DATA = {
    data: {
        client_key: 'test_key',
        sdk_url: 'https://example.com/sdk.js',
        callback_urls: { success: 'https://shop.example.test/success', fail: 'https://shop.example.test/fail' },
    },
};

describe('requestPaymentHandler', () => {
    let mockG7Core: {
        api: { get: ReturnType<typeof vi.fn>; post: ReturnType<typeof vi.fn> };
        state: { setLocal: ReturnType<typeof vi.fn>; get: ReturnType<typeof vi.fn> };
        modal: { open: ReturnType<typeof vi.fn> };
    };

    beforeEach(() => {
        mockG7Core = {
            api: {
                get: vi.fn().mockResolvedValue(CLIENT_CONFIG_DATA),
                post: vi.fn().mockResolvedValue({ success: true }),
            },
            state: { setLocal: vi.fn(), get: vi.fn().mockReturnValue({}) },
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
                },
                undefined
            );

            consoleInfoSpy.mockRestore();
            consoleErrorSpy.mockRestore();
        });

        /**
         * 클레임 추가결제의 취소는 주문 취소 경로를 타면 안 된다. (S3-D8)
         *
         * 두 경우의 `order_number` 가 가리키는 것이 다르다 — 주문은 주문번호지만 클레임은
         * **결제번호(CP…)** 다. 종전에는 구분 없이 `orders/{order_number}/cancel-payment`
         * 를 불러 `orders/CP…/cancel-payment` 가 되었고, 서버는 404 를 돌려주는데 catch 가
         * console.warn 으로 삼켜 **화면에는 아무 신호도 남지 않았다**(실측
         * EX20260915-1145077892 · CP20260915-1145087969).
         */
        it('클레임 추가결제 취소는 주문 취소 API 를 부르지 않는다', async () => {
            const consoleInfoSpy = vi.spyOn(console, 'info').mockImplementation(() => {});
            const consoleErrorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

            setupUserCancel();
            await requestPaymentHandler({
                params: {
                    pgPaymentData: {
                        order_number: 'CP20260915-1145087969',
                        order_name: '교환 추가결제 (EX-1)',
                        amount: 6000,
                        claim_payment_number: 'CP20260915-1145087969',
                        claim_cancel_return_url: '/mypage/claims/EX-1?claim_payment=cancelled&claim_number=EX-1',
                    },
                },
            });

            expect(mockG7Core.api.post).not.toHaveBeenCalled();

            consoleInfoSpy.mockRestore();
            consoleErrorSpy.mockRestore();
        });

        /**
         * 대신 서버가 실어 준 복귀 주소로 돌아간다. (S3-D8)
         *
         * 의도된 중단은 실패가 아니므로 `?claim_payment=cancelled` 이고, 그 주소는
         * `ClaimPaymentRedirect` 가 만든다 — 프런트가 조립하지 않는다. 체크아웃 전용
         * 취소 모달도 띄우지 않는다(신청서 화면에는 그 모달이 없다).
         */
        it('클레임 추가결제 취소는 서버가 준 복귀 주소로 이동한다', async () => {
            const consoleInfoSpy = vi.spyOn(console, 'info').mockImplementation(() => {});
            const consoleErrorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

            const returnUrl = '/mypage/claims/EX-1?claim_payment=cancelled&claim_number=EX-1';
            const original = window.location;
            delete (window as any).location;
            (window as any).location = { ...original, href: 'https://g7.dev/mypage' };

            setupUserCancel();
            await requestPaymentHandler({
                params: {
                    pgPaymentData: {
                        order_number: 'CP20260915-1145087969',
                        order_name: '교환 추가결제 (EX-1)',
                        amount: 6000,
                        claim_payment_number: 'CP20260915-1145087969',
                        claim_cancel_return_url: returnUrl,
                    },
                },
            });

            expect(window.location.href).toBe(returnUrl);
            expect(mockG7Core.modal.open).not.toHaveBeenCalledWith('tosspayments_payment_cancel_modal');

            (window as any).location = original;
            consoleInfoSpy.mockRestore();
            consoleErrorSpy.mockRestore();
        });

        it('비회원 컨텍스트에서는 취소 기록에 X-Guest-Order-Token 헤더를 첨부한다', async () => {
            // 이 엔드포인트는 회원/비회원 공유라 서버가 소유권을 대조한다. 토큰이 빠지면
            // 서버가 404 로 거부하는데 화면은 console.warn 만 남기고 평소대로 취소 안내를
            // 띄우므로, 이력이 유실된 사실이 어디에도 드러나지 않는다.
            const consoleInfoSpy = vi.spyOn(console, 'info').mockImplementation(() => {});
            const consoleErrorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
            mockG7Core.state.get.mockReturnValue({ guestOrderToken: 'guest-token-abc' });

            await callWithCancel();

            expect(mockG7Core.api.post).toHaveBeenCalledWith(
                '/modules/sirsoft-ecommerce/orders/ORD-002/cancel-payment',
                expect.anything(),
                { headers: { 'X-Guest-Order-Token': 'guest-token-abc' } }
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
            callback_urls: { success: 'https://shop.example.test/success', fail: 'https://shop.example.test/fail' },
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

        it('간편결제 선택 시 CARD + card.easyPay 자체창 직행 (flowMode DIRECT)', async () => {
            setupSdk(baseConfig());

            await requestPaymentHandler({ params: { pgPaymentData: pgData(), paymentMethod: 'toss_kakaopay' } });

            // 토스 v2 결제창 계약: 간편결제 자체창은 card 객체 안의 easyPay + flowMode DIRECT.
            // top-level easyPay 키는 SDK 가 읽지 않아 통합결제창이 열린다 (회귀 pin).
            expect(capturedPayload.method).toBe('CARD');
            expect(capturedPayload.card.flowMode).toBe('DIRECT');
            expect(capturedPayload.card.easyPay).toBe('카카오페이');
            expect(capturedPayload.easyPay).toBeUndefined();
        });

        it('일반 카드 선택 시 flowMode DEFAULT + easyPay 부재 (통합결제창)', async () => {
            setupSdk(baseConfig());

            await requestPaymentHandler({ params: { pgPaymentData: pgData(), paymentMethod: 'toss_card' } });

            expect(capturedPayload.method).toBe('CARD');
            expect(capturedPayload.card.flowMode).toBe('DEFAULT');
            expect('easyPay' in capturedPayload.card).toBe(false);
            expect(capturedPayload.easyPay).toBeUndefined();
        });

        it('코어 결제수단 id(vbank)로도 VIRTUAL_ACCOUNT 로 해석한다 (S1-D1)', async () => {
            // 코어 카탈로그의 가상계좌(id='vbank')를 고른 주문은 _local.paymentMethod 에
            // toss_* 가 아니라 코어 id 가 담긴다. enabled_methods 의 core_payment_method 로도
            // 찾지 않으면 CARD 로 강하해 가상계좌 주문에 카드 결제창이 열린다.
            setupSdk(baseConfig());

            await requestPaymentHandler({ params: { pgPaymentData: pgData(), paymentMethod: 'vbank' } });

            expect(capturedPayload.method).toBe('VIRTUAL_ACCOUNT');
            expect(capturedPayload.virtualAccount).toBeDefined();
        });

        it('코어 결제수단 id(card)로도 CARD 로 해석한다 (S1-D1)', async () => {
            setupSdk(baseConfig());

            await requestPaymentHandler({ params: { pgPaymentData: pgData(), paymentMethod: 'card' } });

            expect(capturedPayload.method).toBe('CARD');
            expect(capturedPayload.card.flowMode).toBe('DEFAULT');
        });

        it('toss_* id 가 코어 id 보다 우선한다 (간편결제 자체창 보존 · S1-D1)', async () => {
            // toss_kakaopay 의 core 는 card 다. 코어 매칭을 먼저 하면 easyPay 가 사라진다.
            setupSdk(baseConfig());

            await requestPaymentHandler({ params: { pgPaymentData: pgData(), paymentMethod: 'toss_kakaopay' } });

            expect(capturedPayload.card.easyPay).toBe('카카오페이');
            expect(capturedPayload.card.flowMode).toBe('DIRECT');
        });

        it('면세 금액이 있으면 승인 요청에 taxFreeAmount 를 싣는다 (S1-D12)', async () => {
            // 복합 과세 상점은 결제·결제취소에 면세 금액을 **함께** 보내야 한다(토스 「세금 처리」).
            // 승인에서 빼면 PG 가 전액 과세로 등록해 두고, 면세분이 실린 취소를
            // 「취소 할 수 없는 금액 입니다」 로 거절한다 — 면세 상품이 섞인 주문의 부분환불이 막힌다.
            setupSdk(baseConfig());

            await requestPaymentHandler({
                params: {
                    pgPaymentData: pgData({ tax_free_amount: 40000 }),
                    paymentMethod: 'toss_card',
                },
            });

            expect(capturedPayload.taxFreeAmount).toBe(40000);
        });

        it('면세 금액이 0/없으면 taxFreeAmount 키를 싣지 않는다 (S1-D12)', async () => {
            // 과세 전용 상점에서 0 을 보내면 불필요한 계약 분기를 탄다.
            setupSdk(baseConfig());

            await requestPaymentHandler({
                params: { pgPaymentData: pgData({ tax_free_amount: 0 }), paymentMethod: 'toss_card' },
            });
            expect('taxFreeAmount' in capturedPayload).toBe(false);

            await requestPaymentHandler({
                params: { pgPaymentData: pgData(), paymentMethod: 'toss_card' },
            });
            expect('taxFreeAmount' in capturedPayload).toBe(false);
        });

        it('가상계좌도 면세 금액을 싣는다 (S1-D12)', async () => {
            setupSdk(baseConfig());

            await requestPaymentHandler({
                params: {
                    pgPaymentData: pgData({ tax_free_amount: 15000 }),
                    paymentMethod: 'toss_virtual_account',
                },
            });

            expect(capturedPayload.method).toBe('VIRTUAL_ACCOUNT');
            expect(capturedPayload.taxFreeAmount).toBe(15000);
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

/**
 * SDK 주입 출처 게이트 (동적 스크립트 주입 정책).
 *
 * PG SDK 는 서비스 SDK 라 자체 호스팅할 수 없다. 그래서 **주입 직전에** 호스트를
 * 확인하는 것이 유일한 게이트다. 확장자 없는 SDK URL 은 정적 검사에도 걸리지 않는다.
 *
 * manifest `trusted_script_hosts` 와 코드 상수가 어긋나면 그 차집합이 그대로
 * 사각이 되므로 두 목록의 일치도 함께 고정한다.
 */
/**
 * 결제수단 id 결정 순서 — 액션 인자, 서버가 저장한 payment_method. 화면 상태는 보지 않는다.
 *
 * 화면 상태는 주문서가 아닌 화면(클레임 추가결제 등)에서 비거나 서버와 다른 값일 수 있다 — 서버가 저장한 값이 단일 출처다.
 */
describe('resolveSelectedMethodId', () => {
    it('액션 인자가 있으면 그 값을 쓴다', () => {
        expect(resolveSelectedMethodId('toss_kakaopay', 'vbank')).toBe('toss_kakaopay');
    });

    it('액션 인자가 없으면 서버가 저장한 결제수단을 쓴다', () => {
        expect(resolveSelectedMethodId(undefined, 'vbank')).toBe('vbank');
    });

    it('둘 다 없으면 정하지 않는다(화면 상태로 채우지 않는다)', () => {
        expect(resolveSelectedMethodId(undefined, null)).toBeUndefined();
    });
});

describe('SDK URL 신뢰 호스트 게이트', () => {
    const TRUSTED_SDK_URL = 'https://js.tosspayments.com/v2/standard';

    it('신뢰 호스트 https URL 은 통과한다', () => {
        expect(() => assertTrustedSdkUrl(TRUSTED_SDK_URL)).not.toThrow();
    });

    it.each([
        ['미신뢰 호스트', 'https://cdn.evil.com/sdk.js'],
        ['http (평문)', TRUSTED_SDK_URL.replace('https://', 'http://')],
        ['protocol-relative', TRUSTED_SDK_URL.replace('https://', '//')],
        ['상대 경로', '/local/sdk.js'],
        ['빈 문자열', ''],
        ['javascript 스킴', 'javascript:alert(1)'],
        ['신뢰 호스트를 userinfo 로 위장', 'https://evil.com/@' + new URL(TRUSTED_SDK_URL).hostname + '/x.js'],
        ['신뢰 호스트를 서브도메인 접미로 위장', 'https://' + new URL(TRUSTED_SDK_URL).hostname + '.evil.com/x.js'],
    ])('%s 는 거부된다', (_label, url) => {
        expect(() => assertTrustedSdkUrl(url as string)).toThrow();
    });

    it('manifest trusted_script_hosts 와 KNOWN_SDK_HOSTS 가 일치한다', () => {
        const manifest = JSON.parse(
            readFileSync(resolve(__dirname, '../../../../plugin.json'), 'utf-8')
        );

        expect([...(manifest.trusted_script_hosts ?? [])].sort()).toEqual(
            [...KNOWN_SDK_HOSTS].sort()
        );
    });

    it('manifest 가 호스트별 사유를 함께 선언한다', () => {
        const manifest = JSON.parse(
            readFileSync(resolve(__dirname, '../../../../plugin.json'), 'utf-8')
        );

        for (const host of KNOWN_SDK_HOSTS) {
            expect(typeof manifest.trusted_script_hosts_reason?.[host]).toBe('string');
            expect(manifest.trusted_script_hosts_reason[host].length).toBeGreaterThan(0);
        }
    });
});
