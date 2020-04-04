<?php
/**
 * Created by PhpStorm.
 * User: clevelas
 * Date: 9/27/18
 * Time: 3:34 PM
 */

namespace OSUCOE\Slack;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\BadResponseException;
use OSUCOE\Slack\Exceptions\SlackSCIMException;
use OSUCOE\Slack\Exceptions\SlackSCIMGroupNotFoundException;
use OSUCOE\Slack\Exceptions\SlackSCIMUserNotFoundException;

class SlackSCIM
{
    /**
     * @var Client
     */
    protected $client;
    /**
     * $this->cache['groups']['coe-it-staff']['id'] = $id
     * $this->cache['groups']['coe-it-staff']['members'] = ['clevelas','user2']
     * $this->cache['groups']['coe-it-staff']['details'] = []
     * $this->cache['groupsById'][$id] = $name
     * $this->cache['usersById'][$id] = $name
     * $this->cache['users']['clevelas']['id'] = $id
     * $this->cache['users']['clevelas']['groups'] = ['coe_it_staff']
     * $this->cache['users']['clevelas']['details'] = []
     * @var array
     */
    public $cache;
    public $pagecount = 1000;
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

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * All API calls and associated error handling run through this function
     *
     * @param string $method
     * @param string $url
     * @param string $json
     * @return bool|mixed
     * @throws SlackSCIMException
     */
    private function _request(string $method, string $url, string $json = '')
    {
        $method = strtoupper($method);
        $validMethods = ['GET', 'POST', 'DELETE', 'PATCH', 'PUT'];

        if (!in_array($method, $validMethods)) {
            throw new SlackSCIMException("Invalid Method: $method");
        }

        $returnBody = false;
        if ($method == "GET") {
            $returnBody = true;
        }

        $request = new Request($method, $url, [], $json);
        try {
            $res = $this->client->send($request);
            $this->stats['requests']['total'] += 1;
            $this->stats['requests']['byMethod'][$method] += 1;
            if ($returnBody) {
                $body = json_decode((string)$res->getBody());
            }
            $statusCode = $res->getStatusCode();
            if (!preg_match('/^2\d*/', $statusCode)) {
                throw new SlackSCIMException("Invalid response code: $statusCode");
            }
        } catch (BadResponseException $e) {
            $res = $e->getResponse();
            $body = json_decode($res->getBody()->getContents());

            if (isset($body->Errors)) {
                throw new SlackSCIMException($body->Errors->description, $body->Errors->code, $e);
            } else {
                throw new SlackSCIMException($e->getMessage(), $e->getCode(), $e);
            }
        } catch (\Exception $e) {
            throw new SlackSCIMException($e->getMessage(), $e->getCode(), $e);
        }

        if ($returnBody) {
            return $body;
        } else {
            return true;
        }
    }



    /**
     * Get a group object from Slack by displayName
     *
     * @param string $group
     * @return array
     * @throw SlackSCIMGroupNotFoundException
     */
    public function getGroupByName(string $group)
    {
        if (isset($this->cache['groups'][$group]['details'])) {
            return $this->cache['groups'][$group]['details'];
        }

        $body = $this->_request('GET',"Groups?filter=displayName eq \"$group\"");

        if ($body->totalResults != 1) {
            throw new SlackSCIMGroupNotFoundException('Invalid number of results');
        }

        $this->_cacheGroup($body->Resources[0]);

        return $body->Resources[0];
    }

    /**
     * Get a group object from Slack by ID
     *
     * @param string $id
     * @return array|bool|mixed
     * @throws SlackSCIMGroupNotFoundException
     */
    public function getGroupById(string $id)
    {
        if (isset($this->cache['groupsById'][$id])) {
            $group = $this->cache['groupsById'][$id];
            if (isset($this->cache['groups'][$group]['details'])) {
                return $this->cache['groups'][$group]['details'];
            }
        }

        $body = $this->_request('GET',"Groups/$id");

        if ($body->id != $id) {
            throw new SlackSCIMGroupNotFoundException('Group not found');
        }

        $this->_cacheGroup($body);

        return $body;
    }

