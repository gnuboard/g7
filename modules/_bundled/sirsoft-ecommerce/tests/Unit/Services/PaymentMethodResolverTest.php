<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\PaymentMethodResolver;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * PaymentMethodResolver 단위 테스트 — 이슈 #475
 *
 * 결제수단 능력 해석의 폴백 3단계를 검증한다:
 *
 *   카탈로그 선언 → enum(builtin) → 안전한 기본값
 *
 * 폴백이 이 순서를 지켜야 (1) 플러그인이 신규 키를 선언하지 않아도 기존 동작이 유지되고
 * (2) 선언하면 그 값이 이기며 (3) 미등록 확장 ID 도 안전한 쪽으로 판정된다.
 */
class PaymentMethodResolverTest extends ModuleTestCase
{
    protected function tearDown(): void
    {
        HookManager::resetAll();
        parent::tearDown();
    }

    private function resolver(): PaymentMethodResolver
    {
        return app(PaymentMethodResolver::class);
    }

    /**
     * 확장 결제수단을 카탈로그에 등록합니다 (PG 플러그인이 하는 것과 동일).
     *
     * @param  array<string, mixed>  $defaults  선언할 능력 키
     */
    private function registerMethod(string $id, array $defaults): void
    {
        HookManager::addFilter(
            'sirsoft-ecommerce.settings.filter_available_payment_methods',
            fn (array $methods) => array_merge($methods, [[
                'id' => $id,
                'name' => ['ko' => '확장수단', 'en' => 'Extension Method'],
                'description' => ['ko' => '', 'en' => ''],
                'icon' => 'credit-card',
                'source' => 'plugin:test',
                'defaults' => $defaults,
            ]])
        );

        // 카탈로그는 요청 내 1회 캐시되므로, 훅 등록 후 싱글톤을 새로 만들어야 한다.
        $this->app->forgetInstance(PaymentMethodResolver::class);
    }

    // ─────────────────────────────────────────────
    // needsPgProvider — 카탈로그 → enum → true
    // ─────────────────────────────────────────────

    /**
     * @scenario method_kind=builtin, capability_declared=declared, capability=needs_pg
     *
     * @effects builtin_capability_unchanged
     */
    #[Test]
    public function catalog_declaration_wins_over_enum_for_needs_pg(): void
    {
        // dbank 는 enum 상 PG 불필요(false)지만, 카탈로그가 true 로 선언하면 그것이 이긴다.
        $this->registerMethod('dbank', ['needs_pg' => true]);

        $this->assertTrue($this->resolver()->needsPgProvider('dbank'));
    }

