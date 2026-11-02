<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Database\Seeders;

use Illuminate\Console\Command;
use Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\ProductSeeder;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * gd 확장이 없는 서버에서 상품 샘플 시더가 색상 변형 없이 원본 이미지를 쓰는지 검증한다 (#151 W8)
 *
 * gd 는 코어 기준 「선택」 확장이다. `applyColorFilter()` 가 `imagecreatefromstring()` 을 `@` 로만 감싸 gd 없는 서버에서
 * 미정의 함수 오류로 시더가 중단됐다. 이제 원본 이미지를 그대로 쓰고 경고는 한 번만 낸다.
 */
class ProductSeederWithoutGdTest extends ModuleTestCase
{
    /**
     * gd 가 없으면 원본 이미지를 그대로 돌려주고, 경고는 여러 번 불러도 한 번만 냅니다.
     *
     * @return void 반환값 없음
     *
     * @scenario gd=absent, seeder=product_image
     *
     * @effects product_seeder_keeps_original_images_without_gd
     */
    public function test_returns_original_image_and_warns_once_without_gd(): void
    {
        $seeder = new class extends ProductSeeder
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
            ->with($this->stringContains(__('sirsoft-ecommerce::messages.seeders.product.color_filter_skipped_no_gd')));
        $seeder->setCommand($command);

        $this->assertSame('original-bytes', $seeder->applyColorFilter('original-bytes'));
        $this->assertSame('other-bytes', $seeder->applyColorFilter('other-bytes'));
    }
}
