<?php

namespace App\Reader;

use RuntimeException;

/**
 * A public-domain file Bookflix will not keep. The message is the short reason an admin sees on the source; it is never retried.
 */
class ContentFetchRefused extends RuntimeException {}
