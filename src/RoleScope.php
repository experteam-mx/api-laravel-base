<?php

namespace Experteam\ApiLaravelBase;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;

class RoleScope
{
    public const GLOBAL = 0;
    public const COUNTRY = 1;
    public const COMPANY_COUNTRY = 2;
    public const LOCATION = 3;
    public const INSTALLATION = 4;
    public const REGIONAL = 5;

    private const FIELDS = ['country_id', 'company_id', 'company_country_id', 'location_id', 'installation_id'];

    private const PARENTS = [
        'company_country_id' => ['companies.companyCountry', 'country_id'],
        'location_id' => ['companies.location', 'company_country_id'],
        'installation_id' => ['companies.installation', 'location_id'],
    ];

    private array $hashes = [];

    private function __construct(
        private readonly int $level,
        private readonly ?int $companyCountryId = null,
        private readonly array $regionalCountryIds = [],
        private readonly ?int $locationId = null,
        private readonly ?int $installationId = null,
    ) {
    }

    /**
     * Get the scope bound to the container, resolving it from the authenticated user when none is bound.
     * The scope stays bound until swap() replaces or forgets it.
     *
     * @return self
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public static function current(): self
    {
        if (!app()->bound(self::class)) {
            app()->instance(self::class, self::fromUser(Auth::user()));
        }

        return app(self::class);
    }

    /**
     * Replace the current scope, or forget it when null, and return the previous one.
     *
     * @param self|null $scope
     * @return self|null
     */
    public static function swap(?self $scope): ?self
    {
        $previous = app()->bound(self::class) ? app(self::class) : null;

        if (is_null($scope)) {
            app()->forgetInstance(self::class);
        } else {
            app()->instance(self::class, $scope);
        }

        return $previous;
    }

    /**
     * Build the scope from a user resolved by the security guard.
     *
     * @param mixed $user
     * @return self
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public static function fromUser(mixed $user): self
    {
        if (empty($user)) {
            throw new AuthorizationException();
        }

        if (empty($user->role) && request()->headers->has('AppKey')) {
            return self::system();
        }

        return self::fromSnapshot($user->role, $user->session);
    }

    /**
     * Build the scope from a stored role and session, for queued jobs.
     *
     * @param mixed $role
     * @param mixed $session
     * @return self
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public static function fromSnapshot(mixed $role, mixed $session): self
    {
        $role = json_decode(json_encode($role), true) ?? [];
        $session = json_decode(json_encode($session), true) ?? [];
        $level = $role['role_type']['level'] ?? null;
        $regionalCountryIds = $role['regional_country_id'] ?? [];

        if (!is_numeric($level)) {
            throw new AuthorizationException();
        }

        if (is_string($regionalCountryIds)) {
            $regionalCountryIds = json_decode($regionalCountryIds, true) ?? [];
        }

        return new self(
            (int) $level,
            self::toInt($role['company_country_id'] ?? null),
            array_values(array_map('intval', (array) $regionalCountryIds)),
            self::toInt($session['location_id'] ?? null),
            self::toInt($session['installation_id'] ?? null),
        );
    }

    /**
     * Build an unrestricted scope for service and system contexts.
     *
     * @return self
     */
    public static function system(): self
    {
        return new self(self::GLOBAL);
    }

