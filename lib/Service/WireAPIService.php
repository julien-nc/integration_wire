<?php
/**
 * Nextcloud - Wire
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2022
 */

namespace OCA\Wire\Service;

use Datetime;
use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use OC\Files\Node\File;
use OC\Files\Node\Folder;
use OC\User\NoUserException;
use OCA\Wire\AppInfo\Application;
use OCP\Constants;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\PreConditionNotMetException;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use OCP\Http\Client\IClientService;
use OCP\Share\IManager as ShareManager;
use Throwable;

class WireAPIService {
	/**
	 * @var LoggerInterface
	 */
	private $logger;
	/**
	 * @var IL10N
	 */
	private $l10n;
	/**
	 * @var \OCP\Http\Client\IClient
	 */
	private $client;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var IRootFolder
	 */
	private $root;
	/**
	 * @var ShareManager
	 */
	private $shareManager;
	/**
	 * @var IURLGenerator
	 */
	private $urlGenerator;

	/**
	 * Service to make requests to Wire API
	 */
	public function __construct (string $appName,
								LoggerInterface $logger,
								IL10N $l10n,
								IConfig $config,
								IRootFolder $root,
								ShareManager $shareManager,
								IURLGenerator $urlGenerator,
								IClientService $clientService) {
		$this->logger = $logger;
		$this->l10n = $l10n;
		$this->client = $clientService->newClient();
		$this->config = $config;
		$this->root = $root;
		$this->shareManager = $shareManager;
		$this->urlGenerator = $urlGenerator;
	}

	/**
	 * @param string $userId
	 * @param string $domain
	 * @param string $wireUserId
	 * @return array
	 * @throws PreConditionNotMetException
	 */
	public function getUserAvatar(string $userId, string $domain, string $wireUserId): array {
		error_log('1111');
		$userInfo = $this->request($userId, 'users/' . $domain . '/' . $wireUserId);
		if (isset($userInfo['error'])) {
			return $userInfo;
		}
		$asset = null;
		foreach ($userInfo['assets'] as $a) {
			if ($a['type'] === 'image' && $a['size'] === 'complete') {
				$asset = $a;
				break;
			}
		}
		if ($asset === null) {
			return ['userInfo' => $userInfo];
		}
		error_log('IMAGE : ' . 'assets/' . $domain . '/' . $asset['key']);
		$image = $this->request($userId, 'assets/' . $domain . '/' . $asset['key'], [], 'GET', false);
		if (isset($image['body'])) {
			return ['avatarContent' => $image['body']];
		}
		error_log('ERROR '. json_encode($image));
		return ['userInfo' => $userInfo];
	}

	/**
	 * @param string $userId
	 * @param string $domain
	 * @param string $teamId
	 * @return array
	 * @throws PreConditionNotMetException
	 */
	public function getTeamAvatar(string $userId, string $domain, string $teamId): array {
		$image = $this->request($userId, 'assets/' . $domain . '/' . $teamId, [], 'GET', false);
		if (isset($image['body'])) {
			return ['avatarContent' => $image['body']];
		}

		$teamInfo = $this->request($userId, 'teams/' . $teamId);
		return ['teamInfo' => $teamInfo];
	}

	/**
	 * @param string $userId
	 * @return array|string[]
	 * @throws Exception
	 */
	public function getMyConversationsPerId(string $userId): array {
		$result = $this->request($userId, 'conversations');
		if (isset($result['error'])) {
			return $result;
		}
		$perId = [];
		foreach ($result['conversations'] as $conversation) {
			$perId[$conversation['id']] = $conversation;
		}
		return $perId;
	}

	/**
	 * @param string $userId
	 * @return array|string[]
	 * @throws Exception
	 */
	public function getMyTeamsPerId(string $userId): array {
		$result = $this->request($userId, 'teams');
		if (isset($result['error'])) {
			return $result;
		}
		$perId = [];
		foreach ($result['teams'] as $teams) {
			$perId[$teams['id']] = $teams;
		}
		return $perId;
	}

