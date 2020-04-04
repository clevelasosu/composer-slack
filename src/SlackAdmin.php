<?php
/**
 * This uses the Slack enterprise admin API.
 * https://api.slack.com/enterprise/workspaces
 *
 * 1. As an enterprise admin, create an app
 * 2. Under permissions, set the Redirect URL to http://localhost.  Then add these scopes:
 *    * admin.teams:read
 *    * admin.teams:write
 *    * admin.users:write
 * 3. Go to Manage Distribution and do what's necessary to Activate Public Distribution
 * 4. Go to the 'Shareable URL' and Authorize the app.  This will take you to a 404 error page.  That's ok
 * 5. Copy the 'code' attribute in the URL
 * 6. You will also need the Client ID and Client Secret from the Basic Information tab for the app
 * 7. Go to https://slack.com/api/oauth.access?code=$code&client_id=$client_id&client_secret=$client_secret
 *    Substituting your information for the variables
 *  8. The response should include an 'access-token' that starts with 'xoxp'.
 *    That is the token you need when instantiating this class
 */

namespace OSUCOE\Slack;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use OSUCOE\Slack\Exceptions\SlackAdminException;

class SlackAdmin
{
    /**
     * @var Client
     */
    protected $client;

    protected $token;

    public $cache;
    public $itemLimit = 100;
    public $stats = [
        'requests' => [
            'total' => 0,
            'byMethod' => [
                'GET' => 0,
                'POST' => 0,
                'PATCH' => 0,
                'PUT' => 0,
                'DELETE' => 0,
            ],
        ],
    ];
    
    /**
     * SlackAdmin constructor.
     * @param Client $client
     * @param $token
     */
    public function __construct(Client $client, $token)
    {
        $this->client = $client;
        $this->token = $token;
    }

    /**
     * @param string $method
     * @param string $url
     * @param string $json
     * @param bool|null $returnBody
     * @return bool|mixed
     * @throws SlackAdminException
     */
    private function _request($method, $url, $json = '', bool $returnBody = null)
    {
        $method = strtoupper($method);
        $validMethods = ['GET', 'POST', 'DELETE', 'PATCH', 'PUT'];

        if (!in_array($method, $validMethods)) {
            throw new SlackAdminException("Invalid Method: $method");
        }

        if (!isset($returnBody) AND $method == "GET") {
            $returnBody = true;
        }

        $request = new Request($method, $url, [], $json);
        try {
            $res = $this->client->send($request);
            $this->stats['requests']['total'] += 1;
            $this->stats['requests']['byMethod'][$method] += 1;
            $body = json_decode((string)$res->getBody());

            if (!$body->ok) {
                throw new SlackAdminException($body->error);
            }

        } catch (BadResponseException $e) {
            $res = $e->getResponse();
            $body = json_decode($res->getBody()->getContents());

            if (isset($body->Errors)) {
                throw new SlackAdminException($body->Errors->description, $body->Errors->code, $e);
            } else {
                throw new SlackAdminException($e->getMessage(), $e->getCode(), $e);
            }
        } catch (GuzzleException $e) {
            throw new SlackAdminException($e->getMessage(), $e->getCode(), $e);
        } catch (\Exception $e) {
            throw new SlackAdminException($e->getMessage(), $e->getCode(), $e);
        }

        if ($returnBody) {
            return $body;
        } else {
            return true;
        }
    }

    /**
     * Lists the owners of the workspace
     * https://api.slack.com/methods/admin.teams.owners.list
     *
     * @param string $teamId Team ID (TQ*)
     * @return array Array of user IDs (WC*)
     */
    public function getTeamOwners($teamId)
    {

        $owners = [];
        $end = false;
        $limit = $this->itemLimit;
        $next_cursor = false;

        $query = [
            'token' => $this->token,
            'team_id' => $teamId,
            'limit' => $limit,
        ];

        do {
            if ($next_cursor) {
                $query['cursor'] = $next_cursor;
            }

            $body = $this->_request('GET', "admin.teams.owners.list?" . http_build_query($query));

            if (count($body->owner_ids)) {
                $owners = array_merge($owners, $body->owner_ids);
            } else {
                $end = true;
                break;
            }

            if ($body->response_metadata->next_cursor) {
                $next_cursor = $body->response_metadata->next_cursor;
            } else {
                $end = true;
            }


        } while (!$end);

        if (count($owners)) {
            return $owners;
        } else {
            return [];
        }
    }

