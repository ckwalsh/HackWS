<?hh

// This communicates with an instance of the Google Chrome web browser over the
// Chrome Remote Debugging Protocol.
//
// This assumes a browser is running on localhost (or tunneled to localhost)
// with the remote debugging port set to 20000
//
// google-chrome --remote-debugging-port=20000
//
//
// https://developers.google.com/chrome-developer-tools/docs/debugger-protocol

require_once(__DIR__.'/../vendor/autoload.php');

class ChromeDebuggerTest {
  private Vector<Map<string, mixed>> $navHistory = Vector {};

  public async function run(): Awaitable<void> {
    // Get the json from the chrome debugger that lists which
    // windows/tabs are open
    $json = file_get_contents('http://localhost:20000/json');
    $entries = json_decode($json, true);

    $uri = $entries[0]['webSocketDebuggerUrl'];

    echo "Connecting to $uri\n";

    // Construct the Websocket Client
    $ws = new CKWalsh\HackWS\Client\RFC6455WSClient($uri);

    // Construct the JSON RPC Client
    $json = new CKWalsh\HackWS\Client\JsonRpc\JsonRpcWSClient($ws);

    // Subscribe to the Page.frameNavigated events. Chrome will push us
    // notifications whenever the given frame is navigated and our callback
    // will be called.
    $em = $json->getEventManager();
    $id = $em->addListener(inst_meth($this, 'navHistoryListener'));
    $em->subscribe($id, 'Page.frameNavigated');

    // Connect the websocket client to the browser
    await $ws->asyncConnect();

    // These are executed in parallel and return when both are complete
    $res = await GenVectorWaitHandle::create(Vector {
      $json->asyncCall('Page.enable')->getWaitHandle(),
      $json->asyncCall('Page.getNavigationHistory')->getWaitHandle(),
    });

    var_dump($res);

    // Lets loop for up to 60 seconds processing the navigation events
    for ($start = time(); time() - $start < 60;) {
      // This is a synchronous version of $json->asyncProcess()
      $json->process();
      usleep(1000);
    }

    // Close the connection
    await $ws->asyncClose();
  }

  public async function navHistoryListener(
    string $event,
    Map<string, mixed> $params,
  ): Awaitable<void> {
    var_dump(Pair {$event, $params});
  }
}

$test = new ChromeDebuggerTest();

$test->run()->getWaitHandle()->join();
