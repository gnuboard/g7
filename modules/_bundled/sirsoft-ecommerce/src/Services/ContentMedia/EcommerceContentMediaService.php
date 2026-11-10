<?php

namespace Modules\Sirsoft\Ecommerce\Services\ContentMedia;

use App\Contracts\Extension\StorageInterface;
use App\Support\ContentMedia\AbstractContentMediaService;

/**
 * 이커머스 본문 미디어 골격 서비스 — 코어 골격에 이 모듈 어댑터와 모듈 저장소를 넘긴다 (#887)
 *
 * 업로드·저장 동기화(연결 → 복제 → 제거)·서명·서빙·정리·소유자 삭제 흐름은 코어 `AbstractContentMediaService` 가 돌린다. 공용 훅
 * `core.content_media.*` 도 골격이 발화한다. 상품·공통 정보 코드는 종전 호출 모양을 유지한 `Services\ContentMediaService` 를 쓰고,
 * 그 서비스가 이 클래스에 위임한다.
 *
 * 저장소는 모듈 기본 디스크다(서비스 프로바이더 `$storageServices`) — 미공개 상품 미디어는 서명·관리자 게이트가 걸리므로 공개 자산
 * 디스크에 올리지 않는다.
 */
class EcommerceContentMediaService extends AbstractContentMediaService
{
    /**
     * @param  EcommerceContentMediaStore  $store  이커머스 어댑터
     * @param  StorageInterface  $storage  모듈 저장소 (기본 디스크)
     */
    public function __construct(EcommerceContentMediaStore $store, StorageInterface $storage)
    {
        parent::__construct($store, $storage);
    }

    /**
     * 이커머스 어댑터.
     *
     * @return EcommerceContentMediaStore 어댑터
     */
    public function ecommerceStore(): EcommerceContentMediaStore
    {
        /** @var EcommerceContentMediaStore $store */
        $store = $this->store;

        return $store;
    }
}
