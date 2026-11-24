<?php

namespace Experteam\ApiLaravelBase\Supervisor;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SupervisorFatalAlertNotifier
{
    private const TEMPLATE = __DIR__ . '/../../resources/supervisor-fatal-alert.html';

    /**
     * Email support about a FATAL worker through the API Services email route, then log the event.
     *
     * @param array<string, string> $eventData
     * @return bool True when the email route accepted the email.
     */
    public function sendEmail(array $eventData): bool
    {
        $podName = (string)gethostname();
        $deployment = self::deploymentName($podName);
        $namespace = (string)config('supervisor-fatal-alert.namespace');
        $logPrefix = str_replace('-', '_', $deployment);
        $processName = $eventData['processname'] ?? 'unknown';
        $groupName = $eventData['groupname'] ?? 'unknown';
        $fromState = $eventData['from_state'] ?? 'unknown';

        try {
            $response = Http::acceptJson()
                ->withHeaders(['AppKey' => (string)config('supervisor-fatal-alert.app_key')])
                ->timeout(10)
                ->retry(2, 1000, fn(Throwable $exception) => $exception instanceof ConnectionException, throw: false)
                ->post(rtrim((string)config('supervisor-fatal-alert.services_url'), '/') . '/emails', [
                    'destinations' => [
                        [
                            'address' => (string)config('supervisor-fatal-alert.recipient'),
                            'name' => 'CRA Support',
                        ],
                    ],
                    'subject' => sprintf('Supervisor worker FATAL in %s %s (Pod name: %s)!', $deployment, $namespace, $podName),
                    'template' => 'none',
                    'body' => $this->renderBody([
                        'api' => $deployment,
                        'process' => $processName,
                        'group' => $groupName,
                        'from_state' => $fromState,
                        'namespace' => $namespace,
                        'pod' => $podName,
                        'detected_at' => gmdate('Y-m-d H:i:s') . ' UTC',
                        'worker_log' => "/var/log/supervisord.log.d/supervisor_{$processName}.err.log",
                        'elk_event' => "{$logPrefix}_supervisor_worker_fatal",
                    ]),
                    'prioritize' => true,
                ]);

            $sent = $response->successful() && $response->json('status') === 'success';

            if (!$sent) {
                $this->log("{$logPrefix}_supervisor_fatal_alert_exception", [
                    'status' => $response->status(),
                    'message' => $response->json('data.message') ?? $response->json('message') ?? substr($response->body(), 0, 500),
                ]);
            }
        } catch (Throwable $throwable) {
            $sent = false;
            $this->log("{$logPrefix}_supervisor_fatal_alert_exception", [
                'code' => $throwable->getCode(),
                'message' => $throwable->getMessage(),
            ]);
        }

        $this->log("{$logPrefix}_supervisor_worker_fatal", [
            'process_name' => $processName,
            'group_name' => $groupName,
            'from_state' => $fromState,
            'pod_name' => $podName,
            'namespace' => $namespace,
            'environment' => app()->environment(),
        ]);

        return $sent;
    }

    /**
     * Fill the HTML email template with the escaped values.
     *
     * @param array<string, string> $values
     * @return string
     */
    private function renderBody(array $values): string
    {
        $replacements = [];

        foreach ($values as $key => $value) {
            $replacements['{{' . $key . '}}'] = e($value);
        }

        return strtr((string)file_get_contents(self::TEMPLATE), $replacements);
    }

    /**
     * Get the deployment name from a DeploymentConfig or Deployment pod name.
     *
     * @param string $podName
     * @return string
     */
    public static function deploymentName(string $podName): string
    {
        return (string)preg_replace('/-(?:\d+|[a-z0-9]{9,10})-[a-z0-9]{5}$/', '', $podName);
    }

    /**
     * Write to the ElasticSearch log channel, falling back to stderr when the channel fails.
     *
     * @param string $message
     * @param array<string, mixed> $context
     * @return void
     */
    private function log(string $message, array $context): void
    {
        try {
            Log::channel('ElasticSearch')->error($message, ['id' => uniqid(), 'timestamp' => date_create()] + $context);
        } catch (Throwable $exception) {
            error_log('ELK log failed: ' . $exception->getMessage());
        }
    }
}
