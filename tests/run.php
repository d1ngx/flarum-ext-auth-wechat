<?php

declare(strict_types=1);

// Tests use installed Flarum/FoF classes, mocked HTTP and an in-memory SQLite DB.
// Never load a forum site.php/config.php or connect to a forum database.
$autoload = getenv('FLARUM_VENDOR_AUTOLOAD') ?: dirname(__DIR__).'/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Run composer install or set FLARUM_VENDOR_AUTOLOAD.\n");
    exit(2);
}
require $autoload;
spl_autoload_register(function (string $class): void {
    $prefix = 'D1ngX\\WechatOAuth\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    }
});

use D1ngX\WechatOAuth\WechatClient;
use D1ngX\WechatOAuth\WechatProvider;
use D1ngX\WechatOAuth\WechatResourceOwner;
use Flarum\Forum\Auth\Registration;
use Flarum\Settings\SettingsRepositoryInterface;
use FoF\OAuth\Controllers\AbstractOAuthController;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Laminas\Diactoros\ServerRequest;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use Psr\Http\Message\ServerRequestInterface;

error_reporting(E_ALL);
set_error_handler(function ($level, $message, $file, $line): never {
    throw new ErrorException($message, 0, $level, $file, $line);
});

final class MemorySettings implements SettingsRepositoryInterface
{
    public function __construct(private array $values = []) {}
    public function all(): array { return $this->values; }
    public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->values[$key] = $value; }
    public function delete(string $keyLike): void { unset($this->values[$keyLike]); }
}

final class StateProbe extends AbstractOAuthController
{
    public function __construct() { $this->cache = new ArrayStore(); $this->events = new Illuminate\Events\Dispatcher(); }
    public function link(Flarum\User\User $user, ResourceOwnerInterface $owner): void { $this->linkAccount($user, $owner, '/settings'); }
    public function seed(Store $session, string $state): void { $this->put(self::SESSION_OAUTH2STATE, $state, $session); }
    public function check(Store $session, mixed $state): void
    {
        $this->validateState($session, (new ServerRequest())->withQueryParams(['state' => $state]));
    }
    protected function getRouteName(): string { return 'fof-oauth'; }
    protected function getProviderName(): string { return 'wechat'; }
    protected function getProvider(string $redirectUri): AbstractProvider { throw new LogicException('Unused'); }
    protected function getAuthorizationUrlOptions(): array { return []; }
    protected function isPkceEnabled(): bool { return false; }
    protected function getIdentifier(ResourceOwnerInterface $user): string { return $user->getId(); }
    protected function setSuggestions(Registration $registration, ResourceOwnerInterface $user, string $token): void {}
}

$tests = 0;
$failures = 0;
function test(string $name, callable $fn): void
{
    global $tests, $failures;
    ++$tests;
    try { $fn(); echo "PASS $name\n"; }
    catch (Throwable $e) { ++$failures; echo "FAIL $name: ".get_class($e).' '.$e->getMessage()."\n"; }
}
function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) { throw new RuntimeException('Values differ: '.json_encode([$expected, $actual])); }
}
function rejects(callable $fn, string $class = Throwable::class): Throwable
{
    try { $fn(); } catch (Throwable $e) {
        if (!$e instanceof $class) { throw $e; }
        return $e;
    }
    throw new RuntimeException('Expected rejection');
}
function client(array $responses, array &$history): WechatClient
{
    $handler = HandlerStack::create(new MockHandler($responses));
    $handler->push(Middleware::history($history));
    return new WechatClient([
        'clientId' => 'test-app', 'clientSecret' => 'secret-not-for-logs',
        'redirectUri' => 'https://forum.example/auth/wechat',
    ], ['httpClient' => new Client(['handler' => $handler])]);
}
function jsonResponse(array $data, int $status = 200): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($data));
}
function tokenResponse(array $extra = []): Response
{
    return jsonResponse(array_merge(['access_token' => 'private-token', 'expires_in' => 7200, 'openid' => 'app-openid'], $extra));
}

