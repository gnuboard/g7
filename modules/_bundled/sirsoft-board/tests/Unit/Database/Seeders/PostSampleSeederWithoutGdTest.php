<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Database\Seeders;

use Illuminate\Console\Command;
use Modules\Sirsoft\Board\Database\Seeders\Sample\PostSampleSeeder;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * gd 확장이 없는 서버에서 게시판 샘플 시더가 이미지 없이 진행하는지 검증한다 (#151 W8)
 *
 * gd 는 코어 기준 「선택」 확장이다. 예전에는 `imagecreatetruecolor()` 를 가드 없이 불러 `--sample` 시더가 CLI 에서
 * 중단됐다. 이제 경고 한 줄을 남기고 샘플 이미지·첨부 없이 게시글 생성을 이어간다(ClaimSeeder·PageSampleSeeder 와 같은 가드).
 */
class PostSampleSeederWithoutGdTest extends ModuleTestCase
{
    /**
     * gd 가 없으면 샘플 이미지를 만들지 않고 경고를 남기며, 첨부 생성도 건너뜁니다.
     *
     * @return void 반환값 없음
     *
     * @scenario gd=absent, seeder=board_post
     *
     * @effects board_seeder_skips_images_without_gd
     */
    public function test_skips_sample_images_and_attachments_without_gd(): void
    {
        $seeder = new class extends PostSampleSeeder
        {
            /**
             * gd 가 없는 서버를 흉내 냅니다.
             *
             * @return bool 항상 false
             */
            protected function gdAvailable(): bool
            {
                return false;
            }
        };
        $command = $this->createMock(Command::class);
        $command->expects($this->once())->method('warn')
            ->with($this->stringContains(__('sirsoft-board::messages.seeders.post_sample.images_skipped_no_gd')));
        $seeder->setCommand($command);

        $createImages = new \ReflectionMethod(PostSampleSeeder::class, 'createSampleImageFiles');
        $createImages->invoke($seeder);

        $images = new \ReflectionProperty(PostSampleSeeder::class, 'sampleImages');
        $this->assertSame([], $images->getValue($seeder));

        // 이미지가 없으면 첨부 생성은 DB 를 건드리지 않고 돌아간다 (예전에는 빈 배열 인덱스 접근 경고가 났다)
        $attach = new \ReflectionMethod(PostSampleSeeder::class, 'createAttachmentsForPost');
        $attach->invoke($seeder, 1, 1, null, null);
        $this->assertSame(0, (new \ReflectionProperty(PostSampleSeeder::class, 'attachmentIndex'))->getValue($seeder));
    }
}