	/**
	 * @param string $userId
	 * @return array|string[]
	 * @throws Exception
	 */
	public function getMyConversations(string $userId): array {
		$result = $this->request($userId, 'conversations');
		if (isset($result['error'])) {
			return $result;
		}
		$teamsPerId = $this->getMyTeamsPerId($userId);
		if (isset($teamsPerId['error'])) {
			return $result;
		}
		$conversations = [];
		foreach ($result['conversations'] as $conversation) {
			if ($conversation['team'] !== null) {
				$conversation['team_name'] = $teamsPerId[$conversation['team']]['name'] ?? '??';
				if ($conversation['name'] === null
					&& isset($conversation['members'], $conversation['members']['others'])
					&& is_array($conversation['members']['others'])
					&& count($conversation['members']['others']) === 1
				) {
					$member = $conversation['members']['others'][0];
					$domain = $member['qualified_id']['domain'];
					$wireUserId = $member['qualified_id']['id'];
					$userInfo = $this->request($userId, 'users/' . $domain . '/' . $wireUserId);
					if (!isset($userInfo['error']) && isset($userInfo['name'])) {
						$conversation['name'] = $userInfo['name'];
					}
				}
				$conversations[] = $conversation;
			}
		}
		return $conversations;
	}

	/**
	 * @param string $userId
	 * @param array $fileIds
	 * @param string $conversationDomain
	 * @param string $conversationId
	 * @param string $conversationName
	 * @param string $comment
	 * @param string $permission
	 * @param string|null $expirationDate
	 * @param string|null $password
	 * @return array|string[]
	 * @throws NoUserException
	 * @throws NotPermittedException
	 * @throws PreConditionNotMetException
	 */
	public function sendLinks(string $userId, array $fileIds,
							  string $conversationDomain, string $conversationId,
							  string $conversationName, array $conversationMembers,
							  string $comment, string $permission, ?string $expirationDate = null, ?string $password = null): array {
		$links = [];
		$userFolder = $this->root->getUserFolder($userId);

		// create public links
		foreach ($fileIds as $fileId) {
			$nodes = $userFolder->getById($fileId);
			// if (count($nodes) > 0 && $nodes[0] instanceof File) {
			if (count($nodes) > 0 && ($nodes[0] instanceof File || $nodes[0] instanceof Folder)) {
				$node = $nodes[0];

				$share = $this->shareManager->newShare();
				$share->setNode($node);
				if ($permission === 'edit') {
					$share->setPermissions(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);
				} else {
					$share->setPermissions(Constants::PERMISSION_READ);
				}
				$share->setShareType(IShare::TYPE_LINK);
				$share->setSharedBy($userId);
				$share->setLabel('Wire (' . $conversationName . ')');
				if ($expirationDate !== null) {
					$share->setExpirationDate(new Datetime($expirationDate));
				}
				if ($password !== null) {
					$share->setPassword($password);
				}
				$share = $this->shareManager->createShare($share);
				if ($expirationDate === null) {
					$share->setExpirationDate(null);
					$this->shareManager->updateShare($share);
				}
				$token = $share->getToken();
				$linkUrl = $this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->linkToRoute('files_sharing.Share.showShare', [
						'token' => $token,
					])
				);
				$links[] = [
					'name' => $node->getName(),
					'url' => $linkUrl,
				];
			}
		}

