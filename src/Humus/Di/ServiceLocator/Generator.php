<?php

namespace Humus\Di\ServiceLocator;

use Zend\Code\Generator as CodeGen;

class Generator
{
    protected $containerClass = 'ApplicationContext';

    protected $definitions = array();

    protected $instances = array();

    protected $namespace;

    /**
     * Constructor
     *
     * @param  array $data
     * @return void
     */
    public function __construct(array $data)
    {
        $this->definitions = isset($data['definition']) ? $data['definition'] : array();
        $this->instances = isset($data['instance']) ? $data['instance'] : array();
    }

    /**
     * Set the class name for the generated service locator container
     *
     * @param  string $name
     * @return Generator
     */
    public function setContainerClass($name)
    {
        $this->containerClass = $name;
        return $this;
    }

    /**
     * Set the namespace to use for the generated class file
     *
     * @param  string $namespace
     * @return Generator
     */
    public function setNamespace($namespace)
    {
        $this->namespace = $namespace;
        return $this;
    }

    /**
     * Construct, configure, and return a PHP classfile code generation object
     *
     * Creates a Zend\CodeGenerator\Php\PhpFile object that has
     * created the specified class and service locator methods.
     *
     * @param  null|string $filename
     * @return CodeGen\PhpFile
     */
    public function getCodeGenerator($filename = null)
    {
        $indent         = '    ';
        $instances      = $this->instances;
        $aliases        = isset($instances['alias']) ? $instances['alias'] : array();
        $caseStatements = array();
        $getters        = array();
        $definitions    = $this->definitions;

        foreach ($definitions as $name => $data) {
            // merge data from supertype (one level)
            if (isset($data['supertype']) && isset($definitions[$data['supertype']])) {
                $removeAbstract = false;
                if (!isset($data['abstract']) || (bool) $data['abstract'] === false) {
                    $removeAbstract = true;
                }
                if (isset($data['instantiator']['name'])) {
                    unset($definitions[$data['supertype']]['instantiator']['name']);
                }
                $data = array_merge_recursive($data, $definitions[$data['supertype']]);
                if (is_array($data['supertype'])) {
                    $data['supertype'] = array_shift($data['supertype']);
                }
                $instanceData = isset($instances[$name]) ? $instances[$name] : array();
                $supertypeInstanceData = isset($instances[$data['supertype']]) ? $instances[$data['supertype']] : array(); //@TODO
                $instanceData = array_merge_recursive($instanceData, $supertypeInstanceData);
                $definitions[$name] = $data;
                $instances[$name] = $instanceData;
                if ($removeAbstract) {
                    unset($data['abstract']);
                    unset($data['supertype']);
                }
            }
            // do not allow instantiation of abstract classes
            if (isset($data['abstract']) && (bool) $data['abstract'] === true) {
                continue;
            }
            $getter = $this->normalizeAlias($name);

            $shared = isset($data['shared']) ? (bool) $data['shared'] : true;

            $instantiator = isset($data['instantiator']['name']) ? $data['instantiator']['name'] : '__construct';
            $instantiationParams = isset($data['instantiator']['parameters']) ? $data['instantiator']['parameters'] : array();
            $methods = isset($data['methods']) ? $data['methods'] : array();

            $injectionParams = array();
            foreach ($methods as $method => $params) {
                $injectionParams[$method] = $params;
            }
            $params = array();
            // Build parameter list for instantiation
            foreach ($instantiationParams as $key => $param) {
                if (null === $param['type'] ||
                    in_array($param['type'], array('string', 'array', 'integer', 'boolean'))
                ) {
                    $type = $param['type'];
                    $param = isset($instances[$name]['parameters'][$param['name']]) ?
                            $instances[$name]['parameters'][$param['name']] : NULL;
                    switch (strtolower($type)) {
                        case 'integer':
                            $param = (int) $param;
                            break;
                        case 'boolean':
                            $param = (bool) $param;
                            break;
                    }
                    $string = preg_replace( '/\\\{2,}/', '\\', var_export($param, 1));

                    if (strstr($string, '::__set_state(')) {
                        throw new \RuntimeException(
                            'Arguments in definitions may not contain objects'
                        );
                    }
                    $params[$key] = $string;
                } elseif (isset($param['type']) && $param['type'] == '$this') {
                    $params[$key] = '$this';
                } elseif (isset($param['type'])) {
                    $params[$key] = sprintf('$this->%s()', $this->normalizeAlias($param['type']));
                } else {
                    $message = sprintf(
                        'Unable to use object arguments when building containers. Encountered with "%s", '
                        . 'parameter of type "%s"', $name, get_class($param));
                    throw new \RuntimeException($message);
                }
            }

            // Create instantiation code
            $creation    = '';
            $constructor = $instantiator;
            if ('__construct' != $constructor) {
                // Constructor callback
                if (is_callable($constructor)) {
                    $callback = $constructor;
                    if (is_array($callback)) {
                        $class = (is_object($callback[0])) ? get_class($callback[0]) : $callback[0];
                        $method = $callback[1];
                    } elseif (is_string($callback) && strpos($callback, '::') !== false) {
                        list($class, $method) = explode('::', $callback, 2);
                    }
                    $callback = var_export(array($class, $method), 1);
                    if (count($params)) {
                        $creation = sprintf('$object = call_user_func(%s, %s);', $callback, implode(', ', $params));
                    } else {
                        $creation = sprintf('$object = call_user_func(%s);', $callback);
                    }
                } else if (is_string($constructor) && strpos($constructor, '->') !== false) {
                    list($class, $method) = explode('->', $constructor, 2);
                    if (!class_exists($class)) {
                        throw new \InvalidArgumentException('No class found: ' . $class);
                    }
                    $factoryGetter = $this->normalizeAlias($class);
                    if (count($params)) {
                        $creation = sprintf('$object = $this->' . $factoryGetter . '()->%s(%s);', $method, implode(', ', $params));
                    } else {
                        $creation = sprintf('$object = $this->' . $factoryGetter . '()->%s();', $method);
                    }
                } else {
                    throw new \InvalidArgumentException('Invalid constructor supplied for class: ' . $name);
                }
            } else {
                // Normal instantiation
                $className = '\\' . ltrim($name, '\\');
                $creation = sprintf('$object = new %s(%s);', $className, implode(', ', $params));
            }

            $params = array();
            // Create method call code
            $methods = '';
            foreach ($injectionParams as $key => $methodData) {
                $methodName   = $key;
                $methodParams = $methodData;

                // Create method parameter representation
                foreach ($methodParams as $key => $param) {
                    if (!isset($param['type'])) $param['type'] = NULL;
                    if (!isset($param['name'])) $param['name'] = NULL;
                    if (null === $param['type'] ||
                        in_array($param['type'], array('string', 'array', 'integer', 'boolean'))
                    ) {
                        $type = $param['type'];
                        $param = isset($instances[$name]['parameters'][$param['name']]) ?
                                $instances[$name]['parameters'][$param['name']] : NULL;
                        switch (strtolower($type)) {
                            case 'integer':
                                $param = (int) $param;
                                break;
                            case 'boolean':
                                $param = (bool) $param;
                                break;
                        }

                        $string = preg_replace('/\\\{2,}/', '\\', var_export($param, 1));
                        if (strstr($string, '::__set_state(')) {
                            throw new \RuntimeException(
                                'Arguments in definitions may not contain objects'
                            );
                        }
                        $params[$key] = $string;
                    } elseif (isset($param['type']) && $param['type'] == '$this') {
                        $params[$key] = '$this';
                    } elseif (isset($param['type'])) {
                        $params[$key] = sprintf(
                            '$this->%s()', $this->normalizeAlias($param['type'])
                        );
                    } else {
                        $message = sprintf(
                            'Unable to use object arguments when building containers. '
                            . 'Encountered with "%s", parameter of type "%s"', $name,
                            get_class($param));
                        throw new \RuntimeException($message);
                    }
                }

                $methods .= sprintf("\$object->%s(%s);\n", $methodName, implode(', ', $params));
            }

            $storage = '';
            
            if ($shared === TRUE) {
                $storage = sprintf("\$this->services['%s'] = \$object;\n", $name);
            }

            // Start creating getter
            $getterBody = '';

            if ($shared === TRUE) {
                $getterBody .= sprintf("if (isset(\$this->services['%s'])) {\n",  $name);
                $getterBody .= sprintf("%sreturn \$this->services['%s'];\n}\n", $indent, $name);
            }

            // Creation and method calls
            $getterBody .= sprintf("%s\n", $creation);
            $getterBody .= $methods;

            // Stored service
            $getterBody .= $storage;

            // End getter body
            $getterBody .= "return \$object;\n";

            $getterDef = new CodeGen\MethodGenerator();
            $getterDef->setName($getter)
                      ->setBody($getterBody);
            $getters[] = $getterDef;

            // Get cases for case statements
            $cases = array($name);
            foreach ($aliases as $alias => $classAlias) {
                if ($name == $classAlias) {
                    $cases = array_merge(array($alias), $cases);
                }
            }

            // Build case statement and store
            $statement = '';
            foreach ($cases as $value) {
                $statement .= sprintf("%scase '%s':\n", $indent, $value);
            }
            $statement .= sprintf("%sreturn \$this->%s();\n", str_repeat($indent, 2), $getter);

            $caseStatements[] = $statement;
        }

        // Build switch statement
        $switch  = sprintf(
            "switch (%s) {\n%s\n", '$name', implode("\n", $caseStatements)
        );
        $switch .= sprintf(
            "%sdefault:\n%sreturn parent::get(%s, %s);\n", $indent, str_repeat($indent, 2), '$name', '$params'
        );
        $switch .= "}\n\n";

        // Build get() method
        $nameParam   = new CodeGen\ParameterGenerator();
        $nameParam->setName('name');
        $nameParam->setDefaultValue(array());
        $paramsParam = new CodeGen\ParameterGenerator();
        $paramsParam->setName('params')
                    ->setType('array')
                    ->setDefaultValue(array());

        $get = new CodeGen\MethodGenerator();
        $get->setName('get');
        $get->setParameters(array(
            $nameParam,
            $paramsParam,
        ));
        $get->setBody($switch);

        // Create getters for aliases
        $aliasMethods = array();

        foreach ($aliases as $alias => $class) {
            $aliasMethods[] = $this->getCodeGenMethodFromAlias($alias, $class);
        }

        // Create class code generation object
        $container = new CodeGen\ClassGenerator();
        $container->setName($this->containerClass)
                  ->setExtendedClass('ServiceLocator')
                  ->addMethodFromGenerator($get)
                  ->addMethods($getters)
                  ->addMethods($aliasMethods);

        // Create PHP file code generation object
        $classFile = new CodeGen\FileGenerator();
        $classFile->setUse('Humus\Di\ServiceLocator')
                  ->setClass($container);

        if (null !== $this->namespace) {
            $classFile->setNamespace($this->namespace);
        }

        if (null !== $filename) {
            $classFile->setFilename($filename);
        }

        return $classFile;
    }

