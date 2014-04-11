<?hh // strict

namespace CKWalsh\HackWS\Client;

use \CKWalsh\HackWS\Protocol\Frames\WSFrame;
use \CKWalsh\HackWS\Protocol\Frames\WSFrameOpcodeEnum;
use \CKWalsh\HackWS\Protocol\Handshakes\RFC6455WSHandshake;
use \CKWalsh\HackWS\Protocol\Sockets\WSSocket;

use \Awaitable;
use \Exception;
use \SplQueue;

class RFC6455WSClient extends BaseWSClient {
  private WSSocket $socket;
  private Vector<WSFrame> $frames;
  private SplQueue<string> $messages;
  private bool $processing = false;

  public function __construct(string $uri) {
    $this->socket = new WSSocket($uri, new RFC6455WSHandshake());
    $this->frames = Vector {};
    $this->messages = new SplQueue();
  }

  public async function asyncConnect(): Awaitable<void> {
    await $this->socket->asyncConnect();
  }

  public function isConnected(): bool {
    return $this->socket->isConnected();
  }

  public async function asyncClose(): Awaitable<void> {
    await $this->socket->asyncClose();
  }

  public async function asyncRead(): Awaitable<?string> {
    $message = null;
    while ($this->socket->isConnected() && $message === null) {
      $message = await $this->asyncReadNonBlocking();
    }

    return $message;
  }

  public async function asyncReadNonBlocking(): Awaitable<?string> {
    await $this->asyncProcess();

    if ($this->messages->isEmpty()) {
      return null;
    } else {
      return $this->messages->dequeue();
    }
  }

  public async function asyncWrite(
    string $message,
    bool $binary = true
  ): Awaitable<void> {
    $frame = new WSFrame();
    $frame->opcode = $binary
      ? WSFrameOpcodeEnum::DATA_BINARY
      : WSFrameOpcodeEnum::DATA_TEXT;
    $frame->payload = $message;
    await $this->socket->asyncWrite($frame);
  }

  public async function asyncProcess(): Awaitable<void> {
    if ($this->processing) {
      return;
    }
    $this->processing = true;
    while ($this->socket->isConnected()) {
      $frame = await $this->socket->asyncReadNonBlocking();
      if ($frame === null) {
        break;
      }

      if (WSFrameOpcodeEnum::isData($frame->opcode)) {
        if ($frame->opcode === WSFrameOpcodeEnum::DATA_CONT) {
          if ($this->frames->isEmpty()) {
            await $this->socket->asyncCloseImmediately();
            $this->processing = false;
            throw new WSClientException(
              'Received Continuation frame with no previous frames'
            );
          }
        } else if ($frame->opcode === WSFrameOpcodeEnum::DATA_TEXT) {
          if (!mb_detect_encoding($frame->payload, 'UTF-8', true)) {
            await $this->socket->asyncCloseImmediately();
            $this->processing = false;
            throw new WSClientException('Payload for text frame is not UTF-8');
          }
        }

        $this->frames->add($frame);

        if ($frame->fin) {
          $message = '';
          foreach ($this->frames as $f) {
            $message .= $f->payload;
          }

          $this->messages->enqueue($message);
          $this->frames->clear();
        }
      } else {
        switch ($frame->opcode) {
          case WSFrameOpcodeEnum::CTRL_PING:
            $frame->opcode = WSFrameOpcodeEnum::CTRL_PONG;
            await $this->socket->asyncWrite($frame);
            break;
          case WSFrameOpcodeEnum::CTRL_PONG:
            // Do nothing
            break;
          case WSFrameOpcodeEnum::CTRL_CLOSE:
            await $this->socket->asyncCloseImmediately();
            break;
        }
      }
    }
    $this->processing = false;
  }
}
