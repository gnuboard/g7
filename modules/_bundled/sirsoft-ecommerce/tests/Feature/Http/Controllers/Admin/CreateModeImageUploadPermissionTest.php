<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use Illuminate\Http\UploadedFile;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 등록 화면의 임시 이미지 업로드 권한 테스트 (7.1.0 abilities 게이트 ecom-admin-products#9)
 *
 * 상품·분류 등록 화면은 저장 전에 이미지를 임시로 올린다. 임시 업로드가 수정 권한만 받으면 등록 권한만 가진
 * 운영자는 등록 화면에서 이미지를 올릴 수 없다. 임시 업로드는 등록 또는 수정 권한으로 열고, 이미 있는 상품·분류에
 * 바로 올리는 경로는 수정 권한 그대로다.
 *
 * 파일을 실제로 저장하지 않으려고 잘못된 임시 키를 보낸다 — 권한을 통과하면 검증 오류(422), 막히면 403 이다.
 */
class CreateModeImageUploadPermissionTest extends ModuleTestCase
{
    /**
     * 임시 업로드 요청을 보냅니다. (잘못된 임시 키 — 저장 없이 검증에서 끝난다)
     *
     * @param  string  $resource  업로드 대상 자원 (products 상품 | categories 분류)
     * @return int 응답 HTTP 상태 코드
     */
    private function uploadTemp(string $resource): int
    {
        return $this->postJson("/api/modules/sirsoft-ecommerce/admin/{$resource}/images", [
            'file' => UploadedFile::fake()->image('probe.png'),
            'temp_key' => '../not-a-uuid',
        ])->status();
    }

    /**
     * 등록 권한만 있어도 상품·분류 임시 업로드는 권한을 통과합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin, permission=granted
     *
     * @effects admin_temp_image_upload_accepts_create_permission
     */
    public function test_create_only_operator_passes_temp_upload_permission(): void
    {
        $creator = $this->createAdminUser([
            'sirsoft-ecommerce.products.read',
            'sirsoft-ecommerce.products.create',
            'sirsoft-ecommerce.categories.read',
            'sirsoft-ecommerce.categories.create',
        ]);

        $this->actingAs($creator);
        $this->assertSame(422, $this->uploadTemp('products'));
        $this->assertSame(422, $this->uploadTemp('categories'));
    }

    /**
     * 조회 권한만 있으면 임시 업로드도 403 입니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin, permission=missing
     *
     * @effects admin_temp_image_upload_denies_read_only
     */
    public function test_read_only_operator_is_denied_temp_upload(): void
    {
        $reader = $this->createAdminUser([
            'sirsoft-ecommerce.products.read',
            'sirsoft-ecommerce.categories.read',
        ]);

        $this->actingAs($reader);
        $this->assertSame(403, $this->uploadTemp('products'));
        $this->assertSame(403, $this->uploadTemp('categories'));
    }
}
