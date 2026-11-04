<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Unit\Models;

use App\Support\AssetUrl;
use Illuminate\Http\Request;
use Plugins\Sirsoft\HeroSection\Enums\BannerMediaType;
use Plugins\Sirsoft\HeroSection\Http\Resources\BannerImageResource;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;
use Plugins\Sirsoft\HeroSection\Support\PublicBannerPresenter;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 배너 이미지·영상 주소가 사이트 마운트 접두사를 갖는지 고정한다.
 *
 * 공개 응답·관리자 응답의 주소는 화면이 `<img src>`·`<video src>` 에 그대로 넣고 라우터를 거치지 않는다. 하위 폴더 설치
 * (`https://host/g7/`)에서 접두사가 없으면 사이트 밖을 불러 이미지가 깨진다.
 */
class MediaUrlBasePrefixTest extends PluginTestCase
{
    /**
     * 강제로 지정한 마운트 접두사를 풀어 다른 테스트에 남지 않게 한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        AssetUrl::forceBasePrefix(null);

        parent::tearDown();
    }

    /**
     * 해시만 가진 미디어 행을 만듭니다(저장하지 않음).
     *
     * @param  string  $hash  해시
     * @param  string  $kind  미디어 종류 (image 또는 video)
     * @return BannerImage 저장하지 않은 미디어 모델
     */
    private function media(string $hash, string $kind = 'image'): BannerImage
    {
        $media = new BannerImage;
        $media->forceFill(['hash' => $hash, 'kind' => $kind, 'mime' => $kind === 'video' ? 'video/mp4' : 'image/jpeg', 'width' => 1920, 'height' => 640]);

        return $media;
    }

    /**
     * 하위 폴더(/g7)에서 이미지·모바일 이미지·영상 주소가 공개 응답과 관리자 응답 모두 접두사를 갖고, 루트 설치는 종전과 같다.
     *
     * @scenario surface=media_urls_carry_site_mount_prefix
     *
     * @effects media_served_inside_site_mount
     *
     * @return void 반환값 없음
     */
    public function test_media_urls_carry_the_site_mount_prefix(): void
    {
        $api = '/api/plugins/sirsoft-hero_section/images/';
        $this->assertSame($api.'h1', $this->media('h1')->publicUrl());

        AssetUrl::forceBasePrefix('/g7');
        $this->assertSame('/g7'.$api.'h1', $this->media('h1')->publicUrl());

        $resource = (new BannerImageResource($this->media('h1')))->toArray(Request::create('/g7/api/x'));
        $this->assertSame('/g7'.$api.'h1', $resource['url']);
        $this->assertSame('/g7'.$api.'h1', $resource['download_url']);

        $banner = new Banner;
        $banner->forceFill(['id' => 7, 'media_type' => BannerMediaType::Video->value, 'title' => ['ko' => '제목'], 'is_active' => true, 'display_period' => 'always']);
        $banner->setRelation('pcImage', $this->media('pc'));
        $banner->setRelation('mobileImage', $this->media('m'));
        $banner->setRelation('pcVideo', $this->media('v', 'video'));
        $banner->setRelation('mobileVideo', null);
        $public = PublicBannerPresenter::present($banner, 'ko');
        $this->assertSame('/g7'.$api.'pc', $public['images']['pc']['url']);
        $this->assertSame('/g7'.$api.'m', $public['images']['mobile']['url']);
        $this->assertSame('/g7'.$api.'v', $public['videos']['pc']['url']);
    }
}
