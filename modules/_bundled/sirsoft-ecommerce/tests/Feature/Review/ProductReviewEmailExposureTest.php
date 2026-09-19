<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Review;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\ProductReviewResource;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 공개 리뷰 응답의 회원 이메일 노출 회귀 테스트
 *
 * 상품 리뷰 목록은 인증 없이 열린다. 그 응답에 구매자 이메일이 실리면 화면이
 * 이메일을 그리지 않더라도 응답 본문에서 구매자 주소록을 그대로 수집할 수 있다.
 */
class ProductReviewEmailExposureTest extends ModuleTestCase
{
    /**
     * 공개(비관리자) 요청에서는 작성자 이메일이 실리지 않는다.
     *
     * @scenario case=review_email_public
     *
     * @effects review_author_email_masked_on_public_response
     */
    public function test_public_request_masks_reviewer_email(): void
    {
        $user = $this->createUser();
        $product = Product::factory()->create();

        $review = ProductReview::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);
        $review->load('user');

        // 라우트가 없는 요청 = 관리자 컨트롤러가 아님 = 공개 경로와 동일 판정
        $payload = (new ProductReviewResource($review))->toArray(Request::create('/api/test', 'GET'));

        $this->assertNotNull($payload['user'], '작성자 블록 자체가 사라졌다');
        $this->assertNull(
            $payload['user']['email'],
            '공개 리뷰 응답에 작성자 이메일이 실렸다'
        );
        $this->assertSame($user->name, $payload['user']['name'], '이름까지 지워졌다 — 이메일만 가려야 한다');
    }

    /**
     * 본인은 자기 리뷰에서 자기 이메일을 그대로 본다.
     *
     * @scenario case=review_email_owner
     *
     * @effects review_author_email_visible_to_owner
     */
    public function test_owner_still_sees_own_email(): void
    {
        $user = $this->createUser();
        $product = Product::factory()->create();

        $review = ProductReview::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);
        $review->load('user');

        $this->actingAs($user, 'sanctum');

        $payload = (new ProductReviewResource($review))->toArray(Request::create('/api/test', 'GET'));

        $this->assertSame(
            $user->email,
            $payload['user']['email'],
            '본인 리뷰에서 자기 이메일이 가려졌다'
        );
    }
}
