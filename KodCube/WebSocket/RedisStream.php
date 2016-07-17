<?php
namespace KodCube\WebSocket;

use React\Stream\Stream AS ReactStream;
use Psr\Log\{ LoggerInterface, NullLogger };
use React\EventLoop\LoopInterface;
use Throwable;
use Exception;


class RedisStream extends ReactStream
{
    public $stream;
    private $logger;




    public function __construct(LoopInterface $loop, $host='localhost',$port=6379,$database=0,LoggerInterface $logger=null)
    {
        $this->logger = $logger ?? new NullLogger();

        $url = 'tcp://'.$host.':'.$port;

        $this->stream = stream_socket_client($url, $errno, $errstr, 30, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT );

        parent::__construct($this->stream, $loop);

        $this->on('close',function($chunk) {
            $this->loop->stop();
            echo 'Connection Closed'.PHP_EOL;
        });




        $this->on('error',function($chunk) {
            throw new Exception($chunk);
        });

        $this->on('data',function ($chunk) {
            $this->logger->debug('Data from Redis',['msg'=>$chunk]);
            foreach($this->parse($chunk) AS $msg) {
                $type = $msg[0];
                $this->emit($type,[$msg[1],$msg[2]]);
            }
        });

        if ($database > 0) {
            if ( ! $this->select($database) ) {
                throw new Exception('Error setting database index');
            }
        }
    }

    public function __destruct()
    {
        fclose($this->stream);
    }

    public function getLoop()
    {
        return $this->loop;
    }


    public function auth($password):bool
    {

    }



    /**
     * @param int $index
     */

    public function select($index=0):bool
    {
        $this->logger->debug('SELECT ' . $index);
        try {
            fwrite($this->stream,'SELECT ' . $index . PHP_EOL);
            if ( $this->isOK($this->read())) return true;
        } catch (Throwable $e) {
            $this->logger->critical('Exception from Select Database Command');
            print_r($e);

        }
        return false;
    }
    /********** Keys/Strings ***************/
    public function set($key,$value):bool
    {
        fwrite($this->stream,'SET '.$key.' "'.(string)$value.'" '.PHP_EOL);
        return $this->isOK($this->read());
    }

    /********** Hashes ***************/
    public function hset($key,$field,$value):bool
    {
        $cmd = sprintf('HSET %s %s "%s" '.PHP_EOL,$key,$field,$value);
        fwrite($this->stream,sprintf('HSET %s %s "%s" '.PHP_EOL,$key,$field,$value));
        $result = $this->read();
        return $this->isOK($result);
    }
    /********** Lists ***************/

    public function rpush(string $queue,string $payload) {

        $cmd = sprintf('RPUSH %s "%s" '.PHP_EOL,$queue,$payload);
        fwrite($this->stream,$cmd);
        $result = $this->read();
        return $this->isOK($result);
    }

    /********** Pub/Sub ***************/

    public function subscribe(...$channels)
    {
        fwrite($this->stream,'SUBSCRIBE '.implode(' ',$channels).PHP_EOL);
        return $this->isOK($this->read());

    }

    private function isOK($response)
    {
        $response = current($response);
        return $response == 'OK';
    }

    private function read()
    {
        $response = '';
        while ( ($buffer = fgets($this->stream, 4096)) !== false ) {
//            echo 'Buffer: '.print_r($buffer,1).PHP_EOL;
            $response .= $buffer;
        }
        return $this->parse($response);

    }

    private function parse($chunk)
    {
        $lines = explode(PHP_EOL, trim($chunk));
        return $this->parseLines($lines);
    }
    private function parseLines(array $lines)
    {
        reset($lines);
        while(current($lines)) {
            yield $this->parseLine($lines);
        }
    }

    private function parseLine(&$lines)
    {
        $line = current($lines);
        $prefix = $line[0];
        switch ($prefix) {
            case '+': // Inline Reply
            case '-': // Error Reply
                next($lines);
                return substr($line,1);
            case '$': // String
                return $this->parseString($lines);
            case '*': // Array
                return $this->parseArray($lines);
            case ':': // Integer Reply
                return $this->parseInt($lines);
            default:
                echo "Unknown response prefix: ".$prefix.".";
                break;
        }
    }

    private function parseString(&$lines)
    {
        $line = current($lines);
        $payload = substr($line, 1);
        $size = (int) $payload;
        if ($size === -1) return;
        $line = next($lines);
        next($lines);
        return trim($line);

    }

    private function parseInt(&$lines)
    {
        $int = (int)substr(current($lines),1);
        next($lines);
        return $int;
    }

    private function parseArray(&$lines)
    {
        $line = current($lines);
        $payload = substr($line, 1);
        $count = (int) $payload; // No of Items in array
        if ($count === -1) return;
        next($lines); // Move Point to first element
        $array = [];
        $i = 0;
        while($i<$count) {
            $array[] = $this->parseLine($lines);
            $i++;
        }
        return $array;
    }

}


