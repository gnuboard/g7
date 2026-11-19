<?php

namespace Database\Seeders\Sample;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\SettingsService;
use App\Services\UserService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * 이미지 썸네일 브라우저 실측 — 코어 데이터 정리 (이슈 #869).
 *
 * `ThumbnailMeasureSeeder` 가 만든 것만 지운다:
 *   - 실측 로고 첨부(원래 파일명 `ThumbnailMeasureSeeder::LOGO_FILENAME`) — `AttachmentService::delete` 로 지워
 *     저장소 드라이버 delete 경로가 파생본까지 지우는 것을 함께 실측한다. 그 뒤 사이트 로고 설정을 백업 값으로 되돌린다.
 *   - 실측 회원 2명 — 관리자 화면과 같은 `UserService::deleteUser`(역할, 동의, 토큰 정리 + 아바타 파일 삭제).
 *   - 이 회원들에게 쌓인 데이터베이스 알림과, 지운 행을 가리키는 활동 로그.
 *
 * 순서: 확장 정리 시더(게시판, 이커머스)를 **먼저** 돌린다. 그쪽 데이터가 실측 회원을 작성자로 가리킨다.
 * 순서가 뒤바뀌어도 확장 정리 시더는 회원이 아니라 자기 표식(게시판 슬러그, 상품 코드, 주문번호 접두)으로
 * 대상을 찾으므로 찌꺼기가 영구히 남지는 않는다.
 *
 * 실행 (PowerShell):
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan db:seed --class="Database\Seeders\Sample\ThumbnailMeasureCleanupSeeder"
 */
class ThumbnailMeasureCleanupSeeder extends Seeder
{
    /**
     * 시더를 실행합니다.
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        $this->command?->info(__('common.console.seeders.thumbnail_measure.cleanup_start'));

        ThumbnailMeasureKit::guard();

        $kit = new ThumbnailMeasureKit;
        $kit->silenceOutbound();
        Auth::setUser(ThumbnailMeasureKit::superAdmin());

        $counts = ['logo_attachments' => 0, 'logo_restored' => __('common.console.seeders.thumbnail_measure.logo_restore.no'), 'members' => 0, 'avatar_attachments' => 0, 'notifications' => 0, 'activity_logs' => 0];

        [$counts['logo_attachments'], $counts['logo_restored']] = $this->restoreLogo();

        $members = User::query()
            ->whereIn('email', [ThumbnailMeasureKit::AUTHOR_EMAIL, ThumbnailMeasureKit::OTHER_EMAIL])
            ->get();

        foreach ($members as $member) {
            $avatarIds = Attachment::query()
                ->where('attachmentable_type', User::class)
                ->where('attachmentable_id', $member->id)
                ->pluck('id')
                ->all();
            $morph = $member->getMorphClass();
            $memberId = $member->id;

            app(UserService::class)->deleteUser($member);

            $counts['members']++;
            $counts['avatar_attachments'] += count($avatarIds);

            if (Schema::hasTable('notifications')) {
                $counts['notifications'] += DB::table('notifications')
                    ->where('notifiable_type', $morph)
                    ->where('notifiable_id', $memberId)
                    ->delete();
            }

            $counts['activity_logs'] += ActivityLog::query()
                ->where(fn ($q) => $q->where('loggable_type', $morph)->where('loggable_id', $memberId))
                ->orWhere(fn ($q) => $q->where('loggable_type', (new Attachment)->getMorphClass())->whereIn('loggable_id', $avatarIds ?: [0]))
                ->delete();
        }

        ThumbnailMeasureKit::table(
            $this->command,
            __('common.console.seeders.thumbnail_measure.cleanup_title'),
            ThumbnailMeasureKit::labels(array_keys($counts), __('common.console.seeders.thumbnail_measure.cleanup_headers')),
            [array_values($counts)]
        );

        $this->command?->info(__('common.console.seeders.thumbnail_measure.cleanup_done'));
    }

    /**
     * 실측 로고를 지우고 사이트 로고 설정을 백업 값으로 되돌립니다.
     *
     * 백업이 있으면 백업 값으로, 없으면 지금 설정값으로 시작해 어느 쪽이든 실측 로고 첨부 ID 만 빼고 저장한다.
     * 백업이 없을 때 설정을 그대로 두면 아래에서 지운 첨부 ID 가 `general.site_logo` 에 남아 로고 자리가 깨진다.
     *
     * @return array{0: int, 1: string} [지운 실측 로고 첨부 수, 복원 결과]
     */
    private function restoreLogo(): array
    {
        $config = app(ConfigRepositoryInterface::class);
        $backupPath = storage_path(ThumbnailMeasureSeeder::LOGO_BACKUP);
        $measureIds = Attachment::query()
            ->where('collection', 'site_logo')
            ->where('original_filename', ThumbnailMeasureSeeder::LOGO_FILENAME)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (File::exists($backupPath)) {
            $backup = json_decode((string) File::get($backupPath), true);
            $siteLogo = is_array($backup['site_logo'] ?? null) ? $backup['site_logo'] : [];
            $restored = __('common.console.seeders.thumbnail_measure.logo_restore.yes');
        } else {
            $current = $config->get('general.site_logo', []);
            $siteLogo = is_array($current) ? $current : [];
            $restored = __('common.console.seeders.thumbnail_measure.logo_restore.no_backup');
        }

        $config->set('general.site_logo', self::withoutAttachments($siteLogo, $measureIds));
        app(SettingsService::class)->invalidateSettingsCache();
        File::delete([$backupPath, storage_path(ThumbnailMeasureSeeder::GENERAL_BACKUP)]);

        // 설정에서 뺀 뒤에 지운다 — 반대 순서면 잠시 동안 설정이 없는 첨부를 가리킨다
        $attachments = app(AttachmentService::class);
        $deleted = 0;

        foreach ($measureIds as $id) {
            if ($attachments->delete($id)) {
                $deleted++;
            }
        }

        return [$deleted, $restored];
    }

    /**
     * 사이트 로고 설정값에서 주어진 첨부 ID 를 뺍니다 (항목은 ID 숫자 또는 `id` 키가 있는 배열).
     *
     * @param  array<int, mixed>  $siteLogo  설정값
     * @param  list<int>  $attachmentIds  뺄 첨부 ID
     * @return list<mixed> 남은 항목
     */
    private static function withoutAttachments(array $siteLogo, array $attachmentIds): array
    {
        return array_values(array_filter($siteLogo, function ($item) use ($attachmentIds) {
            $id = is_array($item) ? ($item['id'] ?? null) : $item;

            return ! (is_numeric($id) && in_array((int) $id, $attachmentIds, true));
        }));
    }
}