    /**
     * Lists the administrators of the workspace.  Includes owners.
     * https://api.slack.com/methods/admin.teams.admins.list
     *
     * @param string $teamId Team ID (TQ*)
     * @return array Array of User IDs (WC*)
     */
    public function getTeamAdmins($teamId)
    {
        $admins = [];
        $end = false;
        $limit = $this->itemLimit;
        $next_cursor = false;

        $query = [
            'token' => $this->token,
            'team_id' => $teamId,
            'limit' => $limit,
        ];

        do {
            if ($next_cursor) {
                $query['cursor'] = $next_cursor;
            }

            $body = $this->_request('GET', "admin.teams.admins.list?" . http_build_query($query));

            if (count($body->admin_ids)) {
                $admins = array_merge($admins, $body->admin_ids);
            } else {
                break;
            }

            if ($body->response_metadata->next_cursor) {
                $next_cursor = $body->response_metadata->next_cursor;
            } else {
                $end = true;
            }


        } while (!$end);

        if (count($admins)) {
            return $admins;
        } else {
            return [];
        }
    }

    public function getTeams()
    {

        $teams = [];
        $end = false;
        $limit = $this->itemLimit;
        $next_cursor = false;

        $query = [
            'token' => $this->token,
            'limit' => $limit,
        ];

        do {
            if ($next_cursor) {
                $query['cursor'] = $next_cursor;
            }

            $body = $this->_request('GET', "admin.teams.list?" . http_build_query($query));

            if (count($body->teams)) {
                $teams = array_merge($teams, $body->teams);
            } else {
                $end = true;
                break;
            }

            if ($body->response_metadata->next_cursor) {
                $next_cursor = $body->response_metadata->next_cursor;
            } else {
                $end = true;
            }


        } while (!$end);

        if (count($teams)) {
            return $teams;
        } else {
            return [];
        }
    }
    /**
     * Creates a workspace
     * https://api.slack.com/methods/admin.teams.create
     *
     * @param string $domain First part of the URL for the team
     * @param string $name The formal name of the workspace
     * @param string $description Description of the workspace
     * @param string $discoverability (open|closed|invite_only|unlisted)
     * @return string Team ID of the new workspace (TQ*)
     */
    public function createTeam($domain, $name, $description, $discoverability = 'unlisted')
    {
        if (!$this->isValidDiscoverability($discoverability)) {
            throw new SlackAdminException("Invalid discoverability option given");
        }

        if (!$this->isValidTeamDomain($domain)) {
            throw new SlackAdminException("Invalid team domain");
        }

        if (!$this->isValidTeamName($name)) {
            throw new SlackAdminException("Invalid team name");
        }

        if ($description AND !$this->isValidTeamDescription($description)) {
            throw new SlackAdminException("Invalid team description");
        }

        $query = [
            'token' => $this->token,
            'team_domain' => $domain,
            'team_name' => $name,
            'team_description' => $description,
            'team_discoverability' => $discoverability,
        ];

        $body = $this->_request('POST', "admin.teams.create?" . http_build_query($query), '', true);

        if ($body->team) {
            return $body->team;
        } else {
            // Not sure why it would fail here, but check anyway
            throw new SlackAdminException('Unknown error creating team');
        }
    }

    /**
     * Checks to make sure the valid given is a valid discoverability setting
     * https://api.slack.com/methods/admin.teams.create
     *
     * @param string $discoverability
     * @return bool
     */
    public function isValidDiscoverability($discoverability)
    {
        $validDiscoverability = [
            'open', 'closed', 'invite_only', 'unlisted'
        ];
        if (!in_array($discoverability, $validDiscoverability)) {
            return false;
        }
        return true;
    }

    /**
     * Is the team name valid
     * @param string $domain
     * @return bool
     */
    public function isValidTeamDomain($domain)
    {
        // https://forums.asp.net/t/918584.aspx?REGEX+password+must+contain+letters+a+zA+Z+and+at+least+one+digit+0+9
        // (?=.*[a-z]) => Must contain one letter
        // [0-9a-z-]{1,21} => digits, letters or hyphens, 1 - 21 characters long
        $regex = "/^(?=.*[a-z])[0-9a-z-]{1,21}$/";
        if (preg_match($regex, $domain)) {
            return true;
        }
        return false;
    }

