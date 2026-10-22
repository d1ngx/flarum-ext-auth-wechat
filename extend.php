<?php

use D1ngX\WechatOAuth\WechatProvider;
use Flarum\Extend;
use FoF\OAuth\Extend\RegisterProvider;

return [
    new RegisterProvider(WechatProvider::class),
    new Extend\Locales(__DIR__.'/locale'),
];
