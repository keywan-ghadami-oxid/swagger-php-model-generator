<?php

namespace SwaggerGen;

use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Method;
use Nette\PhpGenerator\PhpNamespace;
use Nette\PhpGenerator\Property;
use RuntimeException;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Yaml\Yaml;

use function is_null;
use function in_array;

class GenerateModels extends ClassGenerator
{

    protected $typeMap = [];

    /**
     * Generates classes in the "classes" field
     *
     * @param string $file_path
     * @throws RuntimeException
     */
    public function generate(string $file_path): void
    {
        $namespace_name = $this->namespaceModel();

        if (substr($file_path, -4, 4) == 'json') {
            $api = json_decode(file_get_contents($file_path), true);
        } else {
            $api = Yaml::parseFile($file_path);
        }

        $namespace = new PhpNamespace($namespace_name);

        foreach ($api['definitions'] as $classId => $class_details) {
            if ($class_details['type'] === 'object') {
                $className = preg_replace("/[^a-zA-z0-9]/", "", ucwords($class_details['title']));
                $class_details['className'] = $className;
                $this->typeMap[$classId] = $className;
            } else {
                $this->typeMap[$classId] = $class_details['type'];
            }
        }

        foreach ($api['definitions'] as $classId => $class_details) {
            if ($class_details['type'] !== 'object') {
                continue;
            }
            $className = $this->typeMap[$classId];
            $class = new ClassType($className, $namespace);
            $class->setExtends(self::MODEL_CLASS_NAME);
            $class->addComment('** This file was generated automatically, you might want to avoid editing it **');

            if (!empty($class_details['description'])) {
                $class->addComment("\n" . $class_details['description']);
            }

            if (isset($class_details['allOf'])) {
                $parent_class_name = $this->typeFromRef($class_details['allOf'][0]);
                $class->setExtends("$namespace_name\\$parent_class_name");
                $required = $class_details['allOf'][1]['required'];
                $properties = $class_details['allOf'][1]['properties'];
            } else {
                $required = isset($class_details['required']) ? $class_details['required'] : null;
                $properties = $class_details['properties'];
            }

            $this->classProperties($properties, $class, $required);

            $this->classes[$className] = $class;
        }
        $done = 1;
    }

    /**
     * @param array $properties
     * @param ClassType $class
     * @param array $required
     * @throws RuntimeException
     */
    private function classProperties(array $properties, ClassType $class, ?array $required): void
    {
        $converter = new CamelCaseToSnakeCaseNameConverter();
        $namespace_name = $this->namespaceModel();
        if (is_null($required)) {
            $required = [];
        }

        foreach ($properties as $property_name => $property_details) {
            if (isset($property_details['$ref'])) {
                $type = $this->typeFromRef($property_details);
                if (false === array_search($type, ['string','int'])) {
                    $typehint = "$namespace_name\\$type";
                }
            } else {
                $type = $property_details['type'];
                if (is_array($type)) {
                    $type = "mixed";
                }
                $typehint = $type;
            }

            /**
             * @var Property $property
             */
            $property = $class->addProperty($property_name)->setVisibility('protected');
            $property->addComment($property_details['description'] ?? "\n");

            if ($type === 'array') {
                $sub_type = $this->typeFromRef($property_details['items']);
                if (isset($property_details['items']['$ref'])) {
                    $sub_typehint = $sub_type;
                } else {
                    $sub_typehint = $sub_type;
                }
                $comment_type = "{$sub_typehint}[]";
            } else {
                $comment_type = $type;
                $sub_type = $sub_typehint = '';
            }
            if ($comment_type === 'number') {
                $comment_type = 'float';
            }
            if (is_array($comment_type)) {
                $comment_type = "";
            }
            $property->addComment("@var $comment_type");

            if (in_array($property_name, $required, true)) {
                $property->addComment('@required');
            } else {
                $this->blankValue($property, $type);
            }

            $capital_case = $converter->denormalize($property_name);#
            $capital_case = ucfirst($capital_case);
            $class->addMethod('get' . $capital_case)
                ->setBody("return \$this->$property_name;")
                ->addComment("@return $comment_type");
            /**
             * @var Method $setter
             */
            $setter = $class->addMethod('set' . $capital_case)
                ->setBody("\$this->$property_name = \$$property_name;\n\nreturn \$this;")
                ->addComment("@param $comment_type \$$property_name")
                ->addComment('')
                ->addComment('@return $this');

            $set_parameter = $setter->addParameter($property_name);
            if ($this->notScalarType($type)) {
                $set_parameter->setTypeHint($typehint);
            }

            if ($sub_type) {
                $property_name_singular = $this->unPlural($property_name);
                $capital_case_singular = $this->unPlural($capital_case);
                /**
                 * @var Method $add_to
                 */
                $add_to = $class->addMethod('add' . $capital_case_singular)
                    ->setBody("\$this->{$property_name}[] = \$$property_name_singular;\n\nreturn \$this;")
                    ->addComment("@param $sub_type \$$property_name_singular")
                    ->addComment('')
                    ->addComment('@return $this');

                $set_parameter = $add_to->addParameter($property_name_singular);
                if ($this->notScalarType($sub_type)) {
                    $set_parameter->setTypeHint($sub_typehint);
                }
            }
        }
    }

    /**
     * @param string $dir
     */
    public function saveClasses(string $dir): void
    {
        $this->saveClassesInternal($dir, $this->namespaceModel());
    }

    /**
     * @param string $dir
     */
    public function dumpParentClass(string $dir): void
    {
        $this->dumpParentInternal($dir, __DIR__ . '/SwaggerModel.php', $this->namespaceModel());
    }

    /**
     * @param Property $property
     * @param string $type
     * @throws RuntimeException
     */
    private function blankValue(Property $property, string $type): void
    {
        if ($type !== 'array' && $this->notScalarType($type)) {
            return;
        }

        switch ($type) {
            case 'array':
                $property->setValue([]);
                break;
            case 'string':
                $property->setValue('');
                break;
            case 'integer':
                $property->setValue(0);
                break;
            case 'number':
                $property->setValue(0.0);
                break;
            case 'boolean':
                $property->setValue(false);
                break;
            default:
                throw new RuntimeException("The property with name {$property->getName()} and type $type was not recognised to set a default value");
        }
    }
}
