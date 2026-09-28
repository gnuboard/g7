<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Validate;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimShippingFeeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Tests\Support\RecordingSchemaBuilder;

/**
 * 이커머스 마이그레이션 결함 수정 검증 — DB 전용 raw SQL · 재실행 가드 · 주석 값 목록 (#599 validate P4)
 *
 * 재실행 가드는 "앞선 실행이 중간에 멈춘 상태" 에서만 드러나는데, 그 상태를 공유 테스트 DB 에
 * 만들면 DDL 이 암묵 커밋되어 테이블이 망가진다. 그래서 스키마 호출을 {@see RecordingSchemaBuilder}
 * 로 바꿔 끼워 **실제 DDL 없이** 무엇을 내려 했는지만 본다. raw SQL 검사는 소스를 읽는다 — 수정 전
 * 코드의 `up()` 을 실제로 돌리면 그 raw ALTER 가 테스트 DB 에 나가므로, 소스 단언을 먼저 둔다.
 */
class ValidateP4MigrationsTest extends ModuleTestCase
{
    /** 마이그레이션 디렉터리 (모듈 루트 기준). */
    private const DIR = 'database/migrations/';

    /** #599 가 만든 클레임 테이블 생성 마이그레이션 7종 — 파일명 → 테이블명. */
    private const CLAIM_CREATES = [
        '2026_08_20_000001_create_ecommerce_order_exchanges_table.php' => 'ecommerce_order_exchanges',
        '2026_08_20_000002_create_ecommerce_order_exchange_options_table.php' => 'ecommerce_order_exchange_options',
        '2026_08_20_000003_create_ecommerce_order_returns_table.php' => 'ecommerce_order_returns',
        '2026_08_20_000004_create_ecommerce_order_return_options_table.php' => 'ecommerce_order_return_options',
        '2026_08_20_000005_create_ecommerce_claim_images_table.php' => 'ecommerce_claim_images',
        '2026_08_20_000006_create_ecommerce_claim_payments_table.php' => 'ecommerce_claim_payments',
        '2026_08_20_000007_create_ecommerce_shipping_policy_claim_addresses_table.php' => 'ecommerce_shipping_policy_claim_addresses',
    ];

    /** 기존 테이블 확장 마이그레이션. */
    private const CLAIM_COLUMNS = '2026_08_20_000008_add_claim_columns_to_existing_tables.php';

    /** 첨부 업로더 주체 마이그레이션. */
    private const UPLOADER_TYPE = '2026_09_14_000001_add_uploader_type_to_ecommerce_claim_images_table.php';

    /** 환불 옵션 상태 인덱스·주석 정정 마이그레이션. */
    private const REFUND_OPTION_STATUS = '2026_09_14_000002_add_option_status_index_to_ecommerce_order_refund_options_table.php';

    /** 배송완료 시각 마이그레이션 (공개 1.2.x 에 포함). */
    private const DELIVERED_AT = '2026_06_11_000002_add_delivered_at_to_ecommerce_order_options_table.php';

    /** 확장 마이그레이션이 바꾸는 `order_cancel_id` 주석. */
    private const ORDER_CANCEL_ID_COMMENT = '취소 FK (deprecated — refund_source_type/refund_source_id 로 대체, 취소 발 환불에 이중 기록)';

    /**
     * 모듈 마이그레이션 파일의 절대 경로를 돌려줍니다.
     *
     * @param  string  $file  파일명
     * @return string 절대 경로
     */
    private function migrationPath(string $file): string
    {
        return $this->getModuleBasePath().'/'.self::DIR.$file;
    }

