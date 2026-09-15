<?php

namespace Modules\Sirsoft\Ecommerce\Models\Concerns;

use Modules\Sirsoft\Ecommerce\Support\AssetStorage;

/**
 * 공개 자산 모델 공용 download_url 해석 trait
 *
 * 행에 기록된 disk 로 직접 URL(CDN) 생성을 시도하고, 불가하면(로컬 디스크,
 * url 미설정 디스크, 훅 차단 등) 기존 API 서빙 경로로 폴백합니다.
 * 디스크 전환 이전에 업로드된 로컬 행과 이후의 원격 행이 혼재해도
 * 각 행이 자기 disk 기준으로 올바른 URL 을 얻습니다.
 *
 * 스토리지 카테고리는 directUrlCategory() 오버라이드로 바꿀 수 있어(기본 'images')
 * 이미지 외의 완전 공개 자산 모델도 재사용할 수 있습니다. 단, 권한 검사가 걸린
 * 첨부파일(비밀글/회원 전용 게시판 등)에는 배선하지 않습니다 — 직접 URL 은
 * 서버 스트리밍 경로의 권한 검사를 우회합니다.
 *
 * 알려진 예외와 그 한계 — `ProductReviewImage` 는 이 trait 을 씁니다. 리뷰 노출 상태는
 * 리소스 층에서 걸러지므로 HIDDEN 리뷰의 이미지는 응답에 실리지 않지만, 공개 자산
 * 디스크를 켠 상태에서 **이미 발급된 직접 URL 을 알고 있는 쪽은 VISIBLE→HIDDEN 전환
 * 이후에도 그 파일에 접근할 수 있습니다.** 직접 URL 은 저장소의 파일을 직접 가리키므로
 * 응답에서 감추는 것으로는 회수되지 않습니다. 노출 상태를 접근 차단 수단으로 써야 하는
 * 운용이라면 그 카테고리에 공개 자산 디스크를 적용하지 않아야 합니다.
 */
trait HasDirectAssetUrl
{
    /**
     * `download_url` 가상 attribute — 직접 URL 우선, 불가 시 API 서빙 URL.
     *
     * @return string 자산 URL
     */
    public function getDownloadUrlAttribute(): string
    {
        return $this->resolveDirectUrl() ?? $this->apiDownloadUrl();
    }

    /**
     * 행 disk 기준 직접 URL 해석을 시도합니다.
     *
     * @return string|null 직접 URL (불가하면 null)
     */
    protected function resolveDirectUrl(): ?string
    {
        $disk = (string) ($this->disk ?? '');
        $path = (string) ($this->path ?? '');

        if ($disk === '' || $path === '') {
            return null;
        }

        return AssetStorage::forDisk($disk)->url($this->directUrlCategory(), $path);
    }

    /**
     * 직접 URL 해석에 쓸 스토리지 카테고리를 반환합니다.
     *
     * @return string 스토리지 카테고리 (기본 'images')
     */
    protected function directUrlCategory(): string
    {
        return 'images';
    }

    /**
     * 기존 API 서빙 경로를 반환합니다 (직접 URL 불가 시 폴백).
     *
     * @return string API 서빙 URL
     */
    abstract protected function apiDownloadUrl(): string;
}
