<?php

namespace Doctrine\ODM\MongoDB\Mapping\Driver;

use Doctrine\Common\Persistence\Mapping\Driver\SymfonyFileLocator;

/**
 * XmlDriver that additionally looks for mapping information in a global file.
 *
 * @license MIT
 */
class SimplifiedXmlDriver extends XmlDriver
{
    const DEFAULT_FILE_EXTENSION = '.mongodb-odm.xml';

    /**
     * {@inheritDoc}
     */
    public function __construct($prefixes, $fileExtension = self::DEFAULT_FILE_EXTENSION)
    {
        $locator = new SymfonyFileLocator((array) $prefixes, $fileExtension);
        parent::__construct($locator, $fileExtension);
    }
}
