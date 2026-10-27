<?php

namespace Experteam\ApiLaravelBase\Tests;

use Experteam\ApiLaravelBase\ApiLaravelBaseServiceProvider;
use Experteam\ApiLaravelBase\RoleScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\SQLiteConnector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class RoleScopeTest extends TestCase
{
    private const HASHES = [
        'companies.companyCountry' => [
            10 => ['id' => 10, 'company_id' => 100, 'country_id' => 1],
            11 => ['id' => 11, 'company_id' => 101, 'country_id' => 1],
            20 => ['id' => 20, 'company_id' => 100, 'country_id' => 2],
        ],
        'companies.location' => [
            1000 => ['id' => 1000, 'company_country_id' => 10],
            1001 => ['id' => 1001, 'company_country_id' => 11],
            1002 => ['id' => 1002, 'company_country_id' => 10],
            2000 => ['id' => 2000, 'company_country_id' => 20],
        ],
        'companies.installation' => [
            5000 => ['id' => 5000, 'location_id' => 1000],
            5001 => ['id' => 5001, 'location_id' => 1001],
            5002 => ['id' => 5002, 'location_id' => 1002],
            5009 => ['id' => 5009, 'location_id' => 1000],
            6000 => ['id' => 6000, 'location_id' => 2000],
        ],
    ];

    protected function getPackageProviders($app): array
    {
        return [ApiLaravelBaseServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Redis::shouldReceive('hget')->andReturnUsing(
            fn(string $key, $id) => isset(self::HASHES[$key][$id]) ? json_encode(self::HASHES[$key][$id]) : null
        );
        Redis::shouldReceive('hgetall')->andReturnUsing(
            fn(string $key) => array_map('json_encode', self::HASHES[$key] ?? [])
        );

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('country_id');
            $table->integer('company_id');
            $table->integer('company_country_id');
            $table->integer('location_id');
            $table->integer('installation_id');
            $table->integer('origin_location_id');
        });

        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->integer('transaction_id');
            $table->integer('location_id');
        });

        DB::table('transactions')->insert([
            ['name' => 'A', 'country_id' => 1, 'company_id' => 100, 'company_country_id' => 10, 'location_id' => 1000, 'installation_id' => 5000, 'origin_location_id' => 1001],
            ['name' => 'B', 'country_id' => 1, 'company_id' => 101, 'company_country_id' => 11, 'location_id' => 1001, 'installation_id' => 5001, 'origin_location_id' => 1000],
            ['name' => 'C', 'country_id' => 2, 'company_id' => 100, 'company_country_id' => 20, 'location_id' => 2000, 'installation_id' => 6000, 'origin_location_id' => 2000],
            ['name' => 'D', 'country_id' => 1, 'company_id' => 100, 'company_country_id' => 10, 'location_id' => 1002, 'installation_id' => 5002, 'origin_location_id' => 1002],
            ['name' => 'E', 'country_id' => 1, 'company_id' => 100, 'company_country_id' => 10, 'location_id' => 1000, 'installation_id' => 5009, 'origin_location_id' => 1000],
        ]);

        DB::table('notes')->insert(array_map(
            fn(int $id) => ['transaction_id' => $id, 'location_id' => 2000],
            range(1, 5)
        ));
    }

    public static function levels(): array
    {
        return [
            'global' => [RoleScope::GLOBAL, [], [], ['A', 'B', 'C', 'D', 'E']],
            'regional' => [RoleScope::REGIONAL, ['regional_country_id' => [2]], [], ['C']],
            'country' => [RoleScope::COUNTRY, ['company_country_id' => 10], [], ['A', 'B', 'D', 'E']],
            'company' => [RoleScope::COMPANY_COUNTRY, ['company_country_id' => 10], [], ['A', 'D', 'E']],
            'location' => [RoleScope::LOCATION, [], ['location_id' => 1000, 'installation_id' => 5000], ['A', 'E']],
            'installation' => [RoleScope::INSTALLATION, [], ['location_id' => 1000, 'installation_id' => 5000], ['A']],
        ];
    }

    #[DataProvider('levels')]
    public function test_levels(int $level, array $role, array $session, array $expected): void
    {
        $this->assertSame($expected, $this->names($this->scope($level, $role, $session), Transaction::class));
    }

    public function test_company_and_country_columns(): void
    {
        $scope = $this->scope(RoleScope::COMPANY_COUNTRY, ['company_country_id' => 10]);

        $this->assertSame(['A', 'D', 'E'], $this->names($scope, CompanyTransaction::class));
    }

    public function test_country_by_company_country(): void
    {
        $scope = $this->scope(RoleScope::COUNTRY, ['company_country_id' => 10]);

        $this->assertSame(['A', 'B', 'D', 'E'], $this->names($scope, CompanyCountryTransaction::class));
    }

    public function test_regional_by_location(): void
    {
        $scope = $this->scope(RoleScope::REGIONAL, ['regional_country_id' => '[2]']);

        $this->assertSame(['C'], $this->names($scope, LocationTransaction::class));
    }

    public function test_by_installation(): void
    {
        $country = $this->scope(RoleScope::COUNTRY, ['company_country_id' => 10]);
        $company = $this->scope(RoleScope::COMPANY_COUNTRY, ['company_country_id' => 10]);
        $location = $this->scope(RoleScope::LOCATION, [], ['location_id' => 1000]);

        $this->assertSame(['A', 'B', 'D', 'E'], $this->names($country, InstallationTransaction::class));
        $this->assertSame(['A', 'D', 'E'], $this->names($company, InstallationTransaction::class));
        $this->assertSame(['A', 'E'], $this->names($location, InstallationTransaction::class));
    }

    public function test_installation_by_location(): void
    {
        $scope = $this->scope(RoleScope::INSTALLATION, [], ['location_id' => 1000, 'installation_id' => 5000]);

        $this->assertSame(['A', 'E'], $this->names($scope, LocationTransaction::class));
    }

    public function test_missing_column(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->names($this->scope(RoleScope::LOCATION, [], ['location_id' => 1000]), CompanyCountryTransaction::class);
    }

    public function test_unknown_field(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->names($this->scope(RoleScope::LOCATION, [], ['location_id' => 1000]), TypoTransaction::class);
    }

    public function test_mixed_declaration(): void
    {
        $scope = $this->scope(RoleScope::INSTALLATION, [], ['location_id' => 1000, 'installation_id' => 5000]);

        $this->assertSame(['A'], $this->names($scope, MixedTransaction::class));
    }

    public function test_undeclared_model(): void
    {
        $this->assertSame(['A', 'B', 'C', 'D', 'E'], $this->names($this->scope(RoleScope::GLOBAL), UndeclaredTransaction::class));

        $this->expectException(AuthorizationException::class);
        $this->names($this->scope(RoleScope::COUNTRY, ['company_country_id' => 10]), UndeclaredTransaction::class);
    }

    public function test_unscoped_model(): void
    {
        $scope = $this->scope(RoleScope::LOCATION, [], ['location_id' => 1000]);

        $this->assertSame(['A', 'B', 'C', 'D', 'E'], $this->names($scope, UnscopedTransaction::class));
    }

    public function test_alias(): void
    {
        $scope = $this->scope(RoleScope::LOCATION, [], ['location_id' => 1000]);

        $this->assertSame(['B', 'E'], $this->names($scope, AliasTransaction::class));
    }

    public function test_or_filters(): void
    {
        $scope = $this->scope(RoleScope::LOCATION, [], ['location_id' => 1001]);

        $this->assertSame([], $this->names($scope, Transaction::class, fn(Builder $query) => $query->where('name', 'A')->orWhere('name', 'C')));
        $this->assertSame(['B'], $this->names($scope, Transaction::class, fn(Builder $query) => $query->where('name', 'B')->orWhere('name', 'C')));
        $this->assertSame(['B'], $this->names($scope, Transaction::class, fn(Builder $query) => $query->whereRaw("name = 'A' or name = 'B'")));
        $this->assertSame(['B'], $this->names($scope, Transaction::class, fn(Builder $query) => $query->where('name', 'A')->where('name', '=', 'B', 'OR')));
    }

    public function test_join(): void
    {
        $scope = $this->scope(RoleScope::LOCATION, [], ['location_id' => 1001]);

        $names = $this->names($scope, Transaction::class, fn(Builder $query) => $query
            ->join('notes', 'notes.transaction_id', '=', 'transactions.id')
            ->select('transactions.*'));

        $this->assertSame(['B'], $names);
    }

    public function test_mongo_branch(): void
    {
        $this->app->bind('db.connector.mongodb', fn() => new SQLiteConnector());
        Connection::resolverFor('mongodb', fn($pdo, $database, $prefix, $config) => new SQLiteConnection($pdo, $database, $prefix, $config));
        config(['database.connections.fake_mongo' => ['driver' => 'mongodb', 'database' => ':memory:', 'prefix' => '']]);

        $wheres = $this->scope(RoleScope::LOCATION, [], ['location_id' => 1001])
            ->apply(MongoTransaction::query()->where('name', 'A')->orWhere('name', 'C'))
            ->getQuery()
            ->wheres;

        $this->assertCount(3, $wheres);
        $this->assertSame(['type' => 'In', 'column' => 'location_id', 'values' => [1001, '1001'], 'boolean' => 'and'], $wheres[2]);
    }

    public function test_service_contexts(): void
    {
        $this->assertSame(['A', 'B', 'C', 'D', 'E'], $this->names(RoleScope::system(), Transaction::class));

        request()->headers->set('AppKey', 'service');
        $this->assertSame(['A', 'B', 'C', 'D', 'E'], $this->names(RoleScope::fromUser((object) ['role' => null, 'session' => null]), Transaction::class));
    }

    public static function missingContexts(): array
    {
        return [
            'no user' => [fn() => RoleScope::fromUser(null)],
            'no role without app key' => [fn() => RoleScope::fromUser((object) ['role' => null, 'session' => null])],
            'no level' => [fn() => RoleScope::fromSnapshot(['role_type' => []], [])],
            'unknown level' => [fn() => RoleScope::fromSnapshot(['role_type' => ['level' => 9]], [])],
            'no location' => [fn() => RoleScope::fromSnapshot(['role_type' => ['level' => RoleScope::LOCATION]], [])],
            'no regional countries' => [fn() => RoleScope::fromSnapshot(['role_type' => ['level' => RoleScope::REGIONAL]], [])],
            'no bound scope nor user' => [fn() => RoleScope::current()],
        ];
    }

    #[DataProvider('missingContexts')]
    public function test_missing_context(callable $scope): void
    {
        $this->expectException(AuthorizationException::class);

        $this->names($scope(), Transaction::class);
    }

    public function test_allows(): void
    {
        $location = $this->scope(RoleScope::LOCATION, [], ['location_id' => 1001]);
        $siblingLocation = $this->scope(RoleScope::LOCATION, [], ['location_id' => 1000]);
        $installation = $this->scope(RoleScope::INSTALLATION, [], ['location_id' => 1000, 'installation_id' => 5000]);
        $country = $this->scope(RoleScope::COUNTRY, ['company_country_id' => 10]);
        $company = $this->scope(RoleScope::COMPANY_COUNTRY, ['company_country_id' => 10]);
        $regional = $this->scope(RoleScope::REGIONAL, ['regional_country_id' => [2]]);

        $this->assertTrue($location->allows(['location_id' => 1001]));
        $this->assertTrue($location->allows(['installation_id' => '5001']));
        $this->assertTrue($location->allows(['country_id' => 1, 'company_country_id' => 11]));
        $this->assertTrue($location->allows(['name' => 'no organizational ids']));
        $this->assertFalse($location->allows(['location_id' => 1000]));
        $this->assertFalse($location->allows(['installation_id' => 5000]));
        $this->assertFalse($location->allows(['location_id' => 1001, 'installation_id' => 5000]));
        $this->assertFalse($location->allows(['location_id' => 999]));
        $this->assertFalse($location->allows(['location_id' => 'abc']));
        $this->assertFalse($location->allows(['location_id' => [1001]]));
        $this->assertFalse($location->allows(['company_id' => 101]));
        $this->assertFalse($siblingLocation->allows(['location_id' => 1002]));
        $this->assertTrue($siblingLocation->allows(['installation_id' => 5009]));
        $this->assertFalse($installation->allows(['installation_id' => 5009]));
        $this->assertTrue($installation->allows(['location_id' => 1000]));
        $this->assertTrue($installation->allows(['country_id' => 1]));
        $this->assertTrue($country->allows(['location_id' => 1001]));
        $this->assertTrue($country->allows(['location_id' => 1000, 'installation_id' => 5000]));
        $this->assertFalse($country->allows(['location_id' => 1000, 'installation_id' => 5001]));
        $this->assertFalse($country->allows(['country_id' => 2]));
        $this->assertTrue($company->allows(['country_id' => 1]));
        $this->assertTrue($company->allows(['location_id' => 1002]));
        $this->assertFalse($company->allows(['location_id' => 1001]));
        $this->assertFalse($company->allows(['country_id' => 2]));
        $this->assertTrue($regional->allows(['country_id' => 2]));
        $this->assertTrue($regional->allows(['location_id' => 2000]));
        $this->assertFalse($regional->allows(['country_id' => 1]));
        $this->assertFalse($regional->allows(['location_id' => 1000]));
        $this->assertTrue($this->scope(RoleScope::GLOBAL)->allows(['location_id' => 999, 'company_id' => 101]));
        $this->assertFalse($this->scope(RoleScope::LOCATION)->allows(['location_id' => 1001]));
    }

    public function test_authorize(): void
    {
        $scope = $this->scope(RoleScope::INSTALLATION, [], ['location_id' => 1001, 'installation_id' => 5001]);
        $scope->authorize(['installation_id' => 5001]);

        $this->expectException(AuthorizationException::class);
        $scope->authorize(['installation_id' => 6000]);
    }

    public function test_constrain(): void
    {
        $scope = $this->scope(RoleScope::LOCATION, [], ['location_id' => 1001]);

        $this->assertSame(
            ['status' => 2, '$and' => [['location_id' => ['$in' => [1001, '1001']]]]],
            $scope->constrain(['status' => 2], LocationTransaction::class)
        );
        $this->assertSame(
            ['$and' => [['origin_location_id' => ['$in' => [1001, '1001']]]]],
            $scope->constrain([], new AliasTransaction())
        );
        $this->assertSame([], $this->scope(RoleScope::GLOBAL)->constrain([], UndeclaredTransaction::class));
    }

    public function test_current(): void
    {
        $scope = RoleScope::system();
        $this->assertNull(RoleScope::swap($scope));
        $this->assertSame($scope, RoleScope::current());
        $this->assertSame($scope, RoleScope::swap(null));
        $this->assertFalse(app()->bound(RoleScope::class));
    }

    public function test_jobs_start_without_scope(): void
    {
        RoleScope::swap(RoleScope::system());

        event(new JobProcessing('sync', Mockery::mock(Job::class)->shouldIgnoreMissing()->shouldReceive('payload')->andReturn([])->getMock()));

        $this->assertFalse(app()->bound(RoleScope::class));
    }

    private function scope(int $level, array $role = [], array $session = []): RoleScope
    {
        return RoleScope::fromSnapshot(['role_type' => ['level' => $level]] + $role, $session);
    }

    private function names(RoleScope $scope, string $model, ?callable $build = null): array
    {
        $query = $model::query();

        if ($build) {
            $build($query);
        }

        return $scope->apply($query)->orderBy('transactions.name')->pluck('name')->all();
    }
}

class Transaction extends Model
{
    protected $table = 'transactions';

    public array $roleScope = ['country_id', 'company_id', 'company_country_id', 'location_id', 'installation_id'];
}

class CompanyTransaction extends Transaction
{
    public array $roleScope = ['company_id', 'country_id'];
}

class CompanyCountryTransaction extends Transaction
{
    public array $roleScope = ['company_country_id'];
}

class LocationTransaction extends Transaction
{
    public array $roleScope = ['location_id'];
}

class InstallationTransaction extends Transaction
{
    public array $roleScope = ['installation_id'];
}

class AliasTransaction extends Transaction
{
    public array $roleScope = ['location_id' => 'origin_location_id'];
}

class MixedTransaction extends Transaction
{
    public array $roleScope = ['installation_id', 'location_id' => 'location_id'];
}

class TypoTransaction extends Transaction
{
    public array $roleScope = ['location_id', 'instalation_id'];
}

class UnscopedTransaction extends Transaction
{
    public array $roleScope = [];
}

class UndeclaredTransaction extends Model
{
    protected $table = 'transactions';
}

class MongoTransaction extends Transaction
{
    protected $connection = 'fake_mongo';

    public array $roleScope = ['location_id'];
}
