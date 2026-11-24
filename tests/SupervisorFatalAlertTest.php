<?php

namespace Experteam\ApiLaravelBase\Tests;

use Experteam\ApiLaravelBase\ApiLaravelBaseServiceProvider;
use Experteam\ApiLaravelBase\Supervisor\SupervisorFatalAlertCommand;
use Experteam\ApiLaravelBase\Supervisor\SupervisorFatalAlertNotifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Mockery;
use Monolog\Handler\TestHandler;
use Orchestra\Testbench\TestCase;
use RuntimeException;

class SupervisorFatalAlertTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ApiLaravelBaseServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('supervisor-fatal-alert.services_url', 'http://services.test/api/');
        $app['config']->set('supervisor-fatal-alert.app_key', 'test-key');
        $app['config']->set('supervisor-fatal-alert.recipient', 'fatal@example.test');
        $app['config']->set('supervisor-fatal-alert.namespace', 'exp-cra-test');
        $app['config']->set('logging.channels.ElasticSearch', ['driver' => 'monolog', 'handler' => TestHandler::class]);
    }

    public function test_sent(): void
    {
        Http::fake(['services.test/*' => Http::response(['status' => 'success', 'data' => []])]);
        Auth::shouldReceive('user')->never();

        $this->assertTrue($this->notify());

        Http::assertSent(fn(Request $request) => $request->url() === 'http://services.test/api/emails'
            && $request->hasHeader('AppKey', 'test-key')
            && $request['destinations'] === [['address' => 'fatal@example.test', 'name' => 'CRA Support']]
            && $request['prioritize'] === true
            && str_contains($request['subject'], 'exp-cra-test')
            && str_contains($request['body'], 'queue_billing stopped in ')
            && str_contains($request['body'], '/var/log/supervisord.log.d/supervisor_queue_billing.err.log')
            && str_contains($request['body'], 'OpenShift restarts the container automatically within about 5 minutes.')
            && str_contains($request['body'], 'If this email repeats, the cause persists and needs attention')
            && !str_contains($request['body'], '{{'));
        $records = $this->records();
        $this->assertCount(1, $records);
        $this->assertStringEndsWith('_supervisor_worker_fatal', $records[0][0]);
        $this->assertSame('queue_billing', $records[0][1]['process_name']);
    }

    public function test_escaped(): void
    {
        Http::fake(['services.test/*' => Http::response(['status' => 'success', 'data' => []])]);

        $this->app->make(SupervisorFatalAlertNotifier::class)->sendEmail(['processname' => '<b>x</b>']);

        Http::assertSent(fn(Request $request) => str_contains($request['body'], '&lt;b&gt;x&lt;/b&gt;')
            && !str_contains($request['body'], '<b>x</b>'));
    }

    public function test_rejected(): void
    {
        Http::fake(['services.test/*' => Http::response(['status' => 'fail', 'data' => ['message' => 'rejected']])]);

        $this->assertFalse($this->notify());

        $records = $this->records();
        $this->assertStringEndsWith('_supervisor_fatal_alert_exception', $records[0][0]);
        $this->assertSame('rejected', $records[0][1]['message']);
        $this->assertStringEndsWith('_supervisor_worker_fatal', $records[1][0]);
    }

    public function test_server_error(): void
    {
        Http::fake(['services.test/*' => Http::response('<html>Bad gateway</html>', 502)]);

        $this->assertFalse($this->notify());

        $records = $this->records();
        $this->assertSame(502, $records[0][1]['status']);
        $this->assertStringContainsString('Bad gateway', $records[0][1]['message']);
    }

    public function test_unreachable(): void
    {
        Sleep::fake();
        Http::fake(fn() => throw new ConnectionException('Connection refused'));

        $this->assertFalse($this->notify());

        $records = $this->records();
        $this->assertStringEndsWith('_supervisor_fatal_alert_exception', $records[0][0]);
        $this->assertSame('Connection refused', $records[0][1]['message']);
        $this->assertStringEndsWith('_supervisor_worker_fatal', $records[1][0]);
    }

    public function test_retried(): void
    {
        Sleep::fake();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            if (++$attempts === 1) {
                throw new ConnectionException('Connection refused');
            }

            return Http::response(['status' => 'success', 'data' => []]);
        });

        $this->assertTrue($this->notify());
        $this->assertSame(2, $attempts);
    }

    public function test_log_failure(): void
    {
        Http::fake(['services.test/*' => Http::response(['status' => 'success', 'data' => []])]);
        Log::shouldReceive('channel')->andThrow(new RuntimeException('Log unavailable'));

        $this->assertTrue($this->notify());
        Http::assertSentCount(1);
    }

    public function test_test_option(): void
    {
        Http::fake(['services.test/*' => Http::response(['status' => 'success', 'data' => []])]);

        $this->artisan('experteam:supervisor:fatal-alert', ['--test' => true])->assertExitCode(0);
    }

    public function test_test_option_failure(): void
    {
        Http::fake(['services.test/*' => Http::response(['status' => 'fail', 'data' => ['message' => 'rejected']])]);

        $this->artisan('experteam:supervisor:fatal-alert', ['--test' => true])->assertExitCode(1);
    }

    public function test_protocol(): void
    {
        $payload = 'processname:queue_billing groupname:queue_billing from_state:BACKOFF';
        $input = $this->stream('ver:3.0 server:supervisor eventname:PROCESS_STATE_FATAL len:' . strlen($payload) . "\n" . $payload
            . "ver:3.0 server:supervisor eventname:PROCESS_STATE_RUNNING len:0\n");
        $output = $this->stream('');
        $notifier = Mockery::mock(SupervisorFatalAlertNotifier::class);
        $notifier->shouldReceive('sendEmail')->once()->with(Mockery::on(
            fn(array $data) => $data['eventname'] === 'PROCESS_STATE_FATAL' && $data['processname'] === 'queue_billing'
        ))->andReturn(true);

        $this->assertSame(0, (new SupervisorFatalAlertCommand())->listen($input, $output, $notifier));

        rewind($output);
        $this->assertSame("READY\nRESULT 2\nOKREADY\nRESULT 2\nOKREADY\n", stream_get_contents($output));
    }

    public function test_protocol_failure(): void
    {
        $payload = 'processname:queue_billing groupname:queue_billing from_state:BACKOFF';
        $input = $this->stream('ver:3.0 server:supervisor eventname:PROCESS_STATE_FATAL len:' . strlen($payload) . "\n" . $payload);
        $output = $this->stream('');
        $notifier = Mockery::mock(SupervisorFatalAlertNotifier::class);
        $notifier->shouldReceive('sendEmail')->once()->andThrow(new RuntimeException('boom'));

        $this->assertSame(0, (new SupervisorFatalAlertCommand())->listen($input, $output, $notifier));

        rewind($output);
        $this->assertSame("READY\nRESULT 2\nOKREADY\n", stream_get_contents($output));
    }

    public function test_event_parsing(): void
    {
        $this->assertSame(
            ['ver' => '3.0', 'server' => 'supervisor', 'eventname' => 'PROCESS_STATE_FATAL', 'len' => '58'],
            SupervisorFatalAlertCommand::parseKeyValueLine('ver:3.0 server:supervisor eventname:PROCESS_STATE_FATAL len:58')
        );
        $this->assertSame(
            ['processname' => 'queue_billing', 'groupname' => 'queue_billing', 'from_state' => 'BACKOFF'],
            SupervisorFatalAlertCommand::parseKeyValueLine('processname:queue_billing groupname:queue_billing from_state:BACKOFF')
        );
    }

    public function test_deployment_name(): void
    {
        $this->assertSame('api-billing-co', SupervisorFatalAlertNotifier::deploymentName('api-billing-co-12-abcde'));
        $this->assertSame('api-billing-co', SupervisorFatalAlertNotifier::deploymentName('api-billing-co-7d9f8c6b5-x2kq9'));
        $this->assertSame('a36372billing-co', SupervisorFatalAlertNotifier::deploymentName('a36372billing-co'));
    }

    public function test_empty_env(): void
    {
        $keys = ['SUPERVISOR_FATAL_ALERT_EMAIL', 'MY_POD_NAMESPACE'];

        try {
            foreach ($keys as $key) {
                $_ENV[$key] = $_SERVER[$key] = '';
            }

            $config = require __DIR__ . '/../config/supervisor-fatal-alert.php';
        } finally {
            foreach ($keys as $key) {
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }

        $this->assertSame('crasupport@experteam.com.ec', $config['recipient']);
        $this->assertSame('local', $config['namespace']);
    }

    /**
     * @return bool
     */
    private function notify(): bool
    {
        return $this->app->make(SupervisorFatalAlertNotifier::class)->sendEmail([
            'processname' => 'queue_billing',
            'groupname' => 'queue_billing',
            'from_state' => 'BACKOFF',
        ]);
    }

    /**
     * @return array<int, array{0: string, 1: array<string, mixed>}>
     */
    private function records(): array
    {
        return array_map(
            fn($record) => [$record['message'], $record['context']],
            Log::channel('ElasticSearch')->getLogger()->getHandlers()[0]->getRecords()
        );
    }

    /**
     * @return resource
     */
    private function stream(string $content)
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