test('authorization URL uses website QR scope, AppID and generated state', function () {
    $history = []; $c = client([], $history);
    $url = $c->getAuthorizationUrl(); parse_str(parse_url($url, PHP_URL_QUERY), $q);
    same('open.weixin.qq.com', parse_url($url, PHP_URL_HOST));
    same('/connect/qrconnect', parse_url($url, PHP_URL_PATH));
    same('test-app', $q['appid']); same('snsapi_login', $q['scope']);
    same('https://forum.example/auth/wechat', $q['redirect_uri']);
    same($c->getState(), $q['state']); same(true, strlen($q['state']) >= 32);
    same(false, isset($q['client_secret'])); same(false, isset($q['secret']));
});
test('code exchange uses WeChat GET parameters without OAuth parameter names', function () {
    $history = []; $c = client([tokenResponse()], $history); $c->getAccessToken('authorization_code', ['code' => 'one-use-code']);
    $r = $history[0]['request']; parse_str($r->getUri()->getQuery(), $q);
    same('GET', $r->getMethod()); same('test-app', $q['appid']); same('secret-not-for-logs', $q['secret']);
    same('one-use-code', $q['code']); same('authorization_code', $q['grant_type']);
    same(false, isset($q['client_id'])); same(false, isset($q['client_secret']));
});
test('old UnionID is used instead of application OpenID', function () {
    $history = []; $c = client([tokenResponse(), jsonResponse(['openid' => 'app-openid', 'unionid' => 'legacy-union', 'nickname' => '测试'])], $history);
    $owner = $c->getResourceOwner($c->getAccessToken('authorization_code', ['code' => 'code']));
    same('legacy-union', $owner->getId()); same('测试', $owner->getNickname());
});
test('UnionID can come from validated token response', function () {
    $history = []; $c = client([tokenResponse(['unionid' => 'legacy-union']), jsonResponse(['openid' => 'app-openid'])], $history);
    same('legacy-union', $c->getResourceOwner($c->getAccessToken('authorization_code', ['code' => 'code']))->getId());
});
foreach ([[], ['unionid' => ''], ['unionid' => 123], ['unionid' => '   ']] as $i => $data) {
    test('missing or invalid UnionID is rejected '.$i, fn () => rejects(fn () => new WechatResourceOwner($data), UnexpectedValueException::class));
}
foreach ([['openid' => 'other', 'unionid' => 'u'], ['openid' => 'app-openid', 'unionid' => 'different']] as $i => $data) {
    test('inconsistent server identity is rejected '.$i, function () use ($data) {
        $history = []; $c = client([tokenResponse(['unionid' => 'original']), jsonResponse($data)], $history);
        rejects(fn () => $c->getResourceOwner($c->getAccessToken('authorization_code', ['code' => 'c'])), UnexpectedValueException::class);
    });
}
test('HTTP 200 WeChat errcode is rejected without leaking upstream message', function () {
    $history = []; $c = client([jsonResponse(['errcode' => 40029, 'errmsg' => 'secret-not-for-logs private-token'])], $history);
    $e = rejects(fn () => $c->getAccessToken('authorization_code', ['code' => 'c']));
    same(false, str_contains($e->getMessage(), 'secret-not-for-logs')); same(40029, $e->getCode());
});
test('HTTP 500 transport error does not leak token request URI', function () {
    $history = []; $c = client([new Response(500, [], 'private-token')], $history);
    $e = rejects(fn () => $c->getAccessToken('authorization_code', ['code' => 'c']));
    same(false, str_contains((string) $e, 'secret-not-for-logs')); same(null, $e->getPrevious());
});
test('malformed token JSON is refused', function () {
    $history = []; $c = client([new Response(200, ['Content-Type' => 'application/json'], 'not-json')], $history);
    rejects(fn () => $c->getAccessToken('authorization_code', ['code' => 'c']));
});
test('token without OpenID is refused', function () {
    $history = []; $c = client([jsonResponse(['access_token' => 'private-token'])], $history);
    rejects(fn () => $c->getAccessToken('authorization_code', ['code' => 'c']));
});
test('userinfo error is refused', function () {
    $history = []; $c = client([tokenResponse(), jsonResponse(['errcode' => 40003, 'errmsg' => 'bad openid'])], $history);
    rejects(fn () => $c->getResourceOwner($c->getAccessToken('authorization_code', ['code' => 'c'])));
});
test('unsupported grant and empty code are refused before HTTP', function () {
    $history = []; $c = client([], $history);
    rejects(fn () => $c->getAccessToken('refresh_token', ['code' => 'c']));
    rejects(fn () => $c->getAccessToken('authorization_code', ['code' => ''])); same([], $history);
});
test('provider preserves legacy name, credentials and default disabled status', function () {
    $p = new WechatProvider(new MemorySettings(['flarum-ext-auth-wechat.app_id' => 'old-app', 'flarum-ext-auth-wechat.app_secret' => 'old-secret']));
    same('wechat', $p->name()); same(false, $p->enabled()); same(false, $p->pkceEnabled());
    parse_str(parse_url($p->provider('https://forum.example/auth/wechat')->getAuthorizationUrl(), PHP_URL_QUERY), $q);
    same('old-app', $q['appid']);
});
test('saved empty credentials disable legacy fallback', function () {
    $p = new WechatProvider(new MemorySettings(['fof-oauth.wechat.client_id' => '', 'flarum-ext-auth-wechat.app_id' => 'old', 'flarum-ext-auth-wechat.app_secret' => 'old']));
    rejects(fn () => $p->provider('https://forum.example/auth/wechat'), RuntimeException::class);
});
test('new credentials take precedence over legacy settings', function () {
    $p = new WechatProvider(new MemorySettings(['fof-oauth.wechat.client_id' => 'new-app', 'fof-oauth.wechat.client_secret' => 'new-secret', 'flarum-ext-auth-wechat.app_id' => 'old-app']));
    parse_str(parse_url($p->provider('https://forum.example/auth/wechat')->getAuthorizationUrl(), PHP_URL_QUERY), $q); same('new-app', $q['appid']);
});
test('extension registers its provider with the real FoF extender', function () {
    $container = new Illuminate\Container\Container();
    $container->instance(SettingsRepositoryInterface::class, new MemorySettings());
    $extenders = require dirname(__DIR__).'/extend.php';
    $extenders[0]->extend($container);
    $providers = iterator_to_array($container->tagged('fof-oauth.providers'));
    same(1, count($providers)); same('wechat', $providers[0]->name());
    rejects(fn () => $extenders[0]->extend($container), RuntimeException::class);
});
test('registration never supplies an email or stores raw WeChat data', function () {
    $p = new WechatProvider(new MemorySettings()); $r = new Registration();
    $p->suggestions($r, new WechatResourceOwner(['unionid' => 'u', 'nickname' => 'wechat-user', 'email' => 'untrusted@example.com']), 'private-token');
    same([], $r->getProvided()); same('wechat-user', $r->getSuggested()['username']); same(null, $r->getPayload());
});
test('FoF state validation accepts once and refuses replay', function () {
    $p = new StateProbe(); $s = new Store('test', new ArraySessionHandler(120)); $s->start();
    $p->seed($s, 'random-state'); $p->check($s, 'random-state'); rejects(fn () => $p->check($s, 'random-state'));
});
test('FoF state validation refuses wrong, missing and cross-session state', function () {
    $p = new StateProbe(); $s = new Store('test', new ArraySessionHandler(120)); $s->start();
    $p->seed($s, 'random-state'); rejects(fn () => $p->check($s, 'wrong'));
    rejects(fn () => $p->check($s, null)); $p->seed($s, 'random-state');
    $other = new Store('other', new ArraySessionHandler(120)); $other->start(); rejects(fn () => $p->check($other, 'random-state'));
});
test('FoF return paths refuse external and control-character redirects', function () {
    foreach (['https://evil.example', '//evil.example', '/\\evil.example', "/\t/evil.example", "\r\nLocation:x"] as $path) {
        same(false, AbstractOAuthController::isSafeReturnPath($path));
    }
    same(true, AbstractOAuthController::isSafeReturnPath('/settings?from=wechat'));
});
test('real Flarum login lookup reconnects legacy user using UnionID', function () {
    $db = new Illuminate\Database\Capsule\Manager();
    $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']); $db->setAsGlobal(); $db->bootEloquent();
    $db->schema()->create('users', function ($t) { $t->increments('id'); $t->string('username'); });
    $db->schema()->create('login_providers', function ($t) {
        $t->increments('id'); $t->integer('user_id'); $t->string('provider'); $t->string('identifier'); $t->timestamp('created_at')->nullable(); $t->timestamp('last_login_at')->nullable();
    });
    $db->table('users')->insert(['id' => 986, 'username' => 'legacy-user']);
    $db->table('login_providers')->insert(['user_id' => 986, 'provider' => 'wechat', 'identifier' => 'legacy-union']);
    $owner = new WechatResourceOwner(['unionid' => 'legacy-union', 'openid' => 'different-app-openid']);
    same(986, Flarum\User\LoginProvider::logIn('wechat', $owner->getId())->id);
    same(null, Flarum\User\LoginProvider::logIn('wechat', 'different-app-openid'));
    same(1, $db->table('users')->count());
});
test('FoF linking refuses another user and does not duplicate an existing binding', function () {
    $p = new StateProbe(); $owner = new WechatResourceOwner(['unionid' => 'legacy-union']);
    $user = Flarum\User\User::findOrFail(986); $p->link($user, $owner);
    same(1, Illuminate\Database\Capsule\Manager::table('login_providers')->count());
    Illuminate\Database\Capsule\Manager::table('users')->insert(['id' => 987, 'username' => 'other']);
    rejects(fn () => $p->link(Flarum\User\User::findOrFail(987), $owner), FoF\OAuth\Errors\AuthenticationException::class);
    same(986, (int) Illuminate\Database\Capsule\Manager::table('login_providers')->value('user_id'));
});

echo "$tests tests, $failures failures\n";
exit($failures === 0 ? 0 : 1);
