<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Models\ContentMedia;
use Modules\Sirsoft\Ecommerce\Models\EcommerceAttachment;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductCommonInfo;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Psr\Log\AbstractLogger;

/**
 * 1.3.4 업그레이드 데이터 단계 — 개발 중 전용 표의 본문 미디어를 범용 첨부 표 `ecommerce_attachments` 로 옮김 (#887)
 *
 * 저장된 본문이 해시 주소로 참조하므로 해시를 그대로 옮기고, 파일은 옮기지 않으므로 디스크·카테고리 기준 경로를 그대로 적는다(서빙이
 * 같은 파일을 읽는다). 해시 충돌·알 수 없는 소유 종류가 있으면 전용 표를 지우지 않고, 새 표가 아직 없으면 보류한다. 모듈 마이그레이션
 * (`drop_ecommerce_content_media_table`)은 빈 전용 표만 지운다.
 *
 * 표를 만들고 지우는(이름을 바꾸는) DDL 은 MySQL 에서 암묵 커밋을 일으켜 테스트 트랜잭션 밖에 남으므로, 만든 표·행·마이그레이션
 * 기록을 시험마다 finally 에서 직접 지운다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/content-media-storage-upgrade.yaml
 */
class MoveContentMediaToEcommerceAttachmentsTest extends ModuleTestCase
{
    private const LEGACY_TABLE = 'ecommerce_content_media';

    private const TARGET_TABLE = 'ecommerce_attachments';

    private const PARKED_TARGET_TABLE = 'ecommerce_attachments_parked_for_test';

    private const LEGACY_MIGRATION = '2026_11_10_000001_create_ecommerce_content_media_table';

    private object $migration;

    private UpgradeContext $context;

    /** @var array<int, string> 시험이 새 표에 둔(둘 수 있는) 해시 */
    private array $hashes = [];

