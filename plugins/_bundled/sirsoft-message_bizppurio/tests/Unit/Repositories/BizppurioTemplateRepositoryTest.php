<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Unit\Repositories;

use App\Models\NotificationDefinition;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioTemplate;
use Plugins\Sirsoft\MessageBizppurio\Repositories\BizppurioTemplateRepository;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * BizppurioTemplateRepository — 다국어 표시명 검색의 JSON 경로 테스트 (#148).
 *
 * 번역 언어에 하이픈이 든 코드(`fil-PH`)가 켜지면 `'$.fil-PH'` 가 잘못된 JSON 경로라
 * MySQL 이 3143 으로 목록 검색 전체를 500 으로 실패시켰다. 키를 따옴표로 감싼다(`'$."fil-PH"'`).
 */
class BizppurioTemplateRepositoryTest extends PluginTestCase
{
    /**
     * 하이픈 로케일이 켜져 있어도 검색이 실패하지 않고 그 언어의 표시명으로 찾는다.
     *
     * @scenario shape=region_tagged
     *
     * @effects json_path_search_quotes_hyphen_locale
     *
     * @return void 반환값 없음
     */
    public function test_paginate_with_definitions_search_handles_region_tagged_locale(): void
    {
        config(['app.translatable_locales' => ['ko', 'en', 'fil-PH']]);

        NotificationDefinition::create([
            'type' => 'welcome',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '회원가입 환영', 'en' => 'Welcome', 'fil-PH' => 'Maligayang pagdating'],
            'variables' => [['key' => 'name', 'description' => '이름']],
            'channels' => ['mail'],
            'hooks' => ['core.auth.after_register'],
            'is_active' => true,
        ]);
        BizppurioTemplate::create(['notification_type' => 'welcome', 'status' => 'draft']);

        $result = (new BizppurioTemplateRepository)->paginateWithDefinitions(['search' => 'Maligayang'], 1, 10);

        $this->assertSame(1, $result->total());
        $this->assertSame('welcome', $result->items()[0]->notification_type);
    }
}
