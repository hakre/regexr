<?php
/*
RegExr: Learn, Build, & Test RegEx
Copyright (C) 2017  gskinner.com, inc.

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace account;

class login extends \core\AbstractAction {

    public $description = 'Attempts to log the user into one of the supported providers. First run it will redirect the user to the requested authentication provider. Upon a successful login the user is redirected back to the main site.';

    public function execute() {
				$type = $this->getValue('type');
				$this->tryLogin($type);
		}

    function tryLogin($type) {

        new \core\Session($this->db, SESSION_NAME);

        $exception = null;

        $auth = new \core\Authentication();
        try {
            $adapter = $auth->connect($type);

            if (!$adapter->isConnected()) {
                $adapter->authenticate();
            }
        } catch (\Exception $ex) {
            $exception = $ex;
        }

        if ($adapter->isConnected()) {
            $userProfile = null;

            try {
                $userProfile = $adapter->getUserProfile();
            } catch (\Exception $ex) {
                $exception = $ex;
            }

            if (!is_null($userProfile)) {
                session_start();

                $id = $this->db->sanitize((string)$userProfile->identifier);
                $displayName = $this->db->sanitize((string)$userProfile->displayName);
                $email = $this->db->sanitize((string)$userProfile->email);

                $sessionId = session_id();
                $sessionData = idx($_SESSION, 'data');
                $userId = null;

                // Check if the user had a temporary session first.
                if (null !== $sessionData) {
                    $sessionData = (object)$sessionData;
                    if ($sessionData->type === 'temporary') {
                        // Migrate all the temp users favorites / ratings / patterns to the correct user
                        // Then delete the old user / session.
                        $existingUser = $this->db->query("SELECT * FROM users WHERE email='{$email}' AND type='{$type}'", true);

                        if (!is_null($existingUser)) {
                            $temporaryUserId = (int)$sessionData->userId;
                            $existingUserId = (int)$existingUser->id;
                            $userId = $existingUserId;

                            $this->db->begin();
                            //Migrate temp users patterns to the exiting user.
                            $this->db->query(sprintf('UPDATE patterns SET owner=%d WHERE owner=%d', $existingUserId, $temporaryUserId));

                            // Delete any favorites patterns that the existing user has already favored.
                            $this->db->query(
                                sprintf(
                                    'DELETE IGNORE
                                        FROM favorites
                                        WHERE userId=%d
                                        AND patternId IN (SELECT patternId FROM (SELECT patternId FROM favorites WHERE userId=%d) as child)',
                                    $temporaryUserId,
                                    $existingUserId
                                )
                            );

                            // Assign remaining favorites to the existing user.
                            $this->db->query(
                                sprintf(
                                    'UPDATE favorites SET userId=%d WHERE userId=%d',
                                    $existingUserId,
                                    $temporaryUserId
                                )
                            );

                            // Delete any ratings that the exiting user already made.
                            $this->db->query(
                                sprintf(
                                    'DELETE IGNORE
                                        FROM userRatings
                                        WHERE userId=%d
                                        AND patternId IN (SELECT patternId FROM (SELECT patternId FROM userRatings WHERE userId=%d) as child)',
                                    $temporaryUserId,
                                    $existingUserId
                                )
                            );

                            // Assign remaining ratings to the existing user.
                            $this->db->query(
                                sprintf(
                                    'UPDATE userRatings SET userId=%d WHERE userId=%d',
                                    $existingUserId,
                                    $temporaryUserId
                                )
                            );

                            // Remove temporary user.
                            $this->db->query(sprintf('DELETE IGNORE FROM users WHERE id=%d', $temporaryUserId));

                            // Remove any sessions for the exiting user (we'll migrate the new one over below)
                            $this->db->query(sprintf('DELETE IGNORE FROM sessions WHERE userId=%d', $existingUserId));

                            $this->db->commit();
                        } else {
                            // Update current temp user to a full fledged user.
                            $userId = (int)$sessionData->userId;
                            $this->db->query(sprintf("UPDATE users SET email='$email', type='$type' WHERE id=%d", $userId));
                        }

                        // Sessions will update below.
                        $sessionData->type = $type;
                        $sessionData->userEmail = $email;
                        $sessionData->userId = $userId;
                        $_SESSION['data'] = $sessionData;
                        session_write_close();
                        session_start();
                    }
                }

                $this->db->query(
                    sprintf(
                        "INSERT INTO users (email, username, authorName, type, oauthUserId, lastLogin)
                                    VALUES ('%s', '%s', '%s', '%s', '$id' , 'NOW()')
                                    ON DUPLICATE KEY UPDATE `username`='$displayName', `oauthUserId`='$id', `lastLogin`=NOW()
                                ",
                        $email,
                        $displayName,
                        $displayName,
                        $type
                    )
                );

                $accessToken = serialize($adapter->getAccessToken());
                $userIdData = $this->db->query("SELECT * FROM users WHERE email='$email' AND type='$type'", true);

                $tokenQuery = "UPDATE sessions SET accessToken='$accessToken', type='$type', userId='$userIdData->id' WHERE id='$sessionId'";
                $this->db->query($tokenQuery);
            }

            session_write_close();
            // Redirect back to the main site.
            header('Location: '. $auth->getBaseDomain());
            die;
        } else {
            throw new \core\APIError(\core\ErrorCodes::API_LOGIN_ERROR, $exception);
        }
    }

    function getUserAccountTypes() {
        $types = $this->db->getEnumValues('users', 'type');
        unset($types[array_search('temporary', $types)]);
        return $types;
    }

    public function getSchema() {
        return array(
            'type' => array('type' => self::ENUM, 'values' => $this->getUserAccountTypes(), 'required' => true)
        );
    }
}
