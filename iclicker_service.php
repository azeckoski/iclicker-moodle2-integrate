<?php 
/**
 * Copyright (c) 2009 i>clicker (R) <http://www.iclicker.com/dnn/>
 *
 * This file is part of i>clicker Moodle integrate.
 *
 * i>clicker Moodle integrate is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * i>clicker Moodle integrate is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with i>clicker Moodle integrate.  If not, see <http://www.gnu.org/licenses/>.
 */
/* $Id$ */

require_once (dirname(__FILE__).'/../../config.php');
global $CFG,$USER,$COURSE;
// link in external libraries
require_once ($CFG->libdir.'/gradelib.php');
require_once ($CFG->libdir.'/dmllib.php');
require_once ($CFG->libdir.'/accesslib.php');
require_once ($CFG->libdir.'/soap/nusoap.php');

/**
 * For XML error handling
 */
function HandleXmlError($errno, $errstr, $errfile, $errline) {
    if ($errno==E_WARNING && (substr_count($errstr,"DOMDocument::loadXML()")>0)) {
        throw new DOMException($errstr);
    } else {
        return false;
    }
}

/**
 * Defines an exception which can occur when validating clicker ids
 * Valid types are:
 * empty - the clickerId is null or empty string
 * length - the clickerId length is not 8 chars (too long), shorter clickerIds are padded out to 8
 * chars - the clickerId contains invalid characters
 * checksum - the clickerId did not validate using the checksum method
 * sample - the clickerId matches the sample one and cannot be used
 */
class ClickerIdInvalidException extends Exception {
    const F_EMPTY = 'EMPTY';
    const F_LENGTH = 'LENGTH';
    const F_CHARS = 'CHARS';
    const F_CHECKSUM = 'CHECKSUM';
    const F_SAMPLE = 'SAMPLE';
    public $type = "UNKNOWN";
    public $clicker_id = NULL;
    /**
     * @param string $message the error message
     * @param string $type [optional] Valid types are:
     * empty - the clickerId is null or empty string
     * length - the clickerId length is not 8 chars (too long), shorter clickerIds are padded out to 8
     * chars - the clickerId contains invalid characters
     * checksum - the clickerId did not validate using the checksum method
     * sample - the clickerId matches the sample one and cannot be used
     * @param string $clicker_id [optional] the clicker id
     */
    function __construct($message, $type = NULL, $clicker_id = NULL) {
        parent::__construct($message);
        $this->type = $type;
        $this->clicker_id = $clicker_id;
    }
    public function errorMessage() {
        $errorMsg = 'Error on line '.$this->getLine().' in '.$this->getFile().': '.$this->getMessage().' : type='.$this->type.' : clicker_id='.$this->clicker_id;
        return $errorMsg;
    }
}

class ClickerRegisteredException extends Exception {
    public $owner_id;
    public $clicker_id;
    public $registered_owner_id;
    function __construct($message, $owner_id, $clicker_id, $registered_owner_id) {
        parent::__construct($message);
        $this->owner_id = $owner_id;
        $this->clicker_id = $clicker_id;
        $this->registered_owner_id = $registered_owner_id;
    }
    public function errorMessage() {
        $errorMsg = 'Error on line '.$this->getLine().' in '.$this->getFile().': '.$this->getMessage().' : cannot register to '.$this->owner_id.', clicker already registered to owner='.$this->registered_owner_id.' : clicker_id='.$this->clicker_id;
        return $errorMsg;
    }
}

/**
 * This marks an exception as being related to an authn or authz failure
 */
class SecurityException extends Exception {
}

class WebservicesException extends Exception {
}

/**
 * This holds all the service logic for the iclicker integrate plugin
 */
class iclicker_service {

    // CONSTANTS
    const BLOCK_NAME = 'block_iclicker';
    const BLOCK_PATH = '/blocks/iclicker';
    const REG_TABLENAME = 'iclicker_registration';
    const REG_ORDER = 'timecreated desc';
    const GRADE_CATEGORY_NAME = 'i>clicker';
    const GRADE_ITEM_TYPE = 'blocks';
    const GRADE_ITEM_MODULE = 'iclicker';
    const GRADE_LOCATION_STR = 'manual';
    const DEFAULT_SYNC_HOUR = 3;
    const BLOCK_RUNNER_KEY = 'block_iclicker_runner';
    const DEFAULT_SERVER_URL = "http://moodle.org/"; // "http://epicurus.learningmate.com/";
    const NATIONAL_WS_URL = "https://webservices.iclicker.com/iclicker_gbsync_registrations/service.asmx";
    /*
     * iclicker_gbsync_reg / #8d7608e1e7f4@
     * 'Basic ' + base64(username + ":" + password)
     */
    const NATIONAL_WS_BASIC_AUTH_HEADER = 'Basic aWNsaWNrZXJfZ2JzeW5jX3JlZzojOGQ3NjA4ZTFlN2Y0QA==';
    const NATIONAL_WS_AUTH_USERNAME = 'iclicker_gbsync_reg';
    const NATIONAL_WS_AUTH_PASSWORD = '#8d7608e1e7f4@';
    // errors constants
    const SCORE_UPDATE_ERRORS = 'ScoreUpdateErrors';
    const POINTS_POSSIBLE_UPDATE_ERRORS = 'PointsPossibleUpdateErrors';
    const USER_DOES_NOT_EXIST_ERROR = 'UserDoesNotExistError';
    const GENERAL_ERRORS = 'GeneralErrors';
    const SCORE_KEY = '${SCORE}';
    
    // CLASS VARIABLES
    
    // CONFIG
    public static $server_URL = self::DEFAULT_SERVER_URL;
    public static $domain_URL = self::DEFAULT_SERVER_URL;
    public static $use_national_webservices = FALSE;
    public static $webservices_URL = self::NATIONAL_WS_URL;
    public static $webservices_username = self::NATIONAL_WS_AUTH_USERNAME;
    public static $webservices_password = self::NATIONAL_WS_AUTH_PASSWORD;
    public static $disable_sync_with_national = FALSE;
    public static $test_mode = FALSE;
    
    // STATIC METHODS
    
    /**
     * @return the path for this block
     */
    public static function block_path($added = NULL) {
        global $CFG;
        if (isset($added)) {
            $added = '/'.$added;
        } else {
            $added = '';
        }
        return $CFG->dirroot.self::BLOCK_PATH.$added;
    }
    
    /**
     * @return the url for this block
     */
    public static function block_url($added = NULL) {
        global $CFG;
        if (isset($added)) {
            $added = '/'.$added;
        } else {
            $added = '';
        }
        return $CFG->wwwroot.self::BLOCK_PATH.$added;
    }
    
    /**
     * i18n message handling
     *
     * @param string $key i18 msg key
     * @param object $vars [optional] optional replacement variables
     * @return the translated string
     */
    public static function msg($key, $vars = NULL) {
        return get_string($key, self::BLOCK_NAME, $vars);
    }
    
    public static function df($time) {
        return strftime('%Y/%m/%d', $time); //userdate($time, '%Y/%m/%d');
    }

    /**
     * Sends an email to an email address
     * 
     * @param string $to email address to send email to
     * @param string $subject email subject
     * @param string $body email body
     * @return true if email sent, false otherwise
     */    
    public static function send_email($to, $subject, $body) {
        // $user should be a fake user object with the email set to the correct value, $from should be a string
        $user = new stdClass();
        $user->email = $to;
        $user->firstname = 'ADMIN';
        $user->lastname = $to;
        $user->mailformat = 0; // plain
        $user->confirmed = 1;
        $user->deleted = 0;
        $user->emailstop = 0;
        $user->id = 1;
        if (email_to_user($user, "SYSTEM", $subject, $body) !== true) {
            error_log("Could not send email ($to) : $subject \n $body");
        }
    }

    /**
     * Sends a notification to the configured email addresses in the system about a failure
     * 
     * @param string $message the message to send
     * @param object $exception [optional] the optional exception to notify the admins about
     * @return true if email sent, false otherwise
     */
    public static function send_notifications($message, $exception=NULL) {
        // load these on demand only - block_iclicker_notify_emails
        $admin_emails = NULL;
        if (!empty($CFG->block_iclicker_notify_emails)) {
            $email_string = $CFG->block_iclicker_notify_emails;
            $admin_emails = explode(',', $email_string);
            array_walk($admin_emails, 'trim');
        }

        // add to failures record and trim it to 5
        $failures = self::get_failures();
        $msg = $message;
        if ($exception != null) {
            $msg .= " Failure: ".$e->message." ".$e;
        }
        array_unshift($failures, date('Y-m-d h:i:s').' :: '.substr($msg, 0, 300));
        while (count($failures) > 5) {
            array_pop($failures);
        }
        set_config('block_iclicker_failures', implode('*****', $failures), self::BLOCK_NAME);

        if ($admin_emails) {
            $sent = false;
            $body = "i>clicker Moodle integrate plugin notification (".date('d.m.Y h:i:s').")\n" + $message + "\n";
            if ($exception != null) {
                $body .= "\nFailure:\n".$e->message."\n\n".$e;
            }
            foreach ($admin_emails as $email) {
                self::send_email($email, 'i>clicker Moodle integrate plugin notification', $body);
            }
            $sent = true;
        } else {
            error_log("No emails set for sending notifications: logging notification: $body");
            $sent = false;
        }
        return $sent;
    }

