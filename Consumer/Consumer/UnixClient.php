<?php
namespace Consumer\Consumer;

class UnixClient
{
    protected $unixClient = null;

    protected $isConncted = false;

    public function __construct(string $sockFile)
    {
        $this->unixClient = socket_create(AF_UNIX, SOCK_STREAM, 0);
        $this->isConncted = socket_connect($this->unixClient, $sockFile);
    }

    public function isConnected(): bool
    {
        return $this->isConncted;
    }

    public function closed(): void
    {
        if (!$this->isConnected()) {
            throw new \Exception('Sock file connection failed: ' . $this->getSocketLastError());
        }
        socket_close($this->unixClient);
    }

    public function recv(): string
    {
        if ($this->isConnected()) {
            socket_recv($this->unixClient, $buffer, 2048, 0);
            if ($buffer === false) {
                throw new \Exception("Failed to receive message: " . $this->getSocketLastError());
            }
            return $buffer;
        }
        return '';
    }

    private function getSocketLastError()
    {
        return socket_strerror(socket_last_error());
    }

    public function send($data): bool
    {
        if ($this->isConnected()) {
            $data = pack('N', strlen($data)) . $data;
            $flag = socket_write($this->unixClient, $data, strlen($data));
            if ($flag === false) {
                throw new \Exception('Failed to send: ' . $this->getSocketLastError());
            }
            return true;
        }
        return false;
    }
}