    /**
     * 마이그레이션 소스에서 주석을 걷어 낸 코드만 돌려줍니다 (주석 속 설명 문구에 속지 않게).
     *
     * @param  string  $file  파일명
     * @return string 주석을 뺀 소스
     */
    private function sourceWithoutComments(string $file): string
    {
        $tokens = token_get_all((string) file_get_contents($this->migrationPath($file)));

        return implode('', array_map(
            fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($token) ? $token[1] : $token),
            $tokens,
        ));
    }

    /**
     * 확장 마이그레이션이 다 적용된 뒤의 가상 스키마 상태를 돌려줍니다.
     *
     * @param  string|null  $orderCancelIdComment  `order_cancel_id` 의 현재 주석
     * @return array<string, array<string, mixed>> 가상 스키마 상태
     */
    private function appliedClaimColumnsState(?string $orderCancelIdComment): array
    {
        return [
            'ecommerce_order_refunds' => [
                'columns' => [
                    'order_cancel_id', 'refund_method', 'refunded_at', 'created_at',
                    'refund_source_type', 'refund_source_id', 'reason_code', 'reason_detail', 'fault_type',
                    'requested_by', 'approved_at', 'on_hold_at', 'withdrawn_at', 'reject_reason',
                ],
                'indexes' => [
                    'ecommerce_order_refunds_source_index',
                    'ecommerce_order_refunds_requested_by_index',
                    'ecommerce_order_refunds_created_at_index',
                ],
                'foreign_keys' => ['fk_rf_requested_by'],
                'comments' => ['order_cancel_id' => $orderCancelIdComment],
            ],
            'ecommerce_order_cancels' => [
                'columns' => ['created_at'],
                'indexes' => ['ecommerce_order_cancels_created_at_index'],
            ],
            'ecommerce_shipping_policy_country_settings' => [
                'columns' => ['extra_fee_multiply', 'claim_fee_mode', 'return_fixed_fee', 'exchange_fixed_fee'],
            ],
            'ecommerce_mileage_transactions' => [
                'columns' => ['order_cancel_id', 'claim_payment_id', 'order_refund_id'],
                'indexes' => ['ecommerce_mileage_trx_claim_payment_index', 'ecommerce_mileage_trx_order_refund_index'],
                'foreign_keys' => ['fk_mt_claim_payment_id', 'fk_mt_order_refund_id'],
            ],
        ];
    }

    /**
     * 첨부 업로더 백필이 MySQL 전용 `UPDATE ... JOIN` raw SQL 을 쓰지 않는다.
     *
     * 수정 전 실패 이유: `backfillUploaderType()` 이 `DB::statement("UPDATE ... JOIN ...")` 로
     * 신청자를 대조해 MySQL 밖의 DB 에서는 구문 오류가 난다.
     *
     * @scenario defect=db_specific_sql
     *
     * @effects uploader_backfill_uses_no_db_specific_sql
     *
     * @return void 반환값 없음
     */
    public function test_uploader_backfill_uses_no_db_specific_sql(): void
    {
        $source = $this->sourceWithoutComments(self::UPLOADER_TYPE);

        $this->assertStringNotContainsString('DB::statement', $source, '업로더 백필이 raw SQL 을 실행합니다');
        $this->assertDoesNotMatchRegularExpression('/\bJOIN\b/i', $source, '업로더 백필이 UPDATE ... JOIN 을 씁니다');
    }

    /**
     * 쿼리 빌더로 옮긴 백필이 종전 규칙 그대로 고객·운영자·미상을 가른다.
     *
     * 수정 전에도 통과한다 — raw SQL 을 쿼리 빌더로 옮기면서 판정이 바뀌지 않았음을 잠그는
     * 회귀 가드다(신청자 = 업로더 → customer, 신청자 ≠ 업로더·비회원 신청서 → admin, 귀속 신청서 없음 → unknown).
     *
     * @scenario defect=db_specific_sql
     *
     * @effects uploader_backfill_splits_customer_and_admin_by_requester
     *
     * @return void 반환값 없음
     */
    public function test_uploader_backfill_splits_customer_and_admin_by_requester(): void
    {
        $customer = User::factory()->create();
        $operator = User::factory()->create();

        $return = OrderReturn::factory()->create(['requested_by' => $customer->id]);
        $guestExchange = OrderExchange::factory()->create(['requested_by' => null]);

        $byRequester = ClaimImage::factory()->forReturn($return)->create(['created_by' => $customer->id]);
        $byOperator = ClaimImage::factory()->forReturn($return)->create(['created_by' => $operator->id]);
        $onGuestClaim = ClaimImage::factory()->forExchange($guestExchange)->create(['created_by' => $operator->id]);
        $guestUpload = ClaimImage::factory()->forReturn($return)->create(['created_by' => null]);
        $pending = ClaimImage::factory()->create(['claimable_type' => 'pending', 'claimable_id' => 0, 'created_by' => $operator->id]);
        $orphan = ClaimImage::factory()->forReturn(2147483000)->create(['created_by' => $operator->id]);

        (require $this->migrationPath(self::UPLOADER_TYPE))->up();

        $this->assertSame('customer', ClaimImage::query()->toBase()->where('id', $byRequester->id)->value('uploader_type'));
        $this->assertSame('admin', ClaimImage::query()->toBase()->where('id', $byOperator->id)->value('uploader_type'));
        $this->assertSame('admin', ClaimImage::query()->toBase()->where('id', $onGuestClaim->id)->value('uploader_type'));
        $this->assertSame('customer', ClaimImage::query()->toBase()->where('id', $guestUpload->id)->value('uploader_type'));
        $this->assertSame('customer', ClaimImage::query()->toBase()->where('id', $pending->id)->value('uploader_type'));
        $this->assertSame('unknown', ClaimImage::query()->toBase()->where('id', $orphan->id)->value('uploader_type'));
    }

    /**
     * 기존 테이블 확장 마이그레이션이 `order_cancel_id` 주석을 raw `ALTER ... MODIFY` 가 아니라
     * 스키마 빌더 `->change()` 로 바꾸고, 이미 그 주석이면 다시 돌려도 DDL 을 내지 않는다.
     *
     * 수정 전 실패 이유: `up()`·`down()` 이 MySQL 에서만 `DB::statement('ALTER TABLE ... MODIFY ...')`
     * 를 실행해 소스 단언에서 실패한다(이 단언이 먼저라 수정 전 코드의 raw ALTER 는 실행되지 않는다).
     *
     * @scenario defect=db_specific_sql
     *
     * @effects order_cancel_id_comment_changes_through_schema_builder, claim_column_extension_rerun_emits_no_ddl
     *
     * @return void 반환값 없음
     */
    public function test_claim_column_extension_changes_comment_through_schema_builder(): void
    {
        $this->assertStringNotContainsString(
            'DB::statement',
            $this->sourceWithoutComments(self::CLAIM_COLUMNS),
            '확장 마이그레이션이 raw ALTER 를 실행합니다',
        );

        $schema = new RecordingSchemaBuilder($this->appliedClaimColumnsState('취소 FK (추후 polymorphic 전환 가능)'));
        $operations = $schema->run($this->app, $this->migrationPath(self::CLAIM_COLUMNS));

        $this->assertCount(1, $operations, '주석 변경 한 건만 나가야 합니다');
        $this->assertSame('ecommerce_order_refunds', $operations[0]['table']);
        $this->assertSame([['column' => 'order_cancel_id', 'comment' => self::ORDER_CANCEL_ID_COMMENT]], $operations[0]['changed']);
        $this->assertSame([], $operations[0]['added']);

        // 두 번째 실행 — 주석이 이미 목표값이라 아무 DDL 도 내지 않는다.
        $schema->resetOperations();
        $this->assertSame([], $schema->run($this->app, $this->migrationPath(self::CLAIM_COLUMNS)));
    }

    /**
     * 클레임 생성 마이그레이션 7종이 테이블 주석을 raw `ALTER TABLE ... COMMENT` 가 아니라
     * `$table->comment()` 로 단다.
     *
     * 수정 전 실패 이유: 7종 모두 `DB::statement('ALTER TABLE ... COMMENT ...')` 를 MySQL 에서만 실행했다.
     *
     * @scenario defect=db_specific_sql
     *
     * @effects claim_create_migrations_set_table_comment_through_schema_builder
     *
     * @return void 반환값 없음
     */
    public function test_claim_create_migrations_set_table_comment_through_schema_builder(): void
    {
        foreach (self::CLAIM_CREATES as $file => $table) {
            $this->assertStringNotContainsString('DB::statement', $this->sourceWithoutComments($file), "{$file} 가 raw SQL 을 실행합니다");

            $operations = (new RecordingSchemaBuilder([]))->run($this->app, $this->migrationPath($file));

            $this->assertSame('create', $operations[0]['operation'] ?? null, "{$file} 가 테이블을 만들지 않았습니다");
            $this->assertSame($table, $operations[0]['table']);
            $this->assertContains(
                'tableComment',
                array_column($operations[0]['commands'], 'name'),
                "{$file} 가 스키마 빌더로 테이블 주석을 달지 않습니다",
            );
        }
    }

    /**
     * 클레임 생성 마이그레이션 7종은 테이블이 이미 있으면 아무것도 만들지 않는다.
     *
     * 수정 전 실패 이유: `Schema::hasTable` 가드가 없어 이미 테이블이 있는 설치본(부분 실패 뒤 재실행 등)
     * 에서 `Schema::create` 가 "Base table already exists" 로 실패한다.
     *
     * @scenario defect=rerun_guard
     *
     * @effects claim_create_migrations_skip_existing_tables
     *
     * @return void 반환값 없음
     */
    public function test_claim_create_migrations_skip_existing_tables(): void
    {
        foreach (self::CLAIM_CREATES as $file => $table) {
            $schema = new RecordingSchemaBuilder([$table => ['columns' => ['id']]]);

            $this->assertSame([], $schema->run($this->app, $this->migrationPath($file)), "{$file} 가 기존 테이블 위에서 DDL 을 냈습니다");
        }
    }

    /**
     * 배송완료 시각 마이그레이션은 컬럼만 남기고 멈춘 설치본에서 다시 돌면 인덱스를 마저 만든다.
     *
     * 수정 전 실패 이유: `up()` 이 `hasColumn` 이면 통째로 return 해, 컬럼 추가 뒤 인덱스 단계에서
     * 멈춘 설치본에는 인덱스가 영영 생기지 않는다.
     *
     * @scenario defect=rerun_guard
     *
     * @effects delivered_at_rerun_completes_the_missing_index
     *
     * @return void 반환값 없음
     */
    public function test_delivered_at_rerun_completes_the_missing_index(): void
    {
        $schema = new RecordingSchemaBuilder([
            'ecommerce_order_options' => ['columns' => ['confirmed_at', 'delivered_at']],
        ]);

        $operations = $schema->run($this->app, $this->migrationPath(self::DELIVERED_AT));

        $this->assertCount(1, $operations, '빠진 인덱스 한 건만 만들어야 합니다');
        $this->assertSame([], $operations[0]['added'], '이미 있는 컬럼을 다시 추가하려 했습니다');
        $this->assertSame(
            [['name' => 'index', 'index' => 'ecommerce_order_options_delivered_at_index']],
            $operations[0]['commands'],
        );

        $schema->resetOperations();
        $this->assertSame([], $schema->run($this->app, $this->migrationPath(self::DELIVERED_AT)), '두 번째 실행이 DDL 을 냈습니다');
    }

    /**
     * #599 마이그레이션의 상태(문자열 enum)·불리언 컬럼 주석이 값 목록을 **값: 설명** 으로 모두 적는다.
     *
     * 수정 전 실패 이유: `exchange_status` 가 "14종: requested ~ withdrawn" 처럼 범위만 적거나,
     * `charge_method`·`option_status` 가 Enum 이름만 적거나, 불리언 `is_stock_restored` 가 1/0 의미를
     * 적지 않아 컬럼 주석만 보고는 값의 뜻을 알 수 없었다.
     *
     * @scenario defect=comment_values
     *
     * @effects claim_status_and_boolean_comments_list_every_value
     *
     * @return void 반환값 없음
     */
    public function test_claim_status_and_boolean_comments_list_every_value(): void
    {
        $values = fn (array $cases): array => array_map(fn ($case) => $case->value, $cases);
        $claimTypes = ['exchange', 'return'];
        $boolean = ['1', '0'];

        $expectations = [
            '2026_08_20_000001_create_ecommerce_order_exchanges_table.php' => ['ecommerce_order_exchanges', [
                'exchange_status' => $values(ExchangeStatusEnum::cases()),
                'fault_type' => $values(ClaimReasonFaultTypeEnum::cases()),
                'charge_method' => $values(ClaimChargeMethodEnum::cases()),
                'shipping_fee_mode' => $values(ClaimShippingFeeModeEnum::cases()),
                'is_stock_restored' => $boolean,
            ]],
            '2026_08_20_000002_create_ecommerce_order_exchange_options_table.php' => ['ecommerce_order_exchange_options', [
                'option_status' => $values(ExchangeStatusEnum::cases()),
                'original_option_status' => $values(OrderStatusEnum::cases()),
                'is_new_stock_deducted' => $boolean,
            ]],
            '2026_08_20_000003_create_ecommerce_order_returns_table.php' => ['ecommerce_order_returns', [
                'return_status' => $values(ReturnStatusEnum::cases()),
                'fault_type' => $values(ClaimReasonFaultTypeEnum::cases()),
                'charge_method' => $values(ClaimChargeMethodEnum::cases()),
                'shipping_fee_mode' => $values(ClaimShippingFeeModeEnum::cases()),
                'is_stock_restored' => $boolean,
            ]],
            '2026_08_20_000004_create_ecommerce_order_return_options_table.php' => ['ecommerce_order_return_options', [
                'option_status' => $values(ReturnStatusEnum::cases()),
                'original_option_status' => $values(OrderStatusEnum::cases()),
            ]],
            '2026_08_20_000005_create_ecommerce_claim_images_table.php' => ['ecommerce_claim_images', [
                'claimable_type' => ['exchange', 'return', 'refund', 'pending'],
            ]],
            '2026_08_20_000006_create_ecommerce_claim_payments_table.php' => ['ecommerce_claim_payments', [
                'payable_type' => $claimTypes,
                'payment_method' => $values(PaymentMethodEnum::cases()),
                'payment_status' => $values(ClaimPaymentStatusEnum::cases()),
            ]],
            '2026_08_20_000007_create_ecommerce_shipping_policy_claim_addresses_table.php' => ['ecommerce_shipping_policy_claim_addresses', [
                'claim_type' => $claimTypes,
            ]],
        ];

        foreach ($expectations as $file => [$table, $columns]) {
            $schema = new RecordingSchemaBuilder([]);
            $schema->run($this->app, $this->migrationPath($file));

            $this->assertCommentsListValues($file, $schema->getColumns($table), $columns);
        }

        // 기존 테이블 확장 — 컬럼이 아직 없는 설치본에서 추가되는 주석을 본다.
        $schema = new RecordingSchemaBuilder([
            'ecommerce_order_refunds' => ['columns' => ['order_cancel_id', 'refund_method', 'refunded_at', 'created_at'], 'comments' => ['order_cancel_id' => self::ORDER_CANCEL_ID_COMMENT]],
            'ecommerce_shipping_policy_country_settings' => ['columns' => ['extra_fee_multiply']],
            'ecommerce_mileage_transactions' => ['columns' => ['order_cancel_id']],
        ]);
        $schema->run($this->app, $this->migrationPath(self::CLAIM_COLUMNS));

        $this->assertCommentsListValues(self::CLAIM_COLUMNS, $schema->getColumns('ecommerce_order_refunds'), [
            'refund_source_type' => $values(RefundSourceTypeEnum::cases()),
            'fault_type' => $values(ClaimReasonFaultTypeEnum::cases()),
        ]);
        $this->assertCommentsListValues(self::CLAIM_COLUMNS, $schema->getColumns('ecommerce_shipping_policy_country_settings'), [
            'claim_fee_mode' => $values(ClaimShippingFeeModeEnum::cases()),
        ]);

        // 환불 옵션 상태 주석 정정 — `->change()` 로 바뀌는 주석을 본다.
        $schema = new RecordingSchemaBuilder([
            'ecommerce_order_refund_options' => [
                'columns' => ['option_status'],
                'indexes' => ['ecommerce_order_refund_opts_status_index'],
            ],
        ]);
        $schema->run($this->app, $this->migrationPath(self::REFUND_OPTION_STATUS));

        $this->assertCommentsListValues(self::REFUND_OPTION_STATUS, $schema->getColumns('ecommerce_order_refund_options'), [
            'option_status' => $values(RefundStatusEnum::cases()),
        ]);
    }

    /**
     * 컬럼 주석이 기대 값마다 `값: 설명` 을 담는지 단언합니다.
     *
     * @param  string  $file  마이그레이션 파일명 (실패 메시지용)
     * @param  array<int, array<string, mixed>>  $columns  기록기의 컬럼 목록
     * @param  array<string, array<int, string>>  $expected  컬럼명 → 기대 값 목록
     * @return void 반환값 없음
     */
    private function assertCommentsListValues(string $file, array $columns, array $expected): void
    {
        $comments = array_column($columns, 'comment', 'name');

        foreach ($expected as $column => $valueList) {
            $comment = (string) ($comments[$column] ?? '');
            $this->assertNotSame('', $comment, "{$file} 의 {$column} 주석이 없습니다");

            foreach ($valueList as $value) {
                $this->assertMatchesRegularExpression(
                    '/(^|[\s(—,\/])'.preg_quote((string) $value, '/').': \S/u',
                    $comment,
                    "{$file} 의 {$column} 주석에 `{$value}: 설명` 이 없습니다 — {$comment}",
                );
            }
        }
    }
}
