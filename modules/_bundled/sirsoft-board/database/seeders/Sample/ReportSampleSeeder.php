<?php

namespace Modules\Sirsoft\Board\Database\Seeders\Sample;

use App\Enums\UserStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Database\Seeders\Sample\Concerns\BoardSampleMarker;
use Modules\Sirsoft\Board\Database\Seeders\Sample\Concerns\ModeratesSampleContent;
use Modules\Sirsoft\Board\Database\Seeders\Sample\Concerns\SampleRunRegistry;
use Modules\Sirsoft\Board\Database\Seeders\Sample\Concerns\SyncsBoardCounts;
use Modules\Sirsoft\Board\Enums\ReportReasonType;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Enums\ReportType;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Models\ReportLog;
use Modules\Sirsoft\Board\Services\BoardSettingsService;

/**
 * 신고 샘플 시더
 *
 * 1케이스 구조: boards_reports(케이스 1행) + boards_report_logs(신고자 기록 N행)
 * 다양한 상태와 사유의 신고 케이스 데이터를 생성합니다.
 *
 * 불변식 (운영 경로 ReportService 와 같은 결과):
 * - 재시드 정리는 샘플 표식(metadata.sample=true)이 있는 케이스·로그만 지운다 — 운영자가 처리한
 *   실제 신고는 남는다(B-4). 이전 버전 시더가 표식 없이 만든 신고는 정리되지 않는다.
 * - 대상은 이번 실행에서 게시글 샘플 시더가 만든(SampleRunRegistry) 게시 상태·미삭제 글·댓글 — 운영 글은 신고하지 않는다.
 *   신고자는 활성 회원이고 대상 작성자가 아니다(B-12·B-13)
 * - 신고 1건마다 처리 이력에 reported 항목(사이클 누적 수)이 붙고, 처리 이력 라벨은 서비스와 같은 값이다
 * - 게시 중단(suspended) → 대상 블라인드(trigger report), 삭제(deleted) → 대상 삭제(글이면 연쇄),
 *   신고자 수가 자동 블라인드 기준 이상인 활성 케이스 → 대상 블라인드(trigger auto_hide) (B-3)
 * - 시각은 대상 작성 → 신고 → 처리 순서, 모두 현재 이전
 */
