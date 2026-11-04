<?php

namespace Plugins\Sirsoft\Popup\Tests\Unit\Models;

use App\Support\AssetUrl;
use Illuminate\Http\Request;
use Plugins\Sirsoft\Popup\Enums\ContentType;
use Plugins\Sirsoft\Popup\Http\Resources\PopupImageResource;
use Plugins\Sirsoft\Popup\Models\Popup;
use Plugins\Sirsoft\Popup\Models\PopupImage;
use Plugins\Sirsoft\Popup\Support\PublicPopupPresenter;
use Plugins\Sirsoft\Popup\Tests\PluginTestCase;

/**
 * 팝업 이미지 주소가 사이트 마운트 접두사를 갖는지 고정한다.
 *
 * 응답의 주소는 사용자 레이어·미리보기가 `<img src>` 에 그대로 넣고 라우터를 거치지 않는다. 하위 폴더 설치
 * (`https://host/g7/`)에서 접두사가 없으면 사이트 밖을 불러 이미지가 깨진다.
 */
class ImageUrlBasePrefixTest extends PluginTestCase
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
     * 해시만 가진 이미지 행을 만듭니다(저장하지 않음).
     *
     * @param  string  $hash  해시
     * @return PopupImage 이미지
     */
    private function image(string $hash): PopupImage
    {
        return (new PopupImage)->forceFill(['hash' => $hash, 'mime' => 'image/jpeg', 'width' => 480, 'height' => 520]);
    }

    /**
     * 하위 폴더(/g7)에서 PC·모바일 이미지 주소가 공개 응답과 관리자 응답 모두 접두사를 갖고, 루트 설치는 종전과 같다.
     *
     * @scenario surface=image_url_carries_site_mount_prefix
     *
     * @effects media_served_inside_site_mount
     *
     * @return void 반환값 없음
     */
    public function test_image_urls_carry_the_site_mount_prefix(): void
    {
        $api = '/api/plugins/sirsoft-popup/images/';
        $this->assertSame($api.'h1', $this->image('h1')->publicUrl());

        AssetUrl::forceBasePrefix('/g7');
        $this->assertSame('/g7'.$api.'h1', $this->image('h1')->publicUrl());

        $resource = (new PopupImageResource($this->image('h1')))->toArray(Request::create('/g7/api/x'));
        $this->assertSame('/g7'.$api.'h1', $resource['url']);
        $this->assertSame('/g7'.$api.'h1', $resource['download_url']);

        $popup = (new Popup)->forceFill(['id' => 3, 'content_type' => ContentType::Image->value, 'page_scope' => 'all', 'device' => 'all', 'is_active' => true, 'display_period' => 'always']);
        $popup->setRelation('pcImage', $this->image('pc'));
        $popup->setRelation('mobileImage', $this->image('m'));
        $public = PublicPopupPresenter::present($popup, 'ko');
        $this->assertSame('/g7'.$api.'pc', $public['image']['pc']['url']);
        $this->assertSame('/g7'.$api.'m', $public['image']['mobile']['url']);
    }
}
