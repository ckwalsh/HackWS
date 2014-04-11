<?hh // strict

namespace CKWalsh\HackWS\Protocol\Sockets;

use \CKWalsh\HackWS\Protocol\Frames\WSFrame;
use \CKWalsh\HackWS\Protocol\Frames\WSFrameOpcodeEnum;
use \CKWalsh\HackWS\Protocol\Frames\WSFrameReader;
use \CKWalsh\HackWS\Protocol\Handshakes\WSHandshake;

use \Awaitable;
use \Exception;
use \SleepWaitHandle;

use \STREAM_CLIENT_CONNECT;
use \STREAM_CLIENT_ASYNC_CONNECT;

class WSSocket {
  const float DEFAULT_CONNECT_TIMEOUT = 30.0;

  private ?resource $socket;
  private string $uri;
  private WSHandshake $handshake;
  private WSFrameReader $frameReader;
  private WSSocketState $state;

  public function __construct(string $uri, WSHandshake $handshake) {
    $this->uri = $uri;
    $this->handshake = $handshake;
    $this->frameReader = new WSFrameReader();
    $this->state = WSSocketStateEnum::DISCONNECTED;
  }

  public function isConnected(): bool {
    return $this->state === WSSocketStateEnum::CONNECTED;
  }

  public async function asyncConnect(
    float $timeout = self::DEFAULT_CONNECT_TIMEOUT,
  ): Awaitable<void> {
    if ($this->state !== WSSocketStateEnum::DISCONNECTED) {
      throw new WSSocketException(
        'Websocket is not disconnected: '.(string)$this->state,
      );
    }

    $this->state = WSSocketStateEnum::CONNECTING;

    $uri_parts = parse_url($this->uri);

    if ($uri_parts === false) {
      $this->state = WSSocketStateEnum::DISCONNECTED;
      throw new WSSocketException('Malformed uri: '.$this->uri);
    }

    $uri_parts = Map::fromArray($uri_parts);

    $scheme = (string) $uri_parts->at('scheme');

    if ($scheme !== 'ws' && $scheme !== 'wss') {
      $this->state = WSSocketStateEnum::DISCONNECTED;
      throw new WSSocketException('Unrecognized scheme: '.$scheme);
    }

    $host = (string) $uri_parts->at('host');
    $port = (int) $uri_parts->at('port');

    $errno = 0;
    $errstr = '';

    $socket = fsockopen(
      ($scheme === 'wss' ? 'ssl://' : 'tcp://').$host,
      $port,
      $errno,
      $errstr,
      $timeout,
    );

    stream_set_blocking($socket, 0);

    if ($socket === false) {
      $this->state = WSSocketStateEnum::DISCONNECTED;
      throw new WSSocketException(
        'Unable to connect: Err '.$errno.' - '.$errstr,
      );
    }

    $this->state = WSSocketStateEnum::HANDSHAKE;
    $data = await $this->handshake->asyncDoHandshake($socket, $this->uri);
    $this->frameReader->reset();
    $this->frameReader->appendData($data);

    $this->socket = $socket;
    $this->state = WSSocketStateEnum::CONNECTED;
  }

  public function connect(
    float $timeout = self::DEFAULT_CONNECT_TIMEOUT,
  ): void {
    return $this->asyncConnect($timeout)->getWaitHandle()->join();
  }

  public async function asyncClose(int $close_status = 1000): Awaitable<void> {
    if ($this->state !== WSSocketStateEnum::CONNECTED) {
      throw new WSSocketException('Socket is not in connected state');
    }
    $this->state = WSSocketStateEnum::DISCONNECTING;

    $close_frame = new WSFrame();
    $close_frame->opcode = WSFrameOpcodeEnum::CTRL_CLOSE;
    $close_frame->payload = (string) pack('n', $close_status);
    await $this->asyncWriteImpl($close_frame, true, false);

    $this->state = WSSocketStateEnum::DISCONNECTING_WAIT;

    $close_frame = null;
    while ($close_frame === null
           || $close_frame->opcode != WSFrameOpcodeEnum::CTRL_CLOSE) {
      $close_frame = await $this->asyncReadImpl(false);
    }

    fclose($this->socket);
    $this->state = WSSocketStateEnum::DISCONNECTED;
    $this->socket = null;
  }

  public async function asyncCloseImmediately(
    int $close_status = 1000,
  ): Awaitable<void> {
    if ($this->state !== WSSocketStateEnum::CONNECTED) {
      throw new WSSocketException('Socket is not in connected state');
    }

    $this->state = WSSocketStateEnum::DISCONNECTING;

    $close_frame = new WSFrame();
    $close_frame->opcode = WSFrameOpcodeEnum::CTRL_CLOSE;
    $close_frame->payload = (string) pack('n', $close_status);
    await $this->asyncWriteImpl($close_frame, true, false);

    fclose($this->socket);
    $this->state = WSSocketStateEnum::DISCONNECTED;
    $this->socket = null;
  }

  public function close(): void {
    return $this->asyncClose()->getWaitHandle()->join();
  }

  public function closeImmediately(): void {
    return $this->asyncCloseImmediately()->getWaitHandle()->join();
  }

  public async function asyncRead(): Awaitable<?WSFrame> {
    return await $this->asyncReadImpl();
  }

  public async function asyncReadImpl(
    bool $check_state = true,
  ): Awaitable<?WSFrame> {
    $frame = null;
    while ($frame === null) {
      if ($check_state && $this->state !== WSSocketStateEnum::CONNECTED) {
        return null;
      }
      $frame = await $this->asyncReadNonBlocking();
      if ($frame === null) {
        await SleepWaitHandle::create(1);
      }
    }

    return $frame;
  }

  public async function asyncReadNonBlocking(): Awaitable<?WSFrame> {
    return await $this->asyncReadNonBlockingImpl();
  }

  protected async function asyncReadNonBlockingImpl(
    bool $check_state = true,
  ): Awaitable<?WSFrame> {
    if ($check_state && $this->state !== WSSocketStateEnum::CONNECTED) {
      return null;
    }

    if (feof($this->socket)) {
      return null;
    }

    $data = stream_get_contents($this->socket);
    if (!$data) {
      return null;
    }

    return $this->frameReader->read($data);
  }

  public function read(): ?WSFrame {
    return $this->asyncRead()->getWaitHandle()->join();
  }

  public function readNonBlocking(): ?WSFrame {
    return $this->asyncReadNonBlocking()->getWaitHandle()->join();
  }

  public async function asyncWrite(
    WSFrame $frame,
    bool $automask = true,
  ): Awaitable<bool> {
    return await $this->asyncWriteImpl($frame, $automask);
  }

  protected async function asyncWriteImpl(
    WSFrame $frame,
    bool $automask,
    bool $check_state = true,
  ): Awaitable<bool> {
    if ($check_state && $this->state !== WSSocketStateEnum::CONNECTED) {
      return false;
    }

    if (feof($this->socket)) {
      return false;
    }
    $raw = $frame->encode($automask);
    // WARNING: There ever is a truly async fwrite, make sure
    // data doesn't get interlaced
    while ($raw) {
      $len = fwrite($this->socket, $raw);
      $raw = substr($raw, $len);
    }

    return true;
  }

  public function write(WSFrame $frame): bool {
    return $this->asyncWrite($frame)->getWaitHandle()->join();
  }

  private function getRawSocket(): resource {
    if ($this->socket === null) {
      throw new WSSocketException('Socket is null');
    }

    return $this->socket;
  }
}
