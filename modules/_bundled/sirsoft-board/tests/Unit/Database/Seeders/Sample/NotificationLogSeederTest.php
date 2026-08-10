<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Database\Seeders\Sample;

use App\Extension\Helpers\NotificationSyncHelper;
use App\Extension\ModuleManager;
use App\Models\NotificationDefinition;
use App\Models\NotificationLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\NotificationDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Modules\Sirsoft\Board\Database\Seeders\Sample\NotificationLogSeeder;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 NotificationLogSeeder 통합 테스트.
 *
 * extension_identifier='sirsoft-board' 정의만 채워지는지 검증.
 */
class NotificationLogSeederTest extends ModuleTestCase
{
    /**
     * 이 테스트가 커밋한 사용자 ID (tearDown 에서 정리)
     *
     * @var array<int, int>
     */
    private array $seededUserIds = [];

    private function bootstrap(): void
    {
        // 테스트 격리: 다른 테스트 클래스가 트랜잭션 외부에서 남긴 잔여 데이터 정리.
        NotificationLog::query()->delete();
        NotificationDefinition::query()->delete();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(NotificationDefinitionSeeder::class);
        $this->syncBoardNotificationDefinitions();

        $userRole = Role::query()->where('identifier', 'user')->firstOrFail();

        // 이메일은 팩토리 기본값(fake()->unique()->safeEmail()) 대신 결정적 고유값을 쓴다.
        // faker 의 unique() 는 생성기 수명 안에서만 유일하고 safeEmail() 의 도메인 풀이 좁아,
        // 트랜잭션 없이 커밋된 사용자가 누적되면 g7_users.email 유니크 충돌이 난다.
        $suffix = uniqid('', true);
        $users = User::factory()
            ->count(15)
            ->sequence(fn ($sequence) => ['email' => "board-notilog-{$sequence->index}-{$suffix}@example.test"])
            ->create();

        $this->seededUserIds = array_merge($this->seededUserIds, $users->pluck('id')->all());

        $users->each(fn (User $u) => $u->roles()->attach($userRole->id, ['assigned_at' => now()]));
    }

    public function test_seeder_creates_default_count(): void
    {
        $this->bootstrap();

        $this->seed(NotificationLogSeeder::class);

        $this->assertSame(100, NotificationLog::count());
    }

    public function test_seeder_only_seeds_board_scope(): void
    {
        $this->bootstrap();

        $this->seed(NotificationLogSeeder::class);

        $board = NotificationLog::query()->where('extension_identifier', 'sirsoft-board')->count();
        $other = NotificationLog::query()->where('extension_identifier', '!=', 'sirsoft-board')->count();

        $this->assertSame(100, $board, '게시판 영역만 채워야 합니다');
        $this->assertSame(0, $other, '코어/타 모듈 영역은 채우지 않아야 합니다');
    }

    public function test_seeder_uses_only_board_definitions(): void
    {
        $this->bootstrap();

        $this->seed(NotificationLogSeeder::class);

        $boardTypes = NotificationDefinition::query()
            ->where('extension_identifier', 'sirsoft-board')
            ->pluck('type')
            ->all();

        foreach (NotificationLog::query()->get() as $log) {
            $this->assertContains($log->notification_type, $boardTypes);
        }
    }

    /**
     * module.php::getNotificationDefinitions() SSoT 기반으로 게시판 알림 정의 시드.
     */
    private function syncBoardNotificationDefinitions(): void
    {
        $module = app(ModuleManager::class)->getModule('sirsoft-board');
        if (! $module) {
            return;
        }

        $helper = app(NotificationSyncHelper::class);
        foreach ($module->getNotificationDefinitions() as $data) {
            $data['extension_type'] = 'module';
            $data['extension_identifier'] = 'sirsoft-board';
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
     * bootstrap() 이 만든 사용자도 같은 이유로 커밋되어 남으므로 함께 정리한다. 정리하지
     * 않으면 스위트를 돌릴수록 g7_users 가 누적되어 다른 테스트의 사용자 건수 단언과
     * 이메일 유니크 제약을 함께 무너뜨린다.
     */
    protected function tearDown(): void
    {
        NotificationLog::query()->delete();

        if ($this->seededUserIds !== []) {
            User::query()->whereIn('id', $this->seededUserIds)->delete();
            $this->seededUserIds = [];
        }

        parent::tearDown();
    }
}
