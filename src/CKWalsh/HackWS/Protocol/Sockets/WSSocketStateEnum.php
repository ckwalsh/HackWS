<?hh // strict

namespace CKWalsh\HackWS\Protocol\Sockets;

newtype WSSocketState = int;

class WSSocketStateEnum {
  const WSSocketState DISCONNECTED = 0;
  const WSSocketState CONNECTING = 1;
  const WSSocketState HANDSHAKE = 2;
  const WSSocketState CONNECTED = 3;
  const WSSocketState DISCONNECTING = 4;
  const WSSocketState DISCONNECTING_WAIT = 5;
}
