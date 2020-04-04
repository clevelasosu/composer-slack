<?php
/**
 * Created by PhpStorm.
 * User: clevelas
 * Date: 9/28/18
 * Time: 10:57 AM
 */

namespace OSUCOE\Slack\Exceptions;


class SlackSCIMException extends \RuntimeException
{
    const USER_NOT_EXIST = 0x11;
//    const SET_NOT_SUPPORTED = 0x01;
//    const SET_NOT_AUTHORIZED = 0x02;
//    const SET_MALFORMED = 0x03;

}