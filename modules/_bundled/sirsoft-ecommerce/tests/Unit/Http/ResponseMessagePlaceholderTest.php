<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http;

use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 응답 메시지 플레이스홀더 치환 테스트 (S2-D1 회귀 · #599 통합실측 세션2)
 *
 * `ResponseHelper::moduleSuccess()`·`moduleError()` 는 치환값을 **마지막 인자**
 * (`$messageParams`)로 받는다. 세 번째 인자는 데이터 페이로드다. 그래서
 * `:count` 같은 플레이스홀더를 가진 메시지 키를 부르면서 데이터만 넘기면
 * 치환이 일어나지 않고 응답 `message` 에 **`:count` 가 그대로 실려 나간다**.
 *
 * 실측에서 관리자 주문상세의 상품 상태 일괄변경이 그 상태였다 —
 * `PATCH /admin/orders/{no}/options/bulk-status` 가
 * `":count개 옵션의 상태가 변경되었습니다."` 를 200 으로 돌려줬다.
 * 관리자 화면은 자기 프론트 언어키(`{{count}}`)로 토스트를 그려 가려졌지만,
 * 응답 `message` 를 그대로 쓰는 다른 소비자(외부 API 연동 · 다른 화면)는
 * 치환되지 않은 원문을 본다.
 *
 * 이 테스트는 **호출부를 정적으로 잠근다** — 플레이스홀더를 가진 메시지 키를
 * 쓰는 컨트롤러 호출은 반드시 치환 배열을 함께 넘겨야 한다.
 */
class ResponseMessagePlaceholderTest extends ModuleTestCase
{
    /**
     * 플레이스홀더를 치환해야 하는 호출부 — [파일, 메시지 키, 기대 치환키].
     *
     * 실측에서 확인된 11곳. 새 호출부가 늘면 이 표에 추가한다.
     *
     * 뒤의 6곳은 `$data` 자리에 인라인 배열(`['deleted_count' => $count]`)을 넘겨
     * **치환 배열처럼 보이던** 것들이다 — 인자 "모양" 이 아니라 **위치**로 판정해야
     * 드러난다(`$messageParams` 는 다섯 번째).
     */
    private const CALL_SITES = [
        ['Http/Controllers/Admin/OrderController.php', 'messages.orders.bulk_updated', 'count'],
        ['Http/Controllers/Admin/OrderController.php', 'messages.orders.option_status_changed', 'count'],
        ['Http/Controllers/Admin/ProductOptionController.php', 'messages.options.bulk_price_updated', 'count'],
        ['Http/Controllers/Admin/ProductOptionController.php', 'messages.options.bulk_stock_updated', 'count'],
        ['Http/Controllers/Admin/ProductOptionController.php', 'messages.options.bulk_updated', 'count'],
        ['Http/Controllers/Admin/ExtraFeeTemplateController.php', 'messages.extra_fee_template.bulk_deleted', 'count'],
        ['Http/Controllers/Admin/ExtraFeeTemplateController.php', 'messages.extra_fee_template.bulk_toggled', 'count'],
        ['Http/Controllers/Admin/ExtraFeeTemplateController.php', 'messages.extra_fee_template.bulk_created', 'count'],
        ['Http/Controllers/Admin/ShippingPolicyController.php', 'messages.shipping_policy.bulk_deleted', 'count'],
        ['Http/Controllers/Admin/ShippingPolicyController.php', 'messages.shipping_policy.bulk_toggled', 'count'],
        ['Http/Controllers/Public/CartController.php', 'messages.cart.merged', 'count'],
    ];

    private function srcPath(string $relative): string
    {
        return __DIR__.'/../../../src/'.$relative;
    }

    /**
     * 호출 인자를 최상위 콤마로 가른다 (중첩 배열·문자열 안의 콤마는 무시).
     *
     * @return array<int, string>
     */
    private function splitTopLevelArgs(string $call): array
    {
        $args = [];
        $depth = 0;
        $current = '';
        $length = strlen($call);

        for ($i = 0; $i < $length; $i++) {
            $char = $call[$i];

            if ($char === "'") {
                $close = strpos($call, "'", $i + 1);
                if ($close === false) {
                    $current .= substr($call, $i);
                    break;
                }
                $current .= substr($call, $i, $close - $i + 1);
                $i = $close;

                continue;
            }

            if (in_array($char, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($char, [')', ']', '}'], true)) {
                $depth--;
            }

            if ($char === ',' && $depth === 0) {
                $args[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $args[] = trim($current);
        }

        return $args;
    }

    /**
     * ko·en 메시지 정의에서 그 키의 원문을 읽는다.
     */
    private function messageTemplate(string $locale, string $key): ?string
    {
        $path = $this->srcPath("lang/{$locale}/messages.php");
        $this->assertFileExists($path, "메시지 파일이 없습니다: {$locale}");

        $messages = require $path;

        // 'messages.orders.bulk_updated' -> ['orders', 'bulk_updated']
        $segments = explode('.', $key);
        array_shift($segments);

        $node = $messages;
        foreach ($segments as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return is_string($node) ? $node : null;
    }

    /**
     * 표에 적은 키는 실제로 플레이스홀더를 갖고 있어야 한다.
     *
     * 키에서 플레이스홀더가 사라졌다면 이 테스트의 전제가 바뀐 것이니
     * 표에서 빼야 한다 — 조용히 통과시키지 않는다.
     *
     * @scenario contract=message_key_carries_placeholder
     *
     * @effects placeholder_present_in_ko_and_en
     */
    public function test_listed_message_keys_actually_carry_placeholders(): void
    {
        foreach (self::CALL_SITES as [$file, $key, $param]) {
            foreach (['ko', 'en'] as $locale) {
                $template = $this->messageTemplate($locale, $key);

                $this->assertNotNull($template, "[{$locale}] 메시지 키가 없습니다: {$key}");

                $this->assertStringContainsString(
                    ":{$param}",
                    $template,
                    "[{$locale}] '{$key}' 에 :{$param} 플레이스홀더가 없습니다. "
                        .'표에서 이 항목을 빼거나 기대 치환키를 고치세요.'
                );
            }
        }
    }

    /**
     * 플레이스홀더를 가진 메시지 키를 부르는 호출은 치환 배열을 함께 넘겨야 한다.
     *
     * `ResponseHelper::moduleSuccess($module, $key, $data, $status, $messageParams)`
     * 에서 `$messageParams` 를 빼면 `:count` 가 그대로 응답에 실린다.
     *
     * @scenario contract=call_site_passes_message_params
     *
     * @effects message_params_passed_at_every_placeholder_call_site
     */
    public function test_placeholder_message_calls_pass_replacement_params(): void
    {
        foreach (self::CALL_SITES as [$file, $key, $param]) {
            $path = $this->srcPath($file);
            $this->assertFileExists($path, "컨트롤러 파일이 없습니다: {$file}");

            $source = (string) file_get_contents($path);
            $needle = "'{$key}'";

            $this->assertStringContainsString(
                $needle,
                $source,
                "{$file} 에서 '{$key}' 호출을 찾지 못했습니다."
            );

            // 키가 등장하는 모든 자리를 확인한다.
            $offset = 0;
            $checked = 0;

            while (($position = strpos($source, $needle, $offset)) !== false) {
                $offset = $position + strlen($needle);
                $checked++;

                // 그 호출의 닫는 괄호까지를 잘라 인자를 최상위 콤마로 가른다.
                // `$messageParams` 는 **다섯 번째 인자**다 — 인자 "모양" 으로 보면
                // `$data` 자리의 인라인 배열(`['deleted_count' => $count]`)이
                // 치환 배열처럼 보여 빠져나간다. 위치로 판정한다.
                $tail = substr($source, $position, 800);
                $end = strpos($tail, ');');
                $call = $end !== false ? substr($tail, 0, $end) : $tail;

                $args = $this->splitTopLevelArgs($call);

                // $call 은 메시지 키부터 시작한다 — 그 앞의 $module 인자를 더해
                // moduleSuccess($module, $messageKey, $data, $status, $messageParams)
                // 기준 인덱스 4 는 이 배열의 인덱스 3 이다.
                $this->assertArrayHasKey(
                    3,
                    $args,
                    "{$file} 의 '{$key}' 호출이 \$messageParams 인자 자체를 넘기지 않습니다. "
                        ."응답 message 에 :{$param} 가 그대로 나갑니다."
                );

                $this->assertMatchesRegularExpression(
                    "/\[\s*'{$param}'\s*=>/",
                    $args[3],
                    "{$file} 의 '{$key}' 호출에서 다섯 번째 인자(\$messageParams)가 "
                        ."['{$param}' => …] 가 아닙니다. 실제: {$args[3]}"
                );
            }

            $this->assertGreaterThan(0, $checked, "{$file} 에서 '{$key}' 호출을 세지 못했습니다.");
        }
    }

    /**
     * 메시지 정의에서 **플레이스홀더를 가진 키를 전수 수집**해, 그 키를 부르는 호출이
     * 빠짐없이 치환 배열을 넘기는지 본다.
     *
     * 위의 `CALL_SITES` 표는 **실측으로 찾은 11곳**이다. 표는 그때 있던 것만 잠근다 —
     * 내일 누군가 새 일괄 API 를 추가하며 `$messageParams` 를 빼면 표에 없으니
     * **조용히 통과한다.** 결함이 재발해도 초록인 가드는 가드가 아니다
     * (#599 세션2 계획서준수검증 결함3).
     *
     * 그래서 이 테스트는 표를 읽지 않는다. `modules/_bundled/sirsoft-ecommerce/src/lang/ko/messages.php` 를 훑어 `:이름` 을 가진
     * 키를 전부 모으고, `src/Http/Controllers/` 전체에서 그 키의 호출을 찾아 같은 규칙으로
     * 판정한다. **새 호출부는 추가되는 순간 이 검사에 들어온다.**
     *
     * 표는 그대로 둔다 — 표는 「이 11곳은 실제로 그 결함이었다」는 실측 기록이고,
     * 이 테스트는 「앞으로 늘어나는 것까지」를 맡는다. 둘은 역할이 다르다.
     *
     * @scenario contract=call_site_passes_message_params
     *
     * @effects message_params_passed_at_every_placeholder_call_site
     */
    public function test_every_placeholder_message_key_is_called_with_replacement_params(): void
    {
        $placeholderKeys = $this->collectPlaceholderMessageKeys();

        $this->assertNotEmpty(
            $placeholderKeys,
            '플레이스홀더를 가진 메시지 키를 하나도 찾지 못했습니다 — 수집이 깨졌습니다. '
                .'이 상태로는 아래 루프가 한 번도 돌지 않아 **무엇도 잠그지 않습니다.**'
        );

        $controllerFiles = $this->controllerFiles();

        $this->assertNotEmpty($controllerFiles, '컨트롤러 파일을 찾지 못했습니다 — 경로가 바뀌었습니다.');

        $violations = [];
        $checked = 0;

        foreach ($controllerFiles as $path) {
            $source = (string) file_get_contents($path);
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($this->srcPath(''))));

            foreach ($placeholderKeys as $key => $params) {
                $needle = "'{$key}'";
                $offset = 0;

                while (($position = strpos($source, $needle, $offset)) !== false) {
                    $offset = $position + strlen($needle);

                    // 그 자리가 ResponseHelper 호출인지 확인한다 — 배열 정의·주석의
                    // 같은 문자열을 호출로 오인하면 거짓 실패가 난다.
                    $before = substr($source, max(0, $position - 200), min(200, $position));
                    if (! preg_match('/module(Success|Error)\s*\(\s*[^;]*$/s', $before)) {
                        continue;
                    }

                    $checked++;

                    $tail = substr($source, $position, 800);
                    $end = strpos($tail, ');');
                    $call = $end !== false ? substr($tail, 0, $end) : $tail;
                    $args = $this->splitTopLevelArgs($call);

                    foreach ($params as $param) {
                        if (! array_key_exists(3, $args)) {
                            $violations[] = "{$relative} 의 '{$key}' 호출이 \$messageParams 인자 자체를 "
                                ."넘기지 않습니다 — 응답 message 에 :{$param} 가 그대로 나갑니다.";

                            continue;
                        }

                        if (! preg_match("/\[\s*'{$param}'\s*=>/", $args[3])) {
                            $violations[] = "{$relative} 의 '{$key}' 호출에서 다섯 번째 인자가 "
                                ."['{$param}' => …] 가 아닙니다. 실제: {$args[3]}";
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(
            0,
            $checked,
            '플레이스홀더 키를 쓰는 ResponseHelper 호출을 한 건도 세지 못했습니다 — '
                .'탐지가 깨졌습니다(초록이어도 아무것도 증명하지 않습니다).'
        );

        $this->assertSame(
            [],
            $violations,
            '플레이스홀더가 치환되지 않은 채 응답에 실리는 호출이 있습니다(S2-D1):
  - '
                .implode('
  - ', $violations)
        );
    }

    /**
     * `modules/_bundled/sirsoft-ecommerce/src/lang/ko/messages.php` 에서 **플레이스홀더를 가진 키**를 전수 수집합니다.
     *
     * @return array<string, array<int, string>> 'messages.a.b' => ['count', …]
     */
    private function collectPlaceholderMessageKeys(): array
    {
        $path = $this->srcPath('lang/ko/messages.php');
        $this->assertFileExists($path, '메시지 파일이 없습니다: ko');

        $found = [];
        $walk = function (array $node, string $prefix) use (&$walk, &$found): void {
            foreach ($node as $segment => $value) {
                $key = $prefix === '' ? (string) $segment : "{$prefix}.{$segment}";

                if (is_array($value)) {
                    $walk($value, $key);

                    continue;
                }

                if (! is_string($value)) {
                    continue;
                }

                if (preg_match_all('/:([a-z_]+)/', $value, $matches)) {
                    $found["messages.{$key}"] = array_values(array_unique($matches[1]));
                }
            }
        };

        $walk(require $path, '');

        return $found;
    }

    /**
     * `src/Http/Controllers/` 아래 컨트롤러 파일 전건.
     *
     * @return array<int, string> 절대 경로
     */
    private function controllerFiles(): array
    {
        $root = $this->srcPath('Http/Controllers');

        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
