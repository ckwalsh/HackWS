<?hh // strict

namespace CKWalsh\HackWS\Protocol\Frames;

use \CKWalsh\HackWS\Protocol\WSProtocolException;

newtype WSFrameOpcode = int;

final class WSFrameOpcodeEnum {
  const WSFrameOpcode DATA_CONT   = 0x0;
  const WSFrameOpcode DATA_TEXT   = 0x1;
  const WSFrameOpcode DATA_BINARY = 0x2;

  const WSFrameOpcode CTRL_CLOSE  = 0x8;
  const WSFrameOpcode CTRL_PING   = 0x9;
  const WSFrameOpcode CTRL_PONG   = 0xA;

  private static ?ImmSet<WSFrameOpcode> $opcodes;

  public static function getValidOpcodes(): ImmSet<WSFrameOpcode> {
    if (self::$opcodes === null) {
      self::$opcodes = ImmSet {
        self::DATA_CONT,
        self::DATA_TEXT,
        self::DATA_BINARY,

        self::CTRL_CLOSE,
        self::CTRL_PING,
        self::CTRL_PONG,
      };
    }

    return self::$opcodes;
  }

  public static function castOrThrow(int $opcode): WSFrameOpcode {
    $opcodeSet = self::getValidOpcodes();
    if ($opcodeSet->contains($opcode)) {
      return $opcode;
    }

    throw new WSFrameException('Unrecognized opcode '.$opcode);
  }

  public static function isData(WSFrameOpcode $opcode): bool {
    return !((bool) ($opcode & 0x8));
  }

  public static function isCtrl(WSFrameOpcode $opcode): bool {
    return (bool) ($opcode & 0x8);
  }
}
