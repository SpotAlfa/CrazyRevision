<?php

class CrazyException extends Exception
{
}

trait CrazyRevision
{
}

class Import
{
    public $accessModifier;
    public $methodName;
    public $conditions;
    public $callbacks;

    public function __construct(string $accessModifier, string $methodName, ?string $conditions, ?string $callbacks)
    {
        $this->accessModifier = $accessModifier;
        $this->methodName = $methodName;
        $this->conditions = $conditions;
        $this->callbacks = $callbacks;
    }
}

function replaceRangeConditions(array $matches): string
{
    $subject = array_shift($matches);
    $subject = trim($subject, '[]');
    $bounds = explode('..', $subject);

    if (ltrim($subject, '.') != $subject) {
        $condition = sprintf('$var <= %d', array_shift($bounds));
    } elseif (rtrim($subject, '.' != $subject)) {
        $condition = sprintf('$var >= %d', array_shift($bounds));
    } else {
        list($bottom, $top) = $bounds;
        $condition = sprintf('($var >= %d && $var <= %d)', $bottom, $top);
    }

    return $condition;
}

function replaceInjectedConditions(array $matches): string {
    $subject = array_shift($matches);
    $subject = trim($subject, '`');
    $subject = sprintf('(%s)', $subject);

    return $subject;
}

const DEF_PATTERN = '/^\s*(public|protected|private)\s*(static)?\s*\$[a-z][a-z\d]*(\s*,\s*\$[a-z][a-z\d]*)*.*?\s*\/\/>.+?$/i';
const PROP_PATTERN = '/(?<=\$)[a-z][a-z\d]*/i';
const COMMENT_PATTERN = '/(?<=\/\/>).+?$/';

const TYPE_DEF_PATTERN = '/^([a-z][a-z\d]*(<(?1)>)?(\[\])*)/i';
const ACC_MOD_PATTERN = '/^(\+|#|-)/';
const IMPORT_PATTERN = '/^[a-z][a-z\d]*/i';
const COND_PATTERN = '/^((\()?(\[(\d+(\.\d+)?)?\.\.(?(4)(\d+(\.\d+)?)?|(\d+(\.\d+)?))\]|`([^`]|(?<=\\\\`))+`)(\s*(&&|\|\|)\s*(?1))*(?(2)\)))/';
const CALLBACKS_PATTERN = '/(?<=^->)\s*([a-z][a-z\d]*|`([^`]|(?<=\\\\`))+`)(\s+&&\s+([a-z][a-z\d]*|`([^`]|(?<=\\\\`))+`))*/i';
const ALIAS_PATTERN = '/(?<=^=>)\s+[a-z][a-z\d]*/';
const USR_COMMENT_PATTERN = '/^--.+/';

const RANGE_COND_PATTERN = '/\[(\d+(\.\d+)?)?\.\.(?(1)(\d+(\.\d+)?)?|(\d+(\.\d+)?))\]/';
const INJ_CODE_PATTERN = '/`([^`]|(?<=\\\\`))+`/';

const COMMENT_START = '//>';
const CALLBACKS_SIGN = '->';
const ALIAS_SIGN = '=>';
const CONJUNCTION = '&&';

const GETTER_TEMPLATE = 'function (): %s { $var = %s; %s; }';
const SETTER_TEMPLATE = 'function (%s $var): void { %s; }';
const GENERAL_TEMPLATE = 'function (...$args) { %s; }';

const COND_CLAUSE = 'if (%s) { %s; %s; } else { throw new CrazyException("Conditions for %s::%s() did not pass"); }';
const NON_COND_CLAUSE = '{ %s; %s; }';

const PREDEFINED_CLASSES = 135;

$classes = get_declared_classes();
$userDefinedClasses = array_slice($classes, PREDEFINED_CLASSES);

