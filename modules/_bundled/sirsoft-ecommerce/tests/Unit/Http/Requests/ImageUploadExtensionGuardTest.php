<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Requests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UploadClaimImageRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\UploadReviewImageRequest;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 이미지 업로드 요청의 확장자 판정 테스트 (P21)
 *
 * 검증 축 — 이 두 룰이 **각각 무엇을 보는지**를 코드에 고정한다.
 *
 *  - `image` / `mimes` 의 통과 판정은 **내용 기반**이다 (`guessExtension()`).
 *  - 그 안의 `shouldBlockPhpUpload()` 만 **클라이언트 확장자**를 보며, 대상은 php 계열뿐이다.
 *  - 그래서 내용이 정상 이미지이고 파일명만 `.html`·`.svg`·`.js` 인 업로드는 **두 룰을 모두 통과한다**.
 *    저장 경로의 확장자 방어는 검증 룰이 아니라 저장 지점이 책임진다
 *    (`ImageStoredExtensionTest` 가 그 축을 고정한다).
 *
 * `image` 룰은 Laravel 11 이후 SVG 를 기본 제외하고(`allow_svg` 옵트인) 자체적으로
 * `validateMimes` 를 부르므로, `mimes` 를 덧붙이지 않은 요청도 php·SVG 는 이미 거절한다.
 */
class ImageUploadExtensionGuardTest extends ModuleTestCase
{
    /**
     * 내용은 정상 PNG 인데 클라이언트 파일명만 다른 업로드를 만듭니다. (실제 업로드와 동형)
     *
     * `UploadedFile::fake()->createWithContent()` 는 MIME 을 **파일명 확장자에서** 만들어내므로
     * 내용·확장자 불일치를 재현하지 못한다 — 진짜 PNG 바이트를 담은 파일에 이름만 붙인다.
     */
    private function disguisedPng(string $clientName): UploadedFile
    {
        $png = UploadedFile::fake()->image('real.png', 20, 20);
        $path = tempnam(sys_get_temp_dir(), 'g7claim');
        copy($png->getRealPath(), $path);

        return new UploadedFile($path, $clientName, null, null, true);
    }

    /**
     * 주어진 rules 로 image 필드를 검증합니다.
     *
     * @param  array<string, mixed>  $rules  검증 규칙
     * @param  UploadedFile  $file  업로드 파일
     * @return bool 검증 실패 여부
     */
    private function failsWith(array $rules, UploadedFile $file): bool
    {
        return Validator::make(['image' => $file], ['image' => $rules['image']])->fails();
    }

    /**
     * 클레임·리뷰 요청 rules 를 함께 돌립니다.
     *
     * @return array{claim: bool, review: bool} 각 요청의 검증 실패 여부
     */
    private function bothFail(UploadedFile $file): array
    {
        return [
            'claim' => $this->failsWith((new UploadClaimImageRequest)->rules(), $file),
            'review' => $this->failsWith((new UploadReviewImageRequest)->rules(), $file),
        ];
    }

    /**
     * php 계열 클라이언트 확장자는 내용이 정상 이미지여도 두 요청 모두 거절한다.
     *
     * 리뷰 요청에는 `mimes` 가 없지만 `image` 룰이 `validateMimes` 를 경유하므로
     * `shouldBlockPhpUpload()` 가 동일하게 실행된다 — 두 요청의 강도가 이 축에서 같다.
     */
    #[Test]
    public function both_requests_reject_a_php_named_image(): void
    {
        $this->assertSame(
            ['claim' => true, 'review' => true],
            $this->bothFail($this->disguisedPng('evil.php'))
        );
    }

    /**
     * SVG 내용은 두 요청 모두 거절한다 (스크립트를 담은 SVG 의 동일 오리진 실행 차단).
     */
    #[Test]
    public function both_requests_reject_an_svg(): void
    {
        $svg = UploadedFile::fake()->createWithContent(
            'x.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );

        $this->assertSame(['claim' => true, 'review' => true], $this->bothFail($svg));
    }

    /**
     * 정상 이미지는 두 요청 모두 통과한다 (대조군 — 위 단언들이 전부-거절로 공허해지지 않음을 고정).
     */
    #[Test]
    public function both_requests_accept_a_normal_image(): void
    {
        $this->assertSame(
            ['claim' => false, 'review' => false],
            $this->bothFail(UploadedFile::fake()->image('ok.png', 20, 20))
        );
    }

    /**
     * 실행 가능한 클라이언트 확장자(php 계열 밖)는 **검증 룰이 막지 못한다** — 측정된 사실.
     *
     * 이 단언이 뒤집히면(룰이 막게 되면) 저장 지점의 확장자 정규화가 불필요해졌다는 뜻이므로
     * 그때 `ImageStoredExtensionTest` 와 함께 재판정한다.
     */
    #[Test]
    public function validation_rules_do_not_block_a_non_php_executable_client_extension(): void
    {
        foreach (['evil.html', 'evil.js', 'evil.xhtml'] as $name) {
            $this->assertSame(
                ['claim' => false, 'review' => false],
                $this->bothFail($this->disguisedPng($name)),
                "{$name} — 내용이 PNG 이므로 내용 기반 판정은 통과한다 (저장 지점이 막아야 한다)"
            );
        }
    }
}
