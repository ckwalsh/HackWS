<?hh

namespace CKWalsh\HackWS\Protocol\Frames;

use \PHPUnit_Framework_TestCase;

class WSFrameTest extends PHPUnit_Framework_TestCase {
	public function testEncode(): void {
		$frame = new WSFrame();
		$frame->payload = 'Hello';
		$this->assertEquals(
			"\x81\x05\x48\x65\x6c\x6c\x6f",
			$frame->encode(false),
		);

		$this->assertEquals(
			"\x81\x05\x48\x65\x6c\x6c\x6f",
			$frame->encode(false),
		);

		$frame->maskingKey = "\x37\xfa\x21\x3d";
		$this->assertEquals(
			"\x81\x85\x37\xfa\x21\x3d\x7f\x9f\x4d\x51\x58",
			$frame->encode(false),
		);
	}
}
