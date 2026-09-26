<?php

namespace Nuhsait\Ci4SimpleAuth\Config;

use CodeIgniter\Config\BaseService;
use Nuhsait\Ci4SimpleAuth\Auth;

class Services extends BaseService
{
    public static function simpleauth(bool $getShared = true): Auth
    {
        if ($getShared) {
            return static::getSharedInstance('simpleauth');
        }

        return new Auth();
    }
}
