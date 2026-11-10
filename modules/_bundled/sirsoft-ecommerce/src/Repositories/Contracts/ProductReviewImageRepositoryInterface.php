<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;

/**
 * 상품 리뷰 이미지 Repository 인터페이스
 */
interface ProductReviewImageRepositoryInterface
{
    /**
     * 리뷰 이미지 생성
     *
     * @param  array  $data  이미지 데이터
     * @return ProductReviewImage 생성된 이미지 모델
     */
    public function create(array $data): ProductReviewImage;

    /**
     * 해시로 리뷰 이미지 조회
     *
     * @param  string  $hash  이미지 해시 (12자)
     * @return ProductReviewImage|null 이미지 모델 (없으면 null)
     */
    public function findByHash(string $hash): ?ProductReviewImage;

    /**
     * 리뷰 이미지 삭제 (소프트 삭제)
     *
     * @param  ProductReviewImage  $image  이미지 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(ProductReviewImage $image): bool;

    /**
     * 이미지 ID 배열로 리뷰 이미지를 일괄 삭제합니다 (소프트 삭제).
     *
     * @param  array  $ids  이미지 ID 배열
     * @return int 삭제된 건수
     */
    public function deleteByIds(array $ids): int;

    /**
     * 래스터 이미지 MIME 리뷰 이미지 행 수 (썸네일 미리 만들기 진행률)
     *
     * @param  list<string>  $mimeTypes  MIME 목록
     * @return int 행 수
     */
    public function countByMimeTypes(array $mimeTypes): int;

    /**
     * 래스터 이미지 MIME 리뷰 이미지 행을 id 순으로 나눠 조회합니다 (썸네일 미리 만들기 — 커서는 마지막 id).
     *
     * @param  list<string>  $mimeTypes  MIME 목록
     * @param  int  $afterId  이 id 보다 큰 행부터
     * @param  int  $limit  최대 행 수
     * @return Collection<int, ProductReviewImage> 행 (id·disk·path·mime_type·width·height)
     */
    public function chunkByMimeTypes(array $mimeTypes, int $afterId, int $limit): Collection;
}
