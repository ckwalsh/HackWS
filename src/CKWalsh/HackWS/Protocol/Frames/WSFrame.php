<?hh // strict

namespace CKWalsh\HackWS\Protocol\Frames;

class WSFrame {
  public bool $fin  = true;
  public bool $rsv1 = false;
  public bool $rsv2 = false;
  public bool $rsv3 = false;
  public WSFrameOpcode $opcode = WSFrameOpcodeEnum::DATA_TEXT;
  public ?string $maskingKey = null;
  public string $payload = '';

  public function encode(bool $automask = true): string {
    $masking_key = null;
    if ($automask) {
      $masking_key = openssl_random_pseudo_bytes(4);
    } else {
      $masking_key = $this->maskingKey;
    }

    $data = '';
    $first_byte = 0;
    if ($this->fin) {
      $first_byte |= 0x80;
    }
    if ($this->rsv1) {
      $first_byte |= 0x40;
    }
    if ($this->rsv2) {
      $first_byte |= 0x20;
    }
    if ($this->rsv3) {
      $first_byte |= 0x10;
    }

    $first_byte |= (int) $this->opcode;

    $data .= chr($first_byte);

    $second_byte = 0;
    if ($masking_key !== null) {
      $second_byte |= 0x80;
    }

    $len = strlen($this->payload);

    $len_mask = 0;

    if ($len < 126) {
      $len_mask = $len;
    } else if ($len < (1 << 16)) {
      $len_mask = 126;
    } else if ($len < (1 << 64)) {
      $len_mask = 127;
    }

    $second_byte |= $len_mask;

    $data .= chr($second_byte);

    switch ($len_mask) {
      case 127:
        $data .= (string) pack('N', $len >> 32);
        $data .= (string) pack('N', $len & 0xFFFFFFFF);
        break;
      case 126:
        $data .= (string) pack('n', $len);
        break;
    }

    $payload = $this->payload;

    if ($masking_key !== null) {
      for ($i = 0; $i < $len; $i++) {
        $payload[$i] = chr(ord($payload[$i]) ^ ord($masking_key[$i % 4]));
      }

      $data .= $masking_key;
    }

    return $data . $payload;
  }
}
