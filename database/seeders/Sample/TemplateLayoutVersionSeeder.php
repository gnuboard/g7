<?php

namespace Database\Seeders\Sample;

use App\Models\TemplateLayout;
use App\Models\TemplateLayoutVersion;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 레이아웃 버전 이력 샘플 시더 (개발·시연용).
 *
 * 버전 칸은 레이아웃별 자동 증가 정수다 — 종전에는 `'1.0.0'` 같은 문자열을 넣어 MySQL strict 모드에서 「Data truncated」로 멈추고
 * 뒤 순서 샘플 시더가 돌지 않았다. 또 모든 버전 이력을 지웠다 — 설치가 끝난 사이트의 운영자 편집 이력이 사라지지 않게 샘플 표시
 * (`changes_summary.is_sample`)가 있는 행만 다시 만들고, 번호는 레이아웃별 기존 최댓값 다음부터 붙인다.
 */
class TemplateLayoutVersionSeeder extends Seeder
{
    /**
     * 샘플 버전 데이터 (label 은 설명 문구용 — 버전 번호는 레이아웃별 정수로 매긴다)
     *
     * @var array<int, array{label: string, changes_summary: array<string, mixed>}>
     */
    private array $sampleVersions = [
        [
            'label' => '1.0.0',
            'changes_summary' => [
                'added' => 0,
                'removed' => 0,
                'is_restored' => false,
                'restored_from' => null,
            ],
        ],
        [
            'label' => '1.1.0',
            'changes_summary' => [
                'added' => 10,
                'removed' => 5,
                'is_restored' => false,
                'restored_from' => null,
            ],
        ],
        [
            'label' => '2.0.0',
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
     *
     * @return void 반환값 없음
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
     * 이전에 이 시더가 만든 샘플 버전만 삭제합니다 — 운영자 편집 이력은 두고 샘플만 다시 만든다.
     *
     * @return void 반환값 없음
     */
    private function deleteExistingVersions(): void
    {
        $deletedCount = TemplateLayoutVersion::query()->where('changes_summary->is_sample', true)->delete();

        if ($deletedCount > 0) {
            $this->command->warn("기존 샘플 레이아웃 버전 {$deletedCount}건을 삭제했습니다.");
        }
    }

    /**
     * 특정 레이아웃에 대한 버전 생성 — 번호는 그 레이아웃의 기존 최댓값 다음 정수부터.
     *
     * @param  TemplateLayout  $layout  대상 레이아웃
     * @param  User  $adminUser  작성자로 기록할 최고관리자
     * @return void 반환값 없음
     */
    private function createVersionsForLayout(TemplateLayout $layout, User $adminUser): void
    {
        $next = (int) TemplateLayoutVersion::query()->where('layout_id', $layout->id)->max('version') + 1;

        foreach ($this->sampleVersions as $index => $versionData) {
            // 레이아웃 content를 복사하여 버전별로 약간 수정 (content.version 은 레이아웃 문서 형식 버전이라 건드리지 않는다)
            $content = $layout->content;

            // 버전별로 컴포넌트 수정 시뮬레이션
            if ($index > 0) {
                $content['meta']['description'] = "Version {$versionData['label']} - Updated layout";
            }

            $version = TemplateLayoutVersion::create([
                'layout_id' => $layout->id,
                'version' => $next++,
                'content' => $content,
                'changes_summary' => $versionData['changes_summary'] + ['is_sample' => true],
                'created_by' => $adminUser->id,
            ]);

            $this->command->info("레이아웃 '{$layout->name}'의 버전 {$version->version}이 생성되었습니다.");
        }
    }
}
