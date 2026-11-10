<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Psr\Log\AbstractLogger;

/**
 * 1.3.4 업그레이드 데이터 단계 — 후기 표의 이관 후기 컬럼 보정 회귀 테스트
 *
 * 마이그레이션이 이미 적용된 설치본에서 다시 돌아도 DDL 없이 「이미 반영됨」 으로 끝나야 한다(재실행 멱등).
 * DDL 분기는 MySQL 암묵 커밋이 테스트 트랜잭션을 깨므로 여기서 돌리지 않는다.
 */
class EnsureImportedReviewColumnsTest extends ModuleTestCase
{
    /** @var array<int, array{0: string, 1: string, 2: array}> 기록된 로그 */
    private array $records = [];

    private object $migration;

    private UpgradeContext $context;

    /**
     * 데이터 단계를 불러오고 로그를 모으는 컨텍스트를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 3)
            .'/upgrades/data/1.3.4/migrations/01_EnsureImportedReviewColumns.php';

        $class = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_3_4\\Migrations\\EnsureImportedReviewColumns';
        $this->migration = new $class;

        $records = &$this->records;
        $logger = new class($records) extends AbstractLogger
        {
            /**
             * 로그 버퍼를 참조로 받습니다.
             *
             * @param  array  $records  기록 버퍼 (참조)
             */
            public function __construct(private array &$records) {}

            /**
             * 로그 한 줄을 버퍼에 쌓습니다.
             *
             * @param  mixed  $level  수준
             * @param  string|\Stringable  $message  메시지
             * @param  array  $context  문맥
             * @return void 반환값 없음
             */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };

        Log::shouldReceive('channel')->once()->andReturn($logger);
        $this->context = new UpgradeContext(fromVersion: '1.3.3', toVersion: '1.3.4', currentStep: '1.3.4');
        Log::clearResolvedInstance('log');
    }

    /**
     * 이미 반영된 스키마에서는 아무것도 바꾸지 않고 분기 로그를 남깁니다.
     *
     * @effects imported_review_upgrade_step_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_already_applied_schema_is_left_alone_with_log(): void
    {
        $this->assertSame('EnsureImportedReviewColumns', $this->migration->name());

        $this->migration->run($this->context);
        $this->migration->run($this->context);

        $this->assertTrue(Schema::hasColumn('ecommerce_product_reviews', 'author_name'));

        $messages = array_column($this->records, 1);
        $this->assertNotEmpty($messages, '분기마다 로그를 남겨야 합니다.');
        foreach ($messages as $message) {
            $this->assertStringContainsString('[ecommerce:1.3.4]', $message);
        }
        $this->assertTrue(
            collect($messages)->contains(fn (string $m) => str_contains($m, '이미')),
            '이미 반영된 스키마라는 로그가 없습니다: '.implode(' / ', $messages)
        );
    }
}
