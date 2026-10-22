<?php

namespace Agoodley\LibrenmsAnsible\Changes;

/**
 * The topology plugin cannot plan or compute a blast radius now; the message says why, for the change's page, timeline
 * and eventlog (no internals: those go to LibreNMS' log).
 */
final class TopologyError extends \RuntimeException
{
}
