<?php

namespace Modules\Sirsoft\Board\Database\Seeders\Sample;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\ReportReasonType;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Enums\ReportType;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Models\ReportLog;

/**
 * 신고 샘플 시더
 *
 * 1케이스 구조: boards_reports(케이스 1행) + boards_report_logs(신고자 기록 N행)
 * 다양한 상태와 사유의 신고 케이스 데이터를 생성합니다.
 */
class ReportSampleSeeder extends Seeder
{
    /**
     * 신고 상세 사유 템플릿
     */
    private const REASON_DETAILS = [
        ReportReasonType::Abuse->value => '욕설과 비방이 포함된 게시글입니다. 다른 사용자를 모욕하는 내용이 있습니다.',
        ReportReasonType::HateSpeech->value => '특정 집단에 대한 혐오 발언이 포함되어 있습니다.',
        ReportReasonType::Spam->value => '광고성 스팸 게시글입니다. 동일한 내용이 반복적으로 게시되고 있습니다.',
        ReportReasonType::Copyright->value => '저작권이 있는 이미지/콘텐츠를 무단으로 사용하고 있습니다.',
        ReportReasonType::Privacy->value => '개인정보(전화번호, 주소 등)가 동의 없이 노출되어 있습니다.',
        ReportReasonType::Misinformation->value => '사실과 다른 허위 정보를 유포하고 있습니다.',
        ReportReasonType::Sexual->value => '성적인 콘텐츠가 포함되어 있습니다.',
        ReportReasonType::Violence->value => '폭력적인 내용이 포함된 게시글입니다.',
        ReportReasonType::Other->value => '기타 사유로 신고합니다. 커뮤니티 가이드라인 위반으로 보입니다.',
    ];

    /**
     * 처리 메모 템플릿
     */
    private const PROCESS_NOTES = [
        ReportStatus::Review->value => '신고 내용을 검토 중입니다.',
        ReportStatus::Rejected->value => '신고 내용을 검토한 결과, 커뮤니티 가이드라인 위반에 해당하지 않습니다.',
        ReportStatus::Suspended->value => '신고 내용 확인 결과 규정 위반으로 판단되어 해당 콘텐츠를 숨김 처리하였습니다.',
    ];

