<?php
namespace KodCube\WebSocket;

use JsonSerializable;

class Message implements MessagePassThroughInterface,JsonSerializable
{
    private $channel;
    private $message;

    public function __construct($channel, $message)
    {
        $this->channel = $channel;
        $this->message = $message;
    }

    public function getChannel()
    {
        return $this->channel;
    }

    public function getBody()
    {
        return $this->message;
    }
    public function getParsedBody()
    {
        return json_decode($this->message);
    }

    public function jsonSerialize()
    {
        return [
            'channel' => $this->getChannel(),
            'body' => $this->getParsedBody()
        ];
    }
}
