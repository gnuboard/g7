<?php

namespace Plugins\Sirsoft\Sso\Services\Admin;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Models\User;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Repositories\Contracts\IdentityProviderRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoAccountRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\AdminAccountRepositoryInterface;
use Plugins\Sirsoft\Sso\Services\AccountResolver;

/**
 * 「회원 연결」 CSV 가져오기 — 미리보기와 실행 (D6 ③, P7, W26, K12 `record_consents`).
 *
 * 열: `email,name,idp_key,subject`. 행마다 결과를 정한다 — `create`(회원이 없어 비밀번호 없이 만들고 연결) / `link`(같은 이메일 회원에
 * 연결) / `exists`(이미 같은 연결) / `conflict`(subject 가 다른 회원에, 또는 그 회원이 그 IdP 의 다른 subject 에 연결) /
 * `admin_target_manual_only`(관리자, 최고관리자 계정 — CSV 로는 연결하지 않는다) / `error`(칸 오류, 없는 IdP).
 * 미리보기는 아무것도 바꾸지 않는다. 실행은 `create`, `link` 행만 처리한다.
 *
 * @since 1.0.0
 */
class CsvImportService
{
    use ResolvesActivityLogType;

    /** 한 번에 받는 행 상한 */
    public const MAX_ROWS = 1000;

    /** @var array<int, string> 머리 행 */
    public const COLUMNS = ['email', 'name', 'idp_key', 'subject'];

    /** @var array<int, string> 결과 종류 */
    public const OUTCOMES = ['create', 'link', 'exists', 'conflict', 'admin_target_manual_only', 'error'];

    /**
     * @param  IdentityProviderRepositoryInterface  $idps  IdP 저장소
     * @param  SsoAccountRepositoryInterface  $links  연결 저장소
     * @param  AdminAccountRepositoryInterface  $accounts  관리자 연결 저장소
     * @param  AccountAdminService  $admin  연결 추가와 사전 등록
     */
    public function __construct(
        private readonly IdentityProviderRepositoryInterface $idps,
        private readonly SsoAccountRepositoryInterface $links,
        private readonly AdminAccountRepositoryInterface $accounts,
        private readonly AccountAdminService $admin,
    ) {}

    /**
     * CSV 를 판정합니다 (실행이면 처리까지).
     *
     * @param  string  $csv  CSV 본문 (UTF-8, BOM 허용)
     * @param  bool  $execute  실행 여부 (false 면 미리보기)
     * @param  User  $actor  관리자
     * @return array{rows: array<int, array<string, mixed>>, counts: array<string, int>, executed: bool} 결과
     *
     * @throws SsoException 머리 행 오류, 행 상한 초과
     */
    public function import(string $csv, bool $execute, User $actor): array
    {
        $records = $this->parse($csv);
        $rows = [];
        $seen = [];
        $idpCache = [];

        foreach ($records as $index => $record) {
            $row = $this->judge($record, $index + 2, $seen, $idpCache);

            if ($execute && in_array($row['outcome'], ['create', 'link'], true)) {
                $row = $this->apply($row, $idpCache[$row['idp_key']] ?? null, $actor);
            }

            $rows[] = $row;
        }

        $counts = array_fill_keys(self::OUTCOMES, 0);
        foreach ($rows as $row) {
            $counts[$row['outcome']]++;
        }

        if ($execute) {
            $this->logActivity('sso.csv_imported', [
                'description_key' => 'sirsoft-sso::activity_log.description.sso.csv_imported',
                'description_params' => ['created' => $counts['create'], 'linked' => $counts['link'], 'skipped' => count($rows) - $counts['create'] - $counts['link']],
                'properties' => ['counts' => $counts],
            ]);
        }

        return ['rows' => $rows, 'counts' => $counts, 'executed' => $execute];
    }