    /**
     * Caches the group object
     *
     * @param $groupdata
     */
    private function _cacheGroup($groupdata)
    {
        $this->cache['groups'][$groupdata->displayName]['details'] = $groupdata;
        $this->cache['groups'][$groupdata->displayName]['id'] = $groupdata->id;
        $this->cache['groupsById'][$groupdata->id] = $groupdata->displayName;
    }

    /**
     * Gets a user object from Slack by ID
     *
     * @param string $id
     * @return array|bool|mixed
     * @throws SlackSCIMUserNotFoundException
     */
    public function getUserById(string $id, bool $force = null)
    {

        if (!$force AND isset($this->cache['usersById'][$id])) {
            $username = $this->cache['usersById'][$id];
            if (isset($this->cache['users'][$username]['details'])) {
                return $this->cache['users'][$username]['details'];
            }
        }

        $body = $this->_request('GET',"Users/$id");

        if ($body->id != $id) {
            throw new SlackSCIMUserNotFoundException("User not found");
        }

        $this->_cacheUser($body);

        return $body;
    }

    /**
     * Gets a user object from Slack by userName
     *
     * @param string $username
     * @param bool $force Bypasses cache
     * @return array
     */
    public function getUserByName(string $username, bool $force = false)
    {

        if (!$force AND isset($this->cache['users'][$username]['details'])) {
            return $this->cache['users'][$username]['details'];
        }

        $body = $this->_request('GET',"Users?filter=userName eq \"$username\"");

        if ($body->totalResults != 1) {
            throw new SlackSCIMUserNotFoundException("User not found");
        }

        $this->_cacheUser($body->Resources[0]);

        return $body->Resources[0];
    }

    /**
     * Caches user object
     *
     * @param $userdata
     */
    private function _cacheUser($userdata)
    {
        $this->cache['users'][$userdata->userName]['details'] = $userdata;
        $this->cache['users'][$userdata->userName]['id'] = $userdata->id;
        $this->cache['usersById'][$userdata->id] = $userdata->userName;
        foreach ($userdata->groups as $group) {
            $this->cache['users'][$userdata->userName]['groups'][] = $group->display;
        }
    }

    /**
     * Gets an array of users from Slack
     *
     * @return array
     */
    public function getUsers()
    {

        $start = 1;
        $count = $this->pagecount;
        $users = [];

        $end = false;

        do {

            $body = $this->_request('GET', "Users?startIndex=$start&count=$count");

            $totalResults = $body->totalResults;
            $itemsPerPage = $body->itemsPerPage;
            $startIndex = $body->startIndex;

            if ($startIndex + $itemsPerPage - 1 >= $totalResults) {
                $end = true;
            } else {
                $start = $startIndex + $count;
            }

            foreach ($body->Resources as $resource) {
                if ($resource->displayName) {
                    $users[] = $resource->displayName;
                } elseif ($resource->userName) {
                    $users[] = $resource->userName;
                } else {
                    continue;
                }
                $this->_cacheUser($resource);
            }
        } while(!$end);

        return $users;
    }

    /**
     * Gets an array of groups from Slack
     *
     * @return array
     */
    public function getGroups()
    {

        $start = 1;
        $count = $this->pagecount;
        $groups = [];

        $end = false;

        do {

            $body = $this->_request('GET', "Groups?startIndex=$start&count=$count");

            $totalResults = $body->totalResults;
            $itemsPerPage = $body->itemsPerPage;
            $startIndex = $body->startIndex;

            if ($startIndex + $itemsPerPage - 1 >= $totalResults) {
                $end = true;
            } else {
                $start = $startIndex + $count;
            }

            foreach ($body->Resources as $resource) {
                $groups[] = $resource->displayName;
                $this->_cacheGroup($resource);
            }
        } while(!$end);

        return $groups;
    }

    /**
     * Get array of group members
     *
     * @param string $group
     * @return array
     * @throws SlackSCIMException
     * @throws GuzzleException
     */
    public function getGroupMembers(string $group)
    {

        // this cache item is an array of onid usernames
        if (isset($this->cache['groups'][$group]['members'])) {
            echo "got from cache\n";
            return $this->cache['groups'][$group]['members'];
        }

        $detail = $this->getGroupByName($group);

        // an array of slack ids
        $encodedmembers = $detail->members;

        $members = [];
        foreach ($encodedmembers as $member) {
            $members[] = $this->getUserNameFromId($member->value);
        }

        $this->cache['groups'][$group]['members'] = $members;

        return $members;
    }

