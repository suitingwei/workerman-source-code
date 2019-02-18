<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Workerman\Connection;

use Error;
use Workerman\Events\EventInterface;
use Workerman\Worker;

/**
 * TcpConnection.
 */
class TcpConnection extends ConnectionInterface
{
    /**
     * Read buffer size.
     *
     * @var int
     */
    const READ_BUFFER_SIZE = 65535;
    
    /**
     * Status initial.
     *
     * @var int
     */
    const STATUS_INITIAL = 0;
    
    /**
     * Status connecting.
     *
     * @var int
     */
    const STATUS_CONNECTING = 1;
    
    /**
     * Status connection established.
     *
     * @var int
     */
    const STATUS_ESTABLISHED = 2;
    
    /**
     * Status closing.
     *
     * @var int
     */
    const STATUS_CLOSING = 4;
    
    /**
     * Status closed.
     *
     * @var int
     */
    const STATUS_CLOSED = 8;
    
    /**
     * Emitted when data is received.
     *
     * @var callback
     */
    public $onMessage = null;
    
    /**
     * Emitted when the other end of the socket sends a FIN packet.
     *
     * @var callback
     */
    public $onClose = null;
    
    /**
     * Emitted when an error occurs with connection.
     *
     * @var callback
     */
    public $onError = null;
    
    /**
     * Emitted when the send buffer becomes full.
     *
     * @var callback
     */
    public $onBufferFull = null;
    
    /**
     * Emitted when the send buffer becomes empty.
     *
     * @var callback
     */
    public $onBufferDrain = null;
    
    /**
     * Application layer protocol.
     * The format is like this Workerman\\Protocols\\Http.
     *
     * @var \Workerman\Protocols\ProtocolInterface
     */
    public $protocol = null;
    
    /**
     * Transport (tcp/udp/unix/ssl).
     *
     * @var string
     */
    public $transport = 'tcp';
    
    /**
     * Which worker belong to.
     *
     * @var Worker
     */
    public $worker = null;
    
    /**
     * Bytes read.
     *
     * @var int
     */
    public $bytesRead = 0;
    
    /**
     * Bytes written.
     *
     * @var int
     */
    public $bytesWritten = 0;
    
    /**
     * Connection->id.
     *
     * @var int
     */
    public $id = 0;
    
    /**
     * A copy of $worker->id which used to clean up the connection in worker->connections
     *
     * @var int
     */
    protected $_id = 0;
    
    /**
     * Sets the maximum send buffer size for the current connection.
     * OnBufferFull callback will be emited When the send buffer is full.
     *
     * @var int
     */
    public $maxSendBufferSize = 1048576;
    
    /**
     * Default send buffer size.
     *
     * @var int
     */
    public static $defaultMaxSendBufferSize = 1048576;
    
    /**
     * Sets the maximum acceptable packet size for the current connection.
     *
     * @var int
     */
    public $maxPackageSize = 1048576;
    
    /**
     * Default maximum acceptable packet size.
     *
     * @var int
     */
    public static $defaultMaxPackageSize = 10485760;
    
    /**
     * Id recorder.
     *
     * @var int
     */
    protected static $_idRecorder = 1;
    
    /**
     * Socket
     *
     * @var resource
     */
    protected $_socket = null;
    
    /**
     * Send buffer.
     *
     * @var string
     */
    protected $_sendBuffer = '';
    
    /**
     * Receive buffer.
     *
     * @var string
     */
    protected $_recvBuffer = '';
    
    /**
     * Current package length.
     *
     * @var int
     */
    protected $_currentPackageLength = 0;
    
    /**
     * Connection status.
     *
     * @var int
     */
    protected $_status = self::STATUS_ESTABLISHED;
    
    /**
     * Remote address.
     *
     * @var string
     */
    protected $_remoteAddress = '';
    
    /**
     * Is paused.
     *
     * @var bool
     */
    protected $_isPaused = false;
    
    /**
     * SSL handshake completed or not.
     *
     * @var bool
     */
    protected $_sslHandshakeCompleted = false;
    
