<?php

namespace Modules\Sirsoft\Board\Upgrades;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use App\Models\NotificationDefinition;
use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Board\Database\Seeders\BoardNotificationDefinitionSeeder;

/**
 * Board 모듈 1.0.0-beta.2 업그레이드 스텝
 *
 * - STEP 1: board_mail_templates → notification_definitions + notification_templates 이관
 * - STEP 2: 파티션 제거 검증
 * - STEP 3: 인덱스 추가 검증
 * - STEP 4: 카운팅 컬럼 동기화 및 검증
 * - STEP 5: database 채널 알림 템플릿 보강
 */
class Upgrade_1_0_0_beta_2 implements UpgradeStepInterface
{
    /**
     * 파티션 제거 검증 대상 테이블 목록.
     *
     * @var string[]
     */
    private const PARTITION_TABLES = [
        'board_posts',
        'board_comments',
        'board_attachments',
    ];

    /**
     * STEP 3 인덱스 검증 대상 목록.
     *
     * 2026_04_17_000004_update_indexes_in_board_tables 마이그레이션 기준.
     *
     * @var array<string, string[]>
     */
    private const EXPECTED_INDEXES = [
        'board_posts' => [
            'idx_board_posts_board_author',
            'idx_board_posts_board_status_created',
            'idx_board_posts_board_parent',
            'idx_board_posts_board_category',
            'idx_board_posts_board_view_count',
            'idx_board_posts_user_activity',
            'idx_board_posts_user_created',
            'idx_board_posts_user_board_stats',
            'idx_board_posts_list_count',
            'idx_board_posts_adjacent',
            'idx_board_posts_user_status',
            'ft_board_posts_title_content',
        ],
        'board_comments' => [
            'idx_board_comments_board_parent',
            'idx_board_comments_post_created',
            'idx_board_comments_user_board',
            'idx_board_comments_post_deleted_created',
            'idx_board_comments_user_status',
        ],
        'board_attachments' => [
            'idx_board_attachments_post_id',
        ],
    ];

    /**
     * STEP 4 카운팅 컬럼 검증 대상.
     *
     * @var array<string, string[]>
     */
    private const COUNT_COLUMNS = [
        'boards' => ['posts_count', 'comments_count'],
        'board_posts' => ['replies_count', 'comments_count', 'attachments_count'],
        'board_comments' => ['replies_count'],
    ];

    /**
     * 업그레이드 스텝을 실행합니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     * @return void
     */
    public function run(UpgradeContext $context): void
    {
        // STEP 1: 알림 정의 이관
        $this->migrateNotifications($context);

        // STEP 2: 파티션 제거 검증
        $this->verifyPartitionsRemoved($context);

        // STEP 3: 인덱스 추가 검증
        $this->verifyIndexesAdded($context);

        // STEP 4: 카운팅 컬럼 동기화 및 검증
        $this->syncCountColumns($context);
        $this->verifyCountColumns($context);

        // STEP 5: database 채널 알림 템플릿 보강
        $this->ensureDatabaseChannelTemplates($context);
    }

    // -------------------------------------------------------------------------
    // STEP 1: 알림 정의 이관
    // -------------------------------------------------------------------------

    /**
     * board_mail_templates → notification_definitions + notification_templates 이관합니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     * @return void
     */
    private function migrateNotifications(UpgradeContext $context): void
    {
        if (! Schema::hasTable('notification_definitions')) {
            $context->logger->warning('[board-beta.2] notification_definitions 테이블 미존재 — 코어 업그레이드 먼저 실행 필요');

            return;
        }

        // 게시판 알림 정의 시딩 (7종)
        $context->logger->info('[board-beta.2] 게시판 알림 정의 시딩...');
        (new BoardNotificationDefinitionSeeder())->run();

        // board_mail_templates → notification_templates 데이터 이관
        if (Schema::hasTable('board_mail_templates')) {
            $context->logger->info('[board-beta.2] 게시판 메일 템플릿 이관 시작...');
            $this->migrateBoardMailTemplates($context);
        }

        // 기존 캐시 무효화
        $this->invalidateCaches();
        $context->logger->info('[board-beta.2] 게시판 메일 템플릿 캐시 무효화 완료');

        $context->logger->info('[board-beta.2] 게시판 알림 정의 이관 완료');
    }

