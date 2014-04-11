<?hh // strict

namespace CKWalsh\HackWS\Client\JsonRpc;

use \JSON_FB_COLLECTIONS;
use \Awaitable;
use \Exception;
use \SleepWaitHandle;

use \CKWalsh\HackWS\Client\WSClient;

class JsonRpcWSClient {
  private bool $procRunning = false;
  private int $cmdIdx = 1;
  private Map<int, Pair<?Map<string, mixed>, ?Exception>> $results = Map {};
  private JsonRpcEventManager $em;
  private WSClient $client;

  public function __construct(WSClient $client) {
    $this->client = $client;
    $this->em = new JsonRpcEventManager();
  }

  public function isConnected(): bool {
    return $this->client->isConnected();
  }

  public function getEventManager(): JsonRpcEventManager {
    return $this->em;
  }

  public async function asyncCall(
    string $method,
    ?Map<string, mixed> $params = null,
  ): Awaitable<Map<string, mixed>> {
    if ($params === null) {
      $params = Map {};
    }

    $id = $this->cmdIdx++;

    $args = Map {
      'id' => $id,
      'method' => $method,
      'params' => $params,
    };

    await $this->client->asyncWrite(json_encode($args), false);

    while ($this->isConnected() && !$this->results->containsKey($id)) {
      await $this->asyncProcess();

      if (!$this->results->containsKey($id)) {
        await SleepWaitHandle::create(1);
      }
    }

    $result = $this->results->at($id);
    $this->results->remove($id);

    if ($result[1] !== null) {
      $ex = $result[1];
      invariant($ex !== null, "This shouldn't happen");
      throw $ex;
    }

    $res = $result[0];
    invariant($res !== null, "This shouldn't happen");

    return $res;

  }

  final public function call(
    string $method,
    ?Map<string, mixed> $params = null,
  ): Map<string, mixed> {
    return $this->asyncCall($method, $params)->getWaitHandle()->join();
  }

  public async function asyncProcess(): Awaitable<void> {
    if ($this->procRunning) {
      return;
    }
    $this->procRunning = true;
    while ($this->isConnected()) {
      $message = await $this->client->asyncReadNonBlocking();

      if ($message === null) {
        break;
      }

      $msg = json_decode($message, true, 512, JSON_FB_COLLECTIONS);

      invariant(
        $msg instanceof Map,
        'Expected a json map as message, got '.$message,
      );

      if ($msg->containsKey('id')) {
        $id = $msg->at('id');
        if ($msg->containsKey('error')) {
          $err_map = $msg->at('error');
          invariant(
            $err_map instanceof Map,
            'Error field of response is not a map',
          );
          $ex = new JsonRpcException($err_map);
          if ($id === null) {
            throw $ex;
          } else {
            $this->results[(int) $id] = Pair {null, $ex};
          }
        } else {
          $result_map = $msg->get('result');
          if ($result_map === null) {
            $result_map = Map {};
          }

          invariant(
            $result_map instanceof Map,
            'Result field of response is not a map',
          );
          $this->results[(int) $id] = Pair {$result_map, null};
        }
      } else {
        $method = (string) $msg->at('method');
        $param_map = $msg->get('params');
        if ($param_map === null) {
          $param_map = Map {};
        }
        invariant(
          $param_map instanceof Map,
          'Param field of response is not a map',
        );
        $this->procRunning = false;
        await $this->em->asyncTriggerEvent($method, $param_map);
        if ($this->procRunning) {
          return;
        }
        $this->procRunning = true;
      }
    }
    $this->procRunning = false;
  }

  final public function process(): void {
    $this->asyncProcess()->getWaitHandle()->join();
  }
}
