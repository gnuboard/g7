<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Seeders;

use Illuminate\Support\Facades\Artisan;
use Modules\Sirsoft\Board\Database\Seeders\BoardTypeSeeder;
use Modules\Sirsoft\Board\Models\BoardType;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 유형 시더가 운영자가 만든 유형을 지우지 않는지 고정한다 (#727 D5-④).
 *
 * 결함(수정 전): 시더 끝의 stale 정리가 범위 조건 없이 "선언에 없는 slug" 를 전부 지워, 언어팩을 켜고 끌 때마다
 * (시더 재실행) 운영자가 「유형 추가」 로 만든 게시판 유형이 사라졌다.
 */
class BoardTypeSeederTest extends ModuleTestCase
{
    /**
     * 운영자가 만든 게시판 유형은 시더를 다시 돌려도 남는다.
     *
     * @scenario row_origin=operator_created
     *
     * @effects operator_created_board_type_survives_reseed
     *
     * @return void 반환값 없음
     */
    public function test_operator_created_board_type_survives_reseed(): void
    {
        BoardType::create(['slug' => 'probe_type', 'name' => ['ko' => '실측 유형', 'en' => 'Probe type']]);

        Artisan::call('db:seed', ['--class' => BoardTypeSeeder::class, '--force' => true]);

        $this->assertTrue(BoardType::where('slug', 'probe_type')->exists(), '운영자가 만든 게시판 유형이 시더 재실행으로 삭제되었다');
        $this->assertTrue(BoardType::where('slug', 'basic')->exists(), '선언 유형은 그대로 있어야 한다');
    }
}
