<?php

/* formally Zend\Di */

namespace Humus\Di;

interface ServiceLocation extends Locator
{
    public function set($name, $service);
}
