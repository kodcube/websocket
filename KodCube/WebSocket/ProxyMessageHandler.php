<?php
namespace KodCube\WebSocket;

use React\EventLoop\LoopInterface;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use SplObjectStorage;
use Psr\Log\{ LoggerInterface, NullLogger };

use Exception;

class ProxyMessageHandler implements MessageComponentInterface
{
    protected $clients;
    protected $channels = [];

    public function __construct(LoopInterface $loop, RedisStream $redis, LoggerInterface $logger=null)
    {
        $this->loop = $loop;

        $this->pid = getmypid();

        $this->logger = $logger ?? new NullLogger();

        $this->redis = $redis;
        
        $this->clients = new SplObjectStorage;

        $this->redis->on('message',function ($channel,$message) {

            $this->logger->debug('Message Received',['channel'=>$channel,'message'=>$message]);
            // check for special messages
            if (in_array($channel,['ws:thread:all','ws:thread:'.$this->pid])) {
                switch ($message) {
                    case 'restart':
                    case 'exit':
                        echo get_class($this).PHP_EOL;
                        $this->metadata('down',date('r'));

                        sleep(5);
                        $this->logger->error($message,['class' => get_class($this),'pid'=>$this->pid,'channel' => $channel,'message'=>$message]);
                        $this->loop->stop();
                        return;
                }
            }
            $this->send(new Message($channel,$message));
        });

        // Record Thread Stats
        $this->metadata('up',date('r'));

        // Add Subscriptions for thread management
        $this->redis->subscribe('ws:thread:all','ws:thread:'.$this->pid);
    }


    private function metadata($name,$value)
    {
        $this->redis->hset('ws:thread:'.$this->pid,$name,$value);
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $this->logger->debug('New connection!',['resourceID' => $conn->resourceId]);
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $numRecv = count($this->clients) - 1;

        $this->logger->debug(
            sprintf('Connection %d sending message "%s" to %d other connection%s' . "\n", $from->resourceId, $msg, $numRecv, $numRecv == 1 ? '' : 's'),
            [
                'resourceID' => $from->resourceId,
                'msg' => $msg
            ]
        );

        $cmd = json_decode($msg);
        
        switch ( $cmd->command ) {
            case '/v1/subscribe':
                if (isset($cmd->channel)) {
                    if ( ! isset($this->channels[$cmd->channel] )) {
                        $this->channels[$cmd->channel][] = $from;
                        $this->redis->subscribe($cmd->channel);
                    }
                    break;
                }
                if (isset($cmd->channels)) {
                    foreach ($cmd->channels AS $channel) {
                        if ( ! isset($this->channels[$channel] )) {
                            $this->channels[$channel][] = $from;
                            $this->redis->subscribe($channel);
                            continue;
                        }
                        if ( ! in_array($from,$this->channels[$channel]) ) {
                            $this->channels[$channel][] = $from;
                        }
                    }
                    break;
                }
                break;

            case '/v1/connected':


                break;
            case 'refresh': // Force Refresh of all data
                $this->redis->rpush('queue.default',json_encode([
                //    'className' =>
                ]));
                
                
                break;
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        // The connection is closed, remove it, as we can no longer send it messages
        foreach ($this->channels AS $channel=>$clients) {
            if ( ! $key = array_search($conn,$clients) ) continue;
            unset($this->channels[$channel][$key]);
        }
                
        $this->clients->detach($conn);

        $this->logger->debug('Connection Closed',['resourceID' => $conn->resourceId]);
    }

    public function onError(ConnectionInterface $conn, Exception $e)
    {
        $this->logger->error('An error has occurred: '.$e->getMessage(),['resourceID'=>$conn->resourceId,'code'=>$e->getCode(),'line'=>$e->getLine(),'message'=>$e->getMessage()]);
        $conn->close();
    }

    public function send($msg) 
    {
        $text = $msg->getBody();

        foreach ($this->channels[$msg->getChannel()] as $client) {
            $this->logger->debug('Sending Message',['msg' => $msg]);
            $client->send(json_encode($msg));
        }
    }
}