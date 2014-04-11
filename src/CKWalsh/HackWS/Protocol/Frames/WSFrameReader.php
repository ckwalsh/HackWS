<?hh // strict

namespace CKWalsh\HackWS\Protocol\Frames;

use \CKWalsh\HackWS\Protocol\WSProtocolException;

class WSFrameReader {
  private string $buffer = '';
  private int $bufferLength = 0;
  private int $pos = 0;
  private int $state = 0;
  private WSFrame $frame;

  private bool $frameIsMasked = false;
  private int $framePayloadLength = 0;
  public function __construct() {
    $this->frame = new WSFrame();
  }

  public function reset(string $data = ''): void {
    $this->buffer = $data;
    $this->bufferLength = strlen($data);
    $this->pos = 0;
    $this->state = 0;
  }

  public function appendData(string $data): void {
    $this->buffer .= $data;
    if ($this->pos >= 8192) {
      $this->buffer = substr($this->buffer, $this->pos);
      $this->pos = 0;
    }
    $this->bufferLength = strlen($this->buffer);
  }

  public function read(?string $data = null): ?WSFrame {
    if ($data !== null) {
      $this->appendData($data);
    }

    switch ($this->state) {
      case 0:
        $this->frame = new WSFrame();
        $this->state++;
        // FALLTHROUGH
      case 1:
        if ($this->bufferLength - $this->pos < 1) {
          return null;
        }
        $first_byte = ord(substr($this->buffer, $this->pos, 1));

        $this->frame->fin = (bool) ($first_byte & 0x80);
        $this->frame->rsv1 = (bool) ($first_byte & 0x40);
        $this->frame->rsv2 = (bool) ($first_byte & 0x20);
        $this->frame->rsv3 = (bool) ($first_byte & 0x10);
        $this->frame->opcode = WSFrameOpcodeEnum::castOrThrow(
          $first_byte & 0x0F,
        );

        $this->pos += 1;
        $this->state++;
        // FALLTHROUGH
      case 2:
        if ($this->bufferLength - $this->pos < 1) {
          return null;
        }

        $second_byte = ord(substr($this->buffer, $this->pos, 1));

        $this->frameIsMasked = (bool) ($second_byte & 0x80);
        $this->framePayloadLength = $second_byte & 0x7F;

        $this->pos += 1;
        $this->state++;
        // FALLTHROUGH
      case 3:
        if ($this->framePayloadLength === 127) {
          if (WSFrameOpcodeEnum::isCtrl($this->frame->opcode)) {
            throw new WSFrameException(
              'Payload length of control frames must be less than 125',
            );
          }
          if ($this->bufferLength - $this->pos < 8) {
            return null;
          }
          $arr1 = unpack('N', substr($this->buffer, $this->pos, 4));
          $arr2 = unpack('N', substr($this->buffer, $this->pos + 4, 4));
          $this->framePayloadLength = ((reset($arr1) & 0x7FFFFFFF) << 32);
          $this->framePayloadLength |= reset($arr2);

          $this->pos += 8;
        } else if ($this->framePayloadLength === 126) {
          if (WSFrameOpcodeEnum::isCtrl($this->frame->opcode)) {
            throw new WSFrameException(
              'Payload length of control frames must be less than 125',
            );
          }
          if ($this->bufferLength - $this->pos < 2) {
            return null;
          }
          $arr = unpack('n', substr($this->buffer, $this->pos, 2));
          $this->framePayloadLength = reset($arr);

          $this->pos += 2;
        }

        $this->state++;
        // FALLTHROUGH
      case 4:
        if ($this->frameIsMasked) {
          if ($this->bufferLength - $this->pos < 4) {
            return null;
          }

          $this->frame->maskingKey = substr($this->buffer, $this->pos, 4);

          $this->pos += 2;
        }

        $this->state++;
        // FALLTHROUGH
      case 5:
        if ($this->bufferLength - $this->pos < $this->framePayloadLength) {
          return null;
        }

        $this->frame->payload = substr(
          $this->buffer,
          $this->pos,
          $this->framePayloadLength,
        );
        if ($this->frameIsMasked) {
          $key = $this->frame->maskingKey;
          if ($key === null || strlen($key) !== 4) {
            throw new WSFrameException(
              'Something really bad happened, we set a mask and now it is null',
            );
          }

          for ($i = 0; $i < $this->framePayloadLength; $i++) {
            $this->frame->payload[$i] = chr(
              ord($this->frame->payload[$i]) & ord($key[$i % 4])
            );
          }
        }

        $this->pos += $this->framePayloadLength;

        // Finally looping back to the top of this state machine
        $this->state = 0;
        return $this->frame;
    }

    throw new WSFrameException('Unexpected state when reading frame');
  }
}