    /**
     * Gets the Slack ID of the group
     *
     * @param string $group
     * @return mixed
     * @throws SlackSCIMException
     */
    public function getGroupIdFromName(string $group)
    {
        if (isset($this->cache['groups'][$group]['id'])) {
            return $this->cache['groups'][$group]['id'];
        }

        $detail = $this->getGroupByName($group);

        return $detail->id;
    }

    /**
     * Gets the group name from the Slack ID
     *
     * @param string $id
     * @return mixed
     * @throws SlackSCIMException
     */
    public function getGroupNameFromId(string $id) {
        if (isset($this->cache['groupsById'][$id])) {
            return $this->cache['groupsById'][$id];
        }

        $detail = $this->getGroupById($id);
        return $detail->displayName;

    }

    /**
     * Creates an IDP group
     *
     * @param string $name
     * @param array $members
     * @return bool
     * @throws GuzzleException
     * @throws SlackSCIMException
     */
    public function createGroup(string $name, array $members=[])
    {
        $json = [
            "schemas" => [
                "urn:scim:schemas:core:1.0",
            ],
            "displayName" => $name,
        ];

        if (count($members) > 0) {
            try {
                $memberids = $this->convertMemberArrayToMemberIdArray($members);
            } catch (SlackSCIMUserNotFoundException $e) {
                throw new SlackSCIMException("Some users don't exist in Slack yet.  Create them first", 1, $e);
            } catch (\Exception $e) {
                throw new SlackSCIMException($e->getMessage(), $e->getCode(), $e);
            }

            foreach ($memberids as $memberid) {
                $json['members'][] = [
                    'value' => $memberid,
                ];
            }
        }

        $json = json_encode($json, JSON_PRETTY_PRINT);

        return $this->_request('POST','Groups', $json);

    }

    /**
     * Does a full replacement of group membership
     *
     * @param string $group
     * @param array $members
     * @param bool $membersAreUsernames
     * @return bool
     * @throws GuzzleException
     * @throws SlackSCIMException
     */
    public function replaceGroupMembers(string $group, array $members, bool $membersAreUsernames=true)
    {
        $id = $this->getGroupIdFromName($group);

        $json = [
            "schemas" => [
                "urn:scim:schemas:core:1.0",
            ],
            "displayName" => $group,
        ];

        if ($membersAreUsernames) {
            try {
                $memberIds = $this->convertMemberArrayToMemberIdArray($members);
            } catch (SlackSCIMUserNotFoundException $e) {
                throw new SlackSCIMException("Some users don't exist in Slack yet.  Create them first", 1, $e);
            } catch (\Exception $e) {
                throw new SlackSCIMException($e->getMessage(), $e->getCode(), $e);
            }
        } else {
            $memberIds = $members;
        }

        foreach ($memberIds as $memberId) {
            $json['members'][] = [
                'value' => $memberId,
            ];
        }

        $json = json_encode($json, JSON_PRETTY_PRINT);

        return $this->_request('PUT', "Groups/$id", $json);

    }

    /**
     * Deletes IDP group
     *
     * @param string $group
     * @return bool
     * @throws SlackSCIMException
     */
    public function deleteGroup(string $group)
    {
        $id = $this->getGroupIdFromName($group);

        try {
            return $this->_request('DELETE',"Groups/$id");
        } catch (SlackSCIMException $e) {
            if ($e->getMessage() == "Invalid number of results") {
                throw new SlackSCIMGroupNotFoundException("Group not found", 1);
            }
        }
    }

    /**
     * Takes an array of usernames and returns an array of Slack IDs
     * Will throw exception if a member doesn't exist.
     *
     * @param array $members
     * @return array
     * @throws GuzzleException
     * @throws SlackSCIMException
     */
    public function convertMemberArrayToMemberIdArray(array $members) {
        $memberIds = [];
        foreach ($members as $member) {
            $memberIds[] = $this->getUserIdFromName($member);
        }
        return $memberIds;
    }

