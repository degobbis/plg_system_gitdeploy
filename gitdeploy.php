<?php
/**
 * GitDeploy Plugin
 *
 * @copyright  Copyright (C) 2020 Tobias Zulauf All rights reserved.
 * @license    http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License Version 2 or Later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;

/**
 * Plugin class for GitDeploy
 *
 * @since  1.0
 */
class plgSystemGitDeploy extends CMSPlugin
{
	/**
	 * Affects constructor behavior. If true, language files will be loaded automatically.
	 *
	 * @var    boolean
	 * @since  1.0
	 */
	protected $autoloadLanguage = true;

	/**
	 * Application object.
	 *
	 * @var    CMSApplication
	 * @since  1.0
	 */
	protected $app;

	/**
	 * Listener for the `onAfterRoute` event
	 *
	 * @return  void
	 *
	 * @since   1.0
	 * @throws  ErrorException
	 */
	public function onAfterRoute()
	{
		if ($this->app->input->getCmd('github', false))
		{
			set_error_handler(
				function($severity, $message, $file, $line)
				{
					throw new \ErrorException($message, 0, $severity, $file, $line);
				}
			);

			set_exception_handler(
				function($e)
				{
					header('HTTP/1.1 500 Internal Server Error');
					echo "Error on line {$e->getLine()}: " . htmlspecialchars($e->getMessage());
					$this->app->close();
				}
			);

			$hookSecret = $this->params->get('hookSecret', '');

			if ($this->params->get('checkHookSecret', 1) && !empty($hookSecret))
			{
				$this->checkSecret($hookSecret);
			}

			$this->checkContentType();
			$this->setPayload();
			$this->handleGitHubEvent();

			$this->app->close();
		}
	}

	/**
	 * Method to check the secret
	 *
	 * @param   array  $hookSecret  The seecret to check
	 *
	 * @return  void
	 *
	 * @since   1.0
	 * @throws  Exception
	 */
	protected function checkSecret($hookSecret)
	{
		if (!$this->app->input->server->get('HTTP_X_HUB_SIGNATURE', false))
		{
			throw new \Exception("HTTP header 'X-Hub-Signature' is missing.");
		}

		if (!extension_loaded('hash'))
		{
			throw new \Exception("Missing 'hash' extension to check the secret code validity.");
		}

		list($algo, $hash) = explode('=', $this->app->input->server->get('HTTP_X_HUB_SIGNATURE'), 2) + array('', '');

		if (!in_array($algo, hash_algos(), TRUE))
		{
			throw new \Exception("Hash algorithm '$algo' is not supported.");
		}

		$this->rawPost = file_get_contents('php://input');

		if ($hash !== hash_hmac($algo, $this->rawPost, $hookSecret))
		{
			throw new \Exception('Hook secret does not match.');
		}
	}

	/**
	 * Method to check the contentype
	 *
	 * @return  void
	 *
	 * @since   1.0
	 * @throws  Exception
	 */
	protected function checkContentType()
	{
		if (!$this->app->input->server->get('CONTENT_TYPE', false))
		{
			throw new \Exception("Missing HTTP 'Content-Type' header.");
		}
		elseif (!$this->app->input->server->get('HTTP_X_GITHUB_EVENT', false))
		{
			throw new \Exception("Missing HTTP 'X-Github-Event' header.");
		}
	}

	/**
	 * Method to check the contentype
	 *
	 * @return  void
	 *
	 * @since   1.0
	 * @throws  Exception
	 */
	protected function setPayload()
	{
		switch ($this->app->input->server->get('CONTENT_TYPE'))
		{
			case 'application/json':
				$json = $this->rawPost ?: file_get_contents('php://input');
				break;

			case 'application/x-www-form-urlencoded':
				$json = $this->app->input->post->get('payload');
				break;

			default:
				throw new \Exception('Unsupported content type: ' . $this->app->input->server->get('HTTP_CONTENT_TYPE'));
		}

		$this->payload = json_decode($json);
	}

