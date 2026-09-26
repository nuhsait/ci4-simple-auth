<?php

use Nuhsait\Ci4SimpleAuth\Auth;

if (! function_exists('auth')) {
    /**
     * Access to the authenticated user: auth()->user(), auth()->id(), auth()->check()
     */
    function auth(): Auth
    {
        return service('simpleauth');
    }
}