    /**
     * 시더 실행
     */
    public function run(): void
    {
        $this->command->info('신고 샘플 데이터 생성 중...');

        // 기존 데이터 삭제 (개발 단계 — truncate 금지, delete 사용)
        ReportLog::query()->delete();
        Report::withTrashed()->forceDelete();

        // 신고 기능이 활성화된 게시판 조회
        $boards = Board::where('use_report', true)->get();

        if ($boards->isEmpty()) {
            $this->command->warn('신고 기능이 활성화된 게시판이 없습니다.');

            return;
        }

        // 테스트 사용자 조회
        $users = User::whereIn('email', [
            'gildong@example.com',
            'chulsoo@example.com',
            'younghee@example.com',
        ])->get();

        if ($users->count() < 3) {
            $users = User::limit(5)->get();
        }

        if ($users->isEmpty()) {
            $this->command->error('사용자가 없습니다. UserSampleSeeder를 먼저 실행하세요.');

            return;
        }

        // 관리자 조회 (처리자용)
        $admin = User::whereHas('roles', function ($query) {
            $query->where('identifier', 'admin');
        })->first();

        $caseCount = 0;

        // 각 상태별로 케이스 생성 (1케이스 = boards_reports 1행 + boards_report_logs N행)
        foreach (ReportStatus::cases() as $status) {
            // 각 상태별로 3~4건 생성
            $countForStatus = rand(3, 4);

            for ($i = 0; $i < $countForStatus; $i++) {
                $board = $boards->random();
                $targetType = ReportType::cases()[array_rand(ReportType::cases())];

                // 게시글/댓글 ID 조회
                $targetId = $this->getRandomTargetId($board, $targetType);

                if (! $targetId) {
                    continue;
                }

                // 이미 케이스가 있는 대상인지 확인 (UNIQUE 제약)
                $exists = Report::withTrashed()
                    ->where('board_id', $board->id)
                    ->where('target_type', $targetType)
                    ->where('target_id', $targetId)
                    ->exists();

                if ($exists) {
                    continue;
                }

                // 스냅샷 데이터 생성
                $snapshot = $this->getSnapshotData($board, $targetType, $targetId);
                $createdAt = now()->subDays(rand(1, 30));
                $lastReportedAt = $createdAt;
                $reporterUser = $users->random();

                // boards_reports 케이스 생성
                $caseData = [
                    'board_id' => $board->id,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'author_id' => $snapshot['author_id'],
                    'status' => $status,
                    'last_reported_at' => $lastReportedAt,
                    'last_activated_at' => null,
                    'metadata' => [
                        'ip' => '127.0.0.1',
                        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Sample Browser',
                    ],
                    'process_histories' => [
                        [
                            'type' => 'reported',
                            'action_label' => '신고 접수',
                            'processor_id' => null,
                            'reason' => null,
                            'reporter_count' => 1,
                            'created_at' => $createdAt->format('Y-m-d H:i:s'),
                        ],
                    ],
                    'created_at' => $createdAt,
                    'updated_at' => now()->subDays(rand(0, 15)),
                ];

                // 처리된 상태인 경우 처리 정보 추가
                if ($status !== ReportStatus::Pending && $admin) {
                    $processedAt = now()->subDays(rand(0, 7));
                    $caseData['processed_by'] = $admin->id;
                    $caseData['processed_at'] = $processedAt;
                    $caseData['process_histories'][] = [
                        'type' => $status->value,
                        'action_label' => $status->label(),
                        'processor_id' => $admin->id,
                        'reason' => self::PROCESS_NOTES[$status->value] ?? null,
                        'reporter_count' => null,
                        'created_at' => $processedAt->format('Y-m-d H:i:s'),
                    ];
                }

                $case = Report::create($caseData);

                // boards_report_logs 신고자 기록 생성 (1~3명)
                $reporterCount = rand(1, min(3, $users->count()));
                $logReporters = $users->shuffle()->take($reporterCount);
                $reasonTypes = ReportReasonType::cases();

                foreach ($logReporters as $idx => $logReporter) {
                    $reasonType = $reasonTypes[$idx % count($reasonTypes)];
                    ReportLog::create([
                        'report_id' => $case->id,
                        'reporter_id' => $logReporter->id,
                        'snapshot' => [
                            'board_name' => $board->getLocalizedName(),
                            'title' => $snapshot['title'],
                            'content' => $snapshot['content'],
                            'content_mode' => $snapshot['content_mode'] ?? 'text',
                            'author_name' => $snapshot['author_name'],
                        ],
                        'reason_type' => $reasonType,
                        'reason_detail' => self::REASON_DETAILS[$reasonType->value],
                        'metadata' => [
                            'ip' => '127.0.0.'.($idx + 1),
                            'user_agent' => 'Mozilla/5.0 Sample Browser',
                        ],
                        'created_at' => $createdAt->copy()->addMinutes($idx * 10),
                        'updated_at' => $createdAt->copy()->addMinutes($idx * 10),
                    ]);
                }

                $caseCount++;
            }
        }

        $this->command->info("신고 케이스 생성 완료: {$caseCount}건");
        $this->command->info('  - 상태별 분포:');

        foreach (ReportStatus::cases() as $status) {
            $count = Report::where('status', $status)->count();
            $this->command->info("    - {$status->value}: {$count}건");
        }

        // 비회원 작성 콘텐츠에 대한 신고 데이터 생성
        $this->createGuestAuthorReports($boards, $users);

        // 다중 신고자 테스트용 케이스 생성 (동일 대상에 여러 신고자)
        $this->createMultiReporterCases($boards, $users, $admin);

        // 재접수 케이스 생성 (반려/중단 후 재신고로 재활성된 케이스)
        $this->createReactivatedCases($boards, $users, $admin);

        // 대량 신고자 케이스 생성 (단일 게시글에 20명 이상 신고)
        $this->createManyReportersCase($boards, $admin);
    }