    /**
     * 데이터 단계를 불러옵니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 3).'/upgrades/data/1.3.4/migrations/03_MoveContentMediaToEcommerceAttachments.php';
        $class = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_3_4\\Migrations\\MoveContentMediaToEcommerceAttachments';
        $this->migration = new $class;

        $logger = new class extends AbstractLogger
        {
            /**
             * 로그를 버립니다.
             *
             * @param  mixed  $level  수준
             * @param  string|\Stringable  $message  메시지
             * @param  array  $context  문맥
             * @return void 반환값 없음
             */
            public function log($level, string|\Stringable $message, array $context = []): void {}
        };
        Log::shouldReceive('channel')->once()->andReturn($logger);
        $this->context = new UpgradeContext(fromVersion: '1.3.4', toVersion: '1.3.4', currentStep: '1.3.4');
        Log::clearResolvedInstance('log');
    }

    /**
     * 공개 업그레이드(전용 표 없음)는 행을 건드리지 않고, 남은 옛 마이그레이션 기록만 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=no_legacy_table
     *
     * @effects public_upgrade_noop, legacy_migration_record_forgotten
     */
    public function test_noop_without_legacy_table(): void
    {
        try {
            $this->assertFalse(Schema::hasTable(self::LEGACY_TABLE));
            $before = EcommerceAttachment::withTrashed()->count();
            $this->recordLegacyMigration();

            $this->migration->run($this->context);

            $this->assertSame($before, EcommerceAttachment::withTrashed()->count());
            $this->assertFalse(DB::table('migrations')->where('migration', self::LEGACY_MIGRATION)->exists(), '파일이 없는 옛 기록은 지운다');
        } finally {
            $this->cleanup();
        }
    }

    /**
     * 행을 해시째 옮기고(이어진 상품·공통 정보, 임시, 소프트 삭제) 디스크·경로·meta·올린 사람·시각을 그대로 둔 뒤 전용 표와 마이그레이션
     * 기록을 지운다. 경로가 그대로라 범위 모델의 카테고리 기준 경로가 옛 행의 파일 위치와 같다. 다시 돌려도 그대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=legacy_rows
     *
     * @effects rows_moved_with_same_hash, file_location_kept, legacy_table_dropped, legacy_migration_record_forgotten, rerun_idempotent
     */
    public function test_moves_rows_keeping_hash_and_location_and_drops_legacy_table(): void
    {
        try {
            $this->createLegacyTable();
            $rows = [
                $this->legacyRow('product', 11, null, null, null, 'image/png'),
                $this->legacyRow('common_info', 12, null, 999999999, null, 'video/mp4'),
                $this->legacyRow('product', null, (string) Str::uuid(), 7, null, 'image/jpeg'),
                $this->legacyRow('product', 13, null, null, now()->subDay()->toDateTimeString(), 'image/png'),
            ];
            DB::table(self::LEGACY_TABLE)->insert($rows);
            $this->recordLegacyMigration();

            $this->migration->run($this->context);

            $this->assertFalse(Schema::hasTable(self::LEGACY_TABLE), '전용 표를 지운다');
            $this->assertFalse(DB::table('migrations')->where('migration', self::LEGACY_MIGRATION)->exists(), '지운 마이그레이션 기록도 지운다');

            foreach ($rows as $row) {
                $moved = ContentMedia::withTrashed()->where('hash', $row['hash'])->firstOrFail();
                $this->assertSame('content_media', $moved->collection);
                $this->assertSame($row['owner_type'] === 'product' ? Product::class : ProductCommonInfo::class, $moved->attachmentable_type);
                $this->assertSame($row['owner_id'], $moved->owner_id);
                $this->assertSame($row['temp_key'], $moved->temp_key);
                $this->assertSame($row['disk'], $moved->disk);
                $this->assertSame($row['path'], $moved->path, '경로 값은 그대로(파일을 옮기지 않음)');
                $this->assertSame($row['path'], $moved->storagePath(), '서빙이 읽는 카테고리 기준 경로가 옛 파일 위치와 같다');
                $this->assertSame($row['stored_filename'], $moved->stored_filename);
                $this->assertSame($row['mime_type'], $moved->mime_type);
                $this->assertSame($row['created_by'], $moved->created_by, '올린 사람 그대로');
                $this->assertSame($row['created_at'], $moved->created_at?->toDateTimeString());
                $this->assertSame($row['deleted_at'] !== null, $moved->trashed());
                $this->assertSame(['width' => 10, 'height' => 20], $moved->meta);
            }
            $this->assertSame('video', ContentMedia::query()->where('hash', $rows[1]['hash'])->firstOrFail()->kind, '종류는 MIME 으로 판정');

            $count = EcommerceAttachment::withTrashed()->count();
            $this->migration->run($this->context);
            $this->assertSame($count, EcommerceAttachment::withTrashed()->count(), '다시 돌려도 그대로');
        } finally {
            $this->cleanup();
        }
    }

    /**
     * 다른 행과 해시가 겹치거나 소유 종류를 알 수 없으면 그 행은 옮기지 않고 전용 표를 남긴다(행을 잃지 않게). 충돌을 걷어 내고 다시
     * 돌리면 이미 옮긴 행은 건너뛰고 나머지를 옮긴 뒤 전용 표를 지운다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=hash_conflict
     *
     * @effects conflict_keeps_legacy_table, unknown_owner_keeps_legacy_table, rerun_after_conflict_completes
     */
    public function test_conflicts_keep_legacy_table_and_rerun_completes(): void
    {
        try {
            $this->createLegacyTable();
            $ok = $this->legacyRow('product', 21, null, null, null, 'image/png');
            $clash = $this->legacyRow('product', 22, null, null, null, 'image/png');
            $unknown = $this->legacyRow('review', 23, null, null, null, 'image/png');
            DB::table(self::LEGACY_TABLE)->insert([$ok, $clash, $unknown]);
            $other = EcommerceAttachment::query()->create([
                'attachmentable_type' => Product::class, 'attachmentable_id' => 99, 'collection' => 'other', 'hash' => $clash['hash'],
                'original_filename' => 'x.png', 'stored_filename' => 'x.png', 'disk' => 'modules', 'path' => 'other/x.png',
                'mime_type' => 'image/png', 'size' => 1,
            ]);

            $this->migration->run($this->context);

            $this->assertTrue(Schema::hasTable(self::LEGACY_TABLE), '충돌이 있으면 전용 표를 남긴다');
            $this->assertSame(3, DB::table(self::LEGACY_TABLE)->count(), '전용 표의 행은 그대로');
            $this->assertNotNull(ContentMedia::withTrashed()->where('hash', $ok['hash'])->first(), '충돌 없는 행은 옮긴다');
            $this->assertNull(ContentMedia::withTrashed()->where('hash', $clash['hash'])->first(), '충돌 행은 옮기지 않는다');
            $this->assertSame('other', EcommerceAttachment::query()->findOrFail($other->id)->collection, '겹친 다른 행은 그대로');
            $this->assertNull(ContentMedia::withTrashed()->where('hash', $unknown['hash'])->first(), '알 수 없는 소유 종류는 옮기지 않는다');

            $other->forceDelete();
            DB::table(self::LEGACY_TABLE)->where('hash', $unknown['hash'])->delete();
            $this->migration->run($this->context);

            $this->assertFalse(Schema::hasTable(self::LEGACY_TABLE), '충돌을 걷어 내면 다시 돌려 마저 옮기고 지운다');
            $this->assertSame(1, ContentMedia::withTrashed()->where('hash', $ok['hash'])->count(), '이미 옮긴 행은 두 번 넣지 않는다');
            $this->assertNotNull(ContentMedia::withTrashed()->where('hash', $clash['hash'])->first());
        } finally {
            $this->cleanup();
        }
    }

    /**
     * 범용 첨부 표가 아직 없으면(모듈 마이그레이션 미적용) 옮기지 않고 전용 표를 남긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=target_missing
     *
     * @effects target_missing_defers
     */
    public function test_defers_when_target_table_is_missing(): void
    {
        try {
            $this->createLegacyTable();
            DB::table(self::LEGACY_TABLE)->insert([$this->legacyRow('product', 41, null, null, null, 'image/png')]);
            Schema::dropIfExists(self::PARKED_TARGET_TABLE);
            Schema::rename(self::TARGET_TABLE, self::PARKED_TARGET_TABLE);

            $this->migration->run($this->context);

            $this->assertTrue(Schema::hasTable(self::LEGACY_TABLE), '새 표가 없으면 전용 표를 남긴다');
            $this->assertSame(1, DB::table(self::LEGACY_TABLE)->count());
        } finally {
            if (Schema::hasTable(self::PARKED_TARGET_TABLE) && ! Schema::hasTable(self::TARGET_TABLE)) {
                Schema::rename(self::PARKED_TARGET_TABLE, self::TARGET_TABLE);
            }
            $this->cleanup();
        }
    }

    /**
     * 모듈 마이그레이션은 빈 전용 표만 지우고, 행이 있으면 업그레이드 단계가 옮기도록 남긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=module_migration
     *
     * @effects module_migration_drops_only_empty_legacy_table
     */
    public function test_module_migration_drops_only_empty_legacy_table(): void
    {
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_11_10_000002_drop_ecommerce_content_media_table.php';
        // setUp 이 Log 파사드를 대역으로 바꾸고 해석 캐시를 비웠다 — Log::shouldReceive 는 그 대역을 다시 대역으로 만들려다
        // 실패하므로 컨테이너의 대역에 직접 기대를 건다. 행이 남은 표를 남길 때 운영자 로그를 한 번 남기는 것이 의도된 동작이다
        Log::getFacadeRoot()->shouldReceive('info')
            ->once()
            ->withArgs(fn ($message, $context = []) => str_contains((string) $message, '옛 본문 미디어 전용 표에 행이 있어 남김')
                && ($context['table'] ?? null) === self::LEGACY_TABLE);

        try {
            $this->createLegacyTable();
            $row = $this->legacyRow('product', 31, null, null, null, 'image/png');
            DB::table(self::LEGACY_TABLE)->insert([$row]);

            $migration->up();
            $this->assertTrue(Schema::hasTable(self::LEGACY_TABLE), '행이 있으면 남긴다');

            DB::table(self::LEGACY_TABLE)->delete();
            $migration->up();
            $this->assertFalse(Schema::hasTable(self::LEGACY_TABLE), '비었으면 지운다');

            $migration->up();
            $this->assertFalse(Schema::hasTable(self::LEGACY_TABLE), '표가 없어도 오류 없음');
        } finally {
            $this->cleanup();
        }
    }

    /**
     * #883 의 전용 표 정의를 그대로 만듭니다.
     *
     * @return void 반환값 없음
     */
    private function createLegacyTable(): void
    {
        Schema::dropIfExists(self::LEGACY_TABLE);
        Schema::create(self::LEGACY_TABLE, function (Blueprint $table) {
            $table->id()->comment('본문 미디어 ID');
            $table->string('hash', 12)->unique()->comment('서빙 주소 해시 (12자)');
            $table->string('owner_type', 20)->comment('소유 종류 (product: 상품 설명, common_info: 상품 공통 정보)');
            $table->unsignedBigInteger('owner_id')->nullable()->comment('소유자 ID (null: 저장 전 임시)');
            $table->string('temp_key', 64)->nullable()->comment('임시 업로드 키 (저장 전 편집 화면이 발급)');
            $table->string('disk', 50)->comment('저장 디스크');
            $table->string('path', 500)->comment('저장 경로 (카테고리 기준 상대 경로)');
            $table->string('original_filename', 255)->comment('원본 파일명');
            $table->string('stored_filename', 255)->comment('저장 파일명');
            $table->string('mime_type', 100)->comment('내용으로 판정한 MIME');
            $table->unsignedBigInteger('size')->default(0)->comment('파일 크기 (바이트)');
            $table->string('kind', 10)->comment('종류 (image·video·audio)');
            $table->json('meta')->nullable()->comment('부가 정보 (이미지 가로·세로 등)');
            $table->unsignedBigInteger('created_by')->nullable()->comment('올린 사용자 ID');
            $table->timestamps();
            $table->softDeletes()->comment('삭제 시각 (본문에서 빠진 미디어)');
        });
    }

    /**
     * 전용 표 행 하나를 만듭니다.
     *
     * @param  string  $ownerType  소유 종류
     * @param  int|null  $ownerId  소유자 ID
     * @param  string|null  $tempKey  임시 키
     * @param  int|null  $createdBy  올린 사용자
     * @param  string|null  $deletedAt  소프트 삭제 시각
     * @param  string  $mime  파일 형식(MIME)
     * @return array<string, mixed> 전용 표에 넣을 행 데이터
     */
    private function legacyRow(string $ownerType, ?int $ownerId, ?string $tempKey, ?int $createdBy, ?string $deletedAt, string $mime): array
    {
        $hash = Str::random(12);
        $this->hashes[] = $hash;
        $stored = Str::uuid()->toString().'.bin';

        return [
            'hash' => $hash,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'temp_key' => $tempKey,
            'disk' => 'modules',
            'path' => $ownerType.'/2026/11/09/'.$stored,
            'original_filename' => 'o.bin',
            'stored_filename' => $stored,
            'mime_type' => $mime,
            'size' => 10,
            'kind' => 'image',
            'meta' => json_encode(['width' => 10, 'height' => 20]),
            'created_by' => $createdBy,
            'created_at' => now()->subDays(2)->startOfSecond()->toDateTimeString(),
            'updated_at' => now()->subDays(2)->startOfSecond()->toDateTimeString(),
            'deleted_at' => $deletedAt,
        ];
    }

    /**
     * 지운 전용 표 마이그레이션 기록을 재현합니다.
     *
     * @return void 반환값 없음
     */
    private function recordLegacyMigration(): void
    {
        DB::table('migrations')->insert(['migration' => self::LEGACY_MIGRATION, 'batch' => 999]);
    }

    /**
     * 암묵 커밋으로 트랜잭션 밖에 남은 것을 지웁니다.
     *
     * @return void 반환값 없음
     */
    private function cleanup(): void
    {
        Schema::dropIfExists(self::LEGACY_TABLE);
        if ($this->hashes !== [] && Schema::hasTable(self::TARGET_TABLE)) {
            DB::table(self::TARGET_TABLE)->whereIn('hash', $this->hashes)->delete();
        }
        DB::table('migrations')->where('migration', self::LEGACY_MIGRATION)->delete();
    }
}
