<?php

declare(strict_types=1);

namespace Box\Mod\Whmcstofossbilling\Security;

/** Only deliberately written, parameter-free messages may cross the API boundary. */
final class SafeException extends \RuntimeException
{
}
