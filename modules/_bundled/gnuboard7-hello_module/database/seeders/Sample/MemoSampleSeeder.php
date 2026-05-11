<?php

namespace Modules\Gnuboard7\HelloModule\Database\Seeders\Sample;

use Illuminate\Database\Seeder;
use Modules\Gnuboard7\HelloModule\Models\Memo;

/**
 * 메모 샘플 시더
 *
 * --sample 옵션 시 20건의 메모를 추가 생성합니다.
 */
class MemoSampleSeeder extends Seeder
{
    /**
     * 시더 실행
     */
    public function run(): void
    {
        Memo::factory()->count(20)->create();
    }
}
