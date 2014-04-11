<?hh

namespace CKWalsh\HackWS\Protocol\Frames;

require_once(__DIR__.'/../../../../../vendor/autoload.php');

use \PHPUnit_Framework_TestCase;

class WSFrameReaderTest extends PHPUnit_Framework_TestCase {
	public function testDecode(): void {
		$fr = new WSFrameReader();
		
		$frame = new WSFrame();
		$frame->payload = 'Hello';
		$encoded = $frame->encode(false);
		$f2 = $fr->read($encoded);
		$this->assertEquals(
			$frame,
			$f2,
		);

		$fr->reset();
		$encoded = $frame->encode(false);
		$f2 = $fr->read($encoded);
		invariant($f2 !== null, '');
		$f2->maskingKey = null;
		$this->assertEquals(
			$frame,
			$f2,
		);
	}

	public function testReadPartial(): void {
		$fr = new WSFrameReader();

		$frame = new WSFrame();
		$frame->payload = 'Hello';
		$encoded = $frame->encode(false);

		for ($i = 0; $i < strlen($encoded) - 1; $i++) {
			$this->assertNull(
				$fr->read($encoded[$i]),
			);
		}

		$f2 = $fr->read($encoded[strlen($encoded) - 1]);
		$this->assertNotNull($f2);
		$this->assertEquals(
			$frame,
			$f2,
		);
	}
}
