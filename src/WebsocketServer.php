<?php
namespace Bobby\Websocket;

class WebsocketServer
{
    protected $config;

    protected $eventHandler;

    public $readers = []; // 所有监听socket

    public $connections = []; // 所有已握手连接

    public $listen; // 服务监听的socket

    public function __construct(ServerConfig $config, EventHandlerContract $eventHandler)
    {
        if (!$config->address || !$config->port) {
            throw new \InvalidArgumentException("instance of " . get_class($config) . " must to set address or port");
        }
        $this->config = $config;

        $eventHandler->bindServer($this);
        $this->eventHandler = $eventHandler;
    }

    public function run()
    {
        switch ($this->config->mode) {
            case ServerConfig::POLL_MODE:
                $this->poll();
        }
    }

    protected function listenSocket()
    {
        if (!$this->listen = stream_socket_server("tcp://{$this->config->address}:{$this->config->port}", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN)) {
            throw new \Exception($errstr, $errno);
        };
        stream_set_blocking($this->listen, false);
        stream_context_set_option($this->listen, SOL_SOCKET, SO_REUSEADDR, 1);
    }

    protected function poll()
    {
        $this->listenSocket();
        $this->readers[] = $this->listen;

        $writers = $exceptions = [];
        while (true) {
            $readers = $this->readers;
            stream_select($readers, $writers, $exceptions, null);

            foreach ($readers as $key => $reader) {
                if ($reader === $this->listen) {
                    $this->acceptConnection($reader);
                } else {
                    if (!$this->isShackedConnection($reader)) {
                        $this->shackWithConnection($reader);
                    } else {
                        if (!$buff = stream_get_contents($reader)) {
                            $this->dealLostPackage($reader);
                            continue;
                        };

                        if (is_null($frame = (new Frame())->decodeClientBuff($buff))) {
                            continue;
                        }

                        switch ($frame->opcode) {
                            case OpcodeEnum::PING:
                                $this->eventHandler->onPing($reader, $frame, new PushResponse());
                                break;
                            case OpcodeEnum::OUT_CONNECT:
                                $this->eventHandler->onOutConnect($reader);
                                break;
                            case OpcodeEnum::TEXT:
                            case OpcodeEnum::PONG:
                                $this->eventHandler->onMessage($reader, $frame, new PushResponse());
                        }
                    }
                }
            }
        }
    }

    protected function dealLostPackage($socket)
    {
        if (method_exists($this->eventHandler, "onLostPackage")) {
            $this->eventHandler->onLostPackage($socket);
        }
    }

    protected function acceptConnection($socket)
    {
        $connection = stream_socket_accept($socket);
        $this->readers[(intval($socket))] = $connection;
        stream_set_blocking($connection, false);
    }

    protected function shackWithConnection($socket)
    {
        if (!$head = stream_get_contents($socket)) {
            return $this->dealLostPackage($socket);
        }

        if (method_exists($this->eventHandler, "onShack")) {
            $this->eventHandler->onShack(new ShackHttpRequest($head), $response = new RefuseShackResponse());

            if ($response->hasErrors()) {
                return $response->response($socket);
            }
        }

        $key = substr($head, strpos($head, "Sec-WebSocket-Key:") + 18);
        $key = trim(substr($key, 0, strpos($key, "\r\n")));
        $newKey = base64_encode(sha1($key . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));

        $newHead = "HTTP/1.1 101 Switching Protocols\r\n";
        $newHead .= "Upgrade: websocket\r\n";
        $newHead .= "Sec-WebSocket-Version: 13\r\n";
        $newHead .= "Connection: Upgrade\r\n";
        $newHead .= "Sec-WebSocket-Accept: $newKey\r\n\r\n";

        fwrite($socket, $newHead);

        $this->connections[intval($socket)] = $socket;
        $this->readers[intval($socket)] = $socket;

        if (method_exists($this->eventHandler, 'onConnection')) {
            $this->eventHandler->onConnection($socket);
        }
    }

    protected function isShackedConnection($connection)
    {
        return in_array($connection, $this->connections);
    }
}