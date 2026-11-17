<?php

declare(strict_types=1);

namespace Nowo\TaskBoardBundle\Routing;

use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Routing\Attribute\Route as RouteAttribute;
use Symfony\Component\Routing\Loader\AttributeClassLoader;
use Symfony\Component\Routing\Route;

use function array_diff_key;
use function array_key_exists;

/**
 * Since 2.21.1 (REQ-SF-004): reads the `#[Route]` attributes of one bundle controller class and sets `_controller` to
 * `Class::method`, the value the 2.21.0 loader wrote. {@see TaskBoardRouteLoader} owns it (no service, no
 * `routing.loader` tag), so a host that decorates `routing.loader.attribute` cannot change the bundle routes. Stateless.
 *
 * @internal
 */
final class ControllerAttributeRouteLoader extends AttributeClassLoader
{
    /**
     * Sets the `_controller` default of a route read from a controller method, and drops the defaults the attribute
     * loader takes from the method's optional parameters (`string $fieldId = ''` would make `{fieldId}` optional): a
     * bundle route has only the defaults its attributes declare, as in 2.21.0.
     *
     * @param ReflectionClass<object> $class
     */
    protected function configureRoute(Route $route, ReflectionClass $class, ReflectionMethod $method, object $attr): void
    {
        $declared = $attr instanceof RouteAttribute ? $attr->getDefaults() : [];
        foreach ($method->getParameters() as $parameter) {
            if (!array_key_exists($parameter->getName(), $declared)) {
                $route->setDefaults(array_diff_key($route->getDefaults(), [$parameter->getName() => true]));
            }
        }
        $route->setDefault('_controller', $class->getName() . '::' . $method->getName());
    }
}