    /**
     * Reduces aliases
     *
     * Takes alias list and reduces it to a 2-dimensional array of
     * class names pointing to an array of aliases that resolve to
     * it.
     *
     * @param  array $aliasList
     * @return array
     */
    protected function reduceAliases(array $aliasList)
    {
        $reduced = array();
        $aliases = array_keys($aliasList);
        foreach ($aliasList as $alias => $service)
        {
            if (in_array($service, $aliases)) {
                do {
                    $service = $aliasList[$service];
                } while (in_array($service, $aliases));
            }
            if (!isset($reduced[$service])) {
                $reduced[$service] = array();
            }
            $reduced[$service][] = $alias;
        }
        return $reduced;
    }

    /**
     * Create a PhpMethod code generation object named after a given alias
     *
     * @param  string $alias
     * @param  class $class Class to which alias refers
     * @return CodeGen\PhpMethod
     */
    protected function getCodeGenMethodFromAlias($alias, $class)
    {
        $alias = $this->normalizeAlias($alias);
        $method = new CodeGen\MethodGenerator();
        $method->setName($alias)
               ->setBody(sprintf('return $this->get(\'%s\');', $class));
        return $method;
    }

    /**
     * Normalize an alias to a getter method name
     *
     * @param  string $alias
     * @return string
     */
    protected function normalizeAlias($alias)
    {
        $normalized = preg_replace('/[^a-zA-Z0-9]/', ' ', $alias);
        $normalized = 'get' . str_replace(' ', '', ucwords($normalized));
        return $normalized;
    }
}