    /**
     * board_mail_templates → notification_templates 데이터를 이관합니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     * @return void
     */
    private function migrateBoardMailTemplates(UpgradeContext $context): void
    {
        $templates = DB::table('board_mail_templates')->get();
        $migratedCount = 0;

        foreach ($templates as $template) {
            $definition = NotificationDefinition::where('type', $template->type)->first();
            if (! $definition) {
                $context->logger->warning("[board-beta.2] 알림 정의 미발견 — type: {$template->type}, 이관 스킵");

                continue;
            }

            // board_mail_templates.variables → notification_definitions.variables fallback 매핑.
            // 시더가 채운 값이 있으면 보존, 비어있는 경우에만 운영 데이터로 보강.
            if (isset($template->variables) && $template->variables) {
                $existingVariables = $definition->variables;
                $isEmpty = empty($existingVariables) || $existingVariables === '[]' || $existingVariables === [];
                if ($isEmpty) {
                    $definition->variables = json_decode($template->variables, true) ?? [];
                    $definition->save();
                }
            }

            NotificationTemplate::updateOrCreate(
                ['definition_id' => $definition->id, 'channel' => 'mail'],
                [
                    'subject' => json_decode($template->subject, true) ?? [],
                    'body' => json_decode($template->body, true) ?? [],
                    'is_active' => $template->is_active,
                    'is_default' => $template->is_default,
                    'user_overrides' => isset($template->user_overrides) && $template->user_overrides ? json_decode($template->user_overrides, true) : null,
                    'updated_by' => $template->updated_by ?? null,
                ]
            );

            $migratedCount++;
        }

        $context->logger->info("[board-beta.2] 게시판 메일 템플릿 {$migratedCount}건 이관 완료");
    }

    /**
     * 기존 Board 메일 템플릿 캐시를 무효화합니다.
     *
     * @return void
     */
    private function invalidateCaches(): void
    {
        $cache = app(CacheInterface::class);
        $cachePrefix = 'mail_template:sirsoft-board:';
        $types = ['new_comment', 'reply_comment', 'post_reply', 'post_action', 'new_post_admin', 'report_received_admin', 'report_action'];

        foreach ($types as $type) {
            $cache->forget($cachePrefix . $type);
        }
    }

    // -------------------------------------------------------------------------
    // STEP 2: 파티션 제거 검증
    // -------------------------------------------------------------------------

    /**
     * 파티션이 제거되었는지 검증합니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     * @return void
     */
    private function verifyPartitionsRemoved(UpgradeContext $context): void
    {
        $totalExpected = count(self::PARTITION_TABLES);
        $totalVerified = 0;

        foreach (self::PARTITION_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $context->logger->warning("[v1.0.0-beta.2] {$table} 테이블이 존재하지 않습니다.");
                continue;
            }

            $partition = DB::selectOne(
                "SELECT PARTITION_NAME FROM INFORMATION_SCHEMA.PARTITIONS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND PARTITION_NAME IS NOT NULL
                 LIMIT 1",
                [$context->table($table)]
            );

            if ($partition !== null) {
                $context->logger->warning(
                    "[v1.0.0-beta.2] {$table} 테이블에 파티션이 아직 남아 있습니다. "
                    . '마이그레이션(remove_partitions_from_board_tables)을 실행하세요.'
                );
            } else {
                $totalVerified++;
                $context->logger->info("[v1.0.0-beta.2] {$table} 테이블 파티션 제거 확인됨.");
            }
        }

