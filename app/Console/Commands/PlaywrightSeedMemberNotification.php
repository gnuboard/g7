<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Playwright E2E · 브라우저 실측용 회원 알림함 시드 커맨드 (#524 WP-F).
 *
 * 관리자 회원 알림함은 «읽음/안 읽음» 과 «제목/본문 검색» 을 함께 다룬다. 그 넷을 한 화면에서
 * 확인하려면 회원 하나에 읽은 알림과 안 읽은 알림이 섞여 있고, 제목에만 있는 낱말과 본문에만
 * 있는 낱말이 갈라져 있어야 한다 — 두 낱말이 겹치면 제목 검색과 본문 검색을 구분할 수 없다.
 *
 * 만드는 것:
 *   - 회원 하나(고정 이메일)
 *   - `notifications` 3건 — 안 읽음 2 + 읽음 1.
 *     제목 낱말은 「델타 알림제목」, 본문 낱말은 「엡실론 알림본문」 으로 갈라 넣는다.
 *
 * 재실행하면 같은 계정과 그 알림을 먼저 지우고 다시 만든다(멱등).
 *
 * 보안 가드 (PlaywrightIssueToken 과 동일 3중 패턴):
 *   ① CLI 한정 · ② G7_PLAYWRIGHT_BYPASS=1 · ③ APP_DEBUG=true inline override
 *
 * 호출 예시:
 *   G7_PLAYWRIGHT_BYPASS=1 php artisan playwright:seed-member-notification --json
 *   G7_PLAYWRIGHT_BYPASS=1 php artisan playwright:seed-member-notification --teardown
 */
class PlaywrightSeedMemberNotification extends Command
{
    /**
     * 고정 회원 이메일 — 재실행 시 같은 계정을 지우고 다시 만든다.
     */
    private const EMAIL = 'e2e-inbox-member@example.test';

    /**
     * 검색 구분 검증용 낱말 — 제목과 본문에 겹치지 않게 갈라 넣는다.
     */
    private const SUBJECT_WORD = '델타 알림제목';

    private const BODY_WORD = '엡실론 알림본문';

    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'playwright:seed-member-notification
        {--teardown : 시드한 데이터를 전량 삭제한다}
        {--json : 결과를 JSON 으로 출력}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = 'Playwright E2E · 실측용 회원 알림함 시드 (CLI + G7_PLAYWRIGHT_BYPASS 가드)';

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        if (php_sapi_name() !== 'cli') {
            $this->error('CLI 전용 커맨드입니다.');

            return self::FAILURE;
        }

        if (env('G7_PLAYWRIGHT_BYPASS') !== '1') {
            $this->error('G7_PLAYWRIGHT_BYPASS=1 환경변수가 필요합니다.');

            return self::FAILURE;
        }

        Config::set('app.debug', true);

        $result = $this->option('teardown') ? $this->teardown() : $this->seed();

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($result as $key => $value) {
                $this->line($key.': '.(is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)));
            }
        }

        return self::SUCCESS;
    }

    /**
     * 시드 데이터를 만듭니다.
     *
     * @return array<string, mixed> 만들어진 식별자 모음
     */
    private function seed(): array
    {
        return DB::transaction(function () {
            $this->purge();

            $member = User::create([
                'name' => '알림함회원E2E',
                'email' => self::EMAIL,
                'password' => Hash::make('e2e-inbox-password'),
                'email_verified_at' => now(),
            ]);

            $ids = [
                $this->notify($member->id, self::SUBJECT_WORD.' 1', '평범한 본문입니다.', null),
                $this->notify($member->id, '다른 제목 2', self::BODY_WORD.' 이 든 본문입니다.', null),
                $this->notify($member->id, '다른 제목 3', '이미 읽은 알림입니다.', now()->subHour()),
            ];

            return [
                'member_uuid' => $member->uuid,
                'member_email' => $member->email,
                'notification_ids' => $ids,
                'unread_count' => 2,
                'total_count' => 3,
                'subject_keyword' => self::SUBJECT_WORD,
                'body_keyword' => self::BODY_WORD,
            ];
        });
    }

    /**
     * 시드 데이터를 전량 삭제합니다.
     *
     * @return array<string, int> 삭제 건수
     */
    private function teardown(): array
    {
        return DB::transaction(fn () => $this->purge());
    }

    /**
     * 고정 계정과 그 알림을 지웁니다 (재실행 멱등의 핵심).
     *
     * @return array<string, int> 삭제 건수
     */
    private function purge(): array
    {
        $userId = DB::table((new User)->getTable())->where('email', self::EMAIL)->value('id');

        if ($userId === null) {
            return ['deleted_notifications' => 0, 'deleted_users' => 0];
        }

        return [
            'deleted_notifications' => DB::table((new DatabaseNotification)->getTable())
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $userId)
                ->delete(),
            'deleted_users' => DB::table((new User)->getTable())->where('id', $userId)->delete(),
        ];
    }

    /**
     * 회원 수신함에 알림 한 건을 넣습니다.
     *
     * 알림 클래스를 실제로 발송하지 않고 행을 직접 넣는다 — 발송 경로(채널·템플릿)는 이 화면의
     * 관심사가 아니고, 그쪽은 발송 이력 테스트가 이미 잰다.
     *
     * @param  int  $userId  수신 회원 ID
     * @param  string  $subject  제목
     * @param  string  $body  본문
     * @param  Carbon|null  $readAt  읽은 시각 (null 이면 안 읽음)
     * @return string 알림 uuid
     */
    private function notify(int $userId, string $subject, string $body, $readAt): string
    {
        $id = (string) Str::uuid();

        DB::table((new DatabaseNotification)->getTable())->insert([
            'id' => $id,
            'type' => 'App\\Notifications\\GenericNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $userId,
            'data' => json_encode([
                'subject' => $subject,
                'body' => $body,
                'click_url' => '/mypage/notifications',
            ], JSON_UNESCAPED_UNICODE),
            'read_at' => $readAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
