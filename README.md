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

### Supervisor FATAL alert

`experteam:supervisor:fatal-alert` is a Supervisor event listener. When a worker reaches FATAL it emails support through the API Services email route, retrying once on a connection error, and logs the event to the `ElasticSearch` channel without going through Redis. A rejected or unreachable email route is logged too.

Require this version before adding the listener: if the command does not exist, the listener itself ends in FATAL. Register it in `deploy/supervisor`, with its logs in the `/var/log/supervisord.log.d` pod volume so they survive a container restart:
```
[eventlistener:supervisor_fatal_alert]
command=php -d display_errors=stderr /opt/app-root/src/artisan experteam:supervisor:fatal-alert
events=PROCESS_STATE_FATAL
buffer_size=50
autostart=true
autorestart=true
startsecs=1
startretries=35
stderr_logfile=/var/log/supervisord.log.d/supervisor_fatal_alert.err.log
stdout_logfile=/var/log/supervisord.log.d/supervisor_fatal_alert.out.log
```
The listener's stdout carries the Supervisor protocol, so nothing else in the process may write to it. A stray line makes Supervisor stop sending events while the process keeps running.

It reads `DHL_API_SERVICES_URL` and `AUTH_MASTER_APP_KEY`, the recipient from `SUPERVISOR_FATAL_ALERT_EMAIL` and the namespace from `MY_POD_NAMESPACE`, which the deployment template sets from `metadata.namespace`. The pod name is the container host name. Run it with `--test` to send a test email.

The email is the HTML template in `resources/supervisor-fatal-alert.html`, with the DHL colors and every value escaped. It uses Poppins where the mail client loads web fonts and Arial in Outlook.

The email tells support that OpenShift restarts the container on its own, which holds only when the deployment has the liveness probe that fails while a Supervisor program is FATAL.

### License
[MIT license](https://opensource.org/licenses/MIT).
