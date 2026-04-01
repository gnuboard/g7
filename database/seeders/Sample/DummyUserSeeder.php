<?php

namespace Database\Seeders\Sample;

use App\Models\Role;
use App\Models\User;
use App\Traits\HasSeederCounts;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DummyUserSeeder extends Seeder
{
    use HasSeederCounts;

    /**
     * 영문 이름 목록 (이메일 생성용)
     *
     * @var array<string>
     */
    private array $englishFirstNames = [
        'james', 'john', 'michael', 'david', 'william',
        'emma', 'olivia', 'sophia', 'isabella', 'mia',
        'robert', 'joseph', 'thomas', 'charles', 'daniel',
        'emily', 'elizabeth', 'madison', 'abigail', 'charlotte',
    ];

    /**
     * 영문 성 목록 (이메일 생성용)
     *
     * @var array<string>
     */
    private array $englishLastNames = [
        'smith', 'johnson', 'williams', 'brown', 'jones',
        'garcia', 'miller', 'davis', 'rodriguez', 'martinez',
        'wilson', 'anderson', 'taylor', 'thomas', 'moore',
    ];

    /**
     * 더미 사용자를 생성합니다.
     *
     * --count=users=N 옵션으로 생성 수를 지정할 수 있습니다.
     * 500건 초과 시 배치 삽입으로 성능을 최적화합니다.
     */
    public function run(): void
    {
        $count = $this->getSeederCount('users', 100);
        $this->command->info("더미 사용자 {$count}개 생성을 시작합니다.");

        // 역할 조회
        $roles = Role::whereIn('identifier', ['admin', 'manager', 'user'])->get()->keyBy('identifier');

        if ($roles->isEmpty()) {
            $this->command->error('역할이 존재하지 않습니다. RolePermissionSeeder를 먼저 실행해주세요.');

            return;
        }

        $timezones = [
            'Asia/Seoul',
            'Asia/Tokyo',
            'America/New_York',
            'America/Los_Angeles',
            'Europe/London',
            'Europe/Paris',
        ];

        $languages = ['ko', 'en'];

        $statuses = ['active', 'inactive', 'blocked'];

        $countries = ['KR', 'JP', 'US', 'GB', 'FR', 'DE'];

        $useBatchInsert = $count > 500;
        $batchSize = 500;
        $batch = [];
        $roleBatch = [];
        $createdCount = 0;
        $hashedPassword = Hash::make('password123');

        $progressBar = $this->command->getOutput()->createProgressBar($count);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');

        for ($i = 1; $i <= $count; $i++) {
            $firstName = $this->getRandomFirstName();
            $lastName = $this->getRandomLastName();
            $name = "{$firstName} {$lastName}";

            // 이메일은 영문 소문자와 숫자만 사용
            $emailFirstName = $this->englishFirstNames[array_rand($this->englishFirstNames)];
            $emailLastName = $this->englishLastNames[array_rand($this->englishLastNames)];
            $email = $emailFirstName.'.'.$emailLastName.$i.'@example.com';

            $userData = [
                'name' => $name,
                'nickname' => $firstName.$i,
                'email' => $email,
                'password' => $hashedPassword,
                'email_verified_at' => rand(0, 1) ? now()->subDays(rand(1, 365)) : null,
                'language' => $languages[array_rand($languages)],
                'timezone' => $timezones[array_rand($timezones)],
                'country' => $countries[array_rand($countries)],
                'status' => $statuses[array_rand($statuses)],
                'mobile' => '010-'.rand(1000, 9999).'-'.rand(1000, 9999),
                'bio' => rand(0, 1) ? $this->getRandomBio() : null,
                'ip_address' => rand(1, 255).'.'.rand(0, 255).'.'.rand(0, 255).'.'.rand(1, 254),
                'last_login_at' => rand(0, 1) ? now()->subDays(rand(0, 30)) : null,
            ];

            // 역할 부여 (90% user, 8% manager, 2% admin)
            $roleIdentifier = $this->getRandomRole();

            if ($useBatchInsert) {
                $userData['created_at'] = now();
                $userData['updated_at'] = now();
                $batch[] = $userData;
                $roleBatch[] = $roleIdentifier;

                // 배치 크기에 도달하면 일괄 삽입
                if (count($batch) >= $batchSize) {
                    $this->insertBatch($batch, $roleBatch, $roles);
                    $batch = [];
                    $roleBatch = [];
                }
            } else {
                $user = User::create($userData);
                $role = $roles->get($roleIdentifier);

                if ($role) {
                    $user->roles()->attach($role->id, [
                        'assigned_at' => now(),
                        'assigned_by' => null,
                    ]);
                }
            }

            $createdCount++;
            $progressBar->advance();

            // 100건마다 메모리 정리
            if ($i % 100 === 0) {
                gc_collect_cycles();
            }
        }

        // 남은 배치 삽입
        if ($useBatchInsert && ! empty($batch)) {
            $this->insertBatch($batch, $roleBatch, $roles);
        }

        $progressBar->finish();
        $this->command->newLine();
        $this->command->info("더미 사용자 {$createdCount}개가 성공적으로 생성되었습니다.");
    }

    /**
     * 사용자 배치 삽입 및 역할 부여
     *
     * @param  array  $batch  사용자 데이터 배열
     * @param  array  $roleBatch  역할 식별자 배열
     * @param  \Illuminate\Support\Collection  $roles  역할 컬렉션
     * @return void
     */
    private function insertBatch(array $batch, array $roleBatch, $roles): void
    {
        // 배치 삽입 전 이메일 목록 저장
        $emails = array_column($batch, 'email');

        // 배치 삽입
        User::insert($batch);

        // 삽입된 사용자 조회하여 역할 부여
        $insertedUsers = User::whereIn('email', $emails)->get()->keyBy('email');
        $roleAttachments = [];

        foreach ($batch as $index => $userData) {
            $user = $insertedUsers->get($userData['email']);
            if ($user) {
                $role = $roles->get($roleBatch[$index]);
                if ($role) {
                    $roleAttachments[] = [
                        'user_id' => $user->id,
                        'role_id' => $role->id,
                        'assigned_at' => now(),
                        'assigned_by' => null,
                    ];
                }
            }
        }

        if (! empty($roleAttachments)) {
            \Illuminate\Support\Facades\DB::table('user_roles')->insert($roleAttachments);
        }
    }

    /**
     * 랜덤 역할 식별자를 반환합니다.
     * 90% user, 8% manager, 2% admin 비율
     *
     * @return string
     */
    private function getRandomRole(): string
    {
        $rand = rand(1, 100);

        if ($rand <= 2) {
            return 'admin';
        }

        if ($rand <= 10) {
            return 'manager';
        }

        return 'user';
    }

    /**
     * 랜덤 이름(First Name)을 반환합니다.
     */
    private function getRandomFirstName(): string
    {
        $names = [
            '민준', '서준', '도윤', '예준', '시우',
            '하준', '지호', '주원', '지후', '준우',
            '서연', '서윤', '지우', '서현', '민서',
            '하은', '하윤', '윤서', '지민', '지유',
            'James', 'John', 'Michael', 'David', 'William',
            'Emma', 'Olivia', 'Sophia', 'Isabella', 'Mia',
        ];

        return $names[array_rand($names)];
    }

    /**
     * 랜덤 성(Last Name)을 반환합니다.
     */
    private function getRandomLastName(): string
    {
        $names = [
            '김', '이', '박', '최', '정',
            '강', '조', '윤', '장', '임',
            'Smith', 'Johnson', 'Williams', 'Brown', 'Jones',
            'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez',
        ];

        return $names[array_rand($names)];
    }

    /**
     * 랜덤 자기소개를 반환합니다.
     */
    private function getRandomBio(): string
    {
        $bios = [
            '안녕하세요! 반갑습니다.',
            '개발을 좋아하는 사람입니다.',
            'Hello! Nice to meet you.',
            '새로운 것을 배우는 것을 좋아합니다.',
            'Software developer passionate about technology.',
            '항상 배우고 성장하려고 노력합니다.',
            'Love coding and problem solving.',
            '커피와 코딩을 좋아합니다.',
        ];

        return $bios[array_rand($bios)];
    }
}