    /**
     * CSV 를 행 배열로 바꿉니다.
     *
     * @param  string  $csv  본문
     * @return array<int, array<string, string>> 행 (머리 행 제외)
     *
     * @throws SsoException 머리 행이 맞지 않거나 행이 너무 많으면
     */
    private function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];

        if ($lines === [] || $lines[0] === '') {
            throw new SsoException('csv_empty', '', 422);
        }

        $header = array_map(static fn ($h) => mb_strtolower(trim((string) $h)), str_getcsv(array_shift($lines)));

        if (array_values(array_intersect(self::COLUMNS, $header)) !== self::COLUMNS) {
            throw new SsoException('csv_header_invalid', implode(',', $header), 422);
        }

        $lines = array_values(array_filter($lines, static fn ($line) => trim($line) !== ''));

        if (count($lines) > self::MAX_ROWS) {
            throw new SsoException('csv_too_many_rows', (string) count($lines), 422);
        }

        return array_map(static function (string $line) use ($header) {
            $values = str_getcsv($line);
            $record = [];
            foreach ($header as $i => $name) {
                $record[$name] = trim((string) ($values[$i] ?? ''));
            }

            return $record;
        }, $lines);
    }

    /**
     * 한 행의 결과를 정합니다.
     *
     * @param  array<string, string>  $record  행
     * @param  int  $line  CSV 줄 번호 (머리 행 = 1)
     * @param  array<string, bool>  $seen  이 파일에서 이미 본 (IdP, subject)·(IdP, 이메일)
     * @param  array<string, IdentityProvider|null>  $idpCache  키 → IdP
     * @return array<string, mixed> 판정 행
     */
    private function judge(array $record, int $line, array &$seen, array &$idpCache): array
    {
        $email = mb_strtolower($record['email'] ?? '');
        $key = $record['idp_key'] ?? '';
        $subject = $record['subject'] ?? '';
        $row = ['line' => $line, 'email' => $email, 'name' => $record['name'] ?? '', 'idp_key' => $key, 'subject' => $subject, 'outcome' => 'error', 'reason' => null];

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['reason' => 'email_invalid'] + $row;
        }

        if ($subject === '' || mb_strlen($subject) > 191) {
            return ['reason' => 'subject_invalid'] + $row;
        }

        $idp = $idpCache[$key] ??= ($key !== '' ? $this->idps->findByKey($key) : null);
        if ($idp === null) {
            return ['reason' => 'idp_not_found'] + $row;
        }

        if (isset($seen[$key.'|s|'.$subject]) || isset($seen[$key.'|e|'.$email])) {
            return ['outcome' => 'conflict', 'reason' => 'duplicate_in_file'] + $row;
        }
        $seen[$key.'|s|'.$subject] = true;
        $seen[$key.'|e|'.$email] = true;

        $user = $this->accounts->findUserByEmail($email);
        $bySubject = $this->links->findByIdpSubject((int) $idp->id, $subject);

        if ($bySubject !== null) {
            return $user !== null && (int) $bySubject->user_id === (int) $user->getKey()
                ? ['outcome' => 'exists'] + $row
                : ['outcome' => 'conflict', 'reason' => 'subject_linked_to_other'] + $row;
        }

        if ($user === null) {
            return ['outcome' => 'create'] + $row;
        }

        if (AccountResolver::isPrivileged($user)) {
            return ['outcome' => 'admin_target_manual_only', 'reason' => 'admin_target_manual_only'] + $row;
        }

        if ($this->links->findByIdpUser((int) $idp->id, (int) $user->getKey()) !== null) {
            return ['outcome' => 'conflict', 'reason' => 'user_linked_other_subject'] + $row;
        }

        return ['outcome' => 'link'] + $row;
    }

    /**
     * 판정 행을 처리합니다 — 실패하면 그 행만 `error` 로 남긴다.
     *
     * @param  array<string, mixed>  $row  판정 행
     * @param  IdentityProvider|null  $idp  IdP
     * @param  User  $actor  관리자
     * @return array<string, mixed> 처리 결과 행
     */
    private function apply(array $row, ?IdentityProvider $idp, User $actor): array
    {
        if ($idp === null) {
            return ['outcome' => 'error', 'reason' => 'idp_not_found'] + $row;
        }

        try {
            if ($row['outcome'] === 'create') {
                $user = $this->admin->preregister($idp, ['name' => $row['name'], 'email' => $row['email']], 'csv');
            } else {
                $user = $this->accounts->findUserByEmail((string) $row['email']);
                if ($user === null) {
                    return ['outcome' => 'error', 'reason' => 'user_not_found'] + $row;
                }
                $this->admin->assertLinkable($idp, $user, null);
            }

            $this->admin->attach($idp, $user, (string) $row['subject'], 'csv');
        } catch (SsoException $e) {
            return ['outcome' => $e->errorCode === 'admin_target_manual_only' ? 'admin_target_manual_only' : 'conflict', 'reason' => $e->errorCode] + $row;
        }

        return $row;
    }
}
