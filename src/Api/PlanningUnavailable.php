<?php

namespace Agoodley\LibrenmsTopology\Api;

/**
 * The planning API cannot be used now: the topology plugin is disabled in LibreNMS, or LibreNMS cannot tell whether it
 * is enabled. The message says which, for the caller's users.
 */
class PlanningUnavailable extends \RuntimeException
{
}