    /**
     * All connection instances.
     *
     * @var array
     */
    public static $connections = array();
    
    /**
     * Status to string.
     *
     * @var array
     */
    public static $_statusToString = array(
        self::STATUS_INITIAL     => 'INITIAL',
        self::STATUS_CONNECTING  => 'CONNECTING',
        self::STATUS_ESTABLISHED => 'ESTABLISHED',
        self::STATUS_CLOSING     => 'CLOSING',
        self::STATUS_CLOSED      => 'CLOSED',
    );
    
    
    /**
     * Adding support of custom functions within protocols
     *
     * @param string $name
     * @param array  $arguments
     *
     * @return void
     */
    public function __call($name, $arguments)
    {
        // Try to emit custom function within protocol
        if (method_exists($this->protocol, $name)) {
            try {
                return call_user_func(array($this->protocol, $name), $this, $arguments);
            } catch (\Exception $e) {
                Worker::log($e);
                exit(250);
            } catch (Error $e) {
                Worker::log($e);
                exit(250);
            }
        }
    }
    
    /**
     * Construct.
     *
     * @param resource $socket
     * @param string   $remote_address
     */
    public function __construct($socket, $remote_address = '')
    {
        //统计数据，这里其实就是存储在了 Connection这个类级别的对象里。其实感觉存在 worker 里也没毛病
        self::$statistics['connection_count']++;
        
        //记录链接的 id
        $this->id = $this->_id = self::$_idRecorder++;
        
        //链接记录器达到上限之后，进行重置
        if (self::$_idRecorder === PHP_INT_MAX) {
            self::$_idRecorder = 0;
        }
        
        //记录当前这个链接的链接套接字
        $this->_socket = $socket;
        
        //设置为非阻塞模式
        stream_set_blocking($this->_socket, 0);
        
        // Compatible with hhvm
        if (function_exists('stream_set_read_buffer')) {
            stream_set_read_buffer($this->_socket, 0);
        }
        
        //注册这个链接套接字的读事件handler
        Worker::$globalEvent->add($this->_socket, EventInterface::EV_READ, array($this, 'baseRead'));
        
        $this->maxSendBufferSize = self::$defaultMaxSendBufferSize;
        $this->maxPackageSize    = self::$defaultMaxPackageSize;
        $this->_remoteAddress    = $remote_address;
        
        //也是为了统计用的,其实这里换成 worker 也没啥影响
        static::$connections[$this->id] = $this;
    }
    
    /**
     * Get status.
     *
     * @param bool $raw_output
     *
     * @return int
     */
    public function getStatus($raw_output = true)
    {
        if ($raw_output) {
            return $this->_status;
        }
        
        return self::$_statusToString[$this->_status];
    }
    
    /**
     * Sends data on the connection.
     *
     * @param string $send_buffer
     * @param bool   $raw
     *
     * @return bool|null
     */
    public function send($send_buffer, $raw = false)
    {
        if ($this->_status === self::STATUS_CLOSING || $this->_status === self::STATUS_CLOSED) {
            return false;
        }
        
        // Try to call protocol::encode($send_buffer) before sending.
        if (false === $raw && $this->protocol !== null) {
            $parser      = $this->protocol;
            $send_buffer = $parser::encode($send_buffer, $this);
            if ($send_buffer === '') {
                return null;
            }
        }
        
        if ($this->_status !== self::STATUS_ESTABLISHED ||
            ($this->transport === 'ssl' && $this->_sslHandshakeCompleted !== true)
        ) {
            if ($this->_sendBuffer) {
                if ($this->bufferIsFull()) {
                    self::$statistics['send_fail']++;
                    
                    return false;
                }
            }
            $this->_sendBuffer .= $send_buffer;
            $this->checkBufferWillFull();
            
            return null;
        }
        
        // Attempt to send data directly.
        if ($this->_sendBuffer === '') {
            if ($this->transport === 'ssl') {
                Worker::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
                $this->_sendBuffer = $send_buffer;
                $this->checkBufferWillFull();
                
                return null;
            }
            set_error_handler(function () {
            });
            $len = fwrite($this->_socket, $send_buffer);
            restore_error_handler();
            // send successful.
            if ($len === strlen($send_buffer)) {
                $this->bytesWritten += $len;
                
                return true;
            }
            // Send only part of the data.
            if ($len > 0) {
                $this->_sendBuffer  = substr($send_buffer, $len);
                $this->bytesWritten += $len;
            } else {
                // Connection closed?
                if (!is_resource($this->_socket) || feof($this->_socket)) {
                    self::$statistics['send_fail']++;
                    if ($this->onError) {
                        try {
                            call_user_func($this->onError, $this, WORKERMAN_SEND_FAIL, 'client closed');
                        } catch (\Exception $e) {
                            Worker::log($e);
                            exit(250);
                        } catch (Error $e) {
                            Worker::log($e);
                            exit(250);
                        }
                    }
                    $this->destroy();
                    
                    return false;
                }
                $this->_sendBuffer = $send_buffer;
            }
            Worker::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
            // Check if the send buffer will be full.
            $this->checkBufferWillFull();
            
            return null;
        } else {
            if ($this->bufferIsFull()) {
                self::$statistics['send_fail']++;
                
                return false;
            }
            
            $this->_sendBuffer .= $send_buffer;
            // Check if the send buffer is full.
            $this->checkBufferWillFull();
        }
    }
    
