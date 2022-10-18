<?php

declare(strict_types=1);

/*
 * This file is part of Contao Config Driver Bundle.
 *
 * @package     contao-config-driver-bundle
 * @license     MIT
 * @author      Daniele Sciannimanica  <https://github.com/doishub>
 * @copyright   Oveleon                <https://www.oveleon.de/>
 */

namespace Oveleon\ContaoConfigDriverBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class ContaoConfigDriverBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
