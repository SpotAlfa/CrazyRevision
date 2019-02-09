<?php

class CrazyException extends Exception
{
}

trait CrazyRevision
{
}

class TestCase
{
    use CrazyRevision;

    private $x = 0; //> int +get

    public function realGetX(): int
    {
        return $this->x;
    }
}

$definitionPattern = '/^\s*(public|protected|private)\s*(static)?\s*\$[a-z][a-z\d]*(\s*,\s*\$[a-z][a-z\d]*)*.*?\s*\/\/>.+?$/i';
$propertyPattern = '/(?<=\$)[a-z][a-z\d]*/i';
$commentPattern = '/(?<=\/\/>).+?$/';

$typeDeclarationPattern = '/^([a-z][a-z\d]*(<(?1)>)?(\[\])*)/i';
$accessModifierPattern = '/^(\+|#|-)/';
$methodImportPattern = '/^[a-z][a-z\d]*/i';
$conditionsPattern = '/^((\()?(\[(\d+(\.\d+)?)?\.\.(?(4)(\d+(\.\d+)?)?|(\d+(\.\d+)?))\]|`([^`]|(?<=\\\\`))+`)(\s*(&&|\|\|)\s*(?1))*(?(2)\)))/';
$callbacksPattern = '/(?<=^->)\s*([a-z][a-z\d]*|`([^`]|(?<=\\\\`))+`)(\s+&&\s+([a-z][a-z\d]*|`([^`]|(?<=\\\\`))+`))*/i';
$aliasPattern = '/(?<=^=>)\s+[a-z][a-z\d]*/';
$userCommentPattern = '/^--.+/';

$rangeConditionPattern = '/\[(\d+(\.\d+)?)?\.\.(?(1)(\d+(\.\d+)?)?|(\d+(\.\d+)?))\]/';
$injectedStringPattern = '/`([^`]|(?<=\\\\`))+`/';
$conjunctionPattern = '/\s*&&\s*/';

$getterTpl = <<<'PHP'
function (): %s {
    $var = %s;
    %s
}


PHP;
$setterTpl = <<<'PHP'
function (%s $var): void {
    %s
}


PHP;
$fnTpl = <<<'PHP'
function (...$args) {
    %s
}

PHP;

$conditional = <<<'PHP'
if (%s) {
    %s;
    %s;
} else {
    throw new CrazyException('Conditions for %s::%s() did not pass');
}
PHP;
$nonConditional = <<<'PHP'
%s;
%s;
PHP;


$classes = get_declared_classes();
$predefinedClassesCount = 135;
$userDefinedClasses = array_slice($classes, $predefinedClassesCount);

