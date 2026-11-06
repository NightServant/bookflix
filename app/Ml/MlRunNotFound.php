<?php

namespace App\Ml;

/**
 * The service answered 404 for a run: it was restarted or its history was cleared, so asking again will never help.
 */
class MlRunNotFound extends MlServiceUnavailable {}