	/**
	 * Method to handle the GitHub event
	 *
	 * @return  void
	 *
	 * @since   1.0
	 * @throws  Exception
	 */
	protected function handleGitHubEvent()
	{
		$payload = $this->app->input->post->get('payload');
		$githubEvent = $this->app->input->server->get('HTTP_X_GITHUB_EVENT');

		switch (strtolower($githubEvent))
		{
			case 'ping':
				$this->sendNotificationMessage('Github Ping', '<pre>'. print_r($payload) .'</pre>');
				break;

			case 'push':
				try
				{
					$this->runGitPull($payload);
				}
				catch (Exception $e)
				{
					$this->sendNotificationMessage($e->getMessage());
				}
				break;

			default:
				header('HTTP/1.0 404 Not Found');
				echo 'Event: ' . $githubEvent . ' Payload: \n' . $payload;
				$this->app->close();
		}
	}

	/**
	 * Method to rund the git pull command
	 *
	 * @return  void
	 *
	 * @since   1.0
	 * @throws  Exception
	 */
	protected function runGitPull($payload)
	{
		$git    = (string) $this->params->get('git', 'master');
		$repo   = (string) $this->params->get('repo', 'master');
		$branch = (string) $this->params->get('branch', 'master');
		$remote = (string) $this->params->get('remote', 'master');

		if ($payload->repository->url === 'https://github.com/' . $repo
			&& $payload->ref === 'refs/heads/' . $branch)
		{
			$output = shell_exec($git . ' pull ' . $remote . ' ' . $branch . ' 2>&1; echo $?');

			// prepare and send the notification email
			if ($this->params->get('sendNotifications', 0))
			{
				$commitsHtml .= '<ul>';

				foreach ($payload->commits as $commit)
				{
					$commitsHtmlLine .= Text::_('PLG_SYSTEM_GITDEPLOY_MESSAGE_BODY_COMMITS_LINE');

					// Replace the variables
					$commitsHtmlLine = str_replace('{commitMessage}', $commit->message, $commitsHtmlLine);
					$commitsHtmlLine = str_replace('{commitAdded}', count($commit->added), $commitsHtmlLine);
					$commitsHtmlLine = str_replace('{commitModified}', count($commit->modified), $commitsHtmlLine);
					$commitsHtmlLine = str_replace('{commitRemoved}', count($commit->removed), $commitsHtmlLine);
					$commitsHtmlLine = str_replace('{commitUrl}', $commit->url, $commitsHtmlLine);

					$commitsHtml .= $commitsHtmlLine;
				}

				$commitsHtml .= '</ul>';

				$messageData['pusherName'] = $payload->pusher->name;
				$messageData['repoUrl'] = $payload->repository->url;
				$messageData['currentSite'] = Uri::base();
				$messageData['commitsHtml'] = $commitsHtml;
				$messageData['gitOutput'] = nl2br($output);

				$this->sendNotificationMessage($messageData, Text::_('PLG_SYSTEM_GITDEPLOY_MESSAGE_BODY'));
			}

			return true;
		}
	}

