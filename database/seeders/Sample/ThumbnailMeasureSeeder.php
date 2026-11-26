<?php

namespace Database\Seeders\Sample;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Enums\AttachmentSourceType;
use App\Enums\UserStatus;
use App\Models\Attachment;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\SettingsService;
use App\Services\UserService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * 이미지 썸네일 브라우저 실측 — 코어 데이터.
 *
 * 개발 DB 전용 샘플 시더다. `--sample` 묶음에 넣지 않으므로 `--class` 로 지정했을 때만 돈다.
 *
 * 만드는 것:
 *   - 회원 2명 — 작성자(`ThumbnailMeasureKit::AUTHOR_EMAIL`), 제3자(`OTHER_EMAIL`). 둘 다 2000×2000 JPEG 아바타.
 *     작성자는 게시판 비밀글, 이커머스 리뷰, 클레임의 주인이 되고, 제3자는 남의 권한 이미지가 거부되는지 잰다.
 *     비밀번호는 실행마다 새로 만들어 콘솔에 찍는다(개발 DB 전용).
 *   - 사이트 로고 교체 — 원래 설정값을 `storage/app/measure/` 에 백업한 뒤 2400×800 PNG 로고로 바꾼다.
 *     원래 로고 첨부는 지우지 않는다(설정 저장 경로 `SettingsService::saveSettings` 를 쓰면 빠진 로고 첨부를
 *     파기하므로, 설정 값만 저장소로 직접 바꾼다). 복원은 `ThumbnailMeasureCleanupSeeder` 가 한다.
 *
 * 업로드는 운영 경로(`AttachmentService::upload` → 저장소 드라이버 put)를 그대로 지난다 — 업로드 설정의
 * 최대 가로, 세로(기본 2000)도 그대로 적용되므로, 2400×800 로고는 저장 시 2000×667 로 줄어든다(아래 표에
 * 저장 크기를 그대로 찍는다). 업로드 시점의 썸네일 생성 예약, 덮어쓰기 무효화까지 실측 대상이다.
 *
 * 재실행: 회원은 다시 만들지 않고(확장 실측 데이터가 그 회원을 가리킨다) 비밀번호, 아바타만 새로 한다.
 * 로고 백업은 처음 한 번만 쓴다 — 두 번째 실행이 실측 로고를 「원래 값」으로 덮어쓰지 않게.
 *
 * 실행 (PowerShell):
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan db:seed --class="Database\Seeders\Sample\ThumbnailMeasureSeeder"
 */
class ThumbnailMeasureSeeder extends Seeder
{
    /** 로고 백업 파일 (storage 기준) — 정리 시더가 읽는다 */
    public const LOGO_BACKUP = 'app/measure/thumbnail-measure-logo-backup.json';

    /** 백업 시점 general 설정 파일 사본 (수동 복구용) */
    public const GENERAL_BACKUP = 'app/measure/thumbnail-measure-general.json.bak';

    /** 실측 로고 첨부의 원래 파일명 접두 — 정리가 실측 로고만 골라 지운다 */
    public const LOGO_FILENAME = 'thumb-measure-logo-2400x800.png';

    /** 실측 아바타 첨부의 원래 파일명 */
    public const AVATAR_FILENAME = 'thumb-measure-avatar-2000.jpg';

    /**
     * 시더를 실행합니다.
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        $this->command?->info(__('common.console.seeders.thumbnail_measure.start'));

        ThumbnailMeasureKit::guard();

        $kit = new ThumbnailMeasureKit;
        $kit->silenceOutbound();

        $admin = ThumbnailMeasureKit::superAdmin();

        try {
            $members = [
                $this->member($kit, $admin, ThumbnailMeasureKit::AUTHOR_EMAIL, ThumbnailMeasureKit::MARK.' 작성자', 'thumbauthor', [37, 99, 235], 'AUTHOR AVATAR'),
                $this->member($kit, $admin, ThumbnailMeasureKit::OTHER_EMAIL, ThumbnailMeasureKit::MARK.' 제3자', 'thumbother', [22, 163, 74], 'OTHER AVATAR'),
            ];

            Auth::setUser($admin);
            $logo = $this->replaceLogo($kit);
        } finally {
            $kit->cleanup();
            Auth::forgetUser();
        }

        ThumbnailMeasureKit::table(
            $this->command,
            __('common.console.seeders.thumbnail_measure.members_title'),
            array_values((array) __('common.console.seeders.thumbnail_measure.members_headers')),
            array_map(fn (array $m) => self::memberRow($m['role'], $m['user'], $m['password'], $m['avatar']), $members)
        );

        ThumbnailMeasureKit::table(
            $this->command,
            __('common.console.seeders.thumbnail_measure.logo_title'),
            array_values((array) __('common.console.seeders.thumbnail_measure.logo_headers')),
            [self::logoRow($logo, storage_path(self::LOGO_BACKUP))]
        );

        $this->command?->info(__('common.console.seeders.thumbnail_measure.done'));
    }

    /**
     * 실측 회원 표의 한 행을 만듭니다 (열 순서는 `members_headers` 와 같다).
     *
     * @param  string  $role  역할 표시 이름
     * @param  object  $user  회원 (id, email)
     * @param  string  $password  이번 실행에서 정한 비밀번호
     * @param  object  $avatar  아바타 첨부 (hash, meta, download_url)
     * @return list<scalar|null> 행
     */
    public static function memberRow(string $role, object $user, string $password, object $avatar): array
    {
        return [
            $role,
            $user->id,
            $user->email,
            $password,
            $avatar->hash,
            ($avatar->meta['width'] ?? '?').'x'.($avatar->meta['height'] ?? '?'),
            $avatar->download_url,
        ];
    }

