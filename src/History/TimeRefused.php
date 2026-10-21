<?php

namespace Agoodley\LibrenmsTopology\History;

/**
 * A map at another time that the history cannot draw (switched off, its tables missing, a time before its
 * earliest snapshot, no baseline to compare with): the message tells the user why, and the API answers it
 * with HTTP 422 instead of today's map, which would pass for the past.
 */
class TimeRefused extends \RuntimeException
{
}
