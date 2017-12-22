<?php

namespace Doctrine\ODM\MongoDB\Tools;

/**
 * Class to generate document repository classes
 *
 * @since   1.0
 */
class DocumentRepositoryGenerator
{
    protected static $template =
'<?php

namespace <namespace>;

use Doctrine\ODM\MongoDB\DocumentRepository;

/**
 * <className>
 *
 * This class was generated by the Doctrine ODM. Add your own custom
 * repository methods below.
 */
class <className> extends DocumentRepository
{
}
';

    public function generateDocumentRepositoryClass($fullClassName)
    {
        $namespace = substr($fullClassName, 0, strrpos($fullClassName, '\\'));
        $className = substr($fullClassName, strrpos($fullClassName, '\\') + 1, strlen($fullClassName));

        $variables = array(
            '<namespace>' => $namespace,
            '<className>' => $className
        );
        return str_replace(array_keys($variables), array_values($variables), self::$template);
    }

    public function writeDocumentRepositoryClass($fullClassName, $outputDirectory, $outputDirectoryNamespace = null)
    {
        $code = $this->generateDocumentRepositoryClass($fullClassName);
        
        if (null === $outputDirectoryNamespace) {
            $relativeClassName = $fullClassName;
        } else {
            $relativeClassName = preg_replace(
                '/^'.str_replace('\\', '\\\\', $outputDirectoryNamespace).'\\\\/', '', $fullClassName
            );
        }
        
        $path = $outputDirectory . DIRECTORY_SEPARATOR
              . str_replace('\\', \DIRECTORY_SEPARATOR, $relativeClassName) . '.php';
        $dir = dirname($path);

        if ( ! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if ( ! file_exists($path)) {
            file_put_contents($path, $code);
            chmod($path, 0664);
        }
    }
}
