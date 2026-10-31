<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Exceptions;

use Modules\Sirsoft\Ecommerce\Exceptions\CurrencyConversionException;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * CurrencyConversionException 단위 테스트
 *
 * 이 예외는 번역문이 아니라 **메시지 키와 치환 파라미터**를 보관한다 — 이미 번역된 문장을
 * 응답의 메시지 키 자리로 넘기면 키 해석에 실패해 원문이 그대로 화면에 나가기 때문이다.
 * 형제 예외(`CartOperationException` 등)가 같은 형태이며, 그 규약을 이 테스트가 고정한다.
 *
 * 동시에 **기존 catch 계약**도 고정한다 — 결제 플러그인 네 곳이 이 예외를
 * `InvalidArgumentException` 으로 잡아 `getMessage()` 를 로그와 결제 실패 사유로 저장한다.
 * 그 자리에 키가 흘러가면 운영자에게 `sirsoft-ecommerce::exceptions.…` 가 그대로 보인다.
 *
 * 모집단은 서비스 소스를 스캔해 파생한다 — throw 지점이 하나 늘면 자동으로 대조 대상이
 * 되고, 스캔 결과가 비면(경로가 바뀌었거나 스캔이 깨졌으면) 그 자체로 실패한다.
 */
class CurrencyConversionExceptionTest extends ModuleTestCase
{
    /**
     * 메시지 키 접두사 (모듈 다국어 네임스페이스).
     */
    private const KEY_PREFIX = 'sirsoft-ecommerce::exceptions.';

    /**
     * throw 지점이 있어야 할 최소 개수 (하한 — 모집단이 비면 실패).
     */
    private const MIN_THROW_SITES = 3;

    /**
     * 키가 정의되어 있어야 할 로케일.
     */
    private const LOCALES = ['ko', 'en'];

    /**
     * 예외는 키와 치환 파라미터를 그대로 돌려준다.
     */
    public function test_it_carries_the_message_key_and_params(): void
    {
        $e = new CurrencyConversionException(
            self::KEY_PREFIX.'unknown_currency',
            ['currency' => 'GBP']
        );

        $this->assertSame(self::KEY_PREFIX.'unknown_currency', $e->getMessageKey());
        $this->assertSame(['currency' => 'GBP'], $e->getMessageParams());
        $this->assertSame('GBP', $e->getCurrency());
    }

    /**
     * 치환 파라미터에 통화가 없으면 빈 문자열로 떨어진다 (호출부가 null 을 다루지 않게).
     */
    public function test_currency_falls_back_to_empty_string(): void
    {
        $e = new CurrencyConversionException(self::KEY_PREFIX.'unknown_currency');

        $this->assertSame([], $e->getMessageParams());
        $this->assertSame('', $e->getCurrency());
    }

    /**
     * `getMessage()` 는 해석된 번역문이어야 한다 — 키가 그대로 남으면 결제 플러그인이
     * 저장하는 실패 사유에 키 문자열이 노출된다.
     */
    public function test_message_is_the_resolved_translation_not_the_key(): void
    {
        $key = self::KEY_PREFIX.'unknown_currency';
        $e = new CurrencyConversionException($key, ['currency' => 'GBP']);

        $this->assertNotSame($key, $e->getMessage());
        $this->assertNotEmpty($e->getMessage());
        $this->assertStringNotContainsString('::', $e->getMessage());
        // 치환이 실제로 적용됐는가 (`:currency` 자리표시자가 남지 않는다).
        $this->assertStringContainsString('GBP', $e->getMessage());
        $this->assertStringNotContainsString(':currency', $e->getMessage());
    }

    /**
     * 기존 catch 계약 — 베이스 `InvalidArgumentException` 으로 잡힌다.
     */
    public function test_it_is_catchable_as_invalid_argument_exception(): void
    {
        $this->assertInstanceOf(
            \InvalidArgumentException::class,
            new CurrencyConversionException(self::KEY_PREFIX.'unknown_currency')
        );
    }

    /**
     * 서비스의 throw 지점 **전량**이 번역문이 아니라 키를 넘긴다.
     *
     * 모집단은 소스 스캔으로 파생한다. 어느 한 지점이 `__()` 로 번역해 넘기도록 되돌아가면
     * 그 지점에서 실패한다.
     */
    public function test_every_throw_site_passes_a_message_key(): void
    {
        $keys = $this->thrownMessageKeys();

        $this->assertGreaterThanOrEqual(
            self::MIN_THROW_SITES,
            count($keys),
            'CurrencyConversionService 에서 throw 지점을 찾지 못했습니다 — 스캔 경로가 깨졌거나 지점이 사라졌습니다.'
        );

        foreach ($keys as $key) {
            $this->assertStringStartsWith(
                self::KEY_PREFIX,
                $key,
                sprintf('throw 인자 "%s" 가 메시지 키가 아닙니다 — 번역문을 넘기면 응답의 키 자리에서 해석에 실패합니다.', $key)
            );
        }
    }

    /**
     * 던져지는 키가 전 로케일에 정의되어 있다 — 미정의 키는 화면에 키 문자열로 나간다.
     */
    public function test_every_thrown_key_is_defined_in_all_locales(): void
    {
        $keys = $this->thrownMessageKeys();

        $this->assertNotEmpty($keys, 'throw 지점 모집단이 비었습니다.');

        foreach (self::LOCALES as $locale) {
            $lines = require dirname(__DIR__, 3)."/src/lang/{$locale}/exceptions.php";

            foreach (array_unique($keys) as $key) {
                $shortKey = substr($key, strlen(self::KEY_PREFIX));

                $this->assertArrayHasKey(
                    $shortKey,
                    $lines,
                    sprintf('%s 로케일에 exceptions.%s 가 없습니다.', $locale, $shortKey)
                );
            }
        }
    }

    /**
     * 서비스 소스에서 `throw new CurrencyConversionException(...)` 의 첫 인자를 모읍니다.
     *
     * 같은 키를 던지는 지점이 둘 이상일 수 있으므로 **중복을 제거하지 않는다** — 하한이
     * 세는 것은 키 종류가 아니라 지점 수다.
     *
     * @return list<string> throw 지점별 첫 인자 리터럴 목록
     */
    private function thrownMessageKeys(): array
    {
        $source = file_get_contents(
            dirname(__DIR__, 3).'/src/Services/CurrencyConversionService.php'
        );

        if ($source === false) {
            return [];
        }

        preg_match_all(
            "/throw\s+new\s+CurrencyConversionException\s*\(\s*(?:'([^']*)'|\"([^\"]*)\")/",
            $source,
            $matches,
            PREG_SET_ORDER
        );

        $keys = [];

        foreach ($matches as $match) {
            $keys[] = $match[1] !== '' ? $match[1] : ($match[2] ?? '');
        }

        return $keys;
    }
}
