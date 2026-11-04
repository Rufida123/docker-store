<?php

// Module routes. Each module registers its own URLs from its routes.php;
// nothing outside a module defines module routes.

require __DIR__.'/../app/Modules/Users/routes.php';
require __DIR__.'/../app/Modules/Products/routes.php';
require __DIR__.'/../app/Modules/Orders/routes.php';
require __DIR__.'/../app/Modules/Notifications/routes.php';
