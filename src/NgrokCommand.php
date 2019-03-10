<?php

namespace JnJairo\Laravel\Ngrok;

use Illuminate\Console\Command;
use JnJairo\Laravel\Ngrok\NgrokProcessBuilder;
use JnJairo\Laravel\Ngrok\NgrokWebService;
use Symfony\Component\Process\Process;

class NgrokCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ngrok
                            {host? : The host to share}
                            {--port= : The port to share}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Share the application with ngrok';

    /**
     * Process builder.
     *
     * @var \JnJairo\Laravel\Ngrok\NgrokProcessBuilder
     */
    protected $processBuilder;

    /**
     * Web service.
     *
     * @var \JnJairo\Laravel\Ngrok\NgrokWebService
     */
    protected $webService;

    /**
     * @param \JnJairo\Laravel\Ngrok\NgrokProcessBuilder $processBuilder
     * @param \JnJairo\Laravel\Ngrok\NgrokWebService $webService
     */
    public function __construct(NgrokProcessBuilder $processBuilder, NgrokWebService $webService)
    {
        parent::__construct();

        $this->processBuilder = $processBuilder;
        $this->webService = $webService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle() : int
    {
        $host = $this->argument('host');
        $port = $this->option('port');

        if ($host === null) {
            $url = $this->getLaravel()->make('config')->get('app.url');

            $urlParsed = parse_url($url);

            if ($urlParsed !== false) {
                if (isset($urlParsed['host'])) {
                    $host = $urlParsed['host'];
                }

                if (isset($urlParsed['port']) && $port === null) {
                    $port = $urlParsed['port'];
                }
            }
        }

        if (empty($host)) {
            $this->error('Invalid host');
            return 1;
        }

        $port = $port ?: '80';

        $this->line('-----------------');
        $this->line('|     NGROK     |');
        $this->line('-----------------');

        $this->line('');

        $this->line('<fg=green>Host: </fg=green>' . $host);
        $this->line('<fg=green>Port: </fg=green>' . $port);

        $this->line('');

        $process = $this->processBuilder->buildProcess($host, $port);

        return $this->runProcess($process);
    }

    /**
     * Run the process.
     *
     * @param \Symfony\Component\Process\Process $process
     * @return int Exit code.
     */
    private function runProcess(Process $process) : int
    {
        $webService = $this->webService;

        $webServiceStarted = false;
        $tunnelStarted = false;

        $process->run(function ($type, $data) use (&$process, &$webService, &$webServiceStarted, &$tunnelStarted) {
            if (! $webServiceStarted) {
                if (preg_match('/msg="starting web service".*? addr=(?<addr>\S+)/', $process->getOutput(), $matches)) {
                    $webServiceStarted = true;

                    $webServiceUrl = 'http://' . $matches['addr'];

                    $webService->setUrl($webServiceUrl);

                    $this->line('<fg=green>Web Interface: </fg=green>' . $webServiceUrl . "\n");
                }
            }

            if ($webServiceStarted && ! $tunnelStarted) {
                $tunnels = $webService->getTunnels();

                if (! empty($tunnels)) {
                    $tunnelStarted = true;

                    foreach ($tunnels as $tunnel) {
                        $this->line('<fg=green>Forwarding: </fg=green>'
                            . $tunnel['public_url'] . ' -> ' . $tunnel['config']['addr']);
                    }
                }
            }

            if (Process::OUT === $type) {
                $process->clearOutput();
            } else {
                $this->error($data);
                $process->clearErrorOutput();
            }
        });

        $this->error($process->getErrorOutput());

        return $process->getExitCode();
    }
}
