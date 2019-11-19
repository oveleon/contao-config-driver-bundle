<?php

declare(strict_types=1);

/*
 * This file is part of Contao Config Driver Bundle.
 *
 * (c) https://www.oveleon.de/
 */

namespace Oveleon\ContaoConfigDriverBundle\ContaoManager;

use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Oveleon\ContaoConfigDriverBundle\ContaoConfigDriverBundle;

class Plugin implements BundlePluginInterface
{
    /**
     * {@inheritdoc}
     */
    public function getBundles(ParserInterface $parser): array
    {
        return [
            BundleConfig::create(ContaoConfigDriverBundle::class)
                ->setLoadAfter([ContaoCoreBundle::class])
                ->setReplace(['config-driver']),
        ];
    }
}
