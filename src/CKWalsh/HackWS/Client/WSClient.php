<?hh // strict

namespace CKWalsh\HackWS\Client;

use \Awaitable;

interface WSClient {
  public function asyncConnect(): Awaitable<void>;
  public function connect(): void;
  public function asyncClose(): Awaitable<void>;
  public function close(): void;
  public function isConnected(): bool;
  public function asyncRead(): Awaitable<?string>;
  public function read(): ?string;
  public function asyncReadNonBlocking(): Awaitable<?string>;
  public function readNonBlocking(): ?string;
  public function asyncWrite(
    string $message,
    bool $binary = true,
  ): Awaitable<void>;
  public function write(string $message): void;
  public function asyncProcess(): Awaitable<void>;
  public function process(): void;
}
