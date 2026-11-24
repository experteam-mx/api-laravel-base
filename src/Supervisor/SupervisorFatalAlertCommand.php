<?php

namespace Experteam\ApiLaravelBase\Supervisor;

use Illuminate\Console\Command;
use Throwable;

class SupervisorFatalAlertCommand extends Command
{
    protected $signature = 'experteam:supervisor:fatal-alert {--test : Send a test email to verify the email route}';

    protected $description = 'Receives Supervisor FATAL events and sends an alert email.';

    /**
     * Send a test email with --test, otherwise run the Supervisor event listener until its input closes.
     *
     * @param SupervisorFatalAlertNotifier $notifier
     * @return int
     */
    public function handle(SupervisorFatalAlertNotifier $notifier): int
    {
        if ($this->option('test')) {
            $sent = $notifier->sendEmail([
                'processname' => 'queue_test',
                'groupname' => 'queue_test',
                'from_state' => 'BACKOFF (TEST)',
            ]);

            if ($sent) {
                $this->info('Test fatal alert email sent successfully.');
                return self::SUCCESS;
            }

            $this->error('Failed to send test email. Check the ELK log.');
            return self::FAILURE;
        }

        return $this->listen(STDIN, STDOUT, $notifier);
    }

    /**
     * Run the Supervisor event listener protocol: acknowledge every event and email support on PROCESS_STATE_FATAL.
     *
     * @param resource $input
     * @param resource $output
     * @param SupervisorFatalAlertNotifier $notifier
     * @return int
     */
    public function listen($input, $output, SupervisorFatalAlertNotifier $notifier): int
    {
        while (true) {
            fwrite($output, "READY\n");
            fflush($output);

            $header = fgets($input);

            if ($header === false) {
                return self::SUCCESS;
            }

            try {
                $headers = self::parseKeyValueLine(trim($header));
                $payloadLength = (int)($headers['len'] ?? 0);
                $payload = $payloadLength > 0
                    ? (string)stream_get_contents($input, $payloadLength)
                    : '';

                if (($headers['eventname'] ?? '') === 'PROCESS_STATE_FATAL') {
                    $notifier->sendEmail(array_merge($headers, self::parseKeyValueLine(trim($payload))));
                }
            } catch (Throwable $exception) {
                fwrite(STDERR, 'Supervisor event processing failed: ' . $exception->getMessage() . PHP_EOL);
            } finally {
                fwrite($output, "RESULT 2\nOK");
                fflush($output);
            }
        }
    }

    /**
     * Parse a Supervisor header or payload line into its key and value pairs.
     *
     * @param string $line
     * @return array<string, string>
     */
    public static function parseKeyValueLine(string $line): array
    {
        $values = [];

        foreach (preg_split('/\s+/', $line) ?: [] as $part) {
            $separator = str_contains($part, ':') ? ':' : '=';
            [$key, $value] = array_pad(explode($separator, $part, 2), 2, '');

            if ($key !== '') {
                $values[$key] = $value;
            }
        }

        return $values;
    }
}