    /**
     * Gets the username from the Slack ID
     *
     * @param string $id
     * @return string
     * @throws SlackSCIMException
     */
    public function getUserNameFromId(string $id)
    {
        if (isset($this->cache['usersById'][$id])) {
            return $this->cache['usersById'][$id];
        }

        $details = $this->getUserById($id);
        return $details->userName;
    }

    /**
     * Gets the Slack ID from the username
     *
     * @param string $username
     * @return string
     * @throws GuzzleException
     * @throws SlackSCIMException
     */
    public function getUserIdFromName(string $username)
    {
        $details = $this->getUserByName($username);
        return $details->id;
    }

    /**
     * Creates an enterprise user
     * @param string $username
     * @param string $email
     * @param string $fullName
     * @param string $photoUrl
     * @return bool
     * @throws SlackSCIMException
     */
    public function createUser(string $username, string $email, string $fullName = "", string $photoUrl = "")
    {
        $json = [
            "schemas" => [
                "urn:scim:schemas:core:1.0",
                "urn:scim:schemas:extension:enterprise:1.0"
            ],
            "userName" => $username,
            "emails" => [
                [
                    "value" => $email,
                ]
            ]
        ];

        if ($fullName) {
            $json['name'] = [
                'familyName' => $fullName,
            ];
        }

        if ($photoUrl) {
            $json['photos'] = [
                [
                    "type" => "photo",
                    "value" => $photoUrl,
                ],
            ];
        }

        $json = json_encode($json, JSON_PRETTY_PRINT);

        return $this->_request('POST','Users', $json);

    }

    /**
     * Patches given individual attributes.
     * first name and last name need to be given together or will be ignored
     *
     * @param string $username
     * @param string $email
     * @param string $fullName
     * @param string $photoUrl
     * @return bool
     * @throws GuzzleException
     * @throws SlackSCIMException
     */
    public function patchUser(string $username, string $email = "", string $fullName = "", string $photoUrl = "")
    {
        if (!$email AND !$fullName AND !$photoUrl) {
            throw new SlackSCIMException('No attributes to update.');
        }

        $id = $this->getUserIdFromName($username);

        $json = [
            'schemas' => [
                "urn:scim:schemas:core:1.0"
            ],
            'id' => $id,
        ];

        if ($email) {
            $json["emails"] = [
                [
                    "value" => $email,
                ]
            ];
        }

        if ($fullName) {
            $json['name'] = [
                'familyName' => $fullName,
            ];
        }

        if ($photoUrl) {
            $json['photos'] = [
                [
                    "type" => "photo",
                    "value" => $photoUrl,
                ]
            ];
        }

        $json = json_encode($json, JSON_PRETTY_PRINT);

        return $this->_request('PATCH',"Users/$id", $json);
    }

    /**
     * Deactives a user account
     *
     * @param string $username
     * @return bool
     * @throws GuzzleException
     * @throws SlackSCIMException
     */
    public function deactivateUser(string $username)
    {
        $id = $this->getUserIdFromName($username);

        $this->_request('DELETE', "Users/$id");
        $this->getUserByName($username, true);

        return true;
    }

    /**
     * Actives an deactivated user
     *
     * @param string $username
     * @return bool
     * @throws GuzzleException
     * @throws SlackSCIMException
     */
    public function activateUser(string $username)
    {
        $id = $this->getUserIdFromName($username);

        $json = [
            'schemas' => [
                "urn:scim:schemas:core:1.0"
            ],
            'id' => $id,
            'active' => 'true',
        ];

        $json = json_encode($json, JSON_PRETTY_PRINT);

        $this->_request('PATCH', "Users/$id", $json);
        $this->getUserByName($username, true);
        return true;
    }

    /**
     * Determines if user is active or not
     *
     * @param string $username
     * @return bool
     * @throws SlackSCIMException
     */
    public function isUserActive(string $username)
    {
        $details = $this->getUserByName($username);
        return (bool) $details->active;
    }
}