	/**
	 * Send the Notifications to the configured notification providers
	 *
	 * @param   string  $message      The message to be sended out
	 * @param   array   $messageData  The array of messagedata to be replaced
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	private function sendNotificationMessage($message, $messageData = [])
	{
		foreach ($messageData as $key => $value)
		{
			$message = str_replace('{' . $key . '}', $value, $message);
		}

		$http = HttpFactory::getHttp();
		$notificationProvider = $this->params->get('notificationProvider', []);

		foreach ($notificationProvider as $provider)
		{
			if ($provider === 'glip')
			{
				if (isset($messageData['currentSite']))
				{
					$data['activity'] = 'GitDeploy for '. $messageData['currentSite'];
				}

				$data['body'] = $this->convertHtmlToMarkdown($message);
				$data['title'] = 'Github Webhook Endpoint';

				$http->post($this->params->get('glipWebhook'), $data);
			}
			if ($provider === 'slack')
			{
				$data = [
					'payload' => json_encode(
						[
							'username' => $this->params->get('slackUsername'),
							'text'     => $message,
						]
					)
				];

				$http->post($this->params->get('slackWebhook'), $data);
			}

			if ($provider === 'mattermost')
			{
				$data = [
					'payload' => json_encode(
						[
							'text' => $message,
						]
					)
				];

				$http->post($this->params->get('mattermostWebhook'), $data);
			}

			if ($provider === 'telegram')
			{
				$data = [
					'chat_id'                  => $this->params->get('telegramChatId'),
					'parse_mode'               => 'HTML',
					'disable_web_page_preview' => 'true',
					'text'                     => $message,
				];

				$http->post('https://api.telegram.org/bot' . $this->params->get('telegramBotToken') . '/sendMessage', $data);
			}
		}
	}

	/**
	 * Converts the following tags from html to markdown: a, p, ul, li, strong, small, br, pre
	 *
	 * @param   string  $message      The message to be sended out
	 * @param   array   $messageData  The array of messagedata to be replaced
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	private function convertHtmlToMarkdown($htmlContent)
	{
		// Strip all tags other than the supported tags
		$markdown = strip_tags($htmlContent, '<a><p><ul><li><strong><small><br><pre>');

		// Replace sequences of invisible characters with spaces
		$markdown = preg_replace('~\s+~u', ' ', $markdown);

		// a
		$markdown = str_replace(" target='_blank' rel='noopener noreferrer'", '', $markdown);

		// Escape the following characters: '*', '_', '[', ']' and '\'
		$markdown = preg_replace('~([*_\\[\\]\\\\])~u', '\\\\$1', $markdown);
		$markdown = preg_replace('~^#~u', '\\\\#', $markdown);

		// https://stackoverflow.com/questions/18563753/getting-all-attributes-from-an-a-html-tag-with-regex
		preg_match_all('/<a(?:\s+(?:href=["\'](?P<href>[^"\'<>]+)["\']|title=["\'](?P<title>[^"\'<>]+)["\']|\w+=["\'][^"\'<>]+["\']))+/i', $markdown, $urlsMatch);
		$urls = array_unique($urlsMatch['href']);

		foreach ($urls as $id => $url)
		{
			$title = $urlsMatch['title'][$id];
			$markdownLink = '[' . $title .'](' . $url . ')';
			$markdown = str_replace("<a href='" . $url . "' title='" . $title . "'", $markdownLink, $markdown);
			$markdown = str_replace('>' . $title . '</a>', '', $markdown);
		}

		// Tag: p
		$markdown = str_replace('<p>', '', $markdown);
		$markdown = str_replace('</p>', '\n', $markdown);

		// Tag: ul
		$markdown = str_replace('<ul>', '', $markdown);
		$markdown = str_replace('</ul>', '\n', $markdown);

		// Tag: li
		$markdown = str_replace('<li>', '- ', $markdown);
		$markdown = str_replace('</li>', '\n', $markdown);

		// Tag: strong
		$markdown = str_replace('<strong>', '**', $markdown);
		$markdown = str_replace('</strong>', '**', $markdown);

		// Tag: small
		$markdown = str_replace('<small>', '<sub><sup>', $markdown);
		$markdown = str_replace('</small>', '<sub><sup>', $markdown);

		// Tag: br
		$markdown = str_replace('<br>', '\n', $markdown);

		// Tag: pre
		$markdown = str_replace('<pre>', '```\n', $markdown);
		$markdown = str_replace('</pre>', '```\n\n', $markdown);

		// Remove leftover \n at the beginning of the line
		$markdown = ltrim($markdown, "\n");

		return htmlspecialchars($markdown, ENT_NOQUOTES, 'UTF-8');
	}
}
