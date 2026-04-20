<?php

namespace Database\Seeders;

use App\Traits\HasSampleSeeders;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use HasSampleSeeders;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 설치 필수 시더 (항상 실행)
        $this->call([
            AdminUserSeeder::class,
            RolePermissionSeeder::class,
            CoreAdminMenuSeeder::class,
            NotificationDefinitionSeeder::class,
        ]);

        // 샘플 시더 (--sample 옵션 시에만 실행)
        if ($this->shouldIncludeSample()) {
            $this->command->info('');
            $this->command->info('=== 코어 샘플 시더 실행 시작 ===');

            $this->call([
                Sample\DummyUserSeeder::class,
                Sample\NotificationLogSeeder::class,
                Sample\TemplateSeeder::class,
                Sample\TemplateLayoutVersionSeeder::class,
                Sample\ActivityLogSampleSeeder::class,
            ]);

            $this->command->info('=== 코어 샘플 시더 실행 완료 ===');
        }
    }
}