		if (count($links) > 0) {
			$message = $comment . "\n";
			foreach ($links as $link) {
				$message .= '```' . $link['name'] . '```: ' . $link['url'] . "\n";
			}
			return $this->sendMessage($userId, $message, $conversationDomain, $conversationId, $conversationMembers);
		} else {
			return ['error' => 'Files not found'];
		}
	}

	/**
	 * @param string $userId
	 * @param string $message
	 * @param string $conversationDomain
	 * @param string $conversationId
	 * @return array|string[]
	 * @throws PreConditionNotMetException
	 */
	public function sendMessage(string $userId, string $message,
								string $conversationDomain, string $conversationId, array $conversationMembers): array {
		error_log('MEMEMEMEMEMEMEMEME '.json_encode($conversationMembers));
		$myUserId = $this->config->getUserValue($userId, Application::APP_ID, 'user_id');
		// TODO generate an encrypted message for all target user's devices
		// concept briefly explained there: https://docs.wire.com/understand/federation/api.html?highlight=send#message-sending-a
		// not enough information in https://staging-nginz-https.zinfra.io/api/swagger-ui/#/default/post_conversations__cnv_domain___cnv__proteus_messages
		$body = [
			'data' => $message,
    		'blob' => $message,
			'sender' => $myUserId, // sender client ID
			// otr style
			'recipients' => [
				'additionalProp1' => [
				  'additionalProp1' => 'string',
				  'additionalProp2' => 'string',
				  'additionalProp3' => 'string',
				],
			],
			'report_missing' => [
				'99db9768-04e3-4b5d-9268-831b6a25c4ab',
			],
    		'native_push' => true, // optional type bool
    		'native_priority' => 'low', // optional type Priority
    		'transient' => true, // optional type bool

			//// protobuf style
    		//'recipients' => [
			//	[
			//		'domain' => , // target domain
			//		'entries' => [
			//			[ // UserEntry
			//				'user' => , // UserId
			//				'clients' => [
			//					[ // ClientEntry
			//						'client' => , // ClientId
			//						'text' => , // bytes
			//					],
			//				],
			//			],
			//		],
			//	],
			//],
//			'client_mismatch_strategy' => 'report_all', // report_all || ignore_all || report_only || ignore_only
		];
//		$endpoint = 'conversations/' . $conversationDomain . '/' . $conversationId . '/proteus/messages';
//		$contentType = 'application/x-protobuf';
		$endpoint = 'conversations/' . $conversationId . '/otr/messages';
		$contentType = 'application/json;charset=utf-8';
		error_log('I SEND '.json_encode($body). ' TO '.$endpoint);
		return $this->request(
			$userId, $endpoint, [], 'POST',
			true, false, $contentType, json_encode($body)
		);
	}

	/**
	 * @param string $userId
	 * @param string $endPoint
	 * @param array $params
	 * @param string $method
	 * @param bool $jsonResponse
	 * @param bool $useCookie
	 * @param string $contentType
	 * @param string|null $requestBody
	 * @return array|mixed|resource|string|string[]
	 * @throws PreConditionNotMetException
	 */
	public function request(string $userId, string $endPoint, array $params = [], string $method = 'GET',
							bool $jsonResponse = true, bool $useCookie = false,
							string $contentType = 'application/json', ?string $requestBody = null): array {
		$adminUrl = $this->config->getAppValue(Application::APP_ID, 'url') ?: Application::DEFAULT_WIRE_API_URL;
		$url = $this->config->getUserValue($userId, Application::APP_ID, 'url', $adminUrl) ?: $adminUrl;

		$this->checkTokenExpiration($userId, $url);

		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		try {
			$url = $url . '/' . $endPoint;
			$options = [
				'headers' => [
					'Authorization'  => 'Bearer ' . $accessToken,
					'Content-Type' => $contentType,
					'User-Agent'  => Application::INTEGRATION_USER_AGENT,
				],
			];

			if ($useCookie) {
				$cookie = $this->config->getUserValue($userId, Application::APP_ID, 'cookie');
				$options['headers']['Cookie'] = 'zuid=' . $cookie;
			}

			if (count($params) > 0) {
				if ($method === 'GET') {
					// manage array parameters
					$paramsContent = '';
					foreach ($params as $key => $value) {
						if (is_array($value)) {
							foreach ($value as $oneArrayValue) {
								$paramsContent .= $key . '[]=' . urlencode($oneArrayValue) . '&';
							}
							unset($params[$key]);
						}
					}
					$paramsContent .= http_build_query($params);

					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = $requestBody === null
						? json_encode($params)
						: $requestBody;
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			} else {
				return ['error' => $this->l10n->t('Bad HTTP method')];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				if ($jsonResponse) {
					return json_decode($body, true);
				} else {
					return [
						'body' => $body,
						'headers' => $response->getHeaders(),
					];
				}
			}
		} catch (ServerException | ClientException $e) {
			$this->logger->warning('Wire API error : ' . $e->getMessage());
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @param string $userId
	 * @param string $url
	 * @return void
	 * @throws \OCP\PreConditionNotMetException
	 */
	private function checkTokenExpiration(string $userId, string $url): void {
		$cookie = $this->config->getUserValue($userId, Application::APP_ID, 'cookie');
		$expireAt = $this->config->getUserValue($userId, Application::APP_ID, 'token_expires_at');
		if ($cookie !== '' && $expireAt !== '') {
			$nowTs = (new Datetime())->getTimestamp();
			$expireAt = (int) $expireAt;
			// if token expires in less than a minute or is already expired
			if ($nowTs > $expireAt - 60) {
				$this->refreshToken($userId, $url);
			}
		}
	}

	/**
	 * @param string $userId
	 * @param string $url
	 * @return bool
	 * @throws \OCP\PreConditionNotMetException
	 */
	private function refreshToken(string $userId, string $url): bool {
		$cookie = $this->config->getUserValue($userId, Application::APP_ID, 'cookie');
		$token = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		if (!$cookie) {
			$this->logger->error('No Wire cookie found', ['app' => Application::APP_ID]);
			return false;
		}
		$result = $this->refreshTokenRequest($url, $cookie, $token);
		if (isset($result['access_token'], $result['expires_in'])) {
			$this->logger->info('Wire access token successfully refreshed', ['app' => Application::APP_ID]);
			$accessToken = $result['access_token'];
			$this->config->setUserValue($userId, Application::APP_ID, 'token', $accessToken);
			// we may get a new cookie
			if (isset($result['cookie'], $result['full-cookie'])) {
				$this->config->setUserValue($userId, Application::APP_ID, 'cookie', $result['cookie']);
				$this->config->setUserValue($userId, Application::APP_ID, 'full-cookie', $result['full-cookie']);
			}
			$nowTs = (new Datetime())->getTimestamp();
			$expiresAt = $nowTs + (int) $result['expires_in'];
			$this->config->setUserValue($userId, Application::APP_ID, 'token_expires_at', $expiresAt);
			return true;
		} else {
			// impossible to refresh the token
			$this->logger->error(
				'Token is not valid anymore. Impossible to refresh it.',
				['app' => Application::APP_ID]
			);
			return false;
		}
	}

	/**
	 * @param string $url
	 * @param array $params
	 * @param string $method
	 * @return array
	 */
	public function refreshTokenRequest(string $url, string $cookie, string $token): array {
		try {
			$url = $url . '/access';
			$options = [
				'headers' => [
					'User-Agent'  => Application::INTEGRATION_USER_AGENT,
					'Authorization' => 'Bearer ' . $token,
					'Cookie' => 'zuid=' . $cookie,
				],
			];

			$response = $this->client->post($url, $options);
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Access token refresh refused')];
			} else {
				$result = json_decode($body, true);
				$setCookieHeader = $response->getHeader('Set-Cookie');
				if ($setCookieHeader) {
					$cookie = preg_replace('/^zuid=/', '', $setCookieHeader);
					$cookie = preg_replace('/; Path=.*$/', '', $cookie);
					$result['cookie'] = $cookie;
					$result['full-cookie'] = $setCookieHeader;
				}
				return $result;
			}
		} catch (Exception $e) {
			$this->logger->warning('Wire OAuth error : '.$e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @param string $userId
	 * @param string $login
	 * @param string $password
	 * @return array
	 */
	public function login(string $userId, string $login, string $password): array {
		$adminUrl = $this->config->getAppValue(Application::APP_ID, 'url') ?: Application::DEFAULT_WIRE_API_URL;
		$url = $this->config->getUserValue($userId, Application::APP_ID, 'url', $adminUrl) ?: $adminUrl;
		try {
			$url = $url . '/login?persist=true';
			$options = [
				'headers' => [
					'User-Agent'  => Application::INTEGRATION_USER_AGENT,
					'Content-Type' => 'application/json',
				],
				'body' => json_encode([
					'email' => $login,
					'password' => $password,
					'persist' => true,
				]),
			];
			$response = $this->client->post($url, $options);
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Invalid credentials')];
			} else {
				$result = json_decode($body, true);
				$setCookieHeader = $response->getHeader('Set-Cookie');
				if (!$setCookieHeader) {
					return ['error' => $this->l10n->t('Invalid response')];
				}
				$cookie = preg_replace('/^zuid=/', '', $setCookieHeader);
				$cookie = preg_replace('/; Path=.*$/', '', $cookie);
				$result['cookie'] = $cookie;
				$result['full-cookie'] = $setCookieHeader;
				return $result;
			}
		} catch (Exception $e) {
			$this->logger->warning('Wire login error : ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		}
	}
}
