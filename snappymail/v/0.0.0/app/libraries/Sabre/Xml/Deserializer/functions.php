<?php

declare(strict_types=1);

namespace Sabre\Xml\Deserializer;

use Sabre\Xml\Reader;

/**
 * This class provides a number of 'deserializer' helper functions.
 * These can be used to easily specify custom deserializers for specific
 * XML elements.
 *
 * You can either use these functions from within the $elementMap in the
 * Service or Reader class, or you can call them from within your own
 * deserializer functions.
 */

/**
 * The 'keyValue' deserializer parses all child elements, and outputs them as
 * a "key=>value" array.
 *
 * For example, keyvalue will parse:
 *
 * <?xml version="1.0"?>
 * <s:root xmlns:s="http://sabredav.org/ns">
 *   <s:elem1>value1</s:elem1>
 *   <s:elem2>value2</s:elem2>
 *   <s:elem3 />
 * </s:root>
 *
 * Into:
 *
 * [
 *   "{http://sabredav.org/ns}elem1" => "value1",
 *   "{http://sabredav.org/ns}elem2" => "value2",
 *   "{http://sabredav.org/ns}elem3" => null,
 * ];
 *
 * If you specify the 'namespace' argument, the deserializer will remove
 * the namespaces of the keys that match that namespace.
 *
 * For example, if you call keyValue like this:
 *
 * keyValue($reader, 'http://sabredav.org/ns')
 *
 * it's output will instead be:
 *
 * [
 *   "elem1" => "value1",
 *   "elem2" => "value2",
 *   "elem3" => null,
 * ];
 *
 * Attributes will be removed from the top-level elements. If elements with
 * the same name appear twice in the list, only the last one will be kept.
 *
 * @phpstan-return array<string, mixed>
 */
function keyValue(Reader $reader, ?string $namespace = null): array
{
    // If there's no children, we don't do anything.
    if ($reader->isEmptyElement) {
        $reader->next();

        return [];
    }

    if (!$reader->read()) {
        $reader->next();

        return [];
    }

    if (Reader::END_ELEMENT === $reader->nodeType) {
        $reader->next();

        return [];
    }

    $values = [];

    do {
        if (Reader::ELEMENT === $reader->nodeType) {
            if (null !== $namespace && $reader->namespaceURI === $namespace) {
                $values[$reader->localName] = $reader->parseCurrentElement()['value'];
            } else {
                $clark = $reader->getClark();
                $values[$clark] = $reader->parseCurrentElement()['value'];
            }
        } else {
            if (!$reader->read()) {
                break;
            }
        }
    } while (Reader::END_ELEMENT !== $reader->nodeType);

    $reader->read();

    return $values;
}

/**
 * The 'enum' deserializer parses elements into a simple list
 * without values or attributes.
 *
 * For example, Elements will parse:
 *
 * <?xml version="1.0"? >
 * <s:root xmlns:s="http://sabredav.org/ns">
 *   <s:elem1 />
 *   <s:elem2 />
 *   <s:elem3 />
 *   <s:elem4>content</s:elem4>
 *   <s:elem5 attr="val" />
 * </s:root>
 *
 * Into:
 *
 * [
 *   "{http://sabredav.org/ns}elem1",
 *   "{http://sabredav.org/ns}elem2",
 *   "{http://sabredav.org/ns}elem3",
 *   "{http://sabredav.org/ns}elem4",
 *   "{http://sabredav.org/ns}elem5",
 * ];
 *
 * This is useful for 'enum'-like structures.
 *
 * If the $namespace argument is specified, it will strip the namespace
 * for all elements that match that.
 *
 * For example,
 *
 * enum($reader, 'http://sabredav.org/ns')
 *
 * would return:
 *
 * [
 *   "elem1",
 *   "elem2",
 *   "elem3",
 *   "elem4",
 *   "elem5",
 * ];
 *
 * @return string[]
 *
 * @phpstan-return list<string>
 */
function enum(Reader $reader, ?string $namespace = null): array
{
    // If there's no children, we don't do anything.
    if ($reader->isEmptyElement) {
        $reader->next();

        return [];
    }
    if (!$reader->read()) {
        $reader->next();

        return [];
    }

    if (Reader::END_ELEMENT === $reader->nodeType) {
        $reader->next();

        return [];
    }
    $currentDepth = $reader->depth;

    $values = [];
    do {
        if (Reader::ELEMENT !== $reader->nodeType) {
            continue;
        }
        if (!is_null($namespace) && $namespace === $reader->namespaceURI) {
            $values[] = $reader->localName;
        } else {
            $values[] = (string) $reader->getClark();
        }
    } while ($reader->depth >= $currentDepth && $reader->next());

    $reader->next();

    return $values;
}

/**
 * The valueObject deserializer turns an XML element into a PHP object of
 * a specific class.
 *
 * This is primarily used by the mapValueObject function from the Service
 * class, but it can also easily be used for more specific situations.
 *
 * @template C of object
 *
 * @param class-string<C> $className
 *
 * @phpstan-return C
 */
function valueObject(Reader $reader, string $className, string $namespace): object
{
    $valueObject = new $className();
    if ($reader->isEmptyElement) {
        $reader->next();

        return $valueObject;
    }

    $defaultProperties = get_class_vars($className);

    $reader->read();
    do {
        if (Reader::ELEMENT === $reader->nodeType && $reader->namespaceURI == $namespace) {
            if (property_exists($valueObject, $reader->localName)) {
                if (is_array($defaultProperties[$reader->localName])) {
                    $valueObject->{$reader->localName}[] = $reader->parseCurrentElement()['value'];
                } else {
                    $valueObject->{$reader->localName} = $reader->parseCurrentElement()['value'];
                }
            } else {
                // Ignore property
                $reader->next();
            }
        } elseif (Reader::ELEMENT === $reader->nodeType) {
            // Skipping element from different namespace
            $reader->next();
        } else {
            if (Reader::END_ELEMENT !== $reader->nodeType && !$reader->read()) {
                break;
            }
        }
    } while (Reader::END_ELEMENT !== $reader->nodeType);

    $reader->read();

    return $valueObject;
}
