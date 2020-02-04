<?php
namespace Bobby\Websocket;

final class OpcodeEnum
{
    const CONTINUED = 0x0;
    const TEXT = 0x1;
    const BINARY= 0x2;
    const OUT_CONNECT = 0x8;
    const PING = 0x9;
    const PONG = 0xA;
}