    /**
     * 사이트 로고 표의 한 행을 만듭니다 (열 순서는 `logo_headers` 와 같다).
     *
     * @param  object  $logo  로고 첨부 (id, hash, meta, download_url)
     * @param  string  $backup  원래 설정 백업 파일 경로
     * @return list<scalar|null> 행
     */
    public static function logoRow(object $logo, string $backup): array
    {
        return [
            $logo->id,
            $logo->hash,
            ($logo->meta['width'] ?? '?').'x'.($logo->meta['height'] ?? '?'),
            $logo->download_url,
            $backup,
        ];
    }

    /**
     * 실측 회원을 만들거나(없으면) 비밀번호, 아바타를 새로 합니다.
     *
     * 회원 생성은 관리자 화면과 같은 `UserService::createUser` 를 지난다(기본 역할 `user` 자동 부여, 생성 훅).
     * 그 경로는 「다음 로그인 때 비밀번호 변경」 을 켜므로 실측 로그인이 막히지 않게 끈다.
     *
     * @param  ThumbnailMeasureKit  $kit  실측 도구
     * @param  User  $admin  회원을 만드는 관리자 (활동 로그의 행위자)
     * @param  string  $email  이메일
     * @param  string  $name  이름 (표지 포함)
     * @param  string  $nickname  닉네임
     * @param  array{0:int,1:int,2:int}  $rgb  아바타 바탕색
     * @param  string  $label  아바타 표식
     * @return array{role: string, user: User, password: string, avatar: Attachment} 결과
     */
    private function member(ThumbnailMeasureKit $kit, User $admin, string $email, string $name, string $nickname, array $rgb, string $label): array
    {
        $password = 'Thm-'.Str::random(12);
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            Auth::setUser($admin);
            $user = app(UserService::class)->createUser([
                'name' => $name,
                'nickname' => $nickname,
                'email' => $email,
                'password' => $password,
                'language' => 'ko',
                'status' => UserStatus::Active->value,
            ]);
        }

        $user->forceFill([
            'password' => $password,
            'email_verified_at' => now(),
            'password_changed_at' => now(),
            'password_change_required' => false,
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        // 아바타는 회원 본인이 올린 것으로 남긴다 — 프로필 화면 업로드(ProfileController::uploadAvatar)와 같은 순서
        Auth::setUser($user);
        $attachments = app(AttachmentService::class);

        if ($existing = $user->avatarAttachment()->first()) {
            $attachments->delete($existing->id);
        }

        $avatar = $attachments->upload(
            $kit->file($kit->jpeg(2000, 2000, $rgb, $label), self::AVATAR_FILENAME),
            User::class,
            $user->id,
            'avatar',
            AttachmentSourceType::Core
        );

        return [
            'role' => $email === ThumbnailMeasureKit::AUTHOR_EMAIL
                ? __('common.console.seeders.thumbnail_measure.roles.author')
                : __('common.console.seeders.thumbnail_measure.roles.other'),
            'user' => $user->fresh(),
            'password' => $password,
            'avatar' => $avatar->fresh(),
        ];
    }

    /**
     * 사이트 로고를 실측 로고로 바꿉니다 (원래 값은 처음 한 번만 백업).
     *
     * 주의: 원래 로고 첨부는 소유자 없는 첨부라 설정에서 빠져 있는 동안 고아 정리 판정에서 보호되지 않는다
     * (`AttachmentService::protectedAttachmentIds` 는 현재 설정값만 본다). 실측 기간에 고아 정리를 돌리지 말고,
     * 끝나면 바로 정리 시더로 복원한다.
     *
     * @param  ThumbnailMeasureKit  $kit  실측 도구
     * @return Attachment 실측 로고 첨부
     */
    private function replaceLogo(ThumbnailMeasureKit $kit): Attachment
    {
        $config = app(ConfigRepositoryInterface::class);
        $attachments = app(AttachmentService::class);
        $current = $config->get('general.site_logo', []);
        $backupPath = storage_path(self::LOGO_BACKUP);

        if (! File::exists($backupPath)) {
            File::ensureDirectoryExists(dirname($backupPath));
            $originalIds = array_values(array_filter(array_map(
                fn ($item) => is_array($item) ? ($item['id'] ?? null) : $item,
                is_array($current) ? $current : []
            ), 'is_numeric'));

            File::put($backupPath, json_encode([
                'site_logo' => $current,
                'original_attachments' => Attachment::query()->whereIn('id', $originalIds)->get(['id', 'hash', 'original_filename'])->toArray(),
                'saved_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $generalFile = storage_path('app/settings/general.json');
            if (File::exists($generalFile)) {
                File::copy($generalFile, storage_path(self::GENERAL_BACKUP));
            }
        }

        // 이전 실행이 올린 실측 로고는 저장소 드라이버 delete 경로로 지운다 (파생본도 함께 사라진다)
        Attachment::query()
            ->where('collection', 'site_logo')
            ->where('original_filename', self::LOGO_FILENAME)
            ->pluck('id')
            ->each(fn ($id) => $attachments->delete((int) $id));

        $logo = $attachments->upload(
            $kit->file($kit->png(2400, 800, [234, 88, 12], 'THUMB MEASURE LOGO 2400x800', false), self::LOGO_FILENAME),
            null,
            null,
            'site_logo',
            AttachmentSourceType::Core
        );

        $config->set('general.site_logo', [$logo->id]);
        app(SettingsService::class)->invalidateSettingsCache();

        return $logo->fresh();
    }
}
