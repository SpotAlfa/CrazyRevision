<?php

trait CrazyRevision
{
}

class CrazyException extends Exception
{
}

$classes = get_declared_classes();
$predefinedClasses = 135;
$userDefinedClasses = array_slice($classes, $predefinedClasses);

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
        $definitionPattern = '/^\s*(public|protected|private)\s*(static)?\s*\$[a-z][a-z\d]*' .
            '(\s*,\s*\$[a-z][a-z\d]*)*.*?\s*\/\/>.+?$/i';
        $isDefinition = (bool)preg_match($definitionPattern, $line);
        if (!($isValidLineNumber && $isDefinition)) {
            continue;
        }

        $commentDelimiter = strpos($line, '//>');
        $propertiesDefinition = substr($line, 0, $commentDelimiter);
        $commentDefinition = substr($line, $commentDelimiter);

        $propertyPattern = '/(?<=\$)[a-z][a-z\d]*/i';
        preg_match_all($propertyPattern, $propertiesDefinition, $matches);
        $properties = array_shift($matches);

        $commentPattern = '/(?<=\/\/>).+?$/';
        preg_match($commentPattern, $commentDefinition, $matches);
        $comment = array_shift($matches);

        $comment = trim($comment);
        $typeDeclarationPattern = '/^[a-z][a-z\d]*(\[\])?/i';
        preg_match($typeDeclarationPattern, $comment, $matches);
        if (!empty($matches)) {
            $typeDeclaration = array_shift($matches);
            $comment = substr($comment, strlen($typeDeclaration));
        } else {
            /** @noinspection PhpUnhandledExceptionInspection */
            throw new CrazyException(sprintf('Cannot find type declaration in %s on line %d', $filename, $lineNumber));
        }

        $primitiveTypes = ['int', 'float', 'double', 'bool', 'string', 'callable', 'object', 'iterable', 'array'];

        $realType = $typeDeclaration;
        if (strpos($typeDeclaration, '[]') !== false) {
            $realType = 'array';
            $baseType = substr($typeDeclaration, 0, strlen($typeDeclaration) - strlen('[]'));
            if (in_array($baseType, $primitiveTypes)) {
                $action = sprintf('is_%s($element)', $baseType);
            } else {
                $action = sprintf('$element instanceof %s', $baseType);
            }
            $arrayCondition = sprintf(
                'array_reduce($var, function(bool $carry, $element): bool { return $carry && %s; }, true)', $action
            );
        }

        $typeReference = $typeDeclaration;
        if (in_array($typeDeclaration, $primitiveTypes)) {
            $typeReference = sprintf('Crazy%s', ucfirst($typeReference));
        }

        $imports = [];
        while (true) {
            $comment = trim($comment);
            $accessModifierPattern = '/^(\+|#|-)/';
            preg_match($accessModifierPattern, $comment, $matches);
            if (!empty($matches)) {
                $accessModifier = array_shift($matches);
                $comment = substr($comment, strlen($accessModifier));
            } else {
                break;
            }

            $comment = trim($comment);
            $importPattern = '/^[a-z][a-z\d]*/i';
            preg_match($importPattern, $comment, $matches);
            $methodName = array_shift($matches);
            if (isset($methodName)) {
                $comment = substr($comment, strlen($methodName));
            } else {
                /** @noinspection PhpUnhandledExceptionInspection */
                throw new CrazyException(sprintf('Cannot find method import in %s on line %d', $filename, $lineNumber));
            }

            $comment = trim($comment);
            $conditionsPattern = '/^((\()?(\[(\d+(\.\d+)?)?\.\.(?(4)(\d+(\.\d+)?)?|(\d+(\.\d+)?))\]' .
                '|`([^`]|(?<=\\\\`))+`)(\s*(&&|\|\|)\s*(?1))*(?(2)\)))/';
            preg_match($conditionsPattern, $comment, $matches);
            $conditions = array_shift($matches);
            if (isset($conditions)) {
                $comment = substr($comment, strlen($conditions));

                $rangeConditionsPattern = '/\[(\d+(\.\d+)?)?\.\.(?(1)(\d+(\.\d+)?)?|(\d+(\.\d+)?))\]/';
                $replaceRangeConditions = function (array $matches): string {
                    $subject = array_shift($matches);
                    $subject = trim($subject, '[]');
                    $bounds = explode('..', $subject);

                    if (ltrim($subject, '.') != $subject) {
                        $condition = sprintf('$var <= %d', array_shift($bounds));
                    } elseif (rtrim($subject, '.') != $subject) {
                        $condition = sprintf('$var >= %d', array_shift($bounds));
                    } else {
                        list($bottom, $top) = $bounds;
                        $condition = sprintf('($var >= %d && $var <= %d)', $bottom, $top);
                    }

                    return $condition;
                };

                $injectedCodePattern = '/`([^`]|(?<=\\\\`))+`/';
                $replaceInjectedConditions = function (array $matches): string {
                    $subject = array_shift($matches);
                    $subject = trim($subject, '`');
                    $subject = sprintf('(%s)', $subject);

                    return $subject;
                };

                $conditions = preg_replace_callback($rangeConditionsPattern, $replaceRangeConditions, $conditions);
                $conditions = preg_replace_callback($injectedCodePattern, $replaceInjectedConditions, $conditions);
                $conditions = sprintf('%s && %s', @$arrayCondition, $conditions);
            } else {
                $conditions = @$arrayCondition;
            }


            $comment = trim($comment);
            $callbacksPattern = '/(?<=^->)\s*([a-z][a-z\d]*|`([^`]|(?<=\\\\`))+`)' .
                '(\s+&&\s+([a-z][a-z\d]*|`([^`]|(?<=\\\\`))+`))*/i';
            preg_match($callbacksPattern, $comment, $matches);
            $callbacks = array_shift($matches);
            if (isset($callbacks)) {
                $comment = substr($comment, strlen('->') + strlen($callbacks));

                $callbacksElements = explode('&&', $callbacks);
                foreach ($callbacksElements as &$element) {
                    $element = trim($element);
                    $withoutQuotes = trim($element, '`');
                    if ($withoutQuotes != $element) {
                        $element = $withoutQuotes;
                    } else {
                        $element = sprintf('%s::%s($var)', $typeReference, $element);
                    }
                }

                $callbacks = implode('; ', $callbacksElements);
            }

            $import = new stdClass();
            $import->accessModifier = $accessModifier;
            $import->methodName = $methodName;
            $import->conditions = @$conditions;
            $import->callbacks = @$callbacks;

            $imports[] = $import;
        }

        $comment = trim($comment);
        $aliasPattern = '/(?<=^=>)\s+[a-z][a-z\d]*/';
        preg_match($aliasPattern, $comment, $matches);
        $alias = array_shift($matches);
        if (isset($alias)) {
            if (count($properties) > 1) {
                /** @noinspection PhpUnhandledExceptionInspection */
                throw new CrazyException(
                    sprintf('Cannot use alias for several properties in %s on line %d', $filename, $lineNumber)
                );
            }

            $comment = substr($comment, strlen('=>') + strlen($alias));
            $alias = trim($alias);
        }

        $comment = trim($comment);
        $userCommentPattern = '/^--.+/';
        preg_match($userCommentPattern, $comment, $matches);
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
                            'return %s::%s(%s, $var, ...$args)',$typeReference, $import->methodName, $propertyReference
                        );
                }

                if (isset($import->conditions)) {
                    $conditionalTemplate = 'if (%s) { %s; %s; } ' .
                        'else { throw new CrazyException("Conditions for %s::%s() did not pass"); }';
                    $code = sprintf(
                        $conditionalTemplate, $import->conditions, $import->callbacks, $action, $class->name,  $method
                    );
                } else {
                    $nonConditionalClauseTemplate = '{ %s; %s; }';
                    $code = sprintf($nonConditionalClauseTemplate, $import->callbacks, $action);
                }

                switch ($import->methodName) {
                    case 'get':
                        $getterTemplate = 'function (): %s { $var = %s; %s; }';
                        $function = sprintf($getterTemplate, $realType, $propertyReference, $code);
                        break;
                    case 'set':
                        $setterTemplate = 'function (%s $var): void { %s; }';
                        $function = sprintf($setterTemplate, $realType, $code);
                        break;
                    default:
                        $generalTemplate = 'function (...$args) { $var = %s; %s; }';
                        $function = sprintf($generalTemplate, $propertyReference, $code);
                }

                $closure = eval(sprintf('return %s;', $function));
                $flags = 0;

                $accessModifiers = ['+' => ZEND_ACC_PUBLIC, '#' => ZEND_ACC_PROTECTED, '-' => ZEND_ACC_STATIC];
                $flags |= $accessModifiers[$accessModifier];
                if (strpos($propertiesDefinition, 'static')) {
                    $flags |= ZEND_ACC_STATIC;
                }

                uopz_add_function($class->name, $method, $closure, $flags, true);
            }
        }
    }
}
