<?hh // strict

namespace CKWalsh\HackWS\Client;

abstract class BaseWSClient implements WSClient {
  final public function connect(): void {
    $this->asyncConnect()->getWaitHandle()->join();
  }

  final public function close(): void {
    $this->asyncClose()->getWaitHandle()->join();
  }

  final public function read(): ?string {
    return $this->asyncRead()->getWaitHandle()->join();
  }

  final public function readNonBlocking(): ?string {
    return $this->asyncReadNonBlocking()->getWaitHandle()->join();
  }

  final public function write(string $message, bool $binary = true): void {
    $this->asyncWrite($message, $binary)->getWaitHandle()->join();
  }

  final public function process(): void {
    $this->asyncProcess()->getWaitHandle()->join();
  }
}
