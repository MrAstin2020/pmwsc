<?php

declare(strict_types=1);

/**
 * Implementation of the Websocket Protocol to allow full-featured
 * chatting with basic command support, message receiving and sending
 * This is a modified version of the pmmp RCON implementation
 *
 * Heavily inspired by the PHP-Websockets repo: https://github.com/ghedipunk/PHP-Websockets
 * PHP-Websockets is coded and "Copyright (c) 2012, Adam Alexander"
 * You should have received a copy of the PHP-Websockets license in the resources folder
 * If not, you should be able to find it in the original repo linked above
 * @copyright 2019, XenialDan
 * @license GPL 3.0, See LICENSE
 * @author XenialDan
 */

namespace xenialdan\pmwsc\ws\tcp;

use InvalidStateException;
use pocketmine\event\server\CommandEvent;
use pocketmine\permission\PermissionManager;
use pocketmine\Server;
use pocketmine\snooze\SleeperNotifier;
use pocketmine\utils\Config;
use raklib\utils\InternetAddress;
use RuntimeException;
use xenialdan\pmwsc\Loader;
use xenialdan\pmwsc\user\WSUser;
use function max;
use function socket_bind;
use function socket_close;
use function socket_create;
use function socket_create_pair;
use function socket_getsockname;
use function socket_last_error;
use function socket_listen;
use function socket_set_nonblock;
use function socket_set_option;
use function socket_strerror;
use function socket_write;
use function trim;

class WS
{
    /** @var Server */
    private $server;
    /** @var resource */
    private $socket;

    /** @var WSInstance */
    private $instance;

    /** @var resource */
    private $ipcMainSocket;
    /** @var resource */
    private $ipcThreadSocket;
    protected $maxBufferSize = 2048;

    /**
     * WS constructor.
     * @param Server $server
     * @param InternetAddress $internetAddress
     * @param int $maxClients
     * @param int $bufferLength
     * @throws RuntimeException
     */
    public function __construct(Server $server, InternetAddress $internetAddress, int $maxClients = 50, $bufferLength = 2048)
    {
        $this->server = $server;
        $this->server->getLogger()->info('Starting remote control listener');
        $this->maxBufferSize = $bufferLength;
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($this->socket === false || !@socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1) || !@socket_bind($this->socket, $internetAddress->getIp(), $internetAddress->getPort()) || !@socket_listen($this->socket)) {
            throw new RuntimeException(trim(socket_strerror(socket_last_error())));
        }

        socket_set_nonblock($this->socket);

        $ret = @socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $ipc);
        if (!$ret) {
            $err = socket_last_error();
            if (($err !== SOCKET_EPROTONOSUPPORT && $err !== SOCKET_ENOPROTOOPT) || !@socket_create_pair(AF_INET, SOCK_STREAM, 0, $ipc)) {
                throw new RuntimeException(trim(socket_strerror(socket_last_error())));
            }
        }

        [$this->ipcMainSocket, $this->ipcThreadSocket] = $ipc;
        $this->server->getLogger()->debug('IPC Main socket is ' . $this->ipcMainSocket);
        $this->server->getLogger()->debug('IPC Thread socket is ' . $this->ipcThreadSocket);

        $notifier = new SleeperNotifier();
        $this->server->getTickSleeper()->addNotifier($notifier, function (): void {
            $this->check();
        });
        $this->instance = new WSInstance($this->socket, (int)max(1, $maxClients), $this->server->getLogger(), $this->ipcThreadSocket, $notifier);

        socket_getsockname($this->socket, $addr, $port);
        $this->server->getLogger()->debug("WS running on $addr:$port Socket " . $this->socket);
    }

    public function stop(): void
    {
        $this->instance->close();
        socket_write($this->ipcMainSocket, "\x00"); //make select() return
        $this->instance->quit();

        @socket_close($this->socket);
        @socket_close($this->ipcMainSocket);
        @socket_close($this->ipcThreadSocket);
    }

    /**
     * @throws RuntimeException
     * @throws InvalidStateException
     */
    public function check(): void
    {
        $command = $this->instance->cmd;
        if (empty($command) && $this->instance->user instanceof WSUser) {
            $user = $this->instance->user;
            $user->setOp($this->server->isOp($user->name));
            if (!empty($user->name) && !empty($user->auth)) {
                $this->server->getLogger()->debug('WS: Checking authentication code for user ' . $user->name);
                if (strtolower(Loader::getAuthCode($user->name, false)) !== $user->auth) {
                    $user->authenticated = false;
                    $this->instance->user = $user;
                }
            }
        } else if ($command[0] === '/') {
            $command = ltrim($command, '/');
            $sender = $this->instance->user;
            $this->server->getLogger()->debug('Called command ' . $command . ' for user ' . $sender);

            $ev = new CommandEvent($sender, $command);
            $ev->call();

            if (!$ev->isCancelled()) {
                $this->server->dispatchCommand($ev->getSender(), $ev->getCommand());
            }
            $this->instance->response = $sender->getMessage();
        } else {
            $this->instance->response = $this->broadcast($this->instance->user->name ?? 'Websocket User', $command);
        }

        $this->instance->synchronized(static function (WSInstance $thread) {
            $thread->notify();
        }, $this->instance);
    }

    /**
     * Call this to broadcast a message to pmmp
     * @param string $displayName
     * @param string $message
     * @return string
     */
    public function broadcast(string $displayName, string $message): string
    {
        $recipients = PermissionManager::getInstance()->getPermissionSubscriptions(Server::BROADCAST_CHANNEL_USERS);
        $format = 'chat.type.text';
        $this->server->broadcastMessage(($reply = '[Web Chat] ' . $this->server->getLanguage()->translateString($format, [$displayName, $message])), $recipients);

        $this->instance->synchronized(static function (WSInstance $thread) {
            $thread->notify();
        }, $this->instance);
        return $reply;
    }

    public function getOps(): Config
    {
        return $this->getServer()->getOps();
    }

    /**
     * @return Server
     */
    public function getServer(): Server
    {
        return $this->server;
    }

    /**
     * @return WSInstance
     */
    public function getInstance(): WSInstance
    {
        return $this->instance;
    }
}