    /**
     * 비회원 작성 콘텐츠에 대한 신고 케이스를 생성합니다.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $boards  게시판 목록
     * @param  \Illuminate\Database\Eloquent\Collection  $users  사용자 목록
     */
    private function createGuestAuthorReports($boards, $users): void
    {
        $this->command->info('');
        $this->command->info('비회원 작성 콘텐츠 신고 데이터 생성 중...');

        $guestNames = ['익명사용자', '방문자123', 'Guest User'];
        $count = 0;

        foreach ($guestNames as $index => $guestName) {
            $board = $boards->random();
            $reporter = $users->random();
            $reasonType = ReportReasonType::cases()[$index % count(ReportReasonType::cases())];
            $targetType = ReportType::cases()[$index % count(ReportType::cases())];

            $targetId = $this->getRandomTargetId($board, $targetType);

            if (! $targetId) {
                continue;
            }

            // UNIQUE 제약 확인
            $exists = Report::withTrashed()
                ->where('board_id', $board->id)
                ->where('target_type', $targetType)
                ->where('target_id', $targetId)
                ->exists();

            if ($exists) {
                continue;
            }

            $createdAt = now()->subDays(rand(1, 10));

            $case = Report::create([
                'board_id' => $board->id,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'author_id' => null,
                'status' => ReportStatus::Pending,
                'last_reported_at' => $createdAt,
                'last_activated_at' => null,
                'metadata' => [
                    'ip' => '192.168.1.'.($index + 100),
                    'user_agent' => 'Mozilla/5.0 Guest-Author-Test',
                ],
                'process_histories' => [
                    [
                        'type' => 'reported',
                        'action_label' => '신고 접수',
                        'processor_id' => null,
                        'reason' => null,
                        'reporter_count' => 1,
                        'created_at' => $createdAt->format('Y-m-d H:i:s'),
                    ],
                ],
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            ReportLog::create([
                'report_id' => $case->id,
                'reporter_id' => $reporter->id,
                'snapshot' => [
                    'board_name' => $board->getLocalizedName(),
                    'title' => '비회원 작성 테스트 게시글 '.($index + 1),
                    'content' => '비회원이 작성한 콘텐츠입니다.',
                    'content_mode' => 'text',
                    'author_name' => $guestName,
                ],
                'reason_type' => $reasonType,
                'reason_detail' => self::REASON_DETAILS[$reasonType->value],
                'metadata' => [
                    'ip' => '192.168.1.'.($index + 100),
                    'user_agent' => 'Mozilla/5.0 Guest-Author-Test',
                ],
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            $count++;
        }

        $this->command->line("비회원 작성 콘텐츠 신고 케이스 생성 완료: {$count}건");
    }

    /**
     * 다중 신고자 테스트용 케이스를 생성합니다.
     * 동일 케이스에 여러 신고자가 logs를 추가한 상태를 테스트합니다.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $boards  게시판 목록
     * @param  \Illuminate\Database\Eloquent\Collection  $users  사용자 목록
     * @param  User|null  $admin  관리자
     */
    private function createMultiReporterCases($boards, $users, $admin): void
    {
        $this->command->info('');
        $this->command->info('다중 신고자 테스트용 케이스 생성 중...');

        $caseCount = 0;
        $targetBoards = $boards->take(min(2, $boards->count()));

        foreach ($targetBoards as $board) {
            foreach ([ReportType::Post, ReportType::Comment] as $targetType) {
                $targetId = $this->getRandomTargetId($board, $targetType);

                if (! $targetId) {
                    continue;
                }

                // UNIQUE 제약 확인
                $exists = Report::withTrashed()
                    ->where('board_id', $board->id)
                    ->where('target_type', $targetType)
                    ->where('target_id', $targetId)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $snapshot = $this->getSnapshotData($board, $targetType, $targetId);
                $createdAt = now()->subDays(rand(5, 14));
                $lastReportedAt = $createdAt->copy()->addHours($users->count() - 1);
                $reasonTypes = ReportReasonType::cases();

                $case = Report::create([
                    'board_id' => $board->id,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'author_id' => $snapshot['author_id'],
                    'status' => ReportStatus::Pending,
                    'last_reported_at' => $lastReportedAt,
                    'last_activated_at' => null,
                    'metadata' => [
                        'ip' => '127.0.0.1',
                        'user_agent' => 'Mozilla/5.0 Multi-Reporter-Test',
                    ],
                    'process_histories' => [
                        [
                            'type' => 'reported',
                            'action_label' => '신고 접수',
                            'processor_id' => null,
                            'reason' => null,
                            'reporter_count' => $users->count(),
                            'created_at' => $createdAt->format('Y-m-d H:i:s'),
                        ],
                    ],
                    'created_at' => $createdAt,
                    'updated_at' => $lastReportedAt,
                ]);

                // 모든 사용자가 각각 신고 (boards_report_logs)
                foreach ($users as $idx => $reporter) {
                    $reasonType = $reasonTypes[$idx % count($reasonTypes)];
                    $logCreatedAt = $createdAt->copy()->addHours($idx);

                    ReportLog::create([
                        'report_id' => $case->id,
                        'reporter_id' => $reporter->id,
                        'snapshot' => [
                            'board_name' => $board->getLocalizedName(),
                            'title' => $snapshot['title'],
                            'content' => $snapshot['content'],
                            'content_mode' => $snapshot['content_mode'] ?? 'text',
                            'author_name' => $snapshot['author_name'],
                        ],
                        'reason_type' => $reasonType,
                        'reason_detail' => self::REASON_DETAILS[$reasonType->value],
                        'metadata' => [
                            'ip' => '127.0.0.'.($idx + 1),
                            'user_agent' => 'Mozilla/5.0 Multi-Reporter-Test',
                        ],
                        'created_at' => $logCreatedAt,
                        'updated_at' => $logCreatedAt,
                    ]);
                }

                $boardName = $board->getLocalizedName();
                $this->command->line("  - [{$boardName}] {$targetType->value} #{$targetId}: {$users->count()}명 신고");
                $caseCount++;
            }
        }

        $this->command->line("다중 신고자 테스트 케이스 생성 완료: {$caseCount}건");
    }

    /**
     * 재접수 케이스를 생성합니다.
     *
     * 시나리오:
     * A. 반려 후 재신고 → 현재 pending (단순 재접수)
     * B. 반려 후 재신고 → 다시 반려 (재접수 후 재처리)
     * C. 게시 중단 후 재신고 → 현재 pending (중단 후 재접수)
     * D. 반려 후 재신고 → 중단 처리 (재접수 후 중단)
     * E. 게시판 삭제 후 남은 신고 케이스 (board_id null — 스냅샷만 남음)
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $boards  게시판 목록
     * @param  \Illuminate\Database\Eloquent\Collection  $users  사용자 목록
     * @param  User|null  $admin  관리자
     */
    private function createReactivatedCases($boards, $users, $admin): void
    {
        $this->command->info('');
        $this->command->info('재접수 케이스 생성 중...');

        $caseCount = 0;
        $reasonTypes = ReportReasonType::cases();

        // 시나리오별 정의 [현재 status, 처리 이력 타입 목록, last_activated_at 설정 여부]
        $scenarios = [
            'A' => ['status' => ReportStatus::Pending,   'histories' => ['reported', 'rejected', 're_reported'],          'label' => '반려 후 재접수 (현재: 접수)'],
            'B' => ['status' => ReportStatus::Rejected,  'histories' => ['reported', 'rejected', 're_reported', 'rejected'], 'label' => '반려 후 재접수 후 재반려'],
            'C' => ['status' => ReportStatus::Pending,   'histories' => ['reported', 'suspended', 're_reported'],          'label' => '중단 후 재접수 (현재: 접수)'],
            'D' => ['status' => ReportStatus::Suspended, 'histories' => ['reported', 'rejected', 're_reported', 'suspended'], 'label' => '재접수 후 중단 처리'],
        ];

        foreach ($scenarios as $key => $scenario) {
            $board = $boards->random();
            $targetType = ReportType::cases()[array_rand(ReportType::cases())];
            $targetId = $this->getRandomTargetId($board, $targetType);

            if (! $targetId) {
                $this->command->line("  - 시나리오 {$key}: 대상 없음 (건너뜀)");
                continue;
            }

            $exists = Report::withTrashed()
                ->where('board_id', $board->id)
                ->where('target_type', $targetType)
                ->where('target_id', $targetId)
                ->exists();

            if ($exists) {
                $this->command->line("  - 시나리오 {$key}: 이미 케이스 존재 (건너뜀)");
                continue;
            }

            $snapshot = $this->getSnapshotData($board, $targetType, $targetId);

            // 시간 흐름: 최초 신고 → 처리 → 재신고 → (재처리)
            $firstReportedAt  = now()->subDays(rand(20, 30));
            $firstProcessedAt = $firstReportedAt->copy()->addDays(rand(1, 3));
            $reReportedAt     = $firstProcessedAt->copy()->addDays(rand(1, 5));
            $lastProcessedAt  = $reReportedAt->copy()->addDays(rand(1, 3));

            // process_histories 구성
            $histories = [];
            $reporterCount = 0;
            foreach ($scenario['histories'] as $idx => $type) {
                $reporterCount++;
                $isReportEvent = in_array($type, ['reported', 're_reported']);
                $entryTime = match ($idx) {
                    0 => $firstReportedAt,
                    1 => $firstProcessedAt,
                    2 => $reReportedAt,
                    default => $lastProcessedAt,
                };

                $histories[] = [
                    'type'         => $type,
                    'action_label' => $type,
                    'processor_id' => ($isReportEvent || ! $admin) ? null : $admin->id,
                    'reason'       => $isReportEvent ? null : (self::PROCESS_NOTES[$type] ?? null),
                    'reporter_count' => $isReportEvent ? $reporterCount : null,
                    'created_at'   => $entryTime->format('Y-m-d H:i:s'),
                ];
            }

            // 현재 status에 따른 처리자 정보
            $currentStatus = $scenario['status'];
            $isCurrentlyProcessed = in_array($currentStatus, [ReportStatus::Rejected, ReportStatus::Suspended]);

            $caseData = [
                'board_id'         => $board->id,
                'target_type'      => $targetType,
                'target_id'        => $targetId,
                'author_id'        => $snapshot['author_id'],
                'status'           => $currentStatus,
                'processed_by'     => ($isCurrentlyProcessed && $admin) ? $admin->id : null,
                'processed_at'     => $isCurrentlyProcessed ? $lastProcessedAt : null,
                'last_reported_at' => $reReportedAt,
                'last_activated_at' => $reReportedAt,
                'metadata'         => ['ip' => '127.0.0.1', 'user_agent' => 'Mozilla/5.0 Reactivated-Test'],
                'process_histories' => $histories,
                'created_at'       => $firstReportedAt,
                'updated_at'       => $isCurrentlyProcessed ? $lastProcessedAt : $reReportedAt,
            ];

            $case = Report::create($caseData);

            // 최초 신고자 log
            $firstReporter = $users->random();
            ReportLog::create([
                'report_id'     => $case->id,
                'reporter_id'   => $firstReporter->id,
                'snapshot'      => [
                    'board_name'   => $board->getLocalizedName(),
                    'title'        => $snapshot['title'],
                    'content'      => $snapshot['content'],
                    'content_mode' => $snapshot['content_mode'] ?? 'text',
                    'author_name'  => $snapshot['author_name'],
                ],
                'reason_type'   => $reasonTypes[0],
                'reason_detail' => self::REASON_DETAILS[$reasonTypes[0]->value],
                'metadata'      => ['ip' => '127.0.0.1', 'user_agent' => 'Mozilla/5.0'],
                'created_at'    => $firstReportedAt,
                'updated_at'    => $firstReportedAt,
            ]);

            // 재신고자 log (다른 사용자 — 없으면 건너뜀)
            $filteredUsers = $users->filter(fn ($u) => $u->id !== $firstReporter->id);
            if ($filteredUsers->isEmpty()) {
                $caseCount++;
                continue;
            }
            $reReporter = $filteredUsers->random();
            ReportLog::create([
                'report_id'     => $case->id,
                'reporter_id'   => $reReporter->id,
                'snapshot'      => [
                    'board_name'   => $board->getLocalizedName(),
                    'title'        => $snapshot['title'],
                    'content'      => $snapshot['content'],
                    'content_mode' => $snapshot['content_mode'] ?? 'text',
                    'author_name'  => $snapshot['author_name'],
                ],
                'reason_type'   => $reasonTypes[1 % count($reasonTypes)],
                'reason_detail' => self::REASON_DETAILS[$reasonTypes[1 % count($reasonTypes)]->value],
                'metadata'      => ['ip' => '127.0.0.2', 'user_agent' => 'Mozilla/5.0'],
                'created_at'    => $reReportedAt,
                'updated_at'    => $reReportedAt,
            ]);

            $boardName = $board->getLocalizedName();
            $this->command->line("  - 시나리오 {$key} [{$boardName}] {$targetType->value} #{$targetId}: {$scenario['label']}");
            $caseCount++;
        }

        // 시나리오 E: board_id null (게시판 삭제 후 남은 신고 케이스)
        $this->command->line('  - 시나리오 E: 게시판 삭제 후 남은 신고 케이스 생성');
        $reporter = $users->random();
        $orphanAt = now()->subDays(rand(10, 20));

        $orphanCase = Report::create([
            'board_id'          => null,
            'target_type'       => ReportType::Post,
            'target_id'         => 99999,
            'author_id'         => null,
            'status'            => ReportStatus::Pending,
            'last_reported_at'  => $orphanAt,
            'last_activated_at' => null,
            'metadata'          => ['ip' => '10.0.0.1', 'user_agent' => 'Mozilla/5.0 Orphan-Test'],
            'process_histories' => [
                [
                    'type'           => 'reported',
                    'action_label'   => '신고 접수',
                    'processor_id'   => null,
                    'reason'         => null,
                    'reporter_count' => 1,
                    'created_at'     => $orphanAt->format('Y-m-d H:i:s'),
                ],
            ],
            'created_at' => $orphanAt,
            'updated_at' => $orphanAt,
        ]);

        ReportLog::create([
            'report_id'     => $orphanCase->id,
            'reporter_id'   => $reporter->id,
            'snapshot'      => [
                'board_name'   => '삭제된 게시판',
                'title'        => '삭제된 게시판의 게시글',
                'content'      => '게시판이 삭제되어 원본을 확인할 수 없는 신고 케이스입니다.',
                'content_mode' => 'text',
                'author_name'  => '알 수 없음',
            ],
            'reason_type'   => ReportReasonType::Spam,
            'reason_detail' => self::REASON_DETAILS[ReportReasonType::Spam->value],
            'metadata'      => ['ip' => '10.0.0.1', 'user_agent' => 'Mozilla/5.0'],
            'created_at'    => $orphanAt,
            'updated_at'    => $orphanAt,
        ]);

        $caseCount++;
        $this->command->line("재접수/특수 케이스 생성 완료: {$caseCount}건");
    }

    /**
     * 대량 신고자 케이스를 생성합니다.
     * 단일 게시글(또는 댓글)에 20명 이상이 신고한 시나리오입니다.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $boards  게시판 목록
     * @param  User|null  $admin  관리자
     */
    private function createManyReportersCase($boards, $admin): void
    {
        $this->command->info('');
        $this->command->info('대량 신고자 케이스 생성 중 (20명 이상)...');

        // 사용자 최대 25명 조회 (20명 이상 확보)
        $manyUsers = User::orderBy('id')->limit(25)->get();

        if ($manyUsers->count() < 5) {
            $this->command->warn('사용자가 5명 미만입니다. 대량 신고자 케이스 생성을 건너뜁니다.');

            return;
        }

        $board = $boards->first();
        $reasonTypes = ReportReasonType::cases();
        $caseCount = 0;

        foreach ([ReportType::Post, ReportType::Comment] as $targetType) {
            $targetId = $this->getRandomTargetId($board, $targetType);

            if (! $targetId) {
                continue;
            }

            // UNIQUE 제약 확인
            $exists = Report::withTrashed()
                ->where('board_id', $board->id)
                ->where('target_type', $targetType)
                ->where('target_id', $targetId)
                ->exists();

            if ($exists) {
                continue;
            }

            $snapshot = $this->getSnapshotData($board, $targetType, $targetId);
            $createdAt = now()->subDays(rand(3, 7));
            $lastReportedAt = $createdAt->copy()->addMinutes(($manyUsers->count() - 1) * 5);

            $case = Report::create([
                'board_id' => $board->id,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'author_id' => $snapshot['author_id'],
                'status' => ReportStatus::Pending,
                'last_reported_at' => $lastReportedAt,
                'last_activated_at' => null,
                'metadata' => [
                    'ip' => '127.0.0.1',
                    'user_agent' => 'Mozilla/5.0 Many-Reporters-Test',
                ],
                'process_histories' => [
                    [
                        'type' => 'reported',
                        'action_label' => '신고 접수',
                        'processor_id' => null,
                        'reason' => null,
                        'reporter_count' => $manyUsers->count(),
                        'created_at' => $createdAt->format('Y-m-d H:i:s'),
                    ],
                ],
                'created_at' => $createdAt,
                'updated_at' => $lastReportedAt,
            ]);

            // 각 사용자별 신고 log 생성
            foreach ($manyUsers as $idx => $reporter) {
                $reasonType = $reasonTypes[$idx % count($reasonTypes)];
                $logCreatedAt = $createdAt->copy()->addMinutes($idx * 5);

                ReportLog::create([
                    'report_id' => $case->id,
                    'reporter_id' => $reporter->id,
                    'snapshot' => [
                        'board_name' => $board->getLocalizedName(),
                        'title' => $snapshot['title'],
                        'content' => $snapshot['content'],
                        'content_mode' => $snapshot['content_mode'] ?? 'text',
                        'author_name' => $snapshot['author_name'],
                    ],
                    'reason_type' => $reasonType,
                    'reason_detail' => self::REASON_DETAILS[$reasonType->value],
                    'metadata' => [
                        'ip' => '10.10.'.intdiv($idx, 255).'.'.($idx % 255 + 1),
                        'user_agent' => 'Mozilla/5.0 Many-Reporters-Test',
                    ],
                    'created_at' => $logCreatedAt,
                    'updated_at' => $logCreatedAt,
                ]);
            }

            $boardName = $board->getLocalizedName();
            $this->command->line("  - [{$boardName}] {$targetType->value} #{$targetId}: {$manyUsers->count()}명 신고");
            $caseCount++;

            // 게시글 타입 1건 생성 후 종료 (댓글도 성공하면 2건)
        }

        $this->command->line("대량 신고자 케이스 생성 완료: {$caseCount}건 (신고자 수: {$manyUsers->count()}명)");
    }

    /**
     * 랜덤 대상 ID를 조회합니다.
     *
     * @param  Board  $board  게시판
     * @param  ReportType  $targetType  대상 타입
     * @return int|null 대상 ID
     */
    private function getRandomTargetId(Board $board, ReportType $targetType): ?int
    {
        if ($targetType === ReportType::Post) {
            $record = DB::table('board_posts')
                ->where('board_id', $board->id)
                ->inRandomOrder()
                ->first();
        } else {
            $record = DB::table('board_comments')
                ->where('board_id', $board->id)
                ->inRandomOrder()
                ->first();
        }

        return $record?->id;
    }

    /**
     * 스냅샷 데이터를 조회합니다.
     *
     * @param  Board  $board  게시판
     * @param  ReportType  $targetType  대상 타입
     * @param  int  $targetId  대상 ID
     * @return array<string, mixed> 스냅샷 데이터
     */
    private function getSnapshotData(Board $board, ReportType $targetType, int $targetId): array
    {
        if ($targetType === ReportType::Post) {
            $record = DB::table('board_posts')
                ->where('board_id', $board->id)
                ->where('id', $targetId)
                ->first();

            if ($record) {
                $authorId = $record->user_id ?? null;

                // 외래키 제약: 존재하지 않는 유저 ID는 null 처리
                if ($authorId && ! User::find($authorId)) {
                    $authorId = null;
                }

                $authorName = $record->author_name;

                if (! $authorName && $authorId) {
                    $user = User::find($authorId);
                    $authorName = $user?->name ?? '알 수 없음';
                }

                return [
                    'author_id' => $authorId,
                    'title' => $record->title,
                    'content' => strip_tags($record->content),
                    'content_mode' => $record->content_mode ?? 'text',
                    'author_name' => $authorName ?? '알 수 없음',
                ];
            }
        } else {
            $comment = DB::table('board_comments')
                ->where('board_id', $board->id)
                ->where('id', $targetId)
                ->first();

            if ($comment) {
                $authorId = $comment->user_id ?? null;

                // 외래키 제약: 존재하지 않는 유저 ID는 null 처리
                if ($authorId && ! User::find($authorId)) {
                    $authorId = null;
                }

                $authorName = $comment->author_name;

                if (! $authorName && $authorId) {
                    $user = User::find($authorId);
                    $authorName = $user?->name ?? '알 수 없음';
                }

                // 댓글이 속한 게시글 제목 조회
                $postTitle = null;
                if ($comment->post_id) {
                    $post = DB::table('board_posts')
                        ->where('board_id', $board->id)
                        ->where('id', $comment->post_id)
                        ->first();
                    $postTitle = $post?->title;
                }

                return [
                    'author_id' => $authorId,
                    'title' => $postTitle,
                    'content' => $comment->content,
                    'content_mode' => 'text',
                    'author_name' => $authorName ?? '알 수 없음',
                ];
            }
        }

        return [
            'author_id' => null,
            'title' => '삭제된 콘텐츠',
            'content' => '원본 콘텐츠가 삭제되었습니다.',
            'content_mode' => 'text',
            'author_name' => '알 수 없음',
        ];
    }
}