    /**
     * @scenario method_kind=builtin, capability_declared=declared, capability=needs_pg
     *
     * @effects builtin_capability_unchanged
     */
    #[Test]
    #[DataProvider('builtinNeedsPgProvider')]
    public function builtin_falls_back_to_enum_when_catalog_does_not_declare(string $methodId, bool $expected): void
    {
        // 카탈로그에 needs_pg 선언이 없으면 enum 의 판정을 그대로 따른다 (하위호환).
        $this->assertSame($expected, $this->resolver()->needsPgProvider($methodId));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function builtinNeedsPgProvider(): array
    {
        return [
            'card 는 PG 필요' => ['card', true],
            'vbank 는 PG 필요' => ['vbank', true],
            'bank 는 PG 필요' => ['bank', true],
            'phone 은 PG 필요' => ['phone', true],
            'dbank 는 수동 입금확인 — PG 불필요' => ['dbank', false],
            'point 는 내부 처리 — PG 불필요' => ['point', false],
            'deposit 은 내부 처리 — PG 불필요' => ['deposit', false],
            'free 는 내부 처리 — PG 불필요' => ['free', false],
        ];
    }

    /**
     * @scenario method_kind=extension, capability_declared=undeclared, capability=needs_pg
     *
     * @effects pg_provider_resolved_not_none
     */
    #[Test]
    public function unregistered_extension_id_defaults_to_requiring_pg(): void
    {
        // 미등록 확장 ID 는 안전한 쪽(PG 필요)으로 판정한다.
        // false 로 오판하면 결제 실패 주문에 관리자 알림이 발송되고 TempOrder 가 삭제된다(#475).
        $this->assertTrue($this->resolver()->needsPgProvider('some_unknown_plugin_method'));
    }

    /**
     * @scenario method_kind=extension, capability_declared=declared, capability=needs_pg
     *
     * @effects pg_provider_resolved_not_none
     */
    #[Test]
    public function extension_method_declaring_needs_pg_false_is_respected(): void
    {
        // 확장이 명시적으로 PG 불필요를 선언하면 그것을 존중한다 (예: 후불 결제).
        $this->registerMethod('vendor_deferred', ['needs_pg' => false]);

        $this->assertFalse($this->resolver()->needsPgProvider('vendor_deferred'));
    }

    // ─────────────────────────────────────────────
    // refundMethod — 카탈로그 → enum match → 능력 기반
    // ─────────────────────────────────────────────

    /**
     * @scenario method_kind=builtin, capability_declared=declared, capability=refund_method
     *
     * @effects builtin_capability_unchanged
     */
    #[Test]
    #[DataProvider('builtinRefundMethodProvider')]
    public function builtin_refund_method_matches_enum_classification(string $methodId, RefundMethodEnum $expected): void
    {
        $this->assertSame($expected, $this->resolver()->refundMethod($methodId));
    }

    /**
     * @return array<string, array{string, RefundMethodEnum}>
     */
    public static function builtinRefundMethodProvider(): array
    {
        return [
            'card → PG 취소' => ['card', RefundMethodEnum::PG],
            'vbank → PG 취소' => ['vbank', RefundMethodEnum::PG],
            'bank → PG 취소' => ['bank', RefundMethodEnum::PG],
            'phone → PG 취소' => ['phone', RefundMethodEnum::PG],
            'dbank → 계좌 환불' => ['dbank', RefundMethodEnum::BANK],
            'point → 포인트 환불' => ['point', RefundMethodEnum::POINTS],
        ];
    }

    /**
     * @scenario method_kind=builtin, capability_declared=declared, capability=refund_method
     *
     * @effects builtin_capability_unchanged
     */
    #[Test]
    public function catalog_declaration_wins_over_enum_for_refund_method(): void
    {
        $this->registerMethod('card', ['refund_method' => 'bank']);

        $this->assertSame(RefundMethodEnum::BANK, $this->resolver()->refundMethod('card'));
    }

    /**
     * @scenario method_kind=extension, capability_declared=declared, capability=refund_method
     *
     * @effects refund_method_is_pg
     */
    #[Test]
    public function extension_method_declaring_pg_refund_is_respected(): void
    {
        $this->registerMethod('nhnkcp_naverpay', [
            'needs_pg' => true,
            'refund_method' => 'pg',
        ]);

        $this->assertSame(RefundMethodEnum::PG, $this->resolver()->refundMethod('nhnkcp_naverpay'));
    }

    /**
     * @scenario method_kind=extension, capability_declared=undeclared, capability=refund_method
     *
     * @effects refund_method_is_pg
     */
    #[Test]
    public function undeclared_extension_method_refund_follows_pg_capability(): void
    {
        // refund_method 를 선언하지 않은 확장수단은 PG 결제 여부로 환불수단을 정한다.
        // BANK 로 떨어지면 카드 취소가 누락되는 안전 이슈가 된다.
        $this->assertSame(
            RefundMethodEnum::PG,
            $this->resolver()->refundMethod('some_unknown_plugin_method')
        );
    }

    /**
     * @scenario method_kind=builtin, capability_declared=declared, capability=refund_method
     *
     * @effects builtin_capability_unchanged
     */
    #[Test]
    public function invalid_refund_method_declaration_falls_back_to_enum(): void
    {
        // 카탈로그에 유효하지 않은 값이 들어와도 폴백으로 안전하게 처리한다.
        $this->registerMethod('card', ['refund_method' => 'not_a_real_method']);

        $this->assertSame(RefundMethodEnum::PG, $this->resolver()->refundMethod('card'));
    }

    // ─────────────────────────────────────────────
    // label — 카탈로그 다국어 → enum label → raw id
    // ─────────────────────────────────────────────

    /**
     * @scenario method_kind=extension, capability_declared=declared, capability=label
     *
     * @effects extension_id_persisted_as_is
     */
    #[Test]
    public function extension_method_label_comes_from_catalog_in_current_locale(): void
    {
        $this->registerMethod('nhnkcp_naverpay', ['needs_pg' => true]);

        app()->setLocale('ko');
        $this->assertSame('확장수단', $this->resolver()->label('nhnkcp_naverpay'));

        app()->setLocale('en');
        $this->assertSame('Extension Method', $this->resolver()->label('nhnkcp_naverpay'));
    }

    /**
     * @scenario method_kind=builtin, capability_declared=declared, capability=label
     *
     * @effects builtin_capability_unchanged
     */
    #[Test]
    public function builtin_label_falls_back_to_enum_label(): void
    {
        // builtin 은 enum 의 다국어 라벨을 그대로 쓴다.
        $this->assertSame(
            PaymentMethodEnum::CARD->label(),
            $this->resolver()->label('card')
        );
    }

    /**
     * @scenario method_kind=extension, capability_declared=undeclared, capability=label
     *
     * @effects extension_id_persisted_as_is
     */
    #[Test]
    public function unknown_method_label_falls_back_to_raw_id(): void
    {
        // 최후 폴백: 키가 없으면 raw id (빈 문자열/예외 대신 식별 가능한 값).
        $this->assertSame('mystery_method', $this->resolver()->label('mystery_method'));
    }

    // ─────────────────────────────────────────────
    // isPgLocked / isBuiltin
    // ─────────────────────────────────────────────

    /**
     * @scenario method_kind=extension, capability_declared=declared, capability=pg_locked
     *
     * @effects admin_shows_pg_locked_badge, admin_hides_pg_select_for_locked
     */
    #[Test]
    public function extension_method_can_declare_pg_locked(): void
    {
        $this->registerMethod('nhnkcp_naverpay', [
            'pg_provider' => 'nhnkcp',
            'pg_locked' => true,
            'needs_pg' => true,
        ]);

        $this->assertTrue($this->resolver()->isPgLocked('nhnkcp_naverpay'));
    }

    /**
     * @scenario method_kind=builtin, capability_declared=declared, capability=pg_locked
     *
     * @effects admin_shows_pg_select_for_unlocked
     */
    #[Test]
    public function builtin_methods_are_not_pg_locked(): void
    {
        // builtin 은 관리자가 PG 를 자유롭게 선택할 수 있어야 한다.
        $this->assertFalse($this->resolver()->isPgLocked('card'));
        $this->assertFalse($this->resolver()->isPgLocked('vbank'));
    }

    /**
     * @scenario method_kind=extension, capability_declared=undeclared, capability=pg_locked
     *
     * @effects admin_shows_pg_select_for_unlocked
     */
    #[Test]
    public function is_builtin_distinguishes_core_methods_from_extension_ids(): void
    {
        $this->assertTrue($this->resolver()->isBuiltin('card'));
        $this->assertTrue($this->resolver()->isBuiltin('dbank'));
        $this->assertFalse($this->resolver()->isBuiltin('nhnkcp_naverpay'));
    }

    // ─────────────────────────────────────────────
    // allValidIds — 검증 화이트리스트
    // ─────────────────────────────────────────────

    #[Test]
    public function all_valid_ids_contains_every_builtin_method(): void
    {
        $ids = $this->resolver()->allValidIds();

        foreach (PaymentMethodEnum::values() as $builtin) {
            $this->assertContains($builtin, $ids);
        }
    }

    /**
     * @scenario method_kind=extension, capability_declared=declared, capability=needs_pg
     *
     * @effects extension_id_passes_validation
     */
    #[Test]
    public function all_valid_ids_includes_registered_extension_methods(): void
    {
        $this->registerMethod('nhnkcp_naverpay', ['needs_pg' => true]);

        $ids = $this->resolver()->allValidIds();

        $this->assertContains('nhnkcp_naverpay', $ids);
        $this->assertContains('card', $ids, 'builtin 이 확장 등록으로 밀려나면 안 된다');
    }

    /**
     * @scenario method_kind=extension, capability_declared=undeclared, capability=needs_pg
     *
     * @effects unregistered_id_still_rejected
     */
    #[Test]
    public function all_valid_ids_excludes_unregistered_ids(): void
    {
        // 화이트리스트가 넓어지되 임의 문자열은 여전히 배제해야 한다 (검증 우회 방지).
        $this->assertNotContains('totally_unknown_method', $this->resolver()->allValidIds());
    }

    #[Test]
    public function all_valid_ids_has_no_duplicates(): void
    {
        // 확장이 builtin id 를 덮어쓰는 형태로 등록해도 중복이 생기지 않아야 한다.
        $this->registerMethod('card', ['needs_pg' => true]);

        $ids = $this->resolver()->allValidIds();

        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    // ─────────────────────────────────────────────
    // 기존 설치 환경 자가 치유 — saved 설정이 정의를 이기지 못한다
    // ─────────────────────────────────────────────

    /**
     * @scenario method_kind=extension, capability_declared=declared, capability=pg_locked
     *
     * @effects saved_null_pg_provider_self_healed
     */
    #[Test]
    public function saved_null_pg_provider_does_not_override_pg_locked_declaration(): void
    {
        // 기존 설치 환경의 order_settings.json 에는 확장 결제수단이 이미
        // `pg_provider: null` 로 영속화되어 있다(#475 결함 시절의 값).
        //
        // mergePaymentMethodSettings() 가 saved 우선이면 플러그인이 defaults 를 고쳐도
        // 기존 설정 파일이 이겨서 결함이 그대로 남는다. 능력 선언(pg_locked/needs_pg/
        // refund_method)과 PG 고정 수단의 pg_provider 는 정의가 SSoT 여야 한다.
        $this->registerMethod('nhnkcp_naverpay', [
            'pg_provider' => 'nhnkcp',
            'pg_locked' => true,
            'needs_pg' => true,
            'refund_method' => 'pg',
        ]);

        $settings = app(EcommerceSettingsService::class);

        // 결함 시절의 saved 상태를 재현 — pg_provider 가 null 로 저장되어 있다.
        $settings->saveSettings([
            'order_settings' => [
                'payment_methods' => [
                    ['id' => 'nhnkcp_naverpay', 'pg_provider' => null, 'is_active' => true],
                ],
            ],
        ]);

        // 싱글톤 캐시를 비우고 재조회 (저장 후 읽기 경로)
        $this->app->forgetInstance(PaymentMethodResolver::class);

        $config = $settings->getPaymentMethodConfig('nhnkcp_naverpay');

        $this->assertSame('nhnkcp', $config['pg_provider'] ?? null, 'PG 고정 수단은 saved null 을 무시하고 정의의 PG 를 강제해야 한다');
        $this->assertTrue((bool) ($config['needs_pg'] ?? false));
        $this->assertTrue($this->resolver()->needsPgProvider('nhnkcp_naverpay'));
    }

    // ─────────────────────────────────────────────
    // 환불 자동 승인 목록 (P16·P17)
    // ─────────────────────────────────────────────

    /**
     * 기본 목록은 `card · point · free` 이고, 사람이 송금해야 하는 수단은 밖이다.
     *
     * @scenario gate_case=default_allow_list
     *
     * @effects auto_approve_default_list_is_card_point_free
     */
    #[Test]
    public function the_default_allow_list_covers_only_the_methods_that_settle_themselves(): void
    {
        $this->assertTrue($this->resolver()->autoApprovesRefund('card'));
        $this->assertTrue($this->resolver()->autoApprovesRefund('point'));
        $this->assertTrue($this->resolver()->autoApprovesRefund('free'));

        foreach (['dbank', 'vbank', 'bank', 'phone', 'deposit'] as $stopped) {
            $this->assertFalse(
                $this->resolver()->autoApprovesRefund($stopped),
                "{$stopped} 는 사람이 송금해야 끝나므로 자동 승인 대상이 아니다 (P16)",
            );
        }
    }

    /**
     * 플러그인이 `auto_approve_refund` 를 선언한 수단은 **운영자가 아직 결정하지 않았다면**
     * 목록에 자동 합류한다. (P17)
     *
     * 합류가 없으면 PG 플러그인을 새로 설치한 순간부터 그 수단의 환불이 전부 승인 대기로 쌓이고,
     * 운영자는 어디를 고쳐야 하는지 알 수 없다 — 설정에 없는 수단이라 목록에 보이지도 않는다.
     *
     * @scenario gate_case=plugin_declared_method_joins
     *
     * @effects plugin_declared_method_joins_until_operator_decides
     */
    #[Test]
    public function a_plugin_declared_method_joins_the_allow_list_until_the_operator_decides(): void
    {
        $this->registerMethod('testpg_kakaopay', [
            'needs_pg' => true,
            'refund_method' => 'pg',
            'auto_approve_refund' => true,
        ]);

        $this->assertTrue(
            $this->resolver()->autoApprovesRefund('testpg_kakaopay'),
            '선언 수단은 운영자 결정 이력이 없으면 자동 합류한다',
        );
    }

    /**
     * 선언하지 않은 확장 수단은 합류하지 않는다 — 미선언의 기본값은 false 다. (P17)
     *
     * 오판의 비용이 비대칭이다: 자동으로 잘못 완료하면 이중 환불이고, 멈추면 운영자가 한 번 더
     * 누르면 된다. `needs_pg` 가 미등록 확장 수단에 true 로 기우는 것과 반대 방향이다.
     *
     * @scenario gate_case=plugin_undeclared_method_stops
     *
     * @effects undeclared_extension_method_does_not_join
     */
    #[Test]
    public function an_undeclared_extension_method_does_not_join_the_allow_list(): void
    {
        $this->registerMethod('testpg_transfer', [
            'needs_pg' => true,
            'refund_method' => 'pg',
        ]);

        $this->assertFalse($this->resolver()->autoApprovesRefund('testpg_transfer'));
    }

    /**
     * 운영자가 결정한 뒤에는 **저장값이 선언을 이긴다**. (P17 — 결정 이력)
     *
     * 이력이 없으면 운영자가 일부러 뺀 수단이 다음 조회의 자동 합류로 되살아난다. 그때 운영자는
     * 자기가 뺀 수단이 왜 계속 돌아오는지 알 방법이 없다 — 화면에는 체크가 풀린 채 보이는데
     * 실제 게이트는 열려 있기 때문이다.
     *
     * @scenario gate_case=operator_removed_declared_method
     *
     * @effects operator_decision_history_beats_plugin_declaration
     */
    #[Test]
    public function the_operator_decision_history_beats_the_plugin_declaration(): void
    {
        $this->registerMethod('testpg_kakaopay', [
            'needs_pg' => true,
            'refund_method' => 'pg',
            'auto_approve_refund' => true,
        ]);

        // 운영자가 목록에서 뺀 채 저장했다 — 결정 이력에는 그 수단이 남는다.
        app(EcommerceSettingsService::class)->saveSettings([
            'claim' => [
                'auto_approve_refund_payment_methods' => ['card', 'point', 'free'],
                'auto_approve_refund_payment_methods_decided' => ['card', 'point', 'free', 'testpg_kakaopay'],
            ],
        ]);
        $this->app->forgetInstance(PaymentMethodResolver::class);

        $this->assertFalse(
            $this->resolver()->autoApprovesRefund('testpg_kakaopay'),
            '운영자가 뺀 수단은 선언이 있어도 다시 합류하지 않는다',
        );
        $this->assertTrue(
            $this->resolver()->autoApprovesRefund('card'),
            '같은 저장에서 남긴 수단은 그대로 자동 승인 대상이다',
        );
    }
}
