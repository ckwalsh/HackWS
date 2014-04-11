<?hh // strict

namespace CKWalsh\HackWS\Client\JsonRpc;

use \Awaitable;
use \GenVectorWaitHandle;

newtype JsonRpcEventListenerId = int;

class JsonRpcEventManager {
  private int $idx = 0;
  private Map<JsonRpcEventListenerId, (function(string, Map<string, mixed>): Awaitable<void>)> $listeners = Map {};
  private Map<string, Set<JsonRpcEventListenerId>> $eventsToListeners = Map {};
  private Map<JsonRpcEventListenerId, Set<string>> $listenersToEvents = Map {};

  public async function asyncTriggerEvent(string $event, Map<string, mixed> $params): Awaitable<void> {
    if ($this->eventsToListeners->containsKey($event)) {
      $whs = Vector {};
      foreach ($this->eventsToListeners->at($event) as $id) {
        $cb = $this->listeners->at($id);
        $whs->add($cb($event, $params)->getWaitHandle());
      }

      await GenVectorWaitHandle::create($whs);
    }
  }

  public function addListener((function(string, Map<string, mixed>): Awaitable<void>) $callback): JsonRpcEventListenerId {
    $id = $this->idx++;

    $this->listeners[$id] = $callback;
    $this->listenersToEvents[$id] = Set {};

    return $id;
  }

  public function destroyListener(JsonRpcEventListenerId $id): this {
    invariant($this->listeners->containsKey($id), 'Listener ID %d is invalid', $id);

    $this->listeners->remove($id);
    foreach ($this->listenersToEvents[$id] as $ev) {
      $this->eventsToListeners->at($ev)->remove($id);
    }
    $this->listenersToEvents->remove($id);

    return $this;
  }

  public function subscribe(JsonRpcEventListenerId $id, string $event): this {
    $this->listenersToEvents->at($id)->add($event);
    if (!$this->eventsToListeners->containsKey($event)) {
      $this->eventsToListeners[$event] = Set {};
    }
    $this->eventsToListeners[$event]->add($id);

    return $this;
  }

  public function unsubscribe(JsonRpcEventListenerId $id, string $event): this {
    $this->listenersToEvents->at($id)->remove($event);
    if ($this->eventsToListeners->containsKey($event)) {
      $this->eventsToListeners->at($event)->remove($id);
      if ($this->eventsToListeners->at($event)->isEmpty()) {
        $this->eventsToListeners->remove($event);
      }
    }

    return $this;
  }
}
