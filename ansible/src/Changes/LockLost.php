<?php

namespace Agoodley\LibrenmsAnsible\Changes;

/**
 * The request that works on a change no longer holds the change's lock (it expired and another request took it, or
 * it was released by force): the step stops there, before its next call to the runner, Oxidized or LibreNMS'
 * maintenance, and the request that holds the lock now goes on from what was recorded. Not a RuntimeException, so no
 * handler of a runner's or Oxidized's errors takes it for one.
 */
final class LockLost extends \Exception
{
}
