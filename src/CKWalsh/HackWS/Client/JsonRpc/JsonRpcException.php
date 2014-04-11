<?hh // strict

namespace CKWalsh\HackWS\Client\JsonRpc;

use \Exception;

class JsonRpcException extends Exception {
  public function __construct(Map<string, mixed> $err_data) {
    parent::__construct(
      (string) $err_data->get('message'),
      (int) $err_data->get('code'),
    );
  }
}
