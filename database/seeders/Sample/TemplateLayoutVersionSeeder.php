<?php

namespace Database\Seeders\Sample;

use App\Models\TemplateLayout;
use App\Models\TemplateLayoutVersion;
use App\Models\User;
use Illuminate\Database\Seeder;

class TemplateLayoutVersionSeeder extends Seeder
{
    /**
     * 샘플 버전 데이터
     */
    private array $sampleVersions = [
        [
            'version' => '1.0.0',
            'changes_summary' => [
                'added' => 0,
                'removed' => 0,
                'is_restored' => false,
                'restored_from' => null,
            ],
        ],
        [
            'version' => '1.1.0',
            'changes_summary' => [
                'added' => 10,
                'removed' => 5,
                'is_restored' => false,
                'restored_from' => null,
            ],
        ],
        [
            'version' => '2.0.0',
            'changes_summary' => [
                'added' => 25,
                'removed' => 15,
                'is_restored' => false,
                'restored_from' => null,
            ],
        ],
    ];

    /**
     * 시더 실행
     */
    public function run(): void
    {
        $this->command->info('템플릿 레이아웃 버전 시더 생성을 시작합니다.');

        // 기존 버전 데이터 삭제
        $this->deleteExistingVersions();

        // 레이아웃 조회
        $layouts = TemplateLayout::all();

        if ($layouts->isEmpty()) {
            $this->command->warn('레이아웃이 없습니다. TemplateSeeder를 먼저 실행해주세요.');
            return;
        }

        // 관리자 사용자 조회
        $adminUser = User::where('is_super', true)->first();

        if (!$adminUser) {
            $this->command->warn('관리자 사용자가 없습니다. AdminUserSeeder를 먼저 실행해주세요.');
            return;
        }

        // 각 레이아웃에 대해 버전 생성
        foreach ($layouts as $layout) {
            $this->createVersionsForLayout($layout, $adminUser);
        }

        $this->command->info('템플릿 레이아웃 버전 시더가 성공적으로 생성되었습니다.');
    }

    /**
     * 기존 버전 데이터 삭제
     */
    private function deleteExistingVersions(): void
    {
        $deletedCount = TemplateLayoutVersion::query()->delete();

        if ($deletedCount > 0) {
            $this->command->warn("기존 레이아웃 버전 {$deletedCount}건을 삭제했습니다.");
        }
    }

    /**
     * 특정 레이아웃에 대한 버전 생성
     */
    private function createVersionsForLayout(TemplateLayout $layout, User $adminUser): void
    {
        foreach ($this->sampleVersions as $index => $versionData) {
            // 레이아웃 content를 복사하여 버전별로 약간 수정
            $content = $layout->content;
            $content['version'] = $versionData['version'];

            // 버전별로 컴포넌트 수정 시뮬레이션
            if ($index > 0) {
                $content['meta']['description'] = "Version {$versionData['version']} - Updated layout";
            }

            $version = TemplateLayoutVersion::create([
                'layout_id' => $layout->id,
                'version' => $versionData['version'],
                'content' => $content,
                'changes_summary' => $versionData['changes_summary'],
                'created_by' => $adminUser->id,
            ]);

            $this->command->info("레이아웃 '{$layout->name}'의 버전 {$version->version}이 생성되었습니다.");
        }
    }
}
