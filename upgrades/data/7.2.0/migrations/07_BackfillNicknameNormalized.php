<?php

namespace App\Upgrades\Data\V7_2_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Normalizer;

/**
 * 기존 회원의 닉네임 정규화 값(`users.nickname_normalized`)을 채웁니다 (7.2.0).
 *
 * 7.2.0 은 닉네임 중복 판정, 금지어 비교, 관리자 목록의 「닉네임 중복」 필터를 정규화 값으로 한다. 이 컬럼은 7.2.0
 * 마이그레이션이 비운 채로 더하고, 값은 회원을 저장할 때 채워진다. 업그레이드한 사이트의 기존 회원은 저장된 적이 없어
 * 값이 비어 있으므로, 그대로 두면 기존 회원과 같은 닉네임으로 가입해도 중복으로 잡히지 않고 필터에도 나오지 않는다.
 *
 * 정규화 규칙(7.2.0 `App\Support\NicknameNormalizer` 의 규칙을 동결해 옮긴 것): NFKC(`intl` 확장이 있을 때만) →
 * 소문자 → 공백과 폭 없는 문자 제거. 결과가 비면 값을 쓰지 않는다.
 *
 * 닉네임 중복 금지용 값(`nickname_unique_key`)은 건드리지 않는다 — 그 값은 정책을 켤 때 도는 재계산 작업이 채운다.
 * 회원의 수정 시각(`updated_at`)도 바꾸지 않는다.
 *
 * 멱등: 닉네임이 있고 정규화 값이 비어 있는 행만 고른다. 두 번째 실행에서는 고칠 행이 없다(공백뿐인 닉네임은 정규화
 * 결과가 비어 매번 골라지지만 쓰지 않는다). 대량 갱신은 `chunkById` 로 나눠 한 번에 500행씩 읽는다.
 *
 * V-1 안전 격리: DB/Schema 파사드만 쓴다. 모델과 서비스를 해석하지 않는다.
 */
final class BackfillNicknameNormalized implements DataMigration
{
    /** 한 번에 읽는 회원 수 */
    private const CHUNK_SIZE = 500;

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'BackfillNicknameNormalized';
    }

    /**
     * 정규화 값이 비어 있는 회원의 닉네임을 정규화해 채웁니다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable('users')
            || ! Schema::hasColumn('users', 'nickname')
            || ! Schema::hasColumn('users', 'nickname_normalized')) {
            $context->logger->warning('[7.2.0] 닉네임 정규화 값 채우기 건너뜀 — users 표에 nickname_normalized 컬럼이 없음(마이그레이션 실행 여부 확인)');

            return;
        }

        $filled = 0;
        $blank = 0;

        DB::table('users')
            ->select(['id', 'nickname'])
            ->whereNotNull('nickname')
            ->where('nickname', '!=', '')
            ->whereNull('nickname_normalized')
            ->chunkById(self::CHUNK_SIZE, function ($rows) use (&$filled, &$blank): void {
                foreach ($rows as $row) {
                    $normalized = $this->normalize((string) $row->nickname);
                    if ($normalized === null) {
                        $blank++;

                        continue;
                    }

                    // 읽은 뒤 다른 요청이 그 회원을 저장했으면(값이 이미 채워짐) 덮지 않는다.
                    $filled += DB::table('users')
                        ->where('id', $row->id)
                        ->whereNull('nickname_normalized')
                        ->update(['nickname_normalized' => $normalized]);
                }
            });

        if ($filled === 0) {
            $context->logger->info('[7.2.0] 닉네임 정규화 값을 채울 회원 없음 — 변경 없음', ['blank_nicknames' => $blank]);

            return;
        }

        $context->logger->info('[7.2.0] 기존 회원의 닉네임 정규화 값을 채웠습니다', [
            'filled' => $filled,
            'blank_nicknames' => $blank,
        ]);
    }

    /**
     * 닉네임을 정규화합니다 (7.2.0 규칙 동결: NFKC → 소문자 → 공백 제거).
     *
     * @param  string  $nickname  닉네임 원문
     * @return string|null 정규화 값 (비어 있거나 컬럼 길이를 넘으면 null)
     */
    private function normalize(string $nickname): ?string
    {
        $value = $nickname;
        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);
            $value = is_string($normalized) ? $normalized : $value;
        }

        $value = mb_strtolower($value, 'UTF-8');
        $value = (string) preg_replace('/[\s\x{200B}-\x{200D}\x{FEFF}]+/u', '', $value);

        // 컬럼은 191자다. 닉네임은 50자 제한이라 넘을 일이 없지만, 넘는 값은 쓰지 않는다(저장 오류로 스텝을 멈추지 않는다).
        return $value === '' || mb_strlen($value, 'UTF-8') > 191 ? null : $value;
    }
}
