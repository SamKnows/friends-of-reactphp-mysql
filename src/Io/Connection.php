<?php

namespace React\MySQL\Io;

use Evenement\EventEmitter;
use React\MySQL\Commands\CommandInterface;
use React\MySQL\Commands\PingCommand;
use React\MySQL\Commands\QueryCommand;
use React\MySQL\Commands\QuitCommand;
use React\MySQL\ConnectionInterface;
use React\MySQL\Exception;
use React\MySQL\QueryResult;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Socket\ConnectionInterface as SocketConnectionInterface;
use React\Stream\ThroughStream;

/**
 * @internal
 * @see ConnectionInterface
 */
class Connection extends EventEmitter implements ConnectionInterface
{
    const STATE_AUTHENTICATED       = 5;
    const STATE_CLOSEING            = 6;
    const STATE_CLOSED              = 7;

    /**
     * @var Executor
     */
    private $executor;

    /**
     * @var integer
     */
    private $state = self::STATE_AUTHENTICATED;

    /**
     * @var SocketConnectionInterface
     */
    private $stream;

    /**
     * Connection constructor.
     *
     * @param SocketConnectionInterface $stream
     * @param Executor                  $executor
     */
    public function __construct(SocketConnectionInterface $stream, Executor $executor)
    {
        $this->stream   = $stream;
        $this->executor = $executor;

        $stream->on('error', [$this, 'handleConnectionError']);
        $stream->on('close', [$this, 'handleConnectionClosed']);
    }

    /**
     * {@inheritdoc}
     */
    public function query($sql, array $params = array())
    {
        $query = new Query($sql);
        if ($params) {
            $query->bindParamsFromArray($params);
        }

        $command = new QueryCommand();
        $command->setQuery($query);
        try {
            $this->_doCommand($command);
        } catch (\Exception $e) {
            return \React\Promise\reject($e);
        }

        $deferred = new Deferred();

        // store all result set rows until result set end
        $rows = array();
        $command->on('result', function ($row) use (&$rows) {
            $rows[] = $row;
        });
        $command->on('end', function () use ($command, $deferred, &$rows) {
            $result = new QueryResult();
            $result->resultFields = $command->resultFields;
            $result->resultRows = $rows;
            $rows = array();

            $deferred->resolve($result);
            $this->quit();
        });

        // resolve / reject status reply (response without result set)
        $command->on('error', function ($error) use ($deferred) {
            $deferred->reject($error);
            $this->quit();
        });
        $command->on('success', function () use ($command, $deferred) {
            $result = new QueryResult();
            $result->affectedRows = $command->affectedRows;
            $result->insertId = $command->insertId;

            $deferred->resolve($result);
            $this->quit();
        });

        return $deferred->promise();
    }

    public function queryStream($sql, $params = array())
    {
        $query = new Query($sql);
        if ($params) {
            $query->bindParamsFromArray($params);
        }

        $command = new QueryCommand();
        $command->setQuery($query);
        $this->_doCommand($command);

        $stream = new ThroughStream();

        // forward result set rows until result set end
        $command->on('result', function ($row) use ($stream) {
            $stream->write($row);
        });
        $command->on('end', function () use ($stream) {
            $stream->end();
        });

        // status reply (response without result set) ends stream without data
        $command->on('success', function () use ($stream) {
            $stream->end();
        });
        $command->on('error', function ($err) use ($stream) {
            $stream->emit('error', array($err));
            $stream->close();
        });

        return $stream;
    }

    public function ping()
    {
        return new Promise(function ($resolve, $reject) {
            $this->_doCommand(new PingCommand())
                ->on('error', function ($reason) use ($reject) {
                    $reject($reason);
                })
                ->on('success', function () use ($resolve) {
                    $resolve();
                });
        });
    }

    public function quit()
    {
        return new Promise(function ($resolve, $reject) {
            $this->_doCommand(new QuitCommand())
                ->on('error', function ($reason) use ($reject) {
                    $reject($reason);
                })
                ->on('success', function () use ($resolve) {
                    $this->state = self::STATE_CLOSED;
                    $this->emit('end', [$this]);
                    $this->emit('close', [$this]);
                    $resolve();
                });
            $this->state = self::STATE_CLOSEING;
        });
    }

    /**
     * @param Exception $err Error from socket.
     *
     * @return void
     * @internal
     */
    public function handleConnectionError($err)
    {
        $this->emit('error', [$err, $this]);
    }

    /**
     * @return void
     * @internal
     */
    public function handleConnectionClosed()
    {
        if ($this->state < self::STATE_CLOSEING) {
            $this->state = self::STATE_CLOSED;
            $this->emit('error', [new \RuntimeException('mysql server has gone away'), $this]);
        }

        // reject all pending commands if connection is closed
        while (!$this->executor->isIdle()) {
            $command = $this->executor->dequeue();
            $command->emit('error', array(
                new \RuntimeException('Connection lost')
            ));
        }
    }

    /**
     * @param CommandInterface $command The command which should be executed.
     * @return CommandInterface
     * @throws Exception Can't send command
     */
    protected function _doCommand(CommandInterface $command)
    {
        if ($this->state === self::STATE_AUTHENTICATED) {
            return $this->executor->enqueue($command);
        } else {
            throw new Exception("Can't send command");
        }
    }
}