    /**
     * Is is a valid team ID
     *
     * @param string $teamId
     * @return bool
     */
    public function isValidTeamId($teamId)
    {
        $regex = "/^T[0-9a-zA-Z]{1,12}$/";
        if (preg_match($regex, $teamId)) {
            return true;
        }
        return false;
    }


    /**
     * Is the team name valid
     *
     * @param string $name
     * @return bool
     */
    public function isValidTeamName($name)
    {
        if (strlen($name) > 0 AND strlen($name) < 256) {
            return true;
        }
        return false;
    }

    /**
     * Is the team description valid
     *
     * @param string $name
     * @return bool
     */
    public function isValidTeamDescription($description)
    {
        if (strlen($description) > 0 AND strlen($description) < 256) {
            return true;
        }
        return false;
    }

    /**
     * Adds a user to a workspace
     * https://api.slack.com/methods/admin.users.assign
     *
     * @param string $userId User ID (WC*)
     * @param string $teamId Team ID (TQ*)
     * @return bool
     * @throws SlackAdminException
     */
    public function assignUserToTeam($userId, $teamId)
    {
        $query = [
            'token' => $this->token,
            'team_id' => $teamId,
            'user_id' => $userId,
        ];

        $body = $this->_request('POST', "admin.users.assign?".http_build_query($query));

        // if the request doesn't return true, it will throw an error
        return true;
    }

    /**
     * Removes a user from a workspace
     * https://api.slack.com/methods/admin.users.remove
     *
     * @param string $userId
     * @param string $teamId
     * @return bool
     */
    public function removeUserFromTeam($userId, $teamId)
    {
        $query = [
            'token' => $this->token,
            'team_id' => $teamId,
            'user_id' => $userId,
        ];

        $body = $this->_request('POST', "admin.users.remove?".http_build_query($query));
        return true;

    }

    /**
     * Sets a user as admin of the workspace
     * https://api.slack.com/methods/admin.users.setAdmin
     *
     * @param string $userId User ID (WC*)
     * @param string $teamId Team ID (TQ*)
     * @return bool
     */
    public function setUserAsAdmin($userId, $teamId)
    {
        $query = [
            'token' => $this->token,
            'team_id' => $teamId,
            'user_id' => $userId,
        ];

        $body = $this->_request('POST', "admin.users.setAdmin?".http_build_query($query));

        return true;
    }

    /**
     * Sets a user as owner of the workspace
     * https://api.slack.com/methods/admin.users.setOwner
     *
     * @param string $userId User ID (WC*)
     * @param string $teamId Team ID (TQ*)
     * @return bool
     */
    public function setUserAsOwner($userId, $teamId)
    {
        $query = [
            'token' => $this->token,
            'team_id' => $teamId,
            'user_id' => $userId,
        ];

        $body = $this->_request('POST', "admin.users.setOwner?".http_build_query($query));
        return true;

    }

    /**
     * Sets a user back to a regular user
     * https://api.slack.com/methods/admin.users.setRegular
     *
     * @param string $userId User ID (WC*)
     * @param string $teamId Team ID (TQ*)
     * @return bool
     */
    public function setUserAsRegular($userId, $teamId)
    {
        $query = [
            'token' => $this->token,
            'team_id' => $teamId,
            'user_id' => $userId,
        ];

        $body = $this->_request('POST', "admin.users.setRegular?".http_build_query($query));
        return true;

    }

    /**
     * Resets a user session, leaving the user unauthenticated
     * https://api.slack.com/methods/admin.users.session.reset
     *
     * @param $userId
     * @param bool $mobileOnly
     * @param bool $webOnly
     * @return bool
     */
    public function resetUserSession($userId, bool $mobileOnly = false, bool $webOnly = false)
    {
        if ($mobileOnly AND $webOnly) {
            throw new SlackAdminException('Cannot specify both web and mobile');
        }

        $query = [
            'token' => $this->token,
            'user_id' => $userId,
            'mobile_only' => $mobileOnly,
            'web_only' => $webOnly,
        ];

        $body = $this->_request('POST', "admin.users.session.reset?".http_build_query($query));
        return true;

    }



}