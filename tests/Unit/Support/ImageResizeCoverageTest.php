<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * 업로드 이미지 축소 적용 범위 테스트
 *
 * 최대 가로/세로·품질은 **코어 환경설정**이므로 특정 화면이 아니라 업로드 전 경로에
 * 적용되어야 합니다. 코어 첨부만 고치면 게시판·페이지·상품 이미지는 원본 그대로 저장되어,
 * 관리자 입장에서는 "설정을 켰는데 어떤 업로드는 줄고 어떤 업로드는 안 준다" 가 됩니다.
 *
 * 실측(2026-07-29): 코어 서비스만 적용한 상태에서 게시판 글쓰기로 2400x1800 을 올렸더니
 * 2400x1800 그대로 저장됐다 — 게시판 첨부는 코어 AttachmentService 를 거치지 않는다.
 *
 * 파일을 저장하는 업로드 서비스가 새로 생기면 이 목록에 추가해야 합니다.
 */
class ImageResizeCoverageTest extends TestCase
{
    /**
     * 업로드 파일을 디스크에 저장하는 서비스 전수.
     *
     * @param  string  $relativePath  검사 대상 서비스 경로
     */
    #[Test]
    #[TestWith(['app/Services/AttachmentService.php'])]
    #[TestWith(['app/Services/TemplateLayoutAttachmentService.php'])]
    #[TestWith(['modules/_bundled/sirsoft-board/src/Services/AttachmentService.php'])]
    #[TestWith(['modules/_bundled/sirsoft-page/src/Services/PageAttachmentService.php'])]
    #[TestWith(['modules/_bundled/sirsoft-ecommerce/src/Services/ProductImageService.php'])]
    #[TestWith(['modules/_bundled/sirsoft-ecommerce/src/Services/CategoryImageService.php'])]
    #[TestWith(['modules/_bundled/sirsoft-ecommerce/src/Services/ProductReviewImageService.php'])]
    #[TestWith(['plugins/_bundled/sirsoft-ckeditor5/src/Services/ImageUploadService.php'])]
    public function every_upload_service_applies_the_configured_image_bounds(string $relativePath): void
    {
        $path = base_path($relativePath);

        $this->assertFileExists($path);

        $source = $this->stripComments(file_get_contents($path));

        $this->assertStringContainsString(
            'resizeInPlace',
            $source,
            "{$relativePath}: 업로드 이미지 축소가 적용되지 않았습니다 — 이 경로로 올린 이미지는 환경설정 상한을 무시합니다."
        );
    }

    /**
     * 주석을 제거한 소스를 반환합니다.
     *
     * 설명 주석에 등장하는 호출 표기가 검사를 통과시키는 것을 막습니다.
     *
     * @param  string  $source  원본 소스
     * @return string 주석이 제거된 소스
     */
    private function stripComments(string $source): string
    {
        $stripped = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $stripped .= is_array($token) ? $token[1] : $token;
        }

        return $stripped;
    }
}
