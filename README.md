API Laravel BASE
=

Base service providers to manage crud services <br>
It includes:
- <b>Model paginate:</b> Trait to manage pagination, it can manage limit, offset and order by.


### Install

Run the following commands to install: <br>
```
composer require experteam/api-laravel-base
```
Change the 
### Update
Run the composer command to update the package: <br>
```
composer update experteam/api-laravel-base
```

### Role scope

`RoleScope` restricts queries to the data the active role may see. The scope comes from the token role and its validated session, never from request parameters. The hierarchy is read from the `companies.companyCountry`, `companies.location` and `companies.installation` Redis hashes.

Each model declares its organizational columns, as a list or as a map from field to column:
```php
public array $roleScope = ['country_id', 'company_country_id', 'location_id', 'installation_id'];
public array $roleScope = ['location_id' => 'check_in_operation_location_id'];
public array $roleScope = [];
```
An empty array marks data without organizational owner. A model without the property, with an unknown field, or without any column the level can use, fails with `AuthorizationException`. Installation filters by the session location when the model does not declare `installation_id`. The levels above resolve through the Redis hierarchy when the model lacks their own column.

```php
RoleScope::current()->apply(Shipment::query());
RoleScope::current()->constrain($match, Payment::class);
RoleScope::current()->authorize($request->only(['country_id', 'location_id', 'installation_id']));
```
`apply()` wraps the previous filters in a group and adds the conditions after them. Apply it last. Global roles and `AppKey` calls without a role are not restricted.

`allows()` and `authorize()` check filters: an id passes when its subtree intersects the scope, and a `company_id` is rejected. In writes, take ownership from the session.

Every queued job starts without a scope. A job sets its own and restores the previous one:
```php
$previous = RoleScope::swap(RoleScope::fromSnapshot($role, $session));

try {
    $this->export();
} finally {
    RoleScope::swap($previous);
}
```

### License
[MIT license](https://opensource.org/licenses/MIT).
