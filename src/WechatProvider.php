<?php

namespace D1ngX\WechatOAuth;

use Flarum\Forum\Auth\Registration;
use FoF\OAuth\Provider;
use League\OAuth2\Client\Provider\AbstractProvider;

final class WechatProvider extends Provider
{
    public function name(): string { return 'wechat'; }

    public function link(): string { return 'https://open.weixin.qq.com/'; }

    public function icon(): string { return 'fab fa-weixin'; }

    public function fields(): array
    {
        return ['client_id' => 'required', 'client_secret' => 'required'];
    }

    public function pkceEnabled(): bool { return false; }

    public function provider(string $redirectUri): AbstractProvider
    {
        $id = $this->credential('client_id', 'app_id');
        $secret = $this->credential('client_secret', 'app_secret');
        if ($id === '' || $secret === '') {
            throw new \RuntimeException('WeChat website application credentials are not configured.');
        }

        return new WechatClient([
            'clientId' => $id, 'clientSecret' => $secret, 'redirectUri' => $redirectUri,
        ], ['httpClient' => new \GuzzleHttp\Client([
            'connect_timeout' => 5, 'timeout' => 15, 'allow_redirects' => false,
        ])]);
    }

    private function credential(string $key, string $legacy): string
    {
        // An explicitly saved empty setting must override legacy credentials too.
        $value = $this->settings->get('fof-oauth.wechat.'.$key);

        return trim((string) ($value ?? $this->settings->get('flarum-ext-auth-wechat.'.$legacy) ?? ''));
    }

    public function suggestions(Registration $registration, mixed $user, string $token): void
    {
        if (!$user instanceof WechatResourceOwner) {
            throw new \UnexpectedValueException('Invalid WeChat resource owner.');
        }
        $registration->suggestUsername($user->getNickname());
        // WeChat supplies no verified email. Never auto-link by nickname or invented email.
        // Avoid persisting the full WeChat response or fetching arbitrary avatar URLs.
    }
}
