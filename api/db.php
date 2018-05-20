<?php

error_reporting(E_ALL);
ini_set("display_errors", 1);

require_once('./config.php');
require_once('./vendor/autoload.php');


/**
 * Database Functions
 */


/**
 * Init the connection to the databse
 * @return a handle that can be passed to further functions
 * @retval db the Medoo-DB-Instance
 * @retval error indicates, whether error occured
 * @retval msg error message, if exists
 */
function db_init(){
    global $mysql_database, $mysql_server, $mysql_user, $mysql_password;

    $ret = [
        'db' => NULL,
        'error' => false,
        'msg' => ''
    ];

    try{
        $db = new Medoo\Medoo([
            'database_type' => 'mysql',
            'database_name' => $mysql_database,
            'server' => $mysql_server,
            'username' => $mysql_user,
            'password' => $mysql_password
        ]);

        $ret['db'] = $db;
    } catch (Exception $e) {
        $ret['error'] = true;
        $ret['msg'] = $e->getMessage();
    }

    return $ret;
}

/**
 * Check an email/ access-password combination for validity.
 * An access password is an OTP that is generated by the user.
 * It is used for logging in with the Google Home App.
 * This password is valid, if:
 *  - it was created less than an hour ago
 *  - it hasn't been used before
 * @param handle handle that was returned by db_init()
 * @param email Mail-Address
 * @param accessPassword access password that was specified
 * @retval db the Medoo-DB-Instance
 * @retval error indicates, whether error occured
 * @retval msg error message, if exists
 * @retval accesskey_id the database id for the key
 * @retval google_key the key that will be sent to google
 */
function db_checkEmailAndAccessPassword($handle, $email, $accessPassword){
    $ret = [
        'db' => $handle['db'],
        'error' => false,
        'msg' => ''
    ];

    try{
        $data = $handle['db']->select('google_accesskey', 
            [
                '[>]user' => 'user_id'
            ], [
                'google_accesskey.accesskey_id',
                'google_accesskey.user_id', 
                'user.email', 
                'google_accesskey.password', 
                'google_accesskey.password_used',
                'google_accesskey.generated_at',
                'google_accesskey.google_key'
            ], [
                'user.email' => $email,
                'google_accesskey.password' => $accessPassword
        ]);
    }catch (Exception $e){
        $ret['error'] = true;
        $ret['msg'] = 'Internal Database Error!';
        return $ret;
    }

    if(count($data) < 1){
        $ret['error'] = true;
        $ret['msg'] = 'Invalid Email or Access Password!<br>Create a new one in your account dashboard.';
        return $ret;
    }
    if((time() - strtotime($data[0]['generated_at'])) > 3600){
        $ret['error'] = true;
        $ret['msg'] = 'This accesskey has expired!<br>Create a new one in your account dashboard.';
        return $ret;
    }
    if($data[0]['password_used']){
        $ret['error'] = true;
        $ret['msg'] = 'This Access Password has been used before!<br>Create a new one in your account dashboard.';
        return $ret;
    }

    $ret['accesskey_id'] = $data[0]['accesskey_id'];
    $ret['google_key'] = $data[0]['google_key'];
    return $ret;
}

/**
 * Mark an access password, defined by its ID, as read
 * @param handle handle that was returned by db_init()
 * @param accesskey_id ID of the access-password-record (not the accesskey itself!)
 * @retval db the Medoo-DB-Instance
 * @retval error indicates, whether error occured
 * @retval msg error message, if exists
 */
function db_markAccessPasswordAsUsed($handle, $accesskey_id){
    $ret = [
        'db' => $handle['db'],
        'error' => false,
        'msg' => ''
    ];

    try{
        $data = $handle['db']->update(
            'google_accesskey',
            [
                'password_used' => 1,
                'used_at' => date('Y-m-d H:i:s')
            ],[
                'accesskey_id' => $accesskey_id
            ]
        );
    }catch (Exception $e){
        $ret['error'] = true;
        $ret['msg'] = 'Internal Database Error!';
        return $ret;
    }
}

