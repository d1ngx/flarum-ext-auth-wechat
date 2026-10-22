<?php

namespace D1ngX\WechatOAuth;

use League\OAuth2\Client\Provider\ResourceOwnerInterface;

final class WechatResourceOwner implements ResourceOwnerInterface
{
    public function __construct(private readonly array $data)
    {
        if (!is_string($data['unionid'] ?? null) || trim($data['unionid']) === '') {
            throw new \UnexpectedValueException('WeChat did not return a UnionID. Authentication was refused.');
        }
    }

    public function getId(): string
    {
        // Legacy hamzone bindings use UnionID, never application-scoped OpenID.
        return $this->data['unionid'];
    }

    public function getNickname(): string
    {
        return is_string($this->data['nickname'] ?? null) ? $this->data['nickname'] : '';
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