    /**
     * Get remote IP.
     *
     * @return string
     */
    public function getRemoteIp()
    {
        $pos = strrpos($this->_remoteAddress, ':');
        if ($pos) {
            return substr($this->_remoteAddress, 0, $pos);
        }
        
        return '';
    }
    
    /**
     * Get remote port.
     *
     * @return int
     */
    public function getRemotePort()
    {
        if ($this->_remoteAddress) {
            return (int)substr(strrchr($this->_remoteAddress, ':'), 1);
        }
        
        return 0;
    }
    
    /**
     * Get remote address.
     *
     * @return string
     */
    public function getRemoteAddress()
    {
        return $this->_remoteAddress;
    }
    
    /**
     * Get local IP.
     *
     * @return string
     */
    public function getLocalIp()
    {
        $address = $this->getLocalAddress();
        $pos     = strrpos($address, ':');
        if (!$pos) {
            return '';
        }
        
        return substr($address, 0, $pos);
    }
    
    /**
     * Get local port.
     *
     * @return int
     */
    public function getLocalPort()
    {
        $address = $this->getLocalAddress();
        $pos     = strrpos($address, ':');
        if (!$pos) {
            return 0;
        }
        
        return (int)substr(strrchr($address, ':'), 1);
    }
    
    /**
     * Get local address.
     *
     * @return string
     */
    public function getLocalAddress()
    {
        return (string)@stream_socket_get_name($this->_socket, false);
    }
    
    /**
     * Get send buffer queue size.
     *
     * @return integer
     */
    public function getSendBufferQueueSize()
    {
        return strlen($this->_sendBuffer);
    }
    
    /**
     * Get recv buffer queue size.
     *
     * @return integer
     */
    public function getRecvBufferQueueSize()
    {
        return strlen($this->_recvBuffer);
    }
    
    /**
     * Is ipv4.
     *
     * return bool.
     */
    public function isIpV4()
    {
        if ($this->transport === 'unix') {
            return false;
        }
        
        return strpos($this->getRemoteIp(), ':') === false;
    }
    
    /**
     * Is ipv6.
     *
     * return bool.
     */
    public function isIpV6()
    {
        if ($this->transport === 'unix') {
            return false;
        }
        
        return strpos($this->getRemoteIp(), ':') !== false;
    }
    
    /**
     * Pauses the reading of data. That is onMessage will not be emitted. Useful to throttle back an upload.
     *
     * @return void
     */
    public function pauseRecv()
    {
        Worker::$globalEvent->del($this->_socket, EventInterface::EV_READ);
        $this->_isPaused = true;
    }
    
