<?php

namespace Tests\Unit\Services;

use App\Contracts\Extension\StorageInterface;
use App\Contracts\Repositories\TemplateLayoutAttachmentRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Models\Template;
use App\Models\TemplateLayoutAttachment;
use App\Services\TemplateLayoutAttachmentService;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

/**
 * 레이아웃 첨부 URL 발급 모드 테스트 (공개 #134)
 *
 * 직접 URL 은 운영자가 `core.storage.public_asset_disk` 로 "이 저장소는 공개다" 라고
 * 명시 선언하고 **행 disk 가 그 값과 일치할 때만** 발급된다. `filesystems.disks.{disk}.url`
 * 설정 유무는 "URL 을 만들 수 있는가" 일 뿐 "익명 읽기가 되는가" 가 아니므로 판정 근거가
 * 될 수 없다 (비공개 버킷 + s3_url 설정 조합에서 직접 URL 은 403).
 */
class TemplateLayoutAttachmentUrlResolutionTest extends TestCase
{
    private TemplateLayoutAttachmentService $service;

    private $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = Mockery::mock(StorageInterface::class);

        $this->service = new TemplateLayoutAttachmentService(
            Mockery::mock(TemplateLayoutAttachmentRepositoryInterface::class),
            Mockery::mock(TemplateRepositoryInterface::class),
            $this->storage
        );
    }

    /** 템플릿 관계가 로드된 첨부를 만듭니다 (DB 미저장). */
    private function makeAttachment(string $disk): TemplateLayoutAttachment
    {
        $template = new Template;
        $template->id = 7;
        $template->identifier = 'sirsoft-basic';

        $attachment = new TemplateLayoutAttachment([
            'layout_name' => 'home',
            'disk' => $disk,
            'path' => 'sirsoft-basic/2026/09/08/bg.png',
            'original_name' => 'bg.png',
            'mime_type' => 'image/png',
            'size' => 178,
        ]);
        $attachment->id = 1;
        $attachment->template_id = 7;
        $attachment->setRelation('template', $template);

        return $attachment;
    }

    /** 프록시(공개 서빙 라우트) URL 인지 단언합니다. */
    private function assertProxyUrl(string $url): void
    {
        $this->assertStringContainsString('/layout-attachments/1/file', $url);
    }

    /**
     * 공개 자산 디스크 미설정 — 기본 설치. 프록시 URL 이어야 합니다.
     *
     * @scenario public_asset_disk=unset,row_disk=attachments,filter_url_hook=absent
     *
     * @effects proxy_url_otherwise
     */
    public function test_unset_public_asset_disk_returns_proxy_url(): void
    {
        Config::set('core.storage.public_asset_disk', '');
        $this->storage->shouldNotReceive('withDisk');

        $this->assertProxyUrl($this->service->resolveUrl($this->makeAttachment('attachments')));
    }

    /**
     * 공개 자산 디스크 미설정 + 행 disk 가 s3 + s3.url 설정 — 프록시여야 합니다.
     *
     * 이 케이스가 §1 의 함정이다. `url()` non-null 이면 우선한다는 규칙을 문자
     * 그대로 적용하면, 비공개 버킷 + `AWS_URL` 설정 환경에서 발급된 직접 URL 이
     * 403 이 되어 배경 이미지가 전부 깨진다. 회귀 방지 축으로 가장 중요하다.
     *
     * @scenario public_asset_disk=unset,row_disk=s3,filter_url_hook=absent
     *
     * @effects proxy_url_otherwise
     */
    public function test_private_s3_row_with_url_config_still_returns_proxy_url(): void
    {
        Config::set('core.storage.public_asset_disk', '');
        Config::set('attachment.disk', 's3');
        Config::set('filesystems.disks.s3.url', 'https://bucket.s3.ap-northeast-2.amazonaws.com');
        $this->storage->shouldNotReceive('withDisk');

        $this->assertProxyUrl($this->service->resolveUrl($this->makeAttachment('s3')));
    }

    /**
     * 공개 자산 디스크 설정 + 행 disk 일치 — 직접 URL 이어야 합니다.
     *
     * @scenario public_asset_disk=public,row_disk=public,filter_url_hook=absent
     *
     * @effects direct_url_when_row_matches_public_disk
     */
    public function test_matching_public_asset_disk_returns_direct_url(): void
    {
        Config::set('core.storage.public_asset_disk', 'public');

        $this->storage->shouldReceive('withDisk')->once()->with('public')->andReturnSelf();
        $this->storage->shouldReceive('url')->once()
            ->with('template-layout-attachments', 'sirsoft-basic/2026/09/08/bg.png')
            ->andReturn('https://cdn.example.test/template-layout-attachments/sirsoft-basic/2026/09/08/bg.png');

        $this->assertSame(
            'https://cdn.example.test/template-layout-attachments/sirsoft-basic/2026/09/08/bg.png',
            $this->service->resolveUrl($this->makeAttachment('public'))
        );
    }

    /**
     * 공개 자산 디스크 설정 + 행 disk 불일치(설정 이전 업로드분) — 프록시여야 합니다.
     *
     * @scenario public_asset_disk=public,row_disk=attachments,filter_url_hook=absent
     *
     * @effects proxy_url_otherwise
     */
    public function test_legacy_row_on_other_disk_returns_proxy_url(): void
    {
        Config::set('core.storage.public_asset_disk', 'public');
        $this->storage->shouldNotReceive('withDisk');

        $this->assertProxyUrl($this->service->resolveUrl($this->makeAttachment('attachments')));
    }

    /**
     * 설정된 공개 자산 디스크가 config 에 없으면(고아) 프록시여야 합니다.
     *
     * @scenario public_asset_disk=ghost_disk,row_disk=attachments,filter_url_hook=absent
     *
     * @effects proxy_url_otherwise
     */
    public function test_orphan_public_asset_disk_returns_proxy_url(): void
    {
        Config::set('core.storage.public_asset_disk', 'vanished_plugin_disk');
        $this->storage->shouldNotReceive('withDisk');

        $this->assertProxyUrl($this->service->resolveUrl($this->makeAttachment('vanished_plugin_disk')));
    }

    /**
     * `core.storage.filter_url` 훅이 URL 을 차단해 url() 이 null 이면 프록시로 폴백해야 합니다.
     *
     * @scenario public_asset_disk=public,row_disk=public,filter_url_hook=blocks
     *
     * @effects proxy_url_otherwise
     */
    public function test_blocked_url_hook_falls_back_to_proxy_url(): void
    {
        Config::set('core.storage.public_asset_disk', 'public');

        $this->storage->shouldReceive('withDisk')->once()->with('public')->andReturnSelf();
        $this->storage->shouldReceive('url')->once()->andReturnNull();

        $this->assertProxyUrl($this->service->resolveUrl($this->makeAttachment('public')));
    }

    /**
     * 공개 자산 디스크가 s3(비공개 버킷)이고 행 disk 도 s3 — 직접 URL 이 발급돼야 합니다.
     *
     * 운영자가 "이 저장소는 공개다" 라고 **선언한** 결과이므로 시스템은 그 선언을 따른다.
     * 버킷이 실제로는 비공개여서 그 주소가 403 이 되는 것은 설정 책임이며, 문서의
     * "버킷/CDN 쪽 요구사항" 이 그 조건을 명시한다. 선언하지 않은 상태(unset)에서
     * 같은 행이 프록시로 남는 것과 대비되는 축이다.
     *
     * @scenario public_asset_disk=s3_private,row_disk=s3,filter_url_hook=absent
     *
     * @effects direct_url_when_row_matches_public_disk
     */
    public function test_declared_s3_disk_with_matching_row_returns_direct_url(): void
    {
        Config::set('core.storage.public_asset_disk', 's3');
        Config::set('filesystems.disks.s3.url', 'https://bucket.s3.ap-northeast-2.amazonaws.com');

        $this->storage->shouldReceive('withDisk')->once()->with('s3')->andReturnSelf();
        $this->storage->shouldReceive('url')->once()
            ->andReturn('https://bucket.s3.ap-northeast-2.amazonaws.com/template-layout-attachments/sirsoft-basic/2026/09/08/bg.png');

        $this->assertSame(
            'https://bucket.s3.ap-northeast-2.amazonaws.com/template-layout-attachments/sirsoft-basic/2026/09/08/bg.png',
            $this->service->resolveUrl($this->makeAttachment('s3'))
        );
    }

    /**
     * 공개 자산 디스크가 s3 인데 행 disk 가 public — 불일치이므로 프록시여야 합니다.
     *
     * 공개 자산 디스크를 public → s3 로 재구성한 뒤 남은 과거 행의 상황이다.
     * 파일은 public 에 그대로 있고 프록시가 행 disk 로 서빙하므로 화면은 정상이다.
     *
     * @scenario public_asset_disk=s3_private,row_disk=public,filter_url_hook=absent
     *
     * @effects proxy_url_otherwise
     */
    public function test_reconfigured_public_asset_disk_demotes_old_rows_to_proxy(): void
    {
        Config::set('core.storage.public_asset_disk', 's3');
        Config::set('filesystems.disks.s3.url', 'https://bucket.s3.ap-northeast-2.amazonaws.com');
        $this->storage->shouldNotReceive('withDisk');

        $this->assertProxyUrl($this->service->resolveUrl($this->makeAttachment('public')));
    }

    /**
     * 공개 자산 디스크가 public 인데 행 disk 가 s3 — 불일치이므로 프록시여야 합니다.
     *
     * 첨부 디스크(s3)와 공개 자산 디스크(public)를 다르게 둔 이상적 혼재 구성에서,
     * 설정 이전에 s3 로 올라간 행이 직접 URL 로 승격되지 않아야 한다.
     *
     * @scenario public_asset_disk=public,row_disk=s3,filter_url_hook=absent
     *
     * @effects proxy_url_otherwise
     */
    public function test_mixed_disks_keep_old_s3_rows_on_proxy(): void
    {
        Config::set('core.storage.public_asset_disk', 'public');
        Config::set('attachment.disk', 's3');
        Config::set('filesystems.disks.s3.url', 'https://bucket.s3.ap-northeast-2.amazonaws.com');
        $this->storage->shouldNotReceive('withDisk');

        $this->assertProxyUrl($this->service->resolveUrl($this->makeAttachment('s3')));
    }

    /**
     * 훅이 차단하는데 행 disk 가 공개 자산 디스크와 불일치 — 훅이 발화하지도 않고 프록시여야 합니다.
     *
     * 게이트가 url() 호출 앞에 있으므로 훅은 개입할 기회조차 얻지 못한다.
     *
     * @scenario public_asset_disk=public,row_disk=s3,filter_url_hook=blocks
     *
     * @effects proxy_url_otherwise
     */
    public function test_hook_never_fires_when_row_disk_does_not_match(): void
    {
        Config::set('core.storage.public_asset_disk', 'public');
        $this->storage->shouldNotReceive('withDisk');
        $this->storage->shouldNotReceive('url');

        $this->assertProxyUrl($this->service->resolveUrl($this->makeAttachment('s3')));
    }
}