/**
 * Get the owner of a specified accesskey
 * @param handle handle that was returned by db_init
 * @param accesskey the accesskey that shall be checked
 * @retval db the Medoo-DB-Instance
 * @retval error indicates, whether error occured
 * @retval msg error message, if exists
 * @retval keyNotUsed The key hasn't been used to login. This implies an error.
 * @retval keyNotFound Key is wrong/ non-existent. This implies an error.
 * @retval accesskey_id The id of the accesskey-record
 * @retval user_id The id of the user that the key belongs to
 * @retval email The user's email
 */
function db_getUserByAccessKey($handle, $accesskey){
    $ret = [
        'db' => $handle['db'],
        'error' => false,
        'msg' => '',
        'keyNotUsed' => false,
        'keyNotFound' => false
    ];

    try{
        $data = $handle['db']->select('google_accesskey', 
            [
                '[>]user' => 'user_id'
            ], [
                'google_accesskey.accesskey_id',
                'google_accesskey.user_id', 
                'user.email', 
                'google_accesskey.password_used',
                'google_accesskey.google_key'
            ], [
                'google_accesskey.google_key' => $accesskey
        ]);
    }catch (Exception $e){
        $ret['error'] = true;
        $ret['msg'] = 'Internal Database Error!';
        return $ret;
    }

    if(count($data) < 1){
        $ret['keyNotFound'] = true;
        $ret['error'] = true;
        $ret['msg'] = 'Unknown/ invalid key!';
        return $ret;
    }
    if(!$data[0]['password_used']){
        $ret['keyNotUsed'] = true;
        $ret['error'] = true;
        $ret['msg'] = 'The key hasn\'t been activated before!';
        return $ret;
    }

    $ret['accesskey_id'] = $data[0]['accesskey_id'];
    $ret['user_id'] = $data[0]['user_id'];
    $ret['email'] = $data[0]['email'];

    return $ret;
}

/**
 * Get all traits that are supported by a device
 * @param handle handle that was returned by db_init
 * @param deviceid deviceid
 * @retval db the Medoo-DB-Instance
 * @retval error indicates, whether error occured
 * @retval msg error message, if exists
 * @retval traits all traits, with their id, google-name and optional config
 */
function db_getTraitsOfDevice($handle, $deviceid){
    $ret = [
        'db' => $handle['db'],
        'error' => false,
        'msg' => '',
        'traits' => []
    ];

    try{
        $data = $handle['db']->select('trait', 
            [
                '[>]trait_type' => 'traittype_id'
            ], [
                'trait.trait_id',
                'trait.config',
                'trait_type.gname'
            ], [
                'trait.device_id' => $deviceid
        ]);
    }catch (Exception $e){
        $ret['error'] = true;
        $ret['msg'] = 'Internal Database Error!';
        return $ret;
    }

    foreach($data as $record){
        $ret['traits'][] = $record;
    }

    return $ret;
}

/**
 * Get all devices and traits of one user
 * @param handle handle that was returned by db_init
 * @param user_id The user's id
 * @retval db the Medoo-DB-Instance
 * @retval error indicates, whether error occured
 * @retval msg error message, if exists
 * @retval devices Array of devices and their traits
 */
function db_getDevicesOfUser($handle, $userid){
    $ret = [
        'db' => $handle['db'],
        'error' => false,
        'msg' => '',
        'devices' => []
    ];

    try{
        $data = $handle['db']->select('device', 
            [
                '[>]device_type' => 'devicetype_id'
            ], [
                'device.device_id',
                'device.user_id',
                'device.name', 
                'device_type.gname'
            ], [
                'device.user_id' => $userid
        ]);
    }catch (Exception $e){
        $ret['error'] = true;
        $ret['msg'] = 'Internal Database Error!';
        return $ret;
    }

    foreach($data as $device){
        $traits = db_getTraitsOfDevice($handle, $device['device_id']);
        if($traits['error']){
            $ret['error'] = true;
            $ret['msg'] = $traits['msg'];
        }

        $device['traits'] = $traits['traits'];
        $ret['devices'][] = $device;
    }

    return $ret;
}

/*
$var = db_init();
$data = $var['db']->select('google_accesskey', 
    [
        '[>]user' => 'user_id'
    ], [
        'google_accesskey.accesskey_id',
        'google_accesskey.user_id', 
        'user.email', 
        'google_accesskey.password', 
        'google_accesskey.password_used',
        'google_accesskey.generated_at',
        'google_accesskey.google_key'
    ], [
        'user.email' => 'kappelt.peter+gbridge2@gmail.com'
    ]);

*/

?>