<?php

namespace D1ngX\WechatOAuth;

use GuzzleHttp\Exception\GuzzleException;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Http\Message\ResponseInterface;

final class WechatClient extends AbstractProvider
{
    public function getBaseAuthorizationUrl(): string
    {
        return 'https://open.weixin.qq.com/connect/qrconnect';
    }

    public function getBaseAccessTokenUrl(array $params): string
    {
        return 'https://api.weixin.qq.com/sns/oauth2/access_token';
    }

    protected function getDefaultScopes(): array
    {
        return ['snsapi_login'];
    }

    protected function getAuthorizationParameters(array $options): array
    {
        $params = parent::getAuthorizationParameters($options);
        $params['appid'] = $this->clientId;
        $params['scope'] = 'snsapi_login';
        unset($params['client_id']);

        return $params;
    }

    protected function getAccessTokenMethod(): string
    {
        return self::METHOD_GET;
    }

    protected function getAccessTokenQuery(array $params): string
    {
        return http_build_query([
            'appid' => $this->clientId,
            'secret' => $this->clientSecret,
            'code' => $params['code'],
            'grant_type' => 'authorization_code',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function getAccessToken($grant, array $options = [])
    {
        if ($grant !== 'authorization_code' || !is_string($options['code'] ?? null) || $options['code'] === '') {
            throw new \InvalidArgumentException('A WeChat authorization code is required.');
        }

        try {
            $token = parent::getAccessToken($grant, $options);
        } catch (GuzzleException | \UnexpectedValueException $e) {
            // WeChat requires credentials in query parameters. Never propagate request URLs.
            throw new IdentityProviderException('WeChat token exchange failed.', 0, []);
        }

        $values = $token->getValues();
        if (!is_string($values['openid'] ?? null) || trim($values['openid']) === '') {
            throw new IdentityProviderException('WeChat returned an invalid token response.', 0, []);
        }

        return $token;
    }

    public function getResourceOwnerDetailsUrl(AccessToken $token): string
    {
        $openid = $token->getValues()['openid'] ?? null;
        if (!is_string($openid) || $openid === '') {
            throw new \UnexpectedValueException('WeChat token is missing OpenID.');
        }

        return 'https://api.weixin.qq.com/sns/userinfo?'.http_build_query([
            'access_token' => $token->getToken(), 'openid' => $openid, 'lang' => 'zh_CN',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function getResourceOwner(AccessToken $token)
    {
        try {
            return parent::getResourceOwner($token);
        } catch (GuzzleException $e) {
            throw new IdentityProviderException('WeChat user information request failed.', 0, []);
        }
    }

    protected function checkResponse(ResponseInterface $response, $data): void
    {
        if (!is_array($data) || $response->getStatusCode() >= 400 || (string) ($data['errcode'] ?? '0') !== '0') {
            // Do not expose upstream errmsg/body: they can contain sensitive request details.
            $code = is_array($data) && is_numeric($data['errcode'] ?? null) ? (int) $data['errcode'] : 0;
            throw new IdentityProviderException('WeChat authorization failed (code '.$code.').', $code, []);
        }
    }

    protected function createResourceOwner(array $response, AccessToken $token): WechatResourceOwner
    {
        $values = $token->getValues();
        if (!is_string($response['openid'] ?? null) || $response['openid'] !== ($values['openid'] ?? null)) {
            throw new \UnexpectedValueException('WeChat returned inconsistent OpenID values.');
        }
        $tokenUnion = $values['unionid'] ?? null;
        $userUnion = $response['unionid'] ?? null;
        if ($tokenUnion !== null && (!is_string($tokenUnion) || trim($tokenUnion) === '')) {
            throw new \UnexpectedValueException('WeChat returned an invalid UnionID.');
        }
        if ($userUnion !== null && (!is_string($userUnion) || trim($userUnion) === '')) {
            throw new \UnexpectedValueException('WeChat returned an invalid UnionID.');
        }
        if ($tokenUnion !== null && $userUnion !== null && $tokenUnion !== $userUnion) {
            throw new \UnexpectedValueException('WeChat returned inconsistent UnionID values.');
        }
        $response['unionid'] = $userUnion ?? $tokenUnion;

        return new WechatResourceOwner($response);
    }
}
