<?hh // strict

namespace CKWalsh\HackWS\Protocol\Handshakes;

use \Awaitable;
use \SleepWaitHandle;

class RFC6455WSHandshake implements WSHandshake {
  const int SLEEP_USECONDS = 50;

  public function __construct() {
  }

  public async function asyncDoHandshake(
    resource $socket,
    string $uri,
  ): Awaitable<string> {
    $uri_parts = parse_url($uri);
    if ($uri_parts === false) {
      throw new WSHandshakeException('Malformed uri: '.$uri);
    }
    $key = (string) base64_encode(openssl_random_pseudo_bytes(20));

    $request_lines = Vector {};
    $request_lines[] = 'GET '.(string)$uri_parts['path'].' HTTP/1.1';
    $request_lines[] = 'Host: '.(string)$uri_parts['host'];
    $request_lines[] = 'Upgrade: websocket';
    $request_lines[] = 'Connection: Upgrade';
    $request_lines[] = 'Sec-WebSocket-Key: '.$key;
    $request_lines[] = 'Sec-WebSocket-Version: 13';
    $request_lines[] = '';
    $request_lines[] = '';

    $request = implode("\r\n", $request_lines->toArray());

    while ($request) {
      $len = fwrite($socket, $request);
      if ($len === false) {
        throw new WSHandshakeException('Unable to write to socket');
      }
      $request = substr($request, (int) $len);
    }

    $response = '';
    $extra_data = '';
    while (true) {
      $chunk = fread($socket, 4096);
      if ($chunk === false) {
        throw new WSHandshakeException('Unable to read from socket');
      }
      if ($chunk !== '') {
        $offset = max(0, strlen($response) - 3);
        $response .= (string) $chunk;
        if (strpos($response, "\r\n\r\n", $offset) !== false) {
          break;
        }
      }

      await SleepWaitHandle::create(self::SLEEP_USECONDS);
    }
    list($response, $extra_data) = explode("\r\n\r\n", $response, 2);

    $lines = explode("\r\n", $response);

    $status = $lines[0];
    $status_parts = preg_split('/\s+/', $status);
    if ((int) $status_parts[1] !== 101) {
      throw new WSHandshakeException('Response is not Switching Protocols');
    }

    $headers = Map {};

    $lc = count($lines);
    for ($i = 1; $i < $lc; $i++) {
      $line = $lines[$i];
      $parts = explode(':', $line, 2);
      $name = strtolower($parts[0]);
      $value = trim($parts[1]);
      $headers[strtolower($parts[0])] = trim($parts[1]);
    }

    if (!$headers->containsKey('connection')) {
      throw new WSHandshakeException('No Connection header sent');
    }

    if (strtolower($headers->at('connection')) !== 'upgrade') {
      throw new WSHandshakeException('Connection is not being upgraded');
    }

    if (!$headers->containsKey('upgrade')) {
      throw new WSHandshakeException('No Upgrade header sent');
    }

    if (strtolower($headers->at('upgrade')) !== 'websocket') {
      throw new WSHandshakeException('Response not upgrading to Websockets');
    }

    if (!$headers->containsKey('sec-websocket-accept')) {
      throw new WSHandshakeException('No Sec-WebSocket-Accept header sent');
    }

    $accept = (string) base64_encode(
      sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true),
    );

    if ($headers->at('sec-websocket-accept') !== $accept) {
      throw new WSHandshakeException('Security key response is not ' . $accept);
    }

    return $extra_data;
  }

  public function doHandshake(resource $socket, string $uri): string {
    return $this->asyncDoHandshake($socket, $uri)->getWaitHandle()->join();
  }
}
