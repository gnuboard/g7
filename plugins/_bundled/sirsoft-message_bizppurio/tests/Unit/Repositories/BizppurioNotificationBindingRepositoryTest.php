<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Repositories;

use Plugins\Sirsoft\MessageBizppurio\Repositories\BizppurioNotificationBindingRepository;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * BizppurioNotificationBindingRepository — upsert·findActive·delete 검증.
 */
class BizppurioNotificationBindingRepositoryTest extends PluginTestCase
{
    private BizppurioNotificationBindingRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new BizppurioNotificationBindingRepository;
    }

    public function test_upsert_creates_then_updates(): void
    {
        $created = $this->repo->upsert('welcome', 'alimtalk', [
            'template_code' => 'TW_1',
            'template_name' => '가입환영',
            'fallback_sms_enabled' => true,
        ]);
        $this->assertSame('TW_1', $created->template_code);

        // 같은 (type, channel) 재저장 → 갱신(중복 생성 아님)
        $updated = $this->repo->upsert('welcome', 'alimtalk', [
            'template_code' => 'TW_2',
            'template_name' => '가입환영v2',
            'fallback_sms_enabled' => false,
        ]);

        $this->assertSame($created->id, $updated->id);
        $this->assertSame('TW_2', $updated->template_code);
        $this->assertDatabaseCount('bizppurio_notification_bindings', 1);
    }

    public function test_find_active_only_returns_active(): void
    {
        $this->repo->upsert('welcome', 'alimtalk', [
            'template_code' => 'TW_1',
            'template_name' => 'n',
            'is_active' => true,
        ]);

        $this->assertNotNull($this->repo->findActive('welcome'));

        $this->repo->upsert('welcome', 'alimtalk', ['is_active' => false, 'template_code' => 'TW_1', 'template_name' => 'n']);
        $this->assertNull($this->repo->findActive('welcome'));
    }

    public function test_delete(): void
    {
        $this->repo->upsert('order_confirmed', 'alimtalk', [
            'template_code' => 'TW_9',
            'template_name' => '주문완료',
        ]);

        $this->repo->delete('order_confirmed', 'alimtalk');
        $this->assertNull($this->repo->findActive('order_confirmed'));
        $this->assertDatabaseCount('bizppurio_notification_bindings', 0);
    }
}
