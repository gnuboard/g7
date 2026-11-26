<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Review;

use App\Enums\ScopeType;
use App\Models\Permission;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Services\ProductReviewService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 리뷰 답글 저장의 권한 범위 검사
 *
 * 상태 변경·답글 삭제와 같은 범위(`sirsoft-ecommerce.reviews.update`)를 답글 저장에도 적용한다.
 */
class SaveReplyScopeTest extends ModuleTestCase
{
    /**
     * 「본인 것만」 범위로 리뷰 수정 권한을 가진 관리자를 만듭니다.
     *
     * @return \App\Models\User 관리자
     */
    private function selfScopedAdmin(): \App\Models\User
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.reviews.update']);
        $permission = Permission::query()->where('identifier', 'sirsoft-ecommerce.reviews.update')->first();
        if ($permission->resource_route_key === null || $permission->owner_key === null) {
            $permission->forceFill(['resource_route_key' => 'review', 'owner_key' => 'user_id'])->save();
        }
        $role = $admin->roles()->where('identifier', 'like', 'admin-test-%')->first();
        $role->permissions()->updateExistingPivot($permission->id, ['scope_type' => ScopeType::Self]);

        return $admin->fresh();
    }

    /**
     * 범위 밖(남의) 리뷰에 답글을 달면 403 으로 막히고 답글이 저장되지 않는다.
     *
     * @scenario case=save_reply_self_other
     *
     * @effects review_reply_scope_denied
     *
     * @return void 반환값 없음
     */
    public function test_out_of_scope_reply_is_denied(): void
    {
        $admin = $this->selfScopedAdmin();
        $review = ProductReview::factory()->create();
        $this->actingAs($admin);

        try {
            app(ProductReviewService::class)->saveReply($review, $admin->id, ['reply_content' => '범위 밖']);
            $this->fail('범위 밖 답글이 저장됐다');
        } catch (AccessDeniedHttpException) {
            $this->assertNull($review->fresh()->reply_content);
        }
    }

    /**
     * 범위 안(본인) 리뷰에는 답글을 달 수 있다.
     *
     * @scenario case=save_reply_self_self
     *
     * @effects review_reply_saved
     *
     * @return void 반환값 없음
     */
    public function test_in_scope_reply_is_saved(): void
    {
        $admin = $this->selfScopedAdmin();
        $review = ProductReview::factory()->create(['user_id' => $admin->id]);
        $this->actingAs($admin);

        app(ProductReviewService::class)->saveReply($review, $admin->id, ['reply_content' => '범위 안']);

        $this->assertSame('범위 안', $review->fresh()->reply_content);
    }
}