    public static function get_failures() {
        $failures = array();
        $failure_string = get_config(self::BLOCK_NAME, 'block_iclicker_failures');
        if (! empty($failure_string)) {
            $failures = explode('*****', $failure_string);
        }
        return $failures;
    }
    
    // USERS

    const USER_FIELDS = 'id,username,firstname,lastname,email';

    /**
     * Authenticate a user by username and password
     * @param string $username
     * @param string $password
     * @return true if the authentication is successful
     * @throw SecurityException if auth invalid
     */
    public static function authenticate_user($username, $password) {
        global $USER;
        if (!isset($USER->id) || !$USER->id) {
            $u = authenticate_user_login($username, $password);
            if ($u === false) {
                throw new SecurityException('Could not authenticate username ('.$username.')');
            }
            complete_user_login($u);
        }
        return true;
    }
    
    /**
     * Ensure user is logged in and return the current user id
     * @return the current user id
     * @throws SecurityException if there is no current user
     * @static
     */
    public static function require_user() {
        global $USER;
        if (!isset($USER->id) || !$USER->id) {
            throw new SecurityException('User must be logged in');
        }
        return $USER->id;
    }
    
    /**
     * Gets the current user_id, return FALSE if none can be found
     * @return boolean the current user id OR null/false if no user
     */
    public static function get_current_user_id() {
        $current_user = null;
        try {
            $current_user = self::require_user();
        } catch (SecurityException $e) {
            $current_user = false;
        }
        return $current_user;
    }

    /**
     * Gets a user by their username
     * @param string $username the username (i.e. login name)
     * @return the user object OR false if none can be found
     */
    public static function get_user_by_username($username) {
        $user = false;
        if ($username) {
            $user = get_record('user', 'username', $username, '', '', '', '', self::USER_FIELDS);
            // TESTING handling
            if (self::$test_mode && !$user) {
                // test users
                if ($username == 'student01') {
                    $user = new stdClass();
                    $user->id = 101;
                    $user->username = 'student01';
                    $user->firstname = 'Student';
                    $user->lastname = 'One';
                    $user->email = 'one@fail.com';
                } else if ($username == 'student02') {
                    $user = new stdClass();
                    $user->id = 102;
                    $user->username = 'student02';
                    $user->firstname = 'Student';
                    $user->lastname = 'Two';
                    $user->email = 'two@fail.com';
                } else if ($username == 'student03') {
                    $user = new stdClass();
                    $user->id = 103;
                    $user->username = 'student03';
                    $user->firstname = 'Student';
                    $user->lastname = 'Three';
                    $user->email = 'three@fail.com';
                } else if ($username == 'inst01') {
                    $user = new stdClass();
                    $user->id = 111;
                    $user->username = 'inst01';
                    $user->firstname = 'Instructor';
                    $user->lastname = 'One';
                    $user->email = 'uno_inst@fail.com';
                }
            }
        }
        return $user;
    }

    /**
     * Get user records for a set of user ids
     * @param array $user_ids an array of user ids OR a single user_id
     * @return a map of user_id -> user data OR single user object for single user_id OR empty array if no matches
     */
    public static function get_users($user_ids) {
        $results = array(
        );
        if (isset($user_ids)) {
            if (is_array($user_ids)) {
                $users = false;
                if (! empty($user_ids)) {
                    $ids = implode(',', $user_ids);
                    $users = get_records_list('user', 'id', $ids, 'id', self::USER_FIELDS);
                }
                if ($users) {
                    foreach ($users as $user) {
                        self::makeUserDisplayName($user);
                        $results[$user->id] = $user;
                    }
                }
            } else {
                // single user id
                $user = get_record('user', 'id', $user_ids, '', '', '', '', self::USER_FIELDS);
                // TESTING handling
                if (self::$test_mode && !$user) {
                    if ($user_ids == 101) {
                        $user = new stdClass();
                        $user->id = 101;
                        $user->username = 'student01';
                        $user->firstname = 'Student';
                        $user->lastname = 'One';
                        $user->email = 'one@fail.com';
                    } else if ($user_ids == 102) {
                        $user = new stdClass();
                        $user->id = 102;
                        $user->username = 'student02';
                        $user->firstname = 'Student';
                        $user->lastname = 'Two';
                        $user->email = 'two@fail.com';
                    } else if ($user_ids == 103) {
                        $user = new stdClass();
                        $user->id = 103;
                        $user->username = 'student03';
                        $user->firstname = 'Student';
                        $user->lastname = 'Three';
                        $user->email = 'three@fail.com';
                    } else if ($user_ids == 111) {
                        $user = new stdClass();
                        $user->id = 111;
                        $user->username = 'inst01';
                        $user->firstname = 'Instructor';
                        $user->lastname = 'One';
                        $user->email = 'uno_inst@fail.com';
                    }
                }
                if ($user) {
                    self::makeUserDisplayName($user);
                    $results = $user;
                }
            }
        }
        return $results;
    }
    
    /**
     * Get a display name for a given user id
     * @param int $user_id id for a user
     * @return the display name
     */
    public static function get_user_displayname($user_id) {
        $name = "UNKNOWN-".$user_id;
        $users = self::get_users($user_id);
        if ($users && array_key_exists($user_id, $users)) {
            $name = self::makeUserDisplayName($users[$user_id]);
        }
        return $name;
    }

    private static function makeUserDisplayName(&$user) {
        $display_name = fullname($user);
        $user->name = $display_name;
        $user->display_name = $display_name;
        return $display_name;
    }

    /**
     * @param int $user_id [optional] the user id
     * @return true if this user is an admin OR false if not
     * @static
     */
    public static function is_admin($user_id = NULL) {
        if (!isset($user_id)) {
            try {
                $user_id = self::require_user();
            }
            catch (SecurityException $e) {
                return false;
            }
        }
        $result = is_siteadmin($user_id);
        return $result;
    }

    /**
     * Check if a user is an instructor in moodle
     * 
     * @param int $user_id [optional] the user id to check (default to current user)
     * @return true if an instructor or false otherwise
     * @static
     */
    public static function is_instructor($user_id = NULL) {
        global $USER;
        if (!isset($user_id)) {
            try {
                $user_id = self::require_user();
            }
            catch (SecurityException $e) {
                return false;
            }
        }
        // sadly this is the only way to do this check: http://moodle.org/mod/forum/discuss.php?d=140383
        $accessinfo = NULL;
        if ($user_id === $USER->id && isset($USER->access)) {
            $accessinfo = $USER->access;
        } else {
            $accessinfo = get_user_access_sitewide($user_id);
        }
        $results = get_user_courses_bycap($user_id, 'moodle/course:update', $accessinfo, false,
            'c.sortorder', array(), 1);
        $result = count($results) > 0;
        return $result;
    }
    
    const CLICKERID_SAMPLE = '11A4C277';
    /**
     * Cleans up and validates a given clicker_id
     * @param clicker_id a remote clicker ID
     * @return the cleaned up and valid clicker ID
     * @throws ClickerIdInvalidException if the id is invalid for some reason,
     * the exception will indicate the type of validation failure
     * @static
     */
    public static function validate_clicker_id($clicker_id) {
        if (!isset($clicker_id) || strlen($clicker_id) == 0) {
            throw new ClickerIdInvalidException("empty or NULL clicker_id", ClickerIdInvalidException::F_EMPTY, $clicker_id);
        }
        if (strlen($clicker_id) > 8) {
            throw new ClickerIdInvalidException("clicker_id is an invalid length", ClickerIdInvalidException::F_LENGTH, $clicker_id);
        }
        $clicker_id = strtoupper(trim($clicker_id));
        if (!preg_match('/^[0-9A-F]+$/', $clicker_id)) {
            throw new ClickerIdInvalidException("clicker_id can only contains A-F and 0-9", ClickerIdInvalidException::F_CHARS, $clicker_id);
        }
        while (strlen($clicker_id) < 8) {
            $clicker_id = "0".$clicker_id;
        }
        if (self::CLICKERID_SAMPLE == $clicker_id) {
            throw new ClickerIdInvalidException("clicker_id cannot match the sample ID", ClickerIdInvalidException::F_SAMPLE, $clicker_id);
        }
        $idArray = array(
        );
        $idArray[0] = substr($clicker_id, 0, 2);
        $idArray[1] = substr($clicker_id, 2, 2);
        $idArray[2] = substr($clicker_id, 4, 2);
        $idArray[3] = substr($clicker_id, 6, 2);
        $checksum = 0;
        foreach ($idArray as $piece) {
            $hex = hexdec($piece);
            $checksum = $checksum ^ $hex;
        }
        if ($checksum != 0) {
            throw new ClickerIdInvalidException("clicker_id checksum (" + $checksum + ") validation failed", ClickerIdInvalidException::F_CHECKSUM, $clicker_id);
        }
        return $clicker_id;
    }
    
    // CLICKER REGISTRATIONS DATA
    
    /**
     * @param int $id the registration ID
     * @return the registration object OR false if none found
     * @static
     */
    public static function get_registration_by_id($reg_id) {
        if (!isset($reg_id)) {
            throw new InvalidArgumentException("reg_id must be set");
        }
        $result = get_record(self::REG_TABLENAME, 'id', $reg_id);
        //$sql = "id = ".addslashes($reg_id);
        //$result = get_record_select(self::REG_TABLENAME, $sql);
        return $result;
    }
    
