<?php

namespace Tests\Unit\Support;

use App\Support\TemplateExternals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateExternalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalizes_supported_externals_and_attributes(): void
    {
        $externals = TemplateExternals::normalize([
            [
                'id' => 'style-main',
                'type' => 'style',
                'url' => 'https://cdn.example.com/main.css',
                'preconnect' => 'https://cdn.example.com',
                'crossorigin' => true,
                'integrity' => 'sha384-style',
                'referrerpolicy' => 'no-referrer',
                'media' => 'screen',
            ],
            [
                'id' => 'script-default',
                'type' => 'script',
                'url' => 'https://cdn.example.com/default.js',
                'defer' => true,
            ],
            [
                'id' => 'preload-font',
                'type' => 'preload',
                'url' => 'https://cdn.example.com/font.woff2',
                'as' => 'font',
                'mimeType' => 'font/woff2',
                'fetchpriority' => 'high',
                'crossorigin' => 'use-credentials',
            ],
        ]);

        $this->assertCount(3, $externals);
        $this->assertSame('anonymous', $externals[0]['crossorigin']);
        $this->assertSame('before-template', $externals[1]['position']);
        $this->assertTrue($externals[1]['defer']);
        $this->assertSame('font/woff2', $externals[2]['mimeType']);
        $this->assertSame('high', $externals[2]['fetchpriority']);

        $styleAttributes = TemplateExternals::linkAttributes($externals[0]);
        $this->assertSame('stylesheet', $styleAttributes['rel']);
        $this->assertSame('https://cdn.example.com/main.css', $styleAttributes['href']);
        $this->assertSame('style-main', $styleAttributes['id']);
        $this->assertSame('screen', $styleAttributes['media']);

        $scriptAttributes = TemplateExternals::scriptAttributes($externals[1]);
        $this->assertSame('https://cdn.example.com/default.js', $scriptAttributes['src']);
        $this->assertTrue($scriptAttributes['defer']);
    }

    public function test_filters_invalid_and_legacy_external_declarations(): void
    {
        $externals = TemplateExternals::normalize([
            ['url' => 'https://legacy.example.com/style.css'],
            ['type' => 'style', 'url' => 'http://cdn.example.com/insecure.css'],
            ['type' => 'preload', 'url' => 'https://cdn.example.com/missing-as.woff2'],
            ['type' => 'script', 'url' => 'https://cdn.example.com/both.js', 'async' => true, 'defer' => true],
            ['type' => 'script', 'url' => 'https://cdn.example.com/invalid-position.js', 'position' => 'after-core'],
            ['type' => 'style', 'url' => 'https://cdn.example.com/valid.css'],
            ['type' => 'style', 'url' => 'https://cdn.example.com/valid.css'],
        ]);

        $this->assertSame([
            [
                'type' => 'style',
                'url' => 'https://cdn.example.com/valid.css',
            ],
        ], $externals);
    }

    public function test_resource_hints_are_deduplicated_by_type_and_origin(): void
    {
        $externals = TemplateExternals::normalize([
            [
                'type' => 'preconnect',
                'url' => 'https://cdn.example.com',
                'crossorigin' => 'anonymous',
            ],
            [
                'type' => 'webfont',
                'url' => 'https://cdn.example.com/font.css',
                'preconnect' => 'https://cdn.example.com',
                'crossorigin' => true,
            ],
            [
                'type' => 'dns-prefetch',
                'url' => 'https://static.example.com',
            ],
        ]);

        $hints = TemplateExternals::resourceHints($externals);

        $this->assertCount(2, $hints);
        $this->assertSame('preconnect', $hints[0]['type']);
        $this->assertSame('https://cdn.example.com', $hints[0]['url']);
        $this->assertSame('dns-prefetch', $hints[1]['type']);
    }
}
