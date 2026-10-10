<?php

namespace Database\Seeders\Sample;

use App\Models\Template;
use Illuminate\Database\Seeder;

/**
 * 템플릿 샘플 시더 — 뒤따르는 샘플 시더(레이아웃 버전)가 쓸 **설치된 관리자 템플릿**을 확인합니다.
 *
 * 종전에는 특정 관리자 템플릿 식별자를 적어 두고, 그 행을 강제 삭제한 뒤 가짜 행(버전 0.1.1·비활성)과
 * 가짜 베이스 레이아웃 한 개로 다시 만들었다. 설치된 관리자 템플릿이 그 식별자이면 샘플을 돌리는
 * 순간 실제 템플릿 행과 그 레이아웃이 통째로 사라졌고, 다른 관리자 템플릿을 쓰면 쓰지 않는 템플릿
 * 행이 생겼다. 이제 템플릿 행은 설치 과정이 만든 것을 그대로 쓰고, 이 시더는 아무것도 만들거나
 * 지우지 않는다(2026-09-28 결정 — 코어가 특정 템플릿 식별자를 알지 않는다).
 *
 * 수동 실행: php artisan db:seed --class="Database\Seeders\Sample\TemplateSeeder"
 */
class TemplateSeeder extends Seeder
{
    /**
     * 시더 실행 — 설치된 관리자 템플릿과 그 레이아웃 수를 보고합니다.
     *
     * @return void
     */
    public function run(): void
    {
        $template = Template::query()
            ->where('type', 'admin')
            ->orderByRaw("status = 'active' desc")
            ->withCount('layouts')
            ->first();

        if ($template === null) {
            $this->command->warn('설치된 관리자 템플릿이 없습니다. 관리자 템플릿을 먼저 설치하세요 — 레이아웃 버전 샘플을 건너뜁니다.');

            return;
        }

        $this->command->info("관리자 템플릿 {$template->identifier} (레이아웃 {$template->layouts_count}개)를 샘플 대상으로 씁니다.");
    }
}