foreach ($userDefinedClasses as $className) {
    /** @noinspection PhpUnhandledExceptionInspection */
    $class = new ReflectionClass($className);
    if (!in_array(CrazyRevision::class, $class->getTraitNames())) {
        continue;
    }

    $file = new SplFileObject($class->getFileName());
    foreach ($file as $lineNumber => $line) {
        $isValidLineNumber = $lineNumber >= $class->getStartLine() && $lineNumber <= $class->getEndLine();
        $isDefinition = preg_match($definitionPattern, $line);
        if (!($isValidLineNumber && $isDefinition)) {
            continue;
        }

        $properties = [];
        preg_match_all($propertyPattern, substr($line, 0, strpos($line, '//>')), $properties);
        $properties = array_shift($properties);

        $matches = [];
        preg_match($commentPattern, $line, $matches);
        $comment = array_shift($matches);

        $comment = trim($comment);
        $success = preg_match($typeDeclarationPattern, $comment, $matches);
        if (!$success) {
            /** @noinspection PhpUnhandledExceptionInspection */
            throw new CrazyException(
                sprintf('Cannot find type declaration in %s on line %d', $file->getFilename(), $lineNumber)
            );
        }
        $typeDeclaration = array_shift($matches);
        $comment = substr($comment, strlen($typeDeclaration));

        $type = $typeDeclaration;
        switch ($typeDeclaration) {
            case 'int':
            case 'float':
            case 'double':
            case 'bool':
            case 'string':
            case 'callable':
            case 'object':
            case 'resource':
                $typeDeclaration = sprintf('Crazy%s', ucfirst($typeDeclaration));
        }

        $imports = [];
        while (true) {
            $comment = ltrim($comment);
            $success = preg_match($accessModifierPattern, $comment, $matches);
            if (!$success) {
                break;
            }
            $accessModifier = array_shift($matches);
            $comment = substr($comment, strlen($accessModifier));

            $success = preg_match($methodImportPattern, $comment, $matches);
            if (!$success) {
                /** @noinspection PhpUnhandledExceptionInspection */
                throw new CrazyException(
                    sprintf('Cannot find method import in %s on line %d', $file->getFilename(), $lineNumber)
                );
            }
            $methodImport = array_shift($matches);
            $comment = substr($comment, strlen($methodImport));

            $comment = ltrim($comment);
            preg_match($conditionsPattern, $comment, $matches);
            $conditions = array_shift($matches);
            $comment = substr($comment, strlen($conditions ?? ''));

            $conditions = preg_replace_callback(
                $rangeConditionPattern,
                function (array $matches): string {
                    $subject = array_shift($matches);
                    $subject = trim($subject, '[]');
                    $bounds = explode('..', $subject);

                    if ($subject[0] === '.') {
                        return sprintf('$var <= %d', array_shift($bounds));
                    } elseif ($subject[-1] === '.') {
                        return sprintf('$var >= %d', array_shift($bounds));
                    } else {
                        list($bottom, $top) = $bounds;
                        return sprintf('($var >= %d && $var <= %d)', $bottom, $top);
                    }
                },
                $conditions
            );
            $conditions = preg_replace_callback(
                $injectedStringPattern,
                function (array $matches): string {
                    $subject = array_shift($matches);
                    return sprintf('(%s)', trim($subject, '`'));
                },
                $conditions
            );

            if ($conditions === '') {
                $conditions = 'true';
            }

            $comment = ltrim($comment);
            preg_match($callbacksPattern, $comment, $matches);
            $callbacks = array_shift($matches);
            $comment = substr($comment, $callbacks !== null ? strlen($callbacks) + 2 : 0);

            if ($callbacks !== null) {
                $callbacks = preg_split($conjunctionPattern, $callbacks);
                array_walk(
                    $callbacks,
                    function (string &$element) use ($typeDeclaration): void {
                        if (strpos($element, '`') !== false) {
                            $element = trim($element, '` ');
                        } else {
                            $element = trim($element);
                            $element = sprintf('$var = %s::%s($var)', $typeDeclaration, $element);
                        }
                    }
                );
                $callbacks = implode(';', $callbacks);
            } else {
                $callbacks = '';
            }

            $imports[] = [$accessModifier, $methodImport, $conditions, $callbacks];
        }

        $comment = ltrim($comment);
        preg_match($aliasPattern, $comment, $matches);
        $alias = array_shift($matches);
        $comment = substr($comment, $alias !== null ? strlen($alias) + 2 : 0);
        if ($alias !== null && count($properties) !== 1) {
            /** @noinspection PhpUnhandledExceptionInspection */
            throw new CrazyException(
                sprintf('Cannot use alias for multiple properties in %s on line %d', $file->getFilename(), $lineNumber)
            );
        }
        if ($alias !== null) {
            $properties[0] = trim($alias);
        }

        $comment = ltrim($comment);
        preg_match($userCommentPattern, $comment, $matches);
        $userComment = array_shift($matches);
        $comment = substr($comment, strlen($userComment ?? ''));

        $comment = ltrim($comment);
        if (strlen($comment) !== 0) {
            /** @noinspection PhpUnhandledExceptionInspection */
            throw new CrazyException(
                sprintf('Unknown tokens in %s on line %d', $file->getFilename(), $lineNumber)
            );
        }

        if (strpos($line, 'static') !== false) {
            $fieldReferenceTpl = 'self::$%s';
        } else {
            $fieldReferenceTpl = '$this->%s';
        }

        foreach ($properties as $property) {
            foreach ($imports as $import) {
                list($accessModifier, $methodImport, $conditions, $callbacks) = $import;
                $fieldReference = sprintf($fieldReferenceTpl, $property);
                $methodName = $methodImport . ucfirst($property);

                if ($methodImport === 'get') {
                    $action = 'return $var';
                } elseif ($methodImport === 'set') {
                    $action = sprintf('%s = $var', $fieldReference);
                } else {
                    $action = sprintf('return %s::%s(%s, ...$args)', $typeDeclaration, $methodImport, $fieldReference);
                }

                if ($conditions === 'true') {
                    $code = sprintf($nonConditional, $callbacks, $action);
                } else {
                    $code = sprintf($conditional, $conditions, $callbacks, $action, $class->name, $methodName);
                }

                switch ($methodImport) {
                    case 'get':
                        $function = sprintf($getterTpl, $type, $fieldReference, $code);
                        break;
                    case 'set':
                        $function = sprintf($setterTpl, $type, $code);
                        break;
                    default:
                        $function = sprintf($fnTpl, $code);
                }

//                echo $function;

                $flags = 0;
                if (strpos($line, 'static') !== false) {
                    $flags |= ZEND_ACC_STATIC;
                }
                switch ($accessModifier) {
                    case '+':
                        $flags |= ZEND_ACC_PUBLIC;
                        break;
                    case '#':
                        $flags |= ZEND_ACC_PROTECTED;
                        break;
                    case '-':
                        $flags |= ZEND_ACC_PRIVATE;
                }

                $closure = eval(sprintf('return %s;', $function));
                uopz_add_function($class->name, $methodName, $closure, $flags, true);
            }
        }
    }
}


$test = new TestCase();

$time = microtime(true);
for ($i = 0; $i < 1e3; $i++) {
    $test->realGetX();
}
echo (microtime(true) - $time) . PHP_EOL;

$time = microtime(true);
for ($i = 0; $i < 1e3; $i++) {
    $test->getX();
}
echo (microtime(true) - $time) . PHP_EOL;