class ReportSampleSeeder extends Seeder
{
    use ModeratesSampleContent;
    use SyncsBoardCounts;

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
        ReportStatus::Deleted->value => '신고 내용 확인 결과 규정 위반이 명백하여 해당 콘텐츠를 삭제하였습니다.',
    ];

    /**
     * 이번 실행에서 이미 케이스를 만든 대상 (board_id:type:id) — UNIQUE 제약 회피
     *
     * @var array<string, true>
     */
    private array $usedTargets = [];

    /**
     * 처리자(관리자)
     */
    private ?User $admin = null;

    /**
     * 자동 블라인드 기준 신고자 수 (0 이면 끔)
     */
    private int $autoHideThreshold = 0;

    /**
     * 자동 블라인드 대상 유형 (post, comment, both)
     */
    private string $autoHideTarget = 'both';

    /**
     * 시더 실행
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        $this->command->info('신고 샘플 데이터 생성 중...');

        // 이번 실행에서 만든 샘플 글이 없으면(게시판에 이미 글이 있어 게시글 시더가 건너뜀) 신고 대상도 없다 —
        // 이전 샘플 신고를 지우면 그 신고로 블라인드된 글만 남으므로 그대로 두고 건너뛴다
        if (SampleRunRegistry::range('board_posts') === null) {
            $this->command->warn('이번 실행에서 만든 샘플 글이 없어 신고 샘플을 건너뜁니다 (이전 샘플 신고는 그대로 둡니다).');

            return;
        }

        // 재시드 정리 — 샘플 표식이 있는 케이스·로그만 지운다(운영 신고 보존, B-4)
        $this->purgeSampleReports();

        // 신고 기능이 켜진 샘플 게시판만 대상으로 한다
        $boards = Board::whereIn('slug', BoardSampleMarker::SAMPLE_BOARD_SLUGS)
            ->where('use_report', true)
            ->get();

        if ($boards->isEmpty()) {
            $this->command->warn('신고 기능이 활성화된 샘플 게시판이 없습니다.');

            return;
        }

        // 신고자는 활성 회원 (차단·휴면·탈퇴 회원 제외, B-12)
        $users = User::where('status', UserStatus::Active->value)->orderBy('id')->limit(25)->get();

        if ($users->count() < 2) {
            $this->command->error('활성 회원이 2명 이상 필요합니다. DummyUserSeeder를 먼저 실행하세요.');

            return;
        }

        $this->admin = User::whereHas('roles', function ($query) {
            $query->where('identifier', 'admin');
        })->first();

        $policy = app(BoardSettingsService::class)->getSettings('report_policy');
        $this->autoHideThreshold = (int) ($policy['auto_hide_threshold'] ?? 0);
        $this->autoHideTarget = (string) ($policy['auto_hide_target'] ?? 'both');

        $caseCount = 0;

        // 각 상태별로 케이스 생성 (1케이스 = boards_reports 1행 + boards_report_logs N행)
        foreach (ReportStatus::cases() as $status) {
            $countForStatus = rand(3, 4);

            for ($i = 0; $i < $countForStatus; $i++) {
                $board = $boards->random();
                $targetType = ReportType::cases()[array_rand(ReportType::cases())];
                $target = $this->pickTarget($board, $targetType, deletable: $status === ReportStatus::Deleted);

                if (! $target) {
                    continue;
                }

                // 자동 블라인드 기준 미만으로 1~3명 (기준이 꺼져 있으면 1~3명)
                $maxReporters = $this->autoHideThreshold > 1 ? min(3, $this->autoHideThreshold - 1) : 3;
                $reporters = $this->pickReporters($users, $target, rand(1, $maxReporters));

                if ($reporters->isEmpty()) {
                    continue;
                }

                if ($this->createCase($board, $targetType, $target, $status, $reporters, 'Sample Browser')) {
                    $caseCount++;
                }
            }
        }

        $this->command->info("신고 케이스 생성 완료: {$caseCount}건");

        // 비회원 작성 콘텐츠에 대한 신고 데이터 생성
        $this->createGuestAuthorReports($boards, $users);

        // 다중 신고자 케이스 (자동 블라인드 기준 미만)
        $this->createMultiReporterCases($boards, $users);

        // 재접수 케이스 (반려/중단 후 재신고로 재활성된 케이스)
        $this->createReactivatedCases($boards, $users);

        // 대량 신고자 케이스 (자동 블라인드 기준 이상 → 대상 자동 블라인드)
        $this->createManyReportersCase($boards, $users);

        $this->command->info('  - 상태별 분포 (샘플):');
        foreach (ReportStatus::cases() as $status) {
            $count = $this->sampleReportQuery()->where('status', $status)->count();
            $this->command->info("    - {$status->value}: {$count}건");
        }

        // 삭제 조치로 바뀐 글·댓글 수를 게시판 카운트에 반영
        $this->syncBoardCounts($boards->pluck('id')->all());
    }

    /**
     * 샘플 표식이 있는 신고 케이스·로그만 영구 삭제합니다.
     *
     * @return void 반환값 없음
     */
    private function purgeSampleReports(): void
    {
        $sampleIds = $this->sampleReportQuery()->withTrashed()->pluck('id')->all();

        if ($sampleIds !== []) {
            ReportLog::query()->whereIn('report_id', $sampleIds)->delete();
            Report::withTrashed()->whereIn('id', $sampleIds)->forceDelete();
            $this->command->line('  - 이전 샘플 신고 '.count($sampleIds).'건 정리 (표식 있는 케이스만)');
        }

        $unmarked = Report::withTrashed()->count();
        if ($unmarked > 0) {
            $this->command->warn("  ⚠ 표식 없는 신고 {$unmarked}건은 운영 데이터로 보고 남깁니다 (이전 버전 시더가 만든 샘플도 표식이 없어 정리되지 않습니다).");
        }
    }

    /**
     * 샘플 표식(metadata.sample=true)이 있는 신고 케이스 쿼리
     *
     * metadata 는 text 컬럼(JSON 문자열)이라 JSON 함수 대신 직렬화 모양으로 찾는다 —
     * JSON 이 아닌 값이 섞여 있어도 쿼리가 실패하지 않는다.
     *
     * @return Builder<Report> 쿼리
     */
    private function sampleReportQuery()
    {
        return Report::query()->where('metadata', 'like', '%"'.BoardSampleMarker::REPORT_METADATA_KEY.'":true%');
    }

    /**
     * 신고 케이스 1건과 신고자 로그를 만들고, 상태에 맞는 조치를 대상에 반영합니다.
     *
     * @param  Board  $board  게시판
     * @param  ReportType  $targetType  대상 유형
     * @param  object  $target  대상 행 (id, user_id, author_name, created_at …)
     * @param  ReportStatus  $status  최종 상태
     * @param  Collection<int, User>  $reporters  신고자 (대상 작성자 제외)
     * @param  string  $agent  user_agent 꼬리표
     * @return Report|null 만든 케이스
     */
    private function createCase(Board $board, ReportType $targetType, object $target, ReportStatus $status, Collection $reporters, string $agent): ?Report
    {
        $snapshot = $this->snapshotOf($board, $targetType, $target);

        // 신고 시각: 대상 작성 뒤 ~ 지금, 신고자마다 10분 간격
        $firstReportedAt = $this->randomTimeAfter(Carbon::parse($target->created_at), 24 * 20);
        $logTimes = [];
        foreach ($reporters->values() as $idx => $_) {
            $logTimes[] = $this->clampToNow($firstReportedAt->copy()->addMinutes($idx * 10));
        }
        $lastLogAt = end($logTimes);

        $histories = [];
        foreach ($logTimes as $idx => $at) {
            $histories[] = $this->historyEntry('reported', null, null, $at, $idx + 1);
        }

        $processedAt = null;
        if ($status !== ReportStatus::Pending) {
            $processedAt = $this->randomTimeAfter($lastLogAt, 72);
            $histories[] = $this->historyEntry($status->value, self::PROCESS_NOTES[$status->value] ?? null, $this->admin?->id, $processedAt);
        }

        $case = Report::create([
            'board_id' => $board->id,
            'target_type' => $targetType,
            'target_id' => $target->id,
            'author_id' => $snapshot['author_id'],
            'status' => $status,
            'processed_by' => $processedAt ? $this->admin?->id : null,
            'processed_at' => $processedAt,
            'last_reported_at' => $firstReportedAt,
            'last_activated_at' => null,
            'metadata' => $this->sampleMetadata('127.0.0.1', $agent),
            'process_histories' => $histories,
            'created_at' => $firstReportedAt,
            'updated_at' => $processedAt ?? $lastLogAt,
        ]);
        $this->backdate($case, $firstReportedAt, $processedAt ?? $lastLogAt);

        $this->createLogs($case, $board, $snapshot, $reporters, $logTimes, $agent);
        $this->applyOutcome($board, $targetType, $target, $status, $reporters->count(), $logTimes, $processedAt);

        return $case;
    }

    /**
     * 케이스 상태·신고자 수에 맞는 조치를 대상 글·댓글에 반영합니다 (ReportService 와 같은 결과).
     *
     * @param  Board  $board  게시판
     * @param  ReportType  $targetType  대상 유형
     * @param  object  $target  대상 행
     * @param  ReportStatus  $status  최종 상태
     * @param  int  $reporterCount  이번 사이클 신고자 수
     * @param  array<int, Carbon>  $logTimes  신고 시각 목록
     * @param  Carbon|null  $processedAt  처리 시각
     * @return void 반환값 없음
     */
    private function applyOutcome(Board $board, ReportType $targetType, object $target, ReportStatus $status, int $reporterCount, array $logTimes, ?Carbon $processedAt): void
    {
        $table = $targetType === ReportType::Post ? 'board_posts' : 'board_comments';

        // 자동 블라인드 — 기준 수에 닿은 신고 시각에 블라인드된다 (반려·삭제 등 처리 전)
        $autoHideApplies = $this->autoHideThreshold > 0
            && $reporterCount >= $this->autoHideThreshold
            && ($this->autoHideTarget === 'both' || $this->autoHideTarget === $targetType->value);

        if ($autoHideApplies) {
            $at = $logTimes[$this->autoHideThreshold - 1];
            $this->blindSampleTarget($table, (int) $target->id, 'auto_hide', $this->sampleActionLog(
                'blind', __('sirsoft-board::messages.reports.blind_by_auto_hide'), $at, null, 'System'
            ));
        }

        if ($processedAt === null) {
            return;
        }

        if ($status === ReportStatus::Suspended) {
            $this->blindSampleTarget($table, (int) $target->id, 'report', $this->sampleActionLog(
                'blind', __('sirsoft-board::messages.reports.blind_by_report'), $processedAt, $this->admin?->id, $this->admin?->name
            ));

            return;
        }

        if ($status === ReportStatus::Deleted) {
            $log = $this->sampleActionLog('delete', null, $processedAt, $this->admin?->id, $this->admin?->name);

            if ($targetType === ReportType::Post) {
                // 답글 삭제 정책 block 게시판에서 살아 있는 답글이 달린 글은 운영 경로도 삭제를 거부한다
                $blocked = ($board->reply_delete_policy?->value ?? 'cascade') === 'block'
                    && DB::table('board_posts')->where('parent_id', $target->id)->whereNull('deleted_at')->exists();

                if (! $blocked) {
                    $this->softDeleteSamplePostCascade($board->id, (int) $target->id, $processedAt, 'report', $log);
                }
            } else {
                $this->softDeleteSampleComment((int) $target->id, $processedAt, 'report', $log);
            }

            return;
        }

        // 반려 — 신고가 가린 것(자동 블라인드)만 되돌린다
        if ($status === ReportStatus::Rejected && $autoHideApplies) {
            DB::table($table)->where('id', $target->id)->where('trigger_type', 'auto_hide')->update([
                'status' => 'published',
            ]);
        }
    }

    /**
     * 신고자 로그를 만듭니다.
     *
     * @param  Report  $case  케이스
     * @param  Board  $board  게시판
     * @param  array<string, mixed>  $snapshot  대상 스냅샷
     * @param  Collection<int, User>  $reporters  신고자
     * @param  array<int, Carbon>  $logTimes  신고 시각
     * @param  string  $agent  user_agent 꼬리표
     * @return void 반환값 없음
     */
    private function createLogs(Report $case, Board $board, array $snapshot, Collection $reporters, array $logTimes, string $agent): void
    {
        $reasonTypes = ReportReasonType::cases();

        foreach ($reporters->values() as $idx => $reporter) {
            $reasonType = $reasonTypes[$idx % count($reasonTypes)];
            $log = ReportLog::create([
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
                'metadata' => $this->sampleMetadata('10.10.'.intdiv($idx, 255).'.'.($idx % 255 + 1), $agent),
                'created_at' => $logTimes[$idx],
                'updated_at' => $logTimes[$idx],
            ]);
            $this->backdate($log, $logTimes[$idx]);
        }
    }

    /**
     * 비회원이 쓴 글·댓글에 대한 신고 케이스를 생성합니다 (실제 비회원 글이 대상, B-13⑤).
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $boards  게시판 목록
     * @param  Collection<int, User>  $users  사용자 목록
     * @return void 반환값 없음
     */
    private function createGuestAuthorReports($boards, Collection $users): void
    {
        $this->command->info('');
        $this->command->info('비회원 작성 콘텐츠 신고 데이터 생성 중...');

        $count = 0;

        foreach ($boards as $board) {
            foreach ([ReportType::Post, ReportType::Comment] as $targetType) {
                if ($count >= 3) {
                    break 2;
                }

                $target = $this->pickTarget($board, $targetType, guestOnly: true);
                if (! $target) {
                    continue;
                }

                $reporters = $this->pickReporters($users, $target, 1);
                if ($reporters->isNotEmpty() && $this->createCase($board, $targetType, $target, ReportStatus::Pending, $reporters, 'Guest-Author-Test')) {
                    $count++;
                }
            }
        }

        $this->command->line("비회원 작성 콘텐츠 신고 케이스 생성 완료: {$count}건");
    }

    /**
     * 다중 신고자 케이스를 생성합니다 — 같은 대상에 여러 신고자, 자동 블라인드 기준 미만.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $boards  게시판 목록
     * @param  Collection<int, User>  $users  사용자 목록
     * @return void 반환값 없음
     */
    private function createMultiReporterCases($boards, Collection $users): void
    {
        $this->command->info('');
        $this->command->info('다중 신고자 케이스 생성 중...');

        $caseCount = 0;
        $reporterCount = $this->autoHideThreshold > 1 ? $this->autoHideThreshold - 1 : 4;

        foreach ($boards->take(min(2, $boards->count())) as $board) {
            foreach ([ReportType::Post, ReportType::Comment] as $targetType) {
                $target = $this->pickTarget($board, $targetType);
                if (! $target) {
                    continue;
                }

                $reporters = $this->pickReporters($users, $target, $reporterCount);
                if ($reporters->isNotEmpty() && $this->createCase($board, $targetType, $target, ReportStatus::Pending, $reporters, 'Multi-Reporter-Test')) {
                    $this->command->line("  - [{$board->slug}] {$targetType->value} #{$target->id}: {$reporters->count()}명 신고");
                    $caseCount++;
                }
            }
        }

        $this->command->line("다중 신고자 케이스 생성 완료: {$caseCount}건");
    }

    /**
     * 대량 신고자 케이스를 생성합니다 — 자동 블라인드 기준 이상이 신고해 대상이 자동 블라인드된 상태.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $boards  게시판 목록
     * @param  Collection<int, User>  $users  사용자 목록
     * @return void 반환값 없음
     */
    private function createManyReportersCase($boards, Collection $users): void
    {
        $this->command->info('');
        $this->command->info('대량 신고자 케이스 생성 중...');

        $board = $boards->first();
        $caseCount = 0;

        foreach ([ReportType::Post, ReportType::Comment] as $targetType) {
            $target = $this->pickTarget($board, $targetType);
            if (! $target) {
                continue;
            }

            $reporters = $this->pickReporters($users, $target, $users->count());
            if ($reporters->count() < 2) {
                continue;
            }

            if ($this->createCase($board, $targetType, $target, ReportStatus::Pending, $reporters, 'Many-Reporters-Test')) {
                $this->command->line("  - [{$board->slug}] {$targetType->value} #{$target->id}: {$reporters->count()}명 신고");
                $caseCount++;
            }
        }

        $this->command->line("대량 신고자 케이스 생성 완료: {$caseCount}건 (자동 블라인드 기준 {$this->autoHideThreshold}명)");
    }

    /**
     * 재접수 케이스를 생성합니다.
     *
     * 시나리오:
     * A. 반려 후 재신고 → 현재 pending (단순 재접수)
     * B. 반려 후 재신고 → 다시 반려 (재접수 후 재처리)
     * C. 게시 중단 후 재신고 → 현재 pending (대상은 블라인드 유지)
     * D. 반려 후 재신고 → 중단 처리 (대상 블라인드)
     * E. 게시판 삭제 후 남은 신고 케이스 (board_id null — 스냅샷만 남음)
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $boards  게시판 목록
     * @param  Collection<int, User>  $users  사용자 목록
     * @return void 반환값 없음
     */
    private function createReactivatedCases($boards, Collection $users): void
    {
        $this->command->info('');
        $this->command->info('재접수 케이스 생성 중...');

        $caseCount = 0;
        $reasonTypes = ReportReasonType::cases();

        $scenarios = [
            'A' => ['status' => ReportStatus::Pending,   'first' => ReportStatus::Rejected,  'second' => null,                     'label' => '반려 후 재접수 (현재: 접수)'],
            'B' => ['status' => ReportStatus::Rejected,  'first' => ReportStatus::Rejected,  'second' => ReportStatus::Rejected,  'label' => '반려 후 재접수 후 재반려'],
            'C' => ['status' => ReportStatus::Pending,   'first' => ReportStatus::Suspended, 'second' => null,                     'label' => '중단 후 재접수 (현재: 접수)'],
            'D' => ['status' => ReportStatus::Suspended, 'first' => ReportStatus::Rejected,  'second' => ReportStatus::Suspended, 'label' => '재접수 후 중단 처리'],
        ];

        foreach ($scenarios as $key => $scenario) {
            $board = $boards->random();
            $targetType = ReportType::cases()[array_rand(ReportType::cases())];
            $target = $this->pickTarget($board, $targetType);

            if (! $target) {
                $this->command->line("  - 시나리오 {$key}: 대상 없음 (건너뜀)");

                continue;
            }

            $reporters = $this->pickReporters($users, $target, 2);
            if ($reporters->count() < 2) {
                continue;
            }

            $snapshot = $this->snapshotOf($board, $targetType, $target);

            // 시간 흐름: 최초 신고 → 처리 → 재신고 → (재처리)
            $firstReportedAt = $this->randomTimeAfter(Carbon::parse($target->created_at), 24 * 5);
            $firstProcessedAt = $this->randomTimeAfter($firstReportedAt, 48);
            $reReportedAt = $this->randomTimeAfter($firstProcessedAt, 72);
            $lastProcessedAt = $scenario['second'] ? $this->randomTimeAfter($reReportedAt, 48) : null;

            $histories = [
                $this->historyEntry('reported', null, null, $firstReportedAt, 1),
                $this->historyEntry($scenario['first']->value, self::PROCESS_NOTES[$scenario['first']->value] ?? null, $this->admin?->id, $firstProcessedAt),
                // 재활성 첫 신고는 새 사이클의 1건
                $this->historyEntry('re_reported', null, null, $reReportedAt, 1),
            ];
            if ($scenario['second']) {
                $histories[] = $this->historyEntry($scenario['second']->value, self::PROCESS_NOTES[$scenario['second']->value] ?? null, $this->admin?->id, $lastProcessedAt);
            }

            $processedAt = $scenario['second'] ? $lastProcessedAt : null;

            $case = Report::create([
                'board_id' => $board->id,
                'target_type' => $targetType,
                'target_id' => $target->id,
                'author_id' => $snapshot['author_id'],
                'status' => $scenario['status'],
                'processed_by' => $processedAt ? $this->admin?->id : null,
                'processed_at' => $processedAt,
                'last_reported_at' => $reReportedAt,
                'last_activated_at' => $reReportedAt,
                'metadata' => $this->sampleMetadata('127.0.0.1', 'Reactivated-Test'),
                'process_histories' => $histories,
                'created_at' => $firstReportedAt,
                'updated_at' => $processedAt ?? $reReportedAt,
            ]);
            $this->backdate($case, $firstReportedAt, $processedAt ?? $reReportedAt);

            $this->createLogs($case, $board, $snapshot, $reporters, [$firstReportedAt, $reReportedAt], 'Reactivated-Test');

            // 대상 상태: 마지막으로 게시 중단된 적이 있으면 블라인드 (C 는 재접수 중에도 블라인드 유지, D 는 재처리로 블라인드)
            $blindAt = match ($key) {
                'C' => $firstProcessedAt,
                'D' => $lastProcessedAt,
                default => null,
            };
            if ($blindAt) {
                $table = $targetType === ReportType::Post ? 'board_posts' : 'board_comments';
                $this->blindSampleTarget($table, (int) $target->id, 'report', $this->sampleActionLog(
                    'blind', __('sirsoft-board::messages.reports.blind_by_report'), $blindAt, $this->admin?->id, $this->admin?->name
                ));
            }

            $this->command->line("  - 시나리오 {$key} [{$board->slug}] {$targetType->value} #{$target->id}: {$scenario['label']}");
            $caseCount++;
        }

        // 시나리오 E: board_id null (게시판 삭제 후 남은 신고 케이스)
        $this->command->line('  - 시나리오 E: 게시판 삭제 후 남은 신고 케이스 생성');
        $reporter = $users->random();
        $orphanAt = now()->subDays(rand(10, 20));

        $orphanCase = Report::create([
            'board_id' => null,
            'target_type' => ReportType::Post,
            'target_id' => 99999,
            'author_id' => null,
            'status' => ReportStatus::Pending,
            'last_reported_at' => $orphanAt,
            'last_activated_at' => null,
            'metadata' => $this->sampleMetadata('10.0.0.1', 'Orphan-Test'),
            'process_histories' => [
                $this->historyEntry('reported', null, null, $orphanAt, 1),
            ],
            'created_at' => $orphanAt,
            'updated_at' => $orphanAt,
        ]);
        $this->backdate($orphanCase, $orphanAt);

        $orphanLog = ReportLog::create([
            'report_id' => $orphanCase->id,
            'reporter_id' => $reporter->id,
            'snapshot' => [
                'board_name' => '삭제된 게시판',
                'title' => '삭제된 게시판의 게시글',
                'content' => '게시판이 삭제되어 원본을 확인할 수 없는 신고 케이스입니다.',
                'content_mode' => 'text',
                'author_name' => '알 수 없음',
            ],
            'reason_type' => ReportReasonType::Spam,
            'reason_detail' => self::REASON_DETAILS[ReportReasonType::Spam->value],
            'metadata' => $this->sampleMetadata('10.0.0.1', 'Orphan-Test'),
            'created_at' => $orphanAt,
            'updated_at' => $orphanAt,
        ]);
        $this->backdate($orphanLog, $orphanAt);

        $caseCount++;
        $this->command->line("재접수/특수 케이스 생성 완료: {$caseCount}건");
    }

    /**
     * 신고할 대상(게시 상태·미삭제 글 또는 댓글)을 고릅니다. 이미 케이스가 있는 대상은 제외합니다.
     *
     * @param  Board  $board  게시판
     * @param  ReportType  $targetType  대상 유형
     * @param  bool  $guestOnly  비회원이 쓴 대상만
     * @param  bool  $deletable  삭제 조치가 가능한 대상만 (답글 삭제 정책 block 게시판의 답글 달린 글 제외)
     * @return object|null 대상 행
     */
    private function pickTarget(Board $board, ReportType $targetType, bool $guestOnly = false, bool $deletable = false): ?object
    {
        $table = $targetType === ReportType::Post ? 'board_posts' : 'board_comments';

        // 이번 실행에서 게시글 샘플 시더가 만든 글·댓글만 — 운영 글에 회원 명의 신고를 지어내지 않는다
        $range = SampleRunRegistry::range($table);
        if ($range === null) {
            return null;
        }

        $query = DB::table($table.' as t')
            ->where('t.board_id', $board->id)
            ->where('t.id', '>', $range[0])
            ->where('t.id', '<=', $range[1])
            ->where('t.status', 'published')
            ->whereNull('t.deleted_at');

        if ($targetType === ReportType::Comment) {
            // 댓글은 원글도 게시 상태여야 신고 화면에 닿는다
            $query->join('board_posts as p', 'p.id', '=', 't.post_id')
                ->where('p.status', 'published')
                ->whereNull('p.deleted_at');
        }

        if ($guestOnly) {
            $query->whereNull('t.user_id');
        }

        if ($deletable && $targetType === ReportType::Post && ($board->reply_delete_policy?->value ?? 'cascade') === 'block') {
            $query->whereNotExists(function ($sub) {
                $sub->from('board_posts as r')
                    ->whereColumn('r.parent_id', 't.id')
                    ->whereNull('r.deleted_at');
            });
        }

        $existing = Report::withTrashed()
            ->where('board_id', $board->id)
            ->where('target_type', $targetType)
            ->pluck('target_id')
            ->all();

        $candidates = $query->inRandomOrder()
            ->limit(50)
            ->get(['t.id', 't.user_id', 't.author_name', 't.created_at']);

        foreach ($candidates as $candidate) {
            $key = $board->id.':'.$targetType->value.':'.$candidate->id;
            if (isset($this->usedTargets[$key]) || in_array($candidate->id, $existing)) {
                continue;
            }
            $this->usedTargets[$key] = true;

            return $candidate;
        }

        return null;
    }

    /**
     * 신고자를 고릅니다 — 활성 회원 중 대상 작성자를 뺀다(본인 신고 불가).
     *
     * @param  Collection<int, User>  $users  활성 회원
     * @param  object  $target  대상 행
     * @param  int  $count  필요한 수
     * @return Collection<int, User> 신고자
     */
    private function pickReporters(Collection $users, object $target, int $count): Collection
    {
        return $users
            ->reject(fn (User $u) => $target->user_id !== null && (int) $u->id === (int) $target->user_id)
            ->shuffle()
            ->take(max(0, $count))
            ->values();
    }

    /**
     * 대상 스냅샷(작성자·제목·내용)을 만듭니다.
     *
     * @param  Board  $board  게시판
     * @param  ReportType  $targetType  대상 유형
     * @param  object  $target  대상 행
     * @return array<string, mixed> 스냅샷 데이터
     */
    private function snapshotOf(Board $board, ReportType $targetType, object $target): array
    {
        $table = $targetType === ReportType::Post ? 'board_posts' : 'board_comments';
        $record = DB::table($table)->where('id', $target->id)->first();

        $authorId = $record?->user_id;
        $authorUser = $authorId ? User::find($authorId) : null;
        $authorName = $record?->author_name ?: ($authorUser?->name ?? '알 수 없음');

        if ($targetType === ReportType::Post) {
            return [
                'author_id' => $authorUser?->id,
                'title' => $record?->title,
                'content' => strip_tags((string) $record?->content),
                'content_mode' => $record?->content_mode ?? 'text',
                'author_name' => $authorName,
            ];
        }

        $postTitle = $record?->post_id
            ? DB::table('board_posts')->where('board_id', $board->id)->where('id', $record->post_id)->value('title')
            : null;

        return [
            'author_id' => $authorUser?->id,
            'title' => $postTitle,
            'content' => $record?->content,
            'content_mode' => 'text',
            'author_name' => $authorName,
        ];
    }

    /**
     * 처리 이력 1건을 만듭니다 (ReportService::createHistoryEntry 와 같은 모양).
     *
     * @param  string  $action  reported, re_reported 또는 상태 값
     * @param  string|null  $reason  처리 사유
     * @param  int|null  $processorId  처리자 ID
     * @param  Carbon  $at  시각
     * @param  int|null  $reporterCount  사이클 누적 신고 수 (신고 항목만)
     * @return array<string, mixed> 처리 이력
     */
    private function historyEntry(string $action, ?string $reason, ?int $processorId, Carbon $at, ?int $reporterCount = null): array
    {
        $entry = [
            'type' => $action,
            'action_label' => ReportStatus::tryFrom($action)?->label() ?? $action,
            'processor_id' => $processorId,
            'reason' => $reason,
            'created_at' => $at->format('Y-m-d H:i:s'),
        ];

        if ($reporterCount !== null) {
            $entry['reporter_count'] = $reporterCount;
        }

        return $entry;
    }

    /**
     * created_at·updated_at 을 지정한 값 그대로 저장합니다.
     *
     * Report·ReportLog 는 created_at 을 대량 할당하지 않아 create() 에 넘긴 과거 시각이 버려지고
     * 지금 시각이 들어간다 — 그러면 처리 시각이 접수 시각보다 앞서는 역행이 생긴다(B-13).
     *
     * @param  Model  $model  저장된 모델
     * @param  Carbon  $createdAt  작성 시각
     * @param  Carbon|null  $updatedAt  수정 시각 (없으면 작성 시각)
     * @return Model 같은 모델
     */
    private function backdate($model, Carbon $createdAt, ?Carbon $updatedAt = null)
    {
        $model->timestamps = false;
        $model->forceFill(['created_at' => $createdAt, 'updated_at' => $updatedAt ?? $createdAt])->saveQuietly();
        $model->timestamps = true;

        return $model;
    }

    /**
     * 샘플 표식을 단 metadata 를 만듭니다.
     *
     * @param  string  $ip  IP
     * @param  string  $agent  user_agent 꼬리표
     * @return array<string, mixed> metadata
     */
    private function sampleMetadata(string $ip, string $agent): array
    {
        return [
            'ip' => $ip,
            'user_agent' => 'Mozilla/5.0 '.$agent,
            BoardSampleMarker::REPORT_METADATA_KEY => true,
        ];
    }

    /**
     * 기준 시각 뒤 1시간 ~ N시간 사이의 시각을 돌려줍니다 (지금을 넘지 않음).
     *
     * @param  Carbon  $base  기준 시각
     * @param  int  $maxHours  최대 지연 시간
     * @return Carbon 시각
     */
    private function randomTimeAfter(Carbon $base, int $maxHours): Carbon
    {
        return $this->clampToNow($base->copy()->addMinutes(rand(60, max(60, $maxHours * 60))));
    }

    /**
     * 시각이 지금을 넘으면 지금으로 맞춥니다.
     *
     * @param  Carbon  $time  시각
     * @return Carbon 지금 이하의 시각
     */
    private function clampToNow(Carbon $time): Carbon
    {
        $now = now();

        return $time->greaterThan($now) ? $now : $time;
    }
}