    /**
     * Resumes reading after a call to pauseRecv.
     *
     * @return void
     */
    public function resumeRecv()
    {
        if ($this->_isPaused === true) {
            Worker::$globalEvent->add($this->_socket, EventInterface::EV_READ, array($this, 'baseRead'));
            $this->_isPaused = false;
            $this->baseRead($this->_socket, false);
        }
    }
    
    
    /**
     * Base read handler.
     *
     * @param resource $socket
     * @param bool     $check_eof
     *
     *
     *
     * @return void 这里一定要注意，整个baseRead函数是出于外部的`stream_select`调用的循环中的。所以如果在这个方法中 return 了，那么相当于
     *              这一次的客户端请求已经处理完毕。如果
     */
    public function baseRead($socket, $check_eof = true)
    {
        // SSL handshake.
        if ($this->transport === 'ssl' && $this->_sslHandshakeCompleted !== true) {
            if ($this->doSslHandshake($socket)) {
                $this->_sslHandshakeCompleted = true;
                if ($this->_sendBuffer) {
                    Worker::$globalEvent->add($socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
                }
            } else {
                return;
            }
        }
        
        set_error_handler(function () {
        });
        $buffer = fread($socket, self::READ_BUFFER_SIZE);
        restore_error_handler();
        
        // Check connection closed.
        if ($buffer === '' || $buffer === false) {
            if ($check_eof && (feof($socket) || !is_resource($socket) || $buffer === false)) {
                $this->destroy();
                
                return;
            }
        } else {
            $this->bytesRead   += strlen($buffer);
            $this->_recvBuffer .= $buffer;
        }
        
        //如果设置了应用层协议，比如http/https/mail等,那么需要按照对应的协议进行数据解析
        if ($this->protocol !== null) {
            $parser = $this->protocol;
            while ($this->_recvBuffer !== '' && !$this->_isPaused) {
                // The current packet length is known.
                if ($this->_currentPackageLength) {
                    // Data is not enough for a package.
                    if ($this->_currentPackageLength > strlen($this->_recvBuffer)) {
                        break;
                    }
                } else {
                    // Get current package length.
                    set_error_handler(function ($code, $msg, $file, $line) {
                        Worker::safeEcho("$msg in file $file on line $line\n");
                    });
                    //获取当前包的大小，在 http 协议是有content-length,不过这里 workerman 好坑，他的interface 没有完全实现，
                    //只有 ws 协议是完整实现了，其他的虽然实现了，但是没有写implement，所以没法直接去用 ide 查看。这里就自己找到
                    //workerman\\protocols\\*.php 对查看对应的解析即可
                    $this->_currentPackageLength = $parser::input($this->_recvBuffer, $this);
                    restore_error_handler();
                    
                    // The packet length is unknown.
                    if ($this->_currentPackageLength === 0) {
                        //跳出 while 循环，直接return了，这样最终就会进入`stream_select`循环中，等待客户端链接发送的下一个包数据;
                        break;
                    } elseif ($this->_currentPackageLength > 0 && $this->_currentPackageLength <= $this->maxPackageSize) {
                        //如果这个包的大小比目前收集到的包大小大，那么还需要继续接受包，直接 break
                        if ($this->_currentPackageLength > strlen($this->_recvBuffer)) {
                            break;
                        }
                    }
                    //如果当前包的大小比定义的最大值还大，视为错误包。断开链接,进行四次挥手
                    else {
                        Worker::safeEcho('error package. package_length=' . var_export($this->_currentPackageLength, true));
                        $this->destroy();
                        
                        return;
                    }
                }
                
                // The data is enough for a packet.
                self::$statistics['total_request']++;
                
                // The current packet length is equal to the length of the buffer.
                if (strlen($this->_recvBuffer) === $this->_currentPackageLength) {
                    $one_request_buffer = $this->_recvBuffer;
                    $this->_recvBuffer  = '';
                } else {
                    // Get a full package from the buffer.
                    $one_request_buffer = substr($this->_recvBuffer, 0, $this->_currentPackageLength);
                    // Remove the current package from the receive buffer.
                    $this->_recvBuffer = substr($this->_recvBuffer, $this->_currentPackageLength);
                }
                // Reset the current packet length to 0.
                $this->_currentPackageLength = 0;
                if (!$this->onMessage) {
                    continue;
                }
                try {
                    //执行onMessage回调，同时使用decode方法来解析包数据
                    call_user_func($this->onMessage, $this, $parser::decode($one_request_buffer, $this));
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
            
            Worker::log(sprintf("Receiving data from connection:%s",$buffer));
            return;
        }
        
        if ($this->_recvBuffer === '' || $this->_isPaused) {
            return;
        }
        
        // Applications protocol is not set.
        self::$statistics['total_request']++;
        if (!$this->onMessage) {
            $this->_recvBuffer = '';
            
            return;
        }
        try {
            call_user_func($this->onMessage, $this, $this->_recvBuffer);
        } catch (\Exception $e) {
            Worker::log($e);
            exit(250);
        } catch (Error $e) {
            Worker::log($e);
            exit(250);
        }
        // Clean receive buffer.
        $this->_recvBuffer = '';
    }
    
    /**
     * Base write handler.
     *
     * @return void|bool
     */
    public function baseWrite()
    {
        set_error_handler(function () {
        });
        if ($this->transport === 'ssl') {
            $len = fwrite($this->_socket, $this->_sendBuffer, 8192);
        } else {
            $len = fwrite($this->_socket, $this->_sendBuffer);
        }
        restore_error_handler();
        if ($len === strlen($this->_sendBuffer)) {
            $this->bytesWritten += $len;
            Worker::$globalEvent->del($this->_socket, EventInterface::EV_WRITE);
            $this->_sendBuffer = '';
            // Try to emit onBufferDrain callback when the send buffer becomes empty.
            if ($this->onBufferDrain) {
                try {
                    call_user_func($this->onBufferDrain, $this);
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
            if ($this->_status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            
            return true;
        }
        if ($len > 0) {
            $this->bytesWritten += $len;
            $this->_sendBuffer  = substr($this->_sendBuffer, $len);
        } else {
            self::$statistics['send_fail']++;
            $this->destroy();
        }
    }
    
    /**
     * SSL handshake.
     *
     * @param $socket
     *
     * @return bool
     */
    public function doSslHandshake($socket)
    {
        if (feof($socket)) {
            $this->destroy();
            
            return false;
        }
        $async = $this instanceof AsyncTcpConnection;
        if ($async) {
            $type = STREAM_CRYPTO_METHOD_SSLv2_CLIENT | STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
        } else {
            $type = STREAM_CRYPTO_METHOD_SSLv2_SERVER | STREAM_CRYPTO_METHOD_SSLv23_SERVER;
        }
        
        // Hidden error.
        set_error_handler(function ($errno, $errstr, $file) {
            if (!Worker::$daemonize) {
                Worker::safeEcho("SSL handshake error: $errstr \n");
            }
        });
        $ret = stream_socket_enable_crypto($socket, true, $type);
        restore_error_handler();
        // Negotiation has failed.
        if (false === $ret) {
            $this->destroy();
            
            return false;
        } elseif (0 === $ret) {
            // There isn't enough data and should try again.
            return false;
        }
        if (isset($this->onSslHandshake)) {
            try {
                call_user_func($this->onSslHandshake, $this);
            } catch (\Exception $e) {
                Worker::log($e);
                exit(250);
            } catch (Error $e) {
                Worker::log($e);
                exit(250);
            }
        }
        
        return true;
    }
    
    /**
     * This method pulls all the data out of a readable stream, and writes it to the supplied destination.
     *
     * @param TcpConnection $dest
     *
     * @return void
     */
    public function pipe($dest)
    {
        $source              = $this;
        $this->onMessage     = function ($source, $data) use ($dest) {
            $dest->send($data);
        };
        $this->onClose       = function ($source) use ($dest) {
            $dest->destroy();
        };
        $dest->onBufferFull  = function ($dest) use ($source) {
            $source->pauseRecv();
        };
        $dest->onBufferDrain = function ($dest) use ($source) {
            $source->resumeRecv();
        };
    }
    
    /**
     * Remove $length of data from receive buffer.
     *
     * @param int $length
     *
     * @return void
     */
    public function consumeRecvBuffer($length)
    {
        $this->_recvBuffer = substr($this->_recvBuffer, $length);
    }
    
    /**
     * Close connection.
     *
     * @param mixed $data
     * @param bool  $raw
     *
     * @return void
     */
    public function close($data = null, $raw = false)
    {
        if ($this->_status === self::STATUS_CLOSING || $this->_status === self::STATUS_CLOSED) {
            return;
        } else {
            if ($data !== null) {
                $this->send($data, $raw);
            }
            $this->_status = self::STATUS_CLOSING;
        }
        if ($this->_sendBuffer === '') {
            $this->destroy();
        } else {
            $this->pauseRecv();
        }
    }
    
    /**
     * Get the real socket.
     *
     * @return resource
     */
    public function getSocket()
    {
        return $this->_socket;
    }
    
    /**
     * Check whether the send buffer will be full.
     *
     * @return void
     */
    protected function checkBufferWillFull()
    {
        if ($this->maxSendBufferSize <= strlen($this->_sendBuffer)) {
            if ($this->onBufferFull) {
                try {
                    call_user_func($this->onBufferFull, $this);
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
        }
    }
    
    /**
     * Whether send buffer is full.
     *
     * @return bool
     */
    protected function bufferIsFull()
    {
        // Buffer has been marked as full but still has data to send then the packet is discarded.
        if ($this->maxSendBufferSize <= strlen($this->_sendBuffer)) {
            if ($this->onError) {
                try {
                    call_user_func($this->onError, $this, WORKERMAN_SEND_FAIL, 'send buffer full and drop package');
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Whether send buffer is Empty.
     *
     * @return bool
     */
    public function bufferIsEmpty()
    {
        return empty($this->_sendBuffer);
    }
    
    /**
     * Destroy connection.
     *
     * @return void
     */
    public function destroy()
    {
        // Avoid repeated calls.
        if ($this->_status === self::STATUS_CLOSED) {
            return;
        }
        // Remove event listener.
        Worker::$globalEvent->del($this->_socket, EventInterface::EV_READ);
        Worker::$globalEvent->del($this->_socket, EventInterface::EV_WRITE);
        
        // Close socket.
        set_error_handler(function () {
        });
        fclose($this->_socket);
        restore_error_handler();
        
        $this->_status = self::STATUS_CLOSED;
        // Try to emit onClose callback.
        if ($this->onClose) {
            try {
                call_user_func($this->onClose, $this);
            } catch (\Exception $e) {
                Worker::log($e);
                exit(250);
            } catch (Error $e) {
                Worker::log($e);
                exit(250);
            }
        }
        // Try to emit protocol::onClose
        if ($this->protocol && method_exists($this->protocol, 'onClose')) {
            try {
                call_user_func(array($this->protocol, 'onClose'), $this);
            } catch (\Exception $e) {
                Worker::log($e);
                exit(250);
            } catch (Error $e) {
                Worker::log($e);
                exit(250);
            }
        }
        if ($this->_status === self::STATUS_CLOSED) {
            // Cleaning up the callback to avoid memory leaks.
            $this->onMessage = $this->onClose = $this->onError = $this->onBufferFull = $this->onBufferDrain = null;
            // Remove from worker->connections.
            if ($this->worker) {
                unset($this->worker->connections[$this->_id]);
            }
            unset(static::$connections[$this->_id]);
        }
    }
    
    /**
     * Destruct.
     *
     * @return void
     */
    public function __destruct()
    {
        static $mod;
        self::$statistics['connection_count']--;
        if (Worker::getGracefulStop()) {
            if (!isset($mod)) {
                $mod = ceil((self::$statistics['connection_count'] + 1) / 3);
            }
            
            if (0 === self::$statistics['connection_count'] % $mod) {
                Worker::log('worker[' . posix_getpid() . '] remains ' . self::$statistics['connection_count'] . ' connection(s)');
            }
            
            if (0 === self::$statistics['connection_count']) {
                Worker::stopAll();
            }
        }
    }
}
