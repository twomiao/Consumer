<?php
class Worker
{
    protected static $pids;

    protected $autoExited = false;


    public function start()
    {
        $pid = pcntl_fork();
        if ($pid === 0) {
            pcntl_signal(SIGUSR2, "Worker::installSignal");

            while (1 && !$this->autoExited) {
                sleep(1);
                echo 'worker run ' . PHP_EOL;
            }

            if ($this->autoExited) {
                echo 'exited worker pid:' . getmypid() . PHP_EOL;
            }

            return;
        } elseif ($pid > 0) {
            echo 'install master signal: sigusr2' . PHP_EOL;
            self::$pids = $pid;
            pcntl_signal(SIGUSR1, "Worker::installSignal");
        } else {
            echo 'error' . PHP_EOL;
        }

        while (1) {
            $pid = pcntl_wait($status, WNOHANG);

            echo '1111' . PHP_EOL;
            if ($pid > 0) {
                var_dump($pid);
                break;
            }
            sleep(1);
        }
    }

    public function installSignal($signal)
    {
        switch ($signal) {
            case SIGUSR2:
                $this->autoExited = true;
                echo 'recv signal: SIGUSR2' . PHP_EOL;
                break;
            case SIGUSR1:
                $pid = self::$pids;
                echo "sent signal sigusr2 to {$pid}\n";
                posix_kill($pid, SIGUSR2);
                break;
        }

    }
}


(new Worker())->start();