        $context->logger->info(
            "[v1.0.0-beta.2] 파티션 제거 검증 완료: {$totalVerified}/{$totalExpected}개 테이블 확인됨."
        );
    }

    // -------------------------------------------------------------------------
    // STEP 3: 인덱스 추가 검증
    // -------------------------------------------------------------------------

    /**
     * 인덱스가 추가되었는지 검증합니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     * @return void
     */
    private function verifyIndexesAdded(UpgradeContext $context): void
    {
        foreach (self::EXPECTED_INDEXES as $table => $expectedIndexes) {
            if (! Schema::hasTable($table)) {
                $context->logger->warning("[v1.0.0-beta.2] {$table} 테이블이 존재하지 않습니다.");
                continue;
            }

            $existingIndexes = array_column(Schema::getIndexes($table), 'name');

            foreach ($expectedIndexes as $index) {
                if (in_array($index, $existingIndexes)) {
                    $context->logger->info("[v1.0.0-beta.2] {$table} 인덱스 확인됨: {$index}");
                } else {
                    $context->logger->warning(
                        "[v1.0.0-beta.2] {$table} 인덱스 누락: {$index}. "
                        . '마이그레이션(update_indexes_in_board_tables)을 실행하세요.'
                    );
                }
            }
        }

        $context->logger->info('[v1.0.0-beta.2] 인덱스 추가 검증 완료.');
    }

    // -------------------------------------------------------------------------
    // STEP 4: 카운팅 컬럼 동기화 및 검증
    // -------------------------------------------------------------------------

    /**
     * 카운팅 컬럼의 기존 데이터를 JOIN UPDATE로 일괄 동기화합니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     * @return void
     */
    private function syncCountColumns(UpgradeContext $context): void
    {
        $prefix = DB::getTablePrefix();

        $this->syncBoardPostsCount($context, $prefix);
        $this->syncBoardCommentsCount($context, $prefix);
        $this->syncPostRepliesCount($context, $prefix);
        $this->syncPostCommentsCount($context, $prefix);
        $this->syncPostAttachmentsCount($context, $prefix);
        $this->syncCommentRepliesCount($context, $prefix);
    }

    /**
     * boards.posts_count 동기화 (JOIN UPDATE)
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     * @param string $prefix DB 테이블 프리픽스
     * @return void
     */
    private function syncBoardPostsCount(UpgradeContext $context, string $prefix): void
    {
        if (! Schema::hasColumn('boards', 'posts_count')) {
            $context->logger->warning('[v1.0.0-beta.2] boards.posts_count 컬럼이 없습니다.');

            return;
        }

        $affected = DB::affectingStatement("
            UPDATE {$prefix}boards b
            LEFT JOIN (
                SELECT board_id, COUNT(*) AS cnt
                FROM {$prefix}board_posts
                WHERE deleted_at IS NULL
                GROUP BY board_id
            ) p ON p.board_id = b.id
            SET b.posts_count = COALESCE(p.cnt, 0)
        ");

        $context->logger->info("[v1.0.0-beta.2] boards.posts_count 동기화 완료: {$affected}건 갱신.");
    }

    /**
     * boards.comments_count 동기화 (JOIN UPDATE)
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     * @param string $prefix DB 테이블 프리픽스
     * @return void
     */
    private function syncBoardCommentsCount(UpgradeContext $context, string $prefix): void
    {
        if (! Schema::hasColumn('boards', 'comments_count')) {
            $context->logger->warning('[v1.0.0-beta.2] boards.comments_count 컬럼이 없습니다.');

            return;
        }

        $affected = DB::affectingStatement("
            UPDATE {$prefix}boards b
            LEFT JOIN (
                SELECT board_id, COUNT(*) AS cnt
                FROM {$prefix}board_comments
                WHERE deleted_at IS NULL
                GROUP BY board_id
            ) c ON c.board_id = b.id
            SET b.comments_count = COALESCE(c.cnt, 0)
        ");

        $context->logger->info("[v1.0.0-beta.2] boards.comments_count 동기화 완료: {$affected}건 갱신.");
    }

    /**
     * board_posts.replies_count 동기화 (JOIN UPDATE)
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     * @param string $prefix DB 테이블 프리픽스
     * @return void
     */
    private function syncPostRepliesCount(UpgradeContext $context, string $prefix): void
    {
        if (! Schema::hasColumn('board_posts', 'replies_count')) {
            $context->logger->warning('[v1.0.0-beta.2] board_posts.replies_count 컬럼이 없습니다.');

            return;
        }

        $affected = DB::affectingStatement("
            UPDATE {$prefix}board_posts p
            LEFT JOIN (
                SELECT parent_id, COUNT(*) AS cnt
                FROM {$prefix}board_posts
                WHERE parent_id IS NOT NULL AND deleted_at IS NULL
                GROUP BY parent_id
            ) r ON r.parent_id = p.id
            SET p.replies_count = COALESCE(r.cnt, 0)
        ");

        $context->logger->info("[v1.0.0-beta.2] board_posts.replies_count 동기화 완료: {$affected}건 갱신.");
    }

    /**
     * board_posts.comments_count 동기화 (JOIN UPDATE)
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     * @param string $prefix DB 테이블 프리픽스
     * @return void
     */
    private function syncPostCommentsCount(UpgradeContext $context, string $prefix): void
    {
        if (! Schema::hasColumn('board_posts', 'comments_count')) {
            $context->logger->warning('[v1.0.0-beta.2] board_posts.comments_count 컬럼이 없습니다.');

            return;
        }

        $affected = DB::affectingStatement("
            UPDATE {$prefix}board_posts p
            LEFT JOIN (
                SELECT post_id, COUNT(*) AS cnt
                FROM {$prefix}board_comments
                WHERE deleted_at IS NULL
                GROUP BY post_id
            ) c ON c.post_id = p.id
            SET p.comments_count = COALESCE(c.cnt, 0)
        ");

        $context->logger->info("[v1.0.0-beta.2] board_posts.comments_count 동기화 완료: {$affected}건 갱신.");
    }

    /**
     * board_posts.attachments_count 동기화 (JOIN UPDATE)
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     * @param string $prefix DB 테이블 프리픽스
     * @return void
     */
    private function syncPostAttachmentsCount(UpgradeContext $context, string $prefix): void
    {
        if (! Schema::hasColumn('board_posts', 'attachments_count')) {
            $context->logger->warning('[v1.0.0-beta.2] board_posts.attachments_count 컬럼이 없습니다.');

            return;
        }

        $affected = DB::affectingStatement("
            UPDATE {$prefix}board_posts p
            LEFT JOIN (
                SELECT post_id, COUNT(*) AS cnt
                FROM {$prefix}board_attachments
                WHERE deleted_at IS NULL
                GROUP BY post_id
            ) a ON a.post_id = p.id
            SET p.attachments_count = COALESCE(a.cnt, 0)
        ");

        $context->logger->info("[v1.0.0-beta.2] board_posts.attachments_count 동기화 완료: {$affected}건 갱신.");
    }

    /**
     * board_comments.replies_count 동기화 (JOIN UPDATE)
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     * @param string $prefix DB 테이블 프리픽스
     * @return void
     */
    private function syncCommentRepliesCount(UpgradeContext $context, string $prefix): void
    {
        if (! Schema::hasColumn('board_comments', 'replies_count')) {
            $context->logger->warning('[v1.0.0-beta.2] board_comments.replies_count 컬럼이 없습니다.');

            return;
        }

        $affected = DB::affectingStatement("
            UPDATE {$prefix}board_comments c
            LEFT JOIN (
                SELECT parent_id, COUNT(*) AS cnt
                FROM {$prefix}board_comments
                WHERE parent_id IS NOT NULL AND deleted_at IS NULL
                GROUP BY parent_id
            ) r ON r.parent_id = c.id
            SET c.replies_count = COALESCE(r.cnt, 0)
        ");

        $context->logger->info("[v1.0.0-beta.2] board_comments.replies_count 동기화 완료: {$affected}건 갱신.");
    }

    /**
     * 카운팅 컬럼이 존재하는지 검증합니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     * @return void
     */
    private function verifyCountColumns(UpgradeContext $context): void
    {
        $totalExpected = 0;
        $totalVerified = 0;

        foreach (self::COUNT_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $context->logger->warning("[v1.0.0-beta.2] {$table} 테이블이 존재하지 않습니다.");

                continue;
            }

            $existingColumns = Schema::getColumnListing($table);

            foreach ($columns as $column) {
                $totalExpected++;

                if (in_array($column, $existingColumns)) {
                    $totalVerified++;
                    $context->logger->info("[v1.0.0-beta.2] {$table}.{$column} 확인됨.");
                } else {
                    $context->logger->warning(
                        "[v1.0.0-beta.2] {$table}.{$column} 누락. "
                        . '마이그레이션(add_count_columns_to_board_tables)을 실행하세요.'
                    );
                }
            }
        }

        $context->logger->info("[v1.0.0-beta.2] 카운팅 컬럼 검증 완료: {$totalVerified}/{$totalExpected}개 확인됨.");
    }

    // -------------------------------------------------------------------------
    // STEP 5: database 채널 알림 템플릿 보강
    // -------------------------------------------------------------------------

    /**
     * 이전 빌드에서 mail 채널만 시딩된 알림 정의에 database 채널 템플릿을 보강합니다.
     *
     * 시더가 updateOrCreate 패턴이므로 이미 존재하는 경우 no-op입니다.
     * 멱등성 보장 목적으로 존재 여부를 명시적으로 확인합니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     * @return void
     */
    private function ensureDatabaseChannelTemplates(UpgradeContext $context): void
    {
        if (! Schema::hasTable('notification_definitions')) {
            $context->logger->warning('[board-beta.2] notification_definitions 테이블 미존재 — STEP 5 스킵');

            return;
        }

        $context->logger->info('[board-beta.2] database 채널 알림 템플릿 보강 시작...');

        $types = ['new_comment', 'reply_comment', 'post_reply', 'post_action', 'new_post_admin', 'report_received_admin', 'report_action'];
        $created = 0;

        $seeder = new BoardNotificationDefinitionSeeder();
        $definitionsMap = collect($seeder->getDefaultDefinitions())->keyBy('type');

        foreach ($types as $type) {
            $definition = NotificationDefinition::where('type', $type)->first();
            if (! $definition) {
                continue;
            }

            $hasDbTemplate = NotificationTemplate::where('definition_id', $definition->id)
                ->where('channel', 'database')
                ->exists();

            if ($hasDbTemplate) {
                continue;
            }

            // 시더 정의에서 database 템플릿 데이터 가져오기
            $defData = $definitionsMap->get($type);
            $dbTemplateData = collect($defData['templates'] ?? [])->firstWhere('channel', 'database');

            if (! $dbTemplateData) {
                continue;
            }

            NotificationTemplate::create([
                'definition_id' => $definition->id,
                'channel' => 'database',
                'subject' => $dbTemplateData['subject'],
                'body' => $dbTemplateData['body'],
                'click_url' => $dbTemplateData['click_url'] ?? null,
                'recipients' => $dbTemplateData['recipients'] ?? null,
                'is_active' => true,
                'is_default' => true,
            ]);

            $created++;
        }

        if ($created > 0) {
            $context->logger->info("[board-beta.2] database 채널 템플릿 보강: {$created}건");
        }

        $context->logger->info('[board-beta.2] database 채널 알림 템플릿 보강 완료');
    }
}
