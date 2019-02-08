<?php

class CrazyException extends Exception
{
}

trait CrazyRevision
{
}

$definitionPattern = '/^\s*(public|protected|private)\s*(static)?\s*\$[a-z][a-z\d]*(\s*,\s*\$[a-z][a-z\d]*)*.*?\s*\/\/>.+?$/i';
$propertyPattern = '/(?<=\$)[a-z][a-z\d]*/i';
$commentPattern = '/^(?<=\/\/>).+?$/';

$typeDeclarationPattern = '/^([a-z][a-z\d]*(<(?1)>)?(\[\])*)/i';
$accessModifierPattern = '/^(+|#|-)/';
$methodImportPattern = '/^[a-z][a-z\d]*/i';
$conditionsPattern = '/^(((\()?\[(\d+(\.\d+)?)\.\.(?(4)((\d+(\.\d+)?|((\d+(\.\d+)))\]|`([^`]|(?<=\\\\`))+`)(\s+(&&|\|\|)\s+(?1))(?(3)\)))*/';
$callbacksPattern = '/^(?<=->)\s*([a-z][a-z\d]*|`([^`]|(?<=\\\\`))+`)(\s+&&\s+([a-z][a-z\d]*|`([^`]|(?<=\\\\`))+`))*/i';
$aliasPattern = '/^(?<==>)\s+[a-z][a-z\d]*/';
$userCommentPattern = '/^--.+/';

$rangeConditionPattern = '/\[(\d+(\.\d+)?)\.\.(?(2)((\d+(\.\d+)?|((\d+(\.\d+)))\]/';
$injectedStringPattern = '/`([^`]|(?<=\\\\`))+`/';
$conjunctionPattern = '/\s*&&\s*/';

$classes = get_declared_classes();
$predefinedClassesCount = 134;
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
        $isDefinition = preg_match($definitionPattern, $file->getCurrentLine());
        if (!($isValidLineNumber && $isDefinition)) {
            continue;
        }

        $properties = [];
        $matches = [];

        preg_match($propertyPattern, $file->getCurrentLine(), $properties);
        preg_match($commentPattern, $file->getCurrentLine(), $matches);
        $comment = array_shift($matches);

        $comment = ltrim($comment);
        $success = preg_match($typeDeclarationPattern, $comment, $matches);
        if (!$success) {
            /** @noinspection PhpUnhandledExceptionInspection */
            throw new CrazyException(
                sprintf('Cannot find type declaration in %s on line %d', $file->getFilename(), $lineNumber)
            );
        }
        $typeDeclaration = array_shift($matches);
        $comment = substr($comment, strlen($typeDeclaration));

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
                function (string $subject): string {
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
                function (string $subject): string {
                    return trim($subject, '[]');
                },
                $conditions
            );

            $comment = ltrim($comment);
            preg_match($callbacksPattern, $comment, $matches);
            $callbacks  = array_shift($matches);
            $comment = substr($comment, strlen($callbacks ?? ''));

            $callbacks = preg_split($conjunctionPattern, $callbacks);
            array_walk(
                $callbacks,
                function (string $element) use ($typeDeclaration): string {
                    $trimmed = trim($element, '`');
                    if ($trimmed !== $element) {
                        return $trimmed;
                    } else {
                        return sprintf('$var = %s::%s()', $typeDeclaration, $element);
                    }
                }
            );
            $callbacks = implode(';', $callbacks);
        }

        $comment = ltrim($comment);
        preg_match($aliasPattern, $comment, $matches);
        $alias = array_shift($matches);
        $comment = substr($comment, strlen($alias ?? ''));

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
    }
}
