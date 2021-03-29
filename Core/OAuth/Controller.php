<?php
namespace Minds\Core\OAuth;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Minds\Core\Config\Config;
use Minds\Core\Di\Di;
use Minds\Core\Entities\Actions\Save;
use Minds\Core\OAuth\Entities\UserEntity;
use Minds\Core\OAuth\Repositories\AccessTokenRepository;
use Minds\Core\OAuth\Repositories\RefreshTokenRepository;
use Minds\Exceptions\UserErrorException;
use Zend\Diactoros\Response\JsonResponse;
use Zend\Diactoros\ServerRequest;

/**
 * OAuth Controller
 * @package Minds\Core\OAuth
 */
class Controller
{
    /** @var Config */
    protected $config;

    /** @var AuthorizationServer */
    protected $authorizationServer;

    /** @var AccessTokenRepository */
    protected $accessTokenRepository;

    /** @var RefreshTokenRepository */
    protected $refreshTokenRepository;

    public function __construct(
        Config $config = null,
        AuthorizationServer $authorizationServer = null,
        AccessTokenRepository $accessTokenRepository = null,
        RefreshTokenRepository $refreshTokenRepository = null
    ) {
        $this->config = $config ?? Di::_()->get('Config');
        $this->authorizationServer = $authorizationServer ?? Di::_()->get('OAuth\Server\Authorization');
        $this->accessTokenRepository = $accessTokenRepository ?? Di::_()->get('OAuth\Repositories\AccessToken');
        $this->refreshTokenRepository = $refreshTokenRepository ?? Di::_()->get('OAuth\Repositories\RefreshToken');
    }

    /**
     * @param ServerRequest $request
     * @return JsonResponse
     */
    public function authorize(ServerRequest $request): JsonResponse
    {
        /** @var User */
        $user = $request->getAttribute('_user');

        try {
            $authRequest = $this->authorizationServer->validateAuthorizationRequest($request);

            $userEntity = new UserEntity();
            $userEntity->setIdentifier($user->getGuid());

            $authRequest->setUser($userEntity);

            // If client is matrix, auto approve without asking user consent.
            if ($authRequest->getClient()->getIdentifier() === 'matrix') {
                $authRequest->setAuthorizationApproved(true);
            }
            
            // Return the HTTP redirect response
            return $this->authorizationServer->completeAuthorizationRequest($authRequest, new JsonResponse([]));
        } catch (OAuthServerException $e) {
            return $e->generateHttpResponse(new JsonResponse([]));
        }
    }

    /**
     * @param ServerRequest $request
     * @return JsonResponse
     */
    public function token(ServerRequest $request): JsonResponse
    {
        $response = new JsonResponse([]);

        try {
            $response = $this->authorizationServer->respondToAccessTokenRequest($request, $response);
            $body = json_decode($response->getBody(), true);
            $body['status'] = 'success';
            $response = new JsonResponse($body);
        } catch (OAuthServerException $e) {
            \Sentry\captureException($e);
            $response = $e->generateHttpResponse($response);
        } catch (\Exception $exception) {
            $body = [
                'status' => 'error',
                'error' => $exception->getMessage(),
                'message' => $exception->getMessage(),
                'errorId' => str_replace('\\', '::', get_class($exception)),
            ];
            $response = new JsonResponse($body);
        }

        return $response;
    }

    /**
     * @param ServerRequest $request
     * @return JsonResponse
     */
    public function revoke(ServerRequest $request): JsonResponse
    {
        $response = new JsonResponse([]);

        try {
            /** @var string */
            $token = $request->getParsedBody()['token'];

            /** @var string */
            $currentToken = $request->getAttribute('oauth_access_token_id');

            if ($currentToken !== $token) {
                throw new UserErrorException("Invalid token");
            }

            $this->accessTokenRepository->revokeAccessToken($token);
            $this->refreshTokenRepository->revokeRefreshToken($token);

            // remove surge token for push notifications.
            $user = $request->getAttribute('_user');
            
            $save = new Save();
            $save->setEntity($user)
              ->save();
            
            $response = new JsonResponse([]);
        } catch (\Exception $e) {
            \Sentry\captureException($e); // Log to sentry
            throw new UserErrorException($e->getMessage(), 500);
        }

        return $response;
    }

    /**
     * @param ServerRequest $request
     * @return JsonResponse
     */
    public function userinfo(ServerRequest $request): JsonResponse
    {
        /** @var User */
        $user = $request->getAttribute('_user');
        
        return new JsonResponse([
            'sub' => (string) $user->getGuid(),
            'name' => $user->getName(),
            'username' => $user->getUsername(),
        ]);
    }

    /**
     * @param ServerRequest $request
     * @return JsonResponse
     */
    public function jwks(ServerRequest $request): JsonResponse
    {
        $pem = file_get_contents($this->config->get('oauth')['public_key']);

        $options = [
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => hash('sha512', $pem),
        ];

        $keyFactory = new \Strobotti\JWK\KeyFactory();
        $key = $keyFactory->createFromPem($pem, $options);

        return new JsonResponse([
            'keys' => [
                json_decode((string) $key, true)
            ]
        ]);
    }

    /**
     * @param ServerRequest $request
     * @return JsonResponse
     */
    public function getOpenIDConfiguration(ServerRequest $request): JsonResponse
    {
        $discovery = [
            'issuer' => $this->config->get('site_url'),
            'authorization_endpoint' =>  $this->config->get('site_url') . 'api/v3/oauth/authorize',
            'token_endpoint' => $this->config->get('site_url') . 'api/v3/oauth/token',
            'revocation_endpoint' => $this->config->get('site_url') . 'api/v3/oauth/revoke',
            // 'introspection_endpoint' => $this->config->get('site_url') . 'api/v3/oauth/introspect',
            'userinfo_endpoint' => $this->config->get('site_url') . 'api/v3/oauth/userinfo',
            'jwks_uri' => $this->config->get('site_url') . 'api/v3/oauth/jwks',
            'scopes_supported' => [
                'openid',
            ],
            'response_types_supported' => [
                'code',
                'token'
            ],
            'response_modes_supported' => [
                'query',
            ],
            'grant_types_supported' => [
                'authorization_code',
                'refresh_token',
            ],
            'token_endpoint_auth_methods_supported' => [
                'client_secret_basic',
                'client_secret_post'
            ],
            'subject_types_supported' => [
                'public'
            ],
            'id_token_signing_alg_values_supported' => [
                'RS256'
            ],
            'claim_types_supported' => [
                'normal'
            ],
            'claims_supported' => [
                'iss',
                'sub',
                'name',
                'username',
            ]
        ];

        return new JsonResponse($discovery, 200);
    }
}