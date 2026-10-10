<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Database\Seeders\Sample;

use App\Extension\Helpers\NotificationSyncHelper;
use App\Extension\ModuleManager;
use App\Models\NotificationDefinition;
use App\Models\NotificationLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\NotificationDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\NotificationLogSeeder;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 NotificationLogSeeder 통합 테스트.
 *
 * extension_identifier='sirsoft-ecommerce' 정의만 채워지는지 검증.
 */
class NotificationLogSeederTest extends ModuleTestCase
{
    /**
     * 회원·관리자와 실제 주문(구매확정·배송완료·결제 후 취소)을 준비합니다.
     *
     * @return void 반환값 없음
     */
    private function bootstrap(): void
    {
        // 테스트 격리: 다른 테스트 클래스가 트랜잭션 외부에서 남긴 잔여 데이터 정리.
        NotificationLog::query()->delete();
        NotificationDefinition::query()->delete();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(NotificationDefinitionSeeder::class);
        $this->syncEcommerceNotificationDefinitions();

        $userRole = Role::query()->where('identifier', 'user')->firstOrFail();
        User::factory()
            ->count(15)
            ->create()
            ->each(fn (User $u) => $u->roles()->attach($userRole->id, ['assigned_at' => now()]));
    }

    /**
     * 기본 건수만큼 발송 이력을 만든다.
     *
     * @scenario seeder=notification
     *
     * @effects default_count
     *
     * @return void 반환값 없음
     */
    public function test_seeder_creates_default_count(): void
    {
        $this->bootstrap();

        $this->seed(NotificationLogSeeder::class);

        $this->assertSame(160, NotificationLog::count(), '기본 160건이 생성되어야 합니다 (정의 55종 미만이면 정의마다 1건 이상)');
    }

    /**
     * 이커머스 영역 이력만 만든다.
     *
     * @scenario seeder=notification
     *
     * @effects ecommerce_scope_only
     *
     * @return void 반환값 없음
     */
    public function test_seeder_only_seeds_ecommerce_scope(): void
    {
        $this->bootstrap();

        $this->seed(NotificationLogSeeder::class);

        $ecommerce = NotificationLog::query()
            ->where('extension_identifier', 'sirsoft-ecommerce')
            ->count();
        $other = NotificationLog::query()
            ->where('extension_identifier', '!=', 'sirsoft-ecommerce')
            ->count();

        $this->assertSame(160, $ecommerce, '이커머스 영역만 채워야 합니다');
        $this->assertSame(0, $other, '코어/타 모듈 영역은 채우지 않아야 합니다');
    }

    /**
     * 이커머스 알림 정의만 쓴다.
     *
     * @scenario seeder=notification
     *
     * @effects ecommerce_definitions_only
     *
     * @return void 반환값 없음
     */
    public function test_seeder_uses_only_ecommerce_definitions(): void
    {
        $this->bootstrap();

        $this->seed(NotificationLogSeeder::class);

        $ecommerceTypes = NotificationDefinition::query()
            ->where('extension_identifier', 'sirsoft-ecommerce')
            ->pluck('type')
            ->all();

        foreach (NotificationLog::query()->get() as $log) {
            $this->assertContains(
                $log->notification_type,
                $ecommerceTypes,
                "type={$log->notification_type} 는 이커머스 정의여야 합니다",
            );
        }
    }

    /**
     * 건수 옵션을 따른다.
     *
     * @scenario seeder=notification
     *
     * @effects count_option
     *
     * @return void 반환값 없음
     */
    public function test_seeder_respects_count_option(): void
    {
        $this->bootstrap();

        $seeder = $this->app->make(NotificationLogSeeder::class);
        $seeder->setSeederCounts(['ecommerce_notification_logs' => 25]);
        $seeder->setCommand($this->createMockCommand());
        $seeder->run();

        $this->assertSame(25, NotificationLog::count());
    }