    /**
     * @param string $clicker_id the clicker id
     * @param int $user_id [optional] the user who registered the clicker (id)
     * @return the registration object OR false if none found
     * @static
     */
    public static function get_registration_by_clicker_id($clicker_id, $user_id = NULL) {
        if (!$clicker_id) {
            throw new InvalidArgumentException("clicker_id must be set");
        }
        $current_user_id = self::require_user();
        if (!isset($user_id)) {
            $user_id = $current_user_id;
        }
        try {
            $clicker_id = self::validate_clicker_id($clicker_id);
        }
        catch (ClickerIdInvalidException $e) {
            return false;
        }
        // NOTE: also returns disabled registrations
        $result = get_record(self::REG_TABLENAME, 'clicker_id', $clicker_id, 'owner_id', $user_id);
        //$sql = "clicker_id = '".addslashes($clicker_id)."' and owner_id = '".addslashes($user_id)."'";
        //$result = get_record_select(self::REG_TABLENAME, $sql);
        if ($result) {
            if (!self::can_read_registration($result, $current_user_id)) {
                throw new SecurityException("User ($current_user_id) not allowed to access registration ($result->id)");
            }
        }
        return $result;
    }
    
    public static function can_read_registration($clicker_registration, $user_id) {
        if (!isset($clicker_registration)) {
            throw new InvalidArgumentException("clicker_registration must be set");
        }
        if (!isset($user_id)) {
            throw new InvalidArgumentException("user_id must be set");
        }
        $result = false;
        if ($clicker_registration->owner_id == $user_id) {
            $result = true;
        } else if (self::is_admin($user_id)) {
            $result = true;
        } else if (self::is_instructor($user_id)) {
            // NOTE: simply allowing instructors to read/write all registrations for now
            $result = true;
        }
        return $result;
    }
    
    public static function can_write_registration($clicker_registration, $user_id) {
        if (!isset($clicker_registration)) {
            throw new InvalidArgumentException("clicker_registration must be set");
        }
        if (!isset($user_id)) {
            throw new InvalidArgumentException("user_id must be set");
        }
        $result = false;
        if ($clicker_registration->owner_id == $user_id) {
            $result = true;
        } else if (self::is_admin($user_id)) {
            $result = true;
        } else if (self::is_instructor($user_id)) {
            // NOTE: simply allowing instructors to read/write all registrations for now
            $result = true;
        }
        return $result;
    }
    
    /**
     * @param int $user_id [optional] the user id OR current user id
     * @param boolean $activated if null or not set then return all,
     * if true then return active only, if false then return inactive only
     * @return the list of registrations for this user or empty array if none
     */
    public static function get_registrations_by_user($user_id = NULL, $activated = NULL) {
        $current_user_id = self::require_user();
        if (!isset($user_id)) {
            $user_id = $current_user_id;
        }
        $sql = "owner_id = '".addslashes($user_id)."'";
        if (isset($activated)) {
            $sql .= ' and activated = '.($activated ? 1 : 0);
        }
        $results = get_records_select(self::REG_TABLENAME, $sql, self::REG_ORDER);
        if (!$results) {
            $results = array(
            );
        }
        return $results;
    }
    
    /**
     * ADMIN ONLY
     * This is a method to get all the clickers for the clicker admin view
     * @param int $start [optional] start value for paging
     * @param int $max [optional] max value for paging
     * @param string $order [optional] the order by string
     * @param string $search [optional] search string for clickers
     * @return array of clicker registrations
     */
    public static function get_all_registrations($start = 0, $max = 0, $order = 'clicker_id', $search = '') {
        if (!self::is_admin()) {
            throw new SecurityException("Only admins can use this function");
        }
        if ($max <= 0) {
            $max = 10;
        }
        $query = '';
        if ($search) {
            // build a search query
            $query = 'clicker_id '.sql_ilike().' '.addslashes($search).'%';
        }
        $results = get_records_select(self::REG_TABLENAME, $query, $order, '*', $start, $max);
        if (!$results) {
            $results = array(
            );
        } else {
            // insert user display names
            $user_ids = array();
            foreach ($results as $reg) {
                $user_ids[] = $reg->owner_id;
            }
            $user_ids = array_unique($user_ids);
            $users = self::get_users($user_ids);
            foreach ($results as $reg) {
                $name = 'UNKNOWN-'.$reg->owner_id;
                if (array_key_exists($reg->owner_id, $users)) {
                    $name = $users[$reg->owner_id]->name;
                }
                $reg->user_display_name = $name;
            }
        }
        return $results;
    }
    
    /**
     * @return the count of the total number of registered clickers
     */
    public static function count_all_registrations() {
        return count_records(self::REG_TABLENAME);
    }
    