    /**
     * Restrict an Eloquent query to the scope, using the organizational columns its model declares.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function apply(Builder $query): Builder
    {
        $model = $query->getModel();
        $conditions = $this->conditions($model);

        if (empty($conditions)) {
            return $query;
        }

        if ($model->getConnection()->getDriverName() === 'mongodb') {
            foreach ($conditions as $column => $ids) {
                $query->whereIn($column, self::withStrings($ids));
            }

            return $query;
        }

        self::wrapWheres($query->getQuery());

        foreach ($conditions as $column => $ids) {
            $query->whereIntegerInRaw($model->qualifyColumn($column), $ids);
        }

        return $query;
    }

    /**
     * Add the scope to a MongoDB $match stage, using the organizational columns the model declares.
     *
     * @param array $match
     * @param \Illuminate\Database\Eloquent\Model|string $model
     * @return array
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function constrain(array $match, Model|string $model): array
    {
        foreach ($this->conditions(is_string($model) ? new $model() : $model) as $column => $ids) {
            $match['$and'][] = [$column => ['$in' => self::withStrings($ids)]];
        }

        return $match;
    }

    /**
     * Determine whether the organizational ids received as values intersect the scope.
     * It fits filters; in writes, ownership comes from the session. A company_id is rejected.
     *
     * @param array $attributes
     * @return bool
     */
    public function allows(array $attributes): bool
    {
        if ($this->level === self::GLOBAL) {
            return true;
        }

        $nodes = [];

        foreach (self::FIELDS as $field) {
            if (!isset($attributes[$field])) {
                continue;
            }

            if ($field === 'company_id') {
                return false;
            }

            $id = filter_var($attributes[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $node = $id === false ? null : $this->node($field, $id);

            if (is_null($node)) {
                return false;
            }

            $nodes[$field] = $node;
        }

        foreach ($nodes as $node) {
            foreach ($nodes as $field => $other) {
                if (array_key_exists($field, $node) && $node[$field] !== $other[$field]) {
                    return false;
                }
            }
        }

        try {
            foreach ($nodes as $field => $node) {
                if (!$this->covers($field, $node)) {
                    return false;
                }
            }
        } catch (AuthorizationException) {
            return false;
        }

        return true;
    }

    /**
     * Ensure the organizational ids received as values intersect the scope.
     *
     * @param array $attributes
     * @return void
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function authorize(array $attributes): void
    {
        if (!$this->allows($attributes)) {
            throw new AuthorizationException();
        }
    }

    private static function toInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function withStrings(array $ids): array
    {
        return array_merge($ids, array_map('strval', $ids));
    }

    private static function wrapWheres(QueryBuilder $query): void
    {
        if (empty($query->wheres)) {
            return;
        }

        $nested = $query->forNestedWhere();
        $nested->wheres = $query->wheres;
        $nested->bindings['where'] = $query->bindings['where'];
        $query->wheres = [];
        $query->bindings['where'] = [];
        $query->addNestedWhereQuery($nested);
    }

    private function conditions(Model $model): array
    {
        if ($this->level === self::GLOBAL) {
            return [];
        }

        $declared = $model->roleScope ?? throw new AuthorizationException();
        $columns = [];

        foreach ($declared as $field => $column) {
            $field = is_int($field) ? $column : $field;

            if (!in_array($field, self::FIELDS, true)) {
                throw new AuthorizationException();
            }

            $columns[$field] = $column;
        }

        if (empty($columns)) {
            return [];
        }

        $conditions = match ($this->level) {
            self::REGIONAL => $this->countryConditions($columns, $this->regionalCountryIds),
            self::COUNTRY => $this->countryConditions($columns, [$this->companyCountry()['country_id']]),
            self::COMPANY_COUNTRY => $this->companyCountryConditions($columns),
            self::LOCATION => $this->locationConditions($columns),
            self::INSTALLATION => $this->installationConditions($columns),
            default => throw new AuthorizationException(),
        };

        $scoped = [];

        foreach ($conditions as $field => $ids) {
            $scoped[$columns[$field]] = $ids;
        }

        return $scoped;
    }

    private function countryConditions(array $columns, array $countryIds): array
    {
        if (empty($countryIds)) {
            throw new AuthorizationException();
        }

        return match (true) {
            isset($columns['country_id']) => ['country_id' => $countryIds],
            isset($columns['company_country_id']) => ['company_country_id' => $this->childrenOf('company_country_id', $countryIds)],
            default => $this->belowCompanyCountry($columns, $this->childrenOf('company_country_id', $countryIds)),
        };
    }

    private function companyCountryConditions(array $columns): array
    {
        $companyCountry = $this->companyCountry();

        return match (true) {
            isset($columns['company_country_id']) => ['company_country_id' => [$companyCountry['id']]],
            isset($columns['company_id'], $columns['country_id']) => [
                'company_id' => [$companyCountry['company_id']],
                'country_id' => [$companyCountry['country_id']],
            ],
            default => $this->belowCompanyCountry($columns, [$companyCountry['id']]),
        };
    }

    private function belowCompanyCountry(array $columns, array $companyCountryIds): array
    {
        $locationIds = $this->childrenOf('location_id', $companyCountryIds);

        return match (true) {
            isset($columns['location_id']) => ['location_id' => $locationIds],
            isset($columns['installation_id']) => ['installation_id' => $this->childrenOf('installation_id', $locationIds)],
            default => throw new AuthorizationException(),
        };
    }

    private function locationConditions(array $columns): array
    {
        $locationId = $this->required($this->locationId);

        return match (true) {
            isset($columns['location_id']) => ['location_id' => [$locationId]],
            isset($columns['installation_id']) => ['installation_id' => $this->childrenOf('installation_id', [$locationId])],
            default => throw new AuthorizationException(),
        };
    }

    private function installationConditions(array $columns): array
    {
        return match (true) {
            isset($columns['installation_id']) => ['installation_id' => [$this->required($this->installationId)]],
            isset($columns['location_id']) => ['location_id' => [$this->required($this->locationId)]],
            default => throw new AuthorizationException(),
        };
    }

    private function childrenOf(string $field, array $parentIds): array
    {
        [$key, $parentField] = self::PARENTS[$field];
        $ids = [];

        foreach (Redis::hgetall($key) ?: [] as $json) {
            $item = json_decode($json, true);

            if (is_array($item) && in_array((int) ($item[$parentField] ?? 0), $parentIds, true)) {
                $ids[] = (int) $item['id'];
            }
        }

        return $ids;
    }

    private function covers(string $field, array $node): bool
    {
        return match ($this->level) {
            self::REGIONAL => in_array($node['country_id'], $this->regionalCountryIds, true),
            self::COUNTRY => $node['country_id'] === $this->companyCountry()['country_id'],
            self::COMPANY_COUNTRY => $field === 'country_id'
                ? $node['country_id'] === $this->companyCountry()['country_id']
                : $node['company_country_id'] === $this->companyCountry()['id'],
            self::LOCATION => $this->within($field, $node, 'location_id', $this->required($this->locationId)),
            self::INSTALLATION => $this->within($field, $node, 'installation_id', $this->required($this->installationId)),
            default => false,
        };
    }

    private function within(string $field, array $node, string $ownField, int $ownId): bool
    {
        $own = $this->node($ownField, $ownId) ?? throw new AuthorizationException();
        $key = array_key_exists($field, $own) ? $field : $ownField;

        return ($node[$key] ?? null) === $own[$key];
    }

    private function node(string $field, int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        if ($field === 'country_id') {
            return ['country_id' => $id];
        }

        [$key, $parentField] = self::PARENTS[$field];
        $item = $this->hash($key, $id);
        $parent = is_null($item) ? null : $this->node($parentField, (int) ($item[$parentField] ?? 0));

        return is_null($parent) ? null : [$field => $id] + $parent;
    }

    private function companyCountry(): array
    {
        $id = $this->required($this->companyCountryId);
        $companyCountry = $this->hash('companies.companyCountry', $id) ?? throw new AuthorizationException();

        return [
            'id' => $id,
            'company_id' => (int) $companyCountry['company_id'],
            'country_id' => (int) $companyCountry['country_id'],
        ];
    }

    private function hash(string $key, int $id): ?array
    {
        if (!array_key_exists("$key:$id", $this->hashes)) {
            $item = json_decode(Redis::hget($key, $id) ?? 'null', true);
            $this->hashes["$key:$id"] = is_array($item) ? $item : null;
        }

        return $this->hashes["$key:$id"];
    }

    private function required(?int $value): int
    {
        return $value ?? throw new AuthorizationException();
    }
}
