<?php

namespace Modular\ConnectorDependencies\Illuminate\View;

class ViewName
{
    /**
     * Normalize the given view name.
     *
     * @param  string  $name
     * @return string
     */
    public static function normalize($name)
    {
        $delimiter = ViewFinderInterface::HINT_PATH_DELIMITER;
        if (strpos($name, $delimiter) === \false) {
            return str_replace('/', '.', $name);
        }
        [$namespace, $name] = explode($delimiter, $name);
        return $namespace . $delimiter . str_replace('/', '.', $name);
    }
}
