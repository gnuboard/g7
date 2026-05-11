<?php

namespace Modules\Gnuboard7\HelloModule\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Gnuboard7\HelloModule\Models\Memo;

/**
 * 메모 기본 시더
 *
 * 모듈 설치 시 기본 샘플 메모 2건을 생성합니다.
 */
class MemoSeeder extends Seeder
{
    /**
     * 시더 실행
     */
    public function run(): void
    {
        $defaults = [
            [
                'title' => '환영합니다',
                'content' => 'Hello 모듈의 첫 번째 샘플 메모입니다. 학습용으로 제공됩니다.',
            ],
            [
                'title' => '두 번째 메모',
                'content' => 'Memo 엔티티의 CRUD 동작을 확인할 수 있는 추가 샘플입니다.',
            ],
        ];

        foreach ($defaults as $row) {
            Memo::updateOrCreate(
                ['title' => $row['title']],
                [
                    'uuid' => (string) Str::uuid(),
                    'content' => $row['content'],
                ]
            );
        }
    }
}
