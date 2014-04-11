<?hh // strict

namespace CKWalsh\HackWS\Protocol\Handshakes;

use \Awaitable;

interface WSHandshake {
  public function asyncDoHandshake(
    resource $socket,
    string $uri,
  ): Awaitable<string>;
  public function doHandshake(resource $socket, string $uri): string;
}
