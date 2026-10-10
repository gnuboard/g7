<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Http\Requests;

use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\AdminClaimRequestPreviewRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\AdminStoreExchangeRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\AdminStoreReturnRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkClaimOptionStatusRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\BulkClaimStatusRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\ClaimLogsRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateClaimStatusRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\UpdateExchangeModeRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\WithdrawClaimOnBehalfRequest;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 요청 검증 문구의 필드 라벨 (#599 — develop 7.1.0 16차 병합 후속)
 *
 * 라벨이 없으면 Laravel 은 필드 키를 풀어쓴 영문을 오류 문구에 넣는다(「sort order 은(는) 올바르지
 * 않습니다」). 클레임 요청들은 `attributes()` 로 키마다 라벨을 주고, 라벨 문구는 언어 파일의
 * `request_attributes`·`claim_settings_attributes` 그룹에 둔다. 이 테스트는 세 가지를 본다 —
 * 요청 코드가 가리키는 라벨 키가 모듈이 싣는 두 로케일(ko·en)에서 번역문으로 풀리는가(오타면 키
 * 문자열이 그대로 나간다 — ja 는 언어팩이 같은 키로 싣고 언어팩 동기 검사가 본다), 실제 검증
 * 실패 문구에 한국어 라벨이 실리는가, 트레이트가 넣는 규칙 키에도 라벨이 있는가. 요청 파일에 글자로
 * 적힌 규칙 키는 audit 룰 `formrequest-attribute-label-coverage` 가 파일마다 판정하지만, 트레이트
 * 키는 그 룰이 읽지 못해 여기서 요청을 실제로 만들어 본다(트러블슈팅 `[case:backend-47]` 의 회귀 그물).
 *
 * 소유 시나리오: `claim-request-attribute-labels`
 */
class ClaimRequestAttributeLabelsTest extends ModuleTestCase
{
    /**
     * 요청 코드가 쓰는 라벨 키를 모두 모읍니다.
     *
     * @return array<int, string> `sirsoft-ecommerce::validation.…` 번역 키
     */
    private function referencedLabelKeys(): array
    {
        $root = dirname(__DIR__, 4).'/src/Http/Requests';
        $keys = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all(
                "/'(sirsoft-ecommerce::validation\\.(?:request_attributes|claim_settings_attributes)\\.[a-z0-9_]+)'/",
                (string) file_get_contents($file->getPathname()),
                $matches
            );
            array_push($keys, ...$matches[1]);
        }

        return array_values(array_unique($keys));
    }

    /**
     * 요청 코드가 가리키는 라벨 키는 한국어·영어 언어 파일에서 모두 번역문으로 풀린다.
     *
     * 키를 잘못 적으면 `__()` 가 키 문자열을 돌려주고, 그 문자열이 그대로 오류 문구에 실린다 —
     * 예외도 경고도 없으므로 여기서 잡는다.
     *
     * @scenario check=label_keys_resolve
     *
     * @effects claim_request_label_keys_resolve_in_every_locale
     *
     * @return void 반환값 없음
     */
    public function test_every_referenced_label_key_resolves_in_every_locale(): void
    {
        $keys = $this->referencedLabelKeys();
        $this->assertGreaterThan(100, count($keys), '라벨 키 수집이 비었습니다 — 요청 경로나 그룹 이름이 바뀌었는지 확인하세요.');

        $unresolved = [];
        foreach (['ko', 'en'] as $locale) {
            foreach ($keys as $key) {
                if (trans($key, [], $locale) === $key) {
                    $unresolved[] = "{$locale}: {$key}";
                }
            }
        }

        $this->assertSame([], $unresolved, "번역문이 없는 라벨 키:\n - ".implode("\n - ", $unresolved));
    }

    /**
     * 커스텀 문구가 없는 검증 실패는 필드 키 대신 한국어 라벨을 싣는다.
     *
     * @scenario check=validation_message
     *
     * @effects claim_request_validation_message_names_the_field_in_korean
     *
     * @return void 반환값 없음
     */
    public function test_validation_messages_carry_the_korean_label(): void
    {
        app()->setLocale('ko');

        $cases = [
            [new ClaimLogsRequest, ['sort_order' => 'sideways'], 'sort_order', '정렬 순서'],
            [new WithdrawClaimOnBehalfRequest, ['reason' => ['not', 'a', 'string']], 'reason', '철회 사유'],
            [new UpdateExchangeModeRequest, ['mode' => ['not', 'a', 'string']], 'mode', '교환 진행 방식'],
            // 트레이트가 넣는 규칙 키 — 관리자 직권 접수 미리보기가 「refund bank.holder 필드는 …」 을 돌려줬다.
            [new AdminStoreReturnRequest, ['refund_bank' => ['holder' => str_repeat('가', 51)]], 'refund_bank.holder', '예금주'],
            [new AdminStoreExchangeRequest, ['items' => [['order_option_id' => 1, 'quantity' => 'abc']]], 'items.0.quantity', '수량'],
        ];

        foreach ($cases as [$request, $input, $field, $label]) {
            $message = Validator::make($input, $request->rules(), $request->messages(), $request->attributes())
                ->errors()
                ->first($field);

            $this->assertStringContainsString($label, $message, $request::class.' 의 '.$field.' 오류 문구에 라벨이 없습니다: '.$message);
            $this->assertStringNotContainsString(str_replace('_', ' ', $field), $message, '필드 키를 풀어쓴 영문이 그대로 나갔습니다: '.$message);
        }
    }

    /**
     * 트레이트가 넣는 규칙 키도 모두 라벨을 가진다.
     *
     * audit 룰은 요청 파일에 글자로 적힌 규칙 키만 읽는다. 관리자 직권 접수와 클레임 상태 변경 요청은
     * 규칙 일부를 트레이트(`ValidatesAdminClaimRequest` · `ResolvesClaimCompletionOptions` ·
     * `ValidatesClaimTransitionTracking`)에서 받으므로, 요청을 실제로 만들어 `rules()` 가 돌려준 키
     * 전부를 `attributes()` 와 대조한다.
     *
     * @scenario check=trait_rule_keys
     *
     * @effects claim_request_trait_rule_keys_have_labels
     *
     * @return void 반환값 없음
     */
    public function test_trait_provided_rule_keys_have_labels(): void
    {
        $requests = [
            AdminClaimRequestPreviewRequest::class,
            AdminStoreExchangeRequest::class,
            AdminStoreReturnRequest::class,
            BulkClaimOptionStatusRequest::class,
            BulkClaimStatusRequest::class,
            UpdateClaimStatusRequest::class,
        ];

        $missing = [];
        foreach ($requests as $class) {
            $request = new $class;
            $labels = $request->attributes();

            foreach (array_keys($request->rules()) as $key) {
                if (! array_key_exists($key, $labels)) {
                    $missing[] = class_basename($class).': '.$key;
                }
            }
        }

        $this->assertSame([], $missing, "라벨이 없는 규칙 키:\n - ".implode("\n - ", $missing));
    }
}