    /**
     * ADMIN ONLY
     * Removes the registration from the database
     *
     * @param int $reg_id id of the clicker registration
     * @return true if removed OR false if not found or not removed
     */
    public static function remove_registration($reg_id) {
        if (!self::is_admin()) {
            throw new SecurityException("Only admins can use this function");
        }
        if (isset($reg_id)) {
            if (delete_records(self::REG_TABLENAME, 'id', $reg_id)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Create a registration
     *
     * @param string $clicker_id the clickerID (e.g. 11111111)
     * @param string $owner_id [optional] the user_id OR current user if not set
     * @param boolean $local_only [optional] create this clicker in the local system only if true, otherwise sync to national system as well
     * @return the clicker_registration object
     */
    public static function create_clicker_registration($clicker_id, $owner_id = NULL, $local_only = false) {
        $clicker_id = self::validate_clicker_id($clicker_id);
        $current_user_id = self::require_user();
        $user_id = $owner_id;
        if (!isset($owner_id)) {
            $user_id = $current_user_id;
        }
        $registration = self::get_registration_by_clicker_id($clicker_id, $user_id);
        // NOTE: we probably want to check the national system here to see if this is already registered
        if ($registration) {
            throw new ClickerRegisteredException('Clicker '.$registration->clicker_id.' already registered', $user_id, $registration->clicker_id, $registration->owner_id);
        } else {
            $clicker_registration = new stdClass ;
            $clicker_registration->clicker_id = $clicker_id;
            $clicker_registration->owner_id = $user_id;
            $reg_id = self::save_registration($clicker_registration);
            $registration = self::get_registration_by_id($reg_id);
            if ($local_only) {
                // sync with national
                self::ws_sync_clicker($registration);
            }
        }
        return $registration;
    }
    
    /**
     * Make a registration active or inactive
     *
     * @param int $reg_id id of the clicker registration
     * @param boolean $activated true to enable, false to disable
     * @return the clicker_registration object
     */
    public static function set_registration_active($reg_id, $activated) {
        if (!isset($reg_id)) {
            throw new InvalidArgumentException("reg_id must be set");
        }
        if (!isset($activated)) {
            throw new InvalidArgumentException("active must be set");
        }
        $current_user_id = self::require_user();
        $registration = self::get_registration_by_id($reg_id);
        if (!$registration) {
            throw new InvalidArgumentException("Could not find registration with id ($reg_id)");
        }
        $registration->activated = $activated ? 1 : 0;
        self::save_registration($registration);
        return $registration;
    }
    
    /**
     * Saves the clicker registration data (create or update)
     * @param object $clicker_registration the registration data as an object
     * @return int id of the saved registration
     * @throw InvalidArgumentException if the registration is invalid (missing data or invalid data)
     */
    public static function save_registration(&$clicker_registration) {
        if (!$clicker_registration || !isset($clicker_registration->clicker_id)) {
            throw new InvalidArgumentException("clicker_registration cannot be empty and clicker_id must be set");
        }
        $clicker_registration->clicker_id = self::validate_clicker_id($clicker_registration->clicker_id);
        $current_user_id = self::require_user();
        // set the owner to current if not set
        if (!isset($clicker_registration->owner_id)) {
            $clicker_registration->owner_id = $current_user_id;
        } else {
            // check for valid user id
            $user = self::get_users($clicker_registration->owner_id);
            if (! $user) {
                throw new InvalidArgumentException('User id ('.$clicker_registration->owner_id.') for registration is invalid');
            }
        }
        $clicker_registration->timemodified = time();
        $reg_id = -1;
        if (!isset($clicker_registration->id)) {
            // new item to save (no perms check)
            $clicker_registration->timecreated = time();
            if (!$reg_id = insert_record(self::REG_TABLENAME, $clicker_registration, true)) {
                print_object($clicker_registration);
                error(self::msg('inserterror'));
            }
        } else {
            // updating existing item
            if (self::can_write_registration($clicker_registration, $current_user_id)) {
                if (!update_record(self::REG_TABLENAME, $clicker_registration)) {
                    print_object($clicker_registration);
                    error(self::msg('updateerror'));
                }
                $reg_id = $clicker_registration->id;
            } else {
                throw new SecurityException("Current user cannot update item ($clicker_registration->id) because they do not have permission");
            }
        }
        return $reg_id;
    }
    
    // COURSES METHODS

    /**
     * Get all the students for a course with their clicker registrations
     * @param int $course_id the course to get the students for
     * @param boolean $include_regs [optional]
     * @return the list of user objects for the students in the course
     */
    public static function get_students_for_course_with_regs($course_id, $include_regs=true) {
        // get_users_by_capability - accesslib - moodle/grade:view
        // search_users - datalib
        $context = get_context_instance(CONTEXT_COURSE, $course_id);
        $results = get_users_by_capability($context, 'moodle/grade:view', 'u.id, u.username, u.firstname, u.lastname, u.email', 'u.lastname', '', '', '', '', FALSE);
        if (isset($results) && !empty($results)) {
            // get the registrations related to these students
            $user_regs = array();
            if ($include_regs) {
                $query = 'activated = 1';
                if (count($results) > 500) {
                    // just return them all since the in query would be super slow anyway
                } else {
                    $query .= ' AND owner_id in (';
                    $first = true;
                    foreach ($results as $student) {
                        if ($first) {
                            $first = false;
                        } else {
                            $query .= ',';
                        }
                        $query .= $student->id;
                    }
                    $query .= ')';
                }
                $regs = get_records_select(self::REG_TABLENAME, $query);
                if ($regs) {
                    // now put these into a map
                    foreach ($regs as $reg) {
                        if (! array_key_exists($reg->owner_id, $user_regs)) {
                            $user_regs[$reg->owner_id] = array();
                        }
                        $user_regs[$reg->owner_id][] = $reg;
                    }
                }
            }
            foreach ($results as $user) {
                // setup display name
                self::makeUserDisplayName($user);
                if ($include_regs) {
                    // add in registrations
                    $user->clicker_registered = false;
                    $user->clickers = array();
                    if (array_key_exists($user->id, $user_regs)) {
                        $user->clicker_registered = true;
                        $user->clickers = $user_regs[$user->id];
                    }
                }
            }
        } else {
            // NO matches
            $results = array();
        }
        return $results;
    }

    /**
     * Get the listing of all courses for an instructor
     * @param int $user_id [optional] the unique user id for an instructor (default to current user)
     * @return the list of courses (maybe be emtpy array)
     */    
    public static function get_courses_for_instructor($user_id = NULL) {
        global $USER;
        // make this only get courses for this instructor
        // get_user_courses_bycap? - accesslib
        // http://docs.moodle.org/en/Category:Capabilities - moodle/course:update
        //$results = get_records('course', 'category', 1, 'id'); // get_records_sql("SELECT * FROM mdl_course where category = 1");
        if (! isset($user_id)) {
            $user_id = self::get_current_user_id();
        }
        $accessinfo = $USER->access;
        if ($user_id === $USER->id && isset($USER->access)) {
            $accessinfo = $USER->access;
        } else {
            $accessinfo = get_user_access_sitewide($user_id);
        }
        $results = get_user_courses_bycap($user_id, 'moodle/course:update', $accessinfo, false,
            'c.sortorder', array('fullname','summary'), 50);
        if (!$results) {
            $results = array();
        }
        return $results;
    }

    /**
     * Retrieve a single course by unique id
     * @param int $course_id the course
     * @return the course object or FALSE
     */
    public static function get_course($course_id) {
        $course = get_record('course', 'id', $course_id);
        // TESTING handling
        if (self::$test_mode && !$course) {
            if ($course_id == '11111111') {
                $course = new stdClass();
                $course->id = $course_id;
                $course->fullname = 'testing: '.$course_id;
            }
        }
        if (!$course) {
            $course = FALSE;
        }
        return $course;
    }

/* Not needed right now
    public static function get_course_grade_item($course_id, $grade_item_name) {
        if (! $course_id) {
            throw new InvalidArgumentException("course_id must be set");
        }
        if (! $grade_item_name) {
            throw new InvalidArgumentException("grade_item_name must be set");
        }
        $grade_item_fetched = false;
        $iclicker_category = grade_category::fetch(array(
            'courseid' => $course_id,
            'fullname' => self::GRADE_CATEGORY_NAME
            )
        );
        if ($iclicker_category) {
            $grade_item_fetched = grade_item::fetch(array(
                'courseid' => $course_id,
                'categoryid' => $iclicker_category->id,
                'itemname' => $grade_item_name
                )
            );
            if (! $grade_item_fetched) {
                $grade_item_fetched = false;
            }
        }
        return $grade_item_fetched;
    }
*/
    
    private static function save_grade_item($grade_item) {
        if (! $grade_item) {
            throw new InvalidArgumentException("grade_item must be set");
        }
        if (! $grade_item->courseid) {
            throw new InvalidArgumentException("grade_item->courseid must be set");
        }
        if (! $grade_item->categoryid) {
            throw new InvalidArgumentException("grade_item->categoryid must be set");
        }
        if (! $grade_item->name) {
            throw new InvalidArgumentException("grade_item->name must be set");
        }
        if (! isset($grade_item->item_number)) {
            $grade_item->item_number = 0;
        }

        // check for an existing item and update or create
        $grade_item_tosave = grade_item::fetch(array(
            'courseid' => $grade_item->courseid,
            'categoryid' => $grade_item->categoryid,
            'itemname' => $grade_item->name
            )
        );
        if (! $grade_item_tosave) {
            // create new one
            $grade_item_tosave = new grade_item();
            $grade_item_tosave->courseid = $grade_item->courseid;
            $grade_item_tosave->categoryid = $grade_item->categoryid;
            $grade_item_tosave->itemnumber = $grade_item->item_number;
            //$grade_item_tosave->idnumber = $grade_item->name;
            $grade_item_tosave->itemname = $grade_item->name;
            $grade_item_tosave->itemtype = self::GRADE_ITEM_TYPE;
            //$grade_item_tosave->itemmodule = self::GRADE_ITEM_MODULE;
            $grade_item_tosave->iteminfo = $grade_item->name.' '.$grade_item->type.' '.self::GRADE_CATEGORY_NAME;
            if (isset($grade_item->points_possible) && $grade_item->points_possible > 0) {
                $grade_item_tosave->grademax = $grade_item->points_possible;
            }
            $grade_item_tosave->insert(self::GRADE_LOCATION_STR);
        } else {
            // update
            if (isset($grade_item->points_possible) && $grade_item->points_possible > 0) {
                $grade_item_tosave->grademax = $grade_item->points_possible;
            }
            $grade_item_tosave->update(self::GRADE_LOCATION_STR);
        }
        $grade_item_id = $grade_item_tosave->id;
        $grade_item_pp = $grade_item_tosave->grademax;

        // now save the related scores
        if (isset($grade_item->scores) && !empty($grade_item->scores)) {
            // get the existing scores
            $current_scores = array();
            $existing_grades = grade_grade::fetch_all(array(
                'itemid' => $grade_item_id
                )
            );
            if ($existing_grades) {
                foreach ($existing_grades as $grade) {
                    $current_scores[$grade->userid] = $grade;
                }
            }

            // run through the scores in the gradeitem and try to save them
            $errors_count = 0;
            $processed_scores = array();
            foreach ($grade_item->scores as $score) {
                $user = self::get_users($score->user_id);
                if (! $user) {
                    $score->error = self::USER_DOES_NOT_EXIST_ERROR;
                    $processed_scores[] = $score;
                    $errors_count++;
                    continue;
                }
                $user_id = $user->id;
                // null/blank scores are not allowed
                if (! isset($score->score)) {
                    $score->error = 'NO_SCORE_ERROR';
                    $processed_scores[] = $score;
                    $errors_count++;
                    continue;
                }
                if (! is_numeric($score->score)) {
                    $score->error = 'SCORE_INVALID';
                    $processed_scores[] = $score;
                    $errors_count++;
                    continue;
                }
                $score->score = floatval($score->score);
                // Student Score should not be greater than the total points possible
                if ($score->score > $grade_item_pp) {
                    $score->error = self::POINTS_POSSIBLE_UPDATE_ERRORS;
                    $processed_scores[] = $score;
                    $errors_count++;
                    continue;
                }
                try {
                    $grade_tosave = NULL;
                    if (isset($current_scores[$user_id])) {
                        // existing score
                        $grade_tosave = $current_scores[$user_id];
                        // check against existing score
                        if ($score->score < $grade_tosave->rawgrade) {
                            $score->error = self::SCORE_UPDATE_ERRORS;
                            $processed_scores[] = $score;
                            $errors_count++;
                            continue;
                        }
                        $grade_tosave->finalgrade = $score->score;
                        $grade_tosave->rawgrade = $score->score;
                        $grade_tosave->timemodified = time();
                        $grade_tosave->update(self::GRADE_LOCATION_STR);
                    } else {
                        // new score
                        $grade_tosave = new grade_grade();
                        $grade_tosave->itemid = $grade_item_id;
                        $grade_tosave->userid = $user_id;
                        $grade_tosave->finalgrade = $score->score;
                        $grade_tosave->rawgrade = $score->score;
                        $grade_tosave->rawgrademax = $grade_item_pp;
                        $now = time();
                        $grade_tosave->timecreated = $now;
                        $grade_tosave->timemodified = $now;
                        $grade_tosave->insert(self::GRADE_LOCATION_STR);
                    }
                    $grade_tosave->user_id = $score->user_id;
                    $processed_scores[] = $grade_tosave;
                } catch (Exception $e) {
                    // General errors, caused while performing updates (Tag: generalerrors)
                    $score->error = self::GENERAL_ERRORS;
                    $processed_scores[] = $score;
                    $errors_count++;
                }
            }
            $grade_item_tosave->scores = $processed_scores;
            // put the errors in the item
            if ($errors_count > 0) {
                $errors = array();
                foreach ($processed_scores as $score) {
                    if (isset($score->error)) {
                        $errors[$score->user_id] = $score->error;
                    }
                }
                $grade_item_tosave->errors = $errors;
            }
            $grade_item_tosave->force_regrading();
        }
        return $grade_item_tosave;
    }

    /**
     * Saves a gradebook (a set of grade items and scores related to a course)
     * 
     * @param object $gradebook an object with at least course_id and items set
     * items should contain grade_items (courseid. categoryid, name, scores)
     * scores should contain grade_grade (user_id, score)
     * @return the saved gradebook with all items and scores in the same structure,
     * errors are recorded as grade_item->errors and score->error
     */
    public static function save_gradebook($gradebook) {
        if (! $gradebook) {
            throw new InvalidArgumentException("gradebook must be set");
        }
        if (! isset($gradebook->course_id)) {
            throw new InvalidArgumentException("gradebook->course_id must be set");
        }
        if (! isset($gradebook->items) || empty($gradebook->items)) {
            throw new InvalidArgumentException("gradebook->items must be set and include items");
        }
        $gb_saved = new stdClass();
        $gb_saved->items = array();
        $gb_saved->course_id = $gradebook->course_id;
        $course = self::get_course($gradebook->course_id);
        if (! $course) {
            throw new InvalidArgumentException("No course found with course_id ($gradebook->course_id)");
        }
        $gb_saved->course = $course;

        // attempt to get the iclicker category first or create it if needed
        $iclicker_category = grade_category::fetch(array(
            'courseid' => $gradebook->course_id,
            'fullname' => self::GRADE_CATEGORY_NAME
            )
        );
        $iclicker_category_id = NULL;
        if (! $iclicker_category) {
            // create the category
            $params = new stdClass();
            $params->courseid = $gradebook->course_id;
            $params->fullname = self::GRADE_CATEGORY_NAME;
            $grade_category = new grade_category($params, false);
            $grade_category->insert(self::GRADE_LOCATION_STR);
            $iclicker_category_id = $grade_category->id;
        } else {
            $iclicker_category_id = $iclicker_category->id;
        }
        $gb_saved->category_id = $iclicker_category_id;
        //echo "\n\nGRADEBOOK: ".var_export($gradebook);
        // iterate through and save grade items by calling other method
        if (! empty($gradebook->items)) {
            $saved_items = array();
            $number = 0;
            foreach ($gradebook->items as $grade_item) {
                $grade_item->categoryid = $iclicker_category_id;
                $grade_item->courseid = $gb_saved->course_id;
                $grade_item->item_number = $number;
                $saved_grade_item = self::save_grade_item($grade_item);
                $saved_items[] = $saved_grade_item;
                $number++;
            }
            $gb_saved->items = $saved_items;
        }
        //echo "\n\nRESULT: ".var_export($gb_saved);
        return $gb_saved;
    }
    
    // DATA ENCODING METHODS

    /**
     * Encodes a clicker registration into XML
     * 
     * @param object $clicker_registration fields(owner_id, clicker_id)
     * @return the XML
     * @throws InvalidArgumentException if the registration is invalid
     */
    public static function encode_registration($clicker_registration) {
        if (! $clicker_registration) {
            throw new InvalidArgumentException("clicker_registration must be set");
        }
        $user_id = $clicker_registration->owner_id;
        $user = self::get_users($user_id);
        if (! $user) {
            throw new InvalidArgumentException("Invalid user id ($user_id) for clicker reg ($clicker_registration->clicker_id)");
        }
        if (!isset($clicker_registration->activated)) {
            $clicker_registration->activated = true;
        }
        $encoded = '<Register>'.PHP_EOL;
        $encoded .= '  <S DisplayName="';
        $encoded .= self::encode_for_xml($user->name);
        $encoded .= '" FirstName="';
        $encoded .= self::encode_for_xml($user->firstname);
        $encoded .= '" LastName="';
        $encoded .= self::encode_for_xml($user->lastname);
        $encoded .= '" StudentID="';
        $encoded .= self::encode_for_xml(strtoupper($user->username));
        $encoded .= '" Email="';
        $encoded .= self::encode_for_xml($user->email);
        $encoded .= '" URL="';
        $encoded .= self::encode_for_xml(self::$domain_URL);
        $encoded .= '" ClickerID="';
        $encoded .= strtoupper($clicker_registration->clicker_id);
        $encoded .= '" Enabled="';
        $encoded .= $clicker_registration->activated ? 'True' : 'False';
        $encoded .= '"></S>'.PHP_EOL;
        // close out
        $encoded .= '</Register>'.PHP_EOL;
        return $encoded;
    }

    /**
     * Creates XML encoding of the result of a clicker registration
     * 
     * @param object $registrations [optional] all regs for the registering user
     * @param boolean $status true if success, false if failure
     * @param string $message the message to send along (typically failure message)
     * @return the XML
     * @throws InvalidArgumentException if the data is invalid
     */
    public static function encode_registration_result($registrations, $status, $message) {
        if (! $registrations) {
            throw new InvalidArgumentException("registrations must be set");
        }
        if (! isset($status)) {
            throw new InvalidArgumentException("status must be set");
        }
        /* SAMPLE
1) When clicker is already registered to some one else - the same
message should be returned that is displayed in the plug-in in xml
format
<RetStatus Status="False" Message=""/>

2) When clicker is already registered to the same user - the same
message should be returned that is displayed in the plug-in in xml
format.
<RetStatus Status="False" Message=""/>

3) When studentid is not found in the CMS
<RetStatus Status="False" Message="Student not found in the CMS"/>

4) Successful registration - 
<RetStatus Status="True" Message="..."/>
         */
        $encoded = '<RetStatus Status="'.($status ? 'True' : 'False').'" Message="'.self::encode_for_xml($message).'" />';
        return $encoded;
    }

    /**
     * Encode a set of courses which a user is an instructor for into XML
     * 
     * @param int $instructor_id unique user id
     * @return the XML
     * @throws InvalidArgumentException if the id is invalid
     */
    public static function encode_courses($instructor_id) {
        if (! isset($instructor_id)) {
            throw new InvalidArgumentException("instructor_id must be set");
        }
        $instructor = self::get_users($instructor_id);
        if (! $instructor) {
            throw new InvalidArgumentException("Invalid instructor user id ($instructor_id)");
        }
        $courses = self::get_courses_for_instructor($instructor_id);
        if (! $courses) {
            throw new SecurityException("No courses found, only instructors can access instructor courses listings");
        }
        $encoded = '<coursemembership username="';
        $encoded .= self::encode_for_xml($instructor->username);
        $encoded .= '">'.PHP_EOL;
        // loop through courses
        foreach ($courses as $course) {
            $encoded .= '  <course id="'.$course->id.'" name="';
            $encoded .= self::encode_for_xml($course->fullname);
            $encoded .= '" usertype="I" />'.PHP_EOL;
        }
        // close out
        $encoded .= '</coursemembership>'.PHP_EOL;
        return $encoded;
    }

    /**
     * Encode a set of enrollments for a course into XML
     * 
     * @param int $course_id unique id for a course
     * @return the XML
     * @throws InvalidArgumentException if the id is invalid
     */
    public static function encode_enrollments($course_id) {
        if (! isset($course_id)) {
            throw new InvalidArgumentException("course_id must be set");
        }
        $course = self::get_course($course_id);
        if (! $course) {
            throw new InvalidArgumentException("No course found with course_id ($course_id)");
        }
        $students = self::get_students_for_course_with_regs($course_id);
        $encoded = '<courseenrollment courseid="'.$course->id.'">'.PHP_EOL;
        // the students may be an empty set
        if ($students) {
            // loop through students
            foreach ($students as $student) {
                // get the clicker data out first if there is any
                $cids_dates = self::make_clicker_ids_and_dates($student->clickers);
                // now make the actual user data line
                $encoded .= '  <user id="'.$student->id.'" usertype="S" firstname="';
                $encoded .= self::encode_for_xml($student->firstname ? $student->firstname : '');
                $encoded .= '" lastname="';
                $encoded .= self::encode_for_xml($student->lastname ? $student->lastname : '');
                $encoded .= '" emailid="';
                $encoded .= self::encode_for_xml($student->email ? $student->email : '');
                $encoded .= '" uniqueid="';
                $encoded .= self::encode_for_xml($student->username);
                $encoded .= '" clickerid="';
                $encoded .= self::encode_for_xml( $cids_dates['clickerid'] );
                $encoded .= '" whenadded="';
                $encoded .= self::encode_for_xml( $cids_dates['whenadded'] );
                $encoded .= '" />'.PHP_EOL;
            }
        }
        // close out
        $encoded .= '</courseenrollment>'.PHP_EOL;
        return $encoded;
    }

    private static function make_clicker_ids_and_dates($clicker_regs) {
        $clicker_ids = '';
        $clicker_added_dates = '';
        if ($clicker_regs && !empty($clicker_regs)) {
            $count = 0;
            foreach ($clicker_regs as $reg) {
                if ($count > 0) {
                    $clicker_ids .= ',';
                    $clicker_added_dates .= ',';
                }
                $clicker_ids .= $reg->clicker_id;
                $clicker_added_dates .= date('M/d/Y', $reg->timecreated);
                $count++;
            }
        }
        return array('clickerid' => $clicker_ids, 'whenadded' => $clicker_added_dates);
    }

    /**
     * Encodes the results of a gradebook save into XML
     * 
     * @param object $gradebook_result the result from gradebook_save
     * @return the XML
     * @throws InvalidArgumentException if the registration is invalid
     */
    public static function encode_gradebook_results($gradebook_result) {
        if (! isset($gradebook_result->course)) {
            throw new InvalidArgumentException("course must be set");
        }
        $course = $gradebook_result->course;
        $course_id = $gradebook_result->course->id;
        // check for any errors
        $has_errors = false;
        foreach ($gradebook_result->items as $item) {
            if (isset($item->errors) && !empty($item->errors)) {
                $has_errors = true;
                break;
            }
        }
        /* SAMPLE
<errors courseid="BFW61">
  <Userdoesnotexisterrors>
    <user id="XXXX" />
  </Userdoesnotexisterrors>
  <Scoreupdateerrors>
    <user id="2222">
      <lineitem name="Decsample" pointspossible="0" type="Text" score="9" />
    </user>
  </Scoreupdateerrors>
  <PointsPossibleupdateerrors>
    <user id="33333">
      <lineitem name="CMSIntTEST01" pointspossible="50" type="iclicker polling scores" score="70" />
    </user>
  </PointsPossibleupdateerrors>
  <Scoreupdateerrors>
    <user id="444444">
      <lineitem name="Mac-integrate-2" pointspossible="31" type="092509Mac" score="13"/>
    </user>
  </Scoreupdateerrors>
  <Generalerrors>
    <user id="5555" error="CODE">
      <lineitem name="itemName" pointspossible="35" score="XX" error="CODE" />
    </user>
  </Generalerrors>
</errors>
         */
        $output = null;
        if ($has_errors) {
            $lineitems = self::make_lineitems($gradebook_result->items);
            $invalid_user_ids = array();

            $encoded = '<errors courseId="'.$course_id.'">'.PHP_EOL;
            // loop through items and errors and generate errors xml blocks
            $error_items = array();
            foreach ($gradebook_result->items as $item) {
                if (isset($item->errors) && !empty($item->errors)) {
                    foreach ($item->scores as $score) {
                        if (isset($score->error)) {
                            $lineitem = $lineitems[$item->id];
                            if (self::USER_DOES_NOT_EXIST_ERROR == $score->error) {
                                $key = self::USER_DOES_NOT_EXIST_ERROR;
                                if (! array_key_exists($score->user_id, $invalid_user_ids)) {
                                    // only if the invalid user is not already listed in the errors
                                    if (!isset($error_items[$key])) {
                                        $error_items[$key] = '';
                                    }
                                    $error_items[$key] .= '    <user id="'.$score->user_id.'" />'.PHP_EOL;
                                    $invalid_user_ids[$score->user_id] = $score->user_id;
                                }
                            } else if (self::POINTS_POSSIBLE_UPDATE_ERRORS == $score->error) {
                                $key = self::POINTS_POSSIBLE_UPDATE_ERRORS;
                                $li = str_replace(self::SCORE_KEY, $score->score, $lineitem);
                                if (!isset($error_items[$key])) {
                                    $error_items[$key] = '';
                                }
                                $error_items[$key] .= '    <user id="'.$score->user_id.'">'.PHP_EOL.'      '.$li.PHP_EOL.'    </user>'.PHP_EOL;
                            } else if (self::SCORE_UPDATE_ERRORS == $score->error) {
                                $key = self::SCORE_UPDATE_ERRORS;
                                $li = str_replace(self::SCORE_KEY, $score->score, $lineitem);
                                if (!isset($error_items[$key])) {
                                    $error_items[$key] = '';
                                }
                                $error_items[$key] .= '    <user id="'.$score->user_id.'">'.PHP_EOL.'      '.$li.PHP_EOL.'    </user>'.PHP_EOL;
                            } else {
                                // general error
                                $key = self::GENERAL_ERRORS;
                                $li = str_replace(self::SCORE_KEY, $score->score, $lineitem);
                                if (!isset($error_items[$key])) {
                                    $error_items[$key] = '';
                                }
                                $error_items[$key] .= '    <user id="'.$score->user_id.'" error="'.$score->error.'">'.PHP_EOL.
                                                      '      <error type="'.$score->error.'" />'.PHP_EOL.
                                                      '      '.$li.PHP_EOL.
                                                      '    </user>'.PHP_EOL;
                            }
                        }
                    }
                }
            }
            // loop through error items and dump to the output
            if (array_key_exists(self::USER_DOES_NOT_EXIST_ERROR, $error_items)) {
                $encoded .= '  <Userdoesnotexisterrors>'.PHP_EOL.$error_items[self::USER_DOES_NOT_EXIST_ERROR].'  </Userdoesnotexisterrors>'.PHP_EOL;
            }
            if (array_key_exists(self::POINTS_POSSIBLE_UPDATE_ERRORS, $error_items)) {
                $encoded .= '  <PointsPossibleupdateerrors>'.PHP_EOL.$error_items[self::POINTS_POSSIBLE_UPDATE_ERRORS].'  </PointsPossibleupdateerrors>'.PHP_EOL;
            }
            if (array_key_exists(self::SCORE_UPDATE_ERRORS, $error_items)) {
                $encoded .= '  <Scoreupdateerrors>'.PHP_EOL.$error_items[self::SCORE_UPDATE_ERRORS].'  </Scoreupdateerrors>'.PHP_EOL;
            }
            if (array_key_exists(self::GENERAL_ERRORS, $error_items)) {
                $encoded .= '  <Generalerrors>'.PHP_EOL.$error_items[self::GENERAL_ERRORS].'  </Generalerrors>'.PHP_EOL;
            }
            // close out
            $encoded .= '</errors>'.PHP_EOL;
            $output = $encoded;
        }
        return $output;
    }

    private static function make_lineitems($items) {
        $lineitems = array();
        foreach ($items as $item) {
            $li = '<lineitem name="'.self::encode_for_xml($item->itemname).'" pointspossible="'.$item->grademax.'" type="'.$item->itemtype.'" score="'.self::SCORE_KEY.'"/>';
            $lineitems[$item->id] = $li;
        }
        return $lineitems;
    }

    /**
     * This will handle the initial parsing of an XML string into a DOM document
     * @param string $xml the xml string
     * @return DOMDocument object
     * @throws InvalidArgumentException if the xml is not set
     * @throws DOMException if the xml fails to parse
     */
    private static function parse_xml_to_doc($xml) {
        if (!$xml) {
            throw new InvalidArgumentException("xml must be set");
        }
        // read the xml (try to anyway)
        set_error_handler('HandleXmlError');
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        if (! $doc->loadXML($xml, LIBXML_NOWARNING) ) {
            throw new Exception("XML read and parse failure: $xml");
        }
        $doc->normalizeDocument();
        restore_error_handler();
        return $doc;
    }

    /**
     * Translate incoming XML into a clicker registration,
     * will figure out the user and get necessary data
     * 
     * @param string $xml the xml
     * @return the clicker_registration object
     * @throws InvalidArgumentException if the xml cannot be parsed
     */
    public static function decode_registration($xml) {
        /*
<Register>
  <S DisplayName="DisplayName-azeckoski-123456" FirstName="First" LastName="Lastazeckoski-123456" 
    StudentID="eid-azeckoski-123456" Email="azeckoski-123456@email.com" URL="http://sakaiproject.org"; ClickerID="11111111"></S>
</Register>
         */
        $doc = self::parse_xml_to_doc($xml);
        $clicker_reg = new stdClass();
        try {
            $users = $doc->getElementsByTagName("S");
            if ($users->length <= 0) {
                throw new InvalidArgumentException("Invalid XML, no S element");
            }
            $user_node = $users->item(0);
            if ($user_node->nodeType == XML_ELEMENT_NODE) {
                $clicker_id = $user_node->getAttribute("ClickerID");
                if (! $clicker_id) {
                    throw new InvalidArgumentException("Invalid XML for registration, no id in the ClickerID element (Cannot process)");
                }
                $username = $user_node->getAttribute("StudentID"); // this is the username
                if (! $username) {
                    throw new InvalidArgumentException("Invalid XML for registration, no id in the StudentID element (Cannot process)");
                }
                $clicker_reg->clicker_id = $clicker_id;
                $clicker_reg->user_username = $username;
                $user = self::get_user_by_username($username);
                if (! $user) {
                    throw new InvalidArgumentException("Invalid username for student ($username), could not find user (Cannot process)");
                }
                $clicker_reg->owner_id = $user->id;
                $clicker_reg->user_display_name = $user_node->getAttribute("DisplayName");
            } else {
                throw new InvalidArgumentException("Invalid user node in XML: $user_node");
            }
        } catch (Exception $e) {
            throw new Exception("XML DOM parsing failure: $e :: $xml");
        }
        return $clicker_reg;
    }

    /**
     * Decodes XML into a gradebook object
     * 
     * @param string $xml the xml
     * @return the gradebook object
     * @throws InvalidArgumentException if the xml cannot be parsed or the data is invalid
     */
    public static function decode_gradebook($xml) {
        /*
<coursegradebook courseid="BFW61">
  <user id="id01" usertype="S">
    <lineitem name="06/02/2009" pointspossible="50" type="iclicker polling scores" score="0"/>
  </user>
  <user id="id02" usertype="S">
    <lineitem name="06/02/2009" pointspossible="50" type="iclicker polling scores" score="0"/>
  </user>
</coursegradebook>
         */
        $doc = self::parse_xml_to_doc($xml);
        $gradebook = new stdClass();
        $gradebook->students = array();
        $gradebook->items = array();
        try {
            // get the course id from the root attribute
            $course_id = $doc->documentElement->getAttribute("courseid");
            if (! $course_id) {
                throw new InvalidArgumentException("Invalid XML, no courseid in the root xml element");
            }
            $users = $doc->getElementsByTagName("user");
            if ($users->length <= 0) {
                throw new InvalidArgumentException("Invalid XML, no user elements found");
            }
            $gradebook->course_id = $course_id;
            foreach ($users as $user_node) {
                if ($user_node->nodeType == XML_ELEMENT_NODE) {
                    $user_type = $user_node->getAttribute("usertype");
                    if (strcasecmp('s', $user_type) != 0) {
                        continue; // skip this one
                    }
                    // valid user to process
                    $user_id = $user_node->getAttribute("id"); // this is the user id (not username)
                    if (! $user_id) {
                        error_log("WARN: Gradebook import failure for course ($course_id), Invalid XML for user, no id in the user element (skipping this entry): ".var_export($user_node));
                        continue;
                    }
                    /* check the username when saving
                    $user = self::get_user_by_username($username);
                    if (! $user) {
                        throw new InvalidArgumentException("Invalid username for student ($username), could not find user (Cannot process)");
                    }
                    $user_id = $user->id;
                    */
                    $gradebook->students[$user_id] = $user_id;
                    $lineitems = $user_node->getElementsByTagName("lineitem");
                    foreach ($lineitems as $lineitem) {
                        $li_name = $lineitem->getAttribute("name");
                        if (! $li_name) {
                            throw new InvalidArgumentException("Invalid XML, no name in the lineitem xml element: $lineitem");
                        }
                        $grade_item = NULL;
                        if (! isset($gradebook->items[$li_name])) {
                            // only add lineitem from the first item
                            $li_type = $lineitem->getAttribute("type");
                            $li_pp = 100.0;
                            $lipptext = $lineitem->getAttribute("pointspossible");
                            if (isset($lipptext) && $lipptext != '') {
                                if (! is_numeric($lipptext)) {
                                    error_log("WARN: Gradebook import failure for course ($course_id) and user ($user_id), Invalid points possible ($lipptext), using default of $li_pp");
                                } else {
                                    $li_pp = floatval($lipptext);
                                }
                            }
                            $grade_item = new stdClass();
                            $grade_item->name = $li_name;
                            $grade_item->points_possible = $li_pp;
                            $grade_item->type = $li_type;
                            $grade_item->scores = array();
                            $gradebook->items[$li_name] = $grade_item;
                        } else {
                            $grade_item = $gradebook->items[$li_name];
                        }
                        $li_score = $lineitem->getAttribute("score");
                        if (! isset($li_score) || '' == $li_score) {
                            error_log("WARN: Gradebook import failure for course ($course_id) and user ($user_id), Invalid score ($li_score), skipping this entry: ".var_export($lineitem));
                            continue;
                        }
                        // add in the score
                        $score = new stdClass();
                        $score->item_name = $grade_item->name;
                        $score->user_id = $user_id;
                        $score->score = $li_score;
                        $grade_item->scores[] = $score;
                    }
                } else {
                    throw new InvalidArgumentException("Invalid user node in XML: $user_node");
                }
            }
        } catch (Exception $e) {
            throw new Exception("XML DOM parsing failure: $e :: $xml");
        }
        
        return $gradebook;
    }

    /**
     * Decodes the webservices xml into an array of clicker registration objects
     * 
     * @param string $xml the xml from an iclicker webservice
     * @return array (clicker_registration object)
     * @throws InvalidArgumentException if the xml cannot be parsed or the data is invalid
     */
    public static function decode_ws_xml($xml) {
        /*
<StudentRoster>
    <S StudentID="student01" FirstName="student01" LastName="student01" URL="https://www.iclicker.com/" CourseName="">
        <Registration ClickerId="12CE32EE" WhenAdded="2009-01-27" Enabled="True" />
    </S>
</StudentRoster>
         */
        $doc = self::parse_xml_to_doc($xml);
        $regs = array();
        try {
            $users = $doc->getElementsByTagName("S");
            if ($users->length > 0) {
                foreach ($users as $user_node) {
                    if ($user_node->nodeType == XML_ELEMENT_NODE) {
                        $student_id = $user_node->getAttribute("StudentID"); // this is the user eid
                        if (! isset($student_id) || '' == $student_id) {
                            throw new InvalidArgumentException("Invalid XML for registration, no id in the StudentID element (Cannot process)");
                        }
                        $student_id = strtolower($student_id); // username
                        $user = self::get_user_by_username($student_id);
                        if (! $user) {
                            //log.warn("Cannot identify user (id="+studentId+") in the national webservices feed, skipping this user");
                            continue;
                        }
                        $user_id = $user->id;
                        $reg_nodes = $user_node->getElementsByTagName("Registration");
                        if ($reg_nodes->length > 0) {
                            foreach ($reg_nodes as $reg_node) {
                                if ($reg_node->nodeType == XML_ELEMENT_NODE) {
                                    $clicker_id = $reg_node->getAttribute("ClickerId");
                                    if (! $clicker_id) {
                                        //log.warn("Missing clickerId in webservices registration XML line, skipping this registration for user: $user_id");
                                        continue;
                                    }
                                    $when_added = $reg_node->getAttribute("WhenAdded"); // "yyyy-MM-dd"
                                    $date_created = time();
                                    if (isset($when_added)) {
                                        $time = strtotime($when_added);
                                        if ($time) {
                                            $date_created = $time;
                                        }
                                    }
                                    $enabled = $reg_node->getAttribute("Enabled");
                                    $activated = true;
                                    if (isset($enabled)) {
                                        $activated = (boolean) $enabled;
                                    }
                                    $clicker_reg = new stdClass();
                                    $clicker_reg->clicker_id = $clicker_id;
                                    $clicker_reg->owner_id = $user_id;
                                    $clicker_reg->user_username = $student_id;
                                    $clicker_reg->timecreated = $date_created;
                                    $clicker_reg->date_created = $date_created;
                                    $clicker_reg->activated = $activated;
                                    $regs[] = $clicker_reg;
                                } else {
                                    // only skipping invalid ones
                                    //log.warn("Invalid registration node in XML (skipping this one): $reg_node");
                                }
                            }
                        }
                    } else {
                        // only skipping invalid ones
                        //throw new InvalidArgumentException("Invalid user node in XML: $user_node");
                    }
                }
            }
        } catch (Exception $e) {
            throw new Exception("XML DOM parsing failure: $e :: $xml");
        }
        return $regs;
    }

    
    // NATIONAL WEBSERVICES
    
    /**
     * Syncs this clicker with the national services clicker (ensure that this is saved to national)
     * 
     * @return results array('errors') with errors if any occurred, false if national ws is disabled
     */
    public static function ws_sync_clicker($clicker_registration) {
        $results = array('errors' => array());
        if (self::$use_national_webservices) {
            try {
                $regs = self::ws_save_clicker($clicker_registration);
                if ($regs && count($regs) > 1) {
                    $cr_key = self::make_reg_key($clicker_registration);
                    $sync_regs = array();
                    foreach ($regs as $reg) {
                        $id = self::make_reg_key($reg);
                        $sync_regs[$id] = $reg;
                    }
                    unset($sync_regs[$cr_key]);
                    $regs = array_values($sync_regs);
                    // now we save all the registrations from national (may already exist)
                    foreach ($regs as $reg) {
                        try {
                            self::ws_save_clicker($reg);
                        } catch (Exception $e) {
                            // this ok, we will carry on
                            $results['errors'][] = $reg;
                        }
                    }
                }
            } catch (Exception $e) {
                // failed to sync with national but we do not fail, only send notification and log
                $msg = "Failure while syncing i>clicker registration ($clicker_registration): $e";
                $results['exception'] = $e;
                self::send_notifications($msg, $e);
                //log.warn(msg);
            }
        } else {
            $results = false;
        }
        return $results;
    }

    /**
     * Syncs all current clickers with the national services clickers for this site
     * 
     * @return results array('errors') with errors if any occurred, false if national ws is disabled
     */
    public static function ws_sync_all() {
        $results = array('errors' => array(), 'runner' => FALSE);
        if (self::$use_national_webservices && ! self::$disable_sync_with_national) {
            $runner_status = get_config(self::BLOCK_NAME, self::BLOCK_RUNNER_KEY);
            $time_check = time() - 60000; // 10 mins ago
            if (isset($runner_status) && ($runner_status > $time_check)) {
                $results['runner'] = TRUE;
                $results['errors'][] = 'sync is already running since '.date('Y-m-d h:i:s', $runner_status);
            } else {
                set_config(self::BLOCK_RUNNER_KEY, time(), self::BLOCK_NAME);
                try {
                    $local_regs_l = self::get_all_registrations(); //list
                    $national_regs_l = self::ws_get_students();
                    // put these into mapped lists so they can be more easily worked with and handled
                    $local_regs = array();
                    if ($local_regs_l) {
                        foreach ($local_regs_l as $reg) {
                            $id = self::make_reg_key($reg);
                            $local_regs[$id] = $reg;
                        }
                    }
                    $national_regs = array();
                    if ($national_regs_l) {
                        foreach ($national_regs_l as $reg) {
                            $id = self::make_reg_key($reg);
                            $national_regs[$id] = $reg;
                        }
                    }
            
                    // create maps and sets of local and remote regs
                    // both contains the items that exist in both sets, only contains items from one set only
                    $national_regs_only = array_diff_key($national_regs, $local_regs); //set
                    $local_regs_only = array_diff_key($local_regs, $national_regs); //set
                    $national_regs_both = array_diff_key($national_regs, $local_regs_only); //set
                    $local_regs_both = array_diff_key($local_regs, $national_regs_only); //set
            
                    foreach ($local_regs_both as $key => $local_reg) {
                        // update if needed or just continue (push local or national or neither)
                        $national_reg = array_key_exists($key, $national_regs_both) ? $national_regs_both[$key] : NULL;
                        if ($national_reg != NULL) {
                            // compare these for diffs
                            if ($local_reg->activated != $national_reg->activated) {
                                try {
                                    $national_reg->activated = $local_reg->activated;
                                    self::ws_save_clicker($national_reg);
                                } catch (Exception $e) {
                                    // this is ok, we will continue anyway
                                    $msg = "Failed during national activate push all sync while syncing i>clicker registration ($national_reg): $e";
                                    $results['errors'][] = $key;
                                    self::send_notifications($msg, $e);
                                    //log.warn(msg);
                                }
                            }
                        }
                    }
            
                    foreach ($national_regs_only as $key => $national_reg) {
                        try {
                            $reg = new stdClass();
                            $reg->clicker_id = $national_reg->clicker_id;
                            $reg->owner_id = $national_reg->owner_id;
                            $reg->activated = $national_reg->activated;
                            $reg->from_national = TRUE;
                            self::save_registration($reg);
                        } catch (Exception $e) {
                            // this is ok, we will continue anyway
                            $msg = "Failed during local push all sync while syncing i>clicker registration ($reg): $e";
                            $results['errors'][] = $key;
                            self::send_notifications($msg, $e);
                            //log.warn(msg);
                        }
                    }
            
                    foreach ($local_regs_only as $key => $local_reg) {
                        try {
                            $reg = new stdClass();
                            $reg->clicker_id = $local_reg->clicker_id;
                            $reg->owner_id = $local_reg->owner_id;
                            $reg->activated = $local_reg->activated;
                            $reg->from_national = FALSE;
                            self::ws_save_clicker($reg);
                        } catch (Exception $e) {
                            // this is ok, we will continue anyway
                            $msg = "Failed during national push all sync while syncing i>clicker registration ($reg): $e";
                            $results['errors'][] = $key;
                            self::send_notifications($msg, $e);
                            //log.warn(msg);
                        }
                    }
                    //log.info("Completed syncing "+total+" i>clicker registrations to ("+$local_regs_only.size()+") and from ("+$national_regs_only.size()+") national");
                    $results['local'] = count($local_regs_only);
                    $results['national'] = count($national_regs_only);
                    $results['total'] = count($local_regs_both) + count($national_regs_only);
                } catch(Exception $e) {
                    // reset the clicker runner
                    set_config(self::BLOCK_RUNNER_KEY, 0, self::BLOCK_NAME);
                    throw $e;
                }
            }
        } else {
            // disabled
            $results = false;
        }
        return $results;
    }

    private static function make_reg_key($reg) {
        return $reg->owner_id.':'.$reg->clicker_id;
    }

    /**
     * Calls to the national webservices and gets all the student clicker registrations for the current domain
     * @return array of clicker registration objects
     */
    public static function ws_get_students() {
        $ws_operation = 'StudentsReport';
        $ws_domain_url = self::$domain_URL; // 'http://epicurus.learningmate.com/';
        $ws_soap_envelope = '<StudentsReport xmlns="http://www.iclicker.com/"> <pVarUrl>'.$ws_domain_url.'</pVarUrl> </StudentsReport>';
        $result = self::ws_soap_call($ws_operation, $ws_soap_envelope);
        $xml = $result['StudentsReportResult'];
        $regs = self::decode_ws_xml($xml);
        return $regs;
    }

    /**
     * Calls to the national webservices to get the clicker registrations for a specific student in this domain
     * @param string $user_name the username (not user id) for a student in this domain
     * @return array of clicker registration objects
     */    
    public static function ws_get_student($user_name) {
        $ws_operation = 'SingleStudentReport';
        $ws_domain_url = self::$domain_URL; // 'http://epicurus.learningmate.com/';
        $ws_soap_envelope = '<SingleStudentReport xmlns="http://www.iclicker.com/"> <pVarUrl>'.$ws_domain_url.'</pVarUrl> <pVarStudentId>'.$user_name.'</pVarStudentId> </SingleStudentReport>';
        $result = self::ws_soap_call($ws_operation, $ws_soap_envelope);
        $xml = $result['SingleStudentReportResult'];
        $regs = self::decode_ws_xml($xml);
        return $regs;
    }

    /**
     * Register a new clicker with the national webservices server for this domain,
     * will return the set of all registrations for the user who registered the clicker
     * @param object $clicker_reg a clicker registration object, fields(owner_id, clicker_id)
     * @return array of clicker registration objects
     */    
    public static function ws_save_clicker($clicker_reg) {
        $ws_operation = 'RegisterStudent';
        $reg_xml = self::encode_for_xml(self::encode_registration($clicker_reg));
        $ws_soap_envelope = '<RegisterStudent xmlns="http://www.iclicker.com/"> <pVarRegXml>'.$reg_xml.'</pVarRegXml> </RegisterStudent>';
        $result = self::ws_soap_call($ws_operation, $ws_soap_envelope);
        $xml = $result['RegisterStudentResult'];
        $regs = self::decode_ws_xml($xml);
        return $regs;
    }

    /**
     * Handles the soap call to the national webservices server
     * @param string $ws_operation the operation to perform (e.g. 'StudentsReport')
     * @param string $ws_soap_envelope the soap envelope to send
     * @return the results of the SOAP call (array(string))
     * @throws WebservicesException if the call fails
     */
    private static function ws_soap_call($ws_operation, $ws_soap_envelope) {
/* won't work with .NET webservices
        $connection = soap_connect(self::NATIONAL_WS_URL);
        if (is_a($connection, 'SoapFault')) {
            throw new WebservicesException('Failure in SOAP connect: '.$connection);
        }
        $call = 'StudentsReport';
        $params = array();
        $result = soap_call($connection, $call, $params);
*/
        $soap_client = new soap_client(self::$webservices_URL, false);
        $err = $soap_client->getError();
        if ($err) {
            echo '<h2>SOAP constructor error:</h2><pre>' . $err . '</pre>';
            throw new WebservicesException('SOAP constructor error: '. $err);
        }
        $soap_client->setCredentials(self::$webservices_username, self::$webservices_password, 'basic');
        $soap_client->soap_defencoding = 'UTF-8';
        $soap_client->operation = $ws_operation;
/* won't work with .NET webservices
        $soap_client->setDefaultRpcParams(true);
        $header_action = new soapval('SOAPAction', 'string', 'http://www.iclicker.com/StudentsReport');
        $header_content_type = new soapval('Content-Type', 'string', 'text/xml; charset=utf-8');
        $soap_client->setHeaders(array($header_content_type, $header_action));
        $params = array('pVarUrl' => 'http://epicurus.learningmate.com/'); // $webservices_URL); // 'http://epicurus.learningmate.com/');
        $result = $soap_client->call('StudentsReport', $params, 'http://www.iclicker.com/', 'http://www.iclicker.com/StudentsReport');
*/
        $soap_msg = $soap_client->serializeEnvelope($ws_soap_envelope);
        $result = $soap_client->send($soap_msg, 'http://www.iclicker.com/'.$ws_operation);
        if ($soap_client->fault) {
            // check for fault
            echo '<h2>SOAP Fault</h2><xmp>';
            var_export($result);
            echo '</xmp>';
            throw new WebservicesException('SOAP fault: '. $result);
        } else {
            // Check for errors
            $err = $soap_client->getError();
            if ($err) {
                // Display the error
                echo '<h2>SOAP Error</h2><pre>' . $err . '</pre>';
                throw new WebservicesException('SOAP error: '. $err);
            }
        }
/*
        echo "<xmp>";
        var_export($result);
        var_export($soap_client);
        echo "</xmp>";
*/
        unset($soap_client);
        return $result;
    }


    // XML support functions

    /**
     * encodes a string for inclusion in an xml document
     * @param string $value the value to encode
     * @return the value with xml chars encoded and replaced
     */
    private static function encode_for_xml($value) {
        if ($value) {
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }
        return '';
    }
    
}

// load the config into the static vars from the global plugin config settings
$block_name = iclicker_service::BLOCK_NAME;
$block_iclicker_notify_emails = get_config($block_name, 'block_iclicker_notify_emails');
$block_iclicker_use_national_ws = get_config($block_name, 'block_iclicker_use_national_ws');
$block_iclicker_domain_url = get_config($block_name, 'block_iclicker_domain_url');
$block_iclicker_webservices_url = get_config($block_name, 'block_iclicker_webservices_url');
$block_iclicker_webservices_username = get_config($block_name, 'block_iclicker_webservices_username');
$block_iclicker_webservices_password = get_config($block_name, 'block_iclicker_webservices_password');
$block_iclicker_disable_sync = get_config($block_name, 'block_iclicker_disable_sync');

iclicker_service::$server_URL = $CFG->wwwroot;
if (!empty($block_iclicker_domain_url)) {
    iclicker_service::$domain_URL = $block_iclicker_domain_url;
} else {
    iclicker_service::$domain_URL = $CFG->wwwroot;
}
if (!empty($block_iclicker_use_national_ws)) {
    iclicker_service::$use_national_webservices = TRUE;
}
if (!empty($block_iclicker_webservices_url)) {
    iclicker_service::$webservices_URL = $block_iclicker_webservices_url;
}
if (!empty($block_iclicker_webservices_username)) {
    iclicker_service::$webservices_username = $block_iclicker_webservices_username;
}
if (!empty($block_iclicker_webservices_password)) {
    iclicker_service::$webservices_password = $block_iclicker_webservices_password;
}
if (!empty($block_iclicker_disable_sync)) {
    iclicker_service::$disable_sync_with_national = TRUE;
}

?>
