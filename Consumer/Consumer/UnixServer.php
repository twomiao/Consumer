<?php

namespace Consumer\Consumer;

class UnixServer
{
    /**
     * @var false|resource
     */
    protected $unixServer;

    /**
     * @var array $clients
     */
    protected $clients    = [];

    /**
     * @var array $readSocks
     */
    protected $readSocks  = [];

    /**
     * @var array $writeSocks
     */
    protected $writeSocks = [];

    /**
     * @var array $excepts
     */
    protected $excepts   = [];

    /**
     * UnixServer constructor.
     * @param string $sockFile
     */
    public function __construct(string $sockFile)
    {
        if (file_exists($sockFile)) {
            unlink($sockFile);
        }

        $this->unixServer = socket_create(AF_UNIX, SOCK_STREAM, 0);
        if (!is_resource($this->unixServer)) {
            throw new \RuntimeException("Unable to create socket: " . $this->getSocketLastError());
        }

        if (!socket_bind($this->unixServer, $sockFile)) {
            throw new \RuntimeException("socket_bind(#socket) fail: " . $this->getSocketLastError());
        }
        if (socket_listen($this->unixServer, 2048) === false) {
            throw new \RuntimeException("socket_listen(#socket) fail: " . $this->getSocketLastError());
        }
        $this->clients[] = $this->unixServer;
    }

    public function listen(callable  $call)
    {
        $this->readSocks = $this->clients;
        if (@socket_select($this->readSocks, $this->writeSocks, $this->excepts, 1, 0) > 0) {
            if (in_array($this->unixServer, $this->readSocks)) {
                socket_set_nonblock($this->unixServer);
                $this->clients[] = socket_accept($this->unixServer);
                $index = array_search($this->unixServer, $this->readSocks);
                unset($this->readSocks[$index]);
            }

            foreach ($this->readSocks as $key => $read_sock) {
                $flag = socket_recv($read_sock, $data, 2048, 0);

                if ($flag === false) {
//                    print(socket_strerror(socket_last_error($read_sock)));
                    unset($this->clients[$key]);
                    socket_close($read_sock);
                    continue;
                } elseif ($flag === 0) {
                    unset($this->clients[$key]);
                    socket_close($read_sock);
                    continue;
                } elseif ($flag > 0) {
                    $data = substr($data, -unpack('N', $data)[1]);
                    extract(unserialize($data));
                    $call($pid, $status);
                }
            }
        }
        return $this;
    }

    protected function getSocketLastError()
    {
        return socket_strerror(socket_last_error());
    }
}