    /**
     * 기본 건수면 시연 대상 정의(본인인증 목적·운영자 직접 메일 제외)마다 이력이 생기고,
     * 관리자 알림은 관리자에게 가며, 템플릿 변수가 남지 않는다 (E-14).
     *
     * @return void 반환값 없음
     *
     * @scenario seeder=notification
     *
     * @effects every_definition_with_template_text
     */
    public function test_seeder_covers_every_definition_with_template_text(): void
    {
        $this->bootstrap();
        $adminRole = Role::query()->where('identifier', 'admin')->firstOrFail();
        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole->id, ['assigned_at' => now()]);

        $this->seed(NotificationLogSeeder::class);

        $expected = NotificationDefinition::query()
            ->where('extension_identifier', 'sirsoft-ecommerce')
            ->where('is_active', true)
            ->where('type', 'not like', 'identity.purpose.%')
            ->where('type', '!=', 'order_custom_email')
            ->pluck('type')
            ->all();
        $seeded = NotificationLog::query()->distinct()->pluck('notification_type')->all();

        $this->assertEqualsCanonicalizing($expected, $seeded, '시연 대상 정의마다 발송 이력이 있어야 합니다');

        foreach (NotificationLog::query()->where('notification_type', 'like', '%\_admin')->get() as $log) {
            $this->assertSame($admin->id, (int) $log->recipient_user_id, "관리자 알림({$log->notification_type})은 관리자에게 보낸 이력이어야 합니다");
        }

        $leftover = NotificationLog::query()->get(['subject', 'body'])
            ->filter(fn ($log) => preg_match('/\{[a-z_]+\}/', (string) $log->subject.(string) $log->body) === 1)
            ->count();
        $this->assertSame(0, $leftover, '제목·본문에 치환되지 않은 템플릿 변수가 없어야 합니다');
    }

    /**
     * 시더 run() 내부의 $this->command->info() 호출용 더미 커맨드.
     *
     * @return Command 더미 커맨드 인스턴스
     */
    private function createMockCommand(): Command
    {
        return new class extends Command
        {
            protected $signature = 'test:dummy';

            /**
             * 출력을 버립니다.
             *
             * @param  mixed  $string  출력 문구
             * @param  mixed  $verbosity  출력 상세도
             * @return void 반환값 없음
             */
            public function info($string, $verbosity = null): void {}

            /**
             * 출력을 버립니다.
             *
             * @param  mixed  $string  출력 문구
             * @param  mixed  $verbosity  출력 상세도
             * @return void 반환값 없음
             */
            public function warn($string, $verbosity = null): void {}
        };
    }

    /**
     * module.php::getNotificationDefinitions() SSoT 기반으로 이커머스 알림 정의 시드.
     *
     * @return void 반환값 없음
     */
    private function syncEcommerceNotificationDefinitions(): void
    {
        $module = app(ModuleManager::class)->getModule('sirsoft-ecommerce');
        if (! $module) {
            return;
        }

        $helper = app(NotificationSyncHelper::class);
        foreach ($module->getNotificationDefinitions() as $data) {
            $data['extension_type'] = 'module';
            $data['extension_identifier'] = 'sirsoft-ecommerce';
            $definition = $helper->syncDefinition($data);
            foreach ($data['templates'] ?? [] as $template) {
                $helper->syncTemplate($definition->id, $template);
            }
        }
    }

    /**
     * 테스트 정리
     *
     * 이 클래스는 트랜잭션 롤백 없이(ModuleTestCase 기본) 시더로 알림 로그를 커밋한다.
     * 정리하지 않으면 이후 실행되는 다른 테스트 클래스가 이 잔여 행을 함께 세어
     * 건수 단언이 깨진다 (예: NotificationLogServiceTest 의 채널 필터 건수).
     *
     * 알림 정의(NotificationDefinition)는 지우지 않는다 — 트랜잭션을 쓰지 않는 다른 테스트
     * 클래스들이 시드된 정의를 공유 전제로 삼고 있어, 함께 지우면 그쪽이 깨진다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        NotificationLog::query()->delete();

        parent::tearDown();
    }
}
