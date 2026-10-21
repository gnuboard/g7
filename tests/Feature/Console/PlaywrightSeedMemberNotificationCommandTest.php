<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * playwright:seed-member-notification — 기존 회원(토큰 회원)에게 심기·이동 주소 지정·심은 알림만 지우기.
 *
 * 관리자 알림 센터 E2E 는 시험마다 새로 생기는 토큰 회원의 알림 센터를 본다. 고정 회원만 만들던 시드로는
 * 그 회원에게 알림을 넣을 수 없어 사례가 건너뛰어졌다. `--user` 로 그 회원에게 심고, 시험 뒤 `--teardown --user`
 * 가 심은 알림만 지워야 한다 — 회원이나 그 회원의 다른 알림을 지우면 다른 시험·실측을 흔든다.
 */
class PlaywrightSeedMemberNotificationCommandTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 옵트인 플래그를 켭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['G7_PLAYWRIGHT_BYPASS'] = '1';
        $_ENV['G7_PLAYWRIGHT_BYPASS'] = '1';
        putenv('G7_PLAYWRIGHT_BYPASS=1');
    }

    /**
     * 옵트인 플래그를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        unset($_SERVER['G7_PLAYWRIGHT_BYPASS'], $_ENV['G7_PLAYWRIGHT_BYPASS']);
        putenv('G7_PLAYWRIGHT_BYPASS');

        parent::tearDown();
    }

    /**
     * 커맨드를 JSON 모드로 실행하고 결과를 돌려줍니다.
     *
     * @param  array<string, mixed>  $options  커맨드 옵션
     * @return array{0: int, 1: array<string, mixed>} [종료 코드, JSON 결과]
     */
    private function runSeed(array $options): array
    {
        $code = Artisan::call('playwright:seed-member-notification', $options + ['--json' => true]);
        $lines = array_values(array_filter(
            preg_split('/\R/', trim(Artisan::output())) ?: [],
            fn (string $l) => str_starts_with(trim($l), '{')
        ));

        return [$code, $lines === [] ? [] : (json_decode(end($lines), true) ?? [])];
    }

    /**
     * 회원 수신함의 알림 행을 돌려줍니다.
     *
     * @param  User  $user  회원
     * @return Collection<int, object> 알림 행
     */
    private function inbox(User $user)
    {
        return DB::table((new DatabaseNotification)->getTable())
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->get();
    }

    /**
     * 기존 회원을 하나 만듭니다.
     *
     * @return User 회원
     */
    private function member(): User
    {
        return User::create([
            'name' => '토큰회원E2E',
            'email' => 'e2e-token-'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('x'),
        ]);
    }

    /**
     * --user(uuid) 는 새 회원을 만들지 않고 그 회원에게 3건을 심는다 — 이동 주소 기본값은 종전과 같다.
     *
     * @scenario target=existing_member, op=seed
     *
     * @effects seeds_into_existing_member, click_url_is_configurable
     *
     * @return void 반환값 없음
     */
    public function test_seeds_into_existing_member_by_uuid(): void
    {
        $member = $this->member();
        $usersBefore = User::count();

        [$code, $out] = $this->runSeed(['--user' => $member->uuid]);

        $this->assertSame(0, $code);
        $this->assertSame($usersBefore, User::count(), '새 회원을 만들면 안 된다');
        $this->assertSame($member->uuid, $out['member_uuid'] ?? null);
        $this->assertFalse($out['member_created'] ?? true);
        $this->assertCount(3, $out['notification_ids'] ?? []);
        $this->assertSame('/mypage/notifications', $out['url'] ?? null);

        $rows = $this->inbox($member);
        $this->assertCount(3, $rows);
        $this->assertSame(2, $rows->whereNull('read_at')->count());
        foreach ($rows as $row) {
            $this->assertSame('/mypage/notifications', json_decode($row->data, true)['click_url']);
        }
    }

    /**
     * --user 는 이메일로도 찾고, --url 은 알림 이동 주소를 바꾼다.
     *
     * @scenario target=existing_member, op=seed
     *
     * @effects seeds_into_existing_member, click_url_is_configurable
     *
     * @return void 반환값 없음
     */
    public function test_url_option_sets_click_url_and_email_lookup_works(): void
    {
        $member = $this->member();

        [$code, $out] = $this->runSeed(['--user' => $member->email, '--url' => '/admin/users']);

        $this->assertSame(0, $code);
        $this->assertSame('/admin/users', $out['url'] ?? null);
        foreach ($this->inbox($member) as $row) {
            $this->assertSame('/admin/users', json_decode($row->data, true)['click_url']);
        }
    }

    /**
     * --teardown --user 는 심은 알림만 지운다 — 회원과 그 회원의 다른 알림은 남는다. 재실행은 멱등.
     *
     * @scenario target=existing_member, op=teardown
     *
     * @effects teardown_removes_only_seeded_notifications
     *
     * @return void 반환값 없음
     */
    public function test_teardown_with_user_removes_only_seeded_notifications(): void
    {
        $member = $this->member();
        $ownId = (string) Str::uuid();
        DB::table((new DatabaseNotification)->getTable())->insert([
            'id' => $ownId,
            'type' => 'App\\Notifications\\GenericNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $member->id,
            'data' => json_encode(['subject' => '원래 알림', 'body' => '지우면 안 된다', 'click_url' => '/x']),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runSeed(['--user' => $member->uuid]);
        $this->runSeed(['--user' => $member->uuid]);
        $this->assertCount(4, $this->inbox($member), '재실행은 앞서 심은 3건을 갈아 끼운다(멱등)');

        [$code, $out] = $this->runSeed(['--user' => $member->uuid, '--teardown' => true]);

        $this->assertSame(0, $code);
        $this->assertSame(3, $out['deleted_notifications'] ?? null);
        $this->assertSame(0, $out['deleted_users'] ?? null);
        $this->assertNotNull(User::find($member->id), '회원은 남아야 한다');
        $this->assertSame([$ownId], $this->inbox($member)->pluck('id')->all(), '원래 알림만 남아야 한다');
    }

    /**
     * 없는 회원을 주면 실패 코드로 끝나고 아무것도 만들지 않는다.
     *
     * @scenario target=existing_member, op=seed
     *
     * @effects unknown_user_fails_without_side_effects
     *
     * @return void 반환값 없음
     */
    public function test_unknown_user_fails_without_creating_anything(): void
    {
        $usersBefore = User::count();
        $notificationsBefore = DB::table((new DatabaseNotification)->getTable())->count();

        $code = Artisan::call('playwright:seed-member-notification', ['--user' => 'no-such-user@example.test']);

        $this->assertSame(1, $code);
        $this->assertSame($usersBefore, User::count());
        $this->assertSame($notificationsBefore, DB::table((new DatabaseNotification)->getTable())->count());
    }

    /**
     * --user 없이는 종전대로 고정 회원을 만들고 --teardown 이 그 회원까지 지운다(회귀 고정).
     *
     * @scenario target=fixed_member, op=seed
     *
     * @effects fixed_member_flow_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_default_flow_creates_and_tears_down_fixed_member(): void
    {
        [$code, $out] = $this->runSeed([]);

        $this->assertSame(0, $code);
        $this->assertTrue($out['member_created'] ?? false);
        $member = User::where('email', 'e2e-inbox-member@example.test')->first();
        $this->assertNotNull($member);
        $this->assertCount(3, $this->inbox($member));

        [$code, $out] = $this->runSeed(['--teardown' => true]);

        $this->assertSame(0, $code);
        $this->assertSame(3, $out['deleted_notifications'] ?? null);
        $this->assertSame(1, $out['deleted_users'] ?? null);
        $this->assertNull(User::where('email', 'e2e-inbox-member@example.test')->first());
    }
}