foreach ($userDefinedClasses as $className) {
    /** @noinspection PhpUnhandledExceptionInspection */
    $class = new ReflectionClass($className);
    $traits = $class->getTraitNames();
    if (!in_array(CrazyRevision::class, $traits)) {
        continue;
    }

    $filename = $class->getFileName();
    $file = new SplFileObject($filename);

    $startLine = $class->getStartLine();
    $endLine = $class->getEndLine();

    foreach ($file as $lineNumber => $line) {
        $isValidLineNumber = ($lineNumber >= $startLine) && ($lineNumber <= $endLine);
        $isDefinition = (bool)preg_match(DEF_PATTERN, $line);
        if (!($isValidLineNumber && $isDefinition)) {
            continue;
        }

        $commentDelimiter = strpos($line, COMMENT_START);
        $propertiesDefinition = substr($line, 0, $commentDelimiter);
        $commentDefinition = substr($line, $commentDelimiter);

        preg_match_all(PROP_PATTERN, $propertiesDefinition, $matches);
        $properties = array_shift($matches);

        preg_match(COMMENT_PATTERN, $commentDefinition, $matches);
        $comment = array_shift($matches);

        $comment = trim($comment);
        preg_match(TYPE_DEF_PATTERN, $comment, $matches);
        if (!empty($matches)) {
            $typeDeclaration = array_shift($matches);
            $comment = substr($comment, strlen($typeDeclaration));
        } else {
            /** @noinspection PhpUnhandledExceptionInspection */
            throw new CrazyException(sprintf('Cannot find type declaration in %s on line %d', $filename, $lineNumber));
        }

        $realType = $typeDeclaration;
        $typeReference = $typeDeclaration;
        $primitiveTypes = ['int', 'float', 'double', 'bool', 'string', 'callable', 'object', 'resource', 'iterable'];
        if (in_array($typeDeclaration, $primitiveTypes)) {
            $typeReference = sprintf('Crazy%s', $typeReference);
        }

        $imports = [];
        while (true) {
            $comment = trim($comment);
            preg_match(ACC_MOD_PATTERN, $comment, $matches);
            if (!empty($matches)) {
                $accessModifier = array_shift($matches);
                $comment = substr($comment, strlen($accessModifier));
            } else {
                break;
            }

            $comment = trim($comment);
            preg_match(IMPORT_PATTERN, $comment, $matches);
            if (!empty($matches)) {
                $methodName = array_shift($matches);
                $comment = substr($comment, strlen($methodName));
            } else {
                /** @noinspection PhpUnhandledExceptionInspection */
                throw new CrazyException(sprintf('Cannot find method import in %s on line %d', $filename, $lineNumber));
            }

            $comment = trim($comment);
            preg_match(COND_PATTERN, $comment, $matches);
            if (!empty($matches)) {
                $conditions = array_shift($matches);
                $comment = substr($comment, strlen($conditions));

                $conditions = preg_replace_callback(RANGE_COND_PATTERN, 'replaceRangeConditions', $conditions);
                $conditions = preg_replace_callback(INJ_CODE_PATTERN, 'replaceInjectedConditions', $conditions);
            }

            $comment = trim($comment);
            preg_match(CALLBACKS_PATTERN, $comment, $matches);
            if (!empty($matches)) {
                $callbacks = array_shift($matches);
                $comment = substr($comment, strlen(CALLBACKS_SIGN) + strlen($callbacks));

                $callbacksElements = explode(CONJUNCTION, $callbacks);
                foreach ($callbacksElements as &$element) {
                    $element = trim($element);
                    $withoutQuotes = trim($element, '`');
                    if ($withoutQuotes != $element) {
                        $element = $withoutQuotes;
                    } else {
                        $element = sprintf('$var = %s::%s($var)', $typeReference, $element);
                    }
                }

                $callbacks = implode('; ', $callbacksElements);
            }

            $import = new Import($accessModifier, $methodName, @$conditions, @$callbacks);
            $imports[] = $import;
        }

        $comment = trim($comment);
        preg_match(ALIAS_PATTERN, $comment, $matches);
        if (!empty($matches)) {
            if (count($properties) > 1) {
                /** @noinspection PhpUnhandledExceptionInspection */
                throw new CrazyException(
                    sprintf('Cannot use alias for several properties in %s on line %d', $filename, $lineNumber)
                );
            }

            $alias = array_shift($matches);
            $comment = substr($comment, strlen(ALIAS_SIGN) + strlen($alias));

            $alias = trim($alias);
        }

        $comment = trim($comment);
        preg_match(USR_COMMENT_PATTERN, $comment, $matches);
        $userComment = array_shift($matches);
        $comment = substr($comment, strlen($userComment));

        $comment = trim($comment);
        if (strlen($comment) != 0) {
            /** @noinspection PhpUnhandledExceptionInspection */
            throw new CrazyException(sprintf('Unknown tokens in %s on line %d', $filename, $lineNumber));
        }

        if (strpos($line, 'static')) {
            $propertyReferenceTemplate = 'self::$%s';
        } else {
            $propertyReferenceTemplate = '$this->%s';
        }

        foreach ($properties as $property) {
            $propertyReference = sprintf($propertyReferenceTemplate, $property);
            foreach ($imports as $import) {
                $method = sprintf('%s%s', $import->methodName, ucfirst($alias ?? $property));

                switch ($import->methodName) {
                    case 'get':
                        $action = 'return $var';
                        break;
                    case 'set':
                        $action = sprintf('%s = $var', $propertyReference);
                        break;
                    default:
                        $action = sprintf(
                            'return %s::%s(%s, ...$args)', $typeReference, $import->methodName, $propertyReference
                        );
                }
                if (isset($conditions)) {
                    $code = sprintf(
                        COND_CLAUSE,
                        $import->conditions,
                        $import->callbacks,
                        $action,
                        $class->name,
                        $method
                    );
                } else {
                    $code = sprintf(NON_COND_CLAUSE, $import->callbacks, $action);
                }
                switch ($import->methodName) {
                    case 'get':
                        $function = sprintf(GETTER_TEMPLATE, $realType, $propertyReference, $code);
                        break;
                    case 'set':
                        $function = sprintf(SETTER_TEMPLATE, $realType, $code);
                        break;
                    default:
                        $function = sprintf(GENERAL_TEMPLATE, $code);
                }

                $closure = eval(sprintf('return %s;', $function));
                $flags = 0;
                if (strpos($propertiesDefinition, 'static')) {
                    $flags |= ZEND_ACC_STATIC;
                }
                switch ($import->accessModifier) {
                    case '+':
                        $flags |= ZEND_ACC_PUBLIC;
                        break;
                    case '#':
                        $flags |= ZEND_ACC_PROTECTED;
                        break;
                    case '-':
                        $flags |= ZEND_ACC_PRIVATE;
                        break;
                }

                uopz_add_function($class->name, $method, $closure, $flags, true);
            }
        }
    }
}
