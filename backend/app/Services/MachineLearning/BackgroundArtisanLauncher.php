<?php

namespace App\Services\MachineLearning;

/**
 * Runs `php artisan ml:train` detached from the web request (nohup ... & on Linux, start /B on Windows), with its
 * output appended to storage/logs/ml-train.log.
 *
 * The php binary is not PHP_BINARY blindly: under PHP-FPM / CGI that points at the FPM or CGI binary, which cannot
 * run artisan. It is taken from ML_PHP_BINARY when set, else PHP_BINARY if that is the CLI binary, else the CLI php
 * next to it (PHP_BINDIR), else plain "php" from the PATH.
 */
class BackgroundArtisanLauncher implements MlTrainingLauncher
{
    public function launch(): ?string
    {
        $windows = PHP_OS_FAMILY === 'Windows';
        $function = $windows ? 'popen' : 'exec';

        if (! $this->callable($function)) {
            return "Starting a background process is disabled on this server (PHP's {$function} function). "
                .'Schedule "php artisan ml:train" in cPanel > Cron Jobs instead (once a day, e.g. 03:30).';
        }

        $command = sprintf('%s %s ml:train', escapeshellarg($this->phpBinary()), escapeshellarg(base_path('artisan')));
        $log = escapeshellarg(storage_path('logs/ml-train.log'));

        if ($windows) {
            pclose(popen("start /B \"\" {$command} >> {$log} 2>&1", 'r'));
        } else {
            exec("nohup {$command} >> {$log} 2>&1 &");
        }

        return null;
    }

    public function phpBinary(): string
    {
        $configured = config('services.ml.php_binary');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if (in_array(strtolower(basename(PHP_BINARY, '.exe')), ['php'], true)) {
            return PHP_BINARY;
        }

        $cli = PHP_BINDIR.DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');

        return is_executable($cli) ? $cli : 'php';
    }

    private function callable(string $function): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return function_exists($function) && ! in_array($function, $disabled, true);
    }
}
