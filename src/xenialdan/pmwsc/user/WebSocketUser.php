<?php

namespace xenialdan\pmwsc\user;
class WebSocketUser
{

    public $socket;
    public $id;
    public $headers = [];
    public $handshake = false;

    public $handlingPartialPacket = false;
    public $partialBuffer = '';
    public $requestedResource = '';

    public $sendingContinuous = false;
    public $partialMessage = '';

    public $hasSentClose = false;

    public function __construct($id, $socket)
    {
        $this->id = $id;
        $this->socket = $socket;
    }
}