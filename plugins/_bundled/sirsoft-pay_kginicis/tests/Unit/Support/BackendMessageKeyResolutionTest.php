<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;

/**
 * 백엔드가 API 응답으로 내보내는 메시지 키가 실제로 번역되는지 검증한다.
 *
 * ResponseHelper::pluginError('messages.x.y') 는 백엔드 lang/{locale}/messages.php
 * 에서 키를 찾는다. 프론트엔드 resources/lang/{locale}.json 에만 정의된 키는
 * 해석되지 않아 사용자에게 'sirsoft-pay_kginicis::messages.x.y' 라는 원문 키가
 * 그대로 노출된다 (관리자 주문상세의 CBT 편의점 404 토스트에서 실제 발생).
 *
 * @effects backend_emitted_message_keys_resolve_in_all_locales
 */
class BackendMessageKeyResolutionTest extends PluginTestCase
{
    private const PLUGIN_PATH = __DIR__.'/../../..';

    /**
     * 소스에서 백엔드가 emit 하는 messages.* 키를 수집합니다.
     *
     * @return array<int, string> 수집된 메시지 키 목록
     */
    private function emittedMessageKeys(): array
    {
        $keys = [];
        $dir = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::PLUGIN_PATH.'/src')
        );

        foreach ($dir as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // emit 형식이 두 가지다:
            //   ResponseHelper::pluginError('messages.x.y')                       — 접두 없음
            //   __('sirsoft-pay_kginicis::messages.x.y') / ResponseHelper::error() — 네임스페이스 접두
            // 접두를 선택적으로 허용하지 않으면 후자를 통째로 놓쳐 사각이 생긴다
            // (실제로 cbt_connectivity.checked 미정의가 이 사각으로 통과했다).
            preg_match_all(
                "/'(?:sirsoft-pay_kginicis::)?(messages\.[a-z_]+\.[a-z_0-9]+)'/",
                (string) file_get_contents($file->getPathname()),
                $matches
            );

            foreach ($matches[1] as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * @return array<int, array<int, string>> 로케일 목록
     */
    public static function localeProvider(): array
    {
        return [['ko'], ['en']];
    }

    /**
     * 백엔드가 내보내는 모든 메시지 키가 각 로케일에서 해석되어야 합니다.
     */
    #[DataProvider('localeProvider')]
    public function test_every_emitted_message_key_resolves(string $locale): void
    {
        $emitted = $this->emittedMessageKeys();
        $this->assertNotEmpty($emitted, '백엔드에서 emit 하는 메시지 키를 찾지 못했습니다.');

        $unresolved = [];

        foreach ($emitted as $key) {
            $namespaced = 'sirsoft-pay_kginicis::'.$key;
            $translated = __($namespaced, [], $locale);

            // 번역이 없으면 Laravel 은 키 문자열을 그대로 돌려준다.
            if ($translated === $namespaced) {
                $unresolved[] = $key;
            }
        }

        $this->assertSame(
            [],
            $unresolved,
            sprintf(
                "[%s] 백엔드 lang/%s/messages.php 에 정의되지 않은 키가 API 응답으로 노출됩니다:\n  - %s",
                $locale,
                $locale,
                implode("\n  - ", $unresolved)
            )
        );